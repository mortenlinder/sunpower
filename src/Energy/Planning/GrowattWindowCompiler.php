<?php
declare(strict_types=1);

namespace Solportalen\Energy\Planning;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Solportalen\Config\Env;

final class GrowattWindowCompiler
{
    public function __construct(private readonly PDO $pdo){}

    public function compile(int $planId):array
    {
        $statement=$this->pdo->prepare("SELECT p.id,a.expires_at FROM plans p JOIN plan_approvals a ON a.plan_id=p.id WHERE p.id=? AND a.status='approved_shadow' AND a.expires_at>UTC_TIMESTAMP(6)");$statement->execute([$planId]);$plan=$statement->fetch();
        if(!$plan)throw new RuntimeException('Kun en aktuel, godkendt plan kan anvendes.');
        $until=min(time()+86400,strtotime((string)$plan['expires_at'].' UTC'));
        $rows=$this->pdo->prepare('SELECT starts_at,ends_at,action,power_w,soc_after,baseline_cost,optimized_cost FROM plan_intervals WHERE plan_id=? AND ends_at>UTC_TIMESTAMP(6) AND starts_at<FROM_UNIXTIME(?) ORDER BY starts_at');$rows->execute([$planId,$until]);
        $groups=['charge_grid'=>[],'discharge'=>[]];$tz=new DateTimeZone('Europe/Copenhagen');
        foreach($rows->fetchAll()as$row){$action=(string)$row['action'];if(!isset($groups[$action]))continue;$start=new DateTimeImmutable($row['starts_at'],new DateTimeZone('UTC'));$end=new DateTimeImmutable($row['ends_at'],new DateTimeZone('UTC'));
            foreach($this->splitMidnight($start->setTimezone($tz),$end->setTimezone($tz))as[$localStart,$localEnd]){$last=array_key_last($groups[$action]);$benefit=max(0,(float)$row['baseline_cost']-(float)$row['optimized_cost']);if($last!==null&&$groups[$action][$last]['end']->getTimestamp()===$localStart->getTimestamp()){$groups[$action][$last]['end']=$localEnd;$groups[$action][$last]['benefit']+=$benefit;$groups[$action][$last]['power_w']=max($groups[$action][$last]['power_w'],(int)$row['power_w']);$groups[$action][$last]['soc_after']=(float)$row['soc_after'];}else{$groups[$action][]=['start'=>$localStart,'end'=>$localEnd,'benefit'=>$benefit,'power_w'=>(int)$row['power_w'],'soc_after'=>(float)$row['soc_after']];}}
        }
        $charge=$this->bestThree($groups['charge_grid']);$discharge=$this->bestThree($groups['discharge']);
        $chargePower=$charge===[]?0:max(array_column($charge,'power_w'));$dischargePower=$discharge===[]?0:max(array_column($discharge,'power_w'));
        return ['plan_id'=>$planId,'valid_until'=>gmdate(DATE_ATOM,$until),'grid_periods'=>$this->periods($discharge),'battery_periods'=>$this->periods($charge),'discharge_power_pct'=>$this->powerPercent($dischargePower,(int)Env::get('BATTERY_MAX_DISCHARGE_W','2500')),'stop_soc_pct'=>(int)Env::get('BATTERY_RESERVE_PCT','20'),'charge_power_pct'=>$this->powerPercent($chargePower,(int)Env::get('BATTERY_MAX_CHARGE_W','2500')),'charge_stop_soc_pct'=>$charge===[]?(int)Env::get('BATTERY_MAX_SOC_PCT','95'):(int)min((float)Env::get('BATTERY_MAX_SOC_PCT','95'),max(array_column($charge,'soc_after'))),'ac_charge_enabled'=>$charge===[]?0:1,'source'=>'manually_approved_plan','automatic_replanning'=>false];
    }

    /** @return list<array{0:DateTimeImmutable,1:DateTimeImmutable}> */
    private function splitMidnight(DateTimeImmutable $start,DateTimeImmutable $end):array{$result=[];while($start<$end){$midnight=$start->modify('tomorrow')->setTime(0,0);$partEnd=$end<$midnight?$end:$midnight;$result[]=[$start,$partEnd];$start=$partEnd;}return$result;}
    private function bestThree(array $groups):array{usort($groups,static fn($a,$b)=>$b['benefit']<=>$a['benefit']);$groups=array_slice($groups,0,3);usort($groups,static fn($a,$b)=>$a['start']<=>$b['start']);return$groups;}
    private function periods(array $groups):array{return array_map(function($g):array{$end=$g['end'];$stop=$end->format('H:i')==='00:00'?(23<<8)|59:$this->packed($end);return['start'=>$this->packed($g['start']),'stop'=>$stop,'enabled'=>1,'label'=>$g['start']->format('d/m H:i').'–'.$g['end']->format('d/m H:i')];},$groups);}
    private function packed(DateTimeImmutable $time):int{return((int)$time->format('G')<<8)|(int)$time->format('i');}
    private function powerPercent(int $watts,int $maximum):int{return$watts<=0?0:max(10,min(100,(int)ceil($watts/max(1,$maximum)*100)));}
}
