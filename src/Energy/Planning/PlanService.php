<?php
declare(strict_types=1);

namespace Solportalen\Energy\Planning;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Solportalen\Config\Env;
use Solportalen\Energy\Optimizer\DynamicProgrammingOptimizer;
use Solportalen\Energy\Pricing\ElectricityTax;
use Solportalen\Repository\StateRepository;

final class PlanService
{
    public function __construct(private readonly PDO $pdo) {}

    public function generate(int $hours = 48): ?int
    {
        $prices = $this->prices($hours); if ($prices === []) return null;
        $weather = $this->weather(); $loadProfile = $this->loadProfile(); $events = $this->evProfile();
        $intervals = [];
        foreach ($prices as $price) {
            $start = new DateTimeImmutable($price['interval_start'], new DateTimeZone('UTC')); $end = new DateTimeImmutable($price['interval_end'], new DateTimeZone('UTC'));
            $duration = ($end->getTimestamp()-$start->getTimestamp())/3600; $local = $start->setTimezone(new DateTimeZone('Europe/Copenhagen'));
            $profileKey = $local->format('N-G'); $baseW = (float) ($loadProfile[$profileKey] ?? Env::get('DEFAULT_LOAD_W','900'));
            $evW = $this->expectedEvW($local, $events); $weatherKey = $start->format('Y-m-d-H'); $pvW = (float) ($weather[$weatherKey]['expected_pv_w'] ?? 0);
            $intervals[] = ['starts_at'=>$start->format('Y-m-d H:i:s'),'ends_at'=>$end->format('Y-m-d H:i:s'),'buy_price'=>(float)$price['total_dkk_kwh'],'load_kwh'=>round(($baseW+$evW)*$duration/1000,4),'solar_kwh'=>round($pvW*$duration/1000,4),'confidence'=>$loadProfile===[]?.48:.72];
        }
        $state = (new StateRepository($this->pdo))->current();
        $battery = ['soc_pct'=>(float)($state['battery_soc_pct']??50),'capacity_kwh'=>(float)Env::get('BATTERY_CAPACITY_KWH','6.5'),'min_soc_pct'=>(float)Env::get('BATTERY_MIN_SOC_PCT','20'),'reserve_pct'=>(float)Env::get('BATTERY_RESERVE_PCT','20'),'max_soc_pct'=>(float)Env::get('BATTERY_MAX_SOC_PCT','95'),'max_charge_w'=>(int)Env::get('BATTERY_MAX_CHARGE_W','2500'),'max_discharge_w'=>(int)Env::get('BATTERY_MAX_DISCHARGE_W','2500'),'round_trip_efficiency'=>(float)Env::get('BATTERY_ROUND_TRIP_EFFICIENCY','.88'),'wear_dkk_kwh'=>(float)Env::get('BATTERY_WEAR_DKK_KWH','.12'),'allow_grid_charge'=>true];
        $optimized = (new DynamicProgrammingOptimizer())->optimize($intervals,$battery); if ($optimized===[]) return null;
        $hash = hash('sha256',json_encode([$intervals,$battery],JSON_THROW_ON_ERROR));
        $existing=$this->pdo->prepare('SELECT id FROM plans WHERE input_hash=? ORDER BY id DESC LIMIT 1');$existing->execute([$hash]);$id=$existing->fetchColumn();if($id!==false)return (int)$id;
        $baseline=array_sum(array_column($optimized,'baseline_cost'));$cost=array_sum(array_column($optimized,'optimized_cost'));$saving=max(0,$baseline-$cost);
        $explanation=sprintf('%d intervaller optimeret over %.1f timer. Forventet omkostning %.2f kr. mod %.2f kr. uden plan.',count($optimized),$hours,$cost,$baseline);
        $this->pdo->beginTransaction();
        try{$statement=$this->pdo->prepare('INSERT INTO plans(generated_at,mode,input_hash,expected_saving_dkk,explanation) VALUES(UTC_TIMESTAMP(6),"shadow",?,?,?)');$statement->execute([$hash,$saving,$explanation]);$planId=(int)$this->pdo->lastInsertId();$row=$this->pdo->prepare('INSERT INTO plan_intervals(plan_id,starts_at,ends_at,action,power_w,soc_before,soc_after,buy_price,baseline_cost,optimized_cost,explanation,confidence) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');foreach($optimized as $item)$row->execute([$planId,$item['starts_at'],$item['ends_at'],$item['action'],$item['power_w'],$item['soc_before'],$item['soc_after'],$item['buy_price'],$item['baseline_cost'],$item['optimized_cost'],$item['explanation'],$item['confidence']]);$this->pdo->commit();return $planId;}catch(\Throwable $error){$this->pdo->rollBack();throw$error;}
    }

    public function latest(): array
    {
        $this->expireApprovedPlans();
        $plan=$this->pdo->query('SELECT p.*,a.approved_at,a.expires_at,a.approved_by,a.status approval_status FROM plans p LEFT JOIN plan_approvals a ON a.plan_id=p.id ORDER BY p.id DESC LIMIT 1')->fetch();if(!$plan)return [];
        $statement=$this->pdo->prepare('SELECT starts_at,ends_at,action,power_w,soc_before,soc_after,buy_price,baseline_cost,optimized_cost,explanation,confidence FROM plan_intervals WHERE plan_id=? ORDER BY starts_at');$statement->execute([$plan['id']]);$rows=$statement->fetchAll();
        $mode=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='requested_battery_mode'")->fetchColumn();$fallback=$this->fallbackMode();
        $command=$this->pdo->prepare("SELECT id,status,error_message,completed_at FROM commands WHERE command_type='apply_approved_plan' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.plan_id'))=? ORDER BY id DESC LIMIT 1");$command->execute([(string)$plan['id']]);
        $plan['intervals']=$rows;$plan['horizon_hours']=$rows===[]?0:round((strtotime(end($rows)['ends_at'])-strtotime($rows[0]['starts_at']))/3600,1);$plan['action_intervals']=count(array_filter($rows,fn($r)=>$r['action']!=='hold'));$plan['csrf_token']=self::csrfToken();$plan['requested_battery_mode']=$mode?:$fallback;$plan['fallback_mode']=$fallback;$plan['writes_enabled']=Env::bool('WRITES_ENABLED');$plan['apply_command']=$command->fetch()?:null;return $plan;
    }

    public function approve(int $planId,string $token,string $approvedBy): array
    {
        if(!hash_equals(self::csrfToken(),$token)&&!hash_equals(self::csrfToken('-1 day'),$token))throw new RuntimeException('Ugyldigt godkendelsestoken. Genindlæs siden.');
        $statement=$this->pdo->prepare('SELECT p.id,p.input_hash,MAX(i.ends_at) expires_at FROM plans p JOIN plan_intervals i ON i.plan_id=p.id WHERE p.id=? GROUP BY p.id,p.input_hash');$statement->execute([$planId]);$plan=$statement->fetch();if(!$plan)throw new RuntimeException('Planen findes ikke.');
        if(strtotime((string)$plan['expires_at'].' UTC')<=time())throw new RuntimeException('Planen er allerede udløbet og kan ikke godkendes.');
        $insert=$this->pdo->prepare('INSERT INTO plan_approvals(plan_id,approved_at,expires_at,approved_by,approval_token_hash,status) VALUES(?,UTC_TIMESTAMP(6),?,?,?,"approved_shadow") ON DUPLICATE KEY UPDATE approved_at=VALUES(approved_at),expires_at=VALUES(expires_at),approved_by=VALUES(approved_by),approval_token_hash=VALUES(approval_token_hash),status="approved_shadow"');
        // Store only a one-way digest of the presented anti-CSRF token.
        $insert->execute([$planId,$plan['expires_at'],$approvedBy,hash('sha256',$token)]);
        $fallback=$this->fallbackMode();$mode=$this->pdo->prepare("INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES('requested_battery_mode','approved_plan',?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),reason=VALUES(reason),updated_at=VALUES(updated_at)");$mode->execute(['Manuelt godkendt plan #'.$planId.' er gyldig til '.$plan['expires_at'].' UTC; derefter '.$fallback]);
        $audit=$this->pdo->prepare('INSERT INTO audit_log(actor,action,object_type,object_id,before_json,after_json,reason,ip_address,correlation_id,created_at) VALUES(?,"approve_shadow_plan","plan",?,NULL,?,"Manuel godkendelse; ingen Modbus-writes",?,UUID(),UTC_TIMESTAMP(6))');$audit->execute([$approvedBy,(string)$planId,json_encode(['status'=>'approved_shadow','input_hash'=>$plan['input_hash']],JSON_THROW_ON_ERROR),$approvedBy]);
        return ['plan_id'=>$planId,'status'=>'approved_shadow','expires_at'=>$plan['expires_at'].'Z','fallback_mode'=>$fallback,'writes_executed'=>false,'approved_at'=>gmdate(DATE_ATOM)];
    }

    public function expireApprovedPlans(): int
    {
        $count=$this->pdo->exec("UPDATE plan_approvals SET status='expired' WHERE status='approved_shadow' AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP(6)");
        if($count>0){$fallback=$this->fallbackMode();$statement=$this->pdo->prepare("INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES('requested_battery_mode',?,'Den godkendte plan er udløbet',UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value),reason=VALUES(reason),updated_at=VALUES(updated_at)");$statement->execute([$fallback]);}
        return (int)$count;
    }

    public function queueApply(int$planId,string$token,string$actor):array
    {
        if(!Env::bool('WRITES_ENABLED'))throw new RuntimeException('Write-kanalen er ikke aktiveret.');
        if(!hash_equals(self::csrfToken(),$token)&&!hash_equals(self::csrfToken('-1 day'),$token))throw new RuntimeException('Ugyldigt godkendelsestoken. Genindlæs siden.');
        $check=$this->pdo->prepare("SELECT p.id FROM plans p JOIN plan_approvals a ON a.plan_id=p.id WHERE p.id=? AND a.status='approved_shadow' AND a.expires_at>UTC_TIMESTAMP(6)");$check->execute([$planId]);if(!$check->fetchColumn())throw new RuntimeException('Planen skal være aktuelt godkendt før anvendelse.');
        $key='manual-plan-'.$planId.'-'.bin2hex(random_bytes(8));$insert=$this->pdo->prepare("INSERT INTO commands(command_type,payload_json,status,requested_by,reason,idempotency_key,created_at) VALUES('apply_approved_plan',?,'pending',NULL,?,?,UTC_TIMESTAMP(6))");$insert->execute([json_encode(['plan_id'=>$planId,'actor'=>$actor],JSON_THROW_ON_ERROR),'Manuel anvendelse af godkendt plan',$key]);
        return['command_id'=>(int)$this->pdo->lastInsertId(),'plan_id'=>$planId,'status'=>'pending','automatic_replanning'=>false];
    }

    public static function csrfToken(string $modify='now'): string{return hash_hmac('sha256',(new DateTimeImmutable($modify,new DateTimeZone('UTC')))->format('Y-m-d'),(string)Env::get('APP_KEY','development-only'));}

    private function fallbackMode():string{$value=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='fallback_mode'")->fetchColumn();return in_array($value,['battery_first','load_first'],true)?(string)$value:'battery_first';}

    private function prices(int $hours):array{$rows=$this->pdo->query('SELECT interval_start,interval_end,spot_dkk_kwh FROM prices WHERE interval_end>=UTC_TIMESTAMP() AND interval_start<UTC_TIMESTAMP()+INTERVAL '.max(1,min(48,$hours)).' HOUR ORDER BY interval_start')->fetchAll();$tz=new DateTimeZone('Europe/Copenhagen');$tax=ElectricityTax::blendedRate();foreach($rows as &$r){$h=(int)(new DateTimeImmutable($r['interval_start'],new DateTimeZone('UTC')))->setTimezone($tz)->format('G');$tariff=$h<6?(float)Env::get('GRID_TARIFF_LOW_DKK','.1062'):($h>=17&&$h<21?(float)Env::get('GRID_TARIFF_PEAK_DKK','.4141'):(float)Env::get('GRID_TARIFF_HIGH_DKK','.1593'));$r['total_dkk_kwh']=round(((float)$r['spot_dkk_kwh']+$tariff+$tax+(float)Env::get('SUPPLIER_MARKUP_DKK','0'))*1.25,4);}unset($r);return$rows;}
    private function weather():array{$rows=$this->pdo->query('SELECT forecast_at,expected_pv_w FROM weather_forecasts WHERE forecast_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR')->fetchAll();$result=[];foreach($rows as$r)$result[(new DateTimeImmutable($r['forecast_at'],new DateTimeZone('UTC')))->format('Y-m-d-H')]=$r;return$result;}
    private function loadProfile():array{$rows=$this->pdo->query("SELECT DAYOFWEEK(source_timestamp) dow,HOUR(source_timestamp) hour,AVG(value_decimal) watts FROM telemetry WHERE signal_name='load_power_w' AND source_timestamp>UTC_TIMESTAMP()-INTERVAL 28 DAY GROUP BY DAYOFWEEK(source_timestamp),HOUR(source_timestamp)")->fetchAll();$result=[];foreach($rows as$r){$iso=((int)$r['dow']+5)%7+1;$result[$iso.'-'.$r['hour']]=(float)$r['watts'];}return$result;}
    private function evProfile():array{return$this->pdo->query('SELECT WEEKDAY(started_at)+1 weekday,HOUR(started_at) start_hour,AVG(TIMESTAMPDIFF(MINUTE,started_at,ended_at)) duration_min,AVG(detected_load_w) watts,COUNT(*) samples FROM consumption_events WHERE ended_at IS NOT NULL AND started_at>UTC_TIMESTAMP()-INTERVAL 42 DAY GROUP BY WEEKDAY(started_at),HOUR(started_at) HAVING COUNT(*)>=2')->fetchAll();}
    private function expectedEvW(DateTimeImmutable $local,array $events):float{foreach($events as$event){if((int)$event['weekday']!==(int)$local->format('N'))continue;$start=(int)$event['start_hour'];$duration=max(1,(float)$event['duration_min']/60);$hour=(int)$local->format('G')+(int)$local->format('i')/60;if($hour>=$start&&$hour<$start+$duration)return(float)$event['watts'];}return 0;}
}
