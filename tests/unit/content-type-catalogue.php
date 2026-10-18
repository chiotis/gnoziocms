<?php
/*
 * The catalogue of content types the theme ships (posts, projects, books): which are on, switching one on or off from the
 * Content types screen, and what switching off does and does not touch.
 *   php tests/unit/content-type-catalogue.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentRepository, ContentTypeAdmin, ContentTypes, SiteSettings, SystemDatabase, SystemMetaRepository, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = sys_get_temp_dir() . '/ctcat' . getmypid();
mkdir("$root/storage", 0775, true);
mkdir("$root/custom", 0775, true);
mkdir("$root/content/posts", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });

$db = new SystemDatabase("$root/storage");
$db->initialize();
$settings = new SiteSettings(new SystemMetaRepository($db), "$root/content");
$types = new ContentTypes(new Theme($root, 'default'));
$markdown = new MarkdownConverter((new Environment([]))->addExtension(new CommonMarkCoreExtension()));
$repo = fn(): ContentRepository => new ContentRepository("$root/content", $markdown, $settings->load(), fn(): array => $types->catalogue());
$write = fn(string $dir, string $file = 'a.md') => (@mkdir("$root/content/$dir", 0775, true) | true) && file_put_contents("$root/content/$dir/$file", "---\ntitle: A\n---\n\nText\n");

// ---- the catalogue is what the theme defines
check('the theme ships posts, projects and books', array_values(array_intersect(['posts', 'projects', 'books'], $types->catalogue())), ['posts', 'projects', 'books']);
check('a type of the site\'s own is not in it', in_array('events', $types->catalogue(), true), false);
$books = $types->themeDefinition('books', 'en', 'en');
check('the Books type has its facts as fields', array_keys($books['fields']), ['author', 'publisher', 'year', 'isbn', 'language', 'buy_links', 'buy_url', 'buy_label']);
check('and an archive of cards, A to Z, filtered by category', [$books['archive']['layout'], $books['archive']['order'], $books['archive']['taxonomies']], ['cards', 'title_asc', ['categories']]);

// ---- which types are on
check('a new site has posts and projects, and a prebuilt type that is not asked for is not on', in_array('books', $repo()->getTypes(), true), false);
check('the ones every site starts with are on', array_values(array_intersect(['pages', 'posts', 'projects', 'forms'], $repo()->getTypes())), ['pages', 'posts', 'projects', 'forms']);
@mkdir("$root/content/books", 0775, true);
check('an empty folder does not switch a prebuilt type on', in_array('books', $repo()->getTypes(), true), false);
$write('books');
check('a prebuilt type that has files is on, as sites with such a folder have always been', in_array('books', $repo()->getTypes(), true), true);
$write('events');
check('a type of the site\'s own is on with its folder', in_array('events', $repo()->getTypes(), true), true);

// ---- switching on and off from the screen
exec('rm -rf ' . escapeshellarg("$root/content/books"));
$log = [];
$admin = new ContentTypeAdmin($types, "$root/content", fn() => ['categories'], fn(string $key) => false, function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = [$action, $id]; }, fn(string $type, bool $on): bool => $settings->setContentType($type, $on));
$manageable = fn() => array_values(array_diff($repo()->getTypes(), ['forms']));
$all = fn() => $admin->typeRows($manageable(), 'en');
$rows = function () use ($all): array { $r = array_filter(array_column($all(), 'enabled', 'type'), fn($v, $k) => $k !== 'pages' && $k !== 'events', ARRAY_FILTER_USE_BOTH); ksort($r); return $r; };
check('one list holds every type: the ones in use first, then the ready-made ones that are off', array_column($all(), 'type'), ['pages', 'posts', 'projects', 'events', 'books', 'businesses', 'points', 'routes']);
check('what is on and what is off', array_column($all(), 'enabled', 'type'), ['pages' => true, 'posts' => true, 'projects' => true, 'events' => true, 'books' => false, 'businesses' => false, 'points' => false, 'routes' => false]);
check('only the theme\'s types can be switched; pages and the site\'s own types are always on', array_column($all(), 'switchable', 'type'), ['pages' => false, 'posts' => true, 'projects' => true, 'events' => false, 'books' => true, 'businesses' => true, 'points' => true, 'routes' => true]);
$bookRow = array_values(array_filter($all(), fn($r) => $r['type'] === 'books'))[0];
check('a row has its label, what it is for, fields, files, layout and where it is defined', [$bookRow['label'], $bookRow['description'] !== '', $bookRow['fields'], $bookRow['items'], $bookRow['layout'], $bookRow['origin']], ['Books', true, 6, 0, 'cards', 'theme']);
check('switching books on goes back to the list and is logged', [$admin->toggle(['type' => 'books', 'enabled' => '1']), end($log)], ['/admin/content-types?toggled=on&type_name=books', ['content_types.enable', 'books']]);
check('now it is on even with no files', in_array('books', $repo()->getTypes(), true), true);
check('and it keeps its place in the list', array_slice($repo()->getTypes(), 0, 5), ['pages', 'posts', 'projects', 'forms', 'books']);
$write('books');
check('switching posts off', [$admin->toggle(['type' => 'posts', 'enabled' => '0']), in_array('posts', $repo()->getTypes(), true)], ['/admin/content-types?toggled=off&type_name=posts', false]);
check('its files are still there', is_dir("$root/content/posts"), true);
check('switching books off hides it though it has files', [$admin->toggle(['type' => 'books', 'enabled' => '0']), in_array('books', $repo()->getTypes(), true), is_file("$root/content/books/a.md")], ['/admin/content-types?toggled=off&type_name=books', false, true]);
check('a type that is off shows as off, and the others are unchanged', $rows(), ['books' => false, 'businesses' => false, 'points' => false, 'posts' => false, 'projects' => true, 'routes' => false]);
check('switching books on again brings it back with its files', [$admin->toggle(['type' => 'books', 'enabled' => '1']), count($repo()->getItems('books', null, true))], ['/admin/content-types?toggled=on&type_name=books', 1]);
check('switching posts on again', [$admin->toggle(['type' => 'posts', 'enabled' => '1']), in_array('posts', $repo()->getTypes(), true), array_key_exists('content_types_off', $settings->parse((string)(new SystemMetaRepository($db))->get('site_settings')))], ['/admin/content-types?toggled=on&type_name=posts', true, false]);
check('pages cannot be switched off', [$admin->toggle(['type' => 'pages', 'enabled' => '0']), in_array('pages', $repo()->getTypes(), true)], ['/admin/content-types?error=type', true]);
check('nor forms', $admin->toggle(['type' => 'forms', 'enabled' => '0']), '/admin/content-types?error=type');
check('a type of the site\'s own is not in the catalogue, so it cannot be switched here', $admin->toggle(['type' => 'events', 'enabled' => '0']), '/admin/content-types?error=type');
check('a name that is not a type is refused', $admin->toggle(['type' => '../x', 'enabled' => '1']), '/admin/content-types?error=type');
check('settings that cannot be written are reported', (new ContentTypeAdmin($types, "$root/content", fn() => [], fn() => false, fn() => null, fn() => false))->toggle(['type' => 'books', 'enabled' => '1']), '/admin/content-types?error=settings');
check('switching off in the settings: pages and forms are refused', [$settings->setContentType('pages', false), $settings->setContentType('forms', false), $settings->setContentType('Bad Name', true)], [false, false, false]);

// ---- the other settings are not disturbed
$before = $settings->load();
$settings->setContentType('projects', false);
$after = $settings->load();
check('only the lists of types changed', array_diff_key($before, ['content_types_off' => 1]) == array_diff_key($after, ['content_types_off' => 1]), true);
check('the off list holds what was switched off, and nothing is left from the defaults', $after['content_types_off'], ['projects']);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
