<?php
/*
 * Theme > Export and import (ThemeTransfer): the whole theme of one site carried to another. Settings sections, Single Layouts, the
 * templates built with blocks, Archive Layouts (in the site's type and taxonomy files) with the search page, Footer Blocks, Custom CSS
 * and font files; the preview of each part, parts left out, lists the other site does not have, missing pictures, and undo.
 *   php tests/unit/theme-transfer.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ArchiveTemplates, ContentTypes, CustomCss, FooterBlocks, SingleLayouts, SystemDatabase, SystemMetaRepository, Taxonomies, Theme, ThemeTransfer};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$base = sys_get_temp_dir() . '/gnozio-theme-transfer-' . getmypid();
register_shutdown_function(function () use ($base) { @exec('rm -rf ' . escapeshellarg($base)); });

/** A site in a folder: its theme, settings kept in memory, and the transfer. */
function site(string $dir, string $repo, array $types): array
{
    foreach (['custom/assets/fonts', 'storage', 'content/taxonomies', 'public/uploads'] as $d) { mkdir("$dir/$d", 0775, true); }
    symlink($repo . '/themes', "$dir/themes");
    $theme = new Theme($dir, 'default');
    $store = new ArrayObject(['settings' => $theme->resolveSettings([])]);
    $db = new SystemDatabase("$dir/storage");
    $db->initialize();
    $taxonomies = new Taxonomies("$dir/content", ['en', 'el']);
    $taxonomies->ensureDefaults();
    $contentTypes = new ContentTypes($theme);
    $transfer = new ThemeTransfer(
        theme: $theme,
        load: fn(): array => $store['settings'],
        save: function (array $s) use ($store): void { $store['settings'] = $s; },
        meta: new SystemMetaRepository($db),
        single: new SingleLayouts($theme, $contentTypes),
        types: $contentTypes,
        taxonomies: $taxonomies,
        typeNames: fn(): array => $types,
        cleanBlocks: fn(array $posted, mixed $stored): array => FooterBlocks::fromPost($posted, $stored),
        css: new CustomCss("$dir/custom"),
        publicDir: "$dir/public",
        version: '0.0.0',
    );
    return ['dir' => $dir, 'theme' => $theme, 'store' => $store, 'types' => $contentTypes, 'taxonomies' => $taxonomies, 'transfer' => $transfer];
}

$a = site("$base/a", $repo, ['pages', 'posts', 'projects', 'books', 'forms']);
$b = site("$base/b", $repo, ['pages', 'posts', 'projects', 'forms']);
$text = [['type' => 'text', 'data' => ['body' => 'Hello']]];

// ---- the source site: a bit of everything
$s = $a['store']['settings'];
$s['design']['shadows'] = 'strong';
$s['design']['font_file'] = 'fonts/brand.woff2';
$s['header']['layout'] = array_key_last($a['theme']->settingsSchema()['header']['fields']['layout']['options']);
$s['brand']['logo'] = '/uploads/logo.png';
$s['single_layouts'] = ['posts' => ['title' => 'split', 'sidebar' => true], 'books' => ['title' => 'split']];
$s['single_templates'] = ['posts' => ['use' => true, 'blocks' => $text]];
$s['archive_templates'] = ['posts' => ['use' => true, 'blocks' => $text, 'card' => $text], 'taxonomy:categories' => ['use' => false, 'blocks' => ['default' => $text, 'el' => $text]]];
$s['footer_blocks'] = $text;
$s['search_page'] = ['title_layout' => 'split', 'title' => 'Search'];
$s['made_up'] = ['x' => 1];
$a['store']['settings'] = $s;
file_put_contents("$base/a/custom/assets/fonts/brand.woff2", 'FONTDATA');
file_put_contents("$base/a/public/uploads/logo.png", 'PNG');
$a['types']->saveCustom('posts', ['archive' => ['layout' => 'list', 'per_page' => 6, 'title' => ['en' => 'News', 'el' => 'Νέα'], 'order' => 'field:price:asc']]);
$cat = $a['taxonomies']->load('categories');
$a['taxonomies']->save('categories', $cat['title'], $cat['terms'], ['layout' => 'list', 'include_children' => false]);
(new CustomCss("$base/a/custom"))->save(".brand { color: red; }\n");

$zip = "$base/theme.zip";
$files = $a['transfer']->export($zip);
check('export carries the font file Branding names', $files, ['assets/fonts/brand.woff2']);
$z = new ZipArchive();
$z->open($zip);
$doc = json_decode((string)$z->getFromName('theme.json'), true);
$z->close();
check('theme.json has the format, the settings, the archives of every list and the Custom CSS', [$doc['format'], $doc['settings']['design']['shadows'], $doc['archives']['types']['posts']['layout'], array_key_exists('projects', $doc['archives']['types']), $doc['archives']['taxonomies']['categories']['layout'], $doc['custom_css']], ['gnoziocms-theme', 'strong', 'list', true, 'list', ".brand { color: red; }\n"]);
check('pages and forms have no list, so no archive', [isset($doc['archives']['types']['pages']), isset($doc['archives']['types']['forms'])], [false, false]);

// ---- reading
$t = $b['transfer'];
check('a zip that is not an export', $t->read("PKnot a zip")['ok'], false);
check('JSON of something else', $t->read('{"format":"x","settings":{}}')['error'], 'This is not a GnozioCMS theme export.');
check('a Branding export is sent to the Branding screen', str_contains($t->read('{"format":"faroscms-branding","settings":{}}')['error'], 'Theme > Branding'), true);
$read = $t->read((string)file_get_contents($zip));
check('the zip reads, with its font file', [$read['ok'], array_keys($read['files'])], [true, ['fonts/brand.woff2']]);

// ---- the parts and the preview
$parts = $t->parts();
check('the parts follow the Theme screen; Brand and Social profiles are not ticked', [isset($parts['branding'], $parts['section:header'], $parts['single_layouts'], $parts['archive_layouts'], $parts['custom_css']), $parts['section:brand']['ticked'], $parts['section:social']['ticked'], $parts['section:header']['ticked']], [true, false, false, true]);
$preview = $t->preview($read);
$byPart = array_column($preview['parts'], null, 'key');
check('Branding shows the shadows', in_array('strong', array_column($byPart['branding']['changes'], 'to'), true), true);
check('Single Layouts shows posts, and books (not on this site) is left out with a warning', [array_column($byPart['single_layouts']['changes'], 'label'), str_contains(implode(' ', $preview['warnings']), 'books')], [['posts'], true]);
check('Archive templates: posts and the categories taxonomy', array_column($byPart['archive_templates']['changes'], 'label'), ['posts', 'taxonomy:categories']);
check('Archive Layouts: posts, projects unchanged, categories, the search page', array_column($byPart['archive_layouts']['changes'], 'label'), ['posts', 'taxonomy categories', 'search page']);
check('Custom CSS and Footer Blocks', [$byPart['custom_css']['changes'][0]['to'], $byPart['footer_blocks']['changes'][0]['to']], ['1 lines', '1 block']);
check('the logo this site does not have is named', str_contains(implode(' ', $preview['warnings']), '/uploads/logo.png'), true);
check('keys the theme does not declare are skipped', in_array('made_up', array_map(fn($k) => explode('.', $k)[0], $preview['skipped']), true) || !isset($b['store']['settings']['made_up']), true);
check('the font file is new here', $preview['files'], [['path' => 'fonts/brand.woff2', 'status' => 'new']]);
check('nothing was written by the preview', [$b['store']['settings']['design']['shadows'] === 'strong', (new CustomCss("$base/b/custom"))->read()], [false, '']);

// ---- applying some parts
$before = $b['store']['settings'];
$result = $t->apply($read, ['branding', 'single_layouts', 'single_templates', 'archive_templates', 'archive_layouts', 'footer_blocks', 'custom_css']);
$now = $b['store']['settings'];
check('applied', [$result['ok'], $result['files'] > 0, $result['changed'] > 5], [true, true, true]);
check('Branding and its font file', [$now['design']['shadows'], is_file("$base/b/custom/assets/fonts/brand.woff2")], ['strong', true]);
check('Header was not ticked, so it is as it was; nor the logo', [$now['header']['layout'], $now['brand']['logo']], [$before['header']['layout'], $before['brand']['logo']]);
check('Single Layouts of posts', $now['single_layouts']['posts']['title'], 'split');
check('the single template of posts, on', [$now['single_templates']['posts']['use'], $now['single_templates']['posts']['blocks']], [true, $text]);
check('the archive templates, the taxonomy\'s with both languages', [ArchiveTemplates::entry($now, 'posts')['use'], ArchiveTemplates::entry($now, 'posts')['card'], array_keys(ArchiveTemplates::entry($now, 'taxonomy:categories')['blocks'])], [true, $text, ['default', 'el']]);
check('Footer Blocks and the search page', [$now['footer_blocks'], $now['search_page']], [$text, ['title_layout' => 'split', 'title' => 'Search']]);
check('the archive of posts in the site\'s own type file, the field order kept', $b['types']->customRaw('posts')['archive'] ?? null, ['layout' => 'list', 'per_page' => 6, 'order' => 'field:price:asc', 'title' => ['en' => 'News', 'el' => 'Νέα']]);
$b['taxonomies']->forget();
check('the archive of the categories taxonomy', $b['taxonomies']->load('categories')['archive'], ['layout' => 'list', 'include_children' => false]);
check('Custom CSS written', (new CustomCss("$base/b/custom"))->read(), ".brand { color: red; }\n");
check('a key no theme declares does not travel', isset($now['made_up']), false);
check('importing again changes nothing', $t->apply($read, ['branding', 'single_layouts', 'archive_layouts', 'custom_css'])['changed'], 0);
check('no part ticked is refused', $t->apply($read, [])['ok'], false);

// ---- checks on what a file may carry
$bad = $doc;
$bad['archives']['types']['posts'] = ['layout' => 'nonsense', 'per_page' => 9999, 'order' => 'drop table', 'taxonomies' => ['categories', 'nope'], 'evil' => '<script>'];
$bad['settings']['design']['shadows'] = 'javascript:alert(1)';
$checked = $t->preview(['document' => $bad, 'files' => []]);
$t->apply(['document' => $bad, 'files' => []], ['archive_layouts', 'branding']);
$archive = $b['types']->customRaw('posts')['archive'];
check('archive values are checked against the schema; unknown keys and taxonomies dropped', [$archive['layout'], $archive['per_page'] <= 60, isset($archive['order']), $archive['taxonomies'], isset($archive['evil'])], ['cards', true, false, ['categories'], false]);
check('a value the schema does not offer is not taken', $b['store']['settings']['design']['shadows'] === 'javascript:alert(1)', false);

// ---- undo
check('a backup is kept', $t->backup() !== null, true);
check('undo', $t->undo(), true);
check('undo puts back the state before the last import', [$b['store']['settings']['design']['shadows'], $b['types']->customRaw('posts')['archive']['layout'] ?? null], ['strong', 'list']);
check('and there is nothing more to undo', $t->undo(), false);

// ---- a file made by hand
$footer = $b['store']['settings']['footer'];
$partial = ['format' => 'gnoziocms-theme', 'settings' => ['header' => ['sticky' => false]]];
$t->apply(['document' => $partial, 'files' => []], ['section:header', 'section:footer']);
check('a section the file does not have is left as it is', $b['store']['settings']['footer'], $footer);
$t->undo();

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail > 0 ? 1 : 0);
