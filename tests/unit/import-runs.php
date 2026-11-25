<?php
/*
 * The history of the imports (ImportRuns): each file an import writes is a row with what was there before; one row undone on its
 * own (made: removed; replaced: put back), refused when the entry was edited since unless forced, never twice; a run that stopped
 * taken up again with its rows skipped; files outside the content folder never recorded; old runs pruned. And the importers
 * writing through it: WordPress (with --resume and the dry run's preview), WooCommerce, a CSV.
 *   php tests/unit/import-runs.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ImportRuns, WordPressImporter};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$root = sys_get_temp_dir() . '/importruns' . getmypid();
@mkdir("$root/content/posts", 0775, true);
file_put_contents("$root/content/posts/old.md", "---\ntitle: Old\n---\n\nMine.\n");
$runs = new ImportRuns("$root/storage/import/runs", "$root/content");

// ---- a run and its rows
$run = $runs->start('wordpress', 'https://old.example', ['lang' => 'en']);
$run->write('posts/1/en', "$root/content/posts/new.md", "---\ntitle: New\n---\n\nNew.\n", 'posts/new: New');
$run->write('posts/2/en', "$root/content/posts/old.md", "---\ntitle: Old, imported\n---\n\nTheirs.\n", 'posts/old: Old');
$run->write('outside', "$root/elsewhere.md", 'x', 'outside');
$run->finish('done', ['written' => 2]);
$got = $runs->get($run->id());
check('each file is a row: made or replaced, as the import left it', array_map(fn($r) => [$r['path'], $r['action'], $r['state']], array_values($got['rows'])), [['posts/new.md', 'created', 'done'], ['posts/old.md', 'updated', 'done']]);
check('a file outside the content folder is written but never recorded', [is_file("$root/elsewhere.md"), isset($got['rows']['outside'])], [true, false]);
check('the list counts the rows', [count($runs->all()), $runs->all()[0]['counts'], $runs->all()[0]['status'], isset($runs->all()[0]['rows'])], [1, ['created' => 1, 'updated' => 1, 'undone' => 0], 'done', false]);

// ---- undo one row
file_put_contents("$root/content/posts/old.md", "---\ntitle: Old, edited after\n---\n");
check('a row edited since the import is shown so', $runs->get($run->id())['rows']['posts/2/en']['state'], 'changed');
$refused = $runs->undo($run->id(), 'posts/2/en');
check('and is not undone without being forced', [$refused['ok'], str_contains((string)file_get_contents("$root/content/posts/old.md"), 'edited after')], [false, true]);
$forced = $runs->undo($run->id(), 'posts/2/en', true);
check('forced, the file is put back as it was before the import', [$forced['ok'], $forced['message'], file_get_contents("$root/content/posts/old.md")], [true, 'Put back posts/old: Old.', "---\ntitle: Old\n---\n\nMine.\n"]);
$removed = $runs->undo($run->id(), 'posts/1/en');
check('a file the import made is removed', [$removed['ok'], is_file("$root/content/posts/new.md"), $runs->get($run->id())['rows']['posts/1/en']['state']], [true, false, 'undone']);
check('a row is undone once', $runs->undo($run->id(), 'posts/1/en')['message'], 'That row was already undone.');
check('an unknown run or row, or a run id that is a path, is refused', [$runs->undo($run->id(), 'nope')['ok'], $runs->undo('../../etc', 'x')['ok'], $runs->get('../x')], [false, false, null]);
check('the list counts what was undone', $runs->all()[0]['counts'], ['created' => 0, 'updated' => 0, 'undone' => 2]);

// ---- a run that stopped, taken up again
$stopped = $runs->start('woocommerce', 'https://shop.example');
$stopped->write('product/1', "$root/content/posts/p1.md", 'one', 'p1');
$stopped->flush(); // the process ends here, the run is left "running"
check('a stopped run is the one to resume', $runs->unfinished('woocommerce', 'https://shop.example')['id'] ?? null, $stopped->id());
check('but not for another source or importer', [$runs->unfinished('woocommerce', 'https://other.example'), $runs->unfinished('wordpress', 'https://shop.example')], [null, null]);
$again = $runs->start('woocommerce', 'https://shop.example', [], true);
check('resuming takes the same run up and knows what it wrote', [$again->id(), $again->isResumed(), $again->isDone('product/1'), $again->isDone('product/2'), $again->count()], [$stopped->id(), true, true, false, 1]);
file_put_contents("$root/content/posts/p1.md", 'one, changed by hand before the second try');
$again->write('product/1', "$root/content/posts/p1.md", 'one again', 'p1');
check('a row written twice keeps the copy from before the first try', $runs->get($again->id())['rows']['product/1']['action'], 'created');
$again->finish();
check('a finished run is not resumed: a new one starts', $runs->start('woocommerce', 'https://shop.example', [], true)->id() !== $stopped->id(), true);

// ---- old runs are pruned
for ($i = 0; $i < ImportRuns::KEEP + 3; $i++) { $runs->start('csv', "pages: $i.csv")->finish(); }
check('at most KEEP runs are kept (one not finished is never pruned)', count($runs->all()) <= ImportRuns::KEEP + 1, true);

// ---- the WordPress importer through it: preview, rows, resume
$SITE = 'https://wp.example';
$web = [
    '/wp-json/' => ['name' => 'WP', 'description' => '', 'home' => $SITE, 'namespaces' => ['wp/v2'], 'show_on_front' => 'posts', 'page_on_front' => 0],
    '/wp-json/wp/v2/types' => ['post' => ['rest_base' => 'posts'], 'page' => ['rest_base' => 'pages']],
    '/wp-json/wp/v2/taxonomies' => ['category' => ['rest_base' => 'categories']],
    '/wp-json/wp/v2/categories' => [], '/wp-json/wp/v2/media' => [], '/wp-json/wp/v2/pages' => [],
    '/wp-json/wp/v2/posts' => [
        ['id' => 1, 'slug' => 'one', 'link' => "$SITE/one/", 'title' => ['rendered' => 'One'], 'content' => ['rendered' => '<p>First.</p>'], 'excerpt' => ['rendered' => ''], 'date' => '2020-01-01T00:00:00', 'status' => 'publish', 'categories' => [], 'tags' => [], 'featured_media' => 0],
        ['id' => 2, 'slug' => 'two', 'link' => "$SITE/two/", 'title' => ['rendered' => 'Two'], 'content' => ['rendered' => '<p>Second.</p>'], 'excerpt' => ['rendered' => ''], 'date' => '2020-01-02T00:00:00', 'status' => 'publish', 'categories' => [], 'tags' => [], 'featured_media' => 0],
    ],
];
$get = function (string $url) use (&$web, $SITE): ?array { $path = parse_url($url, PHP_URL_PATH); parse_str((string)parse_url($url, PHP_URL_QUERY), $q); if (($q['page'] ?? '1') !== '1') { return ['status' => 400, 'body' => '{}']; } return isset($web[$path]) ? ['status' => 200, 'body' => json_encode($web[$path])] : ['status' => 404, 'body' => '{}']; };
$wp = "$root/wp";
@mkdir("$wp/content", 0775, true);
$make = fn() => new WordPressImporter(new GnozioCMS\WordPressReader($SITE, $get), "$wp/content", new GnozioCMS\MediaLibrary("$wp/content", "$wp/uploads"), new GnozioCMS\Taxonomies("$wp/content", ['en']), fn() => 404, ['lang' => 'en']);
$wpRuns = new ImportRuns("$wp/runs", "$wp/content");
$first = $make();
$planA = $first->plan();
$runA = $wpRuns->start('wordpress', $SITE);
$resultA = $first->apply($planA, $runA);
$runA->finish();
check('every post written is a row of the run', [$resultA['written'], array_column(array_values($wpRuns->get($runA->id())['rows']), 'path')], [2, ['posts/one.md', 'posts/two.md']]);
$web['/wp-json/wp/v2/posts'][1]['content']['rendered'] = '<p>Second, changed.</p>';
$planB = $make()->plan();
check('the dry run tells a post that would change from one that would not', array_map(fn($i) => [$i['slug'], $i['state'], $i['changes']], $planB['items']), [['one', 'update', ['added' => 0, 'removed' => 0]], ['two', 'update', ['added' => 1, 'removed' => 1]]]);
$preview = WordPressImporter::preview($planB);
check('and its preview lists each with the lines that change', [str_contains($preview, 'same     posts/one [en]  One'), str_contains($preview, 'change   posts/two [en]  Two  (+1 -1 lines)'), str_contains($preview, '- Second.'), str_contains($preview, '+ Second, changed.')], [true, true, true, true]);
// A run that stopped after the first post: resuming writes only the second.
$second = $make();
$planC = $second->plan();
$runC = $wpRuns->start('wordpress', $SITE);
$runC->write('posts/1/en', "$wp/content/posts/one.md", (string)file_get_contents("$wp/content/posts/one.md"), 'posts/one: One');
$runC->flush();
$resumed = $wpRuns->start('wordpress', $SITE, [], true);
$resultC = $second->apply($planC, $resumed);
check('a resumed import skips what it wrote before it stopped', [$resumed->id(), $resultC['resumed'], $resultC['updated']], [$runC->id(), 1, 1]);
$undo = $wpRuns->undo($runA->id(), 'posts/2/en', true);
check('a post of an earlier run can be undone on its own (removed: that run made it)', [$undo['ok'], is_file("$wp/content/posts/two.md"), is_file("$wp/content/posts/one.md")], [true, false, true]);

exec('rm -rf ' . escapeshellarg($root));
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
