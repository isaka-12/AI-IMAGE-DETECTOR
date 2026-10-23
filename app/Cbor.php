<?php
declare(strict_types=1);

/**
 * Cbor: small CBOR (RFC 8949) decoder for C2PA assertions.
 * Depth and item counts are capped and every length is checked against the
 * bytes left, so hostile input fails fast instead of eating memory.
 */
final class Cbor
{
    private const MAX_DEPTH = 32;
    private const MAX_ITEMS = 100000;

    private int $p = 0;
    private int $items = 0;

    private function __construct(private readonly string $d)
    {
    }

    /** @throws RuntimeException on malformed or oversized input */
    public static function decode(string $d): mixed
    {
        return (new self($d))->item(0);
    }

    private function item(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH || ++$this->items > self::MAX_ITEMS) {
            throw new RuntimeException('CBOR too deep or too large');
        }
        $b     = ord($this->take(1));
        $major = $b >> 5;
        $info  = $b & 0x1F;

        if ($major === 7) {
            return $this->simple($info);
        }
        if ($info === 31 && $major >= 2 && $major <= 5) {
            return $this->indefinite($major, $depth);
        }
        $n = $this->arg($info);

        return match ($major) {
            0       => $n,
            1       => -1 - $n,
            2, 3    => $this->take($n),
            4       => $this->list($n, $depth),
            5       => $this->map($n, $depth),
            default => $this->item($depth + 1),   // 6 = tag: keep the tagged value
        };
    }

    private function simple(int $info): mixed
    {
        if ($info === 24) {     // one-byte simple value: nothing C2PA uses
            $this->take(1);
            return null;
        }
        return match ($info) {
            20      => false,
            21      => true,
            25      => self::half(unpack('n', $this->take(2))[1]),
            26      => unpack('G', $this->take(4))[1],
            27      => unpack('E', $this->take(8))[1],
            28, 29, 30, 31 => throw new RuntimeException('Unsupported CBOR simple value'),
            default => null,   // 22 null, 23 undefined, unassigned simple values
        };
    }

    private function arg(int $info): int
    {
        $n = match (true) {
            $info < 24   => $info,
            $info === 24 => ord($this->take(1)),
            $info === 25 => unpack('n', $this->take(2))[1],
            $info === 26 => unpack('N', $this->take(4))[1],
            $info === 27 => unpack('J', $this->take(8))[1],
            default      => throw new RuntimeException('Reserved CBOR length'),
        };
        if ($n < 0) {
            throw new RuntimeException('CBOR length overflow');
        }
        return $n;
    }

    private function list(int $n, int $depth): array
    {
        // every item takes at least one byte: refuse counts the input cannot hold
        if ($n > strlen($this->d) - $this->p) {
            throw new RuntimeException('CBOR array longer than input');
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->item($depth + 1);
        }
        return $out;
    }

    private function map(int $n, int $depth): array
    {
        if ($n * 2 > strlen($this->d) - $this->p) {
            throw new RuntimeException('CBOR map longer than input');
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $k = self::key($this->item($depth + 1));
            $out[$k] = $this->item($depth + 1);
        }
        return $out;
    }

    /** Indefinite-length string, array or map: items until the 0xFF "break" byte */
    private function indefinite(int $major, int $depth): mixed
    {
        $out = $major <= 3 ? '' : [];
        while ($this->peek() !== "\xFF") {
            if ($major <= 3) {
                $chunk = $this->item($depth + 1);
                if (!is_string($chunk)) {
                    throw new RuntimeException('Bad CBOR string chunk');
                }
                $out .= $chunk;
            } elseif ($major === 4) {
                $out[] = $this->item($depth + 1);
            } else {
                $k = self::key($this->item($depth + 1));
                $out[$k] = $this->item($depth + 1);
            }
        }
        $this->p++;
        return $out;
    }

    private static function key(mixed $k): int|string
    {
        return is_int($k) || is_string($k) ? $k : '?';
    }

    private function peek(): string
    {
        if ($this->p >= strlen($this->d)) {
            throw new RuntimeException('CBOR truncated');
        }
        return $this->d[$this->p];
    }

    private function take(int $n): string
    {
        if ($n > strlen($this->d) - $this->p) {
            throw new RuntimeException('CBOR truncated');
        }
        $s = substr($this->d, $this->p, $n);
        $this->p += $n;
        return $s;
    }

    private static function half(int $h): float
    {
        $exp = ($h >> 10) & 0x1F;
        $man = $h & 0x3FF;
        $v = match ($exp) {
            0       => $man * 2 ** -24,
            31      => $man ? NAN : INF,
            default => (1 + $man / 1024) * 2 ** ($exp - 15),
        };
        return $h & 0x8000 ? -$v : $v;
    }
}
