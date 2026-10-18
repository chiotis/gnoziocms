<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Reads what the CMS needs to know about a font file: its names, weight (or weight range of a variable font), style, the scripts
 * its character map covers, and the measures that let a fallback font take the same room while it loads.
 *
 * TTF, OTF, WOFF and font collections are read as they are (WOFF is unzipped table by table). WOFF2 keeps its tables in one
 * Brotli stream, which only a server with the brotli extension can open; without it a WOFF2 file is accepted but reports
 * `readable: false` and no coverage, and the admin lets the person say what it covers (or asks the browser). Nothing here
 * runs a font, and every offset is checked against the bytes, so a broken or hostile file gives null or less information, never an error.
 */
final class FontFile
{
    public const MAX_BYTES = 6291456;

    /** Script groups: key => [label, the code point ranges that count as "this script", how many of the probe points must be present]. */
    public const GROUPS = [
        'latin' => ['Latin', [[0x41, 0x5A], [0x61, 0x7A]], 52],
        'greek' => ['Greek', [[0x391, 0x3A9], [0x3B1, 0x3C9]], 40],
        'greek_ext' => ['Greek Extended', [[0x1F00, 0x1FFF]], 60],
        'cyrillic' => ['Cyrillic', [[0x410, 0x44F]], 60],
        'symbols' => ['Symbols', [[0x20AC, 0x20AC], [0x2022, 0x2022], [0x2192, 0x2192], [0x2122, 0x2122], [0xA9, 0xA9], [0x2713, 0x2713]], 3],
    ];

    /** Helvetica's advance widths for a to z, per 1000 units: the reference a fallback (Arial) is measured against. */
    private const REFERENCE_ADVANCES = [556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500];

    /**
     * @return array{
     *   format: string, readable: bool, family: string, subfamily: string, weight: int, weight_max: int|null, variable: bool,
     *   italic: bool, units: int, ranges: list<array{0: int, 1: int}>, cover: list<string>|null, ascent: int, descent: int,
     *   line_gap: int, avg_width: float|null
     * }|null null when the bytes are not a font
     */
    public static function parse(string $bytes): ?array
    {
        $length = strlen($bytes);
        if ($length < 12 || $length > self::MAX_BYTES * 2) {
            return null;
        }
        $magic = substr($bytes, 0, 4);
        $format = match (true) {
            $magic === 'wOF2' => 'woff2',
            $magic === 'wOFF' => 'woff',
            $magic === "\x00\x01\x00\x00" || $magic === 'true' => 'ttf',
            $magic === 'OTTO' => 'otf',
            $magic === 'ttcf' => 'ttf',
            default => null,
        };
        if ($format === null) {
            return null;
        }
        $tables = match ($format) {
            'woff2' => self::woff2Tables($bytes),
            'woff' => self::woffTables($bytes),
            default => self::sfntTables($bytes),
        };
        $info = [
            'format' => $format, 'readable' => $tables !== null, 'family' => '', 'subfamily' => '', 'weight' => 400, 'weight_max' => null,
            'variable' => false, 'italic' => false, 'units' => 1000, 'ranges' => [], 'cover' => null, 'ascent' => 0, 'descent' => 0,
            'line_gap' => 0, 'avg_width' => null,
        ];
        if ($tables === null || !isset($tables['cmap'])) {
            return $info;
        }

        $head = $tables['head'] ?? '';
        if (strlen($head) >= 46) {
            $info['units'] = max(16, self::u16($head, 18));
            $info['italic'] = (self::u16($head, 44) & 2) !== 0;
        }
        $os2 = $tables['OS/2'] ?? '';
        if (strlen($os2) >= 64) {
            $class = self::u16($os2, 4);
            $info['weight'] = $class >= 1 && $class <= 1000 ? max(100, min(900, (int)round($class / 100) * 100)) : 400;
            if ((self::u16($os2, 62) & 1) !== 0) {
                $info['italic'] = true;
            }
        }
        $hhea = $tables['hhea'] ?? '';
        if (strlen($hhea) >= 36) {
            $info['ascent'] = self::i16($hhea, 4);
            $info['descent'] = self::i16($hhea, 6);
            $info['line_gap'] = self::i16($hhea, 8);
        }
        $fvar = $tables['fvar'] ?? '';
        if (strlen($fvar) >= 16) {
            $at = self::u16($fvar, 4);
            $count = self::u16($fvar, 8);
            $size = max(20, self::u16($fvar, 10));
            for ($i = 0; $i < min($count, 32); $i++) {
                $axis = substr($fvar, $at + $i * $size, 20);
                if (strlen($axis) === 20 && substr($axis, 0, 4) === 'wght') {
                    $low = (int)round(self::fixed($axis, 4));
                    $high = (int)round(self::fixed($axis, 12));
                    if ($high > $low) {
                        $info['variable'] = true;
                        $info['weight'] = max(1, min(1000, $low));
                        $info['weight_max'] = max(1, min(1000, $high));
                    }
                }
            }
        }
        [$info['family'], $info['subfamily']] = self::names($tables['name'] ?? '');
        if (preg_match('/\b(italic|oblique)\b/i', $info['subfamily'])) {
            $info['italic'] = true;
        }

        $map = self::cmap($tables['cmap']);
        $info['ranges'] = $map['ranges'];
        $info['cover'] = self::coverage($map['ranges']);
        $info['avg_width'] = self::averageWidth($tables, $map['glyphs'], $info['units']);
        return $info;
    }

    /** @return array{0: string, 1: string} the family and the style name of the font */
    private static function names(string $name): array
    {
        if (strlen($name) < 6) {
            return ['', ''];
        }
        $count = self::u16($name, 2);
        $store = self::u16($name, 4);
        $found = [];
        for ($i = 0; $i < min($count, 400); $i++) {
            $at = 6 + $i * 12;
            if ($at + 12 > strlen($name)) {
                break;
            }
            $platform = self::u16($name, $at);
            $language = self::u16($name, $at + 4);
            $id = self::u16($name, $at + 6);
            if (!in_array($id, [1, 2, 16, 17], true)) {
                continue;
            }
            $raw = substr($name, $store + self::u16($name, $at + 10), self::u16($name, $at + 8));
            $text = $platform === 3 || $platform === 0 ? (string)@mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw;
            $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '');
            // The Windows English name wins; any other is the fallback.
            $rank = $platform === 3 && $language === 0x409 ? 2 : 1;
            if ($text !== '' && ($found[$id][0] ?? 0) < $rank) {
                $found[$id] = [$rank, $text];
            }
        }
        return [($found[16][1] ?? $found[1][1] ?? ''), ($found[17][1] ?? $found[2][1] ?? '')];
    }

    /**
     * Every code point the font draws, as merged ranges, and the glyphs of a to z (for measuring).
     *
     * @return array{ranges: list<array{0: int, 1: int}>, glyphs: array<int, int>}
     */
    private static function cmap(string $cmap): array
    {
        $ranges = [];
        $glyphs = [];
        $tables = self::u16($cmap, 2);
        $seen = [];
        for ($i = 0; $i < min($tables, 40); $i++) {
            $platform = self::u16($cmap, 4 + $i * 8);
            $encoding = self::u16($cmap, 6 + $i * 8);
            $offset = self::u32($cmap, 8 + $i * 8);
            $unicode = $platform === 0 || ($platform === 3 && in_array($encoding, [1, 10], true));
            if (!$unicode || isset($seen[$offset]) || $offset + 4 > strlen($cmap)) {
                continue;
            }
            $seen[$offset] = true;
            $format = self::u16($cmap, $offset);
            if ($format === 4) {
                self::cmapFormat4($cmap, $offset, $ranges, $glyphs);
            } elseif ($format === 12) {
                self::cmapFormat12($cmap, $offset, $ranges, $glyphs);
            }
        }
        return ['ranges' => self::merge($ranges), 'glyphs' => $glyphs];
    }

    private static function cmapFormat4(string $cmap, int $at, array &$ranges, array &$glyphs): void
    {
        $segments = intdiv(self::u16($cmap, $at + 6), 2);
        $ends = $at + 14;
        $starts = $ends + $segments * 2 + 2;
        $deltas = $starts + $segments * 2;
        $offsets = $deltas + $segments * 2;
        if ($segments < 1 || $segments > 20000 || $offsets + $segments * 2 > strlen($cmap)) {
            return;
        }
        for ($s = 0; $s < $segments; $s++) {
            $end = self::u16($cmap, $ends + $s * 2);
            $start = self::u16($cmap, $starts + $s * 2);
            if ($start === 0xFFFF || $end < $start) {
                continue;
            }
            $delta = self::u16($cmap, $deltas + $s * 2);
            $rangeOffset = self::u16($cmap, $offsets + $s * 2);
            if ($rangeOffset === 0) {
                $ranges[] = [$start, $end];
                for ($c = max($start, 97); $c <= min($end, 122); $c++) {
                    $glyphs[$c] = ($c + $delta) & 0xFFFF;
                }
                continue;
            }
            $runStart = null;
            for ($c = $start; $c <= $end; $c++) {
                $pos = $offsets + $s * 2 + $rangeOffset + ($c - $start) * 2;
                $glyph = $pos + 2 <= strlen($cmap) ? self::u16($cmap, $pos) : 0;
                $glyph = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                if ($glyph !== 0) {
                    $runStart ??= $c;
                    if ($c >= 97 && $c <= 122) {
                        $glyphs[$c] = $glyph;
                    }
                } elseif ($runStart !== null) {
                    $ranges[] = [$runStart, $c - 1];
                    $runStart = null;
                }
            }
            if ($runStart !== null) {
                $ranges[] = [$runStart, $end];
            }
        }
    }

    private static function cmapFormat12(string $cmap, int $at, array &$ranges, array &$glyphs): void
    {
        $groups = min(self::u32($cmap, $at + 12), 40000);
        for ($g = 0; $g < $groups; $g++) {
            $pos = $at + 16 + $g * 12;
            if ($pos + 12 > strlen($cmap)) {
                return;
            }
            $start = self::u32($cmap, $pos);
            $end = min(self::u32($cmap, $pos + 4), 0x10FFFF);
            if ($end < $start) {
                continue;
            }
            $ranges[] = [$start, $end];
            for ($c = max($start, 97); $c <= min($end, 122); $c++) {
                $glyphs[$c] = self::u32($cmap, $pos + 8) + ($c - $start);
            }
        }
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function merge(array $ranges): array
    {
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($out) - 1;
            if ($last >= 0 && $start <= $out[$last][1] + 1) {
                $out[$last][1] = max($out[$last][1], $end);
            } else {
                $out[] = [$start, $end];
            }
        }
        return $out;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @return list<string> the keys of GROUPS the font covers
     */
    public static function coverage(array $ranges): array
    {
        $found = [];
        foreach (self::GROUPS as $key => [, $blocks, $needed]) {
            $present = 0;
            foreach ($blocks as [$from, $to]) {
                foreach ($ranges as [$start, $end]) {
                    if ($end >= $from && $start <= $to) {
                        $present += min($end, $to) - max($start, $from) + 1;
                    }
                }
            }
            if ($present >= $needed) {
                $found[] = $key;
            }
        }
        return $found;
    }

    /**
     * The font's code points as a CSS unicode-range, kept short: the smallest gaps are filled until there are at most $limit ranges.
     *
     * @param list<array{0: int, 1: int}> $ranges
     */
    public static function unicodeRange(array $ranges, int $limit = 48): string
    {
        $ranges = array_values(array_filter($ranges, static fn(array $r): bool => $r[1] >= 0x20));
        while (count($ranges) > $limit) {
            $best = 1;
            $gap = PHP_INT_MAX;
            for ($i = 1; $i < count($ranges); $i++) {
                $size = $ranges[$i][0] - $ranges[$i - 1][1];
                if ($size < $gap) {
                    $gap = $size;
                    $best = $i;
                }
            }
            $ranges[$best - 1][1] = $ranges[$best][1];
            array_splice($ranges, $best, 1);
        }
        return implode(',', array_map(static fn(array $r): string => $r[0] === $r[1]
            ? 'U+' . strtoupper(dechex($r[0]))
            : 'U+' . strtoupper(dechex($r[0])) . '-' . strtoupper(dechex($r[1])), $ranges));
    }

    /** The average advance of a to z in em, or null when it cannot be measured. */
    private static function averageWidth(array $tables, array $glyphs, int $units): ?float
    {
        $hhea = $tables['hhea'] ?? '';
        $hmtx = $tables['hmtx'] ?? '';
        if (strlen($hhea) < 36 || $hmtx === '' || count($glyphs) < 26) {
            return null;
        }
        $metrics = self::u16($hhea, 34);
        if ($metrics < 1) {
            return null;
        }
        $total = 0;
        foreach ($glyphs as $glyph) {
            $at = min($glyph, $metrics - 1) * 4;
            if ($at + 2 > strlen($hmtx)) {
                return null;
            }
            $total += self::u16($hmtx, $at);
        }
        return $total / count($glyphs) / $units;
    }

    /**
     * The adjustments that make a system font take the room this font takes: size-adjust, ascent, descent and line gap, in percent.
     *
     * @param array<string, mixed> $info the result of parse()
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function fallbackMetrics(array $info): ?array
    {
        $average = $info['avg_width'] ?? null;
        $units = (int)($info['units'] ?? 0);
        if (!is_float($average) || $average <= 0 || $units < 16 || (int)$info['ascent'] <= 0) {
            return null;
        }
        $reference = array_sum(self::REFERENCE_ADVANCES) / count(self::REFERENCE_ADVANCES) / 1000;
        $scale = $average / $reference;
        if ($scale < 0.7 || $scale > 1.4) {
            return null;
        }
        return [
            round($scale * 100, 2),
            round((int)$info['ascent'] / $units / $scale * 100, 2),
            round(abs((int)$info['descent']) / $units / $scale * 100, 2),
            round((int)$info['line_gap'] / $units / $scale * 100, 2),
        ];
    }

    // ---- containers

    /** @return array<string, string>|null table tag => bytes */
    private static function sfntTables(string $bytes): ?array
    {
        $base = 0;
        if (substr($bytes, 0, 4) === 'ttcf') {
            $base = self::u32($bytes, 12);
        }
        $count = self::u16($bytes, $base + 4);
        if ($count < 1 || $count > 64 || $base + 12 + $count * 16 > strlen($bytes)) {
            return null;
        }
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $at = $base + 12 + $i * 16;
            $tag = substr($bytes, $at, 4);
            $offset = self::u32($bytes, $at + 8);
            $length = self::u32($bytes, $at + 12);
            if ($offset > strlen($bytes) || $length > strlen($bytes) - $offset) {
                continue;
            }
            if (in_array($tag, ['cmap', 'head', 'hhea', 'hmtx', 'name', 'OS/2', 'fvar'], true)) {
                $tables[$tag] = substr($bytes, $offset, $length);
            }
        }
        return $tables;
    }

    /** @return array<string, string>|null */
    private static function woffTables(string $bytes): ?array
    {
        if (strlen($bytes) < 44 || !function_exists('gzuncompress')) {
            return null;
        }
        $count = self::u16($bytes, 12);
        if ($count < 1 || $count > 64 || 44 + $count * 20 > strlen($bytes)) {
            return null;
        }
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $at = 44 + $i * 20;
            $tag = substr($bytes, $at, 4);
            $offset = self::u32($bytes, $at + 4);
            $compressed = self::u32($bytes, $at + 8);
            $original = self::u32($bytes, $at + 12);
            if (!in_array($tag, ['cmap', 'head', 'hhea', 'hmtx', 'name', 'OS/2', 'fvar'], true)
                || $offset > strlen($bytes) || $compressed > strlen($bytes) - $offset || $original > 8388608) {
                continue;
            }
            $data = substr($bytes, $offset, $compressed);
            $data = $compressed < $original ? @gzuncompress($data, $original) : $data;
            if (is_string($data) && strlen($data) === $original) {
                $tables[$tag] = $data;
            }
        }
        return $tables;
    }

    /** @return array<string, string>|null null when the Brotli stream cannot be opened here */
    private static function woff2Tables(string $bytes): ?array
    {
        if (!function_exists('brotli_uncompress') || strlen($bytes) < 48) {
            return null;
        }
        static $known = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar', 'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill'];
        $count = self::u16($bytes, 12);
        $compressedSize = self::u32($bytes, 20);
        if ($count < 1 || $count > 64) {
            return null;
        }
        $at = 48;
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $flags = ord($bytes[$at] ?? "\0");
            $at++;
            $index = $flags & 0x3F;
            if ($index === 63) {
                $tag = substr($bytes, $at, 4);
                $at += 4;
            } else {
                $tag = $known[$index] ?? '';
            }
            $original = self::base128($bytes, $at);
            $transform = $flags >> 6;
            $transformed = ($tag === 'glyf' || $tag === 'loca') ? $transform === 0 : $transform !== 0;
            $stored = $original;
            if ($transformed) {
                $stored = self::base128($bytes, $at);
            }
            if ($original === null || $stored === null || strlen($tag) !== 4) {
                return null;
            }
            $entries[] = [$tag, $stored, $transformed];
        }
        $data = @brotli_uncompress(substr($bytes, $at, $compressedSize));
        if (!is_string($data)) {
            return null;
        }
        $tables = [];
        $position = 0;
        foreach ($entries as [$tag, $size, $transformed]) {
            // A transformed hmtx is not the plain table; the width is then not measured.
            if (in_array($tag, ['cmap', 'head', 'hhea', 'hmtx', 'name', 'OS/2', 'fvar'], true) && !($tag === 'hmtx' && $transformed)) {
                $tables[$tag] = substr($data, $position, $size);
            }
            $position += $size;
        }
        return $tables;
    }

    private static function base128(string $bytes, int &$at): ?int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($at >= strlen($bytes)) {
                return null;
            }
            $byte = ord($bytes[$at++]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }
        return null;
    }

    // ---- numbers (every read is bounds-safe: past the end it gives 0)

    private static function u16(string $bytes, int $at): int
    {
        return $at >= 0 && $at + 2 <= strlen($bytes) ? (ord($bytes[$at]) << 8) | ord($bytes[$at + 1]) : 0;
    }

    private static function i16(string $bytes, int $at): int
    {
        $value = self::u16($bytes, $at);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private static function u32(string $bytes, int $at): int
    {
        return $at >= 0 && $at + 4 <= strlen($bytes) ? (int)unpack('N', $bytes, $at)[1] : 0;
    }

    private static function fixed(string $bytes, int $at): float
    {
        $value = self::u32($bytes, $at);
        return ($value >= 0x80000000 ? $value - 0x100000000 : $value) / 65536;
    }
}
