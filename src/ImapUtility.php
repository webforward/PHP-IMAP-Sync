<?php
class ImapUtility {
    protected bool $verbose = false;
    protected bool $test = false;
    protected $connection = null;
    protected Server $server;
    protected stdClass $stats;

    public function __construct(Server $server, bool $isVerbose = false, bool $isTest = false) {
        $this->server = $server;
        $this->verbose = $isVerbose;
        $this->test = $isTest;
        $this->connect();
    }

    protected function connect() {
        if ($this->verbose) {
            echo 'Connect to: ' . $this->server->getImapServerPart() . PHP_EOL;
        }

        $this->connection = imap_open($this->server->getImapServerPart(), $this->server->getUsername(), $this->server->getPassword());
        $this->checkAndThrowImapError('Could\'t connect to host:');
    }

    protected function checkAndThrowImapError(string $message = 'IMAP Error:') {
        $imapErrors = imap_errors();
        if (!empty($imapErrors)) {
            echo $message . PHP_EOL . implode(PHP_EOL, $imapErrors) . PHP_EOL;
            exit(1);
        }
    }

    /**
     * List folders matching pattern
     * @param $pattern * == all folders, % == folders at current level
     */
    public function getFolders(string $pattern = '*'): array {
        return imap_getmailboxes($this->connection, $this->server->getImapServerPart(), $pattern);
    }

    public function changeFolder(string $path, bool $createFolder = false, string $key = ''): bool {
        if (substr($path, 0, 1) !== '{') {
            $path = $this->server->getImapServerPart() . trim($path, '/');
        }

        if ($this->verbose) {
            echo 'Change ' . (!empty($key) ? $key . ' ' : '') . 'path: ' . $this->getNameFromPath($path) . PHP_EOL;
        }
        imap_reopen($this->connection, $path);
        $imapErrors = imap_errors();
        if (empty($imapErrors)) {
            $this->updateStats($path);
            return true;
        }

        // Couldn't open Mailbox folder, so create it
        if ($createFolder && preg_match('/(NONEXISTENT|Mailbox doesn\'t exist)/i', implode(', ', $imapErrors))) {
            $this->createFolder($path);
            imap_reopen($this->connection, $path);
            if (empty(imap_errors())) {
                $this->updateStats($path);
                return true;
            }
        }

        $this->checkAndThrowImapError('Failed to Switch change path (' . $path . '):');
        return false;
    }

    protected function createFolder(string $path) {
        if (substr($path, 0, 1) !== '{') {
            $path = $this->server->getImapServerPart() . trim($path, '/');
        }

        if ($this->verbose) {
            echo 'Create folder: ' . $this->getNameFromPath($path) . PHP_EOL;
        }
        if ($this->test) {
            return;
        }
        imap_createmailbox($this->connection, $path);
        $this->checkAndThrowImapError('Failed to create folder (' . $path . '):');
    }

    public function getStats(): \stdClass {
        if (!$this->stats) {
            $this->stats = new \stdClass();
            $this->stats->count = 0;
            $this->stats->mailbox = '';
        }
        return $this->stats;
    }

    protected function updateStats($path = null) {
        if (substr($path, 0, 1) !== '{') {
            $path = $this->server->getImapServerPart() . trim($path, '/');
        }

        $this->stats = new \stdClass();
        $status = imap_status($this->connection, $path, SA_MESSAGES);
        $this->stats->count = $status->messages;

        $check = imap_check($this->connection);
        $this->stats->mailbox = $check->Mailbox;
    }

    public function getMessage($messageNumber): string {
        return imap_fetchbody($this->connection, $messageNumber, null, FT_PEEK);
    }

    public function putMessage($mail, $opts, $date) {
        if ($this->test) {
            return true;
        }
        $return = imap_append($this->connection, $this->stats->mailbox, $mail, $opts, $date);
        $this->checkAndThrowImapError('Failed put message:');
        return $return;
    }

    public function removeMessages(array $messageNumbers) {
        if ($this->test) {
            return;
        }
        if (empty($messageNumbers)) {
            return;
        }
        foreach ($messageNumbers as $messageNumber) {
            if (!imap_delete($this->connection, $messageNumber)) {
                echo 'Can\'t remove message ' . $messageNumber . '!' . PHP_EOL;
                exit(1);
            }
        }
        if (!imap_expunge($this->connection)) {
            echo 'Can\'t expunge messages!' . PHP_EOL;
            exit(1);
        }
    }

    public function isPathExcluded(stdClass $folder): bool {
        if (($folder->attributes & LATT_NOSELECT) == LATT_NOSELECT) {
            return true;
        }

        // All Mail, Trash, Starred have this attribute
        if (($folder->attributes & 96) == 96) {
            return true;
        }

        // Skip by Pattern
        if (preg_match('/}(.+)$/', $folder->name, $matches)) {
            switch (strtolower($matches[1])) {
                case '[gmail]/all mail':
                case '[gmail]/sent mail':
                case '[gmail]/spam':
                case '[gmail]/starred':
                    return true;
            }
        }

        // By First Folder Part of Name
        if (preg_match('/}([^\/]+)/', $folder->name, $matches)) {
            switch (strtolower($matches[1])) {
                // This bundle is from Exchange
                case 'journal':
                case 'notes':
                case 'outbox':
                case 'rss feeds':
                case 'sync issues':
                    return true;
            }
        }

        return false;
    }

    public function mapPath($path) {
        if (preg_match('/}(.+)$/', $path, $matches)) {
            switch (strtolower($matches[1])) {
                // case 'inbox':         return null;
                case 'deleted items': return '[Gmail]/Trash';
                case 'drafts': return '[Gmail]/Drafts';
                case 'junk e-mail': return '[Gmail]/Spam';
                case 'sent items': return '[Gmail]/Sent Mail';
            }
            $path = str_replace('INBOX/', null, $matches[1]);
        }
        return $path;
    }

    public function getNameFromPath($path) {
        $name = '';
        preg_match('/}(.+)$/', $path, $matches);
        if (count($matches) > 0) {
            $name = str_replace('INBOX/', null, $matches[1]);
        }
        return $name;
    }

    public function getMessages(string $outputText = 'Indexing messages:'): array {
        if ($this->verbose) {
            echo $outputText . PHP_EOL;
        }

        $messages = [];
        $length = strlen($this->getStats()->count);
        for ($messageNumber = 1; $messageNumber <= $this->getStats()->count; $messageNumber++) {
            $mailHeader = imap_headerinfo($this->connection, $messageNumber);

            $message = new \App\Entity\Message();
            $message->setMessageNumber($messageNumber)
                ->setMessageId(isset($mailHeader->message_id) ? $mailHeader->message_id : '')
                ->setSubject(isset($mailHeader->subject) ? $mailHeader->subject : '')
                ->setMailDate($mailHeader->MailDate)
                ->setFlagUnseen(trim($mailHeader->Unseen))
                ->setFlagFlagged(trim($mailHeader->Flagged))
                ->setFlagAnswered(trim($mailHeader->Answered))
                ->setFlagDeleted(trim($mailHeader->Deleted))
                ->setFlagDraft(trim($mailHeader->Draft))
                ->updateHash();

            $messages[$messageNumber] = $message;

            if ($this->verbose) {
                echo '-> ' . str_pad($messageNumber, $length, ' ', STR_PAD_LEFT) . ': ' . $this->decodeSubject($message->getSubject()) . PHP_EOL;
            }
        }
        if ($this->verbose) {
            echo (count($messages) > 0 ? '' : '-> No messages found' . PHP_EOL) . PHP_EOL;
        }
        return $messages;
    }

    public function removeMessagesNotFound(array $sourceMessages, array $targetMessages): int {
        if ($this->verbose) {
            echo 'Remove messages on target server, which not exists on source server:' . PHP_EOL;
        }

        $mapHash = [];
        /** @var \App\Entity\Message $sourceMessage */
        foreach ($sourceMessages as $sourceMessage) {
            if (!empty($sourceMessage->getMessageId())) {
                $mapHash[$sourceMessage->getMessageId()] = $sourceMessage->getMessageNumber();
            }
        }

        $removeMessages = [];
        $length = strlen($this->getStats()->count);
        /** @var \App\Entity\Message $targetMessage */
        foreach ($targetMessages as $targetMessage) {
            if (!empty($targetMessage->getMessageId()) && !array_key_exists($targetMessage->getMessageId(), $mapHash)) {
                $removeMessages[] = $targetMessage->getMessageNumber();
                if ($this->verbose) {
                    echo '-> ' . str_pad($targetMessage->getMessageNumber(), $length, ' ', STR_PAD_LEFT) . ': ' . $this->decodeSubject($targetMessage->getSubject()) . PHP_EOL;
                }
            }
        }
        if (!$this->test) {
            $this->removeMessages($removeMessages);
        }

        if ($this->verbose) {
            echo (count($removeMessages) > 0 ? '' : '-> No messages removed' . PHP_EOL) . PHP_EOL;
        }
        return count($removeMessages);
    }

    public function removeMessagesAll(array $messages): int {
        if ($this->verbose) {
            echo 'Remove all messages on target server:' . PHP_EOL;
        }

        $removeMessages = [];
        $length = strlen($this->getStats()->count);
        /** @var \App\Entity\Message $message */
        foreach ($messages as $message) {
            $removeMessages[] = $message->getMessageNumber();
            if ($this->verbose) {
                echo '-> ' . str_pad($message->getMessageNumber(), $length, ' ', STR_PAD_LEFT) . ': ' . $this->decodeSubject($message->getSubject()) . PHP_EOL;
            }
        }
        if (!$this->test) {
            $this->removeMessages($removeMessages);
        }

        if ($this->verbose) {
            echo (count($removeMessages) > 0 ? '' : '-> No messages removed' . PHP_EOL) . PHP_EOL;
        }
        return count($removeMessages);
    }

    public function decodeSubject(string $value): string {
        $subject = '';
        foreach (imap_mime_header_decode($value) as $item) {
            $subject .= $item->text;
        }
        return $subject;
    }

    /* @todo Maybe good for path mapping in Google; check domain [gmail.com|googlemail.com] or --map-google-source --map-google-target
    function _path_map($x) {
        if (preg_match('/}(.+)$/', $x, $m)) {
            switch (strtolower($m[1])) {
                // case 'inbox': return null;
                case 'deleted items': return '[Gmail]/Trash';
                case 'drafts': return '[Gmail]/Drafts';
                case 'junk e-mail': return '[Gmail]/Spam';
                case 'sent items': return '[Gmail]/Sent Mail';
            }
            $x = str_replace('INBOX/', null, $m[1]);
        }
        return $x;
    }*/
}
