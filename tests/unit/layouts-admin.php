<?php
/*
 * The Single Layouts and Archive Layouts tabs of the Theme screen: the card of each content type and taxonomy, and saving
 * what the archive cards submit (only what changed is written, in the site's own files).
 *   php tests/unit/layouts-admin.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentRepository, ContentTypeAdmin, ContentTypes, LayoutsAdmin, Menus, RedirectRepository, SingleLayouts, SystemDatabase, Taxonomies, TaxonomyEditor, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/layadm' . getmypid();
foreach (['pages', 'posts', 'projects', 'forms', 'books'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/storage/db", 0775, true);
mkdir("$dir/custom", 0775, true);
symlink($repo . '/themes', "$dir/themes");
register_shutdown_function(function () use ($dir) { @exec('rm -rf ' . escapeshellarg($dir)); });
$settings = ['title' => 'Site', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'content_types' => ['books']];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$theme = new Theme($dir, 'default');
$types = new ContentTypes($theme);
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings, fn() => $types->catalogue());
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$taxonomies->ensureDefaults();
$menus = new Menus("$dir/content", fn() => $settings, fn(string $k, ?string $f = null) => $f ?? $k, fn() => []);
$log = [];
$typeAdmin = new ContentTypeAdmin($types, "$dir/content", fn() => $taxonomies->names(), fn(string $key) => in_array($key, ['title', 'slug', 'status'], true), function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = [$action, $id]; });
$editor = new TaxonomyEditor($taxonomies, new RedirectRepository($db), $content, fn() => $menus);
file_put_contents("$dir/content/posts/one.md", "---\ntitle: One\nstatus: published\n---\nBody\n");
$admin = new LayoutsAdmin($theme, new SingleLayouts($theme), $types, $typeAdmin, $taxonomies, $editor, fn() => $content->getTypes(), fn(string $t) => count(glob("$dir/content/$t/*.md") ?: []), fn(string $t) => rtrim($t, 's'));

// ---- single layout cards: one for each content type, whatever it is
$cards = $admin->singleCards(['single_layouts' => ['posts' => ['title' => 'split']]], 'el');
$byType = array_column($cards, null, 'type');
check('a card for every content type the site has, forms and pages included', array_keys($byType), $content->getTypes());
check('with its entries counted', $byType['posts']['count'], 1);
check('and the template that draws its page', [$byType['posts']['template_file'], $byType['pages']['template_file'], $byType['forms']['template_file']], ['single-post.twig', 'single-page.twig', 'single-form.twig']);
check('what it does now', $byType['posts']['values']['title'], 'split');
check('the standard pages can choose a page layout; forms cannot', [$byType['posts']['layouts'], $byType['forms']['layouts'], $byType['forms']['standard']], [true, false, true]);
check('only a template that has a line above the title offers to show it', [$byType['posts']['byline'], $byType['pages']['byline']], [true, false]);
check('the book has a page of its own: no title area to style', [$byType['books']['standard'], $byType['books']['layouts']], [false, false]);
check('forms have no fields to edit, the others link to them', [$byType['forms']['edit_url'], $byType['posts']['edit_url']], ['', 'admin/content-types?type=posts']);

// ---- a type of the site's own gets its card by itself
mkdir("$dir/content/events", 0775, true);
file_put_contents("$dir/content/events/gala.md", "---\ntitle: Gala\nstatus: published\n---\nBody\n");
$settings['content_types'][] = 'events';
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings, fn() => $types->catalogue());
$admin = new LayoutsAdmin($theme, new SingleLayouts($theme), $types, $typeAdmin, $taxonomies, $editor, fn() => $content->getTypes(), fn(string $t) => count(glob("$dir/content/$t/*.md") ?: []), fn(string $t) => rtrim($t, 's'));
$byType = array_column($admin->singleCards([], 'el'), null, 'type');
check('a new content type has a card with the defaults', [isset($byType['events']), $byType['events']['values']['title'], $byType['events']['template_file'], $byType['events']['standard']], [true, 'default', 'single.twig', true]);

// ---- archive cards
$a = $admin->archiveCards('el');
$cards = array_column($a['types'], null, 'type');
check('the lists of the content types, not pages and forms', array_keys($cards), ['books', 'events', 'posts']);
check('each with its settings and the orders it can use', [$cards['posts']['archive']['layout'], $cards['posts']['archive']['per_page'], array_keys($cards['posts']['orders'])], ['cards', 12, ['date_desc', 'date_asc', 'title_asc', 'title_desc', 'random']]);
check('the taxonomies have a card too, with the types they list', [array_column($a['taxonomies'], 'name'), array_column($a['taxonomies'], 'kind'), $a['taxonomies'][0]['listable']], [['categories', 'tags'], ['category', 'tag'], ['books', 'events', 'posts']]);
check('and the names of the types, for their chips', $a['type_labels']['posts'], 'Άρθρα');

// ---- saving archive cards
$failed = $admin->saveArchives(['posts' => ['layout' => 'magazine', 'columns' => '4', 'per_page' => '6', 'order' => 'title_asc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'taxonomies' => ['categories', 'tags']]], [], 'el');
check('a card is saved', $failed, []);
$written = $types->customRaw('posts')['archive'];
ksort($written);
check('only what differs from the theme is written', $written, ['columns' => '4', 'layout' => 'magazine', 'order' => 'title_asc', 'per_page' => 6]);
check('and it is what the archive uses', (new ContentTypes($theme))->definition('posts', 'el', 'el')['archive']['layout'], 'magazine');
check('and logged', end($log), ['content_types.update', 'posts']);
$file = "$dir/custom/content-types/posts.yaml";
$before = filemtime($file);
clearstatcache();
touch($file, $before - 100);
$admin->saveArchives(['posts' => ['layout' => 'magazine', 'columns' => '4', 'per_page' => '6', 'order' => 'title_asc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'taxonomies' => ['categories', 'tags']]], [], 'el');
clearstatcache();
check('a card that did not change writes nothing', filemtime($file), $before - 100);
$admin->saveArchives(['posts' => ['layout' => 'cards', 'columns' => '3', 'per_page' => '12', 'order' => 'date_desc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'taxonomies' => ['categories', 'tags']]], [], 'el');
check('going back to the theme\'s values removes the file', is_file($file), false);
check('pages, forms and types that do not exist are left alone', $admin->saveArchives(['pages' => ['layout' => 'list'], 'forms' => ['layout' => 'list'], 'nothing' => ['layout' => 'list']], [], 'el'), []);
check('and nothing was written for them', glob("$dir/custom/content-types/*.yaml") ?: [], []);
check('an unknown layout keeps the theme\'s', (function () use ($admin, $types) { $admin->saveArchives(['projects' => ['layout' => 'nonsense', 'columns' => '9', 'per_page' => '12', 'order' => 'date_desc']], [], 'el'); return $types->customRaw('projects')['archive']['layout'] ?? 'theme'; })(), 'theme');

// ---- taxonomies
$admin->saveArchives([], ['tags' => ['layout' => 'list', 'columns' => '3', 'per_page' => '5', 'order' => 'date_desc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'types' => ['posts'], 'title' => 'Articles about {term}']], 'el');
$tags = $taxonomies->archive('tags', 'el', 'el');
check('a taxonomy card is saved in the taxonomy file', [$tags['layout'], $tags['per_page'], $tags['types'], $tags['title']], ['list', 5, ['posts'], 'Articles about {term}']);
check('and its name is as it was', $taxonomies->load('tags')['title'], 'Tags');
$admin->saveArchives([], ['tags' => ['layout' => 'cards', 'columns' => '3', 'per_page' => '12', 'order' => 'date_desc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'types' => ['posts', 'books', 'events'], 'title' => '']], 'el');
check('every type ticked stores no list of types, and the defaults store nothing', $taxonomies->load('tags')['archive'], []);
$admin->saveArchives([], ['nothing' => ['layout' => 'list']], 'el');
check('a taxonomy that does not exist is not made', in_array('nothing', $taxonomies->names(), true), false);

// ---- a page of its own can still have what its content type declares
$byType = array_column($admin->singleCards(['single_layouts' => ['books' => ['options' => ['cover' => 'right']]]], 'el'), null, 'type');
check('the book card has a sidebar and a header choice and its options, with what is set', [$byType['books']['sidebar'], $byType['books']['header'], array_keys($byType['books']['options']), $byType['books']['values']['options']['cover']], [true, true, ['cover', 'cover_style', 'show_author', 'show_summary', 'show_facts', 'show_buy'], 'right']);
check('but not the page layout and the title area of a standard page', [$byType['books']['standard'], $byType['books']['layouts']], [false, false]);
check('a standard page has all its choices and no options it has not declared', [$byType['posts']['sidebar'], $byType['posts']['header'], $byType['posts']['options']], [true, true, []]);
check('a form has a header choice but no sidebar', [$byType['forms']['sidebar'], $byType['forms']['header']], [false, true]);
$a = $admin->archiveCards('el');
$booksList = array_column($a['types'], null, 'type')['books'];
check('the list card of books has the option its type declares for the list, with its value', [array_keys($booksList['options']), $booksList['archive']['options']], [['cover_shape'], ['cover_shape' => 'portrait']]);
check('the list of posts has none', array_column($a['types'], null, 'type')['posts']['options'], []);
// saving: only what differs from the theme is written to the site's file
$admin->saveArchives(['books' => ['layout' => 'cards', 'columns' => '4', 'per_page' => '12', 'order' => 'title_asc', 'show_image' => '1', 'show_date' => '1', 'taxonomies' => ['categories'], 'options' => ['cover_shape' => 'square']]], [], 'el');
check('a list option that differs is written to the type\'s own file', str_contains((string)@file_get_contents("$dir/custom/content-types/books.yaml"), 'cover_shape: square'), true);
$admin->saveArchives(['books' => ['layout' => 'cards', 'columns' => '4', 'per_page' => '12', 'order' => 'title_asc', 'show_image' => '1', 'show_date' => '1', 'taxonomies' => ['categories'], 'options' => ['cover_shape' => 'portrait']]], [], 'el');
check('and removed again when it is the theme\'s', is_file("$dir/custom/content-types/books.yaml") ? str_contains((string)file_get_contents("$dir/custom/content-types/books.yaml"), 'cover_shape') : false, false);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
