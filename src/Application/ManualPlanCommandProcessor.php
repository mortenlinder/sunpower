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
        $this->expireSchedule();$command=$this->pdo->query("SELECT id,payload_json FROM commands WHERE command_type='apply_approved_plan' AND status='pending' ORDER BY id LIMIT 1")->fetch();if(!$command)return;
        $claim=$this->pdo->prepare("UPDATE commands SET status='claimed',claimed_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'");$claim->execute([$command['id']]);if($claim->rowCount()!==1)return;
        try{$payload=json_decode($command['payload_json'],true,32,JSON_THROW_ON_ERROR);$schedule=(new GrowattWindowCompiler($this->pdo))->compile((int)$payload['plan_id']);$result=$this->control->applySchedule($schedule);$result['schedule']=$schedule;
            $done=$this->pdo->prepare("UPDATE commands SET status='verified',completed_at=UTC_TIMESTAMP(6) WHERE id=?");$done->execute([$command['id']]);$this->state('manual_schedule',json_encode($schedule,JSON_THROW_ON_ERROR),'Planen er manuelt anvendt og udløber automatisk');$this->audit('apply_approved_plan',(string)$payload['plan_id'],$result,'Manuelt anvendt plan; ingen automatisk replanning');
        }catch(\Throwable$error){$failed=$this->pdo->prepare("UPDATE commands SET status='failed',completed_at=UTC_TIMESTAMP(6),error_message=? WHERE id=?");$failed->execute([substr($error->getMessage(),0,500),$command['id']]);$this->audit('apply_approved_plan_failed',(string)$command['id'],['error'=>$error->getMessage()],'Plananvendelse fejlede eller blev rullet tilbage');}
    }

    private function expireSchedule():void{$raw=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='manual_schedule'")->fetchColumn();if(!$raw)return;$schedule=json_decode((string)$raw,true);if(!isset($schedule['valid_until'])||strtotime($schedule['valid_until'])>time())return;if(Env::bool('WRITES_ENABLED'))$this->control->setLoadFirst();$this->state('manual_schedule','', 'Planvinduet er udløbet; Load First er verificeret');$this->state('requested_battery_mode','load_first','Manuelt planvindue udløbet');$this->audit('manual_schedule_expired',(string)($schedule['plan_id']??''),['fallback'=>'load_first'],'Automatisk sikkerhedsudløb');}
    private function state(string$key,string$value,string$reason):void{$s=$this->pdo->prepare('INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),reason=VALUES(reason),updated_at=VALUES(updated_at)');$s->execute([$key,$value,$reason]);}
    private function audit(string$action,string$id,array$data,string$reason):void{$s=$this->pdo->prepare('INSERT INTO audit_log(actor,action,object_type,object_id,before_json,after_json,reason,ip_address,correlation_id,created_at) VALUES("device-worker",?,"plan",?,NULL,?,?,NULL,UUID(),UTC_TIMESTAMP(6))');$s->execute([$action,$id,json_encode($data,JSON_THROW_ON_ERROR),$reason]);}
}
