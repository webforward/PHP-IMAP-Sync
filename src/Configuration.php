<?php
class Configuration {
    protected bool $verbose = false;
    protected bool $test = false;
    protected bool $wipe = false;
    protected string $source = '';
    protected string $target = '';
    protected string $memory = '';

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

    public function isWipe(): bool {
        return $this->wipe;
    }

    public function getSource(): string {
        return $this->source;
    }

    public function getTarget(): string {
        return $this->target;
    }

    public function getMemory(): string {
        return $this->memory;
    }
}
