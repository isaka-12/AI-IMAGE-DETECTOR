<?php
declare(strict_types=1);

/**
 * C2pa: reads a C2PA manifest store (JUMBF boxes holding CBOR assertions)
 * and reports what the active manifest *declares*: the IPTC digital source
 * type of its actions and the software that made it.
 *
 * The COSE signature and certificate chain are NOT verified here, so the
 * result is a claim, not proof. A forger can write any manifest they like.
 */
final class C2pa
{
    private const MAX_DEPTH = 16;
    private const MAX_BOXES = 1024;

    /** @return array{ok:bool, sourceTypes:string[], agents:string[], actions:string[]} */
    public static function read(string $jumbf): array
    {
        $r = ['ok' => false, 'sourceTypes' => [], 'agents' => [], 'actions' => []];
        try {
            $top = self::boxes($jumbf, 0, strlen($jumbf));
            if (!$top || $top[0][0] !== 'jumb') {
                return $r;
            }
            $store = self::superbox($jumbf, $top[0][1], $top[0][2], 0);
            if ($store['label'] !== 'c2pa' || !$store['children']) {
                return $r;
            }
            // the last manifest in the store is the active one (earlier ones belong to ingredients)
            $active = end($store['children']);

            foreach ($active['children'] as $box) {
                if (preg_match('/^c2pa\.claim(\.v\d+)?$/', $box['label'])) {
                    self::walk(self::cbor($box), $r);
                } elseif ($box['label'] === 'c2pa.assertions') {
                    foreach ($box['children'] as $a) {
                        if (preg_match('/^c2pa\.actions(\.v\d+)?(__\d+)?$/', $a['label'])) {
                            self::walk(self::cbor($a), $r);
                        }
                    }
                }
            }
        } catch (RuntimeException | ValueError) {
            return ['ok' => false, 'sourceTypes' => [], 'agents' => [], 'actions' => []];
        }

        $r['ok'] = true;
        $r['sourceTypes'] = array_values(array_unique($r['sourceTypes']));
        $r['agents'] = array_values(array_unique($r['agents']));
        $r['actions'] = array_values(array_unique($r['actions']));
        return $r;
    }

    /** @return list<array{0:string, 1:int, 2:int}> box type, payload start, payload end */
    private static function boxes(string $d, int $p, int $end): array
    {
        $out = [];
        while ($p + 8 <= $end) {
            if (count($out) >= self::MAX_BOXES) {
                throw new RuntimeException('Too many JUMBF boxes');
            }
            $len  = unpack('N', $d, $p)[1];
            $type = substr($d, $p + 4, 4);
            $hdr  = 8;
            if ($len === 1) {           // 64-bit extended length follows
                if ($p + 16 > $end) {
                    throw new RuntimeException('Truncated JUMBF box');
                }
                $len = unpack('J', $d, $p + 8)[1];
                $hdr = 16;
            } elseif ($len === 0) {     // box runs to the end
                $len = $end - $p;
            }
            if ($len < $hdr || $len > $end - $p) {
                throw new RuntimeException('Bad JUMBF box length');
            }
            $out[] = [$type, $p + $hdr, $p + $len];
            $p += $len;
        }
        return $out;
    }

    /** @return array{label:string, content:list<array{0:string,1:string}>, children:list<array>} */
    private static function superbox(string $d, int $start, int $end, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('JUMBF nested too deep');
        }
        $sb = ['label' => '', 'content' => [], 'children' => []];
        foreach (self::boxes($d, $start, $end) as $i => [$type, $s, $e]) {
            if ($i === 0 && $type === 'jumd') {
                $sb['label'] = self::label(substr($d, $s, $e - $s));
            } elseif ($type === 'jumb') {
                $sb['children'][] = self::superbox($d, $s, $e, $depth + 1);
            } else {
                $sb['content'][] = [$type, substr($d, $s, $e - $s)];
            }
        }
        return $sb;
    }

    /** jumd: 16-byte type UUID, 1 toggle byte, then a NUL-terminated label if bit 0x02 is set */
    private static function label(string $jumd): string
    {
        if (strlen($jumd) < 17 || !(ord($jumd[16]) & 0x02)) {
            return '';
        }
        $label = substr($jumd, 17);
        $nul = strpos($label, "\0");
        return substr($nul === false ? $label : substr($label, 0, $nul), 0, 256);
    }

    private static function cbor(array $box): mixed
    {
        foreach ($box['content'] as [$type, $data]) {
            if ($type === 'cbor') {
                return Cbor::decode($data);
            }
        }
        return null;
    }

    /** Collect digitalSourceType values and software names from a decoded claim / actions assertion */
    private static function walk(mixed $v, array &$r, int $depth = 0): void
    {
        if (!is_array($v) || $depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($v as $k => $x) {
            if ($k === 'digitalSourceType' && is_string($x)) {
                $r['sourceTypes'][] = $x;
            } elseif ($k === 'action' && is_string($x)) {
                $r['actions'][] = $x;
            } elseif (in_array($k, ['softwareAgent', 'claim_generator', 'claim_generator_info'], true)) {
                array_push($r['agents'], ...self::names($x));
            } else {
                self::walk($x, $r, $depth + 1);
            }
        }
    }

    /** softwareAgent / claim_generator_info can be a string, a {name, version} map or a list of them */
    private static function names(mixed $x): array
    {
        if (is_string($x)) {
            return [$x];
        }
        if (!is_array($x)) {
            return [];
        }
        if (isset($x['name']) && is_string($x['name'])) {
            $version = isset($x['version']) && is_string($x['version']) ? ' ' . $x['version'] : '';
            return [$x['name'] . $version];
        }
        $out = [];
        foreach (array_slice($x, 0, 8) as $item) {
            if (is_array($item) || is_string($item)) {
                array_push($out, ...self::names($item));
            }
        }
        return $out;
    }
}
