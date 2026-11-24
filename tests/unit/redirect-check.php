<?php
/*
 * The check after a move: the old site's addresses from its sitemap (an index and the sitemaps it lists) or from a list, each
 * asked of the new site one step at a time, and what each answer means (moved, the same, a chain, temporary, elsewhere, the
 * wrong page, nowhere, a loop); and SafeHttp's single step, which follows no redirect.
 *   php tests/unit/redirect-check.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{RedirectCheck, SafeHttp};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$OLD = 'https://old.example';
$NEW = 'https://new.example';
$sitemaps = [
    "$OLD/wp-sitemap.xml" => '<?xml version="1.0" encoding="UTF-8"?><?xml-stylesheet type="text/xsl" href="/wp-sitemap.xsl"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>' . $OLD . '/wp-sitemap-posts-post-1.xml</loc></sitemap><sitemap><loc>' . $OLD . '/wp-sitemap-posts-page-1.xml</loc></sitemap><sitemap><loc>' . $OLD . '/wp-sitemap-posts-post-1.xml</loc></sitemap></sitemapindex>',
    "$OLD/wp-sitemap-posts-post-1.xml" => '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . $OLD . '/2020/05/moved/</loc><lastmod>2020</lastmod></url><url><loc><![CDATA[' . $OLD . '/same/]]></loc></url><url><loc>' . $OLD . '/%ce%b1%ce%b2/</loc></url></urlset>',
    "$OLD/wp-sitemap-posts-page-1.xml" => '<urlset><url><loc>' . $OLD . '/about/?a=1&amp;b=2</loc></url><url><loc>' . $OLD . '/same/</loc></url></urlset>',
];
$read = [];
$get = function (string $url) use (&$sitemaps, &$read): ?array { $read[] = $url; return isset($sitemaps[$url]) ? ['status' => 200, 'body' => $sitemaps[$url]] : ['status' => 404, 'body' => '']; };

// The new site: path => [status, location]
$site = [
    '/2020/05/moved/' => [301, '/moved'],
    '/moved' => [200, ''],
    '/same/' => [200, ''],
    '/%CE%B1%CE%B2/' => [301, "$NEW/ab"],
    '/ab' => [200, ''],
    '/about/?a=1&b=2' => [301, '/about-us/'],
    '/about-us/' => [301, '/about'],
    '/about' => [200, ''],
    '/temp' => [302, '/moved'],
    '/away' => [301, 'https://elsewhere.test/x'],
    '/www' => [301, 'https://www.new.example/moved'],
    '/gone' => [301, '/nothing'],
    '/loop-a' => [301, '/loop-b'],
    '/loop-b' => [301, '/loop-a'],
    '/oops' => [500, ''],
    '/target' => [301, '/moved'],
];
$asked = [];
$step = function (string $url) use (&$site, &$asked): array {
    $asked[] = $url;
    $parts = parse_url($url);
    $key = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    if (str_contains($url, 'down.example')) { return ['status' => 0, 'location' => '', 'error' => 'The address could not be fetched.']; }
    [$status, $location] = $site[$key] ?? [404, ''];
    return ['status' => $status, 'location' => $location !== '' && !preg_match('#^https?://#', $location) ? 'https://new.example' . $location : $location, 'error' => ''];
};
$checker = new RedirectCheck($get, $step);

// ---- the sitemap
$found = $checker->sitemapAddresses($OLD);
check('an index\'s sitemaps are read once each, their addresses once each, CDATA and entities too', $found, ["$OLD/2020/05/moved/", "$OLD/same/", "$OLD/%ce%b1%ce%b2/", "$OLD/about/?a=1&b=2"]);
check('the WordPress sitemap is tried first and nothing else is needed', $read, ["$OLD/wp-sitemap.xml", "$OLD/wp-sitemap-posts-post-1.xml", "$OLD/wp-sitemap-posts-page-1.xml"]);
$sitemaps = ["$OLD/sitemap.xml" => '<urlset><url><loc>' . $OLD . '/a/</loc></url></urlset>'];
check('without the WordPress one, Yoast\'s and then a plain sitemap.xml', $checker->sitemapAddresses($OLD), ["$OLD/a/"]);
check('no sitemap is no addresses', (new RedirectCheck(fn() => null, $step))->sitemapAddresses($OLD), []);

// ---- one address at a time
$outcome = fn(string $old, string $expected = '') => (fn($r) => [$r['outcome'], implode(' ', $r['steps']), $r['note']])($checker->check($NEW, $old, $expected));
check('moved: a 301 to a page that answers 200', $outcome("$OLD/2020/05/moved/"), ['moved', '301 200', '']);
check('the same address here', $outcome("$OLD/same/"), ['same', '200', '']);
check('Greek letters are asked encoded', $outcome("$OLD/%ce%b1%ce%b2/") === ['moved', '301 200', ''] && $outcome('/αβ/') === ['moved', '301 200', ''], true);
check('the query goes with the address; two redirects are a chain', $outcome("$OLD/about/?a=1&b=2"), ['chain', '301 301 200', '']);
check('a temporary redirect is said so', $outcome('/temp'), ['temporary', '302 200', '']);
check('a redirect to another site', $outcome('/away'), ['elsewhere', '301 → elsewhere.test', 'https://elsewhere.test/x']);
check('www. is the same site', $outcome('/www'), ['moved', '301 200', '']);
check('an address that leads nowhere, directly or after a redirect', [$outcome('/never'), $outcome('/gone')], [['missing', '404', ''], ['missing', '301 404', '']]);
check('a loop', $outcome('/loop-a'), ['broken', '301 301', 'a loop']);
check('a page that answers an error', $outcome('/oops'), ['broken', '500', 'the page answered 500']);
check('a site that cannot be reached', (fn($r) => [$r['outcome'], $r['note']])($checker->check('https://down.example', '/x')), ['broken', 'The address could not be fetched.']);
check('where a list says it should lead is checked', [$outcome('/target', '/moved'), $outcome('/target', '/elsewhere')], [['moved', '301 200', ''], ['wrong_target', '301 200', 'expected /elsewhere']]);
check('a page that is the same here is fine whatever the list says', $outcome('/same/', '/same'), ['same', '200', '']);

// ---- a list and a run
$lines = RedirectCheck::listLines("# Old address, new address, code.\n/2020/05/moved/ /moved 301\n\n/never\n  /temp   /moved  \n");
check('the import\'s redirects.txt and plain lines are read', $lines, [['/2020/05/moved/', '/moved'], ['/never', ''], ['/temp', '/moved']]);
$each = [];
$run = $checker->run($NEW, $lines, function (int $n, array $row) use (&$each) { $each[] = $n; });
check('a run counts each outcome and keeps the problems', [array_filter($run['counts']), array_column($run['problems'], 'outcome'), $each], [['moved' => 1, 'temporary' => 1, 'missing' => 1], ['missing', 'temporary'], [1, 2, 3]]);
check('the CSV has a line for each address', array_slice(explode("\n", trim(RedirectCheck::csv($run['rows']))), 0, 2), ['old,outcome,steps,final,note', '/2020/05/moved/,moved,"301 200",https://new.example/moved,']);

// ---- SafeHttp's single step
$hops = [];
$http = new SafeHttp(fn(string $host) => ['93.184.216.34'], function (string $url, string $ip, string $to, int $max, int $timeout, string $accept, array $headers) use (&$hops) {
    $hops[] = $url;
    return str_ends_with($url, '/start') ? ['status' => 301, 'location' => '/next', 'error' => ''] : ['status' => 200, 'location' => '', 'error' => ''];
});
check('a step answers the redirect and where it points, and goes no further', [$http->step('https://new.example/start'), $hops], [['status' => 301, 'location' => 'https://new.example/next', 'error' => ''], ['https://new.example/start']]);
check('a step to a page', $http->step('https://new.example/next'), ['status' => 200, 'location' => '', 'error' => '']);
$private = new SafeHttp(fn(string $host) => ['10.0.0.5'], fn() => ['status' => 200, 'location' => '', 'error' => '']);
check('a step to a private address is refused unless allowed', [$private->step('https://intranet.example/')['error'] !== '', $private->step('https://intranet.example/', 15, true)['status']], [true, 200]);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
