<?php
// Explicit CLI test: reads password from stdin, sends exactly one test to itself.
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/src/Mailer.php';
$password=rtrim((string)fgets(STDIN),"\r\n");
$id=bin2hex(random_bytes(8));
$mailer=new SolportalCloud\Mailer(['mail_transport'=>'smtp','mail_from'=>'mail@systems.linder.dk','smtp_host'=>'web01.vipsupport.dk','smtp_port'=>587,'smtp_user'=>'mail@systems.linder.dk','smtp_password'=>$password]);
$ok=$mailer->send('mail@systems.linder.dk','Solportalen SMTP-test '.$id,"Teknisk test af Solportalens mailafsendelse med SMTP-login og verificeret TLS.\n\nIngen handling er nødvendig. Test-id: ".$id);
echo $ok?'SMTP_ACCEPTED test_id='.$id.PHP_EOL:'SMTP_FAILED'.PHP_EOL;
exit($ok?0:1);
