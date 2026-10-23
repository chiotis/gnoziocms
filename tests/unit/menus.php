<?php
/*
 * Menus: reading and writing the YAML files, checking items, nesting from the admin form, which menu is in which
 * place, labels in the right language, and which item is active for a page.
 *   php tests/unit/menus.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\Menus;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/menus' . getmypid();
mkdir($dir, 0775, true);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'base_url' => 'https://site.test', 'menu_locations' => ['header' => 'main', 'footer' => 'footer', 'sidebar' => 'side']];
$strings = ['nav.main.about' => 'About us', 'nav.main.home' => 'Home'];
$translate = fn(string $key, ?string $fallback = null): string => $strings[$key] ?? ($fallback ?? $key);
$locations = ['sidebar' => ['default' => 'side']];
$m = new Menus($dir, fn() => $settings, $translate, fn() => $locations);

// ---- checking items
$items = $m->normalize([
    ['label' => 'About', 'url' => 'about', 'class' => ' nav-cta ', 'target' => '_blank'],
    ['label' => '', 'label_key' => '', 'url' => ''],
    'not an item',
    ['labels' => ['en' => 'Services'], 'url' => 'services', 'children' => [
        ['label' => 'Design', 'url' => 'design', 'children' => [['label' => 'Deep', 'url' => 'deep', 'children' => [['label' => 'Too deep', 'url' => 'x']]]]],
    ]],
]);
check('empty rows and non-items are dropped', count($items), 2);
check('a plain label is the default language label, other languages are empty', $items[0]['labels'], ['el' => 'About', 'en' => '']);
check('class and target are trimmed', [$items[0]['class'], $items[0]['target']], ['nav-cta', '_blank']);
check('a language label alone keeps the item', [$items[1]['labels']['en'], $items[1]['url']], ['Services', 'services']);
check('items nest three levels', $items[1]['children'][0]['children'][0]['label'], 'Deep');
check('and no deeper', isset($items[1]['children'][0]['children'][0]['children']), false);
check('anything that is not a list gives no items', $m->normalize('nope'), []);

// ---- the editor: rows with depths become a tree
$row = fn(int $depth, string $el, string $url = 'x', array $more = []) => $more + ['depth' => $depth, 'labels' => ['el' => $el, 'en' => ''], 'url' => $url];
$rows = $m->fromRows([$row(1, 'A', 'a'), $row(2, 'B', 'b'), $row(3, 'C', 'c'), $row(2, 'D', 'd'), $row(1, '', '')], ['el', 'en']);
check('depths nest rows under the row above', [count($rows), $rows[0]['label'] ?? '', count($rows[0]['children'])], [1, '', 2]);
check('a level under a level nests again', $rows[0]['children'][0]['children'][0]['labels']['el'], 'C');
check('a second child goes next to the first', $rows[0]['children'][1]['labels']['el'], 'D');
check('an empty row is skipped', count($rows), 1);
check('a first row that claims to be deep is brought up to the top', count($m->fromRows([$row(3, 'Only')], ['el', 'en'])), 1);
check('so is a row that is more than one level below the one above', [count($m->fromRows([$row(1, 'A'), $row(3, 'B')], ['el', 'en'])[0]['children']), isset($m->fromRows([$row(1, 'A'), $row(3, 'B')], ['el', 'en'])[0]['children'][0]['children'])], [1, false]);
check('a depth outside 1 to 3 is held to it', count($m->fromRows([$row(0, 'A'), $row(9, 'B')], ['el', 'en'])), 1);
check('rows that are not a list of rows give no items', [$m->fromRows('x', ['el']), $m->fromRows([1, 'a', null], ['el'])], [[], []]);
$more = $m->fromRows([$row(1, 'A', 'a', ['class' => ' nav-cta ', 'target' => '_blank', 'hidden' => true, 'label_key' => 'nav.main.home']), $row(1, 'B', 'b', ['hidden' => 'false'])], ['el', 'en']);
check('class, target, theme key and hidden are kept (trimmed)', [$more[0]['class'], $more[0]['target'], $more[0]['label_key'], $more[0]['hidden']], ['nav-cta', '_blank', 'nav.main.home', true]);
check('hidden is only set on an item that is hidden', isset($more[1]['hidden']), false);
check('a label in a language the site does not have is dropped', array_keys($m->fromRows([$row(1, 'A', 'a', ['labels' => ['el' => 'A', 'de' => 'Ä']])], ['el', 'en'])[0]['labels']), ['el', 'en']);
$flat = $m->flatten($rows, ['el', 'en']);
check('flattening gives one row per item with its depth', array_map(fn($r) => $r['depth'] . $r['labels']['el'], $flat), ['1A', '2B', '3C', '2D']);
check('and says which are hidden', array_column($m->flatten($more, ['el', 'en']), 'hidden'), [true, false]);

// ---- writing and reading
$m->write('main', ['title' => 'Main Menu', 'items' => $items]);
check('a menu is written to content/menus', is_file($dir . '/menus/main.yaml'), true);
$read = $m->load('main');
check('and read back, checked', [$read['title'], count($read['items']), $read['items'][0]['url']], ['Main Menu', 2, 'about']);
check('a menu that does not exist is empty, with a title from its key', $m->load('side-bar'), ['title' => 'Side Bar', 'items' => []]);
file_put_contents($dir . '/menus/main.yaml', "title: Changed\nitems: []\n");
check('what was read is kept until told the files changed', $m->load('main')['title'], 'Main Menu');
$m->forget();
check('and read again after that', $m->load('main')['title'], 'Changed');
check('a menu key is made safe for a file name', basename($m->path('../Evil Menu')), 'evil-menu.yaml');
check('an empty key is "menu"', basename($m->path('')), 'menu.yaml');

// ---- defaults and the list
$fresh = sys_get_temp_dir() . '/menus-fresh' . getmypid();
$m2 = new Menus($fresh, fn() => $settings, $translate, fn() => $locations);
$m2->ensureDefaults();
check('a new site gets a main and a footer menu', [is_file($fresh . '/menus/main.yaml'), is_file($fresh . '/menus/footer.yaml')], [true, true]);
check('the main menu starts with the home item', $m2->load('main')['items'][0]['label_key'], 'nav.main.home');
$before = file_get_contents($fresh . '/menus/main.yaml');
$m2->write('side', ['title' => 'Side', 'items' => []]);
$m2->ensureDefaults();
check('defaults do not overwrite what exists', file_get_contents($fresh . '/menus/main.yaml'), $before);
check('the list has the default menus and the custom one, sorted', $m2->keys(), ['footer', 'main', 'side']);
file_put_contents($fresh . '/menus/legacy.en.yaml', "title: x\n");
check('a file with a language suffix is not a menu', in_array('legacy', $m2->keys(), true) || in_array('legacy.en', $m2->keys(), true), false);
check('the admin list has a title for each', array_column($m2->listForAdmin(), 'title'), ['Footer Menu', 'Main Menu', 'Side']);
check('and how many items each has (all levels), and the places it is shown in', array_map(fn($r) => $r['key'] . ':' . $r['items'] . ':' . implode('+', $r['places']), $m2->listForAdmin()), ['footer:7:Footer', 'main:9:Header', 'side:0:Sidebar']);

// ---- which menu is where, with labels and the active item
$m->write('main', ['title' => 'Main', 'items' => [
    ['label_key' => 'nav.main.home', 'url' => ''],
    ['label_key' => 'nav.main.about', 'url' => 'about'],
    ['labels' => ['el' => 'Υπηρεσίες', 'en' => 'Services'], 'url' => 'services', 'children' => [['labels' => ['el' => 'Σχεδιασμός'], 'url' => 'services/design']]],
    ['label' => 'External', 'url' => 'https://other.test/services'],
    ['label' => 'Mail', 'url' => 'mailto:a@b.test'],
    ['label' => 'Own domain', 'url' => 'https://site.test/en/about'],
]]);
$m->write('footer', ['title' => 'Footer', 'items' => [['label' => 'Terms', 'url' => 'terms']]]);
$menus = $m->forTheme('en', 'about');
check('every place has its menu', array_keys($menus), ['header', 'footer', 'sidebar']);
check('the header is the main menu, a place not set falls to its default', [count($menus['header']), count($menus['sidebar'])], [6, 0]);
check('a label with no text in the language comes from the theme strings', $menus['header'][1]['label'], 'About us');
check('a language label is used in that language', $menus['header'][2]['label'], 'Services');
$el = $m->forTheme('el', 'about');
check('and the other language has its own', $el['header'][2]['label'], 'Υπηρεσίες');
check('a child with no label in the language uses the default language', $m->forTheme('en', '')['header'][2]['children'][0]['label'], 'Σχεδιασμός');
check('the item for the page shown is active', [$menus['header'][1]['is_active'], $menus['header'][0]['is_active'], $menus['header'][2]['is_active']], [true, false, false]);
check('the home item is active only on the home page', [$m->forTheme('en', '')['header'][0]['is_active'], $m->forTheme('en', 'about')['header'][0]['is_active']], [true, false]);
$on = $m->forTheme('en', 'services/design')['header'][2];
check('a parent is on the trail, and active too, when a child is the page', [$on['is_active'], $on['is_trail'], $on['children'][0]['is_active']], [true, true, true]);
check('a page below an item keeps it active', $m->forTheme('en', 'about/team')['header'][1]['is_active'], true);
check('the language prefix in an address is ignored', [$m->forTheme('en', 'en/about')['header'][1]['is_active'], $menus['header'][5]['is_active']], [true, true]);
check('another site and a mail link are never active', [$menus['header'][3]['is_active'], $menus['header'][4]['is_active']], [false, false]);

// ---- what the file keeps, and hidden items
$m->write('lean', ['title' => 'Lean', 'items' => [
    ['labels' => ['el' => 'Α', 'en' => ''], 'url' => 'a'],
    ['label' => 'Skip', 'url' => 'skip', 'hidden' => true, 'class' => 'k', 'children' => [['label' => 'Child', 'url' => 'child']]],
]]);
$file = file_get_contents($dir . '/menus/lean.yaml');
check('the file has only what is set', [str_contains($file, "label_key"), str_contains($file, "target"), str_contains($file, "en: ''"), str_contains($file, 'hidden: true'), str_contains($file, 'class: k')], [false, false, false, true, true]);
check('and reads back whole', [$m->load('lean')['items'][0]['labels'], $m->load('lean')['items'][1]['hidden'], $m->load('lean')['items'][1]['children'][0]['label']], [['el' => 'Α', 'en' => ''], true, 'Child']);
$withLean = $settings;
$withLean['menu_locations']['sidebar'] = 'lean';
$m3 = new Menus($dir, fn() => $withLean, $translate, fn() => $locations);
check('a hidden item is not offered to the theme, nor what is under it', array_column($m3->forTheme('el', '')['sidebar'], 'label'), ['Α']);
check('and it stays in the menu', count($m->load('lean')['items']), 2);

// ---- the places of the theme
$places = $m3->locations();
check('the places are the header and footer first, then the theme\'s own, with the menu each shows', array_map(fn($p) => $p['key'] . '=' . $p['menu'], $places), ['header=main', 'footer=footer', 'sidebar=lean']);
check('and the menu the theme starts with', array_map(fn($p) => $p['default'], $places), ['main', 'footer', 'side']);
$bare = ['languages' => $settings['languages']];
$m4 = new Menus($dir, fn() => $bare, $translate, fn() => $locations);
check('a place the settings do not set shows the theme\'s own menu', array_column($m4->locations(), 'menu'), ['main', 'footer', 'side']);
$strip = $bare + ['menu_locations' => ['strip' => 'extra']];
$m5 = new Menus($dir, fn() => $strip, $translate, fn() => $locations);
check('a place only the settings know is listed with a title made from its key', array_values(array_filter($m5->locations(), fn($p) => $p['key'] === 'strip'))[0], ['key' => 'strip', 'label' => 'Strip', 'default' => '', 'menu' => 'extra']);

// ---- languages an item is limited to, its icon, badges and rel
$extra = $m->normalize([
    ['label' => 'Νέο', 'url' => 'new', 'languages' => ['EL', ' el ', 'not a code', 'en'], 'icon' => 'bolt', 'badges' => ['el' => 'Νέο', 'en' => ''], 'rel' => 'nofollow  Sponsored bogus', 'target' => '_blank'],
    ['label' => 'Plain', 'url' => 'plain', 'icon' => 'Bad Icon!', 'badges' => 'nope', 'rel' => ['ugc', 'nofollow']],
    ['label' => 'Μόνο', 'url' => 'only', 'languages' => ['el']],
    ['label_key' => 'nav.main.gone', 'url' => 'gone'],
]);
check('an item is limited to the codes it names: cleaned, no duplicates, no text that is not a code', $extra[0]['languages'], ['el', 'en']);
check('an icon is a plain name, and a badge is kept for the languages that have text', [$extra[0]['icon'], $extra[0]['badges']], ['bolt', ['el' => 'Νέο']]);
check('rel keeps only the values the menu offers, in their order', [$extra[0]['rel'], $extra[1]['rel']], [['nofollow', 'sponsored'], ['nofollow', 'ugc']]);
check('an item with none of these has none of these keys', array_keys($extra[3]), ['label', 'label_key', 'labels', 'url', 'class', 'target']);
$m->write('extra', ['title' => 'Extra', 'items' => $extra]);
$back = $m->load('extra')['items'];
check('the settings are written and read back whole', [$back[0]['languages'], $back[0]['icon'], $back[0]['badges'], $back[0]['rel']], [['el', 'en'], 'bolt', ['el' => 'Νέο'], ['nofollow', 'sponsored']]);
$file = file_get_contents($dir . '/menus/extra.yaml');
check('and an item with none of them has no line for them', [str_contains(explode('url: plain', $file)[1] ?? '', 'icon'), str_contains(explode('url: only', $file)[1] ?? '', 'badges')], [false, false]);
$flat = $m->flatten($extra, ['el', 'en']);
check('the editor gets the same settings for each row', [$flat[0]['languages'], $flat[0]['icon'], $flat[0]['badges'], $flat[0]['rel']], [['el', 'en'], 'bolt', ['el' => 'Νέο', 'en' => ''], ['nofollow', 'sponsored']]);
check('and a row with none gets empty ones', [$flat[1]['languages'], $flat[1]['icon'], $flat[1]['badges']], [[], '', ['el' => '', 'en' => '']]);
$sent = $m->fromRows([['depth' => 1, 'labels' => ['el' => 'X', 'en' => ''], 'url' => 'x', 'languages' => ['en'], 'icon' => 'bolt', 'badges' => ['en' => 'New'], 'rel' => ['nofollow']]], ['el', 'en']);
check('rows the editor sends keep the settings', [$sent[0]['languages'], $sent[0]['icon'], $sent[0]['badges'], $sent[0]['rel']], [['en'], 'bolt', ['en' => 'New'], ['nofollow']]);

$withExtra = $settings;
$withExtra['menu_locations']['sidebar'] = 'extra';
$m6 = new Menus($dir, fn() => $withExtra, $translate, fn() => $locations);
$en = $m6->forTheme('en', '')['sidebar'];
$el = $m6->forTheme('el', '')['sidebar'];
check('an item limited to Greek is not shown in English, and one with no limit is shown in both', [array_column($en, 'label'), array_column($el, 'label')], [['Νέο', 'Plain', ''], ['Νέο', 'Plain', 'Μόνο', '']]);
check('a badge is only shown in the language it was written in', [array_column($en, 'badge'), array_column($el, 'badge')], [['', '', ''], ['Νέο', '', '', '']]);
check('the icon and rel reach the theme', [array_map(fn($r) => $r['icon'] ?? '', $el)[0], $en[0]['rel'], isset($en[1]['rel'])], ['bolt', ['nofollow', 'sponsored'], true]);
check('a theme key the theme does not know is never shown as the label', $en[2]['label'], '');

// ---- roles an item is shown to
$roled = $m->normalize([
    ['label' => 'Όλοι', 'url' => 'all'],
    ['label' => 'Μέλη', 'url' => 'members', 'roles' => ['EDITOR', ' editor ', 'not a role!', 'admin'], 'children' => [
        ['label' => 'Παιδί', 'url' => 'members/child'],
    ]],
    ['label' => 'Διαχειριστές', 'url' => 'admins', 'roles' => ['admin']],
]);
check('an item is limited to the role keys it names: cleaned, no duplicates, no text that is not a key', $roled[1]['roles'], ['editor', 'admin']);
check('an item with no roles has none', isset($roled[0]['roles']), false);
$m->write('roled', ['title' => 'Roled', 'items' => $roled]);
$back = $m->load('roled')['items'];
check('roles are written and read back whole', [$back[1]['roles'], $back[2]['roles']], [['editor', 'admin'], ['admin']]);
$roledFile = file_get_contents($dir . '/menus/roled.yaml');
check('and an item with no roles has no line for them', str_contains(explode('url: members', explode('url: all', $roledFile)[1])[0], 'roles'), false);
$flat = $m->flatten($roled, ['el', 'en']);
check('the editor gets the roles of each row', [$flat[1]['roles'], $flat[0]['roles']], [['editor', 'admin'], []]);
$sent = $m->fromRows([['depth' => 1, 'labels' => ['el' => 'X', 'en' => ''], 'url' => 'x', 'roles' => ['editor']]], ['el', 'en']);
check('rows the editor sends keep the roles', $sent[0]['roles'], ['editor']);

$withRoled = $settings;
$withRoled['menu_locations']['aside'] = 'roled';
$m7 = new Menus($dir, fn() => $withRoled, $translate, fn() => $locations);
$labelsFor = fn(string $role): array => array_column($m7->forTheme('el', '', $role)['aside'], 'label');
check('a visitor who is not signed in sees only the items with no roles', $labelsFor(''), ['Όλοι']);
check('a signed-in user with one of the roles sees the item and what is under it', [$labelsFor('editor'), array_column($m7->forTheme('el', '', 'editor')['aside'][1]['children'], 'label')], [['Όλοι', 'Μέλη'], ['Παιδί']]);
check('a role the item does not name does not see it', $labelsFor('user'), ['Όλοι']);
check('the super admin sees every item', $labelsFor('superadmin'), ['Όλοι', 'Μέλη', 'Διαχειριστές']);
check('a role of its own sees the items it is named in', $labelsFor('admin'), ['Όλοι', 'Μέλη', 'Διαχειριστές']);

// ---- addresses that change
$m->relink('about', 'about-us');
check('an address change is followed in every menu', [$m->load('main')['items'][1]['url'], $m->load('footer')['items'][0]['url']], ['about-us', 'terms']);
$m->relink('services/design', 'services/planning');
check('and in children', $m->load('main')['items'][2]['children'][0]['url'], 'services/planning');

exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($fresh));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
