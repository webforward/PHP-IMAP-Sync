#!/usr/bin/php
<?php
ini_set('memory_limit', '1024M');
/**
 *
 * Well, we had a little problem when migrating clients from other hosts
 * to our servers. Moving the files and MySQL databases was the easy part!
 * We developed PHP-IMAP-Sync to make it easier to transfer these accounts
 * and even keep our servers updated while the transfer was going on...
 *
 * Created by Webforward - www.webfwd.co.uk
 *
 */
$NL = "\n";
error_reporting(E_ALL | E_STRICT);

if (PHP_SAPI != "cli") throw_error('This script needs to be ran in CLI mode.');

$config = config_variables($argc, $argv);
$config['fresh']=true;

echo "Connecting Source...\n";
$s = new IMAP($config['source']);
$s_folders = $s->list_folders();


echo "Connecting Target...\n";
$t = new IMAP($config['target']);
$t_folders = $t->list_folders();

if (preg_match('/}INBOX/',$t_folders[0]['name'],$m)) $t_prefix = 'INBOX';


$summary = array(
    'Copied' => 0,
    'Updated' => 0,
    'Removed' => 0,
    'Exists' => 0,
    'Error'  => 0
);

if (is_array($s_folders)) {
    foreach ($s_folders as $s_folder) {

        if (mail_exclusions($s_folder['name'])) {
            echo 'Skipping '.$s_folder['name'].$NL;
            continue;
        }

        $s->set_folder($s_folder['name']);

        $s_folder_stats = $s->folder_status($s_folder['name']);

        if ($s_folder_stats['mail_count']) {

            if (preg_match('/}(.+)$/',$s_folder['name'],$m)) {
                $s_folder = str_replace('INBOX/',null,$m[1]);
                $t_folder = implode('.', array($t_prefix, $s_folder));
            }

            echo "Changing Folders - ({$s_folder}) ({$t_folder}) - {$s_folder_stats['mail_count']} messages\n";

            $t->set_folder($t_folder);
            $t_folder_stats = $t->folder_status($t_folder);

            $s_messages = array();
            echo "Indexing Source Folder";
            for ($i=1;$i<=$s_folder_stats['mail_count'];$i++) {
                $mail = $s->get_header($i);
                unset($mail['Msgno']);
                @$s_messages[$mail['message_id']] = md5(serialize(array(
                    'Unseen' => $mail['Unseen'],
                    'Flagged' => $mail['Flagged'],
                    'Answered' => $mail['Answered'],
                    'Deleted' => $mail['Deleted'],
                    'Draft' => $mail['Draft']
                )));
                echo '.';
            }
            echo "\n";

            $t_messages = array();
            echo "Indexing Target Folder";
            for ($i=1;$i<=$t_folder_stats['mail_count'];$i++) {
                $mail = $t->get_header($i);
                unset($mail['Msgno']);
                @$t_messages[$mail['message_id']] = md5(serialize(array(
                    'Unseen' => $mail['Unseen'],
                    'Flagged' => $mail['Flagged'],
                    'Answered' => $mail['Answered'],
                    'Deleted' => $mail['Deleted'],
                    'Draft' => $mail['Draft']
                )));
                echo '.';
            }
            echo "\n";

            // Remove any files that shouldn't be on target server

            for ($x=$t_folder_stats['mail_count'];$x>=1;$x--) {
                $message = $t->get_header($x);
                if (isset($message['message_id']) && !array_key_exists($message['message_id'],$s_messages)) {
                    $mail = $t->get_header($x);
                    $t->rem_message($x);
                    $summary['Removed']++;
                    echo " - ".str_pad($x, strlen($s_folder_stats['mail_count']), ' ', STR_PAD_LEFT)." : [REMOVE] {$s_folder} {$message['subject']}\n";
                }
            }


            for ($x=$s_folder_stats['mail_count'];$x>=1;$x--) {
                $updated=false;

                $message = $s->get_header($x);

                if (empty($message['subject'])) $message['subject'] = "*** No Subject ***";

                if (isset($message['message_id'])) {

                    if (array_key_exists($message['message_id'], $t_messages) && $t_messages[$message['message_id']]==$s_messages[$message['message_id']]) {
                        // Message already exists and has not changed
                        echo " - " . str_pad($x, strlen($s_folder_stats['mail_count']), ' ', STR_PAD_LEFT) . " : [EXISTS] {$s_folder} => {$t_folder} - {$message['subject']}\n";
                        $summary['Exists']++;
                    } else {
                        $s->get_message($x);

                        if (array_key_exists($message['message_id'], $t_messages) && $t_messages[$message['message_id']]<>$s_messages[$message['message_id']]) {
                            // This message has changed
                            $t->rem_message($x);
                            $updated=true;
                        }
                        //$flags = array('Unseen', 'Flagged', 'Answered', 'Deleted', 'Draft'); For later use?
                        // Message Options
                        $message['Unseen'] = trim($message['Unseen']);
                        $message['Flagged'] = trim($message['Flagged']);
                        $message['Answered'] = trim($message['Answered']);
                        $message['Deleted'] = trim($message['Deleted']);
                        $message['Draft'] = trim($message['Draft']);

                        $opts = array();
                        //if (!empty($message['Recent'])) $opts[] = '\Recent'; // May not be needed
                        if (empty($message['Unseen'])) $opts[] = '\Seen';
                        if (!empty($message['Flagged'])) $opts[] = '\Flagged';
                        if (!empty($message['Answered'])) $opts[] = '\Answered';
                        if (!empty($message['Deleted'])) $opts[] = '\Deleted';
                        if (!empty($message['Draft'])) $opts[] = '\Draft';
                        $opts = implode(' ',$opts);

                        $date = strftime('%d-%b-%Y %H:%M:%S +0000',strtotime($message['MailDate']));

                        if ($res = $t->put_message(file_get_contents('mail'),$opts,$date)) {
                            echo " - ".str_pad($x, strlen($s_folder_stats['mail_count']), ' ', STR_PAD_LEFT)." : *".($updated?'UPDATE':'COPIED')."* {$s_folder} => {$t_folder} - {$message['subject']} [{$opts}]\n";
                            $summary[($updated?'Updated':'Copied')]++;
                        } else {
                            echo " - ".str_pad($x, strlen($s_folder_stats['mail_count']), ' ', STR_PAD_LEFT)." : *ERROR!* {$s_folder} => {$t_folder} - {$message['subject']} [{$opts}]\n";
                            $summary['Error']++;
                        }
                    }
                }

            }
            echo "\n";

        }
    }
}

print_r($summary);

function throw_error($error) {
    echo $error;
    exit;
}

function config_variables($argc, $argv) {
    $config = array();

    for ($n=1; $n<$argc; $n++) {

        if ($argv[$n]=='--source' or $argv[$n]=='-s') {
            $n++;
            if (empty($argv[$n])) throw_error('You must specify a source IMAP server.');
            $config['source'] = parse_url($argv[$n]);
        }
        elseif ($argv[$n]=='--target' or $argv[$n]=='-t') {
            $n++;
            if (empty($argv[$n])) throw_error('You must specify a target IMAP server.');
            $config['target'] = parse_url($argv[$n]);
        }
        elseif ($argv[$n]=='--test' or $argv[$n]=='-t') {
            $n++;
            if ($argv[$n]=='1') $config['testmode'] = true;
        }
        elseif ($argv[$n]=='--fresh' or $argv[$n]=='-f') {
            $n++;
            if ($argv[$n]=='1') $config['fresh'] = true;
        }

    }

    if (!count($config)) throw_error('You must give this script something to do!');

    if (empty($config['source']['path']) or $config['source']['path']=='/') $config['source']['path'] = '/INBOX';
    if (empty($config['target']['path']) or $config['target']['path']=='/') $config['target']['path'] = '/INBOX';

    return $config;
}

function mail_exclusions($path)
{
    if ( ($path['attribute'] & LATT_NOSELECT) == LATT_NOSELECT) {
        return true;
    }
    // All Mail, Trash, Starred have this attribute
    if ( ($path['attribute'] & 96) == 96) {
        return true;
    }

    // Skip by Pattern
    if (preg_match('/}(.+)$/',$path['name'],$m)) {
        switch (strtolower($m[1])) {
            case '[gmail]/all mail':
            case '[gmail]/sent mail':
            case '[gmail]/spam':
            case '[gmail]/starred':
                return true;
        }
    }

    // By First Folder Part of Name
    if (preg_match('/}([^\/]+)/',$path['name'],$m)) {
        switch (strtolower($m[1])) {
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

function folder_modifications($x) {
    if (preg_match('/}(.+)$/',$x,$m)) {
        switch (strtolower($m[1])) {
            // case 'inbox':         return null;
            //case 'deleted items': return '[Gmail]/Trash';
            //case 'drafts':        return '[Gmail]/Drafts';
            //case 'junk e-mail':   return '[Gmail]/Spam';
            //case 'sent items':    return '[Gmail]/Sent Mail';
        }
        $x = str_replace('INBOX/',null,$m[1]);
    }
    return $x;
}


class IMAP
{
    private $connection;
    private $host;
    private $path;

    function __construct($in) {
        $this->connection = null;

        $this->host = sprintf('{%s',$in['host']);
        if (!empty($in['port'])) {
            $this->host.= sprintf(':%d',$in['port']);
        }
        if (strtolower(@$in['scheme'])=='imap-ssl') $this->host.= '/ssl/novalidate-cert';
        elseif (strtolower(@$in['scheme'])=='imap-ssl') $this->host.= '/tls';
        $this->host.= '}';
        $this->path = $this->host;

        if (!empty($in['path'])) {
            $x = ltrim($in['path'],'/');
            if (!empty($x)) {
                $this->path = $x;
            }
        }
        $this->connection = imap_open($this->host,$in['user'],$in['pass']);
    }

    function list_folders($pat='*') {
        $ret = array();
        $list = imap_getmailboxes($this->connection, $this->host,$pat);
        foreach ($list as $x) {
            $ret[] = array(
                'name' => $x->name,
                'attribute' => $x->attributes,
                'delimiter' => $x->delimiter,
            );
        }
        return $ret;
    }

    function get_message($i) {
        return imap_savebody($this->connection,'mail',$i,null,FT_PEEK);
    }

    function put_message($mail,$opts,$date) {
        $stat = $this->folder_status();
        $ret = imap_append($this->connection,$stat['check_path'],$mail,$opts,$date);
        if ($buf = imap_errors()) throw_error(print_r($buf,true));
        return $ret;

    }

    function get_header($i) {
        $head = imap_headerinfo($this->connection,$i);
        return (array)$head;
    }

    function rem_message($i) {
        if ((imap_delete($this->connection,$i)) ) return imap_expunge($this->connection);
    }

    function set_folder($p,$make=false) {
        if (substr($p,0,1)!='{') $p = $this->host . trim($p,'/');

        $ret = imap_reopen($this->connection,$p);
        $buf = imap_errors();
        if (empty($buf)) return true;
        $buf = implode(', ',$buf);

        if (preg_match('/(NONEXISTENT|Mailbox doesn\'t exist)/i',$buf)) {
            $ret = imap_createmailbox($this->connection,$p);
            $buf = imap_errors();
            if (empty($buf)) {
                imap_reopen($this->connection,$p);
                return true;
            }
            throw_error(print_r($buf,true)."\nFailed to Create setPath($this->connection - $p)\n");
        }

        throw_error(print_r($buf,true)."\nFailed to Switch setPath($p)\n");
    }

    function folder_status($p=null) {
        if (substr($p,0,1)!='{') $p = $this->host . trim($p,'/');

        $res = imap_status($this->connection, $p, SA_MESSAGES);
        $ret = array('mail_count' => $res->messages);
        $res = imap_check($this->connection);
        $ret['check_path'] = $res->Mailbox;
        return $ret;
    }

    /*
    function folder_status()
    {
        $res = imap_mailboxmsginfo($this->connection);
        $ret = array(
            'date' => $res->Date,
            'path' => $res->Mailbox,
            'mail_count' => $res->Nmsgs,
            'size' => $res->Size,
        );
        $res = imap_check($this->connection);
        $ret['check_date'] = $res->Date;
        $ret['check_mail_count'] = $res->Nmsgs;
        $ret['check_path'] = $res->Mailbox;
        return $ret;
    }
    */
}
