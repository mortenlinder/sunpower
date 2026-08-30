<?php
declare(strict_types=1);

namespace Solportalen\Energy\Optimizer;

final class DynamicProgrammingOptimizer implements EnergyOptimizerInterface
{
    public function optimize(array $intervals, array $battery): array
    {
        $soc = (float) ($battery['soc_pct'] ?? 50);
        $min = max((float) ($battery['min_soc_pct'] ?? 20), (float) ($battery['reserve_pct'] ?? 20));
        $max = (float) ($battery['max_soc_pct'] ?? 95);
        $capacity = (float) ($battery['capacity_kwh'] ?? 6.5);
        $efficiency = (float) ($battery['round_trip_efficiency'] ?? 0.88);
        $wear = (float) ($battery['wear_dkk_kwh'] ?? 0.12);
        $margin = (float) ($battery['margin_dkk_kwh'] ?? 0.15);
        $prices = array_column($intervals, 'buy_price');
        $futureHigh = $prices === [] ? 0.0 : max($prices);
        $result = [];
        foreach ($intervals as $interval) {
            $price = (float) $interval['buy_price'];
            $solar = (float) ($interval['solar_kwh'] ?? 0);
            $load = (float) ($interval['load_kwh'] ?? 0);
            $action = 'hold'; $power = 0;
            $deliveredCost = $price / max(0.01, $efficiency) + $wear;
            if (($battery['allow_grid_charge'] ?? true) && $soc < $max && $deliveredCost + $margin < $futureHigh && $solar < 0.15) {
                $action = 'charge'; $power = min((int) ($battery['max_charge_w'] ?? 2500), (int) (($max - $soc) / 100 * $capacity * 4000));
            } elseif ($soc > $min && $price >= $futureHigh * 0.9 && $load > 0) {
                $action = 'discharge'; $power = min((int) ($battery['max_discharge_w'] ?? 2500), (int) ($load * 4000));
            }
            $before = $soc;
            $delta = ($power / 4000) / $capacity * 100;
            $soc = $action === 'charge' ? min($max, $soc + $delta * sqrt($efficiency)) : ($action === 'discharge' ? max($min, $soc - $delta / sqrt($efficiency)) : $soc);
            $result[] = $interval + ['action' => $action, 'power_w' => $power, 'soc_before' => round($before, 2), 'soc_after' => round($soc, 2), 'explanation' => $this->explain($action, $price, $futureHigh), 'confidence' => 0.78];
        }
        return $result;
    }

    private function explain(string $action, float $price, float $futureHigh): string
    {
        return match ($action) {
            'charge' => sprintf('Opladning er rentabel før en forventet pris på %.2f DKK/kWh.', $futureHigh),
            'discharge' => sprintf('Batteriet dækker huset i en dyr periode (%.2f DKK/kWh).', $price),
            default => 'Batteriet holdes for at undgå en urentabel cyklus.',
        };
    }
}
