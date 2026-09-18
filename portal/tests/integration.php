<?php
declare(strict_types=1);
// Run as a local DB administrator on a TEST host. A random, separate schema is used.
spl_autoload_register(static function($c){ if(str_starts_with($c,'SolportalCloud\\')) require dirname(__DIR__).'/src/'.substr($c,15).'.php'; });
use SolportalCloud\{Auth,Devices,Security};
date_default_timezone_set('UTC');
ob_start();
Security::session();
$admin=new PDO(getenv('TEST_MYSQL_DSN')?:'mysql:unix_socket=/run/mysqld/mysqld.sock',getenv('TEST_MYSQL_USER')?:'root',getenv('TEST_MYSQL_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema='solportal_test_'.bin2hex(random_bytes(6));
$admin->exec('CREATE DATABASE '.$schema); $admin->exec('USE '.$schema); $db=$admin;
$tests=0;
function check(bool $ok,string $name): void { global $tests; if(!$ok)throw new RuntimeException($name);$tests++;echo 'PASS '.$name.PHP_EOL; }
function rejects(callable $fn,string $name): void { try{$fn();}catch(RuntimeException $e){check(true,$name);return;}throw new RuntimeException('Did not reject: '.$name); }
function token(PDO $db,string $recipient): string { $q=$db->prepare('SELECT body FROM cloud_mail WHERE recipient=? ORDER BY id DESC LIMIT 1');$q->execute([$recipient]);preg_match('/#token=([a-f0-9]{64})/',$q->fetchColumn(),$m);return $m[1]; }
try {
    $db->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $cfg=['secret'=>str_repeat('a',64),'url'=>'https://solpanel.linder.dk','registration_enabled'=>true];
    $auth=new Auth($db,$cfg);$devices=new Devices($db);
    $message=SolportalCloud\Mailer::message('mail@example.test','user@example.test','Bekræft din e-mail','Danske tegn: æøå');
    check(str_contains($message,'Message-ID: <') && str_contains($message,base64_encode('Danske tegn: æøå')),'Mail has message ID and UTF-8-safe body');
    rejects(fn()=>SolportalCloud\Mailer::message('mail@example.test',"user@example.test\r\nBcc: attacker@example.test",'Test','Body'),'Mail header injection rejected');
    rejects(fn()=>Security::password('short'),'Short password rejected');
    rejects(fn()=>Security::csrf('forged'),'Forged CSRF rejected');
    $auth->register('one@example.test','A sufficiently long password');
    $verify=token($db,'one@example.test');
    check(!str_contains($db->query('SELECT token_hash FROM cloud_tokens LIMIT 1')->fetchColumn(),$verify),'Token only hashed in token table');
    rejects(fn()=>$auth->login('one@example.test','A sufficiently long password'),'Unverified login rejected');
    $auth->consume($verify,'verify');
    rejects(fn()=>$auth->consume($verify,'verify'),'Verification cannot be replayed');
    $auth->login('one@example.test','A sufficiently long password'); $one=(int)$auth->user()['id'];
    $before=(int)$db->query('SELECT COUNT(*) FROM cloud_mail')->fetchColumn();
    $auth->register('ONE@example.test','Another long password');
    check((int)$db->query('SELECT COUNT(*) FROM cloud_users')->fetchColumn()===1,'Email canonicalized; duplicate cannot overwrite');
    $auth->requestToken('one@example.test','reset');$reset=token($db,'one@example.test');
    $auth->consume($reset,'reset','My new strong long password');check($auth->user()===null,'Reset invalidates logged-in sessions');
    rejects(fn()=>$auth->consume($reset,'reset','Yet another secure password'),'Reset is one-use');
    rejects(fn()=>$auth->login('one@example.test','A sufficiently long password'),'Old password no longer accepted');
    $auth->login('one@example.test','My new strong long password');
    $auth->register('two@example.test','Second sufficiently long password');$auth->consume(token($db,'two@example.test'),'verify');
    $two=(int)$db->query("SELECT id FROM cloud_users WHERE email='two@example.test'")->fetchColumn();
    $pair=$devices->create($one,'My private roof');
    rejects(fn()=>$devices->owned($two,$pair['id']),'Cross-tenant access blocked');
    $paired=$devices->pair($pair['code']);
    rejects(fn()=>$devices->pair($pair['code']),'Pair code cannot be reused');
    check($devices->authenticate($paired['token'])['id']===$pair['id'],'Device authentication works');
    rejects(fn()=>$devices->authenticate(str_repeat('0',64)),'Forged device token blocked');
    $d=$devices->authenticate($paired['token']);$input=['captured_at'=>time(),'control_allowed'=>false,'state'=>['pv_power_w'=>1000,'load_power_w'=>500,'grid_power_w'=>-200,'battery_power_w'=>-300,'battery_soc_pct'=>50,'priority_mode'=>'battery_first','writes_enabled'=>true]];
    $devices->receive($d,$input);
    rejects(fn()=>$devices->mode($one,$pair['id'],'battery_first',15),'Local control permission required');
    $input['control_allowed']=true;$devices->receive($d,$input);
    $cmd=$devices->mode($one,$pair['id'],'charge_grid',15);
    rejects(fn()=>$devices->mode($one,$pair['id'],'load_first',15),'Concurrent pending command refused');
    $out=$devices->receive($d,$input);check(count($out['commands'])===1&&$out['commands'][0]['id']===$cmd,'Device gets its queued command');
    $input['acks']=[['id'=>$cmd,'status'=>'verified','message'=>'ok']];$devices->receive($d,$input);
    check($db->query("SELECT status FROM cloud_commands WHERE id='$cmd'")->fetchColumn()==='verified','Acknowledgement stored');
    rejects(fn()=>$devices->mode($one,$pair['id'],'raw_register',15),'Raw register command refused');
    rejects(fn()=>$devices->mode($one,$pair['id'],'load_first',999),'Excessive duration refused');
    $bad=$input;$bad['state']['battery_soc_pct']=101;rejects(fn()=>$devices->receive($d,$bad),'Telemetry range checked');
    $bad=$input;$bad['captured_at']=time()-500;rejects(fn()=>$devices->receive($d,$bad),'Old telemetry rejected');
    $db->exec("UPDATE cloud_installations SET share_public=1 WHERE id='{$pair['id']}'");
    check($devices->summary(5)['today_kwh']===null,'Single-house public production is withheld');
    $expired=$devices->create($two,'Expired');$db->exec("UPDATE cloud_installations SET pair_expires=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id='{$expired['id']}'");
    rejects(fn()=>$devices->pair($expired['code']),'Expired pairing code rejected');
    $db->exec("UPDATE cloud_installations SET sample_at=UTC_TIMESTAMP()-INTERVAL 5 MINUTE WHERE id='{$pair['id']}'");
    rejects(fn()=>$devices->mode($one,$pair['id'],'load_first',15),'Stale installation cannot receive new mode');
    Security::limit($db,$cfg['secret'],'test',1,60);rejects(fn()=>Security::limit($db,$cfg['secret'],'test',1,60),'Rate limiting enforced');
    echo "$tests portal integration tests passed\n";
} finally {
    // Only the exact random schema created by this test can be removed.
    if(preg_match('/^solportal_test_[a-f0-9]{12}$/',$schema))$admin->exec('DROP DATABASE '.$schema);
}
