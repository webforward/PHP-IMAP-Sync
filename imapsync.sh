#!/bin/bash -x

# Example IMAP Sync

php phpimapsync.php --source imap-ssl://account1@domain1.com:password@incoming.mailserver1.com:993/ --target imap-ssl://account2@domain2.com:password@incoming.mailserver2.com:993/.
