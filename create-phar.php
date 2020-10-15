#!/usr/bin/env php
<?php
$appName = 'phpimapsync';
$filePhar = __DIR__ . '/' . $appName . '.phar';

if (file_exists($filePhar)) {
    unlink($filePhar);
}

$phar = new \Phar($filePhar, \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_FILENAME, $appName . '.phar');
$phar->startBuffering();
$phar->buildFromDirectory(__DIR__ . '/src', '/\.php$/');

$phar->setStub('#!/usr/bin/env php' . PHP_EOL . $phar->createDefaultStub($appName . '.php'));
$phar->stopBuffering();

exec('chmod +x ' . $filePhar);
