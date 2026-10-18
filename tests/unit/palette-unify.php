<?php
/*
 * Theme > Branding > Colour: the palette is the one place where colours are set. The per-role overrides of earlier versions are
 * folded into a site palette without changing a byte of the CSS, ready-made palettes are read-only (Customise copies one),
 * "+ Custom" starts from two colours, a Duo pair or a copy, Fix finds the nearest shade that reads at AA, and old exports import.
 *   php tests/unit/palette-unify.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{Branding, BrandingTransfer, BuiltinPalettes, Contrast, DuoPairings, Oklch, PaletteAdmin, SitePalettes, SystemDatabase, SystemMetaRepository, Theme};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_SLASHES)) . "\n"; }

$theme = new Theme($repo, 'default');
$defaults = $theme->defaultSettings();
$settings = static function (array $design = [], string $palette = 'slate') use ($theme, $defaults): array {
    $s = $defaults;
    $s['design'] = $design + $s['design'];
    $s['appearance']['palette'] = $palette;
    return $theme->resolveSettings($s);
};
$render = static fn(array $s): array => [Branding::css($s, ''), Branding::attributes($s), Branding::head($s, '')];
$overrides = [
    'light_accent' => '#0e7490', 'light_background' => '#fbfdff', 'light_text' => '#10202b', 'dark_accent' => '#67e8f9', 'dark_surface' => '#14222e', 'dark_border' => '#2a3d4d',
    'light_secondary' => '#b45309', 'dark_secondary' => '#fcd34d', 'ink' => '#0d1a24',
];

// ---- the ready-made palettes are the style sheet's
$css = (string)file_get_contents($repo . '/themes/default/assets/css/site.css');
$block = static function (string $selector) use ($css): string {
    preg_match('/' . preg_quote($selector, '/') . '\s*\{(.*?)\n\}/s', $css, $m);
    return $m[1] ?? '';
};
$var = static function (string $body, string $name): ?string { return preg_match('/' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6})/i', $body, $m) ? strtolower($m[1]) : null; };
$same = true;
foreach (BuiltinPalettes::all() as $id => $p) {
    $own = $block(':root[data-theme="' . $id . '"]');
    $base = $block(':root');
    $pick = static fn(string $name) => $var($own, $name) ?? $var($base, $name);
    $want = ['light' => ['accent' => $pick('--accent-l'), 'background' => $pick('--n-bg'), 'surface' => $pick('--n-surface'), 'text' => $pick('--n-text'), 'muted' => $pick('--n-muted'), 'border' => $pick('--n-border')],
        'dark' => ['accent' => $pick('--accent-d'), 'background' => $pick('--d-bg'), 'surface' => $pick('--d-surface'), 'text' => $pick('--d-text'), 'muted' => $pick('--d-muted'), 'border' => $pick('--d-border')], 'ink' => $pick('--ink')];
    if ($want['light'] !== $p['light'] || $want['dark'] !== $p['dark'] || $want['ink'] !== $p['ink']) {
        $same = false;
        echo "  differs: $id\n";
    }
}
check('the seven ready-made palettes are exactly the colours of the theme style sheet', [$same, array_keys(BuiltinPalettes::all())], [true, ['slate', 'indigo', 'emerald', 'teal', 'rose', 'amber', 'garnet']]);
check('all of them are the options of the theme', array_keys($theme->settingsSchema()['appearance']['fields']['palette']['options']), array_keys(BuiltinPalettes::all()));

// ---- migration: the overrides on a ready-made palette
$onBuiltin = $settings($overrides, 'teal');
$before = $render($onBuiltin);
$folded = SitePalettes::fold($onBuiltin);
$after = $render(SitePalettes::followSource($folded));
check('overrides on a ready-made palette: the CSS, the attributes and the head are byte for byte the same', $after, $before);
$palette = SitePalettes::active($folded['design']);
check('and they are a site palette "Teal (custom)" on top of Teal, which is now in use', [$palette['name'] ?? null, $palette['source'] ?? null, $palette['light']['accent'] ?? null, $palette['dark']['border'] ?? null, $palette['secondary'] ?? null, $palette['ink'] ?? null, $folded['design']['site_palette']], ['Teal (custom)', 'teal', '#0e7490', '#2a3d4d', ['light' => '#b45309', 'dark' => '#fcd34d'], '#0d1a24', 'teal-custom']);
$left = array_filter($folded['design'], static fn($v, $k): bool => (bool)preg_match('/^(light|dark)_|^ink$/', $k) && $v !== '', ARRAY_FILTER_USE_BOTH);
check('the overrides are gone from the settings, and the ready-made palette is unchanged', [$left, $folded['appearance']['palette']], [[], 'teal']);
check('running it again changes nothing (idempotent)', SitePalettes::fold($folded) === $folded, true);
check('a ready-made palette is checked through the attributes of the page', $after[1]['data-theme'], 'teal');

// ---- migration: the overrides on a site palette
$mine = ['id' => 'wemetrix', 'name' => 'Wemetrix', 'light' => ['accent' => '#1d4ed8', 'background' => '#ffffff', 'text' => '#0f172a'], 'dark' => ['accent' => '#93c5fd', 'background' => '#0b1220'], 'from' => ['accent' => '#1d4ed8', 'base' => '#ffffff', 'role' => 'background']];
$onSite = $settings(['site_palettes' => SitePalettes::encode([$mine]), 'site_palette' => 'wemetrix', 'light_accent' => '#0e7490', 'dark_border' => '#2a3d4d', 'dark_secondary' => '#fcd34d'], 'indigo');
$before = $render($onSite);
$folded = SitePalettes::fold($onSite);
check('overrides on a site palette: the CSS is byte for byte the same', $render(SitePalettes::followSource($folded)), $before);
$palette = SitePalettes::active($folded['design']);
check('they are folded into that palette (an override wins over its colour); no new palette is made', [count(SitePalettes::parse($folded['design']['site_palettes'])), $palette['id'], $palette['light']['accent'], $palette['light']['text'], $palette['dark']['border'], $palette['secondary'], isset($palette['from']), $folded['design']['light_accent']], [1, 'wemetrix', '#0e7490', '#0f172a', '#2a3d4d', ['dark' => '#fcd34d'], true, '']);
check('and it is idempotent', SitePalettes::fold($folded) === $folded, true);

// ---- migration: nothing to fold
$plain = $settings([], 'rose');
check('a site without overrides is not touched', SitePalettes::fold($plain) === $plain, true);
$withPalette = $settings(['site_palettes' => SitePalettes::encode([$mine]), 'site_palette' => 'wemetrix']);
check('neither is a site with a site palette and no overrides', [SitePalettes::fold($withPalette) === $withPalette, $render(SitePalettes::followSource($withPalette)) === $render($withPalette)], [true, true]);
check('a site with neither renders as it did: nothing', Branding::css($plain), '');

// ---- migration: many palettes already; a full list is left alone rather than losing a colour
$full = [];
for ($i = 1; $i <= SitePalettes::MAX; $i++) { $full[] = ['id' => 'p' . $i, 'name' => 'P' . $i, 'light' => ['accent' => '#112233']]; }
$crowded = $settings($overrides + ['site_palettes' => SitePalettes::encode($full)], 'amber');
check('with 24 palettes already and none in use the overrides stay as they are (and still render)', [SitePalettes::fold($crowded) === $crowded, $render($crowded)[0] !== ''], [true, true]);

// ---- ready-made palettes are read-only; Customise copies
$copy = SitePalettes::copyOfBuiltin('teal', []);
check('Customise makes "Teal (custom)", with the colours of Teal and Teal as its source', [$copy['name'], $copy['id'], $copy['source'], $copy['light']['accent'], $copy['dark']['accent'], $copy['light']['background'], $copy['ink']], ['Teal (custom)', 'teal-custom', 'teal', '#0f766e', '#5eead4', '#ffffff', '#0b1220']);
$copy['light']['accent'] = '#000000';
check('changing the copy leaves the ready-made palette as it was', BuiltinPalettes::get('teal')['light']['accent'], '#0f766e');
check('a second copy gets another id and the same name pattern', SitePalettes::copyOfBuiltin('teal', [$copy])['id'], 'teal-custom-2');
check('only a known ready-made palette can be copied', [SitePalettes::copyOfBuiltin('nope', []), PaletteAdmin::copy(['builtin' => 'nope'])['ok'], PaletteAdmin::copy(['builtin' => 'garnet', 'taken' => ['garnet-custom']])['palette']['id']], [null, false, 'garnet-custom-2']);
$palettes = SitePalettes::save([], PaletteAdmin::copy(['builtin' => 'emerald'])['palette']);
$custom = $settings(['site_palettes' => SitePalettes::encode($palettes), 'site_palette' => 'emerald-custom'], 'emerald');
check('a ready-made palette cannot be chosen as a site palette, or edited through the list', [SitePalettes::active($settings(['site_palette' => 'teal']))], [null]);
check('the copy in use paints the colours of the palette (the accent of Emerald)', str_contains(Branding::css($custom), '--accent-l:#047857;') && str_contains(Branding::css($custom), '--accent-d:#6ee7b7;'), true);
check('an edit lands in the palette and not in the ready-made one', (function () use ($palettes, $settings) {
    $edited = $palettes; $edited[0]['light']['accent'] = '#0f5132';
    $s = $settings(['site_palettes' => SitePalettes::encode($edited), 'site_palette' => 'emerald-custom'], 'emerald');
    return [str_contains(Branding::css($s), '--accent-l:#0f5132;'), BuiltinPalettes::get('emerald')['light']['accent']];
})(), [true, '#047857']);

// ---- + Custom: from two colours, from Duo (mocked), from a copy
$gen = PaletteAdmin::generate(['accent' => '#fb5870', 'base' => '#212c34', 'base_role' => 'background']);
$saved = SitePalettes::save([], ['id' => 'mine', 'name' => 'Mine', 'source' => 'rose', 'from' => ['accent' => '#fb5870', 'base' => '#212c34', 'role' => 'background']] + $gen);
check('from two colours: the palette is saved with its two colours, its source and all six roles', [count($saved), $saved[0]['source'], array_keys($saved[0]['light']), $saved[0]['from']['accent'], $gen['passes']], [1, 'rose', array_keys($gen['light']), '#fb5870', true]);
$dir = sys_get_temp_dir() . '/faros-unify-' . getmypid();
$duo = new DuoPairings($dir, static fn(string $u): ?string => json_encode(['colors' => [['colors' => ['#1d4ed8', '#f8fafc'], 'aa_accessible' => true]]]), static fn(): int => 1000);
$answer = PaletteAdmin::duo(['design' => []], $duo);
$pair = $answer['pairs'][0]['colors'] ?? [];
$fromDuo = PaletteAdmin::generate(['accent' => $pair[0] ?? '', 'base' => $pair[1] ?? '', 'base_role' => 'background']);
check('from Duo (a mocked list): a pair fills the two colours and makes a palette that reads at AA', [$answer['ok'], $pair, $fromDuo['ok'], $fromDuo['light']['background'], $fromDuo['passes']], [true, ['#1d4ed8', '#f8fafc'], true, '#f8fafc', true]);
foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); } @rmdir($dir);
$fromCopy = PaletteAdmin::copy(['builtin' => 'indigo']);
check('from a copy of a ready-made palette', [$fromCopy['ok'], $fromCopy['palette']['name'], $fromCopy['palette']['light']['accent']], [true, 'Indigo (custom)', '#4338ca']);

// ---- rename and delete, with the fall back
$list = SitePalettes::save(SitePalettes::save([], PaletteAdmin::copy(['builtin' => 'teal'])['palette']), ['id' => 'two', 'name' => 'Two'] + $gen);
$list = SitePalettes::rename($list, 'two', 'Brand colours');
check('rename changes only the name', [array_column($list, 'name'), $list[1]['light'] === $gen['light']], [['Teal (custom)', 'Brand colours'], true]);
$inUse = $settings(['site_palettes' => SitePalettes::encode($list), 'site_palette' => 'teal-custom'], 'teal');
$gone = $settings(['site_palettes' => SitePalettes::encode(SitePalettes::delete($list, 'teal-custom')), 'site_palette' => 'teal-custom'], 'teal');
check('delete: the palette is gone, the site falls back to its source (Teal) and looks like plain Teal', [array_column(SitePalettes::parse($gone['design']['site_palettes']), 'id'), SitePalettes::active($gone['design']), Branding::css($gone), Branding::attributes($gone)['data-theme']], [['two'], null, '', 'teal']);
check('a palette without a source falls back to the palette chosen in the form (the default one is the first of the row)', [SitePalettes::active($settings(['site_palettes' => SitePalettes::encode([$mine]), 'site_palette' => 'gone']))], [null]);
$moved = SitePalettes::followSource($settings(['site_palettes' => SitePalettes::encode($list), 'site_palette' => 'teal-custom'], 'amber'));
check('the ready-made palette always follows the source of the palette in use', [$moved['appearance']['palette'], SitePalettes::followSource($settings(['site_palettes' => SitePalettes::encode($list), 'site_palette' => 'two'], 'amber'))['appearance']['palette']], ['teal', 'amber']);

// ---- Fix: the nearest shade that reads at AA, same hue
$fix = PaletteAdmin::fix(['colour' => '#34d5c3', 'against' => '#ffffff']);
[$l0, $c0, $h0] = Oklch::fromHex('#34d5c3');
[$l1, $c1, $h1] = Oklch::fromHex($fix['after']);
check('Fix #34d5c3 on white (1.8:1): the result reads at 4.5:1, is darker, and keeps the hue', [$fix['ok'], $fix['ratio_before'], $fix['ratio_after'] >= 4.5, $l1 < $l0, abs($h1 - $h0) < 4.0, $fix['level']], [true, 1.83, true, true, true, 'AA']);
$near = true; $hue = true;
mt_srand(5);
for ($i = 0; $i < 300; $i++) {
    $fg = sprintf('#%06x', mt_rand(0, 0xffffff)); $bg = sprintf('#%06x', mt_rand(0, 0xffffff));
    $min = mt_rand(0, 1) ? 4.5 : 3.0;
    $r = Contrast::fix($fg, [$bg], $min);
    if ($r === null) { continue; }
    $near = $near && Contrast::ratio($r, $bg) >= $min;
    [$a0, $b0, $c0h] = Oklch::fromHex($fg); [$a1, $b1, $c1h] = Oklch::fromHex($r);
    if ($b0 > 0.05 && $b1 > 0.05 && $r !== $fg) { $d = abs($c1h - $c0h); $hue = $hue && min($d, 360 - $d) < 8.0; }
}
check('for 300 random pairs a fix always reaches the minimum (4.5 or 3), and a coloured one keeps its hue', [$near, $hue], [true, true]);
check('a colour that already reads is returned as it is; for UI parts 3:1 is enough', [Contrast::fix('#0f766e', ['#ffffff']), Contrast::ratio(Contrast::fix('#34d5c3', ['#ffffff'], 3.0), '#ffffff') >= 3.0, Contrast::ratio(Contrast::fix('#34d5c3', ['#ffffff'], 3.0), '#ffffff') < 4.5], ['#0f766e', true, true]);
check('on two backgrounds it reads on both; a bad request is refused', [Contrast::ratio(Contrast::fix('#8899aa', ['#ffffff', '#f5f6f8']), '#f5f6f8') >= 4.5, PaletteAdmin::fix(['colour' => 'red', 'against' => '#fff'])['ok'], PaletteAdmin::fix(['colour' => '#123456', 'against' => ''])['ok']], [true, false, false]);
$moved = Contrast::fix('#34d5c3', ['#ffffff']);
$s = $settings(['site_palettes' => SitePalettes::encode([['id' => 'x', 'name' => 'X', 'light' => ['accent' => '#34d5c3', 'background' => '#ffffff'], 'dark' => ['accent' => '#34d5c3', 'background' => '#0b1220']]]), 'site_palette' => 'x']);
$p = SitePalettes::active($s['design']); $p['light']['accent'] = $moved;
$fixed = $settings(['site_palettes' => SitePalettes::encode([$p]), 'site_palette' => 'x']);
check('applying a Fix changes that role in that mode only', [str_contains(Branding::css($fixed), '--accent-l:' . $moved . ';'), str_contains(Branding::css($fixed), '--accent-d:#34d5c3;')], [true, true]);

// ---- export and import: an old file with overrides, and the new shape
$clean = sys_get_temp_dir() . '/faros-unify-site-' . getmypid();
mkdir($clean . '/custom', 0775, true); mkdir($clean . '/storage', 0775, true); @symlink($repo . '/themes', $clean . '/themes');
$theme2 = new Theme($clean, 'default');
$db = new SystemDatabase($clean . '/storage'); $db->initialize();
$store = ['settings' => $theme2->resolveSettings($theme2->defaultSettings())];
$import = new BrandingTransfer($theme2, function () use (&$store) { return $store['settings']; }, function (array $s) use (&$store) { $store['settings'] = $s; }, new SystemMetaRepository($db), '0.1.142');
$old = json_encode(['format' => BrandingTransfer::FORMAT, 'faroscms' => '0.1.142', 'theme' => 'default', 'settings' => ['appearance' => ['palette' => 'teal'], 'design' => $overrides]]);
$read = $import->read($old);
$oldCss = Branding::css($settings($overrides, 'teal'));
$result = $import->apply($read);
$landed = SitePalettes::active($store['settings']['design']);
check('an old export (overrides on Teal) imports as "Teal (custom)", with the same CSS as the site it came from', [$result['ok'], $landed['name'] ?? null, $store['settings']['design']['light_accent'], Branding::css($store['settings'], '')], [true, 'Teal (custom)', '', $oldCss]);
$zip = $clean . '/out.zip';
$import->export($zip);
$doc = json_decode((new ZipArchive())->open($zip) === true ? (function () use ($zip) { $z = new ZipArchive(); $z->open($zip); $j = $z->getFromName('branding.json'); $z->close(); return $j; })() : '', true);
check('exporting the migrated site writes the new shape: a palette and no overrides', [$doc['settings']['design']['light_accent'], $doc['settings']['design']['site_palette'], str_contains($doc['settings']['design']['site_palettes'], 'Teal (custom)')], ['', 'teal-custom', true]);
$store = ['settings' => $theme2->resolveSettings($theme2->defaultSettings())];
$again = $import->apply($import->read((string)file_get_contents($zip)));
check('and that file imports back to the same CSS', [$again['ok'], Branding::css($store['settings'], '')], [true, $oldCss]);
check('importing the same file again changes nothing', $import->apply($import->read((string)file_get_contents($zip)))['changed'], 0);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
