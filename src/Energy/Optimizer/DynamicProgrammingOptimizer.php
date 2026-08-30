<?php
declare(strict_types=1);

namespace Solportalen\Energy\Optimizer;

final class DynamicProgrammingOptimizer implements EnergyOptimizerInterface
{
    public function optimize(array $intervals, array $battery): array
    {
        if ($intervals === []) return [];
        $capacity = max(.5, (float) ($battery['capacity_kwh'] ?? 6.5));
        $minKwh = $capacity * max((float) ($battery['min_soc_pct'] ?? 20), (float) ($battery['reserve_pct'] ?? 20)) / 100;
        $maxKwh = $capacity * (float) ($battery['max_soc_pct'] ?? 95) / 100;
        $startKwh = min($maxKwh, max($minKwh, $capacity * (float) ($battery['soc_pct'] ?? 50) / 100));
        $step = max(.1, (float) ($battery['step_kwh'] ?? .25));
        $eta = sqrt(max(.5, min(1.0, (float) ($battery['round_trip_efficiency'] ?? .88))));
        $wear = max(0, (float) ($battery['wear_dkk_kwh'] ?? .12));
        $maxChargeKw = max(.1, (float) ($battery['max_charge_w'] ?? 2500) / 1000);
        $maxDischargeKw = max(.1, (float) ($battery['max_discharge_w'] ?? 2500) / 1000);
        $allowGridCharge = (bool) ($battery['allow_grid_charge'] ?? true);
        $levels = [];
        for ($energy = $minKwh; $energy <= $maxKwh + .0001; $energy += $step) $levels[] = round($energy, 4);
        $nearest = fn (float $value): float => $levels[array_reduce(array_keys($levels), fn ($best, $i) => abs($levels[$i]-$value)<abs($levels[$best]-$value)?$i:$best, 0)];
        $start = $nearest($startKwh);
        $states = [(string) $start => ['cost' => 0.0, 'path' => []]];
        foreach ($intervals as $interval) {
            $hours = max(.01, (strtotime((string) $interval['ends_at']) - strtotime((string) $interval['starts_at'])) / 3600);
            $price = (float) $interval['buy_price']; $load = max(0, (float) ($interval['load_kwh'] ?? 0)); $solar = max(0, (float) ($interval['solar_kwh'] ?? 0));
            $netLoad = $load - $solar; $solarExcess = max(0, -$netLoad); $houseNeed = max(0, $netLoad);
            $nextStates = [];
            foreach ($states as $storedKey => $state) {
                $stored = (float) $storedKey;
                foreach ($levels as $target) {
                    $delta = $target - $stored;
                    if ($delta > $maxChargeKw * $hours * $eta + .001 || -$delta > $maxDischargeKw * $hours / $eta + .001) continue;
                    $gridKwh = $houseNeed; $cycled = abs($delta); $action = 'hold'; $powerW = 0;
                    if ($delta > .01) {
                        $neededAtAc = $delta / $eta; $fromSolar = min($solarExcess, $neededAtAc); $fromGrid = max(0, $neededAtAc - $fromSolar);
                        if (!$allowGridCharge && $fromGrid > .001) continue;
                        $gridKwh += $fromGrid; $action = $fromGrid > .01 ? 'charge_grid' : 'charge_solar'; $powerW = (int) round($neededAtAc / $hours * 1000);
                    } elseif ($delta < -.01) {
                        $delivered = min($houseNeed, -$delta * $eta); $gridKwh = max(0, $houseNeed - $delivered); $action = 'discharge'; $powerW = (int) round($delivered / $hours * 1000);
                    }
                    $cost = (float) $state['cost'] + $gridKwh * $price + $cycled * $wear;
                    $key = (string) $target;
                    if (!isset($nextStates[$key]) || $cost < $nextStates[$key]['cost']) {
                        $baseline = $houseNeed * $price; $optimized = $gridKwh * $price + $cycled * $wear;
                        $row = $interval + ['action'=>$action,'power_w'=>$powerW,'soc_before'=>round($stored/$capacity*100,2),'soc_after'=>round($target/$capacity*100,2),'baseline_cost'=>round($baseline,5),'optimized_cost'=>round($optimized,5),'explanation'=>$this->explain($action,$price,$solarExcess),'confidence'=>round(min(.95,max(.45,(float)($interval['confidence']??.7))),3)];
                        $nextStates[$key] = ['cost'=>$cost,'path'=>[...$state['path'],$row]];
                    }
                }
            }
            $states = $nextStates;
        }
        if ($states === []) return [];
        usort($states, fn ($a,$b) => $a['cost'] <=> $b['cost']);
        return $states[0]['path'];
    }

    private function explain(string $action, float $price, float $solarExcess): string
    {
        return match ($action) {
            'charge_grid' => sprintf('Oplad fra nettet i et billigt interval (%.2f kr./kWh).', $price),
            'charge_solar' => sprintf('Gem %.2f kWh forventet soloverskud.', $solarExcess),
            'discharge' => sprintf('Dæk husets forbrug fra batteriet ved %.2f kr./kWh.', $price),
            default => 'Hold batteriet; en cyklus er ikke økonomisk fordelagtig her.',
        };
    }
}
