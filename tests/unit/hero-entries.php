<?php
/*
 * The Hero and the Hero slider drawn from entries of a content type (Show: Posts) instead of typed text: the Hero takes the newest
 * entry, the slider a slide for each, in the layout chosen for them all, with the entry's title, excerpt, category, main image and a
 * button to it. No entry: no block, and the block after it opens the page. Typed slides and text are drawn as before.
 *   php tests/unit/hero-entries.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, BlockRenderer, BlockRuntime, ContentItem, ContentTypes, Images, SingleLayouts, SiteTwig, StructuredData, Taxonomies, Theme};

$root = sys_get_temp_dir() . '/heroentries' . getmypid();
mkdir("$root/public", 0775, true);
mkdir("$root/content/taxonomies", 0775, true);
mkdir("$root/admin/templates", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$registry = new BlockRegistry($theme, ['content_types' => fn() => ['posts' => 'Posts', 'projects' => 'Projects'], 'forms' => fn() => ['' => '—']]);
$settings = ['base_url' => 'https://s.test', 'home_page' => 'index', 'date_format' => 'Y-m-d', 'languages' => ['default' => 'en'], 'title' => 'Site'];
$taxonomies = new Taxonomies("$root/content", ['en']);
$twig = (new SiteTwig(
    $root, $theme, new Images("$root/public"),
    fn(): array => $settings, fn(): array => [], fn(): string => 'en', fn(): string => 'en', fn(): bool => false, fn(string $c): bool => false, fn(): string => 't',
    fn(string $k, ?string $f): string => $f ?? $k, fn(string $p): string => 'https://s.test/' . ltrim($p, '/'),
    fn(): StructuredData => throw new RuntimeException('not needed'), fn(): ContentTypes => $types, fn(): SingleLayouts => new SingleLayouts($theme, $types), fn(): Taxonomies => $taxonomies,
    fn(string $t, string $id, ?string $l): string => 'Cat:' . $id, fn(string $t, string $id, string $prefix): string => 'x',
    function (): BlockRuntime { throw new RuntimeException('no block runtime in this test'); }, fn(): bool => true, fn(): array => ['en']
))->create();

$post = static fn(string $slug, array $meta, string $html = '<p>Body</p>'): ContentItem => new ContentItem('posts', $slug, 'en', $meta + ['title' => ucfirst($slug)], '', $html, '', 0);
$posts = [
    $post('first', ['title' => 'First post', 'excerpt' => 'The <b>first</b> excerpt', 'main_image' => '/uploads/one.jpg', 'categories' => ['news']]),
    $post('second', ['title' => 'Second post'], '<p>The second body, ' . str_repeat('long ', 80) . '</p>'),
    $post('third', ['title' => 'Third post', 'main_image' => '/uploads/three.jpg']),
];
$asked = [];
$renderer = new BlockRenderer($registry, $twig, $theme, fn(string $md): string => '<p>' . htmlspecialchars($md, ENT_QUOTES) . '</p>', [
    'items' => function (string $type, string $lang, int $limit, string $term = '', int $offset = 0) use (&$asked, $posts): array {
        $asked[] = [$type, $lang, $limit, $term, $offset];
        return $type === 'posts' ? array_slice($posts, $offset, $limit) : [];
    },
], 'https://s.test');
$draw = static fn(array $blocks): array => $renderer->render($blocks, ['lang' => 'en', 'lang_prefix' => '', 'body_html' => '']);

// ---- the fields
$hero = $registry->get('hero')['fields'];
$slider = $registry->get('hero-slider')['fields'];
check('both blocks offer typed text and every content type', [array_keys($hero['source']['options']), array_keys($slider['source']['options'])], [['manual', 'posts', 'projects'], ['manual', 'posts', 'projects']]);
check('typed text is what they show until another is chosen', [$hero['source']['default'], $slider['source']['default']], ['manual', 'manual']);
check('the typed fields are offered only for typed text, the entry ones only for entries', [$hero['heading']['when'], $hero['image']['when'], $hero['term']['when'], $slider['items']['when'], $slider['limit']['when']], [['source' => ['manual']], ['background' => ['image'], 'source' => ['manual']], ['source' => ['!manual']], ['source' => ['manual']], ['source' => ['!manual']]]);
check('a slider of entries has three slides, eight at most, over the picture', [$slider['limit']['default'], $slider['limit']['max'], $slider['entry_layout']['default']], [3, 8, 'cover']);

// ---- the Hero: the newest entry
$asked = [];
$page = $draw([['type' => 'hero', 'variant' => 'split', 'source' => 'posts', 'term' => 'news', 'heading' => 'Typed', 'text' => 'Typed text', 'actions' => [['label' => 'Typed', 'url' => '/x']]]]);
check('the hero asks for one entry of the type, in the category', $asked, [['posts', 'en', 1, 'news', 0]]);
check('it shows the entry\'s title as the page title, its excerpt without markup, and its picture', [str_contains($page['html'], '<h1 class="hero-title" id="block-1-title">First post</h1>'), str_contains($page['html'], '<p class="hero-text">The first excerpt</p>'), str_contains($page['html'], 'one.jpg')], [true, true, true]);
check('its category is the eyebrow, and the button leads to the entry', [str_contains($page['html'], '<p class="eyebrow">Cat:news</p>'), (bool)preg_match('#<a class="btn btn-primary" href="[^"]*">Read more#', $page['html'])], [true, true]);
check('the typed text and buttons are not drawn', [str_contains($page['html'], 'Typed'), $page['leads_with_hero'], $page['image']], [false, true, '/uploads/one.jpg']);
$page = $draw([['type' => 'hero', 'source' => 'posts', 'eyebrow' => 'Featured', 'entry_button' => 'Read the story']]);
check('a typed eyebrow and button text win', [str_contains($page['html'], '<p class="eyebrow">Featured</p>'), str_contains($page['html'], 'Read the story')], [true, true]);
$page = $draw([['type' => 'hero', 'source' => 'projects'], ['type' => 'text', 'heading' => 'After', 'body' => 'x']]);
check('with no entry there is no hero, and the next block opens the page', [str_contains($page['html'], 'block-hero'), $page['leads_with_hero'], $page['lead']['type'] ?? null], [false, false, 'text']);
$page = $draw([['type' => 'hero', 'heading' => 'Typed heading', 'text' => 'Typed text']]);
check('a typed hero is as before', [str_contains($page['html'], 'Typed heading'), str_contains($page['html'], 'Typed text'), str_contains($page['html'], 'Read more')], [true, true, false]);

// ---- the Hero slider: a slide for each entry
$asked = [];
$page = $draw([['type' => 'hero-slider', 'source' => 'posts', 'limit' => 3, 'entry_layout' => 'cover', 'items' => [['heading' => 'Typed slide']]]]);
check('the slider asks for as many entries as slides', $asked, [['posts', 'en', 3, '', 0]]);
check('a slide for each entry, in the chosen layout, and no typed slide', [substr_count($page['html'], 'class="slider-slide"'), substr_count($page['html'], 'block--cover'), str_contains($page['html'], 'Typed slide')], [3, 3, false]);
check('each with its title and a button to it', [str_contains($page['html'], 'First post'), str_contains($page['html'], 'Second post'), str_contains($page['html'], 'Third post'), substr_count($page['html'], '>Read more')], [true, true, true, 3]);
check('an entry without an excerpt shows the start of its text, shortened', (bool)preg_match('#<p class="hero-text">The second body, (long ){30,}l?o?n?g?…</p>#', $page['html']), true);
check('it opens the page, light text over the first picture', [$page['opens_with_slider'], $page['opens_with_dark_slide']], [true, true]);
$page = $draw([['type' => 'hero-slider', 'source' => 'posts', 'limit' => 2, 'entry_layout' => 'evil']]);
check('two slides when two are asked for, and an unknown layout is the usual one', [substr_count($page['html'], 'class="slider-slide"'), substr_count($page['html'], 'block--cover')], [2, 2]);
$page = $draw([['type' => 'hero-slider', 'source' => 'posts', 'entry_layout' => 'minimal']]);
check('a minimal slide has no picture and the header over it stays dark text', [str_contains($page['html'], 'one.jpg'), $page['opens_with_dark_slide']], [false, false]);
$page = $draw([['type' => 'hero-slider', 'source' => 'projects'], ['type' => 'text', 'heading' => 'After', 'body' => 'x']]);
check('with no entry there is no slider', [str_contains($page['html'], 'slider-hero'), $page['opens_with_slider'], $page['lead']['type'] ?? null], [false, false, 'text']);
$page = $draw([['type' => 'hero-slider', 'items' => [['heading' => 'Typed slide']]]]);
check('typed slides are as before', [substr_count($page['html'], 'class="slider-slide"'), str_contains($page['html'], 'Typed slide')], [1, true]);

// ---- Skip the newest: every block of entries passes it on
check('each block of entries has it, none skipped to begin with, 50 at most, never below none', array_map(static fn(string $t): array => [$registry->get($t)['fields']['offset']['default'], $registry->get($t)['fields']['offset']['max'], \GnozioCMS\FieldSchema::resolve($registry->get($t)['fields'], ['offset' => -3])['offset']], ['latest', 'cards', 'portfolio', 'hero', 'hero-slider']), array_fill(0, 5, [0, 50, 0]));
check('for typed cards, pieces, heroes and slides it is not offered', array_map(static fn(string $t): array => $registry->get($t)['fields']['offset']['when'] ?? [], ['latest', 'cards', 'portfolio', 'hero', 'hero-slider']), [[], ['source' => ['!manual']], ['source' => ['!manual']], ['source' => ['!manual']], ['source' => ['!manual']]]);
$asked = [];
$page = $draw([
    ['type' => 'hero', 'source' => 'posts', 'offset' => 1],
    ['type' => 'hero-slider', 'source' => 'posts', 'limit' => 2, 'offset' => 1],
    ['type' => 'latest', 'source' => 'posts', 'limit' => 3, 'offset' => 2],
    ['type' => 'cards', 'source' => 'posts', 'limit' => 3, 'offset' => 1],
    ['type' => 'portfolio', 'source' => 'posts', 'limit' => 3, 'offset' => 2],
]);
check('the hero, slider, latest, cards and portfolio each ask with their skip', array_column($asked, 4), [1, 1, 2, 1, 2]);
$page = $draw([['type' => 'hero', 'source' => 'posts', 'offset' => 1]]);
check('a hero that skips one shows the second entry', [str_contains($page['html'], 'Second post'), str_contains($page['html'], 'First post')], [true, false]);
$page = $draw([['type' => 'hero', 'source' => 'posts'], ['type' => 'latest', 'source' => 'posts', 'limit' => 3, 'offset' => 1]]);
check('a hero and a list that skips it: each entry once', [substr_count($page['html'], 'First post'), substr_count($page['html'], 'Second post'), substr_count($page['html'], 'Third post')], [1, 1, 1]);
$page = $draw([['type' => 'hero', 'source' => 'posts', 'offset' => 3], ['type' => 'text', 'heading' => 'After', 'body' => 'x']]);
check('skipping every entry leaves no hero', [str_contains($page['html'], 'block-hero'), $page['lead']['type'] ?? null], [false, 'text']);

// ---- in a container: the same entries
$page = $draw([['type' => 'container', 'columns' => [['blocks' => [['type' => 'hero', 'source' => 'posts']]]]]]);
check('a hero of entries in a column shows the entry too', str_contains($page['html'], 'First post'), true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
