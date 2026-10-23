<?php
declare(strict_types=1);

/**
 * Container: walks the JPEG / PNG / WebP structure and pulls out the
 * metadata blocks only. Pixel data is skipped, never decoded.
 *
 * Every length field comes from the (untrusted) upload, so each one is
 * checked against the bytes that are actually left before it is used.
 */
final class Container
{
    private const MAX_PARTS = 4096;      // segments/chunks we are willing to walk
    private const MAX_TEXT  = 1048576;   // cap for one decompressed PNG text chunk
    private const XMP_NS     = "http://ns.adobe.com/xap/1.0/\0";
    private const XMP_EXT_NS = "http://ns.adobe.com/xmp/extension/\0";

    /** @return array{exif:string[], xmp:string[], iptc:string[], text:array<string,string>, jumbf:?string} */
    public static function parse(string $raw, string $mime): array
    {
        $out = ['exif' => [], 'xmp' => [], 'iptc' => [], 'text' => [], 'jumbf' => null];
        match ($mime) {
            'image/jpeg' => self::jpeg($raw, $out),
            'image/png'  => self::png($raw, $out),
            'image/webp' => self::webp($raw, $out),
            default      => null,
        };
        return $out;
    }

    private static function jpeg(string $d, array &$out): void
    {
        $n = strlen($d);
        $p = 2;                 // after SOI (FFD8)
        $jumbf = [];            // APP11 box instance => [sequence => payload]

        for ($i = 0; $i < self::MAX_PARTS && $p + 4 <= $n; $i++) {
            if ($d[$p] !== "\xFF") {
                break;
            }
            $m = ord($d[$p + 1]);
            if ($m === 0xFF) {  // fill byte
                $p++;
                continue;
            }
            if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD8)) {  // markers without a length
                $p += 2;
                continue;
            }
            if ($m === 0xD9 || $m === 0xDA) {  // EOI / start of scan: metadata is over
                break;
            }
            $len = unpack('n', $d, $p + 2)[1];
            if ($len < 2 || $p + 2 + $len > $n) {
                break;
            }
            $seg = substr($d, $p + 4, $len - 2);
            $p  += 2 + $len;

            if ($m === 0xE1) {
                if (str_starts_with($seg, "Exif\0\0")) {
                    $out['exif'][] = substr($seg, 6);
                } elseif (str_starts_with($seg, self::XMP_NS)) {
                    $out['xmp'][] = substr($seg, strlen(self::XMP_NS));
                } elseif (str_starts_with($seg, self::XMP_EXT_NS)) {
                    // extended XMP: 32-byte GUID + 4-byte length + 4-byte offset, then data
                    $out['xmp'][] = substr($seg, strlen(self::XMP_EXT_NS) + 40);
                }
            } elseif ($m === 0xED && str_starts_with($seg, "Photoshop 3.0\0")) {
                $out['iptc'][] = substr($seg, 14);     // 8BIM resources, IPTC-IIM inside
            } elseif ($m === 0xEB && strlen($seg) >= 16 && str_starts_with($seg, 'JP')) {
                // APP11 JUMBF (C2PA): CI "JP", En (box instance), Z (sequence), then the box.
                // Continuation segments repeat the box header, which has to be dropped.
                $en  = unpack('n', $seg, 2)[1];
                $z   = unpack('N', $seg, 4)[1];
                $box = substr($seg, 8);
                if ($z > 1) {
                    $box = substr($box, unpack('N', $box)[1] === 1 ? 16 : 8);
                }
                $jumbf[$en][$z] = $box;
            }
        }

        foreach ($jumbf as $parts) {
            ksort($parts);
            $all = implode('', $parts);
            if (substr($all, 4, 4) === 'jumb') {
                $out['jumbf'] = $all;
                break;
            }
        }
    }

    private static function png(string $d, array &$out): void
    {
        $n = strlen($d);
        $p = 8;                 // after the PNG signature

        for ($i = 0; $i < self::MAX_PARTS && $p + 12 <= $n; $i++) {
            $len  = unpack('N', $d, $p)[1];
            $type = substr($d, $p + 4, 4);
            if ($len > $n - $p - 12) {
                break;
            }
            $start = $p + 8;
            $p    += 12 + $len;     // length + type + data + CRC

            if ($type === 'IEND') {
                break;
            }
            if (!in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'caBX'], true)) {
                continue;           // IDAT and friends: pixels, not metadata
            }
            $data = substr($d, $start, $len);

            if ($type === 'eXIf') {
                $out['exif'][] = $data;
                continue;
            }
            if ($type === 'caBX') {
                $out['jumbf'] ??= $data;
                continue;
            }

            [$key, $rest] = explode("\0", $data, 2) + [1 => ''];
            $key   = substr($key, 0, 79);
            $value = null;
            if ($type === 'tEXt') {
                $value = iconv('ISO-8859-1', 'UTF-8', $rest);
            } elseif ($type === 'zTXt' && $rest !== '') {
                $value = self::inflate(substr($rest, 1));
                $value = $value === null ? null : iconv('ISO-8859-1', 'UTF-8', $value);
            } elseif ($type === 'iTXt' && strlen($rest) >= 2) {
                $compressed = ord($rest[0]) === 1;
                // language tag \0 translated keyword \0 text
                $text  = explode("\0", substr($rest, 2), 3)[2] ?? '';
                $value = $compressed ? self::inflate($text) : $text;
            }
            if ($value === null || $value === false) {
                continue;
            }
            if ($key === 'XML:com.adobe.xmp') {
                $out['xmp'][] = $value;
            } elseif (!isset($out['text'][$key])) {
                $out['text'][$key] = substr($value, 0, self::MAX_TEXT);
            }
        }
    }

    private static function webp(string $d, array &$out): void
    {
        $n = strlen($d);
        if ($n < 12 || !str_starts_with($d, 'RIFF') || substr($d, 8, 4) !== 'WEBP') {
            return;
        }
        $p = 12;

        for ($i = 0; $i < self::MAX_PARTS && $p + 8 <= $n; $i++) {
            $type = substr($d, $p, 4);
            $len  = unpack('V', $d, $p + 4)[1];
            if ($len > $n - $p - 8) {
                break;
            }
            $start = $p + 8;
            $p    += 8 + $len + ($len & 1);   // chunks are padded to an even size

            if ($type === 'EXIF') {
                $exif = substr($d, $start, $len);
                $out['exif'][] = str_starts_with($exif, "Exif\0\0") ? substr($exif, 6) : $exif;
            } elseif ($type === 'XMP ') {
                $out['xmp'][] = substr($d, $start, $len);
            } elseif ($type === 'C2PA') {
                $out['jumbf'] ??= substr($d, $start, $len);
            }
        }
    }

    /** zlib-inflate with an output cap, so a tiny chunk cannot expand into a memory bomb */
    private static function inflate(string $data): ?string
    {
        $v = @gzuncompress($data, self::MAX_TEXT);
        return $v === false ? null : $v;
    }
}
