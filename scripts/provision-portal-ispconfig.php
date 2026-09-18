<?php
// Run locally as root on the authorized ISPConfig host, never through HTTP.
// Uses ISPConfig validation/plugins/datalog, not hand-written website records.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    exit("Root CLI required.\n");
}
if (gethostname() !== 'web01') exit("Unexpected deployment host.\n");
error_reporting(E_ERROR | E_PARSE);
chdir('/usr/local/ispconfig/interface/web/remote');
define('REMOTE_API_CALL', true);
require '../../lib/config.inc.php';
$conf['start_session'] = false;
require '../../lib/app.inc.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$app->load('remoting');
require '../../lib/classes/remote.d/sites.inc.php';
require '../../lib/classes/remote.d/mail.inc.php';
$session = bin2hex(random_bytes(32));
$failed = false;
$app->db->query('INSERT INTO remote_session (remote_session,remote_userid,remote_functions,client_login,tstamp,remote_ip) VALUES (?,1,?,0,?,?)', $session, 'sites_web_domain_add;mail_user_add', time()+120, '127.0.0.1');
try {
    $site = $app->db->queryOneRecord('SELECT domain_id FROM web_domain WHERE domain = ?', 'solpanel.linder.dk');
    if (!$site) {
        $params = [
            'server_id'=>1, 'ip_address'=>'*', 'ipv6_address'=>'',
            'domain'=>'solpanel.linder.dk', 'type'=>'vhost', 'parent_domain_id'=>0,
            'vhost_type'=>'name', 'hd_quota'=>2048, 'traffic_quota'=>-1,
            'document_root'=>'', 'system_user'=>'', 'system_group'=>'',
            'cgi'=>'n', 'ssi'=>'n', 'suexec'=>'y', 'errordocs'=>0,
            'allow_override'=>'All', 'http_port'=>80, 'https_port'=>443,
            'is_subdomainwww'=>0, 'subdomain'=>'none', 'php'=>'php-fpm',
            'server_php_id'=>2, 'active'=>'y', 'ssl'=>'y', 'ssl_letsencrypt'=>'y',
            'ssl_domain'=>'solpanel.linder.dk', 'ssl_redirect'=>'y',
            'php_fpm_use_socket'=>'y', 'pm'=>'ondemand', 'pm_max_children'=>4,
            'pm_start_servers'=>1, 'pm_min_spare_servers'=>1, 'pm_max_spare_servers'=>2,
            'pm_process_idle_timeout'=>20, 'pm_max_requests'=>500,
            'log_retention'=>14, 'backup_interval'=>'daily', 'backup_copies'=>7,
            'apache_directives'=>"DocumentRoot /var/www/solpanel.linder.dk/web/public\n<Directory /var/www/solpanel.linder.dk/web/public>\nOptions -Indexes\nAllowOverride All\nRequire all granted\n</Directory>",
        ];
        $id = (new remoting_sites())->sites_web_domain_add($session, 3, $params);
        echo "Created ISPConfig site ID $id\n";
    } else echo "Site already exists; left unchanged.\n";
    $mail = $app->db->queryOneRecord('SELECT mailuser_id FROM mail_user WHERE email = ?', 'mail@systems.linder.dk');
    if (!$mail) {
        echo "Mailbox password (stdin, do not echo):\n";
        while (ob_get_level()) ob_end_flush();
        $password = trim(fgets(STDIN));
        if (strlen($password)<12) throw new RuntimeException('Password missing/too short.');
        $id = (new remoting_mail())->mail_user_add($session, 3, [
            'server_id'=>1, 'email'=>'mail@systems.linder.dk',
            'login'=>'mail@systems.linder.dk', 'name'=>'Solportalen',
            'password'=>$password, 'quota'=>1073741824, 'postfix'=>'y',
            'access'=>'y', 'disableimap'=>'n', 'disablepop3'=>'y',
            'disablesmtp'=>'n', 'disabledeliver'=>'n', 'autoresponder'=>'n',
            'autoresponder_start_date'=>'', 'autoresponder_end_date'=>'',
            'maildir'=>'', 'homedir'=>'', 'move_junk'=>'y',
            'purge_trash_days'=>0, 'purge_junk_days'=>0,
            'backup_interval'=>'none', 'backup_copies'=>0,
        ]);
        unset($password);
        echo "Created ISPConfig mailbox ID $id\n";
    } else echo "Mailbox already exists; password left unchanged.\n";
} catch (Throwable $error) {
    // ISPConfig exceptions can contain SQL/password hashes; never print them.
    fwrite(STDERR, "Provisioning failed; inspect the scoped server record and form defaults.\n");
    $failed = true;
} finally {
    $app->db->query('DELETE FROM remote_session WHERE remote_session = ?', $session);
}
exit($failed ? 1 : 0);
