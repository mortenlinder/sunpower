<?php
declare(strict_types=1);
namespace Solportalen\Integration\Cloud;
use PDO;
use RuntimeException;
use Solportalen\Config\Env;
final class CloudAgent
{
    private const URL='https://solpanel.linder.dk/';
    public function __construct(private PDO $pdo) {}
    public static function credentialsPath(): string { return SOLPORTAL_ROOT.'/var/cloud-device.json'; }
    public static function credentials(): ?array
    {
        $file=self::credentialsPath();
        if (!is_file($file)) return null;
        $data=json_decode((string)file_get_contents($file),true,8,JSON_THROW_ON_ERROR);
        return is_array($data)?$data:null;
    }
    public static function allowsControl(): bool { return (self::credentials()['allow_control']??false)===true; }
    private static function save(array $data): void
    {
        $file=self::credentialsPath(); $tmp=$file.'.'.bin2hex(random_bytes(6));
        $old=umask(0077);
        try {
            if (file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false || !chmod($tmp,0600) || !rename($tmp,$file)) throw new RuntimeException('Enhedsnøglen kunne ikke gemmes sikkert.');
        } finally { umask($old); }
    }
    public function pair(string $code): void
    {
        if (self::credentials()) throw new RuntimeException('Pi’en er allerede parret. Brug cloud:unpair før en ny parring.');
        $data=$this->request('pair',['code'=>trim($code)]);
        if (!preg_match('/^[a-f0-9]{64}$/',$data['token']??'') || !preg_match('/^[a-f0-9]{32}$/',$data['installation_id']??'')) throw new RuntimeException('Ugyldigt parringssvar.');
        self::save(['token'=>$data['token'],'installation_id'=>$data['installation_id'],'allow_control'=>false]);
    }
    public static function control(bool $enabled): void
    {
        $data=self::credentials(); if (!$data) throw new RuntimeException('Par først Pi’en med din konto.');
        $data['allow_control']=$enabled; self::save($data);
    }
    public function run(bool $once=false): void
    {
        $lock=fopen(SOLPORTAL_ROOT.'/var/cloud-agent.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Cloud-agenten kører allerede.');
        do {
            try { $this->exchange(); }
            catch (\Throwable $e) { fwrite(STDERR,gmdate(DATE_ATOM).' Portalforbindelse fejlede: '.$e->getMessage().PHP_EOL); if ($once) throw $e; }
            if (!$once) sleep(15);
        } while (!$once);
    }
    public function exchange(): void
    {
        $credentials=self::credentials(); if (!$credentials) return;
        $rows=$this->pdo->query("SELECT signal_name,value_json,source_timestamp FROM current_state WHERE signal_name IN ('pv_power_w','load_power_w','grid_power_w','battery_power_w','battery_soc_pct','priority_mode')")->fetchAll();
        $state=[]; $captured=time();
        foreach ($rows as $row) { $state[$row['signal_name']]=json_decode($row['value_json'],true); $captured=min($captured,strtotime($row['source_timestamp'].' UTC')); }
        if (count($rows)<6 || time()-$captured>60) throw new RuntimeException('Lokale målinger er ikke friske; sender ikke gamle tal som live.');
        $raw=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='remote_override'")->fetchColumn();
        $override=$raw?json_decode($raw,true):[]; $state['override_until']=$override['until']??0;
        $state['writes_enabled']=Env::bool('WRITES_ENABLED'); $state['quality']='local_measurements';
        $commands=$this->pdo->query("SELECT idempotency_key,status,error_message FROM commands WHERE command_type='remote_mode' AND status IN ('verified','failed','cancelled') ORDER BY id DESC LIMIT 10")->fetchAll();
        $acks=array_map(static fn($c)=>['id'=>substr($c['idempotency_key'],6),'status'=>$c['status'],'message'=>$c['error_message']??'Registerindstillinger verificeret på inverteren.'],$commands);
        $result=$this->request('exchange',['captured_at'=>$captured,'state'=>$state,'control_allowed'=>$credentials['allow_control'],'acks'=>$acks],$credentials['token']);
        if (abs((int)($result['server_time']??0)-time())>60) throw new RuntimeException('Urene på Pi og portal afviger. Ret tidsynkronisering.');
        foreach (array_slice($result['commands']??[],0,1) as $command) {
            RemoteMode::validate($command,time());
            $allowed=$credentials['allow_control']===true && Env::bool('WRITES_ENABLED');
            $q=$this->pdo->prepare("INSERT IGNORE INTO commands(command_type,payload_json,status,reason,idempotency_key,created_at,error_message) VALUES('remote_mode',?,?,'Tidsbegrænset mode fra ejerens portal',?,UTC_TIMESTAMP(6),?)");
            $q->execute([json_encode($command,JSON_THROW_ON_ERROR),$allowed?'pending':'cancelled','cloud-'.$command['id'],$allowed?null:'Lokal fjernstyring eller writes er slået fra']);
        }
    }
    private function request(string $api,array $payload,?string $token=null): array
    {
        $h=curl_init(self::URL.'?api='.$api); $body='';
        $headers=['Content-Type: application/json','Accept: application/json']; if ($token) $headers[]='Authorization: Bearer '.$token;
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>32768)return 0;$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($h); $status=curl_getinfo($h,CURLINFO_RESPONSE_CODE); curl_close($h);
        if (!$ok || $status!==200) throw new RuntimeException('HTTPS-kald afvist eller utilgængeligt (HTTP '.$status.').');
        $data=json_decode($body,true,16,JSON_THROW_ON_ERROR); if (!is_array($data)) throw new RuntimeException('Ugyldigt portalsvar.'); return $data;
    }
}
