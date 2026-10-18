<?php
/*
 * Theme > Branding > Colour: contrast ratios, the palette generator (OKLCH), the palettes a site makes, the secondary colour,
 * and the Duo colour pairings (fetched live, never part of the code).
 *   php tests/unit/palettes.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{Branding, Contrast, DuoPairings, Oklch, PaletteAdmin, PaletteGenerator, SitePalettes};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_SLASHES)) . "\n"; }
function has(string $haystack, string $needle): bool { return str_contains($haystack, $needle); }

// ---- contrast
check('black on white is 21:1, a colour on itself 1:1', [round(Contrast::ratio('#000000', '#ffffff'), 2), round(Contrast::ratio('#336699', '#336699'), 2)], [21.0, 1.0]);
check('the ratio does not depend on the order', Contrast::ratio('#1d4ed8', '#ffffff') === Contrast::ratio('#ffffff', '#1d4ed8'), true);
check('a known ratio: #767676 on white is 4.54:1', Contrast::report('#767676', '#ffffff'), ['ratio' => 4.54, 'level' => 'AA']);
check('levels: AAA from 7, AA from 4.5, large text from 3, below that a fail', [Contrast::level(7.0), Contrast::level(6.99), Contrast::level(4.5), Contrast::level(4.49), Contrast::level(3.0), Contrast::level(2.99)], ['AAA', 'AA', 'AA', 'AA large', 'AA large', 'fail']);
check('the text on an accent is white on dark colours and the near-black on light ones', [Contrast::textOn('#1d4ed8'), Contrast::textOn('#fde047')], ['#ffffff', '#0b1220']);
check('it is the choice Branding makes for an accent', [Branding::css(['design' => ['light_accent' => '#1d4ed8']]) !== '' && str_contains(Branding::css(['design' => ['light_accent' => '#1d4ed8']]), '--accent-contrast-l:' . Contrast::textOn('#1d4ed8') . ';'), str_contains(Branding::css(['design' => ['light_accent' => '#fde047']]), '--accent-contrast-l:' . Contrast::textOn('#fde047') . ';')], [true, true]);

// ---- OKLCH
check('white is lightness 1, black 0', [round(Oklch::fromHex('#ffffff')[0], 3), round(Oklch::fromHex('#000000')[0], 3)], [1.0, 0.0]);
$roundTrip = true;
foreach (['#1d4ed8', '#fb5870', '#047857', '#fde047', '#808080', '#0b1220'] as $hex) {
    [$l, $c, $h] = Oklch::fromHex($hex);
    $roundTrip = $roundTrip && Oklch::toHex($l, $c, $h) === $hex;
}
check('a colour goes to OKLCH and back unchanged', $roundTrip, true);
check('a colour outside sRGB keeps its lightness and hue and loses chroma', preg_match('/^#[0-9a-f]{6}$/', Oklch::toHex(0.7, 0.4, 145.0)), 1);

// ---- the generator
$pairs = [['#fb5870', '#212c34', 'background'], ['#fb5870', '#212c34', 'ink'], ['#1d4ed8', '#ffffff', 'background'], ['#fde047', '#101010', 'background'], ['#808080', '#808080', 'background'], ['#00ff00', '#fdf6e3', 'ink'], ['#ffffff', '#000000', 'background']];
$a = PaletteGenerator::generate('#fb5870', '#212c34', 'background', '#ff8800');
check('the same input gives the same palette', $a === PaletteGenerator::generate('#fb5870', '#212c34', 'background', '#ff8800'), true);
check('it has the six roles for light and dark, the dark panels and the secondary', [array_keys($a['light']), array_keys($a['dark']), isset($a['ink']), array_keys($a['secondary'])], [PaletteGenerator::ROLES, PaletteGenerator::ROLES, true, ['light', 'dark']]);
check('a clearly dark base is the dark background as it is, a light page is derived from its hue', [$a['dark']['background'], Contrast::luminance($a['light']['background']) > 0.9], ['#212c34', true]);
$b = PaletteGenerator::generate('#1d4ed8', '#ffffff');
check('a light base is the light background as it is', $b['light']['background'], '#ffffff');
$ink = PaletteGenerator::generate('#fb5870', '#212c34', 'ink');
check('an ink base is the light text', $ink['light']['text'], '#212c34');
check('the same two colours as background or as ink give different palettes', $ink['dark']['background'] !== $a['dark']['background'], true);
check('a swapped pair is another palette', PaletteGenerator::generate('#212c34', '#fb5870')['light']['accent'] !== $a['light']['accent'], true);
$all = true;
foreach ($pairs as [$accent, $base, $role]) {
    $p = PaletteGenerator::generate($accent, $base, $role, '#ff8800');
    $all = $all && PaletteGenerator::passes($p);
}
check('text, muted text and the accent read at AA on the background and the surface, in both modes, and so does the text on the accent', $all, true);
mt_srand(11);
$bad = 0;
for ($i = 0; $i < 600; $i++) {
    $p = PaletteGenerator::generate(sprintf('#%06x', mt_rand(0, 0xffffff)), sprintf('#%06x', mt_rand(0, 0xffffff)), mt_rand(0, 1) ? 'ink' : 'background', mt_rand(0, 1) ? sprintf('#%06x', mt_rand(0, 0xffffff)) : null);
    $ok = PaletteGenerator::passes($p);
    foreach (($p['secondary'] ?? []) as $mode => $colour) {
        foreach (['background', 'surface'] as $on) {
            $ok = $ok && Contrast::ratio($colour, $p[$mode][$on]) >= 4.495;
        }
        $ok = $ok && Contrast::ratio(Contrast::textOn($colour), $colour) >= 4.495;
    }
    $bad += $ok ? 0 : 1;
}
check('600 random pairs: every one passes AA (secondary too)', $bad, 0);
check('an accent that already reads is not changed in dark mode', PaletteGenerator::generate('#6ee7b7', '#0b1220')['dark']['accent'], '#6ee7b7');
check('a colour that is not hex gives nothing', [PaletteGenerator::generate('red', '#ffffff'), PaletteGenerator::generate('#ffffff', 'nope'), PaletteGenerator::generate('#ffffff', '#000000', 'background', 'x')], [null, null, null]);
check('the screen asks for it with two colours and a role', [PaletteAdmin::generate(['accent' => '#1d4ed8', 'base' => '#ffffff', 'base_role' => 'ink'])['ok'], PaletteAdmin::generate(['accent' => 'x', 'base' => '#ffffff'])['ok']], [true, false]);

// ---- site palettes
$p1 = ['id' => 'brand', 'name' => 'Brand <b>one</b>', 'light' => $a['light'], 'dark' => $a['dark'], 'ink' => $a['ink'], 'secondary' => $a['secondary'], 'from' => ['accent' => '#fb5870', 'base' => '#212c34', 'role' => 'background']];
$list = SitePalettes::save([], $p1);
check('a palette is saved with a clean name', [count($list), $list[0]['name'], $list[0]['id']], [1, 'Brand one', 'brand']);
$list = SitePalettes::save($list, ['id' => 'second', 'name' => 'Second', 'light' => ['accent' => '#112233']]);
check('several palettes are allowed', array_column($list, 'id'), ['brand', 'second']);
$list = SitePalettes::save($list, ['id' => 'second', 'name' => 'Second', 'light' => ['accent' => '#445566']]);
check('saving with an existing id edits that palette', [count($list), $list[1]['light']['accent']], [2, '#445566']);
$list = SitePalettes::rename($list, 'brand', 'Renamed');
check('a palette can be renamed', $list[0]['name'], 'Renamed');
$text = SitePalettes::encode($list);
check('they are stored as JSON and read back the same', SitePalettes::parse($text), $list);
$list = SitePalettes::delete($list, 'brand');
check('a palette can be deleted', array_column($list, 'id'), ['second']);
check('bad ids, colours and non-lists are dropped, not trusted', [
    SitePalettes::parse('{not json'), SitePalettes::parse([['id' => 'Bad Id!', 'light' => ['accent' => '#112233']]]), SitePalettes::parse([['id' => 'ok', 'light' => ['accent' => 'red; }']]]),
    SitePalettes::parse([['id' => 'ok', 'light' => ['accent' => '#112233', 'nope' => '#ffffff'], 'extra' => 'x']]),
], [[], [], [], [['id' => 'ok', 'name' => 'Palette', 'light' => ['accent' => '#112233'], 'dark' => []]]]);
check('the same id twice keeps the first; the number is limited', [count(SitePalettes::parse([['id' => 'a', 'light' => ['accent' => '#112233']], ['id' => 'a', 'light' => ['accent' => '#223344']]])), count(SitePalettes::parse(array_map(static fn($i) => ['id' => 'p' . $i, 'light' => ['accent' => '#112233']], range(1, 40))))], [1, SitePalettes::MAX]);
check('a new id is made from the name and is unique', [SitePalettes::newId('Sea & Sky', []), SitePalettes::newId('Brand', [['id' => 'brand']])], ['sea-sky', 'brand-2']);
check('24 palettes fit the setting (10000 characters)', strlen(SitePalettes::encode(array_map(static fn($i) => $p1 + [] + ['id' => 'palette-number-' . $i, 'name' => str_repeat('N', 40)], range(1, 24)))) < 10000, true);

// ---- a palette in use fills the colours the site has not set, and the site's own choices win
$design = ['site_palette' => 'brand', 'site_palettes' => SitePalettes::encode([$p1]), 'light_accent' => '#112233'];
$applied = SitePalettes::apply($design);
check('the palette fills what is empty', [$applied['light_background'], $applied['dark_background'], $applied['ink'], $applied['light_secondary']], [$a['light']['background'], $a['dark']['background'], $a['ink'], $a['secondary']['light']]);
check('and does not replace a colour the site chose', $applied['light_accent'], '#112233');
check('no palette in use, or an unknown one, changes nothing', [SitePalettes::apply(['site_palettes' => SitePalettes::encode([$p1])]), SitePalettes::apply(['site_palette' => 'gone', 'site_palettes' => SitePalettes::encode([$p1])])], [['site_palettes' => SitePalettes::encode([$p1])], ['site_palette' => 'gone', 'site_palettes' => SitePalettes::encode([$p1])]]);
$css = Branding::css(['design' => $design]);
check('the CSS of the palette in use has its colours and the shades worked out from them', [has($css, '--n-bg:' . $a['light']['background'] . ';'), has($css, '--d-bg:' . $a['dark']['background'] . ';'), has($css, '--accent-l:#112233;'), has($css, '--accent-d:' . $a['dark']['accent'] . ';'), has($css, '--accent-d-soft:'), has($css, '--ink:' . $a['ink'] . ';')], [true, true, true, true, true, true]);

// ---- update safety: a site that does not use any of it is exactly as it was
$plain = ['design' => ['light_accent' => '#1d4ed8', 'dark_accent' => '#93c5fd', 'heading_color' => 'accent']];
$with = ['design' => $plain['design'] + ['site_palettes' => SitePalettes::encode([$p1]), 'site_palette' => '', 'duo_picker' => true, 'duo_url' => '']];
check('palettes stored but not in use, and the Duo settings, add nothing to the page', Branding::css($with) === Branding::css($plain), true);
check('nothing set is still nothing', [Branding::css(['design' => ['site_palette' => '', 'site_palettes' => '', 'light_secondary' => '', 'dark_secondary' => '']]), Branding::css([])], ['', '']);

// ---- the secondary colour
$css = Branding::css(['design' => ['light_secondary' => '#b45309', 'dark_secondary' => '#fcd34d']]);
check('a secondary colour has its variables for each mode, and the ones that follow the mode', [
    has($css, '--secondary-l:#b45309;'), has($css, '--secondary-l-hover:'), has($css, '--secondary-l-soft:'), has($css, '--secondary-contrast-l:#ffffff;'),
    has($css, '--secondary-d:#fcd34d;'), has($css, '--secondary-contrast-d:#0b1220;'),
    has($css, '--color-secondary:var(--secondary-l);'), has($css, '--color-secondary-contrast:var(--secondary-contrast-l);'),
], [true, true, true, true, true, true, true, true]);
check('dark mode switches it, by class and by the system setting', [has($css, ':root.dark[data-theme][data-mode]{--color-secondary:var(--secondary-d);'), has($css, '@media(prefers-color-scheme:dark){:root[data-mode="system"]:not(.light)[data-theme]{--color-secondary:var(--secondary-d);')], [true, true]);
$css = Branding::css(['design' => ['light_secondary' => '#b45309']]);
check('one mode set: the other uses it', has($css, '--secondary-d:#b45309;'), true);
check('a bad secondary colour adds nothing', Branding::css(['design' => ['light_secondary' => 'red; x']]), '');

// ---- Duo: fetched live, cached for a week, hidden when it fails
$body = json_encode(['colors' => [['colors' => ['#112233', '#ddeeff'], 'aa_accessible' => true], ['colors' => ['#445566', '#778899'], 'aa_accessible' => false], ['colors' => ['zzz', '#778899']], 'x']]);
$dir = sys_get_temp_dir() . '/faros-duo-' . bin2hex(random_bytes(4));
$now = 1000000;
$calls = [];
$http = static function (string $url) use (&$calls, &$body): ?string { $calls[] = $url; return $body; };
$duo = new DuoPairings($dir, $http, static function () use (&$now): int { return $now; });
$pairs = $duo->pairs('');
check('the pairs are read from the default address, checked, with their AA flag', [$pairs, $calls], [[['colors' => ['#112233', '#ddeeff'], 'aa' => true], ['colors' => ['#445566', '#778899'], 'aa' => false]], [DuoPairings::DEFAULT_URL]]);
$duo->pairs('');
$now += 6 * 86400;
$duo->pairs('');
check('and kept for 7 days: no new request', count($calls), 1);
$now += 2 * 86400;
$duo->pairs('');
check('after 7 days it asks again', count($calls), 2);
$duo->pairs('https://example.test/other.json');
check('another address is another list', end($calls), 'https://example.test/other.json');
$failing = new DuoPairings($dir . '-none', static fn(string $u): ?string => null, static fn(): int => 1);
check('a failed request gives nothing (the picker is hidden)', $failing->pairs(''), null);
$broken = new DuoPairings($dir . '-none', static function (string $u): ?string { throw new RuntimeException('timeout'); }, static fn(): int => 1);
check('so does one that throws', $broken->pairs(''), null);
check('and an answer that is not a list of pairs, and an address that is not https', [(new DuoPairings($dir . '-none', static fn(string $u): ?string => '<html>', static fn(): int => 1))->pairs(''), (new DuoPairings($dir . '-none', $http, static fn(): int => 1))->pairs('http://duo.example.test/colors.json'), (new DuoPairings($dir . '-none', static fn(string $u): ?string => '{"colors":[]}', static fn(): int => 1))->pairs('')], [null, null, null]);
check('nothing was cached for a failure', is_dir($dir . '-none'), false);
$cached = json_decode((string)file_get_contents($dir . '/duo-pairings.json'), true);
check('the cache is in the storage folder it was given, with the address and the time', [array_keys($cached)], [['url', 'fetched', 'pairs']]);
$settings = ['design' => ['duo_picker' => true, 'duo_url' => '']];
$answer = PaletteAdmin::duo($settings, new DuoPairings($dir . '-b', $http, static fn(): int => 1));
check('the screen gets the pairs and the credit', [$answer['ok'], $answer['credit'], $answer['credit_url']], [true, 'Colour pairings from Duo by Alex Pate', 'https://duo.alexpate.com']);
$off = PaletteAdmin::duo(['design' => ['duo_picker' => false]], new DuoPairings($dir . '-c', static function (string $u): ?string { throw new RuntimeException('must not be called'); }));
check('switched off, nothing is requested', [$off['ok'], $off['off'] ?? false], [false, true]);
$down = PaletteAdmin::duo($settings, new DuoPairings($dir . '-d', static fn(string $u): ?string => null));
check('when it fails the screen gets a quiet note', [$down['ok'], $down['message']], [false, 'Colour pairings are not available right now.']);
check('the address is one setting of the theme, and the public page never asks for Duo', [
    str_contains((string)file_get_contents($repo . '/themes/default/theme.yaml'), 'duo_url:'),
    (bool)preg_match('/duo/i', (string)file_get_contents($repo . '/themes/default/layouts/base.twig')),
    (bool)preg_match('/duo/i', (string)file_get_contents($repo . '/src/Branding.php')),
], [true, false, false]);
foreach (['', '-b'] as $suffix) { foreach (glob($dir . $suffix . '/*') ?: [] as $f) { @unlink($f); } @rmdir($dir . $suffix); }

// ---- no Duo data in the repository (the collection has no licence)
$tracked = array_filter(explode("\n", (string)shell_exec('cd ' . escapeshellarg($repo) . ' && git ls-files 2>/dev/null')));
$hits = [];
foreach ($tracked as $file) {
    $path = $repo . '/' . $file;
    if (!is_file($path) || filesize($path) > 2000000 || preg_match('#\.(png|jpe?g|gif|webp|woff2?|zip|ico|pdf)$#i', $file)) {
        continue;
    }
    $content = (string)file_get_contents($path);
    // The shape of the collection is in the code and the tests; a copy of it would hold dozens of pairs.
    if (substr_count($content, 'aa_accessible') > 6 || preg_match('/(?:"#[0-9a-f]{6}",\s*"#[0-9a-f]{6}"\]\s*,\s*"aa_accessible"[^}]*\}\s*,\s*\{[^}]*){6}/i', $content)) {
        $hits[] = $file;
    }
}
check('no copy of the Duo collection is in the repository', $hits, []);
check('and there is no data file of it', array_values(array_filter($tracked, static fn(string $f): bool => (bool)preg_match('#duo[^/]*\.json$|colors\.json$#i', $f))), []);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
