<?php
declare(strict_types=1);

namespace Solportalen\Integration\Watts;

use RuntimeException;
use Solportalen\Config\Env;
use Solportalen\Database\Connection;
use Solportalen\Repository\StateRepository;

final class WattsMqttWorker
{
    public function run(): void
    {
        $password = (string) Env::get('WATTS_MQTT_PASSWORD', '');
        if ($password === '') throw new RuntimeException('WATTS_MQTT_PASSWORD mangler.');
        $command = [
            '/usr/bin/mosquitto_sub', '-h', Env::get('WATTS_MQTT_HOST', '127.0.0.1'),
            '-p', Env::get('WATTS_MQTT_PORT', '1883'), '-u', Env::get('WATTS_MQTT_USER', 'solportal'),
            '-P', $password, '-t', Env::get('WATTS_MQTT_TOPIC', 'watts/+/measurement'),
        ];
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('mosquitto_sub kunne ikke startes.');
        fclose($pipes[0]);
        $repository = new StateRepository(Connection::get());
        $decoder = new WattsPayloadDecoder();
        try {
            while (($line = fgets($pipes[1])) !== false) {
                $signals = $decoder->decode(trim($line));
                $repository->storeExternal($signals, 'watts_mqtt', 'measured', gmdate(DATE_ATOM));
            }
            $error = trim((string) stream_get_contents($pipes[2]));
            throw new RuntimeException('Watts MQTT-forbindelsen stoppede' . ($error !== '' ? ': ' . $error : '.'));
        } finally {
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
    }
}
