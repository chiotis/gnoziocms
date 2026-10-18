<?php
/*
 * Single layouts: what the page of one entry does for each content type (page layout, title area, header, parts, sidebar),
 * what a site that saved the older Hero Layouts, Transparent Header and Sidebar settings keeps, what a submitted card stores,
 * and the layouts offered to a single entry.
 *   php tests/unit/single-layouts.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{SingleLayouts, Theme};

$root = sys_get_temp_dir() . '/single' . getmypid();
mkdir("$root/custom/templates", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$theme = new Theme($root, 'default');
$single = new SingleLayouts($theme);

// ---- what the theme offers
check('the theme declares single layouts', $single->declared(), true);
check('with these choices', array_keys($single->fields()), ['title', 'header', 'sidebar', 'image', 'parallax', 'excerpt', 'breadcrumbs', 'byline', 'toc', 'related', 'card']);
check('and these page layouts', array_keys($single->templates()), ['default', 'landing', 'sidebar']);
check('the styles of title area', array_keys($single->titleChoices()), ['default', 'centered', 'split', 'cover', 'minimal']);
check('and what an entry can ask of the header: not "follow the site"', array_keys($single->headerChoices()), ['on', 'off']);

// ---- a type nobody chose for has the defaults, even one the theme has never heard of
$none = $single->forType('events', []);
check('defaults for a new type', $none, ['title' => 'default', 'header' => 'site', 'sidebar' => 'none', 'image' => true, 'parallax' => false, 'excerpt' => true, 'breadcrumbs' => false, 'byline' => true, 'toc' => true, 'related' => true, 'card' => true, 'template' => 'default']);

// ---- the older settings are the starting point
$legacy = [
    'hero_layouts' => ['default' => 'minimal', 'posts' => 'split', 'pages' => 'default', 'projects' => 'default', 'forms' => 'default'],
    'transparent_header' => ['default' => 'off', 'pages' => 'on', 'posts' => 'site'],
    'sidebar' => ['toc' => false, 'related' => true],
];
check('a type the old settings name keeps its title layout', $single->forType('posts', $legacy)['title'], 'split');
check('a type they do not name follows the old default', $single->forType('books', $legacy)['title'], 'minimal');
check('the header comes from Transparent Header', [$single->forType('pages', $legacy)['header'], $single->forType('posts', $legacy)['header'], $single->forType('books', $legacy)['header']], ['on', 'site', 'off']);
check('contents and related pages from the sidebar settings', [$single->forType('pages', $legacy)['toc'], $single->forType('pages', $legacy)['related']], [false, true]);

// ---- what is stored wins
$stored = $legacy + ['single_layouts' => ['posts' => ['title' => 'cover', 'header' => 'on', 'sidebar' => 'left', 'toc' => true, 'template' => 'default', 'image' => false]]];
$posts = $single->forType('posts', $stored);
check('a stored choice wins over the old one', [$posts['title'], $posts['header'], $posts['sidebar'], $posts['toc'], $posts['image']], ['cover', 'on', 'left', true, false]);
check('and the page layout is kept, the sidebar being a choice of its own', [$posts['template'], $single->effectiveTemplate('posts', $stored)], ['default', 'sidebar']);
check('what the card left out comes from the old settings and the defaults', [$posts['excerpt'], $posts['related']], [true, true]);
check('another type is not touched', $single->forType('pages', $stored)['title'], 'default');
$bad = $single->forType('posts', ['single_layouts' => ['posts' => ['title' => 'nonsense', 'sidebar' => 'up', 'template' => 'missing', 'image' => 'yes']]]);
check('a value the theme does not offer falls back', [$bad['title'], $bad['sidebar'], $bad['template']], ['default', 'none', 'default']);

// ---- a submitted card
$out = $single->fromInput([
    'posts' => ['title' => 'centered', 'header' => 'off', 'template' => 'landing', 'sidebar' => 'left', 'image' => '1', 'toc' => '1'],
    'pages' => ['title' => 'nonsense', 'template' => 'missing'],
], ['pages', 'posts', 'projects', 'forms'], $legacy);
check('a card stores its choices', [$out['posts']['title'], $out['posts']['header'], $out['posts']['template'], $out['posts']['sidebar']], ['centered', 'off', 'landing', 'left']);
check('a box that was not ticked is off', [$out['posts']['image'], $out['posts']['excerpt'], $out['posts']['byline'], $out['posts']['toc'], $out['posts']['card']], [true, false, false, true, false]);
check('a value the theme does not offer keeps what the type has', [$out['pages']['title'], $out['pages']['template']], ['default', 'default']);
check('a type with no card keeps what it had, and is stored', [$out['projects']['title'], $out['forms']['header']], ['default', 'off']);
check('every type is in what is stored', array_keys($out), ['pages', 'posts', 'projects', 'forms']);
check('a page layout the theme offers is stored', $single->fromInput(['posts' => ['template' => 'landing']], ['posts'], [])['posts']['template'], 'landing');
check('the sidebar is not a page layout of a card (it has its own choice), and neither is an unknown one', [$single->fromInput(['posts' => ['template' => 'sidebar']], ['posts'], [])['posts']['template'], $single->fromInput(['posts' => ['template' => '../x']], ['posts'], [])['posts']['template']], ['default', 'default']);
check('a card stores the sidebar, and a landing page has none', [$single->fromInput(['posts' => ['sidebar' => 'right']], ['posts'], [])['posts']['sidebar'], $single->effectiveTemplate('posts', ['single_layouts' => ['posts' => ['sidebar' => 'right', 'template' => 'landing']]])], ['right', 'landing']);
check('a type that had the sidebar template keeps its sidebar, on the right', [$single->forType('posts', ['single_layouts' => ['posts' => ['template' => 'sidebar']]])['sidebar'], $single->effectiveTemplate('posts', ['single_layouts' => ['posts' => ['template' => 'sidebar']]])], ['right', 'sidebar']);

// ---- the layouts an entry can choose
check('without a layout of its own for the type, the entry chooses among the theme\'s', array_keys($single->templatesFor('posts', [])), ['default', 'landing', 'sidebar']);
$for = $single->templatesFor('posts', $stored);
check('with one, default follows it and the plain one has a name', array_keys($for), ['default', 'standard', 'landing', 'sidebar']);
check('and default says what it follows', $for['default']['label'], 'Like the others (With sidebar)');

// ---- which templates draw a title area
check('the standard page has one', $theme->usesTitleArea('templates/single-page.twig'), true);
check('so does the post, and it adds a line above the title', [$theme->usesTitleArea('templates/single-post.twig'), str_contains($theme->templateSource('templates/single-post.twig'), 'hero_meta')], [true, true]);
check('the book page does not', $theme->usesTitleArea('templates/single-book.twig'), false);
check('a template that does not exist has none', [$theme->templateSource('templates/nothing.twig'), $theme->templateSource('../theme.yaml')], ['', '']);
check('the page of a type is the first template that exists', [$theme->defaultSingleTemplate('posts', 'post'), $theme->defaultSingleTemplate('books', 'book'), $theme->defaultSingleTemplate('events', 'event'), $theme->defaultSingleTemplate('pages', 'page')], ['templates/single-post.twig', 'templates/single-book.twig', 'templates/single.twig', 'templates/single-page.twig']);
file_put_contents("$root/custom/templates/single-event.twig", "{% include 'components/page-header.twig' %}");
check('the site\'s own template takes over, and is read from there', [$theme->defaultSingleTemplate('events', 'event'), $theme->usesTitleArea('templates/single-event.twig')], ['templates/single-event.twig', true]);

// ---- the options a content type declares for its page (books: where the cover goes, what shows)
$books = $single->forType('books', []);
check('a type that declares options has them, at their defaults', $books['options'], ['cover' => 'left', 'cover_style' => 'book', 'show_author' => true, 'show_summary' => true, 'show_facts' => true, 'show_buy' => true]);
check('a type that declares none has no options key, and the others\' values are as before', array_key_exists('options', $single->forType('posts', [])), false);
$saved = ['single_layouts' => ['books' => ['options' => ['cover' => 'right', 'show_buy' => false, 'bogus' => 'x']]]];
check('stored options are read, checked against the declaration, and the unknown ones dropped', $single->forType('books', $saved)['options'], ['cover' => 'right', 'cover_style' => 'book', 'show_author' => true, 'show_summary' => true, 'show_facts' => true, 'show_buy' => false]);
check('a value the option does not offer falls back to its default', $single->forType('books', ['single_layouts' => ['books' => ['options' => ['cover' => 'zzz']]]])['options']['cover'], 'left');
$out = $single->fromInput(['books' => ['sidebar' => 'left', 'options' => ['cover' => 'top', 'cover_style' => 'book', 'show_author' => '1', 'show_facts' => '1']]], ['books', 'posts'], $saved);
check('a submitted card sets the options: toggles not sent are off, choices not offered are ignored', $out['books']['options'], ['cover' => 'top', 'cover_style' => 'book', 'show_author' => true, 'show_summary' => false, 'show_facts' => true, 'show_buy' => false]);
check('another type\'s card is left alone', array_key_exists('options', $out['posts']), false);
$out = $single->fromInput(['books' => ['options' => ['cover' => 'nonsense']]], ['books'], $saved);
check('an invalid choice keeps the one stored', $out['books']['options']['cover'], 'right');
check('a type whose own template draws the sidebar keeps its template: no switch to the sidebar layout', [$single->effectiveTemplate('books', ['single_layouts' => ['books' => ['sidebar' => 'right']]]), $single->effectiveTemplate('posts', ['single_layouts' => ['posts' => ['sidebar' => 'right']]])], ['default', 'sidebar']);
check('and what a type declares of its own page', [$single->declaredBy('books')['sidebar'], $single->declaredBy('books')['header'], $single->declaredBy('posts')['sidebar'], array_keys($single->declaredBy('books')['options'])], [true, true, false, ['cover', 'cover_style', 'show_author', 'show_summary', 'show_facts', 'show_buy']]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
