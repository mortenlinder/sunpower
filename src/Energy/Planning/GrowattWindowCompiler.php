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
    public function __construct(private readonly PDO $pdo) {}

    public function compile(int $planId): array
    {
        $query=$this->pdo->prepare("SELECT p.id,a.expires_at FROM plans p JOIN plan_approvals a ON a.plan_id=p.id WHERE p.id=? AND a.status='approved_shadow' AND a.expires_at>UTC_TIMESTAMP(6)");
        $query->execute([$planId]);$plan=$query->fetch();
        if(!$plan)throw new RuntimeException('Kun en aktuel, godkendt plan kan anvendes.');
        $rows=$this->pdo->prepare('SELECT starts_at,ends_at,action,power_w,soc_after,baseline_cost,optimized_cost FROM plan_intervals WHERE plan_id=? AND ends_at>UTC_TIMESTAMP(6) ORDER BY starts_at');
        $rows->execute([$planId]);
        return self::compileIntervals($planId,$rows->fetchAll(),strtotime($plan['expires_at'].' UTC'),time());
    }

    /** Clock slots share one AC-charge flag. Never enable AC for a solar-only slot. */
    public static function compileIntervals(int $planId,array $rows,int $expires,int $now): array
    {
        $tz=new DateTimeZone('Europe/Copenhagen');
        $local=(new DateTimeImmutable('@'.$now))->setTimezone($tz);
        $until=min($expires,$local->modify('tomorrow')->setTime(0,0)->getTimestamp());
        if($until<=$now)throw new RuntimeException('Planen er udløbet.');
        usort($rows,static fn($a,$b)=>strcmp($a['starts_at'],$b['starts_at']));
        $chargeAction=null;
        foreach($rows as$row){
            $start=strtotime($row['starts_at'].' UTC');$end=strtotime($row['ends_at'].' UTC');
            if($end<=$now||$start>=$until||!in_array($row['action'],['charge_solar','charge_grid'],true))continue;
            if($chargeAction===null)$chargeAction=$row['action'];
            elseif($row['action']!==$chargeAction){$until=min($until,$start);break;}
        }
        $groups=['charge_solar'=>[],'charge_grid'=>[],'discharge'=>[]];
        foreach($rows as$row){
            $action=$row['action'];if(!isset($groups[$action]))continue;
            $start=strtotime($row['starts_at'].' UTC');$end=min($until,strtotime($row['ends_at'].' UTC'));
            if($end<=$now||$start>=$until)continue;
            $start=max($local->setTime(0,0)->getTimestamp(),$start);
            $from=(new DateTimeImmutable('@'.$start))->setTimezone($tz);
            $to=(new DateTimeImmutable('@'.$end))->setTimezone($tz);
            $benefit=max(0,(float)$row['baseline_cost']-(float)$row['optimized_cost']);
            $last=array_key_last($groups[$action]);
            if($last!==null&&$groups[$action][$last]['end']->getTimestamp()===$start){
                $groups[$action][$last]['end']=$to;
                $groups[$action][$last]['benefit']+=$benefit;
                $groups[$action][$last]['power_w']=max($groups[$action][$last]['power_w'],(int)$row['power_w']);
                $groups[$action][$last]['soc_after']=(float)$row['soc_after'];
            }else $groups[$action][]=['start'=>$from,'end'=>$to,'benefit'=>$benefit,'power_w'=>(int)$row['power_w'],'soc_after'=>(float)$row['soc_after']];
        }
        $charge=self::selectWindows($groups[$chargeAction??'charge_solar'],$now);
        $discharge=self::selectWindows($groups['discharge'],$now);
        $chargePower=$charge===[]?0:max(array_column($charge,'power_w'));
        $dischargePower=$discharge===[]?0:max(array_column($discharge,'power_w'));
        return [
            'plan_id'=>$planId,'valid_until'=>gmdate(DATE_ATOM,$until),'plan_valid_until'=>gmdate(DATE_ATOM,$expires),
            'grid_periods'=>self::periods($discharge),'battery_periods'=>self::periods($charge),
            'battery_action'=>$chargeAction,'discharge_power_pct'=>self::powerPercent($dischargePower,(int)Env::get('BATTERY_MAX_DISCHARGE_W','2500')),
            'stop_soc_pct'=>(int)Env::get('BATTERY_RESERVE_PCT','20'),
            // No Battery First window must not leave solar charging disabled at 0%.
            'charge_power_pct'=>$charge===[]?100:self::powerPercent($chargePower,(int)Env::get('BATTERY_MAX_CHARGE_W','2500')),
            'charge_stop_soc_pct'=>$charge===[]?(int)Env::get('BATTERY_MAX_SOC_PCT','95'):(int)min((float)Env::get('BATTERY_MAX_SOC_PCT','95'),ceil(max(array_column($charge,'soc_after')))),
            'ac_charge_enabled'=>$chargeAction==='charge_grid'&&$charge!==[]?1:0,
            'source'=>'manually_approved_plan','automatic_replanning'=>false,
        ];
    }

    public static function selectWindows(array $groups,int $now):array
    {
        usort($groups,static function($a,$b)use($now):int{
            $activeA=$a['start']->getTimestamp()<=$now&&$a['end']->getTimestamp()>$now;
            $activeB=$b['start']->getTimestamp()<=$now&&$b['end']->getTimestamp()>$now;
            return ($activeB<=>$activeA)?:($b['benefit']<=>$a['benefit'])?:($a['start']<=>$b['start']);
        });
        $groups=array_slice($groups,0,3);usort($groups,static fn($a,$b)=>$a['start']<=>$b['start']);return$groups;
    }

    private static function periods(array $groups):array
    {
        return array_map(static function($group):array{
            $end=$group['end'];$stop=$end->format('H:i')==='00:00'?(23<<8)|59:self::packed($end);
            return ['start'=>self::packed($group['start']),'stop'=>$stop,'enabled'=>1,'label'=>$group['start']->format('d/m H:i').'–'.$end->format('d/m H:i')];
        },$groups);
    }
    private static function packed(DateTimeImmutable $time):int{return((int)$time->format('G')<<8)|(int)$time->format('i');}
    private static function powerPercent(int $watts,int $maximum):int{return$watts<=0?0:max(10,min(100,(int)ceil($watts/max(1,$maximum)*100)));}
}
