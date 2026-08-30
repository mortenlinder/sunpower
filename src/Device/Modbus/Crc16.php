<?php
declare(strict_types=1);

namespace Solportalen\Device\Modbus;

final class Crc16
{
    public static function calculate(string $bytes): int
    {
        $crc = 0xFFFF;
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $crc ^= ord($bytes[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) !== 0 ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }
        return $crc & 0xFFFF;
    }

    public static function append(string $frame): string
    {
        return $frame . pack('v', self::calculate($frame));
    }

    public static function valid(string $frame): bool
    {
        if (strlen($frame) < 4) {
            return false;
        }
        $payload = substr($frame, 0, -2);
        return hash_equals(pack('v', self::calculate($payload)), substr($frame, -2));
    }
}
