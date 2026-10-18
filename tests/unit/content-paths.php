<?php
/*
 * Where an entry is on the site: pages and posts at the root (/about, /my-post), every other type under its name
 * (/projects/mine), the home page at the root of its language, and how an address is read back into a kind of page.
 *   php tests/unit/content-paths.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentPaths, FrontRoute};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

check('pages and posts are the types at the root', [ContentPaths::ROOT_TYPES, ContentPaths::isRoot('pages'), ContentPaths::isRoot('posts'), ContentPaths::isRoot('projects'), ContentPaths::isRoot('forms')], [['pages', 'posts'], true, true, false, false]);
$path = fn(string $type, string $slug, string $lang = 'el') => ContentPaths::build($type, $slug, $lang, 'index', 'el');
check('a page is at the root, in the default language and in another', [$path('pages', 'about'), $path('pages', 'about', 'en')], ['about', 'en/about']);
check('a post is at the root too', [$path('posts', 'hello'), $path('posts', 'hello', 'en')], ['hello', 'en/hello']);
check('the home page is the root of its language', [$path('pages', 'index'), $path('pages', 'index', 'en')], ['', 'en']);
check('a post that has the name of the home page is a post, not the home page', $path('posts', 'index'), 'index');
check('every other type keeps its name in front', [$path('projects', 'mine'), $path('projects', 'mine', 'en'), $path('forms', 'contact'), $path('points', 'spring', 'en')], ['projects/mine', 'en/projects/mine', 'forms/contact', 'en/points/spring']);
check('the list of a type is under its name, posts as well', [ContentPaths::archive('posts', 'el', 'el'), ContentPaths::archive('posts', 'en', 'el'), ContentPaths::archive('projects', 'en', 'el')], ['posts', 'en/posts', 'en/projects']);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index'];
check('a settings object gives the same', [(new ContentPaths($settings))->publicPath('posts', 'hello', 'en'), (new ContentPaths($settings))->publicPath('pages', 'index', 'el')], ['en/hello', '']);

// ---- reading an address back
$types = ['pages', 'posts', 'projects', 'forms'];
$kind = fn(string $p) => (function (array $r): array { return [$r['kind'], $r['type'], $r['slug'], $r['lang']]; })(FrontRoute::resolve($p, $settings, $types));
check('one word is a page or a post (which of the two is for the site to find out)', [$kind('about'), $kind('hello-post'), $kind('en/hello-post')], [['page', '', 'about', 'el'], ['page', '', 'hello-post', 'el'], ['page', '', 'hello-post', 'en']]);
check('the old address of a post is an entry of the type, and the list is the list', [$kind('posts/hello'), $kind('en/posts/hello'), $kind('posts')], [['entry', 'posts', 'hello', 'el'], ['entry', 'posts', 'hello', 'en'], ['archive', 'posts', '', 'el']]);
check('another type is an entry under its name', $kind('projects/mine'), ['entry', 'projects', 'mine', 'el']);
check('nothing is after an address', [$kind('hello/more')[0], $kind('projects/mine/more')[0]], ['not_found', 'not_found']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
