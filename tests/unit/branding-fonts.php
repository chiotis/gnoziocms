<?php
/*
 * Custom font families of Theme > Branding: reading a font (names, weight, style, scripts it covers), taking an upload in, the
 * CSS and preload tags of a family, the single font file of earlier versions, and the warning for a family without Greek.
 * The fonts are built here, byte by byte, so the checks need no font files: one with Latin only, one with Latin, Greek and symbols.
 *   php tests/unit/branding-fonts.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{Branding, BrandingFonts, FontFile};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }
function has(string $haystack, string $needle): bool { return str_contains($haystack, $needle); }

// ---- a font made of bytes: code point ranges, names, weight, italic, optional weight axis
function u16(int $v): string { return pack('n', $v & 0xFFFF); }
function u32(int $v): string { return pack('N', $v); }
function makeFont(array $ranges, string $family = 'Test Sans', string $style = 'Regular', int $weight = 400, bool $italic = false, ?array $axis = null, bool $format12 = false, string $magic = "\x00\x01\x00\x00"): string
{
    $units = 1000;
    $glyphs = [];
    $groups = [];
    $next = 1;
    foreach ($ranges as [$a, $b]) {
        $groups[] = [$a, $b, $next];
        for ($c = $a; $c <= $b; $c++) { $glyphs[$c] = $next++; }
    }
    if ($format12) {
        $sub = u16(12) . u16(0) . u32(16 + 12 * count($groups)) . u32(0) . u32(count($groups));
        foreach ($groups as [$a, $b, $g]) { $sub .= u32($a) . u32($b) . u32($g); }
        $records = u16(0) . u16(4) . u32(12);
    } else {
        $segments = [];
        foreach ($groups as [$a, $b, $g]) { if ($b < 0xFFFF) { $segments[] = [$a, $b, ($g - $a) & 0xFFFF]; } }
        $segments[] = [0xFFFF, 0xFFFF, 1];
        $n = count($segments);
        $sub = u16(4) . u16(16 + 8 * $n) . u16(0) . u16($n * 2) . u16(0) . u16(0) . u16(0);
        foreach ($segments as $s) { $sub .= u16($s[1]); }
        $sub .= u16(0);
        foreach ($segments as $s) { $sub .= u16($s[0]); }
        foreach ($segments as $s) { $sub .= u16($s[2]); }
        foreach ($segments as $s) { $sub .= u16(0); }
        $records = u16(3) . u16(1) . u32(12);
    }
    $cmap = u16(0) . u16(1) . $records . $sub;
    $head = str_repeat("\0", 18) . u16($units) . str_repeat("\0", 24) . u16($italic ? 2 : 0) . str_repeat("\0", 8);
    $hhea = u32(0x00010000) . pack('n', 800) . pack('n', (-200) & 0xFFFF) . u16(0) . str_repeat("\0", 24) . u16($next);
    $hmtx = '';
    for ($i = 0; $i < $next; $i++) { $hmtx .= u16(556) . u16(0); }
    $os2 = u16(4) . u16(556) . u16($weight) . str_repeat("\0", 56) . u16($italic ? 1 : 0) . str_repeat("\0", 14);
    $strings = [[1, $family], [2, $style]];
    $name = u16(0) . u16(count($strings)) . u16(6 + 12 * count($strings));
    $store = '';
    foreach ($strings as [$id, $text]) {
        $utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        $name .= u16(3) . u16(1) . u16(0x409) . u16($id) . u16(strlen($utf16)) . u16(strlen($store));
        $store .= $utf16;
    }
    $name .= $store;
    $tables = ['OS/2' => $os2, 'cmap' => $cmap, 'head' => $head, 'hhea' => $hhea, 'hmtx' => $hmtx, 'name' => $name];
    if ($axis !== null) {
        $fvar = u16(1) . u16(0) . u16(16) . u16(2) . u16(1) . u16(20) . u16(0) . u16(0)
            . 'wght' . u32($axis[0] * 65536) . u32($axis[1] * 65536) . u32($axis[2] * 65536) . u16(0) . u16(256);
        $tables['fvar'] = $fvar;
    }
    ksort($tables);
    $directory = '';
    $body = '';
    $offset = 12 + 16 * count($tables);
    foreach ($tables as $tag => $data) {
        $directory .= $tag . u32(0) . u32($offset + strlen($body)) . u32(strlen($data));
        $body .= $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
    }
    return $magic . u16(count($tables)) . u16(0) . u16(0) . u16(0) . $directory . $body;
}
function wrapWoff(string $ttf): string
{
    $count = unpack('n', substr($ttf, 4, 2))[1];
    $directory = '';
    $body = '';
    $offset = 44 + 20 * $count;
    for ($i = 0; $i < $count; $i++) {
        $entry = substr($ttf, 12 + $i * 16, 16);
        $data = substr($ttf, unpack('N', substr($entry, 8, 4))[1], unpack('N', substr($entry, 12, 4))[1]);
        $zipped = gzcompress($data, 9);
        $stored = strlen($zipped) < strlen($data) ? $zipped : $data;
        $directory .= substr($entry, 0, 4) . u32($offset + strlen($body)) . u32(strlen($stored)) . u32(strlen($data)) . u32(0);
        $body .= $stored . str_repeat("\0", (4 - strlen($stored) % 4) % 4);
    }
    return 'wOFF' . "\x00\x01\x00\x00" . u32(44 + 20 * $count + strlen($body)) . u16($count) . u16(0) . u32(0) . u16(1) . u16(0) . str_repeat("\0", 20) . $directory . $body;
}

$latin = [[0x20, 0x7E], [0xA0, 0xFF]];
$greek = [[0x20, 0x7E], [0xA0, 0xFF], [0x391, 0x3A1], [0x3A3, 0x3A9], [0x3B1, 0x3C9], [0x20AC, 0x20AC], [0x2022, 0x2022], [0x2192, 0x2192], [0xA9, 0xA9]];

// ---- reading a font
$info = FontFile::parse(makeFont($latin, 'Test Sans', 'Bold', 700));
check('a Latin-only font: name, weight, upright, units, readable', [$info['format'], $info['family'], $info['subfamily'], $info['weight'], $info['italic'], $info['units'], $info['readable']], ['ttf', 'Test Sans', 'Bold', 700, false, 1000, true]);
check('and its coverage is Latin only', $info['cover'], ['latin']);
$info = FontFile::parse(makeFont($greek, 'Test Serif', 'Italic', 400, true));
check('a Greek font (cmap format 4) covers Latin, Greek and symbols, not Greek Extended or Cyrillic', [$info['cover'], $info['italic']], [['latin', 'greek', 'symbols'], true]);
$info12 = FontFile::parse(makeFont($greek, 'Test Serif', 'Italic', 400, true, null, true));
check('the same font with cmap format 12 reads the same', $info12['cover'], $info['cover']);
$ext = FontFile::parse(makeFont([[0x20, 0x7E], [0x1F00, 0x1FFF], [0x410, 0x44F]], 'Ext', 'Regular', 400, false, null, true));
check('Greek Extended and Cyrillic are told apart from Greek', $ext['cover'], ['latin', 'greek_ext', 'cyrillic']);
check('a font with a few Greek letters only does not count as Greek', FontFile::parse(makeFont([[0x20, 0x7E], [0x3B1, 0x3B5]]))['cover'], ['latin']);
$variable = FontFile::parse(makeFont($latin, 'Var', 'Regular', 400, false, [100, 400, 900]));
check('a variable font reports its weight range', [$variable['variable'], $variable['weight'], $variable['weight_max']], [true, 100, 900]);
check('an ordinary font is not variable', $info['variable'], false);
$woff = FontFile::parse(wrapWoff(makeFont($greek, 'Wrapped', 'Regular')));
check('a WOFF is unzipped and read the same way', [$woff['format'], $woff['readable'], $woff['family'], $woff['cover']], ['woff', true, 'Wrapped', ['latin', 'greek', 'symbols']]);
check('an OpenType (OTTO) file is recognised', FontFile::parse(makeFont($latin, 'O', 'Regular', 400, false, null, false, 'OTTO'))['format'], 'otf');
check('bytes that are not a font give null', [FontFile::parse('hello, this is not a font file at all'), FontFile::parse(''), FontFile::parse('%PDF-1.7 ' . str_repeat('x', 200))], [null, null, null]);
$truncated = substr(makeFont($greek), 0, 140);
check('a cut-off font gives less, never an error', is_array(FontFile::parse($truncated)) || FontFile::parse($truncated) === null, true);
$inter = (string)file_get_contents($repo . '/themes/default/assets/fonts/inter/inter-greek.woff2');
$woff2 = FontFile::parse($inter);
check('a WOFF2 is recognised; it is read when the server can open Brotli, and says it is not otherwise', [$woff2['format'], $woff2['readable'] === function_exists('brotli_uncompress')], ['woff2', true]);
if (function_exists('brotli_uncompress')) {
    check('and Inter’s Greek subset is Greek', in_array('greek', $woff2['cover'], true), true);
}
check('the unicode range is the font’s own code points, short', FontFile::unicodeRange([[0x20, 0x7E], [0xA0, 0xFF], [0x3B1, 0x3C9]]), 'U+20-7E,U+A0-FF,U+3B1-3C9');
$many = [];
for ($i = 0; $i < 100; $i++) { $many[] = [0x100 + $i * 4, 0x101 + $i * 4]; }
check('a font with very many gaps gets at most 48 ranges', count(explode(',', FontFile::unicodeRange($many))), 48);
$metrics = FontFile::fallbackMetrics(FontFile::parse(makeFont($latin)));
check('the fallback is sized from the widths of a to z and the vertical metrics', $metrics !== null && $metrics[0] > 100 && $metrics[0] < 130 && $metrics[1] > 60 && $metrics[2] > 10, true);

// ---- an upload
$custom = sys_get_temp_dir() . '/faros-fonts-' . getmypid();
register_shutdown_function(static function () use ($custom) { exec('rm -rf ' . escapeshellarg($custom)); });
mkdir($custom, 0775, true);
$upload = static function (string $name, string $bytes, int $error = UPLOAD_ERR_OK) use ($custom): array {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $bytes);
    return ['name' => $name, 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($bytes)];
};
$semi = BrandingFonts::receive($upload('AcmeSans-SemiBold.ttf', makeFont($greek, 'Acme Sans', 'SemiBold', 600)), $custom);
check('an upload is stored under custom/assets/fonts/<family>/ with a hash in its name', [$semi['ok'], (bool)preg_match('#^fonts/acme-sans/acmesans-semibold-[a-f0-9]{8}\.ttf$#', $semi['entry']['file'] ?? '')], [true, true]);
check('and tagged with the family of the file, its weight (from the name), style and coverage', [$semi['entry']['family'], $semi['entry']['weight'], $semi['entry']['style'], $semi['entry']['cover']], ['Acme Sans', '600', 'normal', 'latin,greek,symbols']);
check('the file is on disk', is_file($custom . '/assets/' . $semi['entry']['file']), true);
check('a TTF is accepted with a hint to use WOFF2', has(implode(' ', $semi['warnings']), 'WOFF2 is much smaller'), true);
$bi = BrandingFonts::receive($upload('AcmeSans-BoldItalic.woff', wrapWoff(makeFont($greek, 'Acme Sans', 'Bold Italic', 700, true))), $custom, 'Acme Sans');
check('a WOFF named Bold Italic is 700 italic and keeps the family name given', [$bi['ok'], $bi['entry']['weight'], $bi['entry']['style'], $bi['entry']['family'], pathinfo($bi['entry']['file'], PATHINFO_EXTENSION)], [true, '700', 'italic', 'Acme Sans', 'woff']);
$num = BrandingFonts::receive($upload('lora-latin-300-normal.ttf', makeFont($latin, 'Lora', 'Regular', 400)), $custom);
check('a weight in the name as a number wins over the table', [$num['entry']['weight'], $num['entry']['family']], ['300', 'Lora']);
$osweight = BrandingFonts::receive($upload('Something.ttf', makeFont($latin, 'Something', 'Medium', 500)), $custom);
check('a family called Something is not read as "thin"; the table decides', $osweight['entry']['weight'], '500');
$var = BrandingFonts::receive($upload('Var.ttf', makeFont($latin, 'Var', 'Regular', 400, false, [200, 400, 800])), $custom);
check('a variable font is tagged with its range', $var['entry']['weight'], '200 800');
$woff2up = BrandingFonts::receive($upload('Inter-Greek.woff2', $inter), $custom);
check('a WOFF2 is accepted; its coverage is left for the browser when the server cannot read it', [$woff2up['ok'], $woff2up['entry']['cover'] === '' ? 'unknown' : 'known', $woff2up['entry']['cover'] === '' ? has(implode(' ', $woff2up['warnings']), 'browser') : true], [true, function_exists('brotli_uncompress') ? 'known' : 'unknown', true]);
check('something that is not a font is refused, and so is an unknown type and a failed upload',
    [BrandingFonts::receive($upload('x.woff2', str_repeat('A', 500)), $custom)['ok'], BrandingFonts::receive($upload('x.exe', makeFont($latin)), $custom)['ok'], BrandingFonts::receive($upload('x.ttf', '', UPLOAD_ERR_PARTIAL), $custom)['ok']],
    [false, false, false]);
check('a font renamed to .php or .svg is refused too', [BrandingFonts::receive($upload('x.php', makeFont($latin)), $custom)['ok'], BrandingFonts::receive($upload('x.svg', makeFont($latin)), $custom)['ok']], [false, false]);
check('the family name cannot carry CSS: quotes, braces and slashes are dropped', BrandingFonts::cleanName('Evil"; } body{display:none} /* Sans'), 'Evil bodydisplaynone Sans');
check('and "Inter" is not taken over', BrandingFonts::cleanName('inter'), 'inter Custom');

// ---- the settings, the CSS, the preload
$entry = static fn(array $e): array => $e + ['family' => 'Acme Sans', 'weight' => '400', 'style' => 'normal', 'range' => '', 'cover' => '', 'adjust' => '', 'v' => 'ab12cd34'];
$design = [
    'heading_font' => 'custom', 'body_font' => 'custom', 'heading_family' => 'Acme Display', 'body_family' => 'Acme Sans', 'heading_weight' => 'auto',
    'fonts' => [
        $entry(['file' => 'fonts/acme-sans/regular.woff2', 'weight' => '400', 'range' => 'U+20-7E,U+3B1-3C9', 'cover' => 'latin,greek', 'adjust' => '104.5,90.2,24.1,0']),
        $entry(['file' => 'fonts/acme-sans/italic.woff2', 'style' => 'italic', 'cover' => 'latin,greek']),
        $entry(['file' => 'fonts/acme-sans/bold.woff2', 'weight' => '700', 'cover' => 'latin,greek']),
        $entry(['family' => 'Acme Display', 'file' => 'fonts/acme-display/var.woff2', 'weight' => '200 900', 'cover' => 'latin', 'v' => 'ffee0011']),
        $entry(['family' => 'Unused', 'file' => 'fonts/unused/u.woff2']),
    ],
];
$css = Branding::css(['design' => $design], '/site');
check('a family chosen for text becomes --font-body with the family, its fallback and the system stack', has($css, '--font-body:"Acme Sans", "Acme Sans Fallback", var(--font-system);'), true);
check('and one chosen for headings becomes --font-heading', has($css, '--font-heading:"Acme Display", var(--font-system);') && has($css, '--heading-font-custom:var(--font-heading);'), true);
check('every file of a used family is a @font-face with its weight, style, swap and the address with its version', [
    has($css, '@font-face{font-family:"Acme Sans";font-style:normal;font-weight:400;font-display:swap;src:url("/site/_custom/fonts/acme-sans/regular.woff2?v=ab12cd34") format("woff2");unicode-range:U+20-7E,U+3B1-3C9}'),
    has($css, 'font-family:"Acme Sans";font-style:italic;font-weight:400;font-display:swap;src:url("/site/_custom/fonts/acme-sans/italic.woff2?v=ab12cd34")'),
    has($css, 'font-family:"Acme Display";font-style:normal;font-weight:200 900;font-display:swap;src:url("/site/_custom/fonts/acme-display/var.woff2?v=ffee0011")'),
], [true, true, true]);
check('a family nobody uses is not in the page', has($css, 'Unused'), false);
check('a system font is sized to stand in for a family whose measures are known, and not for one without', [
    has($css, '@font-face{font-family:"Acme Sans Fallback";src:local("Arial"),local("Helvetica"),local("Liberation Sans");size-adjust:104.5%;ascent-override:90.2%;descent-override:24.1%;line-gap-override:0%}'),
    has($css, 'Acme Display Fallback'),
], [true, false]);
check('the faces come before the variables, so the family is known when it is used', strpos($css, '@font-face') < strpos($css, ':root[data-theme][data-mode]{'), true);
$head = Branding::head(['design' => $design], '/site', 'en');
check('only the file of the weight used for text (400) and for headings (700 by default) is preloaded', [
    has($head, '<link rel="preload" href="/site/_custom/fonts/acme-sans/regular.woff2?v=ab12cd34" as="font" type="font/woff2" crossorigin>'),
    has($head, '/site/_custom/fonts/acme-display/var.woff2?v=ffee0011'),
    has($head, 'bold.woff2') || has($head, 'italic.woff2') || has($head, 'unused'),
], [true, true, false]);
check('a heading weight that is set decides which file of the heading family is preloaded', has(Branding::head(['design' => ['heading_weight' => '400'] + $design], '', 'en'), 'acme-display/var.woff2'), true);
$onlyBold = ['heading_font' => 'custom', 'heading_family' => 'B', 'heading_weight' => '800', 'fonts' => [
    $entry(['family' => 'B', 'file' => 'fonts/b/r.woff2', 'weight' => '400']), $entry(['family' => 'B', 'file' => 'fonts/b/eb.woff2', 'weight' => '800']), $entry(['family' => 'B', 'file' => 'fonts/b/i.woff2', 'weight' => '800', 'style' => 'italic'])]];
$h = Branding::head(['design' => $onlyBold], '', 'en');
check('with separate files, the one nearest the weight, upright, is preloaded', [has($h, 'eb.woff2'), has($h, 'r.woff2'), has($h, 'i.woff2')], [true, false, false]);
$greekPage = Branding::head(['design' => $design], '', 'el');
check('on a Greek page a family that has no Greek is not preloaded (the page draws that text in a fallback)', [has($greekPage, 'acme-sans/regular.woff2'), has($greekPage, 'acme-display')], [true, false]);
check('a file that is not woff2 is not preloaded, but is still a @font-face with its format', [
    has(Branding::head(['design' => ['body_font' => 'custom', 'body_family' => 'T', 'fonts' => [$entry(['family' => 'T', 'file' => 'fonts/t/t.ttf'])]]], ''), 'preload'),
    has(Branding::css(['design' => ['body_font' => 'custom', 'body_family' => 'T', 'fonts' => [$entry(['family' => 'T', 'file' => 'fonts/t/t.ttf'])]]]), 'format("truetype")'),
], [false, true]);
check('a role set to its own font, with a family that does not exist, adds nothing', Branding::css(['design' => ['body_font' => 'custom', 'body_family' => 'Nope', 'fonts' => $design['fonts']]]), '');
check('a role not set to its own font ignores the family it was given', Branding::css(['design' => ['body_font' => 'inter', 'body_family' => 'Acme Sans', 'fonts' => $design['fonts']]]), ':root[data-theme][data-mode]{--font-body:"Inter", var(--font-system);}');
check('the only family is used when "your own font" is chosen and nothing else is', has(Branding::css(['design' => ['heading_font' => 'custom', 'fonts' => [$entry(['file' => 'fonts/a/a.woff2'])]]]), '--font-heading:"Acme Sans", var(--font-system);'), true);
$hostile = Branding::css(['design' => ['body_font' => 'custom', 'body_family' => 'X', 'fonts' => [
    $entry(['family' => 'X', 'file' => '../../etc/passwd.woff2']), $entry(['family' => 'X', 'file' => 'fonts/x/a.php']), $entry(['family' => 'X', 'file' => 'fonts/x/ok.woff2', 'range' => 'U+0;} body{x:y', 'v' => 'zz"}', 'adjust' => '1;2', 'weight' => '400;}'])]]]);
check('a path out of the folder, a file that is not a font and values with CSS in them never reach the style sheet', [has($hostile, 'passwd'), has($hostile, '.php'), has($hostile, 'body{x'), has($hostile, 'zz'), has($hostile, 'unicode-range'), has($hostile, 'font-weight:400;'), has($hostile, 'ok.woff2"')], [false, false, false, false, false, true, true]);

// ---- sites that do not touch the new settings, and the single file of earlier versions
$old = ['design' => ['heading_font' => 'custom', 'body_font' => 'inter', 'font_file' => 'fonts/brand.woff2', 'heading_weight' => '700']];
$expected = '@font-face{font-family:"Brand Font";font-style:normal;font-weight:100 900;font-display:swap;src:url("/_custom/fonts/brand.woff2") format("woff2")}'
    . ':root[data-theme][data-mode]{--font-heading:"Brand Font", var(--font-system);--heading-font-custom:var(--font-heading);--font-body:"Inter", var(--font-system);--heading-weight:700;--heading-weight-custom:700;}';
check('the single font file still gives exactly the CSS it always gave (byte for byte)', Branding::css($old), $expected);
check('and exactly its preload', Branding::head($old), "<link rel=\"preload\" href=\"/_custom/fonts/brand.woff2\" as=\"font\" type=\"font/woff2\" crossorigin>\n");
check('even with the new settings present but empty', [Branding::css(['design' => $old['design'] + ['fonts' => [], 'heading_family' => '', 'body_family' => '']]), Branding::head(['design' => $old['design'] + ['fonts' => [], 'heading_family' => '', 'body_family' => '']])], [$expected, Branding::head($old)]);
check('a role with a family and a role with the old file can live together', has(Branding::css(['design' => ['heading_font' => 'custom', 'heading_family' => 'Acme Sans', 'body_font' => 'custom', 'font_file' => 'fonts/brand.woff2', 'fonts' => $design['fonts']]]), 'font-family:"Brand Font"') , true);
check('a legacy file is not loaded when both roles use families', has(Branding::css(['design' => $design + ['font_file' => 'fonts/brand.woff2']]), 'Brand Font'), false);
check('the defaults of the theme give nothing', [Branding::css((new GnozioCMS\Theme($repo, 'default'))->defaultSettings()), Branding::head((new GnozioCMS\Theme($repo, 'default'))->defaultSettings())], ['', '']);

// ---- the old file becomes a family
is_dir($custom . '/assets/fonts') || mkdir($custom . '/assets/fonts', 0775, true);
file_put_contents($custom . '/assets/fonts/brand.ttf', makeFont($greek, 'Brand', 'Regular', 400));
$converted = BrandingFonts::describeExisting($custom, '/_custom/fonts/brand.ttf', 'My font');
check('the old file can be turned into a family entry, drawn at all weights as before', [$converted['ok'], $converted['entry']['family'], $converted['entry']['file'], $converted['entry']['weight']], [true, 'My font', 'fonts/brand.ttf', '100 900']);
check('and a missing file or a path out of the folder is refused', [BrandingFonts::describeExisting($custom, 'fonts/none.woff2', 'x')['ok'], BrandingFonts::describeExisting($custom, '../x.woff2', 'x')['ok']], [false, false]);

// ---- the screen and the warnings
$screen = BrandingFonts::screen($design, $custom, '/site', true);
check('the screen lists every file with its address, whether it is there and what it covers', [count($screen['files']), $screen['files'][0]['url'], $screen['files'][0]['exists'], $screen['files'][0]['cover']], [5, '/site/_custom/fonts/acme-sans/regular.woff2?v=ab12cd34', false, 'latin,greek']);
$warnings = BrandingFonts::warnings($design, true);
check('a site with Greek content is warned about a family with no Greek (headings here), not about one that has it', [count($warnings), $warnings[0]['role'], $warnings[0]['level'], has($warnings[0]['message'], 'no Greek letters')], [1, 'heading', 'warning', true]);
check('no warning on a site without Greek content', BrandingFonts::warnings($design, false), []);
$unknown = ['body_font' => 'custom', 'body_family' => 'T', 'fonts' => [$entry(['family' => 'T', 'file' => 'fonts/t/t.woff2'])]];
check('a family whose coverage is not known gets a softer notice', array_column(BrandingFonts::warnings($unknown, true), 'level'), ['notice']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
