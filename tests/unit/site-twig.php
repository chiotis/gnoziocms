<?php
/*
 * The Twig environment of the site (SiteTwig): where templates are looked for, the template functions that depend on the
 * language, the signed-in person, the form token, the content types and the taxonomies, and that what changes during a request
 * is read when a function runs while the `site`, `theme_settings` and `is_admin` globals are taken when the environment is made.
 *   php tests/unit/site-twig.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRuntime, ContentItem, ContentTypes, Images, SingleLayouts, SiteTwig, StructuredData, Taxonomies, Theme};

$root = sys_get_temp_dir() . '/sitetwig' . getmypid();
mkdir("$root/custom/templates", 0775, true);
mkdir("$root/public", 0775, true);
mkdir("$root/content/taxonomies", 0775, true);
mkdir("$root/admin/templates", 0775, true);
symlink($repo . '/themes', "$root/themes");
file_put_contents("$root/admin/templates/probe.twig", 'admin:{{ is_admin ? "yes" : "no" }}');
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$taxonomies = new Taxonomies("$root/content", ['el', 'en']);
$taxonomies->save('projects-kind', 'Kinds', [
    ['id' => 'residential', 'slug' => 'residential', 'labels' => ['en' => 'Residential', 'el' => 'Κατοικίες'], 'descriptions' => []],
    ['id' => 'office', 'slug' => 'office', 'labels' => ['en' => 'Office', 'el' => 'Γραφεία'], 'descriptions' => []],
]);
$settings = ['base_url' => 'https://s.test', 'home_page' => 'index', 'date_format' => 'Y-m-d', 'languages' => ['default' => 'en'], 'title' => 'Site'];
$themeSettings = [];
$lang = 'en';
$signedIn = false;
$allowed = [];
$token = 'tok1';
$strings = ['greeting' => 'Hello'];
$runtimeBuilt = 0;
$sitesLanguages = ['en', 'el'];
$make = static function () use ($root, $theme, $types, $taxonomies, &$settings, &$themeSettings, &$lang, &$signedIn, &$allowed, &$token, &$strings, &$runtimeBuilt, &$sitesLanguages): Twig\Environment { return (new SiteTwig(
    $root, $theme, new Images("$root/public"),
    function () use (&$settings): array { return $settings; },
    function () use (&$themeSettings): array { return $themeSettings; },
    function () use (&$lang): string { return $lang; },
    fn(): string => 'en',
    function () use (&$signedIn): bool { return $signedIn; },
    function (string $c) use (&$allowed): bool { return in_array($c, $allowed, true); },
    function () use (&$token): string { return $token; },
    function (string $k, ?string $f) use (&$strings): string { return $strings[$k] ?? $f ?? $k; },
    fn(string $p): string => 'https://s.test/' . ltrim($p, '/'),
    fn(): StructuredData => throw new RuntimeException('not needed'),
    fn(): ContentTypes => $types,
    fn(): SingleLayouts => new SingleLayouts($theme, $types),
    fn(): Taxonomies => $taxonomies,
    fn(string $t, string $id, ?string $l): string => $taxonomies->label($t, $id, $l ?? 'en'),
    fn(string $t, string $id, string $prefix): string => trim($prefix . '/' . Taxonomies::kind($t) . '/' . $taxonomies->slug($t, $id), '/'),
    function () use (&$runtimeBuilt): BlockRuntime { $runtimeBuilt++; throw new RuntimeException('no block runtime in this test'); },
    fn(): bool => true,
    function () use (&$sitesLanguages): array { return $sitesLanguages; }
))->create(); };
$twig = $make();
$render = static fn(Twig\Environment $e, string $source, array $vars = []): string => $e->createTemplate($source)->render($vars);

// ---- what is there
foreach (['asset', 'icon', 'link_url', 'content_fields', 'content_values', 'single_layout', 'term_hero_layout', 'content_type', 't', 'format_date', 'item_path', 'slug', 'taxonomy_label', 'taxonomy_url', 'item_terms', 'footer_blocks', 'can', 'csrf_token', 'csrf_field', 'feed_url', 'geo_json', 'seo_title', 'analytics_head'] as $name) {
    check("the function $name is there", $twig->getFunction($name) !== false, true);
}
check('and the filter localized', $twig->getFilter('localized') !== false, true);

// ---- where templates are looked for
file_put_contents("$root/custom/templates/mine.twig", 'custom:{{ 1 + 1 }}');
check('a template of the site is found, and one of the admin by @admin', [$render($twig, '{% include "templates/mine.twig" %}'), $render($twig, '{% include "@admin/probe.twig" %}')], ['custom:2', 'admin:no']);
check('the theme is there as @theme', $twig->getLoader()->exists('@theme/templates/single.twig'), true);

// ---- the language, translations, dates and paths
check('a text of the theme, and the fallback for one it does not have', $render($twig, '{{ t("greeting") }}|{{ t("nope", "Fallback") }}|{{ t("nope") }}'), 'Hello|Fallback|nope');
check('a date as the site writes dates now', $render($twig, '{{ format_date("2026-10-19") }}'), '2026-10-19');
$settings['date_format'] = 'd/m/Y';
check('a change of the format after the environment was made is seen', $render($twig, '{{ format_date("2026-10-19") }}'), '19/10/2026');
$page = new ContentItem('pages', 'about', 'en', ['title' => 'About'], '', '', '', 0);
$home = new ContentItem('pages', 'index', 'en', ['title' => 'Home'], '', '', '', 0);
check('the path of an entry after a language prefix; a home page has none; no entry is the prefix', [$render($twig, '{{ item_path(item, "el/") }}', ['item' => $page]), $render($twig, '{{ item_path(item) }}', ['item' => $home]), $render($twig, '{{ item_path("x", "el/") }}')], ['el/about', '', 'el/']);
check('a text for a language of the page, or the language being shown', [$render($twig, '{{ v|localized }}', ['v' => ['en' => 'A', 'el' => 'Α'], 'lang' => 'el']), $render($twig, '{{ v|localized }}', ['v' => ['en' => 'A', 'el' => 'Α']])], ['Α', 'A']);
$lang = 'el';
check('and that language is asked for each time', $render($twig, '{{ v|localized }}', ['v' => ['en' => 'A', 'el' => 'Α']]), 'Α');
$lang = 'en';
check('a name becomes a slug', $render($twig, '{{ slug("Hello World") }}'), 'hello-world');
check('something that is not an entry has no fields and no values', $render($twig, '{{ content_fields("nothing")|length }}|{{ content_values("nothing")|length }}'), '0|0');
check('a content type has a definition', $render($twig, '{{ content_type("pages").fields is defined ? "y" : "n" }}'), 'y');

// ---- people and forms
check('what a person may do is asked each time', [$render($twig, '{{ can("content.edit") ? "y" : "n" }}')], ['n']);
$allowed = ['content.edit'];
check('and answered as it is now', $render($twig, '{{ can("content.edit") ? "y" : "n" }}'), 'y');
check('the form token, and the field that holds it as HTML, escaped inside', [$render($twig, '{{ csrf_token() }}'), $render($twig, '{{ csrf_field() }}')], ['tok1', '<input type="hidden" name="_csrf" value="tok1">']);
$token = 'a"b';
check('a token that could leave its attribute cannot', $render($twig, '{{ csrf_field() }}'), '<input type="hidden" name="_csrf" value="a&quot;b">');
check('the feed address, in the language of the page', $render($twig, '{{ feed_url("el/") }}'), 'https://s.test/el/feed');

// ---- taxonomies
$entry = new ContentItem('projects', 'p', 'en', ['title' => 'P', 'projects-kind' => ['office', 'unknown', 'residential']], '', '', '', 0);
check('the terms an entry has in a taxonomy the site added, known ones only, each with a page', $render($twig, '{% for g in item_terms(item, "el") %}{{ g.name }}/{{ g.title }}:{% for t in g.terms %}{{ t.label }}={{ t.url }};{% endfor %}{% endfor %}', ['item' => $entry]), 'projects-kind/Kinds:Office=https://s.test/el/projects-kind/office;Residential=https://s.test/el/projects-kind/residential;');
check('an item that is not an entry has none', $render($twig, '{{ item_terms(0)|length }}'), '0');
check('the label and the address of one term', $render($twig, '{{ taxonomy_label("projects-kind", "office") }}|{{ taxonomy_label("projects-kind", "office", "el") }}|{{ taxonomy_url("projects-kind", "office", "el") }}'), 'Office|Γραφεία|https://s.test/el/projects-kind/office');
check('no title area is asked of a term that names none', $render($twig, '[{{ term_hero_layout(item) }}][{{ term_hero_layout(0) }}]', ['item' => $entry]), '[][]');

// ---- footer blocks: nothing built when the theme has none
check('with no footer blocks there is nothing and no block runtime is built', [$render($twig, '{{ footer_blocks().html }}|{{ footer_blocks().styles|length }}'), $runtimeBuilt], ['|0', 0]);

// ---- globals are taken when the environment is made
$themeSettings = ['k' => 'v'];
$signedIn = true;
$sitesLanguages = ['el'];
$settings['title'] = 'Changed';
$twig2 = $make();
check('the globals of a new environment', $render($twig2, '{{ site.title }}|{{ theme_settings.k }}|{{ is_admin ? "admin" : "visitor" }}|{{ admin_languages|join(",") }}|{{ theme.name }}'), 'Changed|v|admin|el|default');
check('and those of the old one stay as they were', $render($twig, '{{ site.title }}|{{ is_admin ? "admin" : "visitor" }}|{{ admin_languages|join(",") }}'), 'Site|visitor|en,el');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
