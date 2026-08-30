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
        $now = gmdate(DATE_ATOM);
        $charge = $this->u32($hybrid, 11) * 0.1;
        $discharge = $this->u32($hybrid, 9) * 0.1;
        $toGrid = $this->u32($hybrid, 29) * 0.1;
        $toUser = $this->u32($hybrid, 21) * 0.1;
        return [
            'device_online' => true,
            'device_status_code' => $base[0] ?? null,
            'device_mode' => 'growatt_modbus_rtu',
            'priority_code' => $priority,
            'priority_mode' => match($priority){0=>'load_first',1=>'battery_first',2=>'grid_first',default=>'unknown'},
            'pv_power_w' => $this->u32($base, 1) * 0.1,
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
            'load_power_w' => $this->u32($hybrid, 37) * 0.1,
            'power_to_user_w' => $toUser,
            'power_to_grid_w' => $toGrid,
            // Growatt exposes import and export as separate positive counters.
            // Portal convention: positive = import, negative = export.
            'grid_power_w' => $toUser - $toGrid,
            'data_quality' => 'measured_unverified_mapping',
            'source_timestamp' => $now,
            'received_timestamp' => $now,
        ];
    }

    /** @return array{input_0_99:list<int>,input_1000_1099:list<int>} */
    public function rawSnapshot(): array
    {
        return ['input_0_99' => $this->read(0, 100), 'input_1000_1099' => $this->read(1000, 100)];
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
