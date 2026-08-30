<?php
declare(strict_types=1);

namespace Solportalen\Device\Modbus;

final class RtuCodec
{
    public static function readRequest(int $slave, int $function, int $start, int $count): string
    {
        self::validateSlave($slave);
        if (!in_array($function, [3, 4], true) || $start < 0 || $start > 65535 || $count < 1 || $count > 60) {
            throw new ModbusException('Ugyldig eller for stor Modbus read-request.');
        }
        return Crc16::append(pack('CCnn', $slave, $function, $start, $count));
    }

    public static function writeSingleRequest(int $slave, int $address, int $value, bool $writesEnabled): string
    {
        if (!$writesEnabled) {
            throw new ModbusException('Modbus writes er globalt deaktiveret.');
        }
        self::validateSlave($slave);
        if ($address < 0 || $address > 65535 || $value < 0 || $value > 65535) {
            throw new ModbusException('Ugyldig write-request.');
        }
        return Crc16::append(pack('CCnn', $slave, 6, $address, $value));
    }

    /** @return list<int> */
    public static function decodeReadResponse(string $frame, int $slave, int $function): array
    {
        if (!Crc16::valid($frame) || strlen($frame) < 5 || ord($frame[0]) !== $slave) {
            throw new ModbusException('Ugyldigt slave-ID eller CRC.');
        }
        $actual = ord($frame[1]);
        if (($actual & 0x80) !== 0) {
            throw new ModbusException('Modbus exception code ' . ord($frame[2]));
        }
        if ($actual !== $function) {
            throw new ModbusException('Uventet funktionskode.');
        }
        $byteCount = ord($frame[2]);
        if (($byteCount % 2) !== 0 || strlen($frame) !== $byteCount + 5) {
            throw new ModbusException('Ugyldig responslængde.');
        }
        return array_values(unpack('n*', substr($frame, 3, $byteCount)) ?: []);
    }

    private static function validateSlave(int $slave): void
    {
        if ($slave < 1 || $slave > 247) {
            throw new ModbusException('Slave-ID skal være 1-247.');
        }
    }
}
