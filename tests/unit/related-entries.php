<?php
/*
 * Related entries (the sidebar's list of other entries of the same type): the ones sharing a category or a tag, those sharing more
 * first and then the newest; the newest when the entry has no term or "latest" is asked; never the entry itself, a draft, a hidden
 * entry or another language; at most the number asked for. Also the Single Layouts choices that turn it on.
 *   php tests/unit/related-entries.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{ContentIndex, ContentRepository, ContentTypes, Relations, SingleLayouts, SystemDatabase, SystemMetaRepository, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$root = sys_get_temp_dir() . '/related-entries' . getmypid();
mkdir($root . '/custom/content-types', 0775, true);
mkdir($root . '/storage', 0775, true);
foreach (['articles', 'notes'] as $dir) {
    mkdir($root . '/content/' . $dir, 0775, true);
}
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
file_put_contents($root . '/custom/content-types/articles.yaml', "label: Articles\nfields: {}\n");
file_put_contents($root . '/custom/content-types/notes.yaml', "label: Notes\nfields: {}\n");

$put = static function (string $path, string $front) use ($root): void {
    file_put_contents($root . '/content/' . $path, "---\n" . $front . "\n---\n\nText\n");
};
$put('articles/a.md', "title: A\nstatus: published\ndate: 2024-01-01\ncategories: [news, design]\ntags: [php]");
$put('articles/b.md', "title: B\nstatus: published\ndate: 2023-01-01\ncategories: [news, design]\nmain_image: /uploads/b.jpg");
$put('articles/c.md', "title: C\nstatus: published\ndate: 2025-01-01\ncategories: [news]\ntags: [php]");
$put('articles/d.md', "title: D\nstatus: published\ndate: 2026-01-01\ncategories: [travel]");
$put('articles/e.md', "title: E draft\nstatus: draft\ndate: 2026-02-01\ncategories: [news]");
$put('articles/f.md', "title: F hidden\nstatus: published\nvisible: false\ndate: 2026-03-01\ncategories: [news]");
$put('articles/g.md', "title: G\nstatus: published\ndate: 2022-01-01");
$put('articles/c.en.md', "title: C EN\nstatus: published\ndate: 2025-01-01\ncategories: [news]");
$put('notes/n.md', "title: Note\nstatus: published\ndate: 2026-04-01\ncategories: [news]");

$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository($root . '/content', new MarkdownConverter($environment), $settings);
$database = new SystemDatabase($root . '/storage');
$database->initialize();
$meta = new SystemMetaRepository($database);
$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$index = new ContentIndex($database, $root . '/content', $meta);
$index->keepTerms(fn(): array => ['categories', 'tags']);
$built = $index->rebuild($content, ['articles', 'notes']);
check('the index is built', $built['ok'], true);
$relations = new Relations($index, $content, $types, $settings, $root . '/content');

$a = $content->find('articles', 'a', 'el', true);
$g = $content->find('articles', 'g', 'el', true);
$titles = static fn(array $cards): array => array_column($cards, 'title');

check('same category: those sharing more categories first, then the newest; no draft, hidden entry, other type or the entry itself',
    $titles($relations->similar($a, 'categories', 6, 'el')), ['B', 'C']);
check('same tags', $titles($relations->similar($a, 'tags', 6, 'el')), ['C']);
check('latest: the newest of the type', $titles($relations->similar($a, 'latest', 6, 'el')), ['D', 'C', 'B', 'G']);
check('an entry with no category gets the newest', $titles($relations->similar($g, 'categories', 2, 'el')), ['D', 'C']);
check('at most the number asked for', count($relations->similar($a, 'latest', 2, 'el')), 2);
check('in the language of the page', $titles($relations->similar($a, 'categories', 6, 'en')), ['C EN']);
$card = $relations->similar($a, 'categories', 1, 'el')[0];
check('a card carries its picture, date and address', [$card['main_image'], $card['date'], $card['url']], ['/uploads/b.jpg', '2023-01-01', '/articles/b']);
check('a taxonomy the entry is not filed in falls back to the newest', $titles($relations->similar($a, 'regions', 1, 'el')), ['D']);

// ---- the choice in Single Layouts
$layouts = new SingleLayouts($theme, $types);
$values = $layouts->forType('articles', []);
check('off by default, four with thumbnails', [$values['entries'], (string)$values['entries_max'], $values['entries_images']], ['none', '4', true]);
$saved = $layouts->fromInput(['articles' => ['entries' => 'categories', 'entries_max' => '6']], ['articles'], []);
check('saved from the card (an unticked chip is off)', [$saved['articles']['entries'], (string)$saved['articles']['entries_max'], $saved['articles']['entries_images']], ['categories', '6', false]);
$bad = $layouts->fromInput(['articles' => ['entries' => 'everything', 'entries_max' => '99']], ['articles'], []);
check('a value that is not offered is not kept', [$bad['articles']['entries'], (string)$bad['articles']['entries_max']], ['none', '4']);

exit($fail > 0 ? 1 : 0);
