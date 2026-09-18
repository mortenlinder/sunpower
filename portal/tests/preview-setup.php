<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE IF NOT EXISTS solportal_cloud_preview');$db->exec('USE solportal_cloud_preview');
$db->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
$cfg=['dsn'=>'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=solportal_cloud_preview;charset=utf8mb4','db_user'=>'root','db_password'=>'','url'=>'https://solpanel.linder.dk','secret'=>bin2hex(random_bytes(32)),'registration_enabled'=>true,'public_min_installations'=>5];
$target=dirname(__DIR__).'/config.php'; if(is_file($target))throw new RuntimeException('Refusing to overwrite configuration');
file_put_contents($target,'<?php return '.var_export($cfg,true).';');chmod($target,0600);
$q=$db->prepare("INSERT IGNORE INTO cloud_users(email,password_hash,verified_at) VALUES('preview@example.test',?,UTC_TIMESTAMP())");$q->execute([password_hash('Local preview password 2026',PASSWORD_DEFAULT)]);
$uid=$db->query("SELECT id FROM cloud_users WHERE email='preview@example.test'")->fetchColumn();
$id=str_repeat('d',32);
$state=['pv_power_w'=>2170,'load_power_w'=>1530,'grid_power_w'=>210,'battery_power_w'=>-850,'battery_soc_pct'=>64,'priority_mode'=>'battery_first','writes_enabled'=>false];
$q=$db->prepare('INSERT INTO cloud_installations(id,user_id,name,last_seen,sample_at,state_json) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE sample_at=UTC_TIMESTAMP(),state_json=VALUES(state_json)');$q->execute([$id,$uid,'Designpreview · illustrative data',json_encode($state)]);
$q=$db->prepare('INSERT IGNORE INTO cloud_samples(installation_id,captured_at,pv_w,load_w,grid_w,battery_w,soc) VALUES(?,?,?,?,?,?,?)');
for($i=1440;$i>0;$i-=5){$t=time()-$i*60;$solar=max(0,sin(($t%86400-21600)/43200*pi()))*3300;$load=700+500*abs(sin($i/120));$q->execute([$id,gmdate('Y-m-d H:i:00',$t),$solar,$load,$load-$solar,0,64]);}
echo "Loopback preview ready (isolated test database, illustrative data only).\n";
