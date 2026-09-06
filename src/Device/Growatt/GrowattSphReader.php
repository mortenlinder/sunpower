<?php
declare(strict_types=1);

namespace Solportalen\Device\Growatt;

use Solportalen\Device\Modbus\RtuCodec;
use Solportalen\Device\Serial\LinuxSerialTransport;

final class GrowattSphReader
{
    public function __construct(private readonly LinuxSerialTransport $transport, private readonly int $slaveId = 1)
    {
    }

    public function readState(): array
    {
        $base = $this->read(0, 50);
        $hybrid = $this->read(1000, 50);
        $priority = $this->read(118, 1)[0] ?? null;
        $chargeSettings = RtuCodec::decodeReadResponse($this->transport->exchange(RtuCodec::readRequest($this->slaveId,3,1090,3)),$this->slaveId,3);
        $now = gmdate(DATE_ATOM);
        $charge = $this->u32($hybrid, 11) * 0.1;
        $discharge = $this->u32($hybrid, 9) * 0.1;
        $toGrid = $this->u32($hybrid, 29) * 0.1;
        $toUser = $this->u32($hybrid, 21) * 0.1;
        $pv = $this->u32($base, 1) * 0.1;
        $reportedLocalLoad = $this->u32($hybrid, 37) * 0.1;
        // Protocol II's "local load" is zero on this installation while real
        // house consumption is present. Normally the documented balance is
        // sufficient. During AC battery charging this firmware omits the
        // charger demand from Pactouser; detect that impossible negative load
        // and calculate a coherent house/grid pair instead.
        $documentedLoad = $pv + $discharge + $toUser - $toGrid - $charge;
        if ($documentedLoad >= -50) {
            $load = max(0.0, $documentedLoad);
            $grid = $toUser - $toGrid;
            $calculationMode = 'growatt_energy_balance';
        } else {
            $load = max(0.0, $toUser - $toGrid);
            $grid = $load + $charge - $discharge - $pv;
            $calculationMode = 'ac_charge_balance_fallback';
        }
        return [
            'device_online' => true,
            'device_status_code' => $base[0] ?? null,
            'device_mode' => 'growatt_modbus_rtu',
            'priority_code' => $priority,
            'ac_charge_enabled' => $chargeSettings[2] === 1,
            'charge_power_pct' => $chargeSettings[0],
            'charge_stop_soc_pct' => $chargeSettings[1],
            'priority_mode' => match($priority){0=>'load_first',1=>'battery_first',2=>'grid_first',default=>'unknown'},
            'pv_power_w' => $pv,
            'pv1_voltage_v' => ($base[3] ?? 0) * 0.1,
            'pv1_current_a' => ($base[4] ?? 0) * 0.1,
            'pv1_power_w' => $this->u32($base, 5) * 0.1,
            'pv2_voltage_v' => ($base[7] ?? 0) * 0.1,
            'pv2_current_a' => ($base[8] ?? 0) * 0.1,
            'pv2_power_w' => $this->u32($base, 9) * 0.1,
            'inverter_power_w' => $this->u32($base, 35) * 0.1,
            'grid_frequency_hz' => ($base[37] ?? 0) * 0.01,
            'grid_voltage_v' => ($base[38] ?? 0) * 0.1,
            'battery_discharge_power_w' => $discharge,
            'battery_charge_power_w' => $charge,
            'battery_power_w' => $discharge - $charge,
            'battery_soc_pct' => (float) ($hybrid[14] ?? 0),
            'load_power_w' => round($load,1),
            'reported_local_load_w' => $reportedLocalLoad,
            'power_to_user_w' => $toUser,
            'power_to_grid_w' => $toGrid,
            // Portal convention: positive = import, negative = export.
            'grid_power_w' => round($grid,1),
            'calculation_mode' => $calculationMode,
            'energy_balance_error_w' => round($pv + $discharge + max(0,$grid) - $load - $charge - max(0,-$grid),1),
            'data_quality' => 'calculated_energy_balance',
            'source_timestamp' => $now,
            'received_timestamp' => $now,
        ];
    }

    /** @return array{input_0_99:list<int>,input_1000_1099:list<int>} */
    public function rawSnapshot(): array
    {
        return [
            'input_0_99' => array_merge($this->read(0,50),$this->read(50,50)),
            'input_1000_1099' => array_merge($this->read(1000,50),$this->read(1050,50)),
        ];
    }

    /** @return list<int> */
    private function read(int $start, int $count): array
    {
        $request = RtuCodec::readRequest($this->slaveId, 4, $start, $count);
        return RtuCodec::decodeReadResponse($this->transport->exchange($request), $this->slaveId, 4);
    }

    /** @param list<int> $registers */
    private function u32(array $registers, int $index): int
    {
        return (($registers[$index] ?? 0) << 16) | ($registers[$index + 1] ?? 0);
    }
}
