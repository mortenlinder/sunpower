<?php
declare(strict_types=1);
namespace Solportalen\Energy\Planning;

use DateTimeImmutable;
use DateTimeZone;

final class ExecutionStatus
{
    public static function describe(array $schedule,array $state,int $now):array
    {
        if(empty($schedule['valid_until'])||strtotime($schedule['valid_until'])<=$now)return ['status'=>'no_active_plan','message'=>'Ingen aktiv inverterplan'];
        $local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Copenhagen'));
        $clock=((int)$local->format('G')<<8)|(int)$local->format('i');
        $action='hold';
        foreach(['battery_periods'=>'charge_grid','grid_periods'=>'discharge']as$key=>$candidate){
            foreach($schedule[$key]??[]as$period)if(!empty($period['enabled'])&&$clock>=$period['start']&&$clock<$period['stop'])$action=$candidate;
        }
        $result=['plan_id'=>$schedule['plan_id'],'action'=>$action,'valid_until'=>$schedule['valid_until'],'status'=>'scheduled','message'=>'Plan aktiv · intet lade-/afladevindue nu'];
        $age=$now-strtotime($state['received_timestamp']??'1970-01-01 UTC');
        if($age>30)return array_replace($result,['status'=>'stale','message'=>'Aktuel invertertilstand kan ikke bekræftes · data er gamle']);
        if($action==='charge_grid'){
            $target=(float)($state['charge_stop_soc_pct']??$schedule['charge_stop_soc_pct']);
            $result['target_soc_pct']=$target;
            if(($state['battery_soc_pct']??0)>=$target){$result['status']='target_reached';$result['message']='Lademålet er nået · '.$target.' %';}
            elseif(($state['priority_mode']??'')==='battery_first'&&!empty($state['ac_charge_enabled'])&&($state['battery_charge_power_w']??0)>50){$result['status']='charging';$result['message']='Opladning bekræftet · netopladning aktiveret';}
            else{$result['status']='mismatch';$result['message']='Planen ønsker netopladning · opladning er ikke bekræftet';}
        }elseif($action==='discharge'){
            $result['status']=($state['battery_discharge_power_w']??0)>50?'discharging':'waiting';
            $result['message']=$result['status']==='discharging'?'Afladning bekræftet':'Afladevindue aktivt · batteriet aflader ikke nu';
        }
        return $result;
    }
}
