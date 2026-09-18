<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/bootstrap.php';
$lock=fopen(sys_get_temp_dir().'/solportal-cloud-'.hash('sha256',__DIR__).'.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);
if (($argv[1]??'')==='migrate') { $db->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql')); echo "Portal schema ready\n"; exit; }
if (($config['mail_transport']??'')!=='sendmail' || !filter_var($config['mail_from'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure mail delivery');
$q=$db->query('SELECT * FROM cloud_mail WHERE sent_at IS NULL AND attempts<8 AND next_attempt<=UTC_TIMESTAMP() ORDER BY id LIMIT 20');
foreach ($q as $mail) {
    $headers='From: Solportalen <'.$config['mail_from'].">\r\nTo: ".$mail['recipient']."\r\nSubject: =?UTF-8?B?".base64_encode($mail['subject'])."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $process=proc_open([$config['sendmail_path'],'-i','-t','-f',$config['mail_from']],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    $ok=false;
    if (is_resource($process)) { fwrite($pipes[0],$headers.chunk_split(base64_encode($mail['body']))); fclose($pipes[0]); $ok=proc_close($process)===0; }
    if ($ok) { $s=$db->prepare('UPDATE cloud_mail SET sent_at=UTC_TIMESTAMP(),body="",attempts=attempts+1 WHERE id=?'); $s->execute([$mail['id']]); }
    else { $s=$db->prepare('UPDATE cloud_mail SET attempts=attempts+1,next_attempt=? WHERE id=?'); $s->execute([gmdate('Y-m-d H:i:s',time()+min(21600,60*2**(int)$mail['attempts'])),$mail['id']]); error_log('Solportal mail delivery failed: message '.(int)$mail['id']); }
}
$db->exec('DELETE FROM cloud_limits WHERE expires_at<UTC_TIMESTAMP()-INTERVAL 1 DAY');
$db->exec('DELETE FROM cloud_tokens WHERE expires_at<UTC_TIMESTAMP()-INTERVAL 1 DAY');
$db->exec('DELETE FROM cloud_mail WHERE created_at<UTC_TIMESTAMP()-INTERVAL 7 DAY');
$db->exec('DELETE FROM cloud_samples WHERE captured_at<UTC_TIMESTAMP()-INTERVAL 24 MONTH');
$db->exec('DELETE FROM cloud_audit WHERE created_at<UTC_TIMESTAMP()-INTERVAL 24 MONTH');
$db->exec('DELETE FROM cloud_commands WHERE created_at<UTC_TIMESTAMP()-INTERVAL 24 MONTH');
