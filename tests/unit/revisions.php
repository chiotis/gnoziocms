<?php
/*
 * Revision history: the line diff and the store that keeps earlier versions of content files.
 *   php tests/unit/revisions.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{LineDiff, RevisionRepository as R, SystemDatabase};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }
$ops = fn(array $d): string => implode('', array_map(fn($r) => $r['op'], $d));

// ---- LineDiff
check('identical', $ops(LineDiff::compare("a\nb", "a\nb")), '==');
check('empty to text', $ops(LineDiff::compare('', "a\nb")), '++');
check('text to empty', $ops(LineDiff::compare("a\nb", '')), '--');
check('change in the middle', LineDiff::compare("a\nb\nc", "a\nX\nc"), [['op' => '=', 'text' => 'a'], ['op' => '-', 'text' => 'b'], ['op' => '+', 'text' => 'X'], ['op' => '=', 'text' => 'c']]);
check('insert', $ops(LineDiff::compare("a\nc", "a\nb\nc")), '=+=');
check('delete', $ops(LineDiff::compare("a\nb\nc", "a\nc")), '=-=');
check('moved line shows as remove and add', LineDiff::summary(LineDiff::compare("a\nb\nc", "b\nc\na")), ['added' => 1, 'removed' => 1]);
check('windows line endings are the same as unix', $ops(LineDiff::compare("a\r\nb", "a\nb")), '==');
check('summary', LineDiff::summary(LineDiff::compare("a\nb", "a\nc\nd")), ['added' => 2, 'removed' => 1]);
$long = implode("\n", range(1, 40));
$changed = str_replace("\n20\n", "\nTWENTY\n", $long);
$ctx = LineDiff::withContext(LineDiff::compare($long, $changed), 2);
check('context keeps the change and 2 lines each side', array_column($ctx, 'text'), ['18', '19', '20', 'TWENTY', '21', '22']);
$two = str_replace(["\n5\n", "\n35\n"], ["\n5x\n", "\n35x\n"], $long);
check('distant changes get a gap marker', in_array('~', array_column(LineDiff::withContext(LineDiff::compare($long, $two), 1), 'op'), true), true);
$big = implode("\n", range(1, 2000)); $bigger = $big . "\nextra";
check('very large files still compare', LineDiff::summary(LineDiff::compare($big, $bigger)), ['added' => 1, 'removed' => 0]);
$bigMid = str_replace("\n1000\n", "\nX\n", $big);
check('large middle change falls back to a block', LineDiff::summary(LineDiff::compare($big, $bigMid))['added'] >= 1, true);

// ---- RevisionRepository
$dir = sys_get_temp_dir() . '/rev' . getmypid(); mkdir($dir . '/db', 0775, true);
$db = new SystemDatabase($dir); $db->initialize(); $r = new R($db);
$file = fn(string $title, string $body = 'text', string $status = 'published') => "---\ntitle: '$title'\nstatus: $status\n---\n\n$body\n";
check('available', $r->isAvailable(), true);
$a = $r->capture('pages', 'about', 'el', $file('One'), 'create', 'alice');
check('first capture recorded', $a > 0, true);
check('same text is not recorded twice', $r->capture('pages', 'about', 'el', $file('One'), 'save', 'alice'), 0);
$b = $r->capture('pages', 'about', 'el', $file('Two', 'other'), 'save', 'bob');
check('changed text is recorded', $b > $a, true);
$rev = $r->find($b);
check('text round trips (Greek, newlines)', $r->find($r->capture('pages', 'about', 'el', $file('Ελληνικά', "γραμμή 1\nγραμμή 2"), 'save', 'bob'))['raw'], $file('Ελληνικά', "γραμμή 1\nγραμμή 2"));
check('title, status, actor, size kept', [$rev['title'], $rev['status'], $rev['actor'], $rev['size'], $rev['action']], ['Two', 'published', 'bob', strlen($file('Two', 'other')), 'save']);
check('list is newest first', array_column($r->forItem('pages', 'about', 'el'), 'title'), ['Ελληνικά', 'Two', 'One']);
check('previous version', $r->previous($rev)['title'], 'One');
check('no previous for the first', $r->previous($r->find($a)), null);
check('other item unaffected', $r->forItem('pages', 'about', 'en'), []);

// baseline / external changes
$path = $dir . '/about.md'; file_put_contents($path, $file('Ελληνικά', "γραμμή 1\nγραμμή 2"));
$r->baseline('pages', 'about', 'el', $path, 'x');
check('baseline: nothing new when the file matches', $r->countForItem('pages', 'about', 'el'), 3);
file_put_contents($path, $file('Edited by hand'));
$r->baseline('pages', 'about', 'el', $path, 'x');
check('baseline: a change made outside the editor is kept', [$r->countForItem('pages', 'about', 'el'), $r->latest('pages', 'about', 'el')['action'], $r->latest('pages', 'about', 'el')['actor']], [4, 'external', null]);
file_put_contents($dir . '/new.md', $file('Untracked'));
$r->baseline('pages', 'new', 'el', $dir . '/new.md', 'x');
check('baseline: an item with no history gets one', $r->latest('pages', 'new', 'el')['action'], 'baseline');
$r->baseline('pages', 'missing', 'el', $dir . '/nope.md', 'x');
check('baseline: missing file ignored', $r->latest('pages', 'missing', 'el'), null);

// rename keeps history
$r->rename('pages', 'about', 'el', 'who-we-are', 'el');
check('rename: history moves with the item', [$r->countForItem('pages', 'about', 'el'), $r->countForItem('pages', 'who-we-are', 'el')], [0, 4]);

// deletes and restores are always recorded
$d = $r->capture('pages', 'who-we-are', 'el', $file('Edited by hand'), 'delete', 'carol');
check('delete recorded even with the same text', $d > 0, true);
check('deleted list', array_map(fn($x) => $x['slug'], $r->deleted()), ['who-we-are']);
$rs = $r->capture('pages', 'who-we-are', 'el', $file('Edited by hand'), 'restore', 'carol');
check('restore recorded', $rs > $d, true);
check('restored item leaves the deleted list', $r->deleted(), []);

// filters
check('filter by text', $r->count(['q' => 'Untracked']), 1);
check('filter escapes wildcards', $r->count(['q' => '%']), 0);
check('filter by actor', $r->count(['actor' => 'bob']), 2);
check('filter by action', $r->count(['action' => 'delete']), 1);
check('filter by type', $r->count(['type' => 'posts']), 0);
check('actors listed', $r->actors(), ['alice', 'bob', 'carol']);
check('recent pages through the list', [count($r->recent([], 3, 0)), count($r->recent([], 3, 3)) > 0], [3, true]);

// pruning
for ($i = 1; $i <= R::KEEP_PER_ITEM + 10; $i++) { $r->capture('posts', 'busy', 'el', $file("v$i"), 'save', 'x'); }
check('only the newest versions are kept', $r->countForItem('posts', 'busy', 'el'), R::KEEP_PER_ITEM);
check('the newest is the last one saved', $r->latest('posts', 'busy', 'el')['title'], 'v' . (R::KEEP_PER_ITEM + 10));
check('huge text is refused', $r->capture('posts', 'huge', 'el', str_repeat('x', 2100000), 'save', 'x'), 0);
check('unknown revision', $r->find(999999), null);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
