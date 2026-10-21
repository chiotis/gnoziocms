<?php
/*
 * The gallery of an entry: the field kind `gallery` (a list of pictures of the media library), checked, stored and read (also
 * `gallery: [...]` at the top of the front matter, as an import may write it); the three place types having one; the strip on the
 * page of one (the Single Layouts option `gallery_strip`: off, under the title, in the body, drawn as a Gallery block, with its style
 * sheet and script); the Gallery block taking the entry's gallery as its source; and a template's Gallery block bound to it.
 * The types are the theme's and one that exists only as a file in custom/content-types during the test.
 *   php tests/unit/gallery-field.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, BlockRenderer, BlockRuntime, ContentItem, ContentRepository, ContentTypes, EntryBindings, FieldSchema, Images, SingleLayouts, SiteTwig, StructuredData, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$root = sys_get_temp_dir() . '/galleryfield' . getmypid();
mkdir("$root/custom/content-types", 0775, true);
foreach (['pages', 'posts', 'routes', 'albums'] as $d) { mkdir("$root/content/$d", 0775, true); }
mkdir("$root/public/uploads/media", 0775, true);
mkdir("$root/storage/db", 0775, true);
symlink($repo . '/themes', "$root/themes");
symlink($repo . '/admin', "$root/admin");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// A type of the site with two galleries under other keys, one capped, and one retired.
file_put_contents("$root/custom/content-types/albums.yaml", <<<'YAML'
label: { en: Albums }
fields:
  subtitle: { type: text, label: Subtitle }
  photos: { type: gallery, label: Photos }
  sketches: { type: gallery, label: Sketches, max: 2 }
  old_pictures: { type: gallery, label: Old, hidden: true }
YAML);
// A type that declares the strip option itself keeps its own.
file_put_contents("$root/custom/content-types/posts.yaml", <<<'YAML'
fields:
  gallery: { type: gallery, label: Pictures }
single:
  options:
    gallery_strip: { type: select, label: Where, default: off, options: { off: Off, body: In the body } }
YAML);

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);

// ---- the field kind
$field = FieldSchema::normalize(['g' => ['type' => 'gallery']])['g'];
check('a gallery is a kind of field, empty by default, keeping up to 100 pictures', [$field['type'], $field['default'], $field['max']], ['gallery', [], 100]);
check('its `max` caps it, never above 100', [FieldSchema::normalize(['g' => ['type' => 'gallery', 'max' => 3]])['g']['max'], FieldSchema::normalize(['g' => ['type' => 'gallery', 'max' => 500]])['g']['max']], [3, 100]);
check('a YAML list of addresses is kept in its order', FieldSchema::clean($field, ['/uploads/b.jpg', '/uploads/a.jpg']), ['/uploads/b.jpg', '/uploads/a.jpg']);
check('repeats, empty lines and what is not an address are dropped', FieldSchema::clean($field, ['/uploads/a.jpg', ' ', '/uploads/a.jpg', 'javascript:alert(1)', '/x.jpg" onerror="y', ['bad'], 7, 'https://cdn.test/p.jpg']), ['/uploads/a.jpg', 'https://cdn.test/p.jpg']);
check('the text of the editor\'s box, one address on each line', FieldSchema::clean($field, "/uploads/a.jpg\r\n\n  /uploads/b.jpg  \n"), ['/uploads/a.jpg', '/uploads/b.jpg']);
check('rows in the shape of the Gallery block\'s give their image', FieldSchema::clean($field, [['image' => '/uploads/a.jpg', 'caption' => 'x'], ['alt' => 'no image']]), ['/uploads/a.jpg']);
check('an empty box or nothing at all is no picture', [FieldSchema::clean($field, ''), FieldSchema::clean($field, null), FieldSchema::clean($field, 'not/a list "quoted"')], [[], [], []]);
check('a capped gallery keeps the first ones', FieldSchema::clean(FieldSchema::normalize(['g' => ['type' => 'gallery', 'max' => 2]])['g'], ['/1.jpg', '/2.jpg', '/3.jpg']), ['/1.jpg', '/2.jpg']);

// ---- the three place types
foreach (['routes', 'points', 'businesses'] as $type) {
    $def = $types->definition($type, 'en', 'en');
    check("$type have a gallery under the key `gallery`, not required", [$def['fields']['gallery']['type'] ?? null, $def['fields']['gallery']['required'] ?? null, $types->galleryKey($type)], ['gallery', false, 'gallery']);
}
check('a type of the theme without one has no gallery', [$types->galleryKey('projects'), $types->galleryKey('pages')], ['', '']);
check('a type with galleries under other keys: the first one that is not retired', $types->galleryKey('albums'), 'photos');

// ---- stored and read
$route = static fn(array $meta): ContentItem => new ContentItem('routes', 'gorge', 'en', ['title' => 'Gorge'] + $meta, '', '<p>Walk.</p>', '', 0);
$pictures = ['/uploads/media/one.jpg', '/uploads/media/two.jpg', '/uploads/media/three.jpg'];
check('read from custom_fields, in order', $types->values('routes', ['custom_fields' => ['gallery' => $pictures]])['gallery'] ?? null, $pictures);
check('read from the top of the front matter when custom_fields has none', $types->values('routes', ['gallery' => ['/uploads/media/top.jpg', 'javascript:x']])['gallery'] ?? null, ['/uploads/media/top.jpg']);
check('custom_fields wins over the top of the front matter', $types->values('routes', ['gallery' => ['/top.jpg'], 'custom_fields' => ['gallery' => ['/own.jpg']]])['gallery'], ['/own.jpg']);
check('a top-level key is read only for a gallery: a text field is not', array_key_exists('area', $types->values('routes', ['area' => 'Crete'])), false);
check('an empty gallery is no value', array_key_exists('gallery', $types->values('routes', ['custom_fields' => ['gallery' => []]])), false);
check('the pictures of the entry, by the type\'s gallery or a key named', [$types->gallery('routes', ['custom_fields' => ['gallery' => $pictures]]), $types->gallery('albums', ['custom_fields' => ['photos' => ['/p.jpg'], 'sketches' => ['/s.jpg']]], 'sketches')], [$pictures, ['/s.jpg']]);
check('a key that is not a gallery, or is retired, gives none', [$types->gallery('albums', ['custom_fields' => ['subtitle' => '/x.jpg']], 'subtitle'), $types->gallery('albums', ['custom_fields' => ['old_pictures' => ['/o.jpg']]], 'old_pictures'), $types->gallery('albums', [], 'nothing')], [[], [], []]);
$stored = $types->fromInput('routes', ['gallery' => "/uploads/media/two.jpg\n/uploads/media/one.jpg\n/uploads/media/two.jpg"], []);
check('the form\'s box is stored as a YAML list, in the order given, once each', $stored['gallery'] ?? null, ['/uploads/media/two.jpg', '/uploads/media/one.jpg']);
check('a list sent as a list is stored too', $types->fromInput('albums', ['sketches' => ['/a.jpg', '/b.jpg', '/c.jpg']], [])['sketches'] ?? null, ['/a.jpg', '/b.jpg']);
check('an emptied box leaves no key behind', array_key_exists('gallery', $types->fromInput('routes', ['gallery' => ''], ['gallery' => $pictures])), false);
check('a retired gallery keeps what it had', $types->fromInput('albums', [], ['old_pictures' => ['/o.jpg']])['old_pictures'] ?? null, ['/o.jpg']);
check('the YAML the editor writes is a plain list', trim(\Symfony\Component\Yaml\Yaml::dump(['custom_fields' => $stored], 4, 2)), "custom_fields:\n  gallery:\n    - /uploads/media/two.jpg\n    - /uploads/media/one.jpg");
$facts = $types->display('routes', ['custom_fields' => ['gallery' => $pictures, 'area' => 'Crete']], 'page', fn(string $d): string => $d, 'en', 'en');
check('a gallery is never a line of the fact sheet', array_column($facts, 'key'), ['area']);

// ---- the option of Single Layouts
$strip = $types->definition('routes', 'en', 'en')['single']['options']['gallery_strip'] ?? null;
check('a type with a gallery gets the strip option: under the title by default, off, or in the body', [$strip['type'] ?? null, $strip['default'] ?? null, array_keys($strip['options'] ?? [])], ['select', 'title', ['off', 'title', 'body']]);
check('in the language of the admin', $types->definition('routes', 'el', 'el')['single']['options']['gallery_strip']['options']['title'], 'Κάτω από τον τίτλο');
check('a type without a gallery is not offered it', array_key_exists('gallery_strip', $types->definition('projects', 'en', 'en')['single']['options']), false);
check('the option comes after the type\'s own', array_key_last($types->definition('routes', 'en', 'en')['single']['options']), 'gallery_strip');
check('a type that declares it keeps its own', [$types->definition('posts', 'en', 'en')['single']['options']['gallery_strip']['default'], array_keys($types->definition('posts', 'en', 'en')['single']['options']['gallery_strip']['options'])], ['off', ['off', 'body']]);
$layouts = new SingleLayouts($theme, $types);
check('the card says under the title until it is changed', $layouts->forType('routes', [])['options']['gallery_strip'], 'title');
$saved = $layouts->fromInput(['routes' => ['options' => ['gallery_strip' => 'body']], 'albums' => ['options' => ['gallery_strip' => 'sideways']]], ['routes', 'albums'], []);
check('the card stores a choice, and refuses one that is not offered', [$saved['routes']['options']['gallery_strip'], $saved['albums']['options']['gallery_strip']], ['body', 'title']);
check('what was stored is what the page reads', $layouts->forType('routes', ['single_layouts' => $saved])['options']['gallery_strip'], 'body');

// ---- drawing: the Gallery block with the entry's gallery, and the strip of the page
$settings = ['base_url' => 'https://s.test', 'home_page' => 'index', 'date_format' => 'Y-m-d', 'languages' => ['default' => 'en', 'available' => ['en']], 'title' => 'Site'];
$taxonomies = new Taxonomies("$root/content", ['en']);
$db = new SystemDatabase("$root/storage");
$db->initialize();
$md = new Environment([]);
$md->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$root/content", new MarkdownConverter($md), $settings);
$runtime = new BlockRuntime(
    $root, $theme, new Images("$root/public"), new SystemMetaRepository($db), $content,
    fn(): array => $settings, fn(): bool => false, fn(): ContentTypes => $types, fn(): string => 'en', fn(): string => 'en',
    fn(string $t, string $id, string $l): string => $id, fn(string $p): string => 'https://s.test/' . ltrim($p, '/'),
    fn(string $m, string $l, string $p): string => "<p>$m</p>", fn(string $s, string $l, string $p): string => ''
);
$twig = (new SiteTwig(
    $root, $theme, new Images("$root/public"),
    fn(): array => $settings, fn(): array => [], fn(): string => 'en', fn(): string => 'en', fn(): bool => false, fn(string $c): bool => false, fn(): string => 't',
    fn(string $k, ?string $f): string => $f ?? $k, fn(string $p): string => 'https://s.test/' . ltrim($p, '/'),
    fn(): StructuredData => throw new RuntimeException('not needed'), fn(): ContentTypes => $types, fn(): SingleLayouts => $layouts, fn(): Taxonomies => $taxonomies,
    fn(string $t, string $id, ?string $l): string => $id, fn(string $t, string $id, string $prefix): string => 'x',
    fn(): BlockRuntime => $runtime, fn(): bool => false, fn(): array => ['en']
))->create();
$renderer = $runtime->renderer('en', '/routes/gorge', $twig);
$context = static fn(ContentItem $item): array => ['item' => $item, 'lang' => 'en', 'lang_prefix' => '', 'body_html' => ''];
$item = $route(['custom_fields' => ['gallery' => $pictures]]);
$images = static fn(string $html): array => preg_match_all('#<a class="gallery-link" href="([^"]+)"#', $html, $m) ? $m[1] : [];

$page = $renderer->render([['type' => 'gallery', 'source' => 'entry', 'heading' => 'Photos']], $context($item));
check('the Gallery block shows the entry\'s gallery, in order, opening in the viewer', [$images($page['html']), str_contains($page['html'], 'data-gallery '), $page['types']], [$pictures, true, ['gallery']]);
$page = $renderer->render([['type' => 'gallery', 'source' => 'entry', 'images' => [['image' => '/typed.jpg']]]], $context($item));
check('the images typed below are not shown when the entry\'s are', $images($page['html']), $pictures);
$page = $renderer->render([['type' => 'gallery', 'source' => 'entry', 'field' => 'sketches']], $context(new ContentItem('albums', 'a', 'en', ['title' => 'A', 'custom_fields' => ['photos' => ['/p.jpg'], 'sketches' => ['/s1.jpg', '/s2.jpg']]], '', '', '', 0)));
check('a field named: that gallery', $images($page['html']), ['/s1.jpg', '/s2.jpg']);
$page = $renderer->render([['type' => 'gallery', 'source' => 'entry'], ['type' => 'text', 'body' => 'After']], $context($route([])));
check('an entry with no gallery: no Gallery block at all, the rest is drawn', [str_contains($page['html'], 'block-gallery'), str_contains($page['html'], 'After'), $page['types']], [false, true, ['text']]);
$page = $renderer->render([['type' => 'gallery', 'source' => 'entry']], ['lang' => 'en', 'body_html' => '']);
check('nor where there is no entry', str_contains($page['html'], 'block-gallery'), false);
$page = $renderer->render([['type' => 'gallery', 'images' => [['image' => '/typed.jpg', 'caption' => 'Typed']]]], $context($item));
check('a Gallery block of typed images is as it was', [$images($page['html']), str_contains($page['html'], 'Typed')], [['/typed.jpg'], true]);

$single = $runtime->singlePage($item, null, 'title', 'en', '/routes/gorge', $twig, ['lang' => 'en', 'lang_prefix' => '']);
check('the strip under the title: a Gallery block, Scrolling strip, of the entry\'s pictures', [$single['gallery_strip']['place'], str_contains($single['gallery_strip']['html'], 'block--strip'), $images($single['gallery_strip']['html'])], ['title', true, $pictures]);
check('it brings the gallery\'s style sheet and script, beside the map\'s', [count($single['styles']), str_contains($single['styles'][0] ?? '', 'gallery'), str_contains($single['styles'][0] ?? '', 'map'), str_contains($single['scripts'][0] ?? '', 'gallery')], [1, true, true, true]);
$body = $runtime->singlePage($item, null, 'body', 'en', '/routes/gorge', $twig, ['lang' => 'en']);
check('in the body: the same strip, with no space of its own above and below', [$body['gallery_strip']['place'], str_contains($body['gallery_strip']['html'], 'space-none'), str_contains($single['gallery_strip']['html'], 'space-compact')], ['body', true, true]);
$off = $runtime->singlePage($item, null, 'off', 'en', '/routes/gorge', $twig, []);
check('off: no strip, and only the map\'s assets', [$off['gallery_strip'], str_contains($off['styles'][0] ?? '', 'gallery'), str_contains($off['styles'][0] ?? '', 'map')], [['html' => '', 'place' => 'off'], false, true]);
$none = $runtime->singlePage($route([]), null, 'title', 'en', '/routes/gorge', $twig, []);
check('an entry with no pictures shows nothing', [$none['gallery_strip'], str_contains($none['styles'][0] ?? '', 'gallery')], [['html' => '', 'place' => 'off'], false]);
$blocks = $renderer->render([['type' => 'text', 'body' => 'x']], $context($item));
$page = $runtime->singlePage(new ContentItem('pages', 'about', 'en', ['title' => 'About'], '', '', '', 0), $blocks, 'title', 'en', '/about', $twig, []);
check('a type without a gallery or a place keeps the styles of its blocks', [$page['styles'], $page['scripts'], $page['gallery_strip']['place']], [$blocks['styles'], $blocks['scripts'], 'off']);
$withBlocks = $runtime->singlePage($item, $blocks, 'title', 'en', '/routes/gorge', $twig, []);
check('with blocks of its own: one style sheet for all of them', [count($withBlocks['styles']), str_contains($withBlocks['styles'][0], 'text'), str_contains($withBlocks['styles'][0], 'gallery'), str_contains($withBlocks['styles'][0], 'map')], [1, true, true, true]);

// The page of any type: the strip under the title comes first, in the body after the text.
$post = new ContentItem('posts', 'trip', 'en', ['title' => 'Trip', 'custom_fields' => ['gallery' => ['/p1.jpg']]], '', '<p>Text of the post.</p>', '', 0);
$strip = $runtime->singlePage($post, null, 'title', 'en', '/posts/trip', $twig, ['lang' => 'en'])['gallery_strip'];
$html = $twig->render('components/page-body.twig', ['item' => $post, 'gallery_strip' => $strip]);
check('page body: under the title, before the text', [str_contains($html, 'entry-strip--title'), strpos($html, 'block-gallery') < strpos($html, 'Text of the post')], [true, true]);
$strip = $runtime->singlePage($post, null, 'body', 'en', '/posts/trip', $twig, ['lang' => 'en'])['gallery_strip'];
$html = $twig->render('components/page-body.twig', ['item' => $post, 'gallery_strip' => $strip]);
check('page body: in the body, after the text', [str_contains($html, 'entry-strip--body'), strpos($html, 'block-gallery') > strpos($html, 'Text of the post')], [true, true]);
$html = $twig->render('components/page-body.twig', ['item' => $post]);
check('page body without a strip: as it was', [str_contains($html, 'entry-strip'), str_contains($html, 'Text of the post')], [false, true]);

// ---- a template built with blocks: the Gallery block's list bound to the gallery
$sources = EntryBindings::sources($types, 'routes', 'en', 'en');
$gallery = array_values(array_filter($sources, fn($s) => $s['path'] === 'custom_fields.gallery'))[0] ?? null;
check('a gallery is offered as a list with one column, the image', [$gallery['kind'] ?? null, $gallery['columns'] ?? null], ['list', [['key' => 'image', 'label' => 'Image', 'kind' => 'image']]]);
check('and never as a single value', in_array('custom_fields.gallery', array_column(EntryBindings::compatible($sources, 'image'), 'path'), true), false);
$bind = new EntryBindings($item, $types, 'en', 'en', fn(string $d): string => $d, fn(string $t, string $id): string => $id);
$registry = $runtime->registry();
$fields = $registry->get('gallery')['fields'];
$out = $bind->apply($fields, ['type' => 'gallery', 'images' => ['bind' => 'custom_fields.gallery', 'map' => ['image' => 'image'], 'fixed' => ['caption' => 'From the route']]]);
check('bound: a row for each picture, with the fixed caption', FieldSchema::resolve($fields, $out['raw'])['images'], array_map(fn($u) => ['image' => $u, 'caption' => 'From the route', 'alt' => ''], $pictures));
$out = $bind->apply($fields, ['type' => 'gallery', 'images' => ['bind' => 'custom_fields.gallery', 'map' => ['alt' => 'image']]]);
check('a column that is not a picture cannot show one: it is named as missing, no rows', [$out['missing'], $out['raw']['images'], $out['hidden']], [['custom_fields.gallery.image'], [], true]);
$out = (new EntryBindings($route([]), $types, 'en', 'en', fn(string $d): string => $d, fn(string $t, string $id): string => $id))->apply($fields, ['type' => 'gallery', 'images' => ['bind' => 'custom_fields.gallery', 'map' => ['image' => 'image']]]);
check('an entry without pictures hides the bound block', $out['hidden'], true);
$page = $renderer->render([['type' => 'gallery', 'images' => ['bind' => 'custom_fields.gallery', 'map' => ['image' => 'image']]]], $context($item) + ['template_mode' => true, 'bindings' => $bind]);
check('drawn: the entry\'s pictures', $images($page['html']), $pictures);
$attack = $route(['custom_fields' => ['gallery' => ['/ok.jpg', 'javascript:alert(1)', '/x.jpg"><script>']]]);
$page = $renderer->render([['type' => 'gallery', 'source' => 'entry']], $context($attack));
check('what is not a safe address never reaches the page', [$images($page['html']), str_contains($page['html'], '<script>'), str_contains($page['html'], 'javascript:')], [['/ok.jpg'], false, false]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
