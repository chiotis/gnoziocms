<?php
/*
 * Links inside content that point at an address of the site: which are found and which are left alone.
 *   php tests/unit/links.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\LinkScanner;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/links' . getmypid();
foreach (['pages', 'posts', 'forms', 'users', 'menus', 'taxonomies'] as $d) { mkdir("$dir/$d", 0775, true); }
$s = new LinkScanner($dir, ['el', 'en'], 'el', ['mysite.test', 'MYSITE.example']);
$count = fn(string $text, string $source = 'old') => (function () use ($s, $text, $source) { $r = $s->rewrite($text, [$source => '/new']); return $r[1]; })();

// ---- what counts as a link to the address
foreach ([
    'markdown link' => '[a](/old)',
    'with an anchor' => '[a](/old#part)',
    'with a query string' => '[a](/old?x=1)',
    'html, double quotes' => '<a href="/old">a</a>',
    'html, single quotes' => "<a href='/old'>a</a>",
    'front matter' => "url: /old\n",
    'quoted in front matter' => "url: '/old'\n",
    'with a trailing slash' => '[a](/old/)',
    'in angle brackets' => '<https://mysite.test/old>',
    'full address on this site' => '[a](https://mysite.test/old)',
    'full address, other case of host' => '[a](http://MySite.Example/old/)',
    'full address with a port' => '[a](http://mysite.test:8080/old)',
    'upper case path' => '[a](/OLD)',
    'at the end of the text' => 'see /old',
    'at the end of a line' => "go to /old\nthen",
] as $label => $text) {
    check("found: $label", $count($text), 1);
}
// ---- what is left alone
foreach ([
    'a longer address' => '[a](/old-page)',
    'a longer word' => '[a](/older)',
    'a page below it' => '[a](/old/child)',
    'another language' => '[a](/en/old)',
    'a relative link' => '[a](../old)',
    'another site' => '[a](https://example.org/old)',
    'another site with our name in it' => '[a](https://notmysite.test/old)',
    'a query parameter' => '[a](/login?next=/old)',
    'plain words' => 'the old way',
    'a different address' => '[a](/new)',
    'a file' => '[a](/old.pdf)',
    'sentence punctuation' => 'see /old, then',
] as $label => $text) {
    check("left alone: $label", $count($text), 0);
}
check('two links in one text', $count("[a](/old) and [b](/old#x) and [c](/other)"), 2);
check('Greek address, as written', $count('[a](/ελληνικα)', 'ελληνικα'), 1);
check('Greek address, percent-encoded', $count('[a](/%CE%B5%CE%BB%CE%BB%CE%B7%CE%BD%CE%B9%CE%BA%CE%B1)', 'ελληνικα'), 1);
check('an address with a language prefix', $count('[a](/en/about)', 'en/about'), 1);
check('nothing to look for', $s->rewrite('[a](/old)', []), ['[a](/old)', 0]);

// ---- rewriting
check('the link is pointed at the new place', $s->rewrite('[a](/old)', ['old' => '/new'])[0], '[a](/new)');
check('an anchor and a query string are kept', $s->rewrite('[a](/old#part) [b](/old?x=1)', ['old' => '/new'])[0], '[a](/new#part) [b](/new?x=1)');
check('a trailing slash is kept', $s->rewrite('[a](/old/)', ['old' => '/new'])[0], '[a](/new/)');
check('a full address keeps its host', $s->rewrite('[a](https://mysite.test/old)', ['old' => '/new'])[0], '[a](https://mysite.test/new)');
check('a full address can go to another site', $s->rewrite('[a](/old) [b](https://mysite.test/old)', ['old' => 'https://example.org/x'])[0], '[a](https://example.org/x) [b](https://example.org/x)');
check('a full address with a port keeps it', $s->rewrite('[a](http://mysite.test:8080/old)', ['old' => '/new'])[0], '[a](http://mysite.test:8080/new)');
check('the case of a link does not matter', $s->rewrite('[a](/Old)', ['old' => '/new'])[0], '[a](/new)');
check('several addresses at once', $s->rewrite('[a](/one) [b](/two) [c](/three)', ['one' => '/1', 'two' => '/2'])[0], '[a](/1) [b](/2) [c](/three)');
check('a replaced link is not replaced again', $s->rewrite('[a](/one)', ['one' => '/two', 'two' => '/three'])[0], '[a](/two)');
check('an address that starts with another is not confused', $s->rewrite('[a](/blog) [b](/blog/post)', ['blog' => '/news', 'blog/post' => '/news/post'])[0], '[a](/news) [b](/news/post)');
check('the count of changed links', $s->rewrite('[a](/old) [b](/old)', ['old' => '/new'])[1], 2);
check('special characters in an address are not a pattern', $s->rewrite('[a](/a.b) [b](/axb)', ['a.b' => '/z'])[0], '[a](/z) [b](/axb)');

// ---- files
file_put_contents("$dir/pages/about.md", "---\ntitle: About\n---\n\nSee [jobs](/old) and [more](/old#x).\n");
file_put_contents("$dir/pages/about.en.md", "---\ntitle: About\n---\n\nSee [jobs](/en/old).\n");
file_put_contents("$dir/posts/hello.md", "---\ntitle: Hi\nblocks:\n  - type: cta\n    actions:\n      - label: Go\n        url: /old\n---\n\nText\n");
file_put_contents("$dir/forms/contact.md", "---\ntitle: Contact\n---\n\nNo links\n");
file_put_contents("$dir/users/users.yaml", "url: /old\n");
file_put_contents("$dir/menus/main.md", "[a](/old)");
file_put_contents("$dir/taxonomies/tags.md", "[a](/old)");
$files = $s->files();
usort($files, fn($a, $b) => [$a['type'], $a['slug'], $a['lang']] <=> [$b['type'], $b['slug'], $b['lang']]);
check('content files are listed with their type, address, and language', array_map(fn($f) => "{$f['type']}/{$f['slug']}/{$f['lang']}", $files), ['forms/contact/el', 'pages/about/el', 'pages/about/en', 'posts/hello/el']);
$found = $s->find(['old', 'en/old']);
usort($found, fn($a, $b) => [$a['type'], $a['slug'], $a['lang']] <=> [$b['type'], $b['slug'], $b['lang']]);
check('files with links are found, others are not', array_map(fn($f) => "{$f['type']}/{$f['slug']}/{$f['lang']}:{$f['count']}", $found), ['pages/about/el:2', 'pages/about/en:1', 'posts/hello/el:1']);
check('links are counted per address', $found[1]['by_source'], ['en/old' => 1]);
$totals = $s->countBySource(['old', 'en/old']); ksort($totals);
check('counts across all content', $totals, ['en/old' => 1, 'old' => 3]);
check('an address nobody links to', $s->countBySource(['nothing']), []);
check('users, menus, and taxonomies are not content', in_array('users', array_column($files, 'type'), true) || in_array('menus', array_column($files, 'type'), true), false);
check('a link in a block of the front matter is found', $s->rewrite((string)file_get_contents("$dir/posts/hello.md"), ['old' => '/new'])[1], 1);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
