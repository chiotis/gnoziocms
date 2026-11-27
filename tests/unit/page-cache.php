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

// ---- a page that names a picture size not written yet is kept for a short time, so it is drawn again with the file's address
$expires = static function (string $body) use ($cache, $dir): int {
    $k = sha1($body);
    $cache->keep($k, $cache->generation(), $body, 200, ['Content-Type: text/html; charset=utf-8']);
    $raw = (string)@file_get_contents($dir . '/' . $k . '.page');
    $head = json_decode((string)strstr($raw, "\n", true), true);
    return (int)($head['expires'] ?? 0) - time();
};
$short = $expires('<img srcset="/_image/media/a.png/960-abc-w72 960w">');
$long = $expires('<img srcset="/uploads/_v/media/a.png/960-abc-w72.webp 960w">');
check('a page with a pending picture is kept about a minute', $short > 0 && $short <= GnozioCMS\Images::PENDING_PAGE_TTL, true);
check('a page whose pictures are all written keeps the full time', $long > GnozioCMS\Images::PENDING_PAGE_TTL, true);

// ---- served before the application boots (public/index.php, audit P2)
$site = $root . '/site';
mkdir($site . '/storage/cache/pages', 0775, true);
file_put_contents($site . '/VERSION', "0.2.0\n");
$early = new PageCache($meta, $site . '/storage/cache/pages', '0.2.0');
$early->setFrontDoor('site.test', ['127.0.0.1']);
$k = $early->keyFor('GET', 'site.test', false, 'about', '', 'site.test');
check('a copy kept writes what the front door needs', [$early->keep($k, $early->generation(), '<p>About</p>', 200, $page), json_decode((string)file_get_contents($site . '/storage/cache/pages/frontdoor.json'), true)], [true, ['site_host' => 'site.test', 'trusted' => ['127.0.0.1']]]);
$server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/about', 'HTTP_HOST' => 'site.test', 'REMOTE_ADDR' => '203.0.113.9'];
$got = null;
$sendEarly = static function (array $headers, string $body) use (&$got): void { $got = $body; };
$try = static function (array $over = [], array $cookies = []) use ($site, $server, $sendEarly, &$got): bool { $got = null; return PageCache::serveEarly($site, $over + $server, $cookies, $sendEarly); };
check('not while nothing has said "quiet" (the work after a response may be due)', $try(), false);
$early->markQuiet();
check('then the copy is sent before the application', [$try(), $got], [true, '<p>About</p>']);
check('the same address with a slash', $try(['REQUEST_URI' => '/about/']), true);
check('not with a query', $try(['REQUEST_URI' => '/about?x=1']), false);
check('not for a POST', $try(['REQUEST_METHOD' => 'POST']), false);
check('not with a session cookie (someone signed in may see drafts)', $try([], [ini_get('session.name') => 'abc']), false);
check('but a cookie of something else does not matter', $try([], ['gz_consent' => 'granted']), true);
check('not for the check of an update', $try(['HTTP_X_GNOZIO_UPDATE' => 'token']), false);
check('nor for one under the old name (an updater on 0.1.316 or older)', $try(['HTTP_X_FAROS_UPDATE' => 'token']), false);
check('not on another host', $try(['HTTP_HOST' => 'evil.test']), false);
check('not for an address that has no copy, nor the admin', [$try(['REQUEST_URI' => '/contact']), $try(['REQUEST_URI' => '/admin'])], [false, false]);
check('HTTPS from a trusted proxy is another copy (the key has the scheme)', $try(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']), false);
check('from anyone else the header is not believed', $try(['HTTP_X_FORWARDED_PROTO' => 'https']), true);
file_put_contents($site . '/storage/maintenance.flag', '{}');
check('not while an update replaces the code', $try(), false);
unlink($site . '/storage/maintenance.flag');
file_put_contents($site . '/VERSION', "0.2.1\n");
check('not after an update (the version is in the key)', $try(), false);
file_put_contents($site . '/VERSION', "0.2.0\n");
file_put_contents($site . '/storage/cache/pages/quiet', (string)(time() - 1));
check('nor once the quiet time is over: the application answers and asks what is due', $try(), false);
$early->markQuiet();
$GLOBALS['database'] = $database;
(function () { $this->bump(); $this->deleteFiles(); })->call($early);
check('a purge removes the copies, the front door and the quiet time', [$try(), is_file($site . '/storage/cache/pages/frontdoor.json'), is_file($site . '/storage/cache/pages/quiet')], [false, false, false]);

// ---- how long a copy is kept (Settings > Cache > Keep each page for)
$headOf = static function (string $k) use ($dir): array {
    return json_decode((string)strstr((string)@file_get_contents($dir . '/' . $k . '.page'), "\n", true), true) ?: [];
};
check('ten minutes until it is set', $cache->ttl(), 600);
check('a lifetime offered is kept, another is refused', [$cache->setTtl(86400), (new PageCache($meta, $dir, '0.1.1'))->ttl(), $cache->setTtl(1234), $cache->ttl()], [true, 86400, false, 86400]);
$cache->keep('ttl-check', $cache->generation(), '<p>a day</p>', 200, $page, []);
$left = (int)($headOf('ttl-check')['expires'] ?? 0) - time();
check('a copy is then kept that long', $left > 86000 && $left <= 86400, true);
$cache->setTtl(600);

// ---- refresh only what changed (CacheTags)
GnozioCMS\CacheTags::reset();
$setUrl = static function (string $url) use ($cache): void { (function () use ($url) { $this->url = $url; })->call($cache); };
$kept = static function (string $name, array $tags, string $url = '') use ($cache, $page, $setUrl): void {
    $setUrl($url !== '' ? $url : 'https://site.test/' . $name);
    $cache->keep('tag-' . $name, $cache->generation(), '<p>' . $name . '</p>', 200, $page, $tags, microtime(true) - 1);
};
$served = static fn(string $name): bool => $cache->serve('tag-' . $name, static function (): void {});
$kept('post', ['entry:posts/hello', 'type:tags']);
$kept('home', ['entry:pages/home', 'type:posts'], 'https://site.test/');
$kept('about', ['entry:pages/about']);
$kept('map', ['*']);
$kept('author', ['id:abc']);
$kept('static', []);
check('a copy keeps what it was made from', $headOf('tag-post')['tags'] ?? null, ['entry:posts/hello', 'type:tags']);
GnozioCMS\CacheTags::changed('posts', 'hello', 'h1');
$aboutCopy = (string)file_get_contents($dir . '/tag-about.page');
$dropped = $cache->dropChanged(GnozioCMS\CacheTags::changes());
check('saving a post drops its page, the lists of posts and what is made from everything, the home page first, then the newest', $dropped, ['https://site.test/', 'https://site.test/map', 'https://site.test/post']);
check('the others are still sent', [$served('post'), $served('home'), $served('map'), $served('about'), $served('author'), $served('static')], [false, false, false, true, true, true]);
GnozioCMS\CacheTags::reset();
GnozioCMS\CacheTags::changed('people', 'someone', 'abc');
check('an entry a relation points at drops the pages that show it', [$cache->dropChanged(GnozioCMS\CacheTags::changes()), $served('about'), $served('author')], [['https://site.test/author'], true, false]);
GnozioCMS\CacheTags::reset();
GnozioCMS\CacheTags::changed('pages', 'about');
$cache->dropChanged(GnozioCMS\CacheTags::changes());
file_put_contents($dir . '/tag-about.page', $aboutCopy);
check('a copy made before the change is not sent even if its file is still there', $served('about'), false);
check('nor kept when it was being made while the change was saved', $cache->keep('tag-late', $cache->generation(), '<p>late</p>', 200, $page, ['type:pages'], microtime(true) - 5), false);
check('one made from something else is', $cache->keep('tag-other', $cache->generation(), '<p>other</p>', 200, $page, ['type:posts'], microtime(true)), true);
check('nothing written, nothing dropped', $cache->dropChanged([]), []);
GnozioCMS\CacheTags::reset();
GnozioCMS\CacheTags::removed('pages', 'old');
check('an entry taken away is said apart (then everything is dropped)', [GnozioCMS\CacheTags::anyRemoved(), in_array('entry:pages/old', GnozioCMS\CacheTags::changes(), true)], [true, true]);
GnozioCMS\CacheTags::reset();
check('the switches are on until they are turned off', [$cache->refreshesChangedOnly(), $cache->rebuildsAfterChange()], [true, true]);
$cache->setRefreshChangedOnly(false);
$cache->setRebuildAfterChange(false);
check('and then off', [$cache->refreshesChangedOnly(), $cache->rebuildsAfterChange()], [false, false]);
$cache->setRefreshChangedOnly(true);
$cache->setRebuildAfterChange(true);
$setUrl('https://site.test/x');
$cache->keep('all-1', $cache->generation(), '<p>x</p>', 200, $page, []);
check('emptying everything gives the addresses to make again', in_array('https://site.test/x', $cache->dropAll(), true) && $cache->stats()['entries'] === 0, true);

// ---- what reads entries says what it read
$content = new GnozioCMS\ContentRepository($root . '/content', new League\CommonMark\MarkdownConverter((new League\CommonMark\Environment\Environment())->addExtension(new League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension())), ['languages' => ['default' => 'en', 'available' => ['en']]]);
mkdir($root . '/content/pages', 0775, true);
file_put_contents($root . '/content/pages/about.md', "---\ntitle: About\n---\n\nHi\n");
GnozioCMS\CacheTags::reset();
$content->find('pages', 'about', 'en');
check('finding one entry is that entry only', GnozioCMS\CacheTags::read(), ['entry:pages/about']);
$content->getItems('posts', 'en');
check('listing a type is the type', GnozioCMS\CacheTags::read(), ['entry:pages/about', 'type:posts']);
GnozioCMS\CacheTags::reset();

// ---- the pages dropped are asked for again: on this server first, else through the public address, never for ever
$asked = [];
$fake = static function (array $answers) use (&$asked): Closure {
    return static function (string $url, bool $local) use (&$asked, $answers): int {
        $asked[] = [parse_url($url, PHP_URL_PATH), $local];
        return $answers[$local ? 'local' : 'public'];
    };
};
check('on this server when it answers', [$cache->rebuild(['https://site.test/', 'https://site.test/a'], $fake(['local' => 200, 'public' => 200])), $asked], [2, [['/', true], ['/a', true]]]);
$asked = [];
check('through the public address when this server does not', [$cache->rebuild(['https://site.test/', 'https://site.test/a'], $fake(['local' => 0, 'public' => 200])), $asked], [2, [['/', true], ['/', false], ['/a', false]]]);
$asked = [];
check('and it stops when neither answers', [$cache->rebuild(['https://site.test/', 'https://site.test/a'], $fake(['local' => 0, 'public' => 0])), $asked], [0, [['/', true], ['/', false]]]);
$asked = [];
check('at most the time it is given', $cache->rebuild(['https://site.test/'], $fake(['local' => 200, 'public' => 200]), -1), 0);

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
