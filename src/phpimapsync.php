<?php
require_once('Entity/Message.php');
require_once('Server.php');
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
        // @todo This synchronize everything, server->path with subfolder is useless
        $this->imapSourceFolders = $this->imapSource->getFolders();
        $this->renderImapFolder($this->imapSource, $this->imapSourceFolders);

        echo 'Connecting Target...' . PHP_EOL;
        $this->imapTarget = new ImapUtility($this->config->getTarget(), $this->config->isVerbose(), $this->config->isTest());
        // @todo This synchronize everything, server->path with subfolder is useless
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

    public function isMessageIdentical(\App\Entity\Message $sourceMessage, array $targetMessages): bool {
        /** @var \App\Entity\Message $targetMessage */
        foreach ($targetMessages as $targetMessage) {
            if (!empty($targetMessage->getMessageId()) && $targetMessage->getMessageId() === $sourceMessage->getMessageId()) {
                return ($sourceMessage === $targetMessage);
            }
        }
        return false;
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

                if (!$this->imapSource->changeFolder($imapSourceFolder->name, false, 'source')) {
                    echo 'Can\'t change source folder: ' . $this->imapSource->getNameFromPath($imapSourceFolder->name) . PHP_EOL;
                    exit(1);
                }

                $targetFolderPath = $this->imapSource->getNameFromPath($imapSourceFolder->name);
                if ($targetFolderPath === '') {
                    echo '[WARNING] Skip, target path empty:' . $imapSourceFolder->name . PHP_EOL;
                    continue;
                }
                if (isset($mapFolder[$targetFolderPath])) {
                    $targetFolderPath = $mapFolder[$targetFolderPath];
                    echo 'Mapping folder ' . $this->imapSource->getNameFromPath($imapSourceFolder->name) . ' to ' . $targetFolderPath . PHP_EOL;
                }

                $targetChangeFolderResult = $this->imapTarget->changeFolder($targetFolderPath, true, 'target');
                if ($this->config->isTest() && !$targetChangeFolderResult) {
                    echo 'Can\'t change target folder: ' . $targetFolderPath . ' (Skipped in testing mode)' . PHP_EOL;
                    continue;
                } else if (!$targetChangeFolderResult) {
                    echo 'Can\'t change target folder: ' . $targetFolderPath . PHP_EOL;
                    exit(1);
                }

                echo 'Source: ' . $this->imapSource->getStats()->count .' messages | '
                    .'Target: ' . $this->imapTarget->getStats()->count .' messages'
                    . PHP_EOL . ($this->config->isVerbose() ? PHP_EOL : '');

                $sourceMessages = $this->imapSource->getMessages('Indexing source messages:');
                $targetMessages = $this->imapTarget->getMessages('Indexing target messages:');
                if ($this->config->isWipe()) {
                    $summary->Removed += $this->imapTarget->removeMessagesAll($targetMessages);
                } else {
                    $summary->Removed += $this->imapTarget->removeMessagesNotFound($sourceMessages, $targetMessages);
                }

                if ($this->config->isVerbose()) {
                    echo 'Synchronize messages:' . PHP_EOL;
                }
                /** @var \App\Entity\Message $sourceMessage */
                foreach ($sourceMessages as $sourceMessage) {
                    $textNumber = str_pad($sourceMessage->getMessageNumber(), strlen($this->imapSource->getStats()->count), ' ', STR_PAD_LEFT);
                    $updated = false;

                    if (empty($sourceMessage->getSubject())) {
                        $sourceMessage->setSubject('*** No Subject ***');
                    }

                    $existsTargetMail = (!empty($sourceMessage->getMessageId()) ? array_key_exists($sourceMessage->getMessageId(), $targetMessages) : false);
                    $textSubject = $this->imapSource->decodeSubject($sourceMessage->getSubject());

                    $isMessageIdentical = false;
                    if (!empty($sourceMessage->getMessageId())) {
                        $isMessageIdentical = $this->isMessageIdentical($sourceMessage, $targetMessages);
                    }

                    if ($existsTargetMail && $isMessageIdentical) {
                        // Message already exists and has not changed
                        if ($this->config->isVerbose()) {
                            echo '-> ' . $textNumber . ': [Exists] ' . $textSubject . PHP_EOL;
                        }
                        $summary->Exists++;
                    } else {
                        if ($existsTargetMail && !$isMessageIdentical) {
                            $this->imapTarget->removeMessages([$sourceMessage->getMessageNumber()]);
                            $updated = true;
                        }

                        $mailOptions = $sourceMessage->getMailOptions();
                        $date = strftime('%d-%b-%Y %H:%M:%S +0000', strtotime($sourceMessage->getMailDate()));
                        $result = $this->imapTarget->putMessage($this->imapSource->getMessage($sourceMessage->getMessageNumber()), $mailOptions, $date);
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
