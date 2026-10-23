<?php
/*
 * The Latest content block answered by the content index is the list reading every entry of the type gives: the same entries in the same
 * order, for a language, a category or a tag, drafts and hidden entries (who may see them), the limit, and entries without a date. It
 * falls back to reading the entries when the index cannot be trusted (the live preview of the editor).
 *   php tests/unit/latest-content.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{ArchiveQuery, BlockRuntime, ContentIndex, ContentRepository, ContentTypes, Images, SystemDatabase, SystemMetaRepository, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Twig\Environment as Twig;
use Twig\Loader\ArrayLoader;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$root = sys_get_temp_dir() . '/latest-content' . getmypid();
foreach (['posts', 'projects', 'pages'] as $dir) {
    mkdir($root . '/content/' . $dir, 0775, true);
}
mkdir($root . '/public', 0775, true);
mkdir($root . '/storage', 0775, true);
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });

// Forty posts: some without a date (the file's time orders them), two with the same date, drafts, hidden, a tag, a category, two languages.
for ($i = 0; $i < 40; $i++) {
    $front = ['title: Post ' . $i, 'status: ' . ($i % 9 === 0 ? 'draft' : 'published')];
    if ($i % 7 === 0) { $front[] = 'visible: false'; }
    if ($i % 5 !== 0) { $front[] = 'date: "2026-0' . (1 + $i % 6) . '-' . sprintf('%02d', 1 + $i % 3 * 5) . '"'; }
    if ($i % 3 === 0) { $front[] = 'categories: [news]'; }
    if ($i % 4 === 0) { $front[] = 'tags: [quick, news]'; }
    if ($i % 11 === 0) { $front[] = 'tags: quick'; }
    file_put_contents($root . '/content/posts/p' . sprintf('%02d', $i) . '.md', "---\n" . implode("\n", $front) . "\n---\n\nBody\n");
    touch($root . '/content/posts/p' . sprintf('%02d', $i) . '.md', 1700000000 + $i * 1000);
    if ($i % 6 === 0) {
        file_put_contents($root . '/content/posts/p' . sprintf('%02d', $i) . '.en.md', "---\ntitle: English $i\nstatus: published\ndate: \"2026-07-" . sprintf('%02d', 1 + $i % 20) . "\"\ncategories: [news]\n---\n\nEN\n");
    }
}
file_put_contents($root . '/content/projects/one.md', "---\ntitle: One\nstatus: published\n---\n\nx\n");

$settings = ['base_url' => 'https://s.test/', 'languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository($root . '/content', new MarkdownConverter($environment), $settings);
$database = new SystemDatabase($root . '/storage');
$database->initialize();
$meta = new SystemMetaRepository($database);
$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$index = new ContentIndex($database, $root . '/content', $meta);
$index->keepReferences(fn(string $type): array => $types->definition($type, 'el', 'el')['fields']);
$index->keepTerms(fn(): array => ['categories', 'tags']);
$query = new ArchiveQuery($index, $content);

$signedIn = false;
$runtime = static function (?\Closure $archiveQuery) use ($root, $theme, $meta, $content, $types, &$signedIn, $settings): BlockRuntime {
    return new BlockRuntime(
        $root,
        $theme,
        new Images($root . '/public'),
        $meta,
        $content,
        fn(): array => $settings,
        function () use (&$signedIn): bool { return $signedIn; },
        fn(): ContentTypes => $types,
        fn(): string => 'el',
        fn(): string => 'el',
        fn(string $taxonomy, string $termId, string $l): string => $termId,
        fn(string $path): string => 'https://s.test/' . ltrim($path, '/'),
        fn(string $markdown, string $l, string $path): string => "<p>$markdown</p>",
        fn(string $slug, string $l, string $path): string => '',
        null,
        null,
        $archiveQuery
    );
};
/** The block's source of entries, as the renderer calls it. */
$items = static function (BlockRuntime $runtime): \Closure {
    $renderer = $runtime->renderer('el', 'home', new Twig(new ArrayLoader([])));
    $providers = (new \ReflectionProperty($renderer, 'providers'))->getValue($renderer);
    return $providers['items'];
};
$names = static fn(array $list): array => array_map(static fn($i) => $i->type . '/' . $i->slug . '.' . $i->lang, $list);

$scanning = $items($runtime(null));
$indexed = $items($runtime(fn(): ArchiveQuery => $query));
$same = static function (string $label, string $type, string $lang, int $limit, string $term = '') use ($scanning, $indexed, $names): void {
    $expected = $names($scanning($type, $lang, $limit, $term));
    check($label, $names($indexed($type, $lang, $limit, $term)), $expected);
};

check('the index can answer', $query->ready(), true);
foreach ([false, true] as $signedIn) {
    $who = $signedIn ? ' (signed in: drafts and hidden entries too)' : ' (a visitor)';
    $same('the newest six' . $who, 'posts', 'el', 6);
    $same('the most the block allows' . $who, 'posts', 'el', 24);
    $same('more than that is the most it allows' . $who, 'posts', 'el', 500);
    $same('none at all is one' . $who, 'posts', 'el', 0);
    $same('the other language, with nothing from the default one' . $who, 'posts', 'en', 12);
    $same('a category' . $who, 'posts', 'el', 8, 'news');
    $same('a tag' . $who, 'posts', 'el', 8, 'quick');
    $same('a term written with capitals and spaces' . $who, 'posts', 'el', 8, ' News ');
    $same('a term nothing is filed under' . $who, 'posts', 'el', 8, 'nothing');
    $same('a type with one entry and no date' . $who, 'projects', 'el', 6);
    $same('a language the type has nothing in' . $who, 'projects', 'en', 6);
}
$signedIn = false;
check('the visitor gets the newest published entries', [count($indexed('posts', 'el', 6, '')), $names($indexed('posts', 'el', 3, ''))[0] === $names($scanning('posts', 'el', 3, ''))[0]], [6, true]);
$direct = $query->page(['types' => ['posts'], 'term' => ['taxonomy' => ['categories', 'tags'], 'ids' => ['quick']]], 'el', false, [], [], 'date_desc', '', 0, 0, 1, 8);
check('the index itself answers a term filed as a category or a tag', [$direct !== null, $direct === null ? null : $names($direct['items'])], [true, $names($scanning('posts', 'el', 8, 'quick'))]);
check('a type that is not content gives nothing', [$indexed('forms', 'el', 6, ''), $indexed('nothing', 'el', 6, '')], [[], []]);

// The index follows a save and a delete.
$file = $root . '/content/posts/p03.md';
file_put_contents($file, "---\ntitle: Brand new\nstatus: published\ndate: \"2030-01-01\"\ncategories: [fresh]\n---\n\nChanged\n");
$content->forget();
$index->upsert($content->parseFile('posts', $file));
$same('a saved entry is where its date puts it', 'posts', 'el', 4);
check('and is first', $names($indexed('posts', 'el', 1, ''))[0], 'posts/p03.el');
$same('and in the category it was given', 'posts', 'el', 4, 'fresh');
unlink($file);
$index->remove('posts', 'p03', 'el');
$content->forget();
$same('a deleted entry is gone', 'posts', 'el', 4);

// An index that cannot be trusted is not used: the entries are read.
$unsure = $items($runtime(fn(): ArchiveQuery => new ArchiveQuery(new ContentIndex(new SystemDatabase($root . '/storage'), $root . '/content', $meta), $content)));
check('with an index made with no definitions, the entries are read and the list is the same', $names($unsure('posts', 'el', 6, 'news')), $names($scanning('posts', 'el', 6, 'news')));

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
