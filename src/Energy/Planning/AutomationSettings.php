<?php
declare(strict_types=1);

namespace Solportalen\Energy\Planning;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class AutomationSettings
{
    public function __construct(private readonly PDO $pdo) {}

    public function get(): array
    {
        $keys=['intelligent_control_enabled','intelligent_control_until','plan_horizon_hours','fallback_mode','last_auto_plan_date','last_auto_price_until'];
        $quoted="'".implode("','",$keys)."'";$rows=$this->pdo->query("SELECT state_key,state_value FROM operational_state WHERE state_key IN ($quoted)")->fetchAll();$values=[];
        foreach($rows as$row)$values[$row['state_key']]=$row['state_value'];
        $latest=$this->pdo->query("SELECT id,status,payload_json,created_at,completed_at,error_message FROM commands WHERE reason='Daglig intelligent styring' ORDER BY id DESC LIMIT 1")->fetch()?:null;$lastPlan=null;
        if($latest){$payload=json_decode((string)$latest['payload_json'],true);$planId=(int)($payload['plan_id']??0);$expiry=null;if($planId){$q=$this->pdo->prepare('SELECT expires_at FROM plan_approvals WHERE plan_id=?');$q->execute([$planId]);$expiry=$q->fetchColumn()?:null;}$scheduleRaw=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='manual_schedule'")->fetchColumn();$schedule=$scheduleRaw?json_decode((string)$scheduleRaw,true):[];$lastPlan=['plan_id'=>$planId,'command_status'=>$latest['status'],'queued_at'=>$latest['created_at'],'applied_at'=>$latest['completed_at'],'applied_until'=>(($schedule['plan_id']??null)===$planId?$schedule['valid_until']??null:null),'price_plan_until'=>$expiry,'error'=>$latest['error_message']];}
        return ['enabled'=>($values['intelligent_control_enabled']??'0')==='1','enabled_until'=>($values['intelligent_control_until']??'')?:null,'horizon_hours'=>(int)($values['plan_horizon_hours']??48),'fallback_mode'=>$values['fallback_mode']??'battery_first','last_auto_plan_date'=>($values['last_auto_plan_date']??'')?:null,'last_auto_price_until'=>($values['last_auto_price_until']??'')?:null,'last_auto_plan'=>$lastPlan,'csrf_token'=>PlanService::csrfToken()];
    }

    public function save(array $input,string $token,string $actor):array
    {
        if(!hash_equals(PlanService::csrfToken(),$token)&&!hash_equals(PlanService::csrfToken('-1 day'),$token))throw new RuntimeException('Ugyldigt sikkerhedstoken. Genindlæs siden.');
        $enabled=filter_var($input['enabled']??false,FILTER_VALIDATE_BOOL);$horizon=(int)($input['horizon_hours']??48);if(!in_array($horizon,[24,36,48],true))throw new RuntimeException('Planhorisonten skal være 24, 36 eller 48 timer.');
        $fallback=(string)($input['fallback_mode']??'battery_first');if(!in_array($fallback,['battery_first','load_first'],true))throw new RuntimeException('Fallback skal være Battery First eller Load First.');
        $until=trim((string)($input['enabled_until']??''));if($until!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$until,new DateTimeZone('Europe/Copenhagen'));if(!$date||$date->format('Y-m-d')!==$until)throw new RuntimeException('Slutdatoen er ugyldig.');if($date<new DateTimeImmutable('today',new DateTimeZone('Europe/Copenhagen')))throw new RuntimeException('Slutdatoen kan ikke ligge i fortiden.');}
        $this->pdo->beginTransaction();try{$this->put('intelligent_control_enabled',$enabled?'1':'0','Ændret i panelet');$this->put('intelligent_control_until',$until,'Ændret i panelet');$this->put('plan_horizon_hours',(string)$horizon,'Ændret i panelet');$this->put('fallback_mode',$fallback,'Standardtilstand uden aktiv plan');$command=$this->pdo->prepare("INSERT INTO commands(command_type,payload_json,status,requested_by,reason,idempotency_key,created_at) VALUES('apply_fallback_mode',?,'pending',NULL,'Fallback ændret i panelet',?,UTC_TIMESTAMP(6))");$command->execute([json_encode(['mode'=>$fallback,'actor'=>$actor],JSON_THROW_ON_ERROR),'fallback-'.bin2hex(random_bytes(12))]);$audit=$this->pdo->prepare('INSERT INTO audit_log(actor,action,object_type,object_id,before_json,after_json,reason,ip_address,correlation_id,created_at) VALUES(?,"update_automation_settings","settings","energy",NULL,?,"Driftsindstilling ændret i lokalt panel",?,UUID(),UTC_TIMESTAMP(6))');$audit->execute([$actor,json_encode(['enabled'=>$enabled,'enabled_until'=>$until?:null,'horizon_hours'=>$horizon,'fallback_mode'=>$fallback],JSON_THROW_ON_ERROR),$actor]);$this->pdo->commit();}catch(\Throwable$error){$this->pdo->rollBack();throw$error;}
        return $this->get();
    }

    public function put(string$key,string$value,string$reason):void{$statement=$this->pdo->prepare('INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),reason=VALUES(reason),updated_at=VALUES(updated_at)');$statement->execute([$key,$value,$reason]);}
}
