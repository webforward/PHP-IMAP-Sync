<?php
class ImapUtility {
    protected bool $verbose = false;
    protected bool $test = false;
    protected $connection = null;
    protected string $host = '';
    protected string $username = '';
    protected string $password = '';
    protected string $path = '';
    protected stdClass $stats;
    protected array $mailFlags = ['Unseen', 'Flagged', 'Answered', 'Deleted', 'Draft'];

    public function __construct(string $url, bool $isVerbose = false, bool $isTest = false) {
        $this->verbose = $isVerbose;
        $this->test = $isTest;

        $this->parseConfigUrl($url);
        $this->connect($url);
    }

    protected function parseConfigUrl(string $url) {
        $uri = parse_url($url);
        $this->username = $uri['user'];
        $this->password = $uri['pass'];

        $this->host = '{' . $uri['host'];
        if (!empty($uri['port'])) {
            $this->host .= ':' . $uri['port'];
        }
        if (!empty($uri['scheme'])) {
            switch (strtolower($uri['scheme'])) {
                case 'imap-ssl':
                    $this->host .= '/ssl';
                    break;
                case 'imap-ssl-novalidate':
                    $this->host .= '/ssl/novalidate-cert';
                    break;
                case 'imap-tls':
                    $this->host .= '/tls';
                    break;
                default:
            }
        }
        $this->host .= '}';

        if (empty($uri['path']) or $uri['path'] === '/') {
            $uri['path'] = '/INBOX';
        }
        $trim = ltrim($uri['path'],'/');
        if (!empty($trim)) {
            $this->path = $trim;
        }
    }

    protected function connect(string $url) {
        if ($this->verbose) {
            echo 'Connect to: ' . $this->host . PHP_EOL;
        }

        $this->connection = imap_open($this->host, $this->username, $this->password);
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
        return imap_getmailboxes($this->connection, $this->host, $pattern);
    }

    public function changeFolder(string $path, bool $createFolder = false, string $key = ''): bool {
        if (substr($path, 0, 1) !== '{') {
            $path = $this->host . trim($path, '/');
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
    }

    protected function createFolder(string $path) {
        if (substr($path, 0, 1) !== '{') {
            $path = $this->host . trim($path, '/');
        }

        if ($this->verbose) {
            echo 'Create folder: ' . $path . PHP_EOL;
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
            $path = $this->host . trim($path, '/');
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

    public function getHeader($i): stdClass {
        $mailHeader = imap_headerinfo($this->connection, $i);
        foreach ($this->mailFlags as $flag) {
            $mailHeader->$flag = trim($mailHeader->$flag);
        }
        return $mailHeader;
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

    public function getMessages(string $message = 'Indexing messages:'): array {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }

        $messages = [];
        $length = strlen($this->getStats()->count);
        for ($messageNumber = 1; $messageNumber <= $this->getStats()->count; $messageNumber++) {
            $mailHeader = $this->getHeader($messageNumber);
            $messages[$mailHeader->message_id] = md5(serialize([
                'Unseen' => $mailHeader->Unseen,
                'Flagged' => $mailHeader->Flagged,
                'Answered' => $mailHeader->Answered,
                'Deleted' => $mailHeader->Deleted,
                'Draft' => $mailHeader->Draft,
            ]));
            if ($this->verbose) {
                echo '-> ' . str_pad($messageNumber, $length, ' ', STR_PAD_LEFT) . ': ' . $mailHeader->subject . PHP_EOL;
            }
        }
        if ($this->verbose) {
            echo (count($messages) > 0 ? '' : '-> No messages found' . PHP_EOL) . PHP_EOL;
        }
        return $messages;
    }

    public function removeMessagesNotFound(array $sourceMessages): int {
        if ($this->verbose) {
            echo 'Remove messages on target server, which not exists on source server:' . PHP_EOL;
        }

        $removeMessages = [];
        $length = strlen($this->getStats()->count);
        for ($messageNumber = 1; $messageNumber <= $this->getStats()->count; $messageNumber++) {
            $mailHeader = $this->getHeader($messageNumber);
            if (isset($mailHeader->message_id) && !array_key_exists($mailHeader->message_id, $sourceMessages)) {
                $removeMessages[] = $messageNumber;
                if ($this->verbose) {
                    echo '-> ' . str_pad($messageNumber, $length, ' ', STR_PAD_LEFT) . ': ' . $mailHeader->subject . PHP_EOL;
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

    public function removeMessagesAll(): int {
        if ($this->verbose) {
            echo 'Remove all messages on target server:' . PHP_EOL;
        }

        $removeMessages = [];
        $length = strlen($this->getStats()->count);
        for ($messageNumber = 1; $messageNumber <= $this->getStats()->count; $messageNumber++) {
            $mailHeader = $this->getHeader($messageNumber);
            $removeMessages[] = $messageNumber;
            if ($this->verbose) {
                echo '-> ' . str_pad($messageNumber, $length, ' ', STR_PAD_LEFT) . ': ' . $mailHeader->subject . PHP_EOL;
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

    public function getMailOptionsFromMailHeader(\stdClass $mailHeader): string {
        $mailOptions = [];
        foreach ($this->mailFlags as $flag) {
            if ($flag === 'Unseen' && empty($mailHeader->$flag)) {
                $mailOptions[] = '\\Seen';
            } else if ($flag !== 'Unseen' && !empty($mailHeader->$flag)) {
                $mailOptions[] = '\\' . $flag;
            }
        }
        return implode(' ', $mailOptions);
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
