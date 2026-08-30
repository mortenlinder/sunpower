<?php
declare(strict_types=1);

namespace Solportalen\Device\Profile;

use InvalidArgumentException;

final class RegisterDecoder
{
    /** @param list<int> $words */
    public function decode(array $words, array $definition): int|float|string|array
    {
        if (($definition['word_swap'] ?? false) === true) {
            $words = array_reverse($words);
        }
        if (($definition['byte_swap'] ?? false) === true) {
            $words = array_map(static fn (int $word): int => (($word & 0xFF) << 8) | ($word >> 8), $words);
        }
        $type = $definition['type'] ?? 'uint16';
        $binary = pack('n*', ...$words);
        $raw = match ($type) {
            'uint16' => $words[0] ?? throw new InvalidArgumentException('Manglende register.'),
            'int16' => (($words[0] ?? 0) & 0x8000) ? ($words[0] - 0x10000) : $words[0],
            'uint32' => (int) (unpack('N', $binary)[1] ?? 0),
            'int32' => $this->signed32((int) (unpack('N', $binary)[1] ?? 0)),
            'float32' => (float) (unpack('G', $binary)[1] ?? 0.0),
            'ascii' => rtrim($binary, "\0 "),
            'enum' => ($definition['enum'] ?? [])[(string) ($words[0] ?? 0)] ?? 'unknown',
            'bitfield' => $this->bits($words[0] ?? 0, $definition['bits'] ?? []),
            default => throw new InvalidArgumentException('Ikke-understøttet registertype: ' . $type),
        };
        if (is_int($raw) || is_float($raw)) {
            if (is_int($raw) && !array_key_exists('scale', $definition) && !array_key_exists('offset', $definition)) {
                return $raw;
            }
            return ($raw * (float) ($definition['scale'] ?? 1)) + (float) ($definition['offset'] ?? 0);
        }
        return $raw;
    }

    private function signed32(int $value): int
    {
        return ($value & 0x80000000) !== 0 ? $value - 0x100000000 : $value;
    }

    private function bits(int $value, array $definitions): array
    {
        $result = [];
        foreach ($definitions as $bit => $name) {
            $result[(string) $name] = ($value & (1 << (int) $bit)) !== 0;
        }
        return $result;
    }
}
