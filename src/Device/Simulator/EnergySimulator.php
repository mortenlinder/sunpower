<?php
declare(strict_types=1);

namespace Solportalen\Device\Simulator;

use DateTimeImmutable;
use DateTimeZone;

final class EnergySimulator
{
    public function state(?DateTimeImmutable $now = null, float $soc = 64.0): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen'));
        $hour = ((int) $now->format('G')) + ((int) $now->format('i')) / 60;
        $sun = max(0.0, sin((($hour - 6.0) / 14.0) * M_PI));
        $pv = round(5200 * $sun * (0.82 + 0.12 * sin($hour * 2.7)));
        $load = round(430 + 180 * sin($hour * 1.4) ** 2 + (($hour >= 17 && $hour <= 21) ? 1150 : 0));
        $battery = $pv > $load + 400 && $soc < 95 ? -min(2400, $pv - $load) : (($pv < $load && $soc > 20) ? min(1900, $load - $pv) : 0);
        $grid = $load - $pv - $battery;
        return [
            'device_online' => true, 'device_status' => 'normal', 'device_mode' => 'simulator',
            'pv_power_w' => $pv, 'pv1_power_w' => round($pv * 0.53), 'pv2_power_w' => round($pv * 0.47),
            'load_power_w' => $load, 'battery_power_w' => $battery, 'battery_soc_pct' => $soc,
            'grid_power_w' => $grid, 'grid_frequency_hz' => 50.01, 'grid_voltage_v' => 231.4,
            'battery_temperature_c' => 24.8, 'inverter_temperature_c' => 37.2,
            'fault_code' => 0, 'warning_code' => 0, 'data_quality' => 'simulated',
            'source_timestamp' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'received_timestamp' => gmdate(DATE_ATOM),
        ];
    }
}
