<?php
// Authorized host-local ISPConfig administration. Never expose as a web route.
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || gethostname() !== 'web01') exit(1);
error_reporting(E_ERROR | E_PARSE);
chdir('/usr/local/ispconfig/interface/web/remote');
define('REMOTE_API_CALL', true);
require '../../lib/config.inc.php';
$conf['start_session'] = false;
require '../../lib/app.inc.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$app->load('remoting');
require '../../lib/classes/remote.d/mail.inc.php';
$domain = $app->db->queryOneRecord('SELECT * FROM mail_domain WHERE domain = ?', 'systems.linder.dk');
if (!$domain || (int)$domain['domain_id'] !== 26 || (int)$domain['sys_groupid'] !== 4) exit("Unexpected mail domain.\n");
$session = bin2hex(random_bytes(32));
$failed = false;
$app->db->query('INSERT INTO remote_session (remote_session,remote_userid,remote_functions,client_login,tstamp,remote_ip) VALUES (?,1,?,0,?,?)', $session, 'mail_domain_update', time()+120, '127.0.0.1');
try {
    if (empty($domain['dkim_private'])) {
        $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        if (!$key || !openssl_pkey_export($key,$private)) throw new RuntimeException('Key creation failed');
        $domain['dkim_private'] = $private;
        $domain['dkim_public'] = openssl_pkey_get_details($key)['key'];
        $domain['dkim_selector'] = 'solportal202609';
    }
    $domain['dkim'] = 'y';
    (new remoting_mail())->mail_domain_update($session,3,26,$domain);
    $public = str_replace(["-----BEGIN PUBLIC KEY-----","-----END PUBLIC KEY-----","\r","\n"],'',$domain['dkim_public']);
    echo json_encode(['name'=>$domain['dkim_selector'].'._domainkey.systems.linder.dk','type'=>'TXT','content'=>'v=DKIM1; k=rsa; p='.$public],JSON_UNESCAPED_SLASHES),"\n";
} catch (Throwable $e) {
    // ISPConfig errors can embed SQL/key material. Deliberately redact them.
    fwrite(STDERR,"DKIM update failed; inspect only scoped non-secret status.\n");
    $failed = true;
} finally {
    $app->db->query('DELETE FROM remote_session WHERE remote_session = ?',$session);
}
exit($failed?1:0);
