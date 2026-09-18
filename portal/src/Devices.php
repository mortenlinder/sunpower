<?php
declare(strict_types=1);
namespace SolportalCloud;
use PDO;
use RuntimeException;
final class Devices
{
    public const MODES=['load_first','battery_first','charge_grid','grid_first'];
    public function __construct(private PDO $db) {}
    public function owned(int $user,string $id): array
    {
        $q=$this->db->prepare('SELECT * FROM cloud_installations WHERE id=? AND user_id=?'); $q->execute([$id,$user]);
        return $q->fetch() ?: throw new RuntimeException('Anlægget findes ikke.');
    }
    public function create(int $user,string $name): array
    {
        $name=trim($name); if ($name==='' || mb_strlen($name)>80) throw new RuntimeException('Navngiv anlægget med højst 80 tegn.');
        $q=$this->db->prepare('SELECT COUNT(*) FROM cloud_installations WHERE user_id=?'); $q->execute([$user]);
        if ((int)$q->fetchColumn()>=10) throw new RuntimeException('Der kan højst oprettes 10 anlæg på en konto.');
        $id=bin2hex(random_bytes(16)); $code=strtoupper(bin2hex(random_bytes(12)));
        $q=$this->db->prepare('INSERT INTO cloud_installations(id,user_id,name,pair_hash,pair_expires) VALUES(?,?,?,?,?)');
        $q->execute([$id,$user,$name,hash('sha256',$code),gmdate('Y-m-d H:i:s',time()+900)]);
        Security::audit($this->db,$user,$id,'create_installation'); return ['id'=>$id,'code'=>$code];
    }
    public function pair(string $code): array
    {
        $code=strtoupper(trim($code)); if (!preg_match('/^[A-F0-9]{24}$/',$code)) throw new RuntimeException('Parringskoden er ugyldig eller udløbet.');
        $token=bin2hex(random_bytes(32));
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT id FROM cloud_installations WHERE pair_hash=? AND pair_expires>UTC_TIMESTAMP() AND token_hash IS NULL FOR UPDATE');
            $q->execute([hash('sha256',$code)]); $id=$q->fetchColumn(); if (!$id) throw new RuntimeException('Parringskoden er ugyldig eller udløbet.');
            $q=$this->db->prepare('UPDATE cloud_installations SET token_hash=?,pair_hash=NULL,pair_expires=NULL WHERE id=?'); $q->execute([hash('sha256',$token),$id]);
            Security::audit($this->db,null,$id,'paired'); $this->db->commit(); return ['installation_id'=>$id,'token'=>$token];
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function authenticate(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/',$token)) throw new RuntimeException('Device authentication failed');
        $q=$this->db->prepare('SELECT * FROM cloud_installations WHERE token_hash=?'); $q->execute([hash('sha256',$token)]);
        return $q->fetch() ?: throw new RuntimeException('Device authentication failed');
    }
    public function receive(array $device,array $input): array
    {
        $now=time(); $state=$input['state']??[]; if (!is_array($state)) throw new RuntimeException('Invalid state');
        $sample=(int)($input['captured_at']??0);
        if ($sample>$now+30 || $sample<$now-300) throw new RuntimeException('Sample is stale or clock is incorrect');
        $clean=[];
        foreach (['pv_power_w'=>[0,1000000],'load_power_w'=>[0,1000000],'grid_power_w'=>[-1000000,1000000],'battery_power_w'=>[-1000000,1000000],'battery_soc_pct'=>[0,100]] as $key=>[$min,$max]) {
            $v=$state[$key]??null;
            if ($v!==null && (!is_numeric($v) || !is_finite((float)$v) || $v<$min || $v>$max)) throw new RuntimeException('Invalid telemetry range');
            $clean[$key]=$v===null?null:round((float)$v,2);
        }
        $clean['priority_mode']=in_array($state['priority_mode']??'',array_merge(self::MODES,['unknown']),true)?$state['priority_mode']:'unknown';
        $clean['override_until']=max(0,min($now+7200,(int)($state['override_until']??0)));
        $clean['quality']=substr((string)($state['quality']??'unknown'),0,40);
        $clean['writes_enabled']=($state['writes_enabled']??false)===true;
        $clean['control_allowed']=($input['control_allowed']??false)===true;
        $at=gmdate('Y-m-d H:i:s',$sample);
        $q=$this->db->prepare('UPDATE cloud_installations SET last_seen=UTC_TIMESTAMP(),control_allowed=?,state_json=IF(sample_at IS NULL OR sample_at<=?, ?,state_json),sample_at=GREATEST(COALESCE(sample_at,?),?) WHERE id=?');
        $q->execute([(int)$clean['control_allowed'],$at,json_encode($clean,JSON_THROW_ON_ERROR),$at,$at,$device['id']]);
        // One value per minute limits growth; no missing samples are filled with invented zeroes.
        $q=$this->db->prepare('INSERT IGNORE INTO cloud_samples(installation_id,captured_at,pv_w,load_w,grid_w,battery_w,soc) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$device['id'],gmdate('Y-m-d H:i:00',$sample),$clean['pv_power_w'],$clean['load_power_w'],$clean['grid_power_w'],$clean['battery_power_w'],$clean['battery_soc_pct']]);
        foreach (array_slice(is_array($input['acks']??null)?$input['acks']:[],0,10) as $ack) {
            if (!is_array($ack) || !in_array($ack['status']??'',['verified','failed','cancelled'],true)) continue;
            $q=$this->db->prepare("UPDATE cloud_commands SET status=?,completed_at=UTC_TIMESTAMP(),result_json=? WHERE id=? AND installation_id=? AND status IN ('pending','expired')");
            $q->execute([$ack['status'],json_encode(['message'=>substr((string)($ack['message']??''),0,300)]),$ack['id']??'',$device['id']]);
        }
        $q=$this->db->prepare("UPDATE cloud_commands SET status='expired',completed_at=UTC_TIMESTAMP() WHERE installation_id=? AND status='pending' AND expires_at<=UTC_TIMESTAMP()"); $q->execute([$device['id']]);
        $q=$this->db->prepare("SELECT id,mode,duration_minutes,UNIX_TIMESTAMP(expires_at) AS expires_at FROM cloud_commands WHERE installation_id=? AND status='pending' AND expires_at>UTC_TIMESTAMP() ORDER BY created_at LIMIT 1"); $q->execute([$device['id']]);
        return ['server_time'=>$now,'commands'=>$q->fetchAll()];
    }
    public function mode(int $user,string $id,string $mode,int $minutes): string
    {
        $d=$this->owned($user,$id); $state=json_decode($d['state_json']??'{}',true);
        if (!$d['control_allowed'] || empty($state['writes_enabled']) || !$d['sample_at'] || strtotime($d['sample_at'].' UTC')<time()-60) throw new RuntimeException('Fjernstyring kræver friske data og lokal tilladelse på Pi’en.');
        if (!in_array($mode,self::MODES,true) || !in_array($minutes,[15,30,60,120],true)) throw new RuntimeException('Ugyldig mode eller varighed.');
        $this->db->beginTransaction();
        try {
            // Serialize concurrent owner submissions on this installation.
            $q=$this->db->prepare('SELECT id FROM cloud_installations WHERE id=? FOR UPDATE'); $q->execute([$id]);
            $q=$this->db->prepare("SELECT id FROM cloud_commands WHERE installation_id=? AND status='pending' AND expires_at>UTC_TIMESTAMP()"); $q->execute([$id]);
            if ($q->fetch()) throw new RuntimeException('Afvent svar på den seneste kommando.');
            $command=bin2hex(random_bytes(16));
            $q=$this->db->prepare('INSERT INTO cloud_commands(id,installation_id,mode,duration_minutes,expires_at) VALUES(?,?,?,?,?)');
            $q->execute([$command,$id,$mode,$minutes,gmdate('Y-m-d H:i:s',time()+120)]);
            Security::audit($this->db,$user,$id,'request_mode:'.$mode); $this->db->commit(); return $command;
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function summary(int $minimum): array
    {
        // Fixed, previous half-hour blocks and at least five opted-in installations per block.
        // This is aggregated/pseudonymous data, not a guarantee of mathematical anonymity.
        $start=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Copenhagen')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $cutoff=gmdate('Y-m-d H:i:s',intdiv(time(),1800)*1800-1800);
        $q=$this->db->prepare('SELECT FLOOR(UNIX_TIMESTAMP(s.captured_at)/1800)*1800 AS t, SUM(s.pv_w)/60000 AS kwh, COUNT(DISTINCT s.installation_id) AS contributors FROM cloud_samples s JOIN cloud_installations d ON d.id=s.installation_id WHERE d.share_public=1 AND s.captured_at>=? AND s.captured_at<? AND s.pv_w IS NOT NULL GROUP BY t HAVING contributors>=? ORDER BY t');
        $q->execute([$start,$cutoff,max(5,$minimum)]); $rows=$q->fetchAll();
        return ['points'=>array_map(static fn($r)=>['t'=>(int)$r['t'],'kwh'=>round((float)$r['kwh'],1)],$rows),'today_kwh'=>$rows?round(array_sum(array_column($rows,'kwh')),1):null,'minimum'=>max(5,$minimum),'delay_minutes'=>30];
    }
}
