<?php
class Configuration {
    protected bool $verbose = false;
    protected bool $test = false;
    protected bool $listFolder = false;
    protected bool $wipe = false;
    protected string $source = '';
    protected string $target = '';
    protected string $memory = '';
    protected array $mapFolder = [];

    public function __construct(int $argc, array $argv) {
        $this->parseArgs($argc, $argv);
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
                $this->source = $argv[$i];
            } else if (in_array($argv[$i], ['--target', '-t'])) {
                $i++;
                if (empty($argv[$i])) {
                    echo 'You must specify a target IMAP server.' . PHP_EOL;
                    exit(1);
                }
                $this->target = $argv[$i];
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

    protected function validate() {
        $errors = [];
        if (empty($this->source)) {
            $errors[] = 'You must specify a source IMAP server.';
        }
        if (empty($this->target)) {
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

    public function getSource(): string {
        return $this->source;
    }

    public function getTarget(): string {
        return $this->target;
    }

    public function getMapFolder(): array {
        return $this->mapFolder;
    }

    public function getMemory(): string {
        return $this->memory;
    }
}
