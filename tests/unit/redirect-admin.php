<?php
/*
 * The public addresses of a site (which lead to a page, which look most like one that was missed, a pasted address of
 * the site made into a path) and what the Redirects screen does with a submitted action.
 *   php tests/unit/redirect-admin.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentRepository, PublicPaths, RedirectAdmin, RedirectRepository, SystemDatabase, Taxonomies};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/redadm' . getmypid();
foreach (['pages', 'posts', 'forms'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/storage/db", 0775, true);
$settings = ['title' => 'Site', 'base_url' => 'https://mysite.test', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index'];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$repo = new RedirectRepository($db);
$store = new Taxonomies("$dir/content", ['el', 'en']);
$store->ensureDefaults();
$store->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']]]);
file_put_contents("$dir/content/pages/index.md", "---\ntitle: Home\nstatus: published\n---\nHi\n");
file_put_contents("$dir/content/pages/about.md", "---\ntitle: About\nstatus: published\n---\nAbout\n");
file_put_contents("$dir/content/pages/about.en.md", "---\ntitle: About EN\nstatus: published\n---\nAbout\n");
file_put_contents("$dir/content/pages/draft.md", "---\ntitle: Draft\nstatus: draft\n---\nD\n");
file_put_contents("$dir/content/posts/hello-world.md", "---\ntitle: Hello\nstatus: published\n---\nP\n");
file_put_contents("$dir/content/forms/contact.md", "---\ntitle: Contact\n---\nF\n");

$paths = new PublicPaths($content, fn() => $store, fn() => $settings, fn() => 'https://mysite.test');

// ---- which addresses lead to a page
$map = $paths->map();
check('every published address is listed with its title, forms and drafts are not', $map, ['' => 'Home', 'about' => 'About', 'en/about' => 'About EN', 'hello-world' => 'Hello']);
foreach ([
    'the home page' => ['', true], 'a page' => ['about', true], 'a page in another language' => ['en/about', true], 'an unknown page' => ['nothing', false],
    'a page that is a draft' => ['draft', false], 'a post' => ['posts/hello-world', true], 'an unknown post' => ['posts/nope', false],
    'a list of posts' => ['posts', true], 'the language home' => ['en', true], 'search' => ['search', true], 'the sitemap' => ['sitemap.xml', true],
    'a tag that exists' => ['tag/news', true], 'a tag that does not' => ['tag/nope', false], 'a tag list without a term' => ['tag', false],
    'a category address in the other language' => ['en/category/announcements', true], 'too many segments' => ['posts/hello-world/extra', false],
] as $label => [$path, $expected]) {
    check($label, $paths->exists($path), $expected);
}

// ---- the closest address
check('a typo finds the address (the first of equally close ones)', PublicPaths::suggest('en/abuot', $map), '/about');
check('a near miss in a post', PublicPaths::suggest('hello-wrld', $map), '/hello-world');
check('nothing close gives nothing', PublicPaths::suggest('zzzzzz', $map), '');

// ---- a pasted address
$_SERVER['HTTP_HOST'] = 'localhost:8080';
check('an address of the site becomes a path', $paths->localize(' https://mysite.test/en/about?x=1#top '), '/en/about?x=1#top');
check('so does one of the host asked', $paths->localize('http://localhost:8080/about'), '/about');
check('another site is left alone', $paths->localize('https://other.example/about'), 'https://other.example/about');
check('a path is left alone', $paths->localize('/about'), '/about');
check('the bare address is the home page', $paths->localize('https://mysite.test'), '/');

// ---- actions
$log = [];
$admin = new RedirectAdmin($repo, $paths, function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = $action; });
$src = fn() => array_column($repo->all([], 100, 0), 'target', 'source');

check('a new form is blank', $admin->blankForm(), ['id' => 0, 'source' => '', 'target' => '', 'code' => 301, 'note' => '', 'enabled' => true]);
$r = $admin->apply(['do' => 'add', 'source' => '/old-page', 'target' => 'https://mysite.test/about', 'code' => '301', 'note' => ' moved '], 'tester');
check('adding works and goes back to the list', [$r['location'], $r['error']], ['/admin/redirects?done=added', '']);
check('the pasted address of the site was stored as a path', $src(), ['old-page' => '/about']);
check('and the note trimmed, the origin manual', [$repo->findBySource('old-page')['note'], $repo->findBySource('old-page')['origin']], ['moved', 'manual']);
$r = $admin->apply(['do' => 'add', 'source' => 'old-page', 'target' => '/en/about', 'code' => '301'], 'tester');
check('the same address twice is refused, the form stays as typed', [$r['location'], $r['error'], $r['form']['target']], [null, 'duplicate', '/en/about']);
$r = $admin->apply(['do' => 'add', 'source' => '/about', 'target' => '/en/about', 'code' => '301'], 'tester');
check('an address that has a page is refused', $r['error'], 'source_has_page');
$r = $admin->apply(['do' => 'add', 'source' => '/x', 'target' => '/x', 'code' => '301'], 'tester');
check('the repository checks still apply', $r['error'], 'same');
$r = $admin->apply(['do' => 'add', 'source' => '/admin/x', 'target' => '/about', 'code' => '301'], 'tester');
check('the admin cannot be redirected', $r['error'], 'source_admin');
$r = $admin->apply(['do' => 'add', 'source' => '/loop-a', 'target' => '/old-page', 'code' => '301'], 'tester');
check('a chain is allowed', [$r['error'], $r['location']], ['', '/admin/redirects?done=added']);

$id = (int)$repo->findBySource('old-page')['id'];
$r = $admin->apply(['do' => 'update', 'id' => (string)$id, 'source' => '/old-page', 'target' => '/posts/hello-world', 'code' => '302', 'note' => '', 'enabled' => '1'], 'tester');
check('changing one works', [$r['location'], $repo->find($id)['target'], (int)$repo->find($id)['status_code']], ['/admin/redirects?done=updated', '/posts/hello-world', 302]);
$r = $admin->apply(['do' => 'update', 'id' => (string)$id, 'source' => '/old-page', 'target' => '/about', 'code' => '301'], 'tester');
check('an update without the box ticked turns it off', (bool)$repo->find($id)['enabled'], false);
$r = $admin->apply(['do' => 'update', 'id' => '9999', 'source' => '/ghost', 'target' => '/about', 'code' => '301', 'enabled' => '1'], 'tester');
check('changing one that is not there just goes back', $r['location'], '/admin/redirects');
$r = $admin->apply(['do' => 'update', 'id' => (string)$id, 'source' => '/loop-a', 'target' => '/about', 'code' => '301', 'enabled' => '1'], 'tester');
check('moving a redirect onto another one is a duplicate', $r['error'], 'duplicate');

$admin->apply(['do' => 'toggle', 'id' => (string)$id], 'tester');
check('toggle turns it on', (bool)$repo->find($id)['enabled'], true);
$admin->apply(['do' => 'toggle', 'id' => (string)$id], 'tester');
check('and off', (bool)$repo->find($id)['enabled'], false);
check('toggling nothing is harmless', $admin->apply(['do' => 'toggle', 'id' => '9999'], 'tester')['location'], '/admin/redirects?done=updated');

// ---- importing a list
$text = "# a comment\n/from-1 /about\n/from-2, /posts/hello-world, 302\nfrom-3 => /en/about\n\n/from-4 -> https://other.example/x\n/about /en/about\n/from-1 /about\n/from-5\n/from-6 nothing\n/from-7 /about 999";
$r = $admin->apply(['do' => 'import', 'lines' => $text], 'tester');
check('an import shows its report, not a redirect', [$r['location'], $r['tab']], [null, 'redirects']);
check('the usable lines were added', $r['report']['added'], 5);
check('the others are named by line and reason', array_map(fn($x) => [$x['line'], $x['reason']], $r['report']['skipped']), [[7, 'source_has_page'], [8, 'duplicate'], [9, 'target_empty'], [10, 'target_relative']]);
check('a second code is honoured, an odd one becomes 301', [(int)$repo->findBySource('from-2')['status_code'], (int)$repo->findBySource('from-7')['status_code']], [302, 301]);
check('an imported redirect says so', $repo->findBySource('from-1')['note'], 'Imported');
$many = implode("\n", array_map(fn($n) => "/bulk-$n /about", range(1, 520)));
check('only the first 500 lines are read', $admin->apply(['do' => 'import', 'lines' => $many], 'tester')['report']['added'], 500);

// ---- deleting
$ids = array_map('intval', [$repo->findBySource('bulk-1')['id'], $repo->findBySource('bulk-2')['id']]);
$r = $admin->apply(['do' => 'bulk_delete', 'ids' => $ids], 'tester');
check('several can be deleted', [$r['location'], $repo->findBySource('bulk-1', false)], ['/admin/redirects?done=deleted&n=2', null]);
$r = $admin->apply(['do' => 'delete', 'id' => (string)$repo->findBySource('bulk-3')['id']], 'tester');
check('or one', $r['location'], '/admin/redirects?done=deleted&n=1');

// ---- addresses that were missed
$repo->recordNotFound('/en/abuot', 'https://x.test/');
$repo->recordNotFound('/completely-unknown', '');
$missing = $admin->missing('');
check('missed addresses come with the closest existing one', array_column($missing, 'suggestion', 'path'), ['en/abuot' => '/about', 'completely-unknown' => '']);
$admin->apply(['do' => 'missing_delete', 'path' => '/en/abuot'], 'tester');
check('one can be ignored', count($admin->missing('')), 1);
check('an ignored address is kept apart, not lost', [count($admin->missing('', 200, true)), $repo->notFoundCount(), $repo->notFoundIgnoredCount()], [1, 1, 1]);
$repo->recordNotFound('/en/abuot', '');
check('and it stays hidden when asked for again, though still counted', [count($admin->missing('')), (int)$admin->missing('', 200, true)[0]['hits']], [1, 2]);
$admin->apply(['do' => 'missing_restore', 'path' => '/en/abuot'], 'tester');
check('it can be listed again', [count($admin->missing('')), count($admin->missing('', 200, true))], [2, 0]);
$repo->recordNotFound('/wp-login.php', '');
$repo->recordNotFound('/wp-admin/setup', '');
$repo->recordNotFound('/xmlrpc.php', '');
$r = $admin->apply(['do' => 'missing_ignore_all', 'q' => 'wp-'], 'tester');
check('ignoring all that match a search leaves the others', [$r['location'], $names = array_column($admin->missing(''), 'path') ?: [], count($admin->missing('', 200, true))], ['/admin/redirects?tab=missing&done=ignored_all&n=2', $names, 2]);
$r = $admin->apply(['do' => 'missing_ignore_all'], 'tester');
sort($names);
check('the ones that did not match are still listed', $names, ['completely-unknown', 'en/abuot', 'xmlrpc.php']);
check('ignoring all hides every address on the list', [$r['location'], count($admin->missing('')), $repo->notFoundCount(), $repo->notFoundIgnoredCount()], ['/admin/redirects?tab=missing&done=ignored_all&n=3', 0, 0, 5]);
$repo->recordNotFound('/wp-login.php', '');
check('and they do not come back when visitors ask again', [count($admin->missing('')), $repo->notFoundCount()], [0, 0]);
$repo->recordNotFound('/new-miss', '');
check('a new address is listed', array_column($admin->missing(''), 'path'), ['new-miss']);
check('clearing the list keeps what was ignored', [$admin->apply(['do' => 'missing_clear'], 'tester')['location'], count($admin->missing('')), $repo->notFoundIgnoredCount()], ['/admin/redirects?tab=missing&done=cleared', 0, 5]);
check('all ignored can be listed again', [$admin->apply(['do' => 'missing_restore_all'], 'tester')['location'], $repo->notFoundCount(), $repo->notFoundIgnoredCount()], ['/admin/redirects?tab=missing&done=restored_all&n=5', 5, 0]);
$admin->apply(['do' => 'missing_ignore_all'], 'tester');
$admin->apply(['do' => 'missing_restore_all', 'q' => 'wp-login'], 'tester');
check('or only the ones matching a search', array_column($admin->missing(''), 'path'), ['wp-login.php']);
$repo->clearNotFound();
$repo->create('xmlrpc.php', '/about', 301, 'manual', '', 'tester');
check('making a redirect for an ignored address removes it too', $repo->notFoundIgnoredCount(), 3);
foreach (['completely-unknown', 'en/abuot', 'wp-admin/setup', 'wp-login.php'] as $gone) {
    $repo->deleteNotFound($gone);
}
check('the log can be emptied', [$repo->notFoundCount(), $repo->notFoundIgnoredCount()], [0, 0]);
check('an unknown action does nothing', [$admin->apply(['do' => 'whatever'], 't')['location'], $admin->apply([], 't')['error']], [null, '']);

// ---- the list
$admin->apply(['do' => 'toggle', 'id' => (string)$id], 'tester'); // old-page is on again
$repo->create('off-one', '/about', 301, 'manual', '', 't');
$admin->apply(['do' => 'toggle', 'id' => (string)$repo->findBySource('off-one')['id']], 'tester');
$repo->create('chain-start', '/old-page', 301, 'manual', '', 't');
$repo->create('to-loop', '/to-loop-b', 301, 'manual', '', 't');
$repo->create('to-loop-b', '/to-loop', 301, 'manual', '', 't');
$repo->create('to-nowhere', '/no-such-page', 301, 'manual', '', 't');
$repo->create('to-other', 'https://other.example', 301, 'manual', '', 't');
$repo->create('to-page', '/about', 301, 'manual', '', 't');
$states = [];
foreach ($admin->rows([], 500, 1) as $row) { $states[$row['source']] = $row['target_state']; }
check('a target that is a page', $states['to-page'], 'ok');
check('a target that is another redirect', $states['chain-start'], 'chain');
check('a target that leads back', $states['to-loop'], 'loop');
check('a target that does not exist', $states['to-nowhere'], 'missing');
check('a target on another site', $states['to-other'], 'external');
check('paging gives the next rows', count($admin->rows([], 5, 2)) > 0 && count($admin->rows([], 5, 2)) <= 5, true);

// ---- redirects whose links can be updated
$fixId = (int)$repo->findBySource('to-page')['id'];
$chainId = (int)$repo->findBySource('chain-start')['id'];
$offId = (int)$repo->findBySource('off-one', false)['id'];
$tempId = (int)$repo->findBySource('from-2')['id']; // 302
check('only permanent redirects that are on qualify, each to the end of its chain', $admin->linkFixes([$fixId, $chainId, $offId, $tempId, 9999]), ['to-page' => '/about', 'chain-start' => '/about']);

check('every action was written to the activity log', array_values(array_unique($log)), ['redirects.create', 'redirects.update', 'redirects.import', 'redirects.delete', 'redirects.missing_ignore_all', 'redirects.missing_clear', 'redirects.missing_restore_all']);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
