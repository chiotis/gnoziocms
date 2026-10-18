<?php
/*
 * What the Taxonomies screen does with a submitted form: reading the rows, saving terms and layout, a moved address
 * leaving redirects and updating menu links, and counting the entries filed under each term.
 *   php tests/unit/taxonomy-editor.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentRepository, Menus, RedirectRepository, SystemDatabase, Taxonomies, TaxonomyEditor};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/taxed' . getmypid();
foreach (['pages', 'posts', 'projects', 'forms'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/storage/db", 0775, true);
$settings = ['title' => 'Site', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index'];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$redirects = new RedirectRepository($db);
$store = new Taxonomies("$dir/content", ['el', 'en']);
$store->ensureDefaults();
$store->save('tags', 'Tags', [
    ['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']],
    ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']],
    ['id' => 'unused', 'slug' => 'unused', 'labels' => ['el' => 'Άχρηστο', 'en' => 'Unused']],
]);
$menus = new Menus("$dir/content", fn() => $settings, fn(string $k, ?string $f = null) => $f ?? $k, fn() => []);
$menus->write('main', ['title' => 'Main', 'items' => [['label' => 'News', 'url' => 'tag/news'], ['label' => 'Home', 'url' => 'index']]]);
$editor = new TaxonomyEditor($store, $redirects, $content, fn() => $menus);

file_put_contents("$dir/content/posts/one.md", "---\ntitle: One\nstatus: published\ntags: [news, design]\ncategories: [insights]\n---\nBody\n");
file_put_contents("$dir/content/posts/one.en.md", "---\ntitle: One EN\nstatus: draft\ntags: [news]\n---\nBody\n");
file_put_contents("$dir/content/projects/p.md", "---\ntitle: P\nstatus: published\ntags: news\n---\nBody\n");
file_put_contents("$dir/content/pages/about.md", "---\ntitle: About\nstatus: published\ntags: [news]\n---\nBody\n");
file_put_contents("$dir/content/forms/contact.md", "---\ntitle: Contact\ntags: [news]\n---\nBody\n");

// ---- a new taxonomy
file_put_contents("$dir/content/pages/showcase.md", "---\ntitle: Showcase\nstatus: published\n---\nBody\n");
file_put_contents("$dir/content/posts/launch.md", "---\ntitle: Launch\nstatus: published\n---\nBody\n");
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$editor = new TaxonomyEditor($store, $redirects, $content, fn() => $menus);
$made = $editor->create('Project types', '', ['projects', 'nonsense', 'pages'], ['el', 'en'], 'index', 'tester');
check('a name makes the address and the file', [$made, is_file("$dir/content/taxonomies/project-types.yaml"), $store->names()], [['name' => 'project-types', 'error' => ''], true, ['categories', 'project-types', 'tags']]);
$loaded = $store->load('project-types');
check('with its title, no terms yet, and only the content types that can be listed', [$loaded['title'], $loaded['terms'], $loaded['archive']], ['Project types', [], ['types' => ['projects']]]);
check('a Greek name becomes a Latin address', $editor->create('Κλάδοι', '', [], ['el', 'en'], 'index', 'tester'), ['name' => 'kladoi', 'error' => '']);
check('an address of its own is used', $editor->create('Industries', 'sectors', [], ['el', 'en'], 'index', 'tester')['name'], 'sectors');
check('none chosen lists every type, and writes no archive choice', $store->load('sectors')['archive'], []);
$refused = fn(string $title, string $name) => $editor->create($title, $name, [], ['el', 'en'], 'index', 'tester')['error'] !== '';
check('a name is needed', $refused('', 'x1'), true);
check('an address with nothing to make it from is refused', $refused('!!!', ''), true);
check('so is one that starts with a number, is one letter, or has odd characters', [$refused('A', 'x'), $refused('A', '1abc'), $refused('A', '!!')], [true, true, true]);
check('a word the site uses is refused: reserved, a language, a content type, a taxonomy, the home page, a page', [
    $refused('A', 'admin'), $refused('A', 'en'), $refused('A', 'posts'), $refused('A', 'sectors'), $refused('A', 'tags'), $refused('A', 'index'), $refused('A', 'showcase'),
], [true, true, true, true, true, true, true]);
check('an address a post has is refused, as one a page has', $editor->create('A', 'launch', [], ['el', 'en'], 'index', 'tester')['error'], 'There is a post at /launch. Choose another address, or rename it first.');
check('a different home page name is refused too', $editor->create('A', 'welcome', [], ['el', 'en'], 'welcome', 'tester')['error'] !== '', true);
check('and nothing is written when refused', $store->names(), ['categories', 'kladoi', 'project-types', 'sectors', 'tags']);

// ---- deleting one
$store->save('kladoi', 'Κλάδοι', [['id' => 'a', 'slug' => 'a', 'labels' => ['el' => 'Α', 'en' => 'A']]]);
file_put_contents("$dir/content/posts/two.md", "---\ntitle: Two\nstatus: published\nkladoi: [a]\n---\nBody\n");
$gone = $editor->delete('kladoi', 'tester');
check('a taxonomy of the site\'s own is deleted, and says how many entries were filed under it', [$gone, is_file("$dir/content/taxonomies/kladoi.yaml"), in_array('kladoi', $store->names(), true)], [['ok' => true, 'title' => 'Κλάδοι', 'filed' => 1], false, false]);
check('the entries keep what they had', str_contains((string)file_get_contents("$dir/content/posts/two.md"), 'kladoi: [a]'), true);
check('categories and tags cannot be deleted', [$editor->delete('tags', 't')['ok'], $editor->delete('categories', 't')['ok'], is_file("$dir/content/taxonomies/tags.yaml"), is_file("$dir/content/taxonomies/categories.yaml")], [false, false, true, true]);
check('nor one that does not exist', [$editor->delete('nothing', 't')['ok'], $editor->delete('../tags', 't')['ok'], $editor->delete('', 't')['ok']], [false, false, false]);
check('a taxonomy has to be made first: Taxonomies::delete leaves the built in files alone too', [$store->delete('tags'), is_file("$dir/content/taxonomies/tags.yaml")], [false, true]);

// ---- counting
$u = $editor->usage(['tags', 'categories']);
check('every language and draft is counted, forms are not', $u['tags'], ['news' => 4, 'design' => 1]);
check('one pass covers every taxonomy', $u['categories'], ['insights' => 1]);
$by = $editor->usage(['tags'], true)['tags'];
check('and can be split by content type', $by['news'], ['pages' => 1, 'posts' => 2, 'projects' => 1]);
check('types that can be listed leave out pages and forms', $editor->listableTypes(), ['posts', 'projects']);

// ---- reading the form
$rows = $editor->rowsFromPost(['term_id' => ['news', ''], 'term_slug' => ['news', ''], 'term_label' => ['el' => ['Νέα', 'Νέο'], 'en' => ['News', ' ']], 'term_description' => ['el' => ['Περιγραφή', ''], 'en' => ['', '']]], ['el', 'en']);
check('a row per term, in order, with trimmed labels', [$rows[0]['id'], $rows[1]['id'], $rows[1]['labels']], ['news', '', ['el' => 'Νέο', 'en' => '']]);
check('descriptions are read per language', [$rows[0]['descriptions'], $rows[1]['descriptions']], [['el' => 'Περιγραφή', 'en' => ''], ['el' => '', 'en' => '']]);
$rows = $editor->rowsFromPost(['term_id' => ['news'], 'term_slug' => ['news'], 'term_label' => ['el' => ['Νέα'], 'en' => ['News']]], ['el', 'en']);
check('no description boxes at all means leave them alone', $rows[0]['descriptions'], null);
check('junk in the form is harmless', $editor->rowsFromPost(['term_id' => 'x', 'term_label' => 'y'], ['el']), []);

// ---- saving
$post = [
    'taxonomy_title' => 'Topics',
    'term_id' => ['news', 'design', ''], 'term_slug' => ['nea', 'design', ''],
    'term_label' => ['el' => ['Νέα', 'Σχεδιασμός', 'Νέος όρος'], 'en' => ['News', 'Design', '']],
    'term_description' => ['el' => ['Όλα τα νέα', '', ''], 'en' => ['', '', '']],
    'archive' => ['layout' => 'list', 'per_page' => '5', 'types' => ['posts']],
];
$r = $editor->apply('tags', $post, ['el', 'en'], 'el', 'tester');
check('the summary counts what happened', $r, ['title' => 'Topics', 'terms' => 3, 'moved' => 1, 'removed' => 1, 'added' => 1, 'orphaned' => 0, 'refused' => 0]);
$saved = $store->load('tags');
check('title and terms are stored, in the order sent', [$saved['title'], array_column($saved['terms'], 'id')], ['Topics', ['news', 'design', 'neos-oros']]);
check('the description is stored', $saved['terms'][0]['descriptions']['el'], 'Όλα τα νέα');
check('the layout is stored, only what differs from the defaults', [$saved['archive']['layout'], $saved['archive']['types']], ['list', ['posts']]);
$rd = [];
foreach ($redirects->all() as $row) { $rd[$row['source']] = $row['target']; }
check('a moved address leaves a redirect in each language', [$rd['tag/news'] ?? null, $rd['en/tag/news'] ?? null], ['/tag/nea', '/en/tag/nea']);
check('the menu link follows', $menus->load('main')['items'][0]['url'], 'tag/nea');

// ---- removing a term that entries use reports them
$r = $editor->apply('tags', ['taxonomy_title' => 'Topics', 'term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']], 'archive' => ['types' => ['posts', 'projects']]], ['el', 'en'], 'el', 'tester');
check('removed terms and the entries still filed under them', [$r['removed'], $r['orphaned']], [2, 4]);
check('choosing every type stores no type list', isset($store->load('tags')['archive']['types']), false);
$editor->apply('tags', ['taxonomy_title' => 'Topics', 'term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']], 'archive' => ['layout' => 'magazine', 'per_page' => '12']], ['el', 'en'], 'el', 'tester');
$editor->apply('tags', ['taxonomy_title' => 'Topics', 'term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']]], ['el', 'en'], 'el', 'tester');
check('a form without the layout part (it is edited in Theme > Archive Layouts) leaves it alone', $store->load('tags')['archive']['layout'] ?? null, 'magazine');
check('a form without a title keeps the title', $editor->apply('tags', ['term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']]], ['el', 'en'], 'el', 't')['title'], 'Topics');


// ---- a tree: moving a term or renaming a parent leaves redirects for everything below
$lbl = fn(string $en) => ['el' => $en, 'en' => $en];
$cat = fn(array $ids, array $slugs, array $parents, array $names, array $extra = []) => ['term_id' => $ids, 'term_slug' => $slugs, 'term_parent' => $parents, 'term_label' => ['el' => $names, 'en' => $names]] + $extra;
$editor->apply('categories', $cat(['books', 'english', 'classics', 'plays'], ['books', 'english', 'classics', 'plays'], ['', 'books', 'english', ''], ['Books', 'English', 'Classics', 'Plays']), ['el', 'en'], 'el', 'tester');
check('a form can give a term a parent, and the address holds the parents\'', array_map(fn($id) => $store->slug('categories', $id), ['books', 'english', 'classics', 'plays']), ['books', 'books/english', 'books/english/classics', 'plays']);
$menus->write('main', ['title' => 'Main', 'items' => [['label' => 'English', 'url' => 'category/books/english'], ['label' => 'Home', 'url' => 'index']]]);
$before = count($redirects->all());
$r = $editor->apply('categories', $cat(['books', 'english', 'classics', 'plays'], ['books', 'english', 'classics', 'plays'], ['', 'plays', 'english', ''], ['Books', 'English', 'Classics', 'Plays']), ['el', 'en'], 'el', 'tester');
$rd = [];
foreach ($redirects->all() as $row) { $rd[$row['source']] = $row['target']; }
check('moving a term counts it and what is below it', [$r['moved'], $r['refused']], [2, 0]);
check('the old addresses of the term and its descendants lead to the new, in each language', [$rd['category/books/english'] ?? null, $rd['category/books/english/classics'] ?? null, $rd['en/category/books/english'] ?? null, $rd['en/category/books/english/classics'] ?? null], ['/category/plays/english', '/category/plays/english/classics', '/en/category/plays/english', '/en/category/plays/english/classics']);
check('the menu link follows', $menus->load('main')['items'][0]['url'], 'category/plays/english');
$r = $editor->apply('categories', $cat(['books', 'english', 'classics', 'plays'], ['library', 'english', 'classics', 'plays'], ['', 'plays', 'english', ''], ['Books', 'English', 'Classics', 'Plays']), ['el', 'en'], 'el', 'tester');
$rd = [];
foreach ($redirects->all() as $row) { $rd[$row['source']] = $row['target']; }
check('renaming a parent with nothing below it redirects only itself', [$r['moved'], $rd['category/books'] ?? null], [1, '/category/library']);
$r = $editor->apply('categories', $cat(['plays', 'english', 'classics', 'library'], ['plays', 'english', 'classics', 'library'], ['', 'plays', 'english', ''], ['Plays', 'English', 'Classics', 'Library']), ['el', 'en'], 'el', 'tester');
$r = $editor->apply('categories', $cat(['plays', 'english', 'classics', 'library'], ['plays', 'english', 'classics', 'library'], ['classics', 'plays', 'english', ''], ['Plays', 'English', 'Classics', 'Library']), ['el', 'en'], 'el', 'tester');
check('a loop is refused and said so; nothing moves', [$r['refused'], $r['moved'], $store->slug('categories', 'plays')], [1, 0, 'plays']);
$r = $editor->apply('categories', $cat(['plays', 'classics', 'library'], ['plays', 'classics', 'library'], ['', 'english', ''], ['Plays', 'Classics', 'Library']), ['el', 'en'], 'el', 'tester');
$rd = [];
foreach ($redirects->all() as $row) { $rd[$row['source']] = $row['target']; }
check('deleting a parent moves its children up a level, with a redirect', [$store->slug('categories', 'classics'), $r['removed'], $rd['category/plays/english/classics'] ?? null], ['plays/classics', 1, '/category/plays/classics']);
$screen = $editor->screen('categories', [], ['el', 'en'], 'el', true, fn($t) => $t);
check('the screen lists a tree in tree order, with depth and address', [$screen['hierarchical'], array_map(fn($t) => $t['id'] . ':' . $t['depth'] . ':' . $t['path'], $screen['taxonomy_terms'])], [true, ['plays:0:plays', 'classics:1:plays/classics', 'library:0:library']]);
$screen = $editor->screen('tags', [], ['el', 'en'], 'el', true, fn($t) => $t);
check('and a flat taxonomy is listed as before', [$screen['hierarchical'], array_column($screen['taxonomy_terms'], 'depth')], [false, [0]]);
$editor->apply('categories', ['taxonomy_title' => 'Categories', 'term_id' => ['plays'], 'term_slug' => ['plays'], 'term_label' => ['el' => ['Plays'], 'en' => ['Plays']], 'taxonomy_hierarchical' => '0'], ['el', 'en'], 'el', 'tester');
check('the tree can be turned off for categories', [$store->isHierarchical('categories'), str_contains((string)file_get_contents("$dir/content/taxonomies/categories.yaml"), 'hierarchical: false')], [false, true]);
$editor->apply('categories', ['taxonomy_title' => 'Categories', 'term_id' => ['plays'], 'term_slug' => ['plays'], 'term_label' => ['el' => ['Plays'], 'en' => ['Plays']], 'taxonomy_hierarchical' => '1'], ['el', 'en'], 'el', 'tester');
check('and on again; a form that does not send the choice leaves it', [$store->isHierarchical('categories'), $editor->apply('categories', ['term_id' => ['plays'], 'term_slug' => ['plays'], 'term_label' => ['el' => ['Plays'], 'en' => ['Plays']]], ['el', 'en'], 'el', 't')['terms'], $store->isHierarchical('categories')], [true, 1, true]);
$archive = $editor->archiveFromInput('categories', ['layout' => 'list', 'children_shown' => '1']);
check('the archive card stores "no sub-terms" when its chip is off, and nothing when it is on', [$archive['include_children'] ?? 'default', $editor->archiveFromInput('categories', ['children_shown' => '1', 'include_children' => '1'])['include_children'] ?? 'default'], [false, 'default']);
check('a form that did not draw the chip leaves the choice alone', $editor->archiveFromInput('categories', ['layout' => 'list'])['include_children'] ?? 'default', 'default');
$store->save('categories', 'Categories', $store->load('categories')['terms'], ['include_children' => false]);
check('and keeps a choice already made', $editor->archiveFromInput('categories', ['layout' => 'list'])['include_children'] ?? 'default', false);
$made = $editor->create('Regions', '', [], ['el', 'en'], 'index', 'tester', true);
check('a new taxonomy can be a tree from the start', [$made['error'], $store->isHierarchical('regions')], ['', true]);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
