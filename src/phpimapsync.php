<?php
require_once('Configuration.php');
require_once('ImapUtility.php');

class ImapSync {
    protected Configuration $config;

    protected ImapUtility $imapSource;
    protected array $imapSourceFolders;

    protected ImapUtility $imapTarget;
    protected array $imapTargetFolder;

    public function initialize(int $argc, array $argv, string $header) {
        $this->config = new Configuration($argc, $argv);
        error_reporting(E_ALL | E_STRICT);
        $this->renderHeader($header);
        $this->checkCliMode();
        $this->setMemoryLimit($this->config->getMemory());

        echo 'Connecting Source...' . PHP_EOL;
        $this->imapSource = new ImapUtility($this->config->getSource(), $this->config->isVerbose(), $this->config->isTest());
        $this->imapSourceFolders = $this->imapSource->getFolders();
        $this->renderImapFolder($this->imapSource, $this->imapSourceFolders);

        echo 'Connecting Target...' . PHP_EOL;
        $this->imapTarget = new ImapUtility($this->config->getTarget(), $this->config->isVerbose(), $this->config->isTest());
        $this->imapTargetFolders = $this->imapTarget->getFolders();
        $this->renderImapFolder($this->imapTarget, $this->imapTargetFolders);

        $this->renderMapFolderInfo();

        if ($this->config->isListFolder()) {
            exit(0);
        }
        $this->sync();
    }

    protected function renderHeader(string $header) {
        $headerHash = str_repeat('#', (int)(80 - strlen($header) - 2) / 2);
        echo $headerHash . ' ' . $header . ' ' . $headerHash . PHP_EOL;
    }

    protected function checkCliMode() {
        if (PHP_SAPI !== 'cli') {
            echo 'This script needs to be ran in CLI mode.' . PHP_EOL;
            exit(1);
        }
    }

    public function setMemoryLimit(string $memory = '') {
        if ($memory !== '') {
            ini_set('memory_limit', $memory);
        }
    }

    public function renderImapFolder(ImapUtility $imap, array $folders) {
        if ($this->config->isVerbose()) {
            foreach ($folders as $folder) {
                echo '-> ' . $imap->getNameFromPath($folder->name) . PHP_EOL;
            }
            echo PHP_EOL;
        }
    }

    protected function renderMapFolderInfo() {
        $mapFolder = $this->config->getMapFolder();
        if (!empty($mapFolder) && $this->config->isVerbose()) {
            echo 'Mapping source to target paths:' . PHP_EOL;
            foreach ($this->imapSourceFolders as $folder) {
                $sourceFolder = $this->imapSource->getNameFromPath($folder->name);
                if (isset($mapFolder[$sourceFolder])) {
                    echo '-> ' . $sourceFolder . ' => ' . $mapFolder[$sourceFolder] . PHP_EOL;
                }
            }
            echo PHP_EOL;
        }
    }

    public function sync() {
        $summary = new \stdClass();
        $summary->Copied = 0;
        $summary->Updated = 0;
        $summary->Removed = 0;
        $summary->Exists = 0;
        $summary->Error = 0;

        echo 'Synchronize...' . PHP_EOL . PHP_EOL;
        $mapFolder = $this->config->getMapFolder();
        if (is_array($this->imapSourceFolders)) {
            foreach ($this->imapSourceFolders as $imapSourceFolder) {
                if ($this->config->isVerbose()) {
                    echo str_repeat('#', 80) . PHP_EOL;
                }
                echo '# Folder: ' . $this->imapSource->getNameFromPath($imapSourceFolder->name) . PHP_EOL;

                if ($this->imapSource->isPathExcluded($imapSourceFolder)) {
                    echo '[Source] Skip, folder excluded: ' . $imapSourceFolder->name . PHP_EOL;
                    continue;
                }

                $this->imapSource->changeFolder($imapSourceFolder->name, false, 'source');

                $targetFolderPath = $this->imapSource->getNameFromPath($imapSourceFolder->name);
                if ($targetFolderPath === '') {
                    echo '[WARNING] Skip, target path empty:' . $imapSourceFolder->name . PHP_EOL;
                    continue;
                }
                if (isset($mapFolder[$targetFolderPath])) {
                    $targetFolderPath = $mapFolder[$targetFolderPath];
                    echo 'Mapping folder ' . $this->imapSource->getNameFromPath($imapSourceFolder->name) . ' to ' . $targetFolderPath . PHP_EOL;
                }

                $this->imapTarget->changeFolder($targetFolderPath, true, 'target');

                echo 'Source: ' . $this->imapSource->getStats()->count .' messages | '
                    .'Target: ' . $this->imapTarget->getStats()->count .' messages'
                    . PHP_EOL . ($this->config->isVerbose() ? PHP_EOL : '');

                $sourceMessages = $this->imapSource->getMessages('Indexing source messages:');
                $targetMessages = $this->imapTarget->getMessages('Indexing target messages:');
                if ($this->config->isWipe()) {
                    $summary->Removed += $this->imapTarget->removeMessagesAll();
                } else {
                    $summary->Removed += $this->imapTarget->removeMessagesNotFound($sourceMessages);
                }

                if ($this->config->isVerbose()) {
                    echo 'Synchronize messages:' . PHP_EOL;
                }
                for ($messageNumber = 1; $messageNumber <= $this->imapSource->getStats()->count; $messageNumber++) {
                    $textNumber = str_pad($messageNumber, strlen($this->imapSource->getStats()->count), ' ', STR_PAD_LEFT);
                    $updated = false;
                    $mailHeader = $this->imapSource->getHeader($messageNumber);

                    if (empty($mailHeader->subject)) {
                        $mailHeader->subject = "*** No Subject ***";
                    }

                    if (isset($mailHeader->message_id)) {
                        $existsTargetMail = array_key_exists($mailHeader->message_id, $targetMessages);
                        $textSubject = $this->imapSource->decodeSubject($mailHeader->subject);

                        if ($existsTargetMail && $targetMessages[$mailHeader->message_id] === $sourceMessages[$mailHeader->message_id]) {
                            // Message already exists and has not changed
                            if ($this->config->isVerbose()) {
                                echo '-> ' . $textNumber . ': [Exists] ' . $textSubject . PHP_EOL;
                            }
                            $summary->Exists++;
                        } else {
                            if ($existsTargetMail && $targetMessages[$mailHeader->message_id] !== $sourceMessages[$mailHeader->message_id]) {
                                $this->imapTarget->removeMessages([$messageNumber]);
                                $updated = true;
                            }

                            $mailOptions = $this->imapTarget->getMailOptionsFromMailHeader($mailHeader);
                            $date = strftime('%d-%b-%Y %H:%M:%S +0000', strtotime($mailHeader->MailDate));
                            $result = $this->imapTarget->putMessage($this->imapSource->getMessage($messageNumber), $mailOptions, $date);
                            $textOptions = str_replace('\\', '', $mailOptions);
                            if ($result) {
                                if ($this->config->isVerbose()) {
                                    echo '-> ' . $textNumber . ': [' . ($updated ? 'Update' : 'Copied') . '] ' . $textSubject . ' (' . $textOptions . ')' . PHP_EOL;
                                }
                                $updated ? $summary->Updated++ : $summary->Copied++;
                            } else {
                                if ($this->config->isVerbose()) {
                                    echo '-> ' . $textNumber . ': [Error] ' . $textSubject . '(' . $textOptions . ')' . PHP_EOL;
                                }
                                $summary->Error++;
                            }
                        }
                    }
                }
                echo PHP_EOL;
            }

            echo 'Summary:' . PHP_EOL;
            foreach ($summary as $key => $value) {
                echo '-> ' . $key . ': ' . $value . PHP_EOL;
            }
        }
   }
}

$imapSync = new ImapSync();
$imapSync->initialize($argc, $argv, 'PHP-IMAP-Sync');
exit(0);
