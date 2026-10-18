<?php
/*
 * What the blocks of a page need from the site (BlockRuntime): the registry and the choices it offers, the types of content with
 * a place, the maps, the YouTube services and the address of a kept picture; and that each is built only when asked for, with
 * the settings, language and signed-in person read when they are used.
 *   php tests/unit/block-runtime.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, BlockRenderer, BlockRuntime, ContentItem, ContentRepository, ContentTypes, GeoView, Images, SystemDatabase, SystemMetaRepository, Theme, YouTubePlaylist, YouTubeThumbs};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Twig\Environment as Twig;
use Twig\Loader\ArrayLoader;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/blockrt' . getmypid();
foreach (['pages', 'posts', 'points', 'forms'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/public", 0775, true);
mkdir("$dir/storage/db", 0775, true);
file_put_contents("$dir/content/forms/contact.md", "---\ntitle: Contact us\nstatus: published\n---\n");
file_put_contents("$dir/content/points/spring.en.md", "---\ntitle: Spring\nstatus: published\ncustom_fields:\n  location: '35.2, 26.3'\n---\n\nx\n");
$settings = ['base_url' => 'https://s.test/', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'apis' => ['youtube' => ['key' => 'K1'], 'maps' => ['load' => 'auto']]];
$signedIn = false;
$lang = 'el';
$asked = ['types' => 0];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$runtime = new BlockRuntime(
    $dir,
    $theme,
    new Images("$dir/public"),
    new SystemMetaRepository($db),
    $content,
    function () use (&$settings): array { return $settings; },
    function () use (&$signedIn): bool { return $signedIn; },
    function () use ($types, &$asked): ContentTypes { $asked['types']++; return $types; },
    function () use (&$lang): string { return $lang; },
    fn(): string => 'el',
    fn(string $taxonomy, string $termId, string $l): string => "$taxonomy:$termId:$l",
    fn(string $path): string => 'https://s.test/' . ltrim($path, '/'),
    fn(string $markdown, string $l, string $path): string => "<p>$markdown</p>",
    fn(string $slug, string $l, string $path): string => "[form $slug $l $path]"
);

check('nothing is built until it is asked for', $asked['types'], 0);

// ---- the registry and its choices
$registry = $runtime->registry();
check('one registry, with the blocks of the theme', [$runtime->registry() === $registry, $registry instanceof BlockRegistry, $registry->get('latest') !== null], [true, true, true]);
check('the types of content to show are those with a definition apart from pages and forms, with their names', $registry->get('latest')['fields']['source']['options'], ['points' => 'Points of interest', 'posts' => 'Posts']);
check('the forms to place are those of the site, after a blank', $registry->get('form')['fields']['form']['options'] ?? null, ['' => '—', 'contact' => 'Contact us']);
check('the Map block offers one typed place and the types with a place', $registry->get('map')['fields']['source']['options'] ?? null, ['manual' => 'One place, typed below', 'points' => 'Points of interest']);

// ---- types with a place
check('a type with a field of the kind location can have a place, another cannot', [$runtime->hasPlaceField('points'), $runtime->hasPlaceField('posts'), $runtime->hasPlaceField('pages')], [true, false, false]);
$view = $runtime->geoView();
check('the maps are one service, and know which types have a place', [$runtime->geoView() === $view, $view instanceof GeoView, $view->placeTypes()], [true, true, ['points']]);
$item = new ContentItem('points', 'spring', 'en', ['title' => 'Spring', 'custom_fields' => ['location' => '35.2, 26.3']], '', '<p>x</p>', '', 0);
$data = $view->dataset([$item], 'en');
check('a point is drawn with the address of its language', [$data['items'][0]['url'], $data['items'][0]['kind']], ['https://s.test/en/points/spring', 'Points of interest']);
check('and with none of a prefix in the default language', $view->dataset([$item], 'el')['items'][0]['url'], 'https://s.test/points/spring');
check('the maps gather the entries of a type, in the language asked', array_map(fn($i) => $i->slug, $view->gather(['points'], 'en')), ['spring']);

// ---- YouTube
$playlist = $runtime->youtubePlaylist();
check('the playlist service is one, until it is forgotten', [$runtime->youtubePlaylist() === $playlist], [true]);
$runtime->resetYoutube();
check('and then it is made again, with the settings as they are', [$runtime->youtubePlaylist() !== $playlist, $runtime->youtubePlaylist() instanceof YouTubePlaylist], [true, true]);
$thumbs = $runtime->youtubeThumbs();
check('the pictures are one service', [$runtime->youtubeThumbs() === $thumbs, $thumbs instanceof YouTubeThumbs], [true, true]);
$id = 'AAAAAAAAAAA';
mkdir("$dir/storage/cache/youtube", 0775, true);
file_put_contents("$dir/storage/cache/youtube/$id.jpg", "\xFF\xD8\xFF\xE0" . str_repeat('x', 800));
$path = $thumbs->path($id);
parse_str((string)parse_url($path, PHP_URL_QUERY), $q);
$address = (string)parse_url($path, PHP_URL_PATH);
check('a picture is given to whoever has its signature', $runtime->youtubeThumbFile($address, $q['s']), "$dir/storage/cache/youtube/$id.jpg");
check('and to no one else', [$runtime->youtubeThumbFile($address, 'x'), $runtime->youtubeThumbFile($address, ''), $runtime->youtubeThumbFile('_yt/BBBBBBBBBBB.jpg', $q['s'])], [null, null, null]);

// ---- the renderer
$twig = new Twig(new ArrayLoader([]));
check('a renderer for the blocks of a page', $runtime->renderer('en', 'about', $twig) instanceof BlockRenderer, true);

array_map('unlink', array_merge(glob("$dir/storage/cache/youtube/*") ?: []));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
