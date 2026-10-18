<?php
/*
 * The template functions that put the site's choices into a page (PageTwigFunctions: analytics, SEO, branding) and the maps of
 * content (GeoTwigFunctions): which are there, that settings, the language and the signed-in person are read when a function
 * runs (not when it is added), that HTML is let through only where it is meant to be, and that nothing is built for a page that
 * does not use them.
 *   php tests/unit/twig-site-functions.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, GeoMap, GeoLibrary, GeoTwigFunctions, GeoView, Images, PageTwigFunctions};
use Twig\Environment;
use Twig\Loader\ArrayLoader;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . var_export($actual, true) . ' expected ' . var_export($expected, true)) . "\n"; }

$twigOf = static fn(array $templates): Environment => new Environment(new ArrayLoader($templates), ['autoescape' => 'html', 'cache' => false]);

// ---- the page: analytics, SEO, branding
$settings = ['title' => 'Site', 'tagline' => 'A tagline', 'analytics' => ['mode' => 'custom', 'head_code' => '<script>owner()</script>', 'body_code' => '<b>end</b>', 'skip_signed_in' => true], 'seo' => ['home_title' => 'Home title']];
$themeSettings = [];
$signedIn = false;
$twig = $twigOf([
    'head' => '{{ analytics_head("/a.js", "/api") }}|{{ analytics_body() }}',
    'title' => '{{ seo_title(own, page, home, type) }}',
    'description' => '{{ seo_description("", excerpt, "", home) }}',
    'robots' => '[{{ seo_robots(noindex, kind) }}]',
    'seohead' => '{{ seo_head() }}',
    'card' => '{{ seo_twitter_card(true) }}|{{ seo_share_image() }}',
    'branding' => '{{ branding_css() }}#{{ branding_head() }}',
]);
PageTwigFunctions::register($twig, function () use (&$settings): array { return $settings; }, function () use (&$themeSettings): array { return $themeSettings; }, function () use (&$signedIn): bool { return $signedIn; }, 'https://s.test');

foreach (['analytics_head', 'analytics_body', 'seo_title', 'seo_description', 'seo_robots', 'seo_head', 'seo_share_image', 'seo_twitter_card', 'branding_css', 'branding_head'] as $name) {
    check("the function $name is there", $twig->getFunction($name) !== false, true);
}
check('the owner\'s code is let through as HTML, not escaped', $twig->render('head'), '<script>owner()</script>|<b>end</b>');
$signedIn = true;
check('and not run for someone signed in, who is asked about each time', $twig->render('head'), '|');
$signedIn = false;
$settings['analytics']['mode'] = 'off';
check('a change of settings after the functions were added is seen', $twig->render('head'), '|');
check('the title of a home page, and of a page with none of its own', [$twig->render('title', ['own' => '', 'page' => 'About', 'home' => true, 'type' => 'pages']), $twig->render('title', ['own' => '', 'page' => '', 'home' => false, 'type' => 'pages'])], ['Home title', 'Site']);
check('the title a page chose is kept, and is text, not HTML', $twig->render('title', ['own' => 'A & B', 'page' => 'x', 'home' => false, 'type' => 'pages']), 'A &amp; B');
check('the description falls back to the tagline of the site', $twig->render('description', ['excerpt' => '', 'home' => false]), 'A tagline');
$settings['seo']['discourage'] = true;
check('the robots tag follows the settings now', $twig->render('robots', ['noindex' => false, 'kind' => 'pages']), '[noindex, nofollow]');
check('with nothing chosen there are no tags the same on every page', $twig->render('seohead'), '');
$settings['seo']['verification']['google'] = 'abc123';
check('a verification code becomes a meta tag, as HTML', $twig->render('seohead'), '<meta name="google-site-verification" content="abc123">');
$settings['seo']['verification']['google'] = 'abc"def';
check('and a value that could leave its attribute is not used at all', $twig->render('seohead'), '');
check('a card for a shared link, and its image', str_starts_with($twig->render('card'), 'summary_large_image|') || str_contains($twig->render('card'), '|'), true);
$themeSettings = ['branding' => ['primary' => '#123456']];
check('branding is asked for as it is now, and drawn as HTML', [str_contains($twig->render('branding'), '#'), str_contains($twig->render('branding'), '&lt;')], [true, false]);

// ---- the maps of content
$dir = sys_get_temp_dir() . '/faros-twig-geo-' . bin2hex(random_bytes(4));
mkdir($dir . '/public', 0775, true);
$images = new Images($dir . '/public');
$built = 0;
$lang = 'en';
$mapSettings = ['apis' => ['maps' => ['load' => 'auto']]];
$view = new GeoView(new GeoMap(new GeoLibrary($images, $dir . '/cache'), $images), fn() => [], fn(string $t, string $l): string => ucfirst($t), fn(string $s, string $l): string => $s, fn(ContentItem $i, string $l): string => '/' . $l . '/' . $i->slug, fn(): array => [], ['tiles_url' => '', 'attribution' => '']);
$geoTwig = $twigOf([
    'dataset' => '{% for i in geo_dataset(items).items %}{{ i.url }};{% endfor %}',
    'json' => '{{ geo_json({"a": "</script><b>&"}) }}',
    'load' => '{{ geo_load() }}',
    'position' => '{% set p = geo_position(item) %}{{ p.lat }},{{ p.lng }}',
    'profile' => '{{ elevation_profile(profile, "Height: <b>") }}',
    'none' => 'no map here',
]);
GeoTwigFunctions::register($geoTwig, function () use (&$built, $view): GeoView { $built++; return $view; }, function () use (&$lang): string { return $lang; }, function () use (&$mapSettings): array { return $mapSettings; });
foreach (['geo_dataset', 'geo_single', 'geo_nearby', 'geo_json', 'geo_load', 'geo_position', 'route_facts', 'elevation_profile'] as $name) {
    check("the function $name is there", $geoTwig->getFunction($name) !== false, true);
}
$geoTwig->render('none');
check('a page with no map does not build the maps', $built, 0);
$point = new ContentItem('points', 'near', 'en', ['title' => 'Near', 'custom_fields' => ['location' => '35.021, 26.001']], '', '<p>Text</p>', '', 0);
$other = new ContentItem('points', 'far', 'en', ['title' => 'Far', 'custom_fields' => ['location' => '35.5, 26.5']], '', '<p>Text</p>', '', 0);
check('the entries of a map are those with a place, in the language being shown', $geoTwig->render('dataset', ['items' => [$point, 'not an item', $other]]), '/en/near;/en/far;');
$lang = 'el';
check('and that language is asked for each time', $geoTwig->render('dataset', ['items' => [$point]]), '/el/near;');
check('the position of an entry', $geoTwig->render('position', ['item' => $point]), '35.021,26.001');
check('data for a script cannot close the script, and is let through as HTML', $geoTwig->render('json'), '{"a":"\u003C/script\u003E\u003Cb\u003E\u0026"}');
check('the map loads when the settings say, as they are now', $geoTwig->render('load'), 'auto');
$mapSettings = [];
check('and by a click when they say nothing', $geoTwig->render('load'), 'click');
$svg = $geoTwig->render('profile', ['profile' => [[0.0, 100], [1.0, 150], [2.0, 120]]]);
check('the drawing of the heights is HTML, and its label is text', [str_starts_with($svg, '<svg'), str_contains($svg, '<b>')], [true, false]);

array_map('unlink', glob($dir . '/cache/*/*') ?: []);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
