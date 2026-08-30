<?php
declare(strict_types=1);

namespace Solportalen\Device\Serial;

use RuntimeException;

final class LinuxSerialTransport
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(private readonly string $device, private readonly int $baudrate = 9600)
    {
        if (preg_match('#^/dev/(ttyUSB[0-9]+|ttyACM[0-9]+|serial/by-id/[A-Za-z0-9._:+-]+)$#', $device) !== 1) {
            throw new RuntimeException('Serieporten er ikke en tilladt Linux-enhed.');
        }
        if (!in_array($baudrate, [9600, 19200, 38400, 57600, 115200], true)) {
            throw new RuntimeException('Baudraten er ikke tilladt.');
        }
    }

    public function open(): void
    {
        if (!is_readable($this->device) || !is_writable($this->device)) {
            throw new RuntimeException('Ingen læse/skriveadgang til ' . $this->device);
        }
        $command = ['stty', '-F', $this->device, (string) $this->baudrate, 'cs8', '-cstopb', '-parenb', 'raw', '-echo', 'min', '0', 'time', '10'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Kunne ikke starte stty.');
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('stty fejlede: ' . trim((string) $stderr));
        }
        $stream = fopen($this->device, 'r+b');
        if ($stream === false || !flock($stream, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Serieporten kan ikke åbnes eksklusivt.');
        }
        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    public function exchange(string $request, float $timeoutSeconds = 1.5): string
    {
        if (!is_resource($this->stream)) {
            $this->open();
        }
        while (is_resource($this->stream) && fread($this->stream, 256) !== '') {
        }
        $written = fwrite($this->stream, $request);
        fflush($this->stream);
        if ($written !== strlen($request)) {
            throw new RuntimeException('Ufuldstændig skrivning til serieporten.');
        }
        $response = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $lastByteAt = null;
        while (microtime(true) < $deadline) {
            $read = [$this->stream]; $write = []; $except = [];
            $selected = stream_select($read, $write, $except, 0, 100000);
            if ($selected === false) {
                throw new RuntimeException('Fejl under venten på Modbus-svar.');
            }
            if ($selected > 0) {
                $chunk = fread($this->stream, 256);
                if ($chunk !== false && $chunk !== '') {
                    $response .= $chunk;
                    $lastByteAt = microtime(true);
                }
            }
            if ($lastByteAt !== null && microtime(true) - $lastByteAt > 0.08) {
                break;
            }
        }
        if ($response === '') {
            throw new RuntimeException('Timeout: intet Modbus-svar.');
        }
        return $response;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            flock($this->stream, LOCK_UN);
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
