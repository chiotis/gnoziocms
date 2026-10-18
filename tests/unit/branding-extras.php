<?php
/*
 * Branding, part C: the type overrides (a step of the scale, weight, line height, letter spacing for H1, H2, H3, lead and small
 * text), the form styling, radius / border / shadow presets, the phone overrides, and export / import / undo / reset.
 *   php tests/unit/branding-extras.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{Branding, BrandingForms, BrandingShape, BrandingTransfer, BrandingTypography, SystemDatabase, SystemMetaRepository, Theme};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }
function has(string $h, string $n): bool { return str_contains($h, $n); }
function lum(string $hex): float { $c = array_map(fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))]); return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; }
function ratio(string $a, string $b): float { [$x, $y] = [lum($a), lum($b)]; return (max($x, $y) + 0.05) / (min($x, $y) + 0.05); }
function rmtree(string $d): void { foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (in_array(basename($f), ['.', '..'], true)) continue; is_dir($f) && !is_link($f) ? rmtree($f) : unlink($f); } @rmdir($d); }

$dir = sys_get_temp_dir() . '/faros-branding-' . getmypid();
mkdir($dir . '/custom/assets/fonts', 0775, true);
mkdir($dir . '/storage', 0775, true);
symlink($repo . '/themes', $dir . '/themes');
file_put_contents($dir . '/custom/assets/fonts/brand.woff2', 'FONTDATA');
$theme = new Theme($dir, 'default');
$defaults = $theme->defaultSettings();
$design = static fn(array $over): array => ['design' => array_replace($defaults['design'], $over)];

// ---- defaults: nothing new adds a byte
check('a site that sets none of the new choices gets no CSS', Branding::css($defaults), '');
check('the new fields all start on "theme" or empty', array_filter(array_intersect_key($defaults['design'], array_flip(['h1_step', 'h3_weight', 'lead_leading', 'small_tracking', 'body_weight', 'paragraph_space', 'input_bg', 'input_border', 'input_border_width', 'label_size', 'label_weight', 'input_ring', 'error_style', 'radius_card', 'radius_image', 'radius_input', 'border_width', 'mobile_gutter', 'mobile_section_space'])), fn($v) => $v !== 'auto' && $v !== ''), []);
check('and the type, form and shape classes add nothing for them', [BrandingTypography::css($defaults['design']), BrandingForms::css($defaults['design']), BrandingShape::css($defaults['design'])], ['', '', '']);

// ---- type overrides
$css = Branding::css($design(['h1_step' => '4', 'h1_weight' => '800', 'h1_leading' => 'tight', 'h1_tracking' => 'tighter']));
check('H1: a step of the scale, never pixels, with weight, line height and letter spacing, over the classes of blocks', has($css, ':root[data-theme][data-mode] h1,:root[data-theme][data-mode] .hero-title,:root[data-theme][data-mode] .place-title{font-size:var(--step-4);font-weight:800;line-height:1.05;letter-spacing:-0.045em}'), true);
check('and no pixel size anywhere', preg_match('/font-size:[0-9.]+px/', $css), 0);
check('H2, H3, lead and small each have their own rule', [
    has(Branding::css($design(['h2_step' => '3'])), 'h2,:root[data-theme][data-mode] .section-title'),
    has(Branding::css($design(['h3_weight' => '500'])), '[data-mode] h3{font-weight:500}'),
    has(Branding::css($design(['lead_step' => '2', 'lead_leading' => 'loose'])), '.lead,:root[data-theme][data-mode] .single-hero-subtitle,:root[data-theme][data-mode] .prose.is-lead,:root[data-theme][data-mode] .book-lead,:root[data-theme][data-mode] .place-lead{font-size:var(--step-2);line-height:1.6}'),
    has(Branding::css($design(['small_step' => '-1'])), 'small,:root[data-theme][data-mode] figcaption{font-size:var(--step--1)}'),
], [true, true, true, true]);
check('H4 to H6 have no override', has(Branding::css($design(['h1_step' => '4', 'h2_step' => '3', 'h3_step' => '2'])), 'h4'), false);
check('a step that is not one of the scale, or a weight that is not offered, adds nothing', Branding::css($design(['h1_step' => '9', 'h1_weight' => '1000', 'h2_leading' => 'wild', 'h3_tracking' => 'x'])), '');
check('body weight and paragraph spacing', [
    Branding::css($design(['body_weight' => '300'])),
    has(Branding::css($design(['paragraph_space' => 'loose'])), ':is(.prose,.prose-block) p:not(:last-child){margin-bottom:1.8em}'),
], ['body.theme-body{font-weight:300}', true]);
check('the overrides come after the scale and tokens, so the Heading scale still applies to the steps', strpos(Branding::css($design(['scale' => '1.25', 'h1_step' => '4'])), '--step-5') < strpos(Branding::css($design(['scale' => '1.25', 'h1_step' => '4'])), 'var(--step-4)'), true);

// ---- shadows: the old values are the same CSS as before the presets, the new ones are added
check('"none" is what it was', Branding::css($design(['shadows' => 'none'])), ':root[data-theme][data-mode]{--shadow-s:none;--shadow-m:none;}');
check('"strong" is what it was', Branding::css($design(['shadows' => 'strong'])), ':root[data-theme][data-mode]{--shadow-s:0 2px 6px rgb(15 23 42 / 0.16), 0 1px 2px rgb(15 23 42 / 0.12);--shadow-m:0 30px 60px -24px rgb(15 23 42 / 0.65);}');
check('"theme" (the old default) adds nothing', Branding::css($design(['shadows' => 'auto'])), '');
check('soft is the theme\'s own shadow written out; medium sits between soft and strong', [
    Branding::css($design(['shadows' => 'soft'])),
    has(Branding::css($design(['shadows' => 'medium'])), '--shadow-m:0 18px 40px -20px rgb(15 23 42 / 0.5)'),
], [':root[data-theme][data-mode]{--shadow-s:0 1px 2px rgb(15 23 42 / 0.06), 0 1px 1px rgb(15 23 42 / 0.04);--shadow-m:0 22px 44px -28px rgb(15 23 42 / 0.45);}', true]);
$site = file_get_contents($repo . '/themes/default/assets/css/site.css');
check('the soft preset equals the theme\'s default tokens', [has($site, '--shadow-s: ' . BrandingShape::SHADOWS['soft'][0] . ';'), has($site, '--shadow-m: ' . BrandingShape::SHADOWS['soft'][1] . ';')], [true, true]);
check('the schema offers none, soft, medium and strong (and still "theme")', array_keys($theme->settingsSchema()['design']['fields']['shadows']['options']), ['auto', 'none', 'soft', 'medium', 'strong']);

// ---- radius, border, phone
$css = Branding::css($design(['radius_card' => 24, 'radius_image' => 4, 'border_width' => 'medium']));
check('card corners and border apply to the cards, image corners to the images', [
    has($css, ':root[data-theme][data-mode] .sidebar-card'), has($css, '.plan-card,') && has($css, '{border-radius:1.5rem;border-width:2px}'),
    has($css, ':root[data-theme][data-mode] .prose img') && has($css, '{border-radius:0.25rem}'),
], [true, true, true]);
check('a radius of 0 is square', has(Branding::css($design(['radius_card' => 0])), '{border-radius:0px}'), true);
check('the general radius still works beside them', has(Branding::css($design(['radius' => 10, 'radius_card' => 24])), '--radius-m:0.625rem;'), true);
check('phone overrides sit in a media query, the section space as the scale the sections use', Branding::css($design(['mobile_gutter' => 14, 'mobile_section_space' => 29])), '@media(max-width:640px){:root[data-theme][data-mode]{--gutter:0.875rem;--section-scale:0.5}}');
check('without them nothing is added for phones', has(Branding::css($design(['gutter' => 60, 'section_space' => 80])), '@media'), false);

// ---- forms
$css = Branding::css($design(['input_bg' => 'surface', 'input_border' => 'text', 'input_border_width' => 'medium', 'label_size' => 'large', 'label_weight' => '700', 'radius_input' => 0]));
check('field background, border, width and corners go to the public forms and the search field', [
    has($css, ':root[data-theme][data-mode] .form-grid input:not([type="checkbox"],[type="radio"]),:root[data-theme][data-mode] .form-grid textarea,:root[data-theme][data-mode] .form-grid select,:root[data-theme][data-mode] .search-form input'),
    has($css, '{background:var(--color-surface);border-color:var(--color-text);border-width:2px;border-radius:0px}'),
], [true, true]);
check('label size and weight', has($css, '.form-grid label>span:first-child,:root[data-theme][data-mode] .form-grid legend{font-size:1.05rem;font-weight:700}'), true);
$forms = file_get_contents($repo . '/themes/default/assets/css/site.css');
check('the selectors really match the theme\'s form markup', [has($forms, '.form-grid input:not([type="checkbox"], [type="radio"]),'), has($forms, '.search-form input {'), has($forms, '.form-grid label > span:first-child')], [true, true, true]);
foreach (['outline' => '2px', 'high' => '3px'] as $preset => $width) {
    $css = BrandingForms::css(['input_ring' => $preset]);
    check("focus \"$preset\" is always a visible ring of at least 2px, not a soft shadow", [has($css, 'outline:' . $width . ' solid'), has($css, 'outline-offset:2px'), has($css, ':focus{'), has($css, 'box-shadow:none')], [true, true, true, true]);
}
check('"high contrast" focus uses the text colour, which the palette keeps readable', has(BrandingForms::css(['input_ring' => 'high']), 'outline:3px solid var(--color-text)'), true);
check('errors never rely on colour alone', [has(BrandingForms::css(['error_style' => 'strong']), 'border-width:2px'), has(BrandingForms::css(['error_style' => 'tinted']), 'border-left:3px solid var(--color-danger)')], [true, true]);
// the theme's own tokens: the error text, and the focus colour, on the page and on the tinted field, in both modes
foreach ([['light', '#b42318', '#ffffff', '#0e1726', '#1d4ed8'], ['dark', '#fda29b', '#0b1220', '#e8edf4', '#93c5fd']] as [$mode, $danger, $bg, $text, $accent]) {
    $tint = '#';
    for ($i = 0; $i < 3; $i++) { $tint .= str_pad(dechex((int)round(hexdec(substr($danger, 1 + 2 * $i, 2)) * 0.08 + hexdec(substr($bg, 1 + 2 * $i, 2)) * 0.92)), 2, '0', STR_PAD_LEFT); }
    check("$mode: error text on the tinted field keeps AA (4.5:1)", ratio($danger, $tint) >= 4.5, true);
    check("$mode: the high contrast ring (text colour) on the page keeps 3:1", ratio($text, $bg) >= 3.0, true);
}

// ---- export, then import on a clean site
$store = ['settings' => $theme->resolveSettings($design([
    'light_accent' => '#1d4ed8', 'dark_accent' => '#93c5fd', 'heading_font' => 'custom', 'font_file' => 'fonts/brand.woff2', 'h1_step' => '4', 'h1_weight' => '800',
    'input_border' => 'text', 'input_ring' => 'high', 'light_secondary' => '#aa3355', 'site_palettes' => '[{"id":"p1","name":"Mine"}]', 'duo_picker' => false, 'radius_card' => 22, 'shadows' => 'medium', 'mobile_gutter' => 14,
] ) + ['appearance' => ['palette' => 'rose', 'mode' => 'dark', 'shape' => 'sharp'] + $defaults['appearance']])];
$metaDb = new SystemDatabase($dir . '/storage');
$metaDb->initialize();
$meta = new SystemMetaRepository($metaDb);
$transfer = new BrandingTransfer($theme, function () use (&$store) { return $store['settings']; }, function (array $s) use (&$store) { $store['settings'] = $s; }, $meta, '0.0.0');
$zipPath = $dir . '/export.zip';
$files = $transfer->export($zipPath);
check('the export has the font file the settings point to', $files, ['assets/fonts/brand.woff2']);
$zip = new ZipArchive(); $zip->open($zipPath);
$doc = json_decode($zip->getFromName('branding.json'), true);
check('and the whole design and appearance tree, not a list of keys', [$doc['format'], array_keys($doc['settings']), count($doc['settings']['design']) === count($store['settings']['design']), $doc['settings']['appearance']['palette']], ['faroscms-branding', ['appearance', 'design'], true, 'rose']);
check('the font file is in the zip', $zip->getFromName('assets/fonts/brand.woff2'), 'FONTDATA');
$zip->close();

$clean = sys_get_temp_dir() . '/faros-branding-clean-' . getmypid();
mkdir($clean . '/custom', 0775, true); mkdir($clean . '/storage', 0775, true); symlink($repo . '/themes', $clean . '/themes');
$theme2 = new Theme($clean, 'default');
$store2 = ['settings' => $theme2->resolveSettings($theme2->defaultSettings())];
$db2 = new SystemDatabase($clean . '/storage');
$db2->initialize();
$meta2 = new SystemMetaRepository($db2);
$import = new BrandingTransfer($theme2, function () use (&$store2) { return $store2['settings']; }, function (array $s) use (&$store2) { $store2['settings'] = $s; }, $meta2, '0.0.0');
$read = $import->read((string)file_get_contents($zipPath));
check('the zip is read', [$read['ok'], array_keys($read['files'])], [true, ['fonts/brand.woff2']]);
$cleanCss = Branding::css($store2['settings']);
$preview = $import->preview($read);
$byKey = array_column($preview['changes'], null, 'key');
check('the preview lists what changes and changes nothing', [
    $byKey['h1_step']['from'], $byKey['h1_step']['to'], $byKey['shadows']['to'], $byKey['palette']['to'] ?? null, $preview['files'], $preview['skipped'], $store2['settings'] === $theme2->resolveSettings($theme2->defaultSettings()), is_file($clean . '/custom/assets/fonts/brand.woff2'),
], ['auto', '4', 'medium', 'rose', [['path' => 'fonts/brand.woff2', 'status' => 'new']], [], true, false]);
$result = $import->apply($read);
check('applying writes the settings and the font file', [$result['ok'], $result['files'], $result['changed'] > 5, file_get_contents($clean . '/custom/assets/fonts/brand.woff2')], [true, 1, true, 'FONTDATA']);
$importedPalette = GnozioCMS\SitePalettes::active($store2['settings']['design']);
check('what the other parts add (secondary colour, Duo) travels without being listed anywhere; the colour overrides of this old export became a site palette', [$store2['settings']['design']['light_secondary'], $importedPalette['name'] ?? null, $importedPalette['secondary']['light'] ?? null, $importedPalette['light']['accent'] ?? null, $store2['settings']['design']['light_accent'], $store2['settings']['design']['duo_picker']], ['', 'Rose (custom)', '#aa3355', '#1d4ed8', '', false]);
check('the CSS and the attributes of the clean site are now identical', [Branding::css($store2['settings'], ''), Branding::attributes($store2['settings'])], [Branding::css($store['settings'], ''), Branding::attributes($store['settings'])]);
check('and the CSS before was empty', $cleanCss, '');
check('importing again shows nothing to change', [$import->preview($import->read((string)file_get_contents($zipPath)))['changes'], $import->apply($import->read((string)file_get_contents($zipPath)))['changed']], [[], 0]);

// ---- undo
$info = $import->backup();
check('the previous settings were kept', [$info['reason'], $info['at'] !== ''], ['import', true]);
check('undo puts them back, and the backup is spent', [$import->undo(), Branding::css($store2['settings']), $import->backup(), $import->undo()], [true, '', null, false]);

// ---- hostile files
check('a file that is not a Branding export is refused', [$import->read('{"a":1}')['ok'], $import->read('PKnot a zip')['ok'], $import->read('')['ok']], [false, false, false]);
$evil = $dir . '/evil.zip'; $z = new ZipArchive(); $z->open($evil, ZipArchive::CREATE);
$z->addFromString('branding.json', json_encode(['format' => 'faroscms-branding', 'settings' => ['design' => ['h1_step' => '4', 'unknown_key' => 'x', 'light_accent' => 'javascript:evil'], 'appearance' => []]]));
$z->addFromString('assets/../../index.php', '<?php evil();'); $z->addFromString('assets/fonts/x.php', '<?php evil();'); $z->addFromString('assets/fonts/ok.woff', 'W');
$z->close();
$bad = $import->read((string)file_get_contents($evil));
check('only font files of safe names are taken from a zip', array_keys($bad['files']), ['fonts/ok.woff']);
$prev = $import->preview($bad);
check('keys the theme does not declare are skipped, and a bad value falls back to the default', [$prev['skipped'], array_column($prev['changes'], 'key')], [['design.unknown_key'], ['h1_step']]);

// ---- reset per section
$store2['settings'] = $theme2->resolveSettings($design(['h1_step' => '4', 'radius_card' => 20, 'input_bg' => 'surface', 'button_height' => 50, 'light_accent' => '#112233', 'mobile_gutter' => 12]));
$before = $store2['settings']['design'];
check('resetting Typography restores its defaults and only those', [$import->reset('type'), $store2['settings']['design']['h1_step'], $store2['settings']['design']['radius_card'], $store2['settings']['design']['light_accent']], [1, 'auto', 20, '#112233']);
check('Shape, Forms, Layout and Buttons each reset their own', [$import->reset('shape'), $import->reset('forms'), $import->reset('layout'), $import->reset('buttons'), $import->reset('colour')], [1, 1, 1, 1, 1]);
check('everything is back at the defaults', $store2['settings']['design'] === $defaults['design'], true);
check('a section that is not one is refused, and one already at its defaults changes nothing', [$import->reset('nope'), $import->reset('type')], [-1, 0]);
check('the reset can be undone', [$import->undo(), $import->reset('type')], [true, 0]);
$sections = []; foreach (array_keys($theme->settingsSchema()['design']['fields']) as $k) { $own = array_filter(array_keys(BrandingTransfer::SECTIONS), fn($s) => in_array($k, $transfer->sectionKeys($s), true)); $sections[$k] = count($own); }
check('no setting belongs to two sections', array_keys(array_filter($sections, fn($n) => $n > 1)), []);
// Settings that no section owns (a later part may add some) are only not offered a section reset; export and import carry them anyway.
echo 'note settings without a section reset: ' . implode(', ', array_keys(array_filter($sections, fn($n) => $n === 0))) . "\n";

rmtree($dir); rmtree($clean);
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
