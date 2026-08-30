<?php
declare(strict_types=1);

namespace Solportalen\Integration\Watts;

use RuntimeException;

final class WattsPayloadDecoder
{
    public function decode(string $json): array
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('MQTT payload er ikke et objekt.');
        }
        $import = (float) ($data['positive_active_power'] ?? 0);
        $export = (float) ($data['negative_active_power'] ?? 0);
        return ['grid_power_w' => $import - $export, 'grid_l1_power_w' => (float) ($data['positive_active_power_l1'] ?? 0) - (float) ($data['negative_active_power_l1'] ?? 0), 'grid_l2_power_w' => (float) ($data['positive_active_power_l2'] ?? 0) - (float) ($data['negative_active_power_l2'] ?? 0), 'grid_l3_power_w' => (float) ($data['positive_active_power_l3'] ?? 0) - (float) ($data['negative_active_power_l3'] ?? 0), 'grid_frequency_hz' => (float) ($data['frequency'] ?? 0), 'source' => 'watts_mqtt', 'quality' => 'measured'];
    }
}
