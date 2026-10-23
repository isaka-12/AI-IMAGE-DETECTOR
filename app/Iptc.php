<?php
declare(strict_types=1);

/**
 * Iptc: legacy IPTC-IIM fields from a JPEG's Photoshop (APP13) block.
 * Credit, Source and Caption are where tools often write "Made with Google AI",
 * generator names or prompts. Every length is checked against the bytes left.
 */
final class Iptc
{
    // record 2 dataset number => name
    private const FIELDS = [
        5   => 'ObjectName',
        25  => 'Keywords',
        80  => 'By-line',
        105 => 'Headline',
        110 => 'Credit',
        115 => 'Source',
        116 => 'CopyrightNotice',
        120 => 'Caption',
    ];
    private const MAX_PARTS = 4096;
    private const MAX_VALUE = 2000;

    /** @return array<string, string> from the APP13 payload after "Photoshop 3.0\0" */
    public static function fromPhotoshop(string $d): array
    {
        $n = strlen($d);
        $p = 0;
        $out = [];
        for ($i = 0; $i < self::MAX_PARTS && $p + 12 <= $n; $i++) {
            if (substr($d, $p, 4) !== '8BIM') {
                break;
            }
            $id = unpack('n', $d, $p + 4)[1];
            // resource name: Pascal string, padded so length byte + name is even
            $nameLen = ord($d[$p + 6]);
            $q = $p + 7 + $nameLen + (($nameLen + 1) % 2);
            if ($q + 4 > $n) {
                break;
            }
            $size = unpack('N', $d, $q)[1];
            $q += 4;
            if ($size > $n - $q) {
                break;
            }
            if ($id === 0x0404) {   // IPTC-NAA record
                $out += self::iim(substr($d, $q, $size));
            }
            $p = $q + $size + ($size & 1);
        }
        return $out;
    }

    /** @return array<string, string> */
    public static function iim(string $d): array
    {
        $n = strlen($d);
        $p = 0;
        $out = [];
        for ($i = 0; $i < self::MAX_PARTS && $p + 5 <= $n; $i++) {
            if ($d[$p] !== "\x1C") {
                break;
            }
            $record  = ord($d[$p + 1]);
            $dataset = ord($d[$p + 2]);
            $len     = unpack('n', $d, $p + 3)[1];
            if ($len & 0x8000) {    // extended length: only used for binary data, not text fields
                break;
            }
            $p += 5;
            if ($len > $n - $p) {
                break;
            }
            if ($record === 2 && isset(self::FIELDS[$dataset])) {
                $v = substr($d, $p, $len);
                if (!preg_match('//u', $v)) {
                    $v = (string) iconv('ISO-8859-1', 'UTF-8', $v);
                }
                $name = self::FIELDS[$dataset];
                // Keywords repeat once per keyword
                $out[$name] = substr(isset($out[$name]) ? $out[$name] . ', ' . $v : $v, 0, self::MAX_VALUE);
            }
            $p += $len;
        }
        return $out;
    }
}
