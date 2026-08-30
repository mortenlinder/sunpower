<?php
declare(strict_types=1);

namespace Solportalen\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Solportalen\Config\Env;

final class InsightRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function dashboard(): array
    {
        $weather = $this->pdo->query('SELECT forecast_at,temperature_c,cloud_pct,precipitation_mm,symbol_code,solar_score,expected_pv_w FROM weather_forecasts WHERE forecast_at>=UTC_TIMESTAMP() ORDER BY forecast_at LIMIT 48')->fetchAll();
        $prices = $this->pdo->query('SELECT interval_start,interval_end,spot_dkk_kwh FROM prices WHERE interval_end>=UTC_TIMESTAMP() ORDER BY interval_start LIMIT 192')->fetchAll();
        $tz = new DateTimeZone('Europe/Copenhagen');
        foreach ($prices as &$price) {
            $local = (new DateTimeImmutable($price['interval_start'], new DateTimeZone('UTC')))->setTimezone($tz);
            $hour = (int) $local->format('G');
            $tariff = $hour < 6 ? (float) Env::get('GRID_TARIFF_LOW_DKK', '.1327') : ($hour >= 17 && $hour < 21 ? (float) Env::get('GRID_TARIFF_PEAK_DKK', '.5176') : (float) Env::get('GRID_TARIFF_HIGH_DKK', '.1991'));
            $price['tariff_dkk_kwh'] = $tariff;
            $price['total_dkk_kwh'] = round(((float) $price['spot_dkk_kwh'] + $tariff + (float) Env::get('ENERGY_TAX_DKK', '.009') + (float) Env::get('SUPPLIER_MARKUP_DKK', '0')) * 1.25, 4);
        }
        unset($price);
        $sorted = $prices; usort($sorted, fn ($a, $b) => $a['total_dkk_kwh'] <=> $b['total_dkk_kwh']);
        $cheap = array_slice($sorted, 0, min(12, count($sorted)));
        $solar = array_column($weather, 'solar_score');
        $score = $solar === [] ? null : round(array_sum($solar) / count($solar));
        $events = $this->pdo->query('SELECT event_type,started_at,ended_at,detected_load_w,energy_kwh,confidence FROM consumption_events ORDER BY started_at DESC LIMIT 5')->fetchAll();
        return ['location' => Env::get('LOCATION_NAME', 'Værløse'), 'weather' => $weather, 'prices' => $prices, 'solar_barometer' => $score, 'consumption_events' => $events, 'plan' => ['mode' => 'shadow', 'action' => $cheap === [] ? 'Afventer priser' : 'Billigste ladevinduer er fundet', 'cheap_intervals' => $cheap, 'writes_enabled' => false], 'generated_at' => gmdate(DATE_ATOM)];
    }
}
