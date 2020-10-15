#!/usr/bin/env bash
set -e

php --define phar.readonly=0 create-phar.php
