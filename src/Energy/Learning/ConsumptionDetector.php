<?php
declare(strict_types=1);

namespace Solportalen\Energy\Learning;

use PDO;
use Solportalen\Config\Env;

final class ConsumptionDetector
{
    private ?int $candidateAt = null;
    private ?int $activeAt = null;
    private int $lastAt = 0;
    private float $energyWh = 0;
    private float $baseline;

    public function __construct(private readonly PDO $pdo)
    {
        $value = $pdo->query("SELECT AVG(value_decimal) FROM telemetry WHERE signal_name='load_power_w' AND value_decimal<4000 AND source_timestamp>UTC_TIMESTAMP()-INTERVAL 14 DAY")->fetchColumn();
        $this->baseline = $value === false || $value === null ? 800.0 : max(200.0, (float) $value);
    }

    public function observe(float $loadW): void
    {
        $now = time(); $delta = $loadW - $this->baseline; $threshold = (float) Env::get('EV_DETECT_W', '4500');
        if ($this->activeAt !== null) {
            $seconds = $this->lastAt > 0 ? min(30, $now - $this->lastAt) : 5;
            $this->energyWh += max(0, $delta) * $seconds / 3600;
            if ($delta < $threshold * .55) $this->finish($now, $delta);
        } elseif ($delta >= $threshold) {
            $this->candidateAt ??= $now;
            if ($now - $this->candidateAt >= 30) { $this->activeAt = $this->candidateAt; $this->energyWh = 0; }
        } else {
            $this->candidateAt = null;
            $this->baseline = .995 * $this->baseline + .005 * $loadW;
        }
        $this->lastAt = $now;
    }

    private function finish(int $now, float $delta): void
    {
        $duration = $now - (int) $this->activeAt;
        if ($duration >= 300) {
            $load = (int) round($this->energyWh * 3600 / max(1, $duration));
            $confidence = min(.98, .55 + min(.3, $duration / 14400) + min(.13, $load / 100000));
            $statement = $this->pdo->prepare('INSERT INTO consumption_events(event_type,started_at,ended_at,baseline_w,detected_load_w,energy_kwh,confidence,details_json) VALUES ("probable_ev_charge",FROM_UNIXTIME(?),FROM_UNIXTIME(?),?,?,?,?,?)');
            $statement->execute([$this->activeAt, $now, round($this->baseline), $load, round($this->energyWh / 1000, 3), $confidence, json_encode(['method'=>'sustained_load_step','threshold_w'=>(int) Env::get('EV_DETECT_W','4500')], JSON_THROW_ON_ERROR)]);
        }
        $this->activeAt = null; $this->candidateAt = null; $this->energyWh = 0;
    }
}
