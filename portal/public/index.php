<?php
declare(strict_types=1);
use SolportalCloud\{Auth,Devices,Security};
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
try { require dirname(__DIR__).'/bootstrap.php'; }
catch (Throwable $e) { http_response_code(503); echo 'Solportalen klargøres. Prøv igen senere.'; error_log('Solportal bootstrap: '.$e->getMessage()); exit; }
if (($_SERVER['HTTPS']??'')!=='on') { header('Location: '.$config['url'].'/',true,308); exit; }
header('Strict-Transport-Security: max-age=31536000');
$devices=new Devices($db); $api=(string)($_GET['api']??'');
function jsonResponse(array $value,int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); exit; }
function inputJson(): array {
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>16384) throw new RuntimeException('Request too large');
    $raw=file_get_contents('php://input',false,null,0,16385);
    if (strlen($raw)>16384) throw new RuntimeException('Request too large');
    $input=json_decode($raw,true,12,JSON_THROW_ON_ERROR); if (!is_array($input)) throw new RuntimeException('Invalid payload'); return $input;
}
$ip=$_SERVER['REMOTE_ADDR']??'unknown';
if ($api!=='') {
    try {
        if ($api==='public' && $_SERVER['REQUEST_METHOD']==='GET') {
            Security::limit($db,$config['secret'],'public:'.$ip,120,60);
            jsonResponse($devices->summary((int)$config['public_min_installations']));
        }
        if (in_array($api,['pair','exchange'],true)) {
            if ($_SERVER['REQUEST_METHOD']!=='POST') jsonResponse(['error'=>'POST required'],405);
            Security::limit($db,$config['secret'],$api.':'.$ip,$api==='pair'?10:120,60);
            $input=inputJson();
            if ($api==='pair') jsonResponse($devices->pair((string)($input['code']??'')));
            $token=preg_replace('/^Bearer /','',$_SERVER['HTTP_AUTHORIZATION']??'');
            $device=$devices->authenticate($token); jsonResponse($devices->receive($device,$input));
        }
        Security::session(); $auth=new Auth($db,$config); $user=$auth->user(); if (!$user) jsonResponse(['error'=>'Log ind igen'],401);
        $id=(string)($_GET['id']??''); $d=$devices->owned((int)$user['id'],$id);
        if ($api==='state') {
            $q=$db->prepare('SELECT id,mode,duration_minutes,status,created_at,completed_at,result_json FROM cloud_commands WHERE installation_id=? ORDER BY created_at DESC LIMIT 10'); $q->execute([$id]);
            jsonResponse(['state'=>json_decode($d['state_json']??'{}',true),'sample_at'=>$d['sample_at']===null?null:strtotime($d['sample_at'].' UTC'),'last_seen'=>$d['last_seen'],'commands'=>$q->fetchAll()]);
        }
        if ($api==='history') {
            $days=max(1,min(730,(int)($_GET['days']??1))); $step=$days<=2?300:($days<=31?3600:86400);
            $q=$db->prepare('SELECT FLOOR(UNIX_TIMESTAMP(captured_at)/?)*? AS t, AVG(pv_w) AS pv, AVG(load_w) AS load_w, AVG(grid_w) AS grid_w, AVG(battery_w) AS battery, AVG(soc) AS soc, COUNT(*) AS samples FROM cloud_samples WHERE installation_id=? AND captured_at>=? GROUP BY t ORDER BY t');
            $q->execute([$step,$step,$id,gmdate('Y-m-d H:i:s',time()-$days*86400)]); jsonResponse(['points'=>$q->fetchAll(),'step'=>$step]);
        }
        jsonResponse(['error'=>'Not found'],404);
    } catch (Throwable $e) { jsonResponse(['error'=>$e instanceof PDOException?'Tjenesten er midlertidigt utilgængelig.':$e->getMessage()],http_response_code()===429?429:400); }
}
Security::session(); $auth=new Auth($db,$config); $user=$auth->user();
$page=(string)($_GET['page']??'home'); $notice=$_SESSION['notice']??null; unset($_SESSION['notice']); $error=null; $pair=null;
function redirectPage(string $page): never { header('Location: /?page='.$page,true,303); exit; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        Security::csrf((string)($_POST['csrf']??'')); $action=(string)($_POST['action']??'');
        Security::limit($db,$config['secret'],'post:'.$ip,40,900);
        if (in_array($action,['register','login','forgot','resend'],true)) {
            Security::limit($db,$config['secret'],'account:'.strtolower(trim((string)($_POST['email']??''))),10,900);
        }
        switch ($action) {
            case 'register':
                if (($_POST['password']??'')!==($_POST['password_confirm']??'')) throw new RuntimeException('Adgangskoderne er ikke ens.');
                $auth->register((string)$_POST['email'],(string)$_POST['password']);
                $_SESSION['notice']='Hvis adressen kan oprettes, sender vi et bekræftelseslink. Tjek også spam.'; redirectPage('login');
            case 'login': $auth->login((string)$_POST['email'],(string)$_POST['password']); redirectPage('dashboard');
            case 'logout': $auth->logout(); redirectPage('home');
            case 'forgot': case 'resend':
                $auth->requestToken((string)$_POST['email'],$action==='forgot'?'reset':'verify');
                $notice='Hvis adressen findes og er berettiget, sender vi et link. Tjek også spam.'; break;
            case 'verify': $auth->consume((string)($_POST['token']??''),'verify'); $_SESSION['notice']='E-mailen er bekræftet. Du kan nu logge ind.'; redirectPage('login');
            case 'reset':
                if (($_POST['password']??'')!==($_POST['password_confirm']??'')) throw new RuntimeException('Adgangskoderne er ikke ens.');
                $auth->consume((string)($_POST['token']??''),'reset',(string)$_POST['password']); $auth->logout(); $_SESSION['notice']='Adgangskoden er ændret. Log ind med din nye kode.'; redirectPage('login');
            default:
                if (!$user) throw new RuntimeException('Log ind for at fortsætte.');
                if ($action==='create') { $pair=$devices->create((int)$user['id'],(string)($_POST['name']??'')); break; }
                $id=(string)($_POST['id']??''); $d=$devices->owned((int)$user['id'],$id);
                if ($action==='mode') {
                    if (!password_verify((string)($_POST['password']??''),$user['password_hash'])) throw new RuntimeException('Bekræft ændringen med din adgangskode.');
                    $devices->mode((int)$user['id'],$id,(string)$_POST['mode'],(int)$_POST['minutes']);
                    $notice='Kommandoen er lagt i kø. Afvent Pi’ens verificerede svar — kø betyder ikke, at inverteren allerede har skiftet.';
                } elseif ($action==='share') {
                    $q=$db->prepare('UPDATE cloud_installations SET share_public=? WHERE id=? AND user_id=?'); $q->execute([isset($_POST['share'])?1:0,$id,$user['id']]);
                    Security::audit($db,(int)$user['id'],$id,'public_sharing:'.(isset($_POST['share'])?'on':'off')); $notice='Dit valg af datadeling er gemt.';
                } elseif ($action==='revoke') {
                    if (!password_verify((string)($_POST['password']??''),$user['password_hash'])) throw new RuntimeException('Bekræft med din adgangskode.');
                    $db->beginTransaction();
                    $q=$db->prepare('UPDATE cloud_installations SET token_hash=NULL,pair_hash=NULL,control_allowed=0,share_public=0 WHERE id=? AND user_id=?'); $q->execute([$id,$user['id']]);
                    $q=$db->prepare("UPDATE cloud_commands SET status='cancelled' WHERE installation_id=? AND status='pending'"); $q->execute([$id]);
                    Security::audit($db,(int)$user['id'],$id,'device_revoked'); $db->commit();
                    $notice='Forbindelsen er afkoblet. En allerede udført midlertidig mode udløber lokalt på Pi’en.';
                } elseif ($action==='repair') {
                    if ($d['token_hash']) throw new RuntimeException('Afkobl først den eksisterende forbindelse.');
                    $code=strtoupper(bin2hex(random_bytes(12)));
                    $q=$db->prepare('UPDATE cloud_installations SET pair_hash=?,pair_expires=? WHERE id=? AND user_id=?'); $q->execute([hash('sha256',$code),gmdate('Y-m-d H:i:s',time()+900),$id,$user['id']]); $pair=['id'=>$id,'code'=>$code];
                } else throw new RuntimeException('Ukendt handling.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error=$e instanceof PDOException?'Tjenesten kunne ikke gemme ændringen. Prøv igen.':$e->getMessage();
        if ($e instanceof PDOException) error_log('Solportal database action failed: '.$e->getCode());
    }
}
if ($page==='dashboard' && !$user) redirectPage('login');
$installations=[]; $selected=null;
if ($user) {
    $q=$db->prepare('SELECT * FROM cloud_installations WHERE user_id=? ORDER BY created_at'); $q->execute([$user['id']]); $installations=$q->fetchAll();
    foreach ($installations as $d) if ($d['id']===($_GET['id']??$installations[0]['id']??'')) $selected=$d;
}
$allowed=['home','guide','privacy','register','login','forgot','resend','reset','verify','dashboard'];
if (!in_array($page,$allowed,true)) { http_response_code(404); $page='home'; }
function e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
require dirname(__DIR__).'/views/layout.php';
