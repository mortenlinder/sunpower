<?php
declare(strict_types=1);
namespace Solportalen\Integration\Cloud;
use RuntimeException;
use Solportalen\Config\Env;
final class RemoteMode
{
    public static function validate(array $c,int $now): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/',$c['id']??'') || !in_array($c['mode']??'',['load_first','battery_first','charge_grid','grid_first'],true)
            || !in_array((int)($c['duration_minutes']??0),[15,30,60,120],true)
            || (int)($c['expires_at']??0)<=$now || (int)$c['expires_at']>$now+150) throw new RuntimeException('Ugyldig eller udløbet fjernkommando.');
    }
    public static function schedule(array $c,int $now): array
    {
        self::validate($c,$now);
        $tz=new \DateTimeZone('Europe/Copenhagen'); $local=(new \DateTimeImmutable('@'.$now))->setTimezone($tz);
        // Never program a recurring whole-day remote command. Stop no later than midnight.
        $until=min($now+(int)$c['duration_minutes']*60,$local->modify('tomorrow')->setTime(0,0)->getTimestamp());
        if ($until-$now<60) throw new RuntimeException('For tæt på midnat. Prøv igen efter datoskiftet.');
        $end=(new \DateTimeImmutable('@'.$until))->setTimezone($tz);
        $period=['start'=>((int)$local->format('H')<<8)|(int)$local->format('i'),'stop'=>$end->format('H:i')==='00:00'?(23<<8)|59:((int)$end->format('H')<<8)|(int)$end->format('i'),'enabled'=>1];
        $reserve=max((int)Env::get('BATTERY_MIN_SOC_PCT','20'),(int)Env::get('BATTERY_RESERVE_PCT','20'));
        $max=(int)Env::get('BATTERY_MAX_SOC_PCT','95');
        if ($reserve<5 || $max>100 || $max<=$reserve) throw new RuntimeException('Lokale batterigrænser er ugyldige.');
        // Reuse the locally validated plan compiler's power conversion and configured limits.
        $action=match($c['mode']){'battery_first'=>'charge_solar','charge_grid'=>'charge_grid','grid_first'=>'discharge',default=>'hold'};
        $power=(int)Env::get($action==='discharge'?'BATTERY_MAX_DISCHARGE_W':'BATTERY_MAX_CHARGE_W','2500');
        $schedule=\Solportalen\Energy\Planning\GrowattWindowCompiler::compileIntervals(0,[['starts_at'=>gmdate('Y-m-d H:i:s',$now),'ends_at'=>gmdate('Y-m-d H:i:s',$until),'action'=>$action,'power_w'=>$power,'soc_after'=>$max,'baseline_cost'=>0,'optimized_cost'=>0]],$until,$now);
        $schedule['stop_soc_pct']=$reserve; $schedule['until']=$until; $schedule['mode']=$c['mode'];
        return $schedule;
    }
}
