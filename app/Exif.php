<?php
declare(strict_types=1);

/**
 * Exif: minimal TIFF/EXIF reader for the handful of tags the detector uses.
 * Works the same for JPEG APP1, PNG eXIf and WebP EXIF blobs.
 */
final class Exif
{
    private const IFD0 = [
        0x010E => 'ImageDescription',
        0x010F => 'Make',
        0x0110 => 'Model',
        0x0131 => 'Software',
        0x0132 => 'DateTime',
        0x013B => 'Artist',
        0x8298 => 'Copyright',
        0x9C9B => 'XPTitle',        // Windows tags, UTF-16LE
        0x9C9C => 'XPComment',
        0x9C9D => 'XPAuthor',
        0x9C9E => 'XPKeywords',
        0x8769 => '_exifIfd',
        0x8825 => '_gpsIfd',
    ];
    private const EXIF_IFD = [
        0x829A => 'ExposureTime',
        0x829D => 'FNumber',
        0x8827 => 'ISO',
        0x9003 => 'DateTimeOriginal',
        0x920A => 'FocalLength',
        0x9286 => 'UserComment',
        0xA002 => 'PixelXDimension',
        0xA003 => 'PixelYDimension',
        0xA433 => 'LensMake',
        0xA434 => 'LensModel',
    ];
    private const XP_TAGS = ['XPTitle', 'XPComment', 'XPAuthor', 'XPKeywords'];
    // TIFF type => bytes per value
    private const SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8];
    private const MAX_ENTRIES = 512;
    private const MAX_VALUE   = 65536;

    /** @return array<string, string|int|float> */
    public static function parse(string $tiff): array
    {
        if (strlen($tiff) < 8) {
            return [];
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return [];
        }
        $le = $order === 'II';
        if (self::u16($tiff, 2, $le) !== 42) {
            return [];
        }

        $tags = [];
        self::ifd($tiff, self::u32($tiff, 4, $le), $le, self::IFD0, $tags);
        if (isset($tags['_exifIfd']) && is_int($tags['_exifIfd'])) {
            self::ifd($tiff, $tags['_exifIfd'], $le, self::EXIF_IFD, $tags);
        }
        unset($tags['_exifIfd']);
        // only whether a location is recorded, never the coordinates themselves
        if (isset($tags['_gpsIfd'])) {
            $tags['GPS'] = 'present';
        }
        unset($tags['_gpsIfd']);

        foreach (self::XP_TAGS as $xp) {
            if (isset($tags[$xp]) && is_string($tags[$xp])) {
                $tags[$xp] = rtrim((string) @iconv('UTF-16LE', 'UTF-8//IGNORE', $tags[$xp]), "\0 ");
            }
        }

        if (isset($tags['UserComment']) && is_string($tags['UserComment'])) {
            $tags['UserComment'] = self::userComment($tags['UserComment']);
        }
        return $tags;
    }

    private static function ifd(string $t, int $off, bool $le, array $want, array &$tags): void
    {
        $n = strlen($t);
        if ($off < 8 || $off + 2 > $n) {
            return;
        }
        $count = min(self::u16($t, $off, $le), self::MAX_ENTRIES);

        for ($i = 0; $i < $count; $i++) {
            $e = $off + 2 + $i * 12;
            if ($e + 12 > $n) {
                return;
            }
            $tag = self::u16($t, $e, $le);
            if (!isset($want[$tag])) {
                continue;
            }
            $type = self::u16($t, $e + 2, $le);
            $size = (self::SIZES[$type] ?? 0) * self::u32($t, $e + 4, $le);
            if ($size === 0 || $size > self::MAX_VALUE) {
                continue;
            }
            $at = $size <= 4 ? $e + 8 : self::u32($t, $e + 8, $le);
            if ($at + $size > $n) {
                continue;
            }
            $raw = substr($t, $at, $size);

            $tags[$want[$tag]] = match ($type) {
                2       => rtrim($raw, "\0 "),
                3       => self::u16($raw, 0, $le),
                4       => self::u32($raw, 0, $le),
                5, 10   => self::rational($raw, $le, $type === 10),
                default => $raw,
            };
        }
    }

    /** UserComment starts with an 8-byte charset id (A1111 stores its generation parameters here) */
    private static function userComment(string $v): string
    {
        $id   = substr($v, 0, 8);
        $text = substr($v, 8);
        if ($id === "UNICODE\0") {
            // byte order is not reliable in the wild: guess it from where the zero bytes sit
            $even = $odd = 0;
            for ($i = 0, $l = min(strlen($text), 512); $i < $l; $i++) {
                if ($text[$i] === "\0") {
                    $i % 2 ? $odd++ : $even++;
                }
            }
            $text = (string) @iconv($even >= $odd ? 'UTF-16BE' : 'UTF-16LE', 'UTF-8//IGNORE', $text);
        }
        return rtrim($text, "\0 ");
    }

    private static function rational(string $raw, bool $le, bool $signed): float
    {
        $num = self::u32($raw, 0, $le);
        $den = self::u32($raw, 4, $le);
        if ($signed) {
            $num = $num >= 0x80000000 ? $num - 0x100000000 : $num;
            $den = $den >= 0x80000000 ? $den - 0x100000000 : $den;
        }
        return $den === 0 ? 0.0 : $num / $den;
    }

    private static function u16(string $s, int $o, bool $le): int
    {
        return unpack($le ? 'v' : 'n', $s, $o)[1];
    }

    private static function u32(string $s, int $o, bool $le): int
    {
        return unpack($le ? 'V' : 'N', $s, $o)[1];
    }
}
