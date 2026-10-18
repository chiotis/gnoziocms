<?php
/*
 * Content as CSV: reading a file, the preview of an import (what is created, changed, skipped, or wrong), applying it
 * (with backups and rollback), and the export. Also the two small helpers they share.
 *   php tests/unit/content-csv.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ArrayPath, ContentCsv, ContentRepository, Format, HtmlGuard, RevisionRepository, SystemDatabase};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- the small helpers
check('a list from an array drops empties', Format::list(['a', '', 'b']), ['a', 'b']);
check('a list from comma text is trimmed', Format::list('a, b ,c'), ['a', 'b', 'c']);
check('one value is a list of one', Format::list('solo'), ['solo']);
check('nothing is an empty list', [Format::list(null), Format::list('')], [[], []]);
check('a comma list drops empty entries', Format::commaList(' a,, b ,'), ['a', 'b']);
check('an empty comma list', Format::commaList('  '), []);

$data = ['seo' => ['title' => 'T']];
check('a path is read', ArrayPath::get($data, ['seo', 'title']), 'T');
check('a missing step gives null', ArrayPath::get($data, ['seo', 'nope', 'x']), null);
ArrayPath::set($data, ['a', 'b', 'c'], 1);
check('a path is written, making the steps', $data['a']['b']['c'], 1);
ArrayPath::set($data, ['seo', 'title'], 'New');
check('and overwritten', $data['seo']['title'], 'New');
ArrayPath::set($data, ['', 'x'], 1);
check('an empty step writes nothing', array_key_exists('', $data), false);
ArrayPath::unset($data, ['seo', 'title']);
check('a path is removed', isset($data['seo']['title']), false);
ArrayPath::unset($data, ['nothing', 'here']);
check('removing what is not there changes nothing', array_keys($data), ['seo', 'a']);

// ---- a small site
$dir = sys_get_temp_dir() . '/csv' . getmypid();
foreach (['pages', 'posts'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/storage/db", 0775, true);
$settings = ['title' => 'Site', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index'];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$markdown = new MarkdownConverter($environment);
$content = new ContentRepository("$dir/content", $markdown, $settings);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$revisions = new RevisionRepository($db);
$rawHtml = true;
$mayRaw = function () use (&$rawHtml) { return $rawHtml; };
$make = fn() => new ContentCsv(
    $content, "$dir/content", fn() => $settings, $revisions,
    fn() => new HtmlGuard(fn() => $environment, fn() => throw new RuntimeException('no blocks here')),
    fn(string $d) => $d, fn() => 'tester', $mayRaw
);
$csv = $make();

file_put_contents("$dir/content/pages/about.md", "---\ntitle: About\nstatus: published\nvisible: true\ntranslation_id: aaaaaaaaaaaaaaaa\nseo:\n  title: Old SEO\n  description: Keep me\ncustom_note: hello\nblocks:\n  - type: text\n    body: Block text\n---\n\nAbout body.\n");
file_put_contents("$dir/content/pages/about.en.md", "---\ntitle: About EN\nstatus: published\ntranslation_id: aaaaaaaaaaaaaaaa\n---\n\nEnglish body.\n");
file_put_contents("$dir/content/pages/other.md", "---\ntitle: Other\ntranslation_id: bbbbbbbbbbbbbbbb\n---\n\nx\n");
file_put_contents("$dir/content/posts/hello.md", "---\ntitle: Hello\nstatus: published\ndate: '2026-03-04'\nauthor: Jane\ntags:\n  - one\n  - two\ncategories:\n  - news\nmain_image: /uploads/media/a.jpg\nexcerpt: Short\nfeatures:\n  - x\n  - y\ncustom_fields:\n  editions:\n    - title: 'First, \"quoted\"'\n      shops:\n        - label: Shop A\n          url: 'https://a.example/b?c=1'\n        - label: Κατάστημα\n    - title: Second\n---\n\nPost body\n");

$write = function (string $text) use ($dir): string { $p = $dir . '/in' . mt_rand() . '.csv'; file_put_contents($p, $text); return $p; };

// ---- reading a file
$p = $csv->parseImport($write("content_type,language,slug,title,body\r\npages,el,new-page,  Spaced Title  ,\"Line one\n\nLine two  \"\r\n\r\n,,,,\r\n"));
check('a file is read into rows', [$p['ok'], count($p['rows'])], [true, 1]);
check('headers are as they are', $p['headers'], ['content_type', 'language', 'slug', 'title', 'body']);
check('values are trimmed, but not the body', [$p['rows'][0]['title'], $p['rows'][0]['body']], ['Spaced Title', "Line one\n\nLine two  "]);
$p = $csv->parseImport($write("Content Type;Language;META.Foo Bar\npages;el;x\n"));
check('a semicolon separated file is understood', [$p['ok'], $p['rows'][0]['language'] ?? null], [true, 'el']);
check('headers are lower case with underscores', $p['headers'], ['content_type', 'language', 'meta.foo_bar']);
$p = $csv->parseImport($write("\xEF\xBB\xBFlanguage,slug\nel,a\n"));
check('a byte order mark is ignored', $p['headers'], ['language', 'slug']);
check('a file with no language column is refused', $csv->parseImport($write("slug,title\na,b\n")), ['ok' => false, 'error' => 'CSV must contain a language column.']);
check('an empty file is refused', $csv->parseImport($write('')), ['ok' => false, 'error' => 'CSV is empty.']);
$p = $csv->parseImport($write("language,slug\nel,a\nel\n"));
check('a short row is padded with empty values', $p['rows'][1], ['language' => 'el', 'slug' => '']);

// ---- the preview
$headers = ['content_type', 'language', 'slug', 'title', 'status', 'visible', 'date', 'author', 'tags', 'categories', 'translation_id', 'main_image', 'excerpt', 'body', 'meta.seo.title', 'meta.seo.description', 'meta.extra', 'meta.list'];
$row = fn(array $over) => $over + array_fill_keys($headers, '');
$preview = $csv->preview('pages', [
    $row(['content_type' => 'pages', 'language' => 'el', 'slug' => 'about', 'title' => 'About changed', 'body' => 'New body', 'meta.seo.title' => 'New SEO', 'meta.seo.description' => '', 'meta.extra' => 'true', 'meta.list' => '["a","b"]']),
    $row(['content_type' => 'pages', 'language' => 'el', 'slug' => 'brand-new', 'title' => 'Brand New', 'tags' => 'Ignored Tag', 'visible' => 'no', 'body' => 'x']),
    $row(['content_type' => 'posts', 'language' => 'el', 'slug' => 'wrong-type']),
    $row(['content_type' => 'pages', 'language' => 'fr', 'slug' => 'wrong-language']),
    $row(['content_type' => 'pages', 'language' => 'el', 'slug' => '', 'title' => '']),
    $row(['content_type' => 'pages', 'language' => 'el', 'slug' => 'Brand New', 'title' => 'Twice']),
    $row(['content_type' => 'pages', 'language' => 'en', 'slug' => 'other-slug', 'translation_id' => 'aaaaaaaaaaaaaaaa', 'title' => 'By translation']),
    $row(['content_type' => 'pages', 'language' => 'en', 'slug' => 'about', 'translation_id' => 'aaaaaaaaaaaaaaaa']),
    $row(['content_type' => 'pages', 'language' => 'el', 'slug' => '', 'title' => 'Slug From Title']),
], $headers);
$actions = array_column($preview['rows'], 'action');
check('every row has an action, in order', $actions, ['update', 'create', 'error', 'error', 'error', 'error', 'update', 'update', 'create']);
check('the summary counts them', $preview['summary'], ['create' => 2, 'update' => 3, 'skip' => 0, 'error' => 4]);
check('lines are numbered as in the file (header is line 1)', array_column($preview['rows'], 'line'), [2, 3, 4, 5, 6, 7, 8, 9, 10]);
check('a type that is not the one imported is named', $preview['rows'][2]['message'], 'content_type mismatch: expected pages, got posts.');
check('a language the site does not have is refused', $preview['rows'][3]['message'], 'Invalid language.');
check('a row with no slug and no title is refused', $preview['rows'][4]['message'], 'Missing slug/title.');
check('the same slug and language twice is refused', $preview['rows'][5]['message'], 'Duplicate target slug+language in CSV.');
check('a slug is made from the title when there is none', $preview['rows'][8]['slug'], 'slug-from-title');
check('an existing item is found by translation id in another language', $preview['rows'][6]['action'], 'update');
$entries = [];
foreach ($preview['entries'] as $e) { $entries[$e['slug'] . '|' . $e['lang']] = $e; }
$about = $entries['about|el'];
check('an update knows the file it replaces and the time it was in', [basename($about['old_path']), basename($about['new_path']), $about['old_mtime'] > 0], ['about.md', 'about.md', true]);
check('a field in a column is set', $about['data']['title'], 'About changed');
check('a nested field is set by its path', $about['data']['seo']['title'], 'New SEO');
check('an empty cell removes that field', isset($about['data']['seo']['description']), false);
check('a field without a column is left as it was', [$about['data']['custom_note'], $about['data']['blocks'][0]['body'], $about['data']['translation_id']], ['hello', 'Block text', 'aaaaaaaaaaaaaaaa']);
check('"true" becomes a real boolean, a JSON list a real list', [$about['data']['extra'], $about['data']['list']], [true, ['a', 'b']]);
check('the body is the one in the file', $about['body'], 'New body');
$new = $entries['brand-new|el'];
check('a new page gets a new file name and a translation id', [basename($new['new_path']), $new['old_path'], preg_match('/^[0-9a-f]{16}$/', $new['data']['translation_id'])], ['brand-new.md', '', 1]);
check('visible "no" is false, tags are not kept on pages', [$new['data']['visible'], isset($new['data']['tags'])], [false, false]);
check('a new item has a title, status, and visibility', [$new['data']['title'], $new['data']['status']], ['Brand New', 'published']);
$en = $entries['other-slug|en'];
check('a change of slug in another language points at the old file and the new one', [basename($en['old_path']), basename($en['new_path']), $en['old_slug']], ['about.en.md', 'other-slug.en.md', 'about']);
$conflict = $csv->preview('pages', [$row(['content_type' => 'pages', 'language' => 'el', 'slug' => 'about', 'translation_id' => 'aaaaaaaaaaaaaaaa']), $row(['content_type' => 'pages', 'language' => 'en', 'slug' => 'about', 'translation_id' => 'aaaaaaaaaaaaaaaa'])], $headers);
check('the same address and translation in two languages are two updates', array_column($conflict['rows'], 'action'), ['update', 'update']);
$clash = $csv->preview('pages', [$row(['content_type' => 'pages', 'language' => 'el', 'slug' => 'other', 'translation_id' => 'aaaaaaaaaaaaaaaa'])], $headers);
check('a translation id and a slug that point at two items are a conflict', $clash['rows'][0]['message'], 'Conflict: translation_id and slug point to different items.');

// posts: tags and categories become slugs, a date is normalised through the site
$posts = $csv->preview('posts', [$row(['content_type' => 'posts', 'language' => 'el', 'slug' => 'hello', 'title' => 'Hello', 'tags' => 'Two Words, Second Tag ,', 'categories' => 'News', 'date' => '2026-05-06', 'author' => 'Sam', 'main_image' => '/x.jpg', 'excerpt' => 'Ex'])], $headers);
$post = $posts['entries'][0]['data'];
check('tags and categories are stored as address-safe names', [$post['tags'], $post['categories']], [['two-words', 'second-tag'], ['news']]);
check('date, author, image, and excerpt come from their columns', [$post['date'], $post['author'], $post['main_image'], $post['excerpt']], ['2026-05-06', 'Sam', '/x.jpg', 'Ex']);
$cleared = $csv->preview('posts', [$row(['content_type' => 'posts', 'language' => 'el', 'slug' => 'hello', 'title' => 'Hello'])], $headers);
check('empty cells clear the optional fields', [isset($cleared['entries'][0]['data']['date']), isset($cleared['entries'][0]['data']['author']), isset($cleared['entries'][0]['data']['tags']), isset($cleared['entries'][0]['data']['excerpt'])], [false, false, false, false]);

// ---- an export read back changes nothing
$items = $content->getItems('posts', null, true, false);
$table = $csv->exportTable('posts', $items, 'Site');
check('the export starts with the fixed columns', array_slice($table['headers'], 0, 16), ['site_title', 'content_type', 'language', 'slug', 'title', 'status', 'visible', 'date', 'author', 'tags', 'categories', 'translation_id', 'main_image', 'excerpt', 'updated_at', 'body']);
check('and the other fields follow, sorted, lists as JSON', array_slice($table['headers'], 16), ['meta.custom_fields.editions', 'meta.features']);
$r = array_combine($table['headers'], $table['rows'][0]);
check('a row has the values', [$r['site_title'], $r['content_type'], $r['language'], $r['slug'], $r['title'], $r['visible'], $r['date'], $r['author'], $r['tags'], $r['categories'], $r['excerpt'], $r['meta.features']], ['Site', 'posts', 'el', 'hello', 'Hello', 'true', '2026-03-04', 'Jane', 'one, two', 'news', 'Short', '["x","y"]']);
check('and the body is the Markdown', trim($r['body']), 'Post body');
check('a repeater with rows inside its rows is one JSON cell', json_decode($r['meta.custom_fields.editions'], true), [['title' => 'First, "quoted"', 'shops' => [['label' => 'Shop A', 'url' => 'https://a.example/b?c=1'], ['label' => 'Κατάστημα']]], ['title' => 'Second']]);
check('every row is as long as the header', array_unique(array_map('count', $table['rows'])), [count($table['headers'])]);
$out = fopen($dir . '/export.csv', 'w'); fputcsv($out, $table['headers'], ',', '"', ''); foreach ($table['rows'] as $line) { fputcsv($out, $line, ',', '"', ''); } fclose($out);
$again = $csv->parseImport($dir . '/export.csv');
$back = $csv->preview('posts', $again['rows'], $again['headers']);
check('an import of an export is an update of the file the first time (it is written in the site\'s own layout)', $back['summary'], ['create' => 0, 'update' => 1, 'skip' => 0, 'error' => 0]);
$csv->apply('posts', $back['entries']);
$content = new ContentRepository("$dir/content", $markdown, $settings);
$csv = new ContentCsv($content, "$dir/content", fn() => $settings, $revisions, fn() => new HtmlGuard(fn() => $environment, fn() => throw new RuntimeException('no blocks here')), fn(string $d) => $d, fn() => 'tester', $mayRaw);
$table = $csv->exportTable('posts', $content->getItems('posts', null, true, false), 'Site');
$out = fopen($dir . '/export2.csv', 'w'); fputcsv($out, $table['headers'], ',', '"', ''); foreach ($table['rows'] as $line) { fputcsv($out, $line, ',', '"', ''); } fclose($out);
$again = $csv->parseImport($dir . '/export2.csv');
check('and the second time nothing changes at all', $csv->preview('posts', $again['rows'], $again['headers'])['summary'], ['create' => 0, 'update' => 0, 'skip' => 1, 'error' => 0]);
$hello = $content->getItems('posts', null, true, false)[0];
check('the nested rows come back the same, written as block YAML', [$hello->meta['custom_fields']['editions'][0]['shops'][1]['label'] ?? null, (bool)preg_match('/^          label: \'Shop A\'$/m', (string)file_get_contents("$dir/content/posts/hello.md"))], ['Κατάστημα', true]);

// ---- applying
check('nothing to apply is said', $csv->apply('pages', []), ['ok' => false, 'error' => 'Nothing to import.']);
$beforeAbout = file_get_contents("$dir/content/pages/about.md");
$res = $csv->apply('pages', [$about, $new, $en]);
check('a preview is applied', $res, ['ok' => true]);
check('an updated file has the new front matter and body', str_contains(file_get_contents("$dir/content/pages/about.md"), "title: 'About changed'") || str_contains(file_get_contents("$dir/content/pages/about.md"), 'title: About changed'), true);
check('what the row did not mention is still in it', str_contains(file_get_contents("$dir/content/pages/about.md"), 'Block text') && str_contains(file_get_contents("$dir/content/pages/about.md"), 'custom_note'), true);
check('a new file is written', is_file("$dir/content/pages/brand-new.md"), true);
check('a file whose slug changed moves', [is_file("$dir/content/pages/about.en.md"), is_file("$dir/content/pages/other-slug.en.md")], [false, true]);
$backups = glob("$dir/content/.import-backups/pages-*/pages/about.md");
check('the files it replaced were copied first', [count($backups), file_get_contents($backups[0] ?? '/nonexistent')], [1, $beforeAbout]);
check('and each file is in the history', count($revisions->forItem('pages', 'about', 'el')) >= 1, true);

// a file changed since the preview
$stale = $entries['about|el'];
$stale['old_path'] = "$dir/content/pages/about.md";
$stale['old_mtime'] = filemtime("$dir/content/pages/about.md") - 100;
check('content that changed since the preview is not overwritten', $csv->apply('pages', [$stale]), ['ok' => false, 'error' => 'Content changed since dry-run. Please rerun preview.']);

// a failure part way puts everything back (PHP warns about the failing copy and write; that is expected here)
set_error_handler(fn() => true);
mkdir("$dir/content/pages/blocked.md");
$good = ['action' => 'update', 'old_path' => "$dir/content/pages/about.md", 'old_mtime' => filemtime("$dir/content/pages/about.md"), 'new_path' => "$dir/content/pages/about.md", 'slug' => 'about', 'lang' => 'el', 'data' => ['title' => 'Should be undone'], 'body' => 'x'];
$bad = ['action' => 'create', 'old_path' => '', 'old_mtime' => 0, 'new_path' => "$dir/content/pages/blocked.md", 'slug' => 'blocked', 'lang' => 'el', 'data' => ['title' => 'Blocked'], 'body' => 'y'];
$before = file_get_contents("$dir/content/pages/about.md");
check('a target that cannot be backed up stops the import before anything is written', $csv->apply('pages', [$good, $bad]), ['ok' => false, 'error' => 'Failed to create backup copy before import.']);
check('and nothing was changed', file_get_contents("$dir/content/pages/about.md"), $before);
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    mkdir("$dir/content/pages/locked", 0555);
    $unwritable = ['action' => 'create', 'old_path' => '', 'old_mtime' => 0, 'new_path' => "$dir/content/pages/locked/x.md", 'slug' => 'x', 'lang' => 'el', 'data' => ['title' => 'X'], 'body' => 'z'];
    check('a file that cannot be prepared stops it too', $csv->apply('pages', [$good, $unwritable]), ['ok' => false, 'error' => 'Failed while preparing import files.']);
    check('with nothing changed and no temporary files left', [file_get_contents("$dir/content/pages/about.md"), glob("$dir/content/pages/.*tmp-import*") ?: []], [$before, []]);
    chmod("$dir/content/pages/locked", 0755);
}

restore_error_handler();

// raw HTML is not a way around the rule
$rawHtml = false;
$html = ['action' => 'create', 'old_path' => '', 'old_mtime' => 0, 'new_path' => "$dir/content/pages/html.md", 'slug' => 'html', 'lang' => 'el', 'data' => ['title' => 'Html'], 'body' => 'Hi <script>alert(1)</script> there'];
check('an import by someone who may not use raw HTML is applied', $csv->apply('pages', [$html]), ['ok' => true]);
check('with the HTML shown as text', str_contains(file_get_contents("$dir/content/pages/html.md"), '<script>'), false);
$rawHtml = true;
$html['new_path'] = "$dir/content/pages/html2.md";
$csv->apply('pages', [$html]);
check('and left alone for someone who may', str_contains(file_get_contents("$dir/content/pages/html2.md"), '<script>alert(1)</script>'), true);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
