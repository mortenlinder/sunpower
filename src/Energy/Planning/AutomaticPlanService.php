<?php
declare(strict_types=1);

namespace Solportalen\Energy\Planning;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Solportalen\Config\Env;

final class AutomaticPlanService
{
    public function __construct(private readonly PDO $pdo){}

    public function run(?int $planId,bool $refreshNow=false):array
    {
        $store=new AutomationSettings($this->pdo);$settings=$store->get();$now=new DateTimeImmutable('now',new DateTimeZone('Europe/Copenhagen'));$today=$now->format('Y-m-d');
        if(!$settings['enabled'])return['status'=>'disabled'];
        if($settings['enabled_until']!==null&&$today>$settings['enabled_until']){$store->put('intelligent_control_enabled','0','Kalenderperioden er udløbet');return['status'=>'expired','fallback_mode'=>$settings['fallback_mode']];}
        if(!Env::bool('WRITES_ENABLED'))return['status'=>'blocked','reason'=>'Write-kanalen er ikke aktiveret'];
        $priceUntil=$this->pdo->query('SELECT MAX(interval_end) FROM prices')->fetchColumn();if(!$priceUntil)return['status'=>'waiting_for_market'];
        // Re-evaluate measured SOC/load even when the market horizon is unchanged.
        // A daily key previously suppressed later plans while the UI kept generating drafts.
        $last=$settings['last_auto_plan'];
        if($last && in_array($last['command_status'],['pending','claimed'],true))return['status'=>'awaiting_device','plan_id'=>$last['plan_id']];
        if(!$refreshNow&&!self::refreshDue($last,(string)$priceUntil,$settings['last_auto_price_until'],time()))return['status'=>'already_planned','plan_id'=>$last['plan_id'],'price_until'=>$priceUntil];
        if(!$planId)return['status'=>'waiting_for_data'];
        $expires=$this->pdo->prepare('SELECT MAX(ends_at) FROM plan_intervals WHERE plan_id=?');$expires->execute([$planId]);$expiresAt=$expires->fetchColumn();if(!$expiresAt)return['status'=>'waiting_for_data'];
        if($settings['enabled_until']!==null){$calendarEnd=(new DateTimeImmutable($settings['enabled_until'],$now->getTimezone()))->modify('+1 day')->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$expiresAt=min((string)$expiresAt,$calendarEnd);}
        $this->pdo->beginTransaction();try{
            $approval=$this->pdo->prepare('INSERT INTO plan_approvals(plan_id,approved_at,expires_at,approved_by,approval_token_hash,status) VALUES(?,UTC_TIMESTAMP(6),?,"system:auto",?,"approved_shadow") ON DUPLICATE KEY UPDATE approved_at=VALUES(approved_at),expires_at=VALUES(expires_at),approved_by=VALUES(approved_by),approval_token_hash=VALUES(approval_token_hash),status="approved_shadow"');$approval->execute([$planId,$expiresAt,hash('sha256','automatic-'.$today)]);
            $key='automatic-plan-'.$planId.'-'.intdiv(time(),900);$command=$this->pdo->prepare("INSERT IGNORE INTO commands(command_type,payload_json,status,requested_by,reason,idempotency_key,created_at) VALUES('apply_approved_plan',?,'pending',NULL,'Daglig intelligent styring',?,UTC_TIMESTAMP(6))");$command->execute([json_encode(['plan_id'=>$planId,'actor'=>'system:auto','automatic'=>true],JSON_THROW_ON_ERROR),$key]);
            if($command->rowCount()===0){$this->pdo->rollBack();return['status'=>'already_queued','plan_id'=>$planId];}
            $store->put('last_auto_plan_date',$today,'Automatisk plan lagt i kø');$store->put('last_auto_price_until',(string)$priceUntil,'Seneste prishorisont anvendt i automatisk plan');$store->put('requested_battery_mode','approved_plan','Automatisk plan #'.$planId.' er lagt i kø');
            $audit=$this->pdo->prepare('INSERT INTO audit_log(actor,action,object_type,object_id,before_json,after_json,reason,ip_address,correlation_id,created_at) VALUES("system:auto","queue_automatic_plan","plan",?,NULL,?,"Daglig intelligent styring",NULL,UUID(),UTC_TIMESTAMP(6))');$audit->execute([(string)$planId,json_encode(['expires_at'=>$expiresAt,'fallback_mode'=>$settings['fallback_mode']],JSON_THROW_ON_ERROR)]);$this->pdo->commit();
        }catch(\Throwable$error){$this->pdo->rollBack();throw$error;}
        return['status'=>'queued','plan_id'=>$planId,'date'=>$today,'price_until'=>$priceUntil,'fallback_mode'=>$settings['fallback_mode']];
    }

    public static function refreshDue(?array $last,string $priceUntil,?string $previousPriceUntil,int $now):bool
    {
        if(!$last)return true;
        if(in_array($last['command_status'],['pending','claimed'],true))return false;
        if(!empty($last['applied_until'])&&strtotime($last['applied_until'])<=$now)return true;
        if(!$previousPriceUntil||strtotime($priceUntil.' UTC')>strtotime($previousPriceUntil.' UTC'))return true;
        return $now-strtotime($last['queued_at'].' UTC')>=900;
    }
}
