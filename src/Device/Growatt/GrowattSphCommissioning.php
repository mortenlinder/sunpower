<?php
declare(strict_types=1);

namespace Solportalen\Device\Growatt;

use Solportalen\Device\Modbus\RtuCodec;
use Solportalen\Device\Serial\LinuxSerialTransport;

final class GrowattSphCommissioning
{
    public function __construct(private readonly LinuxSerialTransport $transport, private readonly int $slaveId = 1)
    {
    }

    public function inspect(): array
    {
        $request = RtuCodec::readRequest($this->slaveId, 3, 1070, 39);
        $registers = RtuCodec::decodeReadResponse($this->transport->exchange($request), $this->slaveId, 3);
        return self::decode($registers);
    }

    /** @param list<int> $registers */
    public static function decode(array $registers): array
    {
        if (count($registers) !== 39) {
            throw new \RuntimeException('Commissioning-snapshot skal indeholde holding-register 1070-1108.');
        }
        $at = static fn (int $address): int => $registers[$address - 1070];
        $time = static function (int $value): array {
            $hour = ($value >> 8) & 0xff;
            $minute = $value & 0xff;
            return ['raw'=>$value,'formatted'=>sprintf('%02d:%02d',$hour,$minute),'valid'=>$hour <= 23 && $minute <= 59];
        };
        $periods = static function (int $start) use ($at, $time): array {
            $result=[];
            for ($slot=1; $slot<=3; $slot++, $start+=3) {
                $result[]=['slot'=>$slot,'start'=>$time($at($start)),'stop'=>$time($at($start+1)),'enabled'=>$at($start+2) === 1,'enable_raw'=>$at($start+2)];
            }
            return $result;
        };
        $validPercent = static fn (int $value): bool => $value >= 0 && $value <= 100;
        $gridPower=$at(1070);$gridSoc=$at(1071);$chargePower=$at(1090);$chargeSoc=$at(1091);$acCharge=$at(1092);
        $warnings=[];
        foreach (['1070 Grid First effekt'=>$gridPower,'1071 Grid First stop-SOC'=>$gridSoc,'1090 Battery First effekt'=>$chargePower,'1091 Battery First stop-SOC'=>$chargeSoc] as $label=>$value) {
            if (!$validPercent($value)) $warnings[]="$label ligger uden for 0-100: $value";
        }
        if (!in_array($acCharge,[0,1],true)) $warnings[]="1092 AC Charge enable er hverken 0 eller 1: $acCharge";
        return [
            'captured_at'=>gmdate(DATE_ATOM),'function_code'=>3,'start_address'=>1070,'register_count'=>39,
            'grid_first'=>['discharge_power_pct'=>$gridPower,'stop_soc_pct'=>$gridSoc,'periods'=>$periods(1080)],
            'battery_first'=>['charge_power_pct'=>$chargePower,'stop_soc_pct'=>$chargeSoc,'ac_charge_enabled'=>$acCharge === 1,'ac_charge_raw'=>$acCharge,'periods'=>$periods(1100)],
            'raw'=>array_combine(range(1070,1108),$registers),
            'warnings'=>$warnings,
            'ready_for_writes'=>false,
            'next_gate'=>'Sammenlign readback med ShinePhone og verificer model/firmware før kontrolleret write-test.'
        ];
    }
}
