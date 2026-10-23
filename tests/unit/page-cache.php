<?php
/*
 * The site's own copy of its public pages (PageCache): which requests may be answered with a copy (a GET on the site's own address,
 * with no query, when it is turned on), which answers are kept (a 200 page, sitemap or feed without a cookie, not private, not refused,
 * not too big, not when the site changed meanwhile), that a copy is sent as it was made, that it stops being used after its time or
 * after a purge, and that a purge also empties it from FrontCache.
 *   php tests/unit/page-cache.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{FrontCache, PageCache, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$root = sys_get_temp_dir() . '/pagecache' . getmypid();
mkdir($root . '/storage', 0775, true);
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
$database = new SystemDatabase($root . '/storage');
$database->initialize();
$meta = new SystemMetaRepository($database);
$dir = $root . '/storage/cache/pages';
$cache = new PageCache($meta, $dir, '0.1.1');

$page = ['Content-Type: text/html; charset=utf-8', 'Cache-Control: public, max-age=0, s-maxage=60', 'X-Frame-Options: SAMEORIGIN', 'X-Powered-By: PHP', 'X-Page-Cache: miss'];
$sent = null;
$send = static function (array $headers, string $body) use (&$sent): void { $sent = [$headers, $body]; };
$key = static fn(string $path = 'about', string $query = '', string $method = 'GET', string $host = 'site.test', bool $https = true): ?string => (new PageCache(new SystemMetaRepository($GLOBALS['database']), $GLOBALS['dir'], '0.1.1'))->keyFor($method, $host, $https, $path, $query, 'site.test');

// ---- off until it is turned on
check('it is off at first, and then no request has a key', [$cache->isEnabled(), $key()], [false, null]);
check('turned on', [$cache->setEnabled(true), $cache->isEnabled(), (new PageCache($meta, $dir, '0.1.1'))->isEnabled()], [true, true, true]);

// ---- which requests may be answered with a copy
check('a GET on the address of the site has a key', is_string($key()), true);
check('the key is the same for the same page, and is made of the page, scheme and version', [$key() === $key('about'), $key('/about/') === $key('about'), $key('about') !== $key('contact'), $key('about') !== $key('about', '', 'GET', 'site.test', false), $key('about') !== (new PageCache($meta, $dir, '0.1.2'))->keyFor('GET', 'site.test', true, 'about', '', 'site.test')], [true, true, true, true, true]);
check('the host in capitals is the same host', $key('about', '', 'GET', 'SITE.test') === $key(), true);
check('a query (a filter, a sort, a search) is not kept', $key('about', 'q=a'), null);
check('a POST or a HEAD is not', [$key('about', '', 'POST'), $key('about', '', 'HEAD')], [null, null]);
check('another host is not (a request that names a host of its own cannot fill the cache)', [$key('about', '', 'GET', 'evil.test'), $key('about', '', 'GET', '')], [null, null]);
check('and a site with no address keeps nothing', $cache->keyFor('GET', 'site.test', true, 'about', '', ''), null);

// ---- what is kept, and sent as it was
$k = $key();
check('nothing is sent when nothing is kept', [$cache->serve($k, $send), $sent], [false, null]);
$generation = $cache->generation();
check('a 200 page is kept', $cache->keep($k, $generation, '<h1>About</h1>', 200, $page), true);
check('and sent with its headers (not the ones the server sets again) and its body', [$cache->serve($k, $send), $sent], [true, [['Content-Type: text/html; charset=utf-8', 'Cache-Control: public, max-age=0, s-maxage=60', 'X-Frame-Options: SAMEORIGIN'], '<h1>About</h1>']]);
check('it is counted', $cache->stats()['entries'], 1);
check('a page of another address is not that one', $cache->serve($key('contact'), $send), false);

// ---- what is not kept
$no = static fn(string $label, int $status, string $body, array $headers) => check($label, [$cache->keep($key('no-' . md5($label)), $cache->generation(), $body, $status, $headers), $cache->serve($key('no-' . md5($label)), $GLOBALS['send'])], [false, false]);
$no('a page that is not found', 404, 'x', $page);
$no('a redirect', 302, 'x', $page);
$no('an empty answer', 200, '', $page);
$no('an answer that sets a cookie', 200, 'x', [...$page, 'Set-Cookie: PHPSESSID=abc; path=/']);
$no('an answer that says it is private', 200, 'x', ['Content-Type: text/html', 'Cache-Control: private, no-store']);
$no('an answer that says no-store', 200, 'x', ['Content-Type: text/html', 'Cache-Control: no-store']);
$no('a picture', 200, 'x', ['Content-Type: image/png']);
$no('a download', 200, 'x', ['Content-Type: application/pdf']);
$no('an answer too big to be a page', 200, str_repeat('a', PageCache::MAX_BYTES + 1), $page);
check('a sitemap, a feed and robots.txt are kept', [
    $cache->keep($key('sitemap.xml'), $cache->generation(), '<urlset/>', 200, ['Content-Type: application/xml; charset=utf-8']),
    $cache->keep($key('feed'), $cache->generation(), '<rss/>', 200, ['Content-Type: application/rss+xml']),
    $cache->keep($key('robots.txt'), $cache->generation(), 'User-agent: *', 200, ['Content-Type: text/plain']),
    $cache->keep($key('bare'), $cache->generation(), '<p>no type</p>', 200, ['Cache-Control: public, max-age=0']),
], [true, true, true, true]);

// ---- the site changed while the answer was made
check('an answer that began under an older generation is not kept', $cache->keep($key('late'), 'an-older-one', 'x', 200, $page), false);

// ---- a copy has its time
$file = $dir . '/' . $k . '.page';
$raw = (string)file_get_contents($file);
$cut = strpos($raw, "\n");
$head = json_decode(substr($raw, 0, $cut), true);
$head['expires'] = time() - 1;
file_put_contents($file, json_encode($head) . substr($raw, $cut));
check('a copy past its time is not sent', $cache->serve($k, $send), false);
$head['expires'] = time() + 60;
file_put_contents($file, json_encode($head) . substr($raw, $cut));
check('and before it is', $cache->serve($k, $send), true);
file_put_contents($file, json_encode($head) . substr($raw, $cut) . 'extra');
check('a copy that is not whole is not sent', $cache->serve($k, $send), false);
file_put_contents($file, 'not a copy');
check('nor is a file that is not one', $cache->serve($k, $send), false);

// ---- a purge: the generation changes when the request ends, and the copies go
$cache->keep($k, $cache->generation(), '<h1>About</h1>', 200, $page);
$before = $cache->generation();
$script = '<?php require ' . var_export($repo . '/vendor/autoload.php', true) . ';'
    . '$db = new GnozioCMS\SystemDatabase(' . var_export($root . '/storage', true) . '); $db->initialize(); $meta = new GnozioCMS\SystemMetaRepository($db);'
    . '$c = new GnozioCMS\PageCache($meta, ' . var_export($dir, true) . ', "0.1.1"); $f = new GnozioCMS\FrontCache($meta, "site.test"); $f->keepPages($c);'
    . 'echo json_encode([$f->purgeAll(), $f->purgeAll(), $c->generation()]);';
file_put_contents($root . '/purge.php', $script);
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/purge.php'));
check('FrontCache empties it, once, and says there was something to empty', json_decode($out, true), [true, true, $before]);
check('only when the request ends: the generation is another and no copy is left', [$cache->generation() !== $before, $cache->stats()['entries'], $cache->serve($k, $send)], [true, 0, false]);

// ---- off: nothing is kept, and what was kept does not come back
$cache->keep($k, $cache->generation(), '<h1>About</h1>', 200, $page);
$cache->setEnabled(false);
check('turned off, it keeps and sends nothing', [$cache->isEnabled(), $key(), $cache->stats()['entries']], [false, null, 0]);
$cache->setEnabled(true);
check('and turned on again it starts empty', [$cache->serve($k, $send), $cache->stats()['entries']], [false, 0]);

// ---- a response that something refused
FrontCache::doNotKeep();
check('an answer that said it must not be kept is not kept', $cache->keep($key('refused'), $cache->generation(), 'x', 200, $page), false);

// ---- FrontCache with the copy turned off has nothing to empty (as before)
$off = new PageCache($meta, $dir . '-none', '0.1.1');
$off->setEnabled(false);
$front = new FrontCache($meta, 'site.test');
$front->keepPages($off);
check('with no cache in front and its own turned off there is nothing to empty', $front->purgeAll(), false);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
