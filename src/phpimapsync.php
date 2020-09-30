<?php
require_once('Configuration.php');
require_once('ImapUtility.php');

error_reporting(E_ALL | E_STRICT);

$header = 'PHP-IMAP-Sync';
$headerHash = str_repeat('#', (int)(80 - strlen($header) - 2) / 2);
echo $headerHash . ' ' . $header . ' ' . $headerHash . PHP_EOL;

if (PHP_SAPI !== 'cli') {
    echo 'This script needs to be ran in CLI mode.' . PHP_EOL;
    exit(1);
}

$config = new Configuration($argc, $argv);
if ($config->getMemory()) {
    ini_set('memory_limit', $config->getMemory());
}

echo 'Connecting Source...' . PHP_EOL;
$imapSource = new ImapUtility($config->getSource(), $config->isVerbose(), $config->isTest());
$imapSourceFolders = $imapSource->getFolders();

echo 'Connecting Target...' . PHP_EOL;
$imapTarget = new ImapUtility($config->getTarget(), $config->isVerbose(), $config->isTest());
$imapTargetFolders = $imapTarget->getFolders();

$summary = new \stdClass();
$summary->Copied = 0;
$summary->Updated = 0;
$summary->Removed = 0;
$summary->Exists = 0;
$summary->Error = 0;

echo 'Synchronize...' . PHP_EOL . PHP_EOL;
if (is_array($imapSourceFolders)) {
    foreach ($imapSourceFolders as $imapSourceFolder) {
        if ($config->isVerbose()) {
            echo str_repeat('#', 80) . PHP_EOL;
        }
        echo '# Folder: ' . $imapSource->getNameFromPath($imapSourceFolder->name) . PHP_EOL;

        if ($imapSource->isPathExcluded($imapSourceFolder)) {
            echo '[Source] Skip, folder excluded: ' . $imapSourceFolder->name . PHP_EOL;
            continue;
        }

        $imapSource->changeFolder($imapSourceFolder->name, false, 'source');

        $targetFolderPath = $imapSource->getNameFromPath($imapSourceFolder->name);
        if ($targetFolderPath === '') {
            echo '[WARNING] Skip, target path empty:' . $imapSourceFolder->name . PHP_EOL;
            continue;
        }

        $imapTarget->changeFolder($targetFolderPath, true, 'target');
        echo 'Source: ' . $imapSource->getStats()->count .' messages | Target: ' . $imapTarget->getStats()->count .' messages' . PHP_EOL;

        $sourceMessages = $imapSource->getMessages('Indexing source messages:');
        $targetMessages = $imapTarget->getMessages('Indexing target messages:');
        if ($config->isWipe()) {
            $summary->Removed += $imapTarget->removeMessagesAll();
        } else {
            $summary->Removed += $imapTarget->removeMessagesNotFound($sourceMessages);
        }

        if ($config->isVerbose()) {
            echo 'Synchronize messages:' . PHP_EOL;
        }
        for ($messageNumber = 1; $messageNumber <= $imapSource->getStats()->count; $messageNumber++) {
            $textNumber = str_pad($messageNumber, strlen($imapSource->getStats()->count), ' ', STR_PAD_LEFT);
            $updated = false;
            $mailHeader = $imapSource->getHeader($messageNumber);

            if (empty($mailHeader->subject)) {
                $mailHeader->subject = "*** No Subject ***";
            }

            if (isset($mailHeader->message_id)) {
                $existsTargetMail = array_key_exists($mailHeader->message_id, $targetMessages);

                if ($existsTargetMail && $targetMessages[$mailHeader->message_id] === $sourceMessages[$mailHeader->message_id]) {
                    // Message already exists and has not changed
                    if ($config->isVerbose()) {
                        echo '-> ' . $textNumber . ': [Exists] ' . $mailHeader->subject . PHP_EOL;
                    }
                    $summary->Exists++;
                } else {
                    if ($existsTargetMail && $targetMessages[$mailHeader->message_id] !== $sourceMessages[$mailHeader->message_id]) {
                        $imapTarget->removeMessages([$messageNumber]);
                        $updated = true;
                    }

                    $mailOptions = $imapTarget->getMailOptionsFromMailHeader($mailHeader);
                    $date = strftime('%d-%b-%Y %H:%M:%S +0000', strtotime($mailHeader->MailDate));
                    $result = $imapTarget->putMessage($imapSource->getMessage($messageNumber), $mailOptions, $date);
                    $textOptions = str_replace('\\', '', $mailOptions);
                    if ($result) {
                        if ($config->isVerbose()) {
                            echo '-> ' . $textNumber . ': [' . ($updated ? 'Update' : 'Copied') . '] ' . $mailHeader->subject . ' (' . $textOptions . ')' . PHP_EOL;
                        }
                        $updated ? $summary->Updated++ : $summary->Copied++;
                    } else {
                        if ($config->isVerbose()) {
                            echo '-> ' . $textNumber . ': [Error] ' . $mailHeader->subject . '(' . $textOptions . ')' . PHP_EOL;
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

exit(0);
