<?php
declare(strict_types=1);

namespace Solportalen\Integration\Forecast;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Solportalen\Config\Env;

final class ExternalDataService
{
    public function __construct(private readonly PDO $pdo) {}

    public function refresh(): array
    {
        $result = ['weather' => 'not-run', 'prices' => 'not-run'];
        foreach (['weather' => fn () => $this->weather(), 'prices' => fn () => $this->prices()] as $name => $job) {
            try { $result[$name] = $job(); } catch (\Throwable $error) { $result[$name] = 'error: ' . $error->getMessage(); }
        }
        $statement = $this->pdo->prepare('INSERT INTO worker_heartbeats(worker,heartbeat_at,status,details_json) VALUES ("forecast",UTC_TIMESTAMP(6),?,?) ON DUPLICATE KEY UPDATE heartbeat_at=VALUES(heartbeat_at),status=VALUES(status),details_json=VALUES(details_json)');
        $ok = !str_starts_with($result['weather'], 'error') || !str_starts_with($result['prices'], 'error');
        $statement->execute([$ok ? 'ok' : 'error', json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    }

    private function weather(): string
    {
        $lastFetch = $this->pdo->query('SELECT MAX(fetched_at) FROM weather_forecasts')->fetchColumn();
        if (is_string($lastFetch) && time() - strtotime($lastFetch . ' UTC') < 3600) return 'cache er stadig frisk';
        $lat = Env::get('LOCATION_LAT', '55.7833');
        $lon = Env::get('LOCATION_LON', '12.3833');
        $url = 'https://api.met.no/weatherapi/locationforecast/2.0/compact?' . http_build_query(['lat' => $lat, 'lon' => $lon]);
        $payload = $this->getJson($url, 'Solportalen/0.2 github.com/mortenlinder/sunpower');
        $rows = $payload['properties']['timeseries'] ?? [];
        $statement = $this->pdo->prepare('INSERT INTO weather_forecasts(forecast_at,location,temperature_c,cloud_pct,precipitation_mm,symbol_code,solar_score,expected_pv_w,source,fetched_at) VALUES (?,?,?,?,?,?,?,?,"yr_locationforecast",UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE temperature_c=VALUES(temperature_c),cloud_pct=VALUES(cloud_pct),precipitation_mm=VALUES(precipitation_mm),symbol_code=VALUES(symbol_code),solar_score=VALUES(solar_score),expected_pv_w=VALUES(expected_pv_w),fetched_at=VALUES(fetched_at)');
        $count = 0;
        foreach ($rows as $row) {
            $at = new DateTimeImmutable((string) ($row['time'] ?? 'now'));
            $details = $row['data']['instant']['details'] ?? [];
            $cloud = max(0.0, min(100.0, (float) ($details['cloud_area_fraction'] ?? 100)));
            $hour = (int) $at->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('G');
            $daylight = max(0.0, sin(M_PI * ($hour - 5.0) / 16.0));
            $score = round(100 * $daylight * (1 - .82 * $cloud / 100), 1);
            $peak = (int) Env::get('PV_PEAK_W', '6000');
            $expected = (int) round($peak * $score / 100);
            $next = $row['data']['next_1_hours'] ?? $row['data']['next_6_hours'] ?? [];
            $statement->execute([$at->format('Y-m-d H:i:s'), Env::get('LOCATION_NAME', 'Værløse'), (float) ($details['air_temperature'] ?? 0), $cloud, (float) ($next['details']['precipitation_amount'] ?? 0), (string) ($next['summary']['symbol_code'] ?? 'unknown'), $score, $expected]);
            $count++;
        }
        return $count . ' prognosepunkter';
    }

    private function prices(): string
    {
        $start = gmdate('Y-m-d', time() - 86400);
        $end = gmdate('Y-m-d', time() + 3 * 86400);
        $query = http_build_query(['start' => $start, 'end' => $end, 'filter' => json_encode(['PriceArea' => Env::get('PRICE_AREA', 'DK2')]), 'sort' => 'TimeUTC ASC', 'columns' => 'TimeUTC,PriceArea,DayAheadPriceDKK', 'limit' => 1000]);
        $payload = $this->getJson('https://api.energidataservice.dk/dataset/DayAheadPrices?' . $query, 'Solportalen/0.3');
        $records = $payload['records'] ?? [];
        $statement = $this->pdo->prepare('INSERT INTO prices(interval_start,interval_end,area,spot_dkk_kwh,source,fetched_at) VALUES (?,?,?,?,"energidataservice",UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE interval_end=VALUES(interval_end),spot_dkk_kwh=VALUES(spot_dkk_kwh),fetched_at=VALUES(fetched_at)');
        $count = 0;
        foreach ($records as $record) {
            $raw = $record['TimeUTC'] ?? null;
            if ($raw === null || !isset($record['DayAheadPriceDKK'])) continue;
            $at = new DateTimeImmutable((string) $raw, new DateTimeZone('UTC'));
            $statement->execute([$at->format('Y-m-d H:i:s'), $at->modify('+15 minutes')->format('Y-m-d H:i:s'), (string) ($record['PriceArea'] ?? 'DK2'), (float) $record['DayAheadPriceDKK'] / 1000]);
            $count++;
        }
        if ($count === 0) throw new RuntimeException('Prisfeed indeholdt ingen anvendelige records.');
        return $count . ' prisintervaller';
    }

    private function getJson(string $url, string $userAgent): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => $userAgent, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
        if (!is_string($body) || $status < 200 || $status >= 300) throw new RuntimeException('HTTP ' . $status . ($error !== '' ? ': ' . $error : ''));
        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }
}
