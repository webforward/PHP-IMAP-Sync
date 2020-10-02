<?php
class Configuration {
    protected bool $verbose = false;
    protected bool $test = false;
    protected bool $listFolder = false;
    protected bool $wipe = false;
    protected Server $source;
    protected string $sourceUrl = '';
    protected string $sourceUsername = '';
    protected string $sourcePassword = '';
    protected Server $target;
    protected string $targetUrl = '';
    protected string $targetUsername = '';
    protected string $targetPassword = '';
    protected string $memory = '';
    protected array $mapFolder = [];

    public function __construct(int $argc, array $argv) {
        $this->source = new Server();
        $this->target = new Server();
        $this->parseArgs($argc, $argv);
        $this->configureServer($this->source, $this->sourceUrl, $this->sourceUsername, $this->sourcePassword);
        $this->configureServer($this->target, $this->targetUrl, $this->targetUsername, $this->targetPassword);
        $this->validate();
        return $this;
    }

    protected function parseArgs(int $argc, array $argv) {
        for ($i = 1; $i < $argc; $i++) {
            if (in_array($argv[$i], ['--verbose', '-v'])) {
                $this->verbose = true;
            } else if (in_array($argv[$i], ['--dry-run', '--test', '-t'])) {
                $this->test = true;
            } else if (in_array($argv[$i], ['--listFolder'])) {
                $this->listFolder = true;
            } else if (in_array($argv[$i], ['--wipe', '-w'])) {
                $this->wipe = true;
            } else if (in_array($argv[$i], ['--source', '-s'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a source IMAP server.' . PHP_EOL;
                    exit(1);
                }
                $this->sourceUrl = $argv[$i];
            } else if (in_array($argv[$i], ['--sourceUsername'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a source username.' . PHP_EOL;
                    exit(1);
                }
                $this->sourceUsername = $argv[$i];
            } else if (in_array($argv[$i], ['--sourcePassword'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a source password.' . PHP_EOL;
                    exit(1);
                }
                $this->sourcePassword = $argv[$i];
            } else if (in_array($argv[$i], ['--target', '-t'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a target IMAP server.' . PHP_EOL;
                    exit(1);
                }
                $this->targetUrl = $argv[$i];
            } else if (in_array($argv[$i], ['--targetUsername'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a target username.' . PHP_EOL;
                    exit(1);
                }
                $this->targetUsername = $argv[$i];
            } else if (in_array($argv[$i], ['--targetPassword'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a target password.' . PHP_EOL;
                    exit(1);
                }
                $this->targetPassword = $argv[$i];
            } else if (in_array($argv[$i], ['--mapFolder'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a map folder value.' . PHP_EOL;
                    exit(1);
                }
                $data = json_decode($argv[$i], true);
                if (json_last_error()) {
                    echo 'Folder mapping failed: ' . json_last_error_msg() . PHP_EOL;
                    exit(1);
                }
                $this->mapFolder = $data;
                $this->verbose = true;
                $this->test = true;
            } else if (in_array($argv[$i], ['--memory', '-m'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a memory value.' . PHP_EOL;
                    exit(1);
                }
                $this->memory = $argv[$i];
            }
        }
    }

    protected function configureServer(Server &$server, string $url, string $username, string $password) {
        $url = trim($url);
        $isJson = (strpos($url, '{') === 0);
        if ($isJson) {
            $jsonConfig = json_decode($url);
            if (json_last_error()) {
                echo 'Error converting json config: ' . json_last_error_msg() . PHP_EOL;
                exit(1);
            }
            $url = isset($jsonConfig->url) ? $jsonConfig->url : '';
        }

        $uri = parse_url($url);
        if (isset($uri['scheme'])) {
            $server->setScheme($uri['scheme']);
        }
        if (isset($uri['host'])) {
            $server->setHost($uri['host']);
        }
        if (isset($uri['port'])) {
            $server->setPort($uri['port']);
        }
        if (isset($uri['user'])) {
            $server->setUsername($uri['user']);
        }
        if (isset($uri['pass'])) {
            $server->setPassword($uri['pass']);
        }
        if (isset($uri['path'])) {
            $server->setPath($uri['path']);
        }

        if ($isJson) {
            if (isset($jsonConfig->username)) {
                $server->setUsername($jsonConfig->username);
            }
            if (isset($jsonConfig->password)) {
                $server->setPassword($jsonConfig->password);
            }
        }

        if ($username !== '') {
            $server->setUsername($username);
        }
        if ($password !== '') {
            $server->setPassword($password);
        }
    }

    protected function validate() {
        $errors = [];
        if (!($this->source instanceof Server && $this->source->validate())) {
            $errors[] = 'You must specify a source IMAP server.';
        }
        if (!($this->target instanceof Server && $this->target->validate())) {
            $errors[] = 'You must specify a target IMAP server.';
        }
        if (count($errors)) {
            echo implode(PHP_EOL, $errors) . PHP_EOL;
            exit(1);
        }
    }

    public function isVerbose(): bool {
        return $this->verbose;
    }

    public function isTest(): bool {
        return $this->test;
    }

    public function isListFolder(): bool {
        return $this->listFolder;
    }

    public function isWipe(): bool {
        return $this->wipe;
    }

    public function getSource(): Server {
        return $this->source;
    }

    public function getTarget(): Server {
        return $this->target;
    }

    public function getMapFolder(): array {
        return $this->mapFolder;
    }

    public function getMemory(): string {
        return $this->memory;
    }
}
