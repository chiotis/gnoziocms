<?php
/*
 * The store of parsed content files: a second visit reads it instead of parsing, a changed, new or removed file is seen, a file
 * that cannot be read is never kept, and a new version of the code starts over.
 *   php tests/unit/content-store.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\ContentRepository;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/contentstore' . getmypid();
mkdir("$dir/content/posts", 0775, true);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$converter = new MarkdownConverter($environment);
$repo = function (string $salt = '1') use ($dir, $settings, $converter): ContentRepository {
    $r = new ContentRepository("$dir/content", $converter, $settings);
    $r->storeParsedIn("$dir/cache", $salt);
    return $r;
};
$write = function (string $name, string $text, int $mtime) use ($dir): void { $p = "$dir/content/posts/$name.md"; file_put_contents($p, $text); touch($p, $mtime); clearstatcache(); };
$write('one', "---\ntitle: One\nstatus: published\n---\n\n**bold**\n", 1700000000);
$write('one.en', "---\ntitle: One EN\nstatus: published\n---\n\ntext\n", 1700000000);
$write('bad', "---\ntitle: [unclosed\n---\n\nx\n", 1700000000);

$first = $repo()->getItems('posts', 'el', true);
check('the first visit parses and keeps the readable files', [count($first), is_file("$dir/cache/posts.ser")], [2, true]);
$kept = unserialize((string)file_get_contents("$dir/cache/posts.ser"), ['allowed_classes' => false]);
check('a file that cannot be read is not kept', array_map('basename', array_keys($kept['files'])), ['one.en.md', 'one.md']);

// What is kept is what is read: change the kept HTML and a second visit shows it, so it did not parse again.
$kept['files']["$dir/content/posts/one.md"][4] = '<p>from the store</p>';
file_put_contents("$dir/cache/posts.ser", serialize($kept));
$second = $repo();
$one = $second->find('posts', 'one', 'el');
check('a second visit reads the store', [$one?->html, $one?->meta['title'], $one?->lang, $one?->mtime], ['<p>from the store</p>', 'One', 'el', 1700000000]);
check('the other language comes from the store too', $second->find('posts', 'one', 'en')?->meta['title'], 'One EN');
check('a file that cannot be read is still reported', count($second->unreadable()), 1);

$write('one', "---\ntitle: One changed\nstatus: published\n---\n\n*new*\n", 1700000100);
check('a changed file is parsed again', [$repo()->find('posts', 'one', 'el')?->meta['title'], trim($repo()->find('posts', 'one', 'el')?->html ?? '')], ['One changed', '<p><em>new</em></p>']);

$write('two', "---\ntitle: Two\nstatus: published\n---\n\nx\n", 1700000200);
unlink("$dir/content/posts/one.en.md");
$third = $repo();
check('a new file is seen and a removed one is gone', [$third->find('posts', 'two', 'el')?->meta['title'], $third->find('posts', 'one', 'en', true, false)], ['Two', null]);
$kept = unserialize((string)file_get_contents("$dir/cache/posts.ser"), ['allowed_classes' => false]);
check('the store forgets the removed file', array_map('basename', array_keys($kept['files'])), ['one.md', 'two.md']);

$kept['files']["$dir/content/posts/two.md"][4] = '<p>stale</p>';
file_put_contents("$dir/cache/posts.ser", serialize($kept));
check('a new version of the code starts over', trim($repo('2')->find('posts', 'two', 'el')?->html ?? ''), '<p>x</p>');

$plain = new ContentRepository("$dir/content", $converter, $settings);
check('without a store everything still works', $plain->find('posts', 'two', 'el')?->meta['title'], 'Two');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exec('rm -rf ' . escapeshellarg($dir));
exit($fail === 0 ? 0 : 1);
