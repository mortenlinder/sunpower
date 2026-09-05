<?php
declare(strict_types=1);

namespace Solportalen\Application;

use PDO;
use Solportalen\Config\Env;
use Solportalen\Device\Growatt\GrowattSphControl;
use Solportalen\Energy\Planning\GrowattWindowCompiler;

final class ManualPlanCommandProcessor
{
    public function __construct(private readonly PDO $pdo,private readonly GrowattSphControl $control){}

    public function tick():void
    {
        $this->expireSchedule();$command=$this->pdo->query("SELECT id,command_type,payload_json FROM commands WHERE command_type IN ('apply_approved_plan','apply_fallback_mode') AND status='pending' ORDER BY id LIMIT 1")->fetch();if(!$command)return;
        $claim=$this->pdo->prepare("UPDATE commands SET status='claimed',claimed_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'");$claim->execute([$command['id']]);if($claim->rowCount()!==1)return;
        try{$payload=json_decode($command['payload_json'],true,32,JSON_THROW_ON_ERROR);if($command['command_type']==='apply_fallback_mode'){$active=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='manual_schedule'")->fetchColumn();if($active){$activeSchedule=json_decode((string)$active,true);if(isset($activeSchedule['valid_until'])&&strtotime($activeSchedule['valid_until'])>time()){$cancel=$this->pdo->prepare("UPDATE commands SET status='cancelled',completed_at=UTC_TIMESTAMP(6),error_message='En godkendt plan er stadig aktiv' WHERE id=?");$cancel->execute([$command['id']]);return;}}$result=$this->control->setFallbackMode((string)$payload['mode']);$this->state('requested_battery_mode',(string)$payload['mode'],'Fallback anvendt fra panelet');$this->audit('apply_fallback_mode',(string)$command['id'],$result,'Fallback anvendt og verificeret');$done=$this->pdo->prepare("UPDATE commands SET status='verified',completed_at=UTC_TIMESTAMP(6),error_message=NULL WHERE id=?");$done->execute([$command['id']]);return;}$automatic=(bool)($payload['automatic']??false);$schedule=(new GrowattWindowCompiler($this->pdo))->compile((int)$payload['plan_id']);$schedule['source']=$automatic?'automatically_approved_plan':'manually_approved_plan';$schedule['automatic_replanning']=$automatic;$result=$this->control->applySchedule($schedule);$result['schedule']=$schedule;
            $this->state('manual_schedule',json_encode($schedule,JSON_THROW_ON_ERROR),$automatic?'Automatisk plan anvendt':'Planen er manuelt anvendt og udløber automatisk');$this->audit('apply_approved_plan',(string)$payload['plan_id'],$result,$automatic?'Automatisk plan anvendt og verificeret':'Manuelt anvendt plan');$done=$this->pdo->prepare("UPDATE commands SET status='verified',completed_at=UTC_TIMESTAMP(6),error_message=NULL WHERE id=?");$done->execute([$command['id']]);
        }catch(\Throwable$error){$failed=$this->pdo->prepare("UPDATE commands SET status='failed',completed_at=UTC_TIMESTAMP(6),error_message=? WHERE id=?");$failed->execute([substr($error->getMessage(),0,500),$command['id']]);$this->audit('apply_approved_plan_failed',(string)$command['id'],['error'=>$error->getMessage()],'Plananvendelse fejlede eller blev rullet tilbage');}
    }

    private function expireSchedule():void{$raw=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='manual_schedule'")->fetchColumn();if(!$raw)return;$schedule=json_decode((string)$raw,true);if(!isset($schedule['valid_until'])||strtotime($schedule['valid_until'])>time())return;$fallback=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='fallback_mode'")->fetchColumn()?:'battery_first';if(Env::bool('WRITES_ENABLED'))$this->control->setFallbackMode((string)$fallback);$label=$fallback==='battery_first'?'Battery First':'Load First';$this->state('manual_schedule','', 'Planvinduet er udløbet; '.$label.' er verificeret');$this->state('requested_battery_mode',(string)$fallback,'Planvindue udløbet');$this->audit('manual_schedule_expired',(string)($schedule['plan_id']??''),['fallback'=>$fallback],'Automatisk sikkerhedsudløb');}
    private function state(string$key,string$value,string$reason):void{$s=$this->pdo->prepare('INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),reason=VALUES(reason),updated_at=VALUES(updated_at)');$s->execute([$key,$value,$reason]);}
    private function audit(string$action,string$id,array$data,string$reason):void{$s=$this->pdo->prepare('INSERT INTO audit_log(actor,action,object_type,object_id,before_json,after_json,reason,ip_address,correlation_id,created_at) VALUES("device-worker",?,"plan",?,NULL,?,?,NULL,UUID(),UTC_TIMESTAMP(6))');$s->execute([$action,$id,json_encode($data,JSON_THROW_ON_ERROR),$reason]);}
}
