<?php
declare(strict_types=1);

namespace Solportalen\Repository;

use PDO;

final class StateRepository
{
    private int $lastHistoryAt = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(array $state): void
    {
        $sourceAt = $this->sqlTime((string) $state['source_timestamp']);
        $receivedAt = $this->sqlTime((string) $state['received_timestamp']);
        $upsert = $this->pdo->prepare('INSERT INTO current_state (signal_name,value_json,source,quality,source_timestamp,received_timestamp) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),source=VALUES(source),quality=VALUES(quality),source_timestamp=VALUES(source_timestamp),received_timestamp=VALUES(received_timestamp)');
        $storeHistory = time() - $this->lastHistoryAt >= 30;
        $history = $storeHistory ? $this->pdo->prepare('INSERT INTO telemetry (signal_name,value_decimal,value_text,source,quality,source_timestamp,received_timestamp) VALUES (?,?,?,?,?,?,?)') : null;
        $this->pdo->beginTransaction();
        try {
            foreach ($state as $name => $value) {
                if (in_array($name, ['source_timestamp', 'received_timestamp', 'data_quality'], true)) {
                    continue;
                }
                $upsert->execute([$name, json_encode($value, JSON_THROW_ON_ERROR), 'growatt_rs485', $state['data_quality'], $sourceAt, $receivedAt]);
                if ($history !== null) {
                    $history->execute([$name, is_numeric($value) ? $value : null, is_scalar($value) ? (string) $value : json_encode($value), 'growatt_rs485', $state['data_quality'], $sourceAt, $receivedAt]);
                }
            }
            $this->heartbeat('device', 'ok', ['signals' => count($state)]);
            $this->pdo->commit();
            if ($storeHistory) {
                $this->lastHistoryAt = time();
            }
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function current(): array
    {
        $rows = $this->pdo->query('SELECT signal_name,value_json,quality,source_timestamp,received_timestamp FROM current_state')->fetchAll();
        $state = [];
        $latest = null;
        foreach ($rows as $row) {
            $state[$row['signal_name']] = json_decode($row['value_json'], true, 8, JSON_THROW_ON_ERROR);
            $latest = $latest === null || $row['received_timestamp'] > $latest ? $row['received_timestamp'] : $latest;
        }
        if ($latest !== null) {
            $state['source_timestamp'] = gmdate(DATE_ATOM, strtotime($latest . ' UTC'));
            $state['received_timestamp'] = $state['source_timestamp'];
            $state['data_quality'] = $rows[0]['quality'] ?? 'unknown';
        }
        return $state;
    }

    public function heartbeat(string $worker, string $status, array $details): void
    {
        $statement = $this->pdo->prepare('INSERT INTO worker_heartbeats(worker,heartbeat_at,status,details_json) VALUES (?,UTC_TIMESTAMP(6),?,?) ON DUPLICATE KEY UPDATE heartbeat_at=VALUES(heartbeat_at),status=VALUES(status),details_json=VALUES(details_json)');
        $statement->execute([$worker, $status, json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    private function sqlTime(string $atom): string
    {
        return gmdate('Y-m-d H:i:s.u', strtotime($atom));
    }
}
