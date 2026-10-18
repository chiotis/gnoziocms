<?php
/*
 * Adding the demo site of starter/ to a site that has content: only what is missing is copied, in the groups chosen, and
 * nothing that exists is ever replaced.
 *   php tests/unit/starter-content.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\StarterContent;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$base = sys_get_temp_dir() . '/starter' . getmypid();
$put = static function (string $path, string $text): void { @mkdir(dirname($path), 0775, true); file_put_contents($path, $text); };
register_shutdown_function(function () use ($base) { @exec('rm -rf ' . escapeshellarg($base)); });

// A small demo: two pages, a post, a book, a taxonomy, a menu, a picture with its description, a file, and a hidden file.
$put("$base/starter/content/pages/about.md", "about");
$put("$base/starter/content/pages/contact.md", "contact");
$put("$base/starter/content/pages/contact.en.md", "contact en");
$put("$base/starter/content/posts/news.md", "news");
$put("$base/starter/content/books/dune.md", "dune");
$put("$base/starter/content/taxonomies/genre.yaml", "title: Genre\n");
$put("$base/starter/content/menus/main.yaml", "title: Main\n");
$put("$base/starter/content/forms/.gitkeep", "");
$put("$base/starter/content/media/abc.yaml", "id: abc\n");
$put("$base/starter/uploads/media/abc.png", "png");
$put("$base/starter/uploads/files/sheet.csv", "a,b");
$site = "$base/site";
$put("$site/content/pages/about.md", "MY OWN ABOUT");
$demo = new StarterContent($base, "$site/content", "$site/public/uploads");

check('a copy of the code with a starter has a demo to offer, one without has none', [$demo->available(), (new StarterContent("$base/nothing", "$site/content", "$site/public/uploads"))->available()], [true, false]);
$groups = $demo->groups();
check('the groups are offered in order, with what is new in each and what the demo holds', array_map(fn($g) => [$g['label'], $g['new'], $g['total']], $groups), [
    'pages' => ['Pages', 2, 3], 'posts' => ['Posts', 1, 1], 'books' => ['Books', 1, 1], 'taxonomies' => ['Taxonomies', 1, 1], 'media' => ['Pictures and files', 3, 3],
]);
check('a group that holds nothing (hidden files only) is not offered, and the menus never are', [isset($groups['forms']), isset($groups['menus'])], [false, false]);

$r = $demo->add(['pages', 'books', 'nonsense']);
check('the chosen groups are copied: the pages that were missing and the book; a name that is no group is ignored', [$r['added'], $r['groups'], $r['failed']], [3, ['pages' => 2, 'books' => 1], 0]);
check('what the site had is not replaced', file_get_contents("$site/content/pages/about.md"), 'MY OWN ABOUT');
check('what was missing is there, a language of a page too', [file_get_contents("$site/content/pages/contact.md"), file_get_contents("$site/content/pages/contact.en.md"), file_get_contents("$site/content/books/dune.md")], ['contact', 'contact en', 'dune']);
check('a group that was not chosen is not copied', [is_file("$site/content/posts/news.md"), is_file("$site/content/taxonomies/genre.yaml")], [false, false]);
check('the menus and the hidden files are never copied', [is_file("$site/content/menus/main.yaml"), is_file("$site/content/forms/.gitkeep")], [false, false]);

$r = $demo->add(['pages', 'books']);
check('doing it again adds nothing', [$r['added'], $r['groups']], [0, []]);
check('and the screen says what is left', array_map(fn($g) => $g['new'], $demo->groups()), ['pages' => 0, 'posts' => 1, 'books' => 0, 'taxonomies' => 1, 'media' => 3]);

$r = $demo->add(['posts', 'taxonomies', 'media']);
check('the rest follows: posts, taxonomies, and the pictures with their descriptions', [$r['added'], is_file("$site/content/posts/news.md"), is_file("$site/content/taxonomies/genre.yaml"), is_file("$site/content/media/abc.yaml"), is_file("$site/public/uploads/media/abc.png"), is_file("$site/public/uploads/files/sheet.csv")], [5, true, true, true, true, true]);
check('everything is there now', array_sum(array_map(fn($g) => $g['new'], $demo->groups())), 0);

// A file that appears between the screen and the click is not replaced either.
unlink("$site/content/posts/news.md");
$put("$base/starter/content/posts/other.md", "other");
$r = $demo->add(['posts']);
check('a file that was deleted by the site comes back only because it is missing; a new one of the demo is added', [$r['added'], file_get_contents("$site/content/posts/other.md")], [2, 'other']);
file_put_contents("$site/content/posts/news.md", 'EDITED');
$demo->add(['posts']);
check('and an edited one stays as edited', file_get_contents("$site/content/posts/news.md"), 'EDITED');

// A folder that cannot be written is counted, not hidden.
$put("$base/starter/content/books/another.md", "x");
chmod("$site/content/books", 0555);
$r = $demo->add(['books']);
chmod("$site/content/books", 0775);
check('a file that cannot be written is counted as failed', [$r['added'], $r['failed']], posix_geteuid() === 0 ? [1, 0] : [0, 1]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
