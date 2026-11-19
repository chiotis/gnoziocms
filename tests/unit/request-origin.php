<?php
/*
 * RequestOrigin (audit S1, S6, S12): forwarded headers count only from a trusted proxy; the visitor's address, HTTPS and the host;
 * the list of trusted proxies with ranges. And the shop's refusal to turn on card payments and accounts without a site address.
 *   php tests/unit/request-origin.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{RequestOrigin, SystemDatabase, SystemMetaRepository};
use GnozioCMS\Shop\{ShopSettings, ShopSettingsAdmin};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }

// ---- the list
check('addresses and ranges, by line, comma or space; the rest left out', RequestOrigin::parseList("127.0.0.1\n10.0.0.0/8, ::1 fd00::/8\nnonsense 10.0.0.0/40 1.2.3.4/x"), ['127.0.0.1', '10.0.0.0/8', '::1', 'fd00::/8']);
check('an array as stored', RequestOrigin::parseList(['127.0.0.1', 'bad']), ['127.0.0.1']);
check('nothing is nothing', RequestOrigin::parseList(''), []);
check('in a range', [RequestOrigin::inList('10.20.30.40', ['10.0.0.0/8']), RequestOrigin::inList('11.0.0.1', ['10.0.0.0/8'])], [true, false]);
check('an odd prefix length', [RequestOrigin::inList('192.168.1.130', ['192.168.1.128/25']), RequestOrigin::inList('192.168.1.127', ['192.168.1.128/25'])], [true, false]);
check('IPv6 and its ranges, never mixed with IPv4', [RequestOrigin::inList('::1', ['::1']), RequestOrigin::inList('fd12::5', ['fd00::/8']), RequestOrigin::inList('127.0.0.1', ['::1'])], [true, true, false]);
check('one address alone', [RequestOrigin::inList('127.0.0.1', ['127.0.0.1']), RequestOrigin::inList('127.0.0.2', ['127.0.0.1'])], [true, false]);

// ---- no proxies: only the connection counts
$forged = ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'evil.example', 'HTTP_HOST' => 'site.example'];
$o = new RequestOrigin($forged, []);
check('with no trusted proxy, X-Forwarded-For is ignored', $o->clientIp(), '203.0.113.5');
check('and -Proto', $o->isHttps(), false);
check('and -Host: the Host of the connection', $o->host(), 'site.example');
$o = new RequestOrigin($forged, ['10.0.0.0/8']);
check('nor from a connection that is not a listed proxy', [$o->clientIp(), $o->isHttps(), $o->host()], ['203.0.113.5', false, 'site.example']);

// ---- behind a trusted proxy
$o = new RequestOrigin(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'www.site.example', 'HTTP_HOST' => '127.0.0.1:8080'], ['127.0.0.1', '10.0.0.0/8']);
check('the visitor is the first address from the right that is not a proxy; what the visitor wrote on the left is not used', $o->clientIp(), '198.51.100.7');
check('-Proto and -Host count', [$o->isHttps(), $o->host()], [true, 'www.site.example']);
$o = new RequestOrigin(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.3'], ['127.0.0.1', '10.0.0.0/8']);
check('a chain of proxies only: the connection', $o->clientIp(), '127.0.0.1');
$o = new RequestOrigin(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'junk, 198.51.100.7'], ['127.0.0.1']);
check('an address that is not one stops the walk', $o->clientIp(), '198.51.100.7');
$o = new RequestOrigin(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, junk'], ['127.0.0.1']);
check('junk at the right end: the connection', $o->clientIp(), '127.0.0.1');

// ---- HTTPS and the host
check('HTTPS of the connection and port 443', [(new RequestOrigin(['HTTPS' => 'on'], []))->isHttps(), (new RequestOrigin(['HTTPS' => 'off'], []))->isHttps(), (new RequestOrigin(['SERVER_PORT' => '443'], []))->isHttps()], [true, false, true]);
check('a host that is not a host name is localhost', [(new RequestOrigin(['HTTP_HOST' => 'evil.example/"><script>'], []))->host(), (new RequestOrigin([], []))->host(), (new RequestOrigin(['HTTP_HOST' => 'Site.Example:8443'], []))->host(), (new RequestOrigin(['HTTP_HOST' => '[::1]:8080'], []))->host()], ['localhost', 'localhost', 'site.example:8443', '[::1]:8080']);

// ---- the shop: no card payments and no accounts switched on without a site address
$dir = sys_get_temp_dir() . '/reqorigin' . getmypid();
@mkdir($dir, 0775, true);
$db = new SystemDatabase($dir);
$db->initialize();
$settings = new ShopSettings(new SystemMetaRepository($db));
$admin = fn(string $site) => new ShopSettingsAdmin($settings, fn() => null, fn() => [], ['el'], null, null, $site);
$r = $admin('')->act(['tab' => 'payments', 'viva' => ['enabled' => '1']]);
check('Viva is refused without a site address, with the reason', [$r['ok'], str_contains($r['message'], 'site address')], [false, true]);
check('and stays off', $settings->all()['payments']['viva']['enabled'], false);
$r = $admin('')->act(['tab' => 'payments', 'cardlink' => ['enabled' => '1']]);
check('so is Cardlink', $r['ok'], false);
$r = $admin('')->act(['tab' => 'payments', 'bank_transfer' => ['enabled' => '1']]);
check('bank transfer needs no address', [$r['ok'], $settings->all()['payments']['bank_transfer']['enabled']], [true, true]);
$r = $admin('')->act(['tab' => 'accounts', 'google' => '1', 'signup' => '1']);
check('Google sign-in for customers is refused', $r['ok'], false);
$r = $admin('')->act(['tab' => 'accounts', 'signup' => '1']);
check('sign-up, on already, may be saved on', $r['ok'], true);
$r = $admin('')->act(['tab' => 'accounts']);
check('switching it off is always allowed', [$r['ok'], $settings->all()['accounts']['signup']], [true, false]);
$r = $admin('')->act(['tab' => 'accounts', 'signup' => '1']);
check('and switching it on again is refused', $r['ok'], false);
$r = $admin('https://shop.example')->act(['tab' => 'payments', 'viva' => ['enabled' => '1']]);
check('with the site address set, Viva turns on', [$r['ok'], $settings->all()['payments']['viva']['enabled']], [true, true]);
$r = $admin('')->act(['tab' => 'payments', 'viva' => ['enabled' => '1']]);
check('what is on already may be saved on', $r['ok'], true);
check('the screen knows the address is missing', [$admin('')->screen([])['no_site_address'], $admin('https://shop.example')->screen([])['no_site_address']], [true, false]);
exec('rm -rf ' . escapeshellarg($dir));

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
