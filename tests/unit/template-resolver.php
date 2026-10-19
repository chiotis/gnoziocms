<?php
/*
 * Which template draws a public page (TemplateResolver): an entry's own `template:`, the type's template, the page layout chosen
 * for the type, the archive of a type, the page of a category or tag, how a name is written, and the singular of a type.
 *   php tests/unit/template-resolver.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, SingleLayouts, TemplateResolver, Theme};

$root = sys_get_temp_dir() . '/tplres' . getmypid();
mkdir("$root/custom/templates", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$theme = new Theme($root, 'default');
$themeSettings = [];
$resolver = new TemplateResolver($theme, new SingleLayouts($theme), function () use (&$themeSettings): array { return $themeSettings; });
$entry = static fn(string $type, array $meta = []): ContentItem => new ContentItem($type, 'x', 'en', $meta, '', '', '', 0);

// ---- how a name is written, and the singular of a type
check('a bare name, with .twig, and the full path are the same template', [TemplateResolver::normalize('landing'), TemplateResolver::normalize('landing.twig'), TemplateResolver::normalize('templates/landing.twig')], ['templates/landing.twig', 'templates/landing.twig', 'templates/landing.twig']);
check('nothing, or a path that leaves the templates, is no template', [TemplateResolver::normalize('  '), TemplateResolver::normalize('../secret')], ['', '']);
check('the singular of a type', array_map([TemplateResolver::class, 'singular'], ['businesses', 'stories', 'routes', 'news', 's', 'x', 'ies']), ['business', 'story', 'route', 'new', 's', 'x', 'ie']);

// ---- an entry
check('a page uses the page template of the theme', $resolver->forItem($entry('pages')), $theme->defaultSingleTemplate('pages', 'page'));
check('an entry that asks for a template the theme has gets it', $resolver->forItem($entry('pages', ['template' => 'landing'])), 'templates/landing.twig');
check('and one that asks for a template nobody has gets the usual', $resolver->forItem($entry('pages', ['template' => 'nothing-like-it'])), $theme->defaultSingleTemplate('pages', 'page'));
check('the plain layout is the type\'s own, whatever the type chose', (function () use (&$themeSettings, $resolver, $entry, $theme) {
    $themeSettings = ['single_layouts' => ['pages' => ['template' => 'landing']]];
    return $resolver->forItem($entry('pages', ['template' => 'standard'])) === $theme->defaultSingleTemplate('pages', 'page');
})(), true);
$themeSettings = ['single_layouts' => ['pages' => ['template' => 'landing']]];
check('the page layout chosen for the type applies, read as the settings are now', $resolver->forItem($entry('pages')), 'templates/landing.twig');
$themeSettings = [];
check('and stops applying when the settings change', $resolver->forItem($entry('pages')), $theme->defaultSingleTemplate('pages', 'page'));
$themeSettings = ['single_layouts' => ['forms' => ['template' => 'landing']]];
check('a form never takes a page layout', $resolver->forItem($entry('forms')), $theme->defaultSingleTemplate('forms', 'form'));

// ---- the archive of a type, the page of a term
check('an archive falls back to the general archive', $resolver->forArchive('unknowns'), 'templates/archive.twig');
file_put_contents("$root/custom/templates/archive-unknowns.twig", 'x');
check('a site template for the type wins', $resolver->forArchive('unknowns'), 'templates/archive-unknowns.twig');
file_put_contents("$root/custom/templates/archive-widget.twig", 'x');
check('so does one named for its singular', $resolver->forArchive('widgets'), 'templates/archive-widget.twig');
check('a term page falls back to the archive', $resolver->forTaxonomy('category', 'news'), 'templates/archive.twig');
file_put_contents("$root/custom/templates/archive-category-news.twig", 'x');
check('a template for the one term wins', $resolver->forTaxonomy('category', 'news'), 'templates/archive-category-news.twig');
check('and the slug cannot name another file', $resolver->forTaxonomy('category', '../news'), 'templates/archive-category-news.twig');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
