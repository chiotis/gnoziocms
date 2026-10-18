<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The custom font families of Theme > Branding.
 *
 * A family is a name and a list of font files (stored as `design.fonts`, one flat list: every entry says which family it belongs to,
 * the file under custom/assets, its weight and style, and what was read from the file when it was uploaded). A family is chosen for
 * headings (`design.heading_family`) or text (`design.body_family`) by setting that font to "Your own font". Everything is checked
 * on the way in and again on the way out, and the CSS is made of those checked parts only.
 *
 * A site that has no entry and no chosen family gets nothing from here, so its pages stay as they were. The single `font_file` of
 * earlier versions keeps working; it is used by a role that chose "Your own font" and has no family.
 */
final class BrandingFonts
{
    public const DIRECTORY = 'fonts';
    public const FORMATS = ['woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'];
    private const WEIGHTS = ['thin' => 100, 'hairline' => 100, 'extralight' => 200, 'ultralight' => 200, 'light' => 300, 'regular' => 400, 'book' => 400, 'normal' => 400,
        'medium' => 500, 'semibold' => 600, 'demibold' => 600, 'demi' => 600, 'bold' => 700, 'extrabold' => 800, 'ultrabold' => 800, 'black' => 900, 'heavy' => 900];
    private const RESERVED = ['inter', 'brand font'];

    /** The weight headings are drawn in when Branding does not choose one (the theme's own). */
    private const HEADING_WEIGHT = 700;

    // ---- reading the settings

    /**
     * The font entries of the settings that can be used, each with every part checked.
     *
     * @param array<string, mixed> $design
     * @return list<array{family: string, file: string, weight: string, style: string, range: string, cover: list<string>|null, adjust: list<float>|null, v: string}>
     */
    public static function entries(array $design): array
    {
        $list = $design['fonts'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $entries = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $family = self::cleanName((string)($item['family'] ?? ''));
            $file = self::cleanFile((string)($item['file'] ?? ''));
            if ($family === '' || $file === '') {
                continue;
            }
            $entries[] = [
                'family' => $family,
                'file' => $file,
                'weight' => self::cleanWeight((string)($item['weight'] ?? '')),
                'style' => ($item['style'] ?? '') === 'italic' ? 'italic' : 'normal',
                'range' => self::cleanRange((string)($item['range'] ?? '')),
                'cover' => self::cleanCover((string)($item['cover'] ?? '')),
                'adjust' => self::cleanAdjust((string)($item['adjust'] ?? '')),
                'v' => preg_match('/^[a-f0-9]{4,16}$/', (string)($item['v'] ?? '')) === 1 ? (string)$item['v'] : '',
            ];
        }
        return $entries;
    }

    /**
     * @param array<string, mixed> $design
     * @return array<string, list<array<string, mixed>>> family name => its entries
     */
    public static function families(array $design): array
    {
        $families = [];
        foreach (self::entries($design) as $entry) {
            $families[$entry['family']][] = $entry;
        }
        return $families;
    }

    /** The family a role ('heading' or 'body') uses: only when that font is "Your own font" and the family exists. */
    public static function roleFamily(array $design, string $role): ?string
    {
        if (($design[$role . '_font'] ?? '') !== 'custom') {
            return null;
        }
        $families = self::families($design);
        $name = self::cleanName((string)($design[$role . '_family'] ?? ''));
        if ($name !== '') {
            return isset($families[$name]) ? $name : null;
        }
        // Nothing chosen yet: the only family there is, when no file of the old kind is set to be used instead.
        if (count($families) === 1 && trim((string)($design['font_file'] ?? '')) === '') {
            return (string)array_key_first($families);
        }
        return null;
    }

    /**
     * The font stack of each role that uses a family.
     *
     * @return array<string, string> 'heading' / 'body' => value of --font-heading / --font-body
     */
    public static function stacks(array $design): array
    {
        $families = self::families($design);
        $stacks = [];
        foreach (['heading', 'body'] as $role) {
            $name = self::roleFamily($design, $role);
            if ($name !== null) {
                $stacks[$role] = self::stack($name, $families[$name]);
            }
        }
        return $stacks;
    }

    /** @param list<array<string, mixed>> $files */
    private static function stack(string $family, array $files): string
    {
        $fallback = self::fallbackEntry($files) !== null ? ', "' . $family . ' Fallback"' : '';
        return '"' . $family . '"' . $fallback . ', var(--font-system)';
    }

    /** The file that stands for the family when a system font is sized to match it: the regular upright one. */
    private static function fallbackEntry(array $files): ?array
    {
        $best = null;
        $distance = PHP_INT_MAX;
        foreach ($files as $file) {
            if ($file['adjust'] === null) {
                continue;
            }
            [$low, $high] = self::weightRange($file['weight']);
            $gap = ($low <= 400 && $high >= 400 ? 0 : min(abs($low - 400), abs($high - 400))) + ($file['style'] === 'italic' ? 1000 : 0);
            if ($gap < $distance) {
                $distance = $gap;
                $best = $file;
            }
        }
        return $best;
    }

    // ---- what a page gets

    /**
     * The @font-face rules of the families the headings and the text use, and the faces that size a system font to stand in for them.
     *
     * @param array<string, mixed> $design
     */
    public static function fontFaces(array $design, string $baseUrl): string
    {
        $families = self::families($design);
        $used = [];
        foreach (['heading', 'body'] as $role) {
            $name = self::roleFamily($design, $role);
            if ($name !== null) {
                $used[$name] = true;
            }
        }
        $css = '';
        foreach (array_keys($used) as $name) {
            $files = $families[$name];
            foreach ($files as $file) {
                $css .= '@font-face{font-family:"' . $name . '";font-style:' . $file['style'] . ';font-weight:' . $file['weight']
                    . ';font-display:swap;src:url("' . self::url($file, $baseUrl) . '") format("' . self::cssFormat($file['file']) . '")'
                    . ($file['range'] !== '' ? ';unicode-range:' . $file['range'] : '') . '}';
            }
            $fallback = self::fallbackEntry($files);
            if ($fallback !== null) {
                [$size, $ascent, $descent, $gap] = $fallback['adjust'];
                $css .= '@font-face{font-family:"' . $name . ' Fallback";src:local("Arial"),local("Helvetica"),local("Liberation Sans");size-adjust:'
                    . self::percent($size) . ';ascent-override:' . self::percent($ascent) . ';descent-override:' . self::percent($descent)
                    . ';line-gap-override:' . self::percent($gap) . '}';
            }
        }
        return $css;
    }

    /**
     * The preload tags for the files the page needs first: for headings and for text, the upright file of the weight the theme uses
     * (the same file when it is a variable one), in the script of the page's language. Other weights wait until a page asks for them.
     *
     * @param array<string, mixed> $design
     */
    public static function preloads(array $design, string $baseUrl, string $language = ''): string
    {
        $families = self::families($design);
        $script = strtolower(substr($language, 0, 2)) === 'el' ? 'greek' : 'latin';
        $weights = ['heading' => self::headingWeight($design), 'body' => 400];
        $html = '';
        $seen = [];
        foreach ($weights as $role => $weight) {
            $name = self::roleFamily($design, $role);
            if ($name === null) {
                continue;
            }
            $file = self::preloadFile($families[$name], $weight, $script);
            if ($file === null) {
                continue;
            }
            $url = self::url($file, $baseUrl);
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $html .= '<link rel="preload" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" as="font" type="font/woff2" crossorigin>' . "\n";
            }
        }
        return $html;
    }

    /** @param list<array<string, mixed>> $files */
    private static function preloadFile(array $files, int $weight, string $script): ?array
    {
        $best = null;
        $rank = null;
        foreach ($files as $file) {
            if (self::extension($file['file']) !== 'woff2' || $file['style'] !== 'normal' || ($file['cover'] !== null && !in_array($script, $file['cover'], true))) {
                continue;
            }
            [$low, $high] = self::weightRange($file['weight']);
            $gap = $low <= $weight && $high >= $weight ? 0 : min(abs($low - $weight), abs($high - $weight));
            $key = [$gap, $high - $low];
            if ($rank === null || $key < $rank) {
                $rank = $key;
                $best = $file;
            }
        }
        return $best;
    }

    private static function headingWeight(array $design): int
    {
        $weight = (string)($design['heading_weight'] ?? '');
        return in_array($weight, ['400', '500', '600', '700', '800'], true) ? (int)$weight : self::HEADING_WEIGHT;
    }

    /**
     * What to tell the person about the families they chose: a family without Greek letters on a site that has Greek content, and a
     * family whose coverage is not known. Each is a role ('heading' / 'body') and a message.
     *
     * @param array<string, mixed> $design
     * @return list<array{role: string, level: string, message: string}>
     */
    public static function warnings(array $design, bool $greekSite): array
    {
        $families = self::families($design);
        $labels = ['heading' => 'Headings', 'body' => 'Text'];
        $out = [];
        foreach ($labels as $role => $label) {
            $name = self::roleFamily($design, $role);
            if ($name === null || !$greekSite) {
                continue;
            }
            $known = array_filter(array_column($families[$name], 'cover'), static fn(mixed $cover): bool => $cover !== null);
            $greek = array_filter($known, static fn(array $cover): bool => in_array('greek', $cover, true));
            if ($greek !== []) {
                continue;
            }
            if (count($known) === count($families[$name])) {
                $out[] = ['role' => $role, 'level' => 'warning', 'message' => $label . ' use “' . $name . '”, which has no Greek letters. Greek text on this site will be drawn in a system font instead.'];
            } else {
                $out[] = ['role' => $role, 'level' => 'notice', 'message' => 'It is not known whether “' . $name . '” has Greek letters (' . $label . '). Check the chips of its files below.'];
            }
        }
        return $out;
    }

    // ---- uploading and reading files

    /**
     * Takes an uploaded font into custom/assets/fonts/<family>/ and says what it is. Nothing is added to the settings: the person
     * saves the Branding form for that, so an upload that is abandoned changes no page.
     *
     * @param array<string, mixed> $upload one entry of $_FILES
     * @return array{ok: bool, error?: string, entry?: array<string, mixed>, info?: array<string, mixed>, warnings?: list<string>}
     */
    public static function receive(array $upload, string $customPath, string $family = ''): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'The file is larger than the server accepts.' : 'The file could not be uploaded.'];
        }
        $tmp = (string)($upload['tmp_name'] ?? '');
        $name = (string)($upload['name'] ?? '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::FORMATS[$extension])) {
            return ['ok' => false, 'error' => 'Use a WOFF2 file (best), or WOFF, TTF or OTF.'];
        }
        if (!is_file($tmp)) {
            return ['ok' => false, 'error' => 'The file could not be uploaded.'];
        }
        $size = (int)filesize($tmp);
        if ($size < 64 || $size > FontFile::MAX_BYTES) {
            return ['ok' => false, 'error' => 'A font file can be up to ' . (int)(FontFile::MAX_BYTES / 1048576) . ' MB.'];
        }
        $bytes = (string)file_get_contents($tmp);
        $info = FontFile::parse($bytes);
        if ($info === null) {
            return ['ok' => false, 'error' => 'This is not a font file.'];
        }
        $format = $info['format'];
        // The extension follows what the file is, so a font is never served under the wrong type.
        $stored = $format === 'woff2' || $format === 'woff' ? $format : ($format === 'otf' ? 'otf' : 'ttf');
        $base = self::slug(pathinfo($name, PATHINFO_FILENAME), 'font');
        $name = self::cleanName($family) ?: self::cleanName($info['family']) ?: self::cleanName(ucwords(str_replace(['-', '_'], ' ', pathinfo($name, PATHINFO_FILENAME))));
        $hash = substr(sha1($bytes), 0, 8);
        $relative = self::DIRECTORY . '/' . self::slug($name, 'font-' . substr(md5($name), 0, 6)) . '/' . $base . '-' . $hash . '.' . $stored;
        $target = rtrim($customPath, '/') . '/assets/' . $relative;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            return ['ok' => false, 'error' => 'The folder custom/assets/fonts could not be created. Check that custom/ is writable.'];
        }
        if (!is_file($target) && @file_put_contents($target, $bytes, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'The font could not be saved. Check that custom/ is writable.'];
        }
        $entry = self::entryFor($info, $name, $relative, $hash, $upload['name'] ?? '');
        $warnings = [];
        if ($stored !== 'woff2') {
            $warnings[] = 'This is a ' . strtoupper($stored) . ' file. WOFF2 is much smaller to download; convert it (for example with Google’s woff2 tool or a font service) when you can.';
        }
        if (!$info['readable']) {
            $warnings[] = 'Cannot check this file on this server (a WOFF2 needs the PHP brotli extension). Your browser checks it, or tick what it covers; a TTF, OTF or WOFF of the same font can be checked here.';
        }
        return ['ok' => true, 'entry' => $entry, 'info' => self::describeInfo($info, $size), 'warnings' => $warnings];
    }

    /**
     * The entry for a font file that is already in custom/assets, for the file of the earlier single-font setting.
     *
     * @return array{ok: bool, error?: string, entry?: array<string, mixed>, info?: array<string, mixed>, warnings?: list<string>}
     */
    public static function describeExisting(string $customPath, string $path, string $family): array
    {
        $path = trim($path);
        $path = preg_replace('#^/?_custom/#', '', $path) ?? $path;
        $relative = self::cleanFile($path);
        $file = rtrim($customPath, '/') . '/assets/' . $relative;
        if ($relative === '' || !is_file($file)) {
            return ['ok' => false, 'error' => 'That file is not in custom/assets.'];
        }
        $bytes = (string)file_get_contents($file, false, null, 0, FontFile::MAX_BYTES);
        $info = FontFile::parse($bytes);
        if ($info === null) {
            return ['ok' => false, 'error' => 'This is not a font file.'];
        }
        $entry = self::entryFor($info, self::cleanName($family) ?: 'My font', $relative, substr(sha1($bytes), 0, 8), basename($relative));
        // The old setting drew one file at every weight; keep it that way until the person says otherwise.
        if (!$info['variable']) {
            $entry['weight'] = '100 900';
        }
        return ['ok' => true, 'entry' => $entry, 'info' => self::describeInfo($info, strlen($bytes)), 'warnings' => []];
    }

    /** @return array<string, mixed> */
    private static function entryFor(array $info, string $family, string $relative, string $hash, string $original): array
    {
        $lower = strtolower((string)$original);
        $weight = $info['variable'] && $info['weight_max'] !== null ? $info['weight'] . ' ' . $info['weight_max'] : (string)self::weightFromName($lower, $info['weight']);
        $cover = $info['cover'];
        $adjust = FontFile::fallbackMetrics($info);
        return [
            'family' => $family,
            'file' => $relative,
            'weight' => self::cleanWeight($weight),
            'style' => $info['italic'] || preg_match('/italic|oblique|[-_ ]it(?:[-_. ]|$)/', $lower) === 1 ? 'italic' : 'normal',
            'range' => $info['ranges'] !== [] ? FontFile::unicodeRange($info['ranges']) : '',
            'cover' => $cover === null ? '' : ($cover === [] ? 'none' : implode(',', $cover)),
            'adjust' => $adjust === null ? '' : implode(',', $adjust),
            'v' => $hash,
        ];
    }

    /** The weight the file name states ("Lora-700-normal", "Roboto-SemiBold"), or $fallback when it states none. */
    private static function weightFromName(string $name, int $fallback): int
    {
        $name = strtolower(pathinfo($name, PATHINFO_FILENAME));
        foreach (preg_split('/[-_. ]+/', $name) ?: [] as $token) {
            if (preg_match('/^[1-9]00$/', $token) === 1) {
                return (int)$token;
            }
        }
        // Only the style part after the last dash is read, so a family called "Something" is not read as "thin".
        $style = preg_replace('/[^a-z]+/', '', (string)preg_replace('/^.*[-_]/', '', $name)) ?? '';
        if ($style === $name || $style === '') {
            return $fallback;
        }
        // The longer names first, so "semibold" is not read as "bold".
        $name = $style;
        foreach (['extralight', 'ultralight', 'extrabold', 'ultrabold', 'semibold', 'demibold', 'hairline', 'thin', 'light', 'medium', 'black', 'heavy', 'bold', 'regular', 'book', 'demi'] as $word) {
            if (str_contains($name, $word)) {
                return self::WEIGHTS[$word];
            }
        }
        return $fallback;
    }

    /** @return array<string, mixed> what the screen shows about a file */
    private static function describeInfo(array $info, int $size): array
    {
        return ['format' => $info['format'], 'readable' => $info['readable'], 'variable' => $info['variable'], 'size' => $size, 'font_family' => $info['family'], 'font_style' => $info['subfamily']];
    }

    // ---- the Branding screen

    /**
     * What the Branding screen needs about the families: every entry with its address and whether the file is there, and the languages.
     *
     * @param array<string, mixed> $design
     * @return array<string, mixed>
     */
    public static function screen(array $design, string $customPath, string $baseUrl, bool $greekSite): array
    {
        $files = [];
        foreach (self::entries($design) as $entry) {
            $path = rtrim($customPath, '/') . '/assets/' . $entry['file'];
            $files[] = [
                'family' => $entry['family'], 'file' => $entry['file'], 'weight' => $entry['weight'], 'style' => $entry['style'],
                'range' => $entry['range'], 'cover' => $entry['cover'] === null ? '' : ($entry['cover'] === [] ? 'none' : implode(',', $entry['cover'])),
                'adjust' => $entry['adjust'] === null ? '' : implode(',', $entry['adjust']), 'v' => $entry['v'],
                'url' => self::url($entry, $baseUrl), 'exists' => is_file($path), 'size' => is_file($path) ? (int)filesize($path) : 0,
                'format' => self::extension($entry['file']),
            ];
        }
        return [
            'files' => $files,
            'heading_family' => self::cleanName((string)($design['heading_family'] ?? '')),
            'body_family' => self::cleanName((string)($design['body_family'] ?? '')),
            'base' => rtrim($baseUrl, '/'),
            'greek_site' => $greekSite,
            'groups' => array_map(static fn(array $group): string => $group[0], FontFile::GROUPS),
        ];
    }

    // ---- cleaning

    public static function cleanName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N} _.&()-]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = mb_substr($name, 0, 40);
        return in_array(mb_strtolower($name), self::RESERVED, true) ? $name . ' Custom' : $name;
    }

    /** A path under custom/assets, or '' when it is not one a font can have. */
    public static function cleanFile(string $file): string
    {
        $file = ltrim(trim($file), '/');
        if ($file === '' || strlen($file) > 200 || str_contains($file, '..') || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $file) !== 1) {
            return '';
        }
        return isset(self::FORMATS[self::extension($file)]) ? $file : '';
    }

    /** "400", or "300 700" for a variable font; '400' when it is neither. */
    public static function cleanWeight(string $weight): string
    {
        if (preg_match('/^\s*(\d{1,4})(?:\s*[ -]\s*(\d{1,4}))?\s*$/', $weight, $m) !== 1) {
            return '400';
        }
        $low = max(1, min(1000, (int)$m[1]));
        $high = isset($m[2]) ? max(1, min(1000, (int)$m[2])) : $low;
        if ($high < $low) {
            [$low, $high] = [$high, $low];
        }
        return $low === $high ? (string)$low : $low . ' ' . $high;
    }

    /** @return array{0: int, 1: int} */
    private static function weightRange(string $weight): array
    {
        $parts = array_map('intval', explode(' ', $weight));
        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    private static function cleanRange(string $range): string
    {
        $range = trim($range);
        return preg_match('/^U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(,\s?U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?){0,63}$/', $range) === 1 ? str_replace(', ', ',', $range) : '';
    }

    /** @return list<string>|null the scripts the file covers; null when that is not known */
    private static function cleanCover(string $cover): ?array
    {
        $cover = trim($cover);
        if ($cover === '') {
            return null;
        }
        return array_values(array_filter(array_unique(explode(',', $cover)), static fn(string $key): bool => isset(FontFile::GROUPS[$key])));
    }

    /** @return list<float>|null size-adjust, ascent, descent and line gap in percent */
    private static function cleanAdjust(string $adjust): ?array
    {
        $parts = explode(',', trim($adjust));
        if (count($parts) !== 4) {
            return null;
        }
        $numbers = [];
        foreach ($parts as $i => $part) {
            if (!is_numeric($part)) {
                return null;
            }
            $value = (float)$part;
            if ($value < 0 || $value > 300 || ($i === 0 && $value < 50)) {
                return null;
            }
            $numbers[] = $value;
        }
        return $numbers;
    }

    // ---- small helpers

    /** @param array<string, mixed> $entry */
    private static function url(array $entry, string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/_custom/' . $entry['file'] . ($entry['v'] !== '' ? '?v=' . $entry['v'] : '');
    }

    private static function extension(string $file): string
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION));
    }

    private static function cssFormat(string $file): string
    {
        return match (self::extension($file)) {
            'ttf' => 'truetype',
            'otf' => 'opentype',
            default => self::extension($file),
        };
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
    }

    private static function slug(string $text, string $fallback): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)) ?? '', '-');
        return $slug === '' ? $fallback : substr($slug, 0, 60);
    }
}
