<?php
class Server {
    protected string $scheme = '';
    protected string $host = '';
    protected int $port = 0;
    protected string $username = '';
    protected string $password = '';
    protected string $path = 'INBOX';
    protected string $imapServerPart = '';

    public function getScheme(): string {
        return $this->scheme;
    }

    public function setScheme(string $scheme): self {
        $this->scheme = $scheme;
        $this->updateImapServerPart();
        return $this;
    }

    public function getHost(): string {
        return $this->host;
    }

    public function setHost(string $host): self {
        $this->host = $host;
        $this->updateImapServerPart();
        return $this;
    }

    public function getPort(): int {
        return $this->port;
    }

    public function setPort(int $port): self {
        $this->port = $port;
        $this->updateImapServerPart();
        return $this;
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function setUsername(string $username): self {
        $this->username = $username;
        return $this;
    }

    public function getPassword(): string {
        return $this->password;
    }

    public function setPassword(string $password): self {
        $this->password = $password;
        return $this;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function setPath(string $path): self {
        $path = ltrim($path,'/');
        if ($path === '') {
            $path = 'INBOX';
        }
        $this->path = $path;
        return $this;
    }

    public function getImapServerPart(): string {
        return $this->imapServerPart;
    }

    protected function updateImapServerPart(): self {
        $serverPart = '{' . $this->host;
        if ($this->port > 0) {
            $serverPart .= ':' . $this->port;
        }
        if (!empty($this->scheme)) {
            switch (strtolower($this->scheme)) {
                case 'imap-ssl':
                    $serverPart .= '/ssl';
                    break;
                case 'imap-ssl-novalidate':
                    $serverPart .= '/ssl/novalidate-cert';
                    break;
                case 'imap-tls':
                    $serverPart .= '/tls';
                    break;
                default:
            }
        }
        $serverPart .= '}';
        $this->imapServerPart = $serverPart;
        return $this;
    }

    public function validate() {
        return ($this->scheme !== '' && $this->host !== '' && $this->username !== '' && $this->password !== '' && $this->path !== '');
    }
}
