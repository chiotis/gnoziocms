<?php
/*
 * The cache in front of the site: CloudPanel's controller is found and handed one purge with its tag, a response that must not be
 * kept says so, and any other Varnish gets a PURGE with the site's host at the address that was saved.
 *   php tests/unit/front-cache.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{FrontCache, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/frontcache' . getmypid();
mkdir("$dir/storage/db", 0775, true);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);

$cache = new FrontCache($meta, 'site.test');
check('without a cache there is nothing to empty', [$cache->mode(), $cache->purgeAll()], ['none', false]);
FrontCache::doNotKeep();
check('and a response that must not be kept is no trouble', true, true);

check('an address that is not one is refused', [$cache->setAddress('ftp://x'), $cache->setAddress('http://user:pw@x:1'), $cache->address()], [false, false, '']);

// A small server that answers each PURGE and writes down what it was asked (the built-in server of PHP knows no PURGE).
$listen = stream_socket_server('tcp://127.0.0.1:0');
$name = (string)stream_socket_get_name($listen, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($listen);
$serverCode = '$s = stream_socket_server("tcp://127.0.0.1:' . $port . '"); while ($c = stream_socket_accept($s, 30)) { $head = ""; while (($l = fgets($c)) !== false && trim($l) !== "") { $head .= $l; } preg_match("/^Host: (.*)$/mi", $head, $h); file_put_contents(' . var_export("$dir/asked.txt", true) . ', strtok($head, "\r\n") . " " . trim($h[1] ?? "")); fwrite($c, "HTTP/1.1 200 Purged\r\nContent-Length: 0\r\n\r\n"); fclose($c); }';
$server = proc_open([PHP_BINARY, '-r', $serverCode], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !($probe = @fsockopen('127.0.0.1', $port)); $i++) { usleep(100000); }
if ($probe) { fclose($probe); }

check('an address is kept', [$cache->setAddress("127.0.0.1:$port"), (new FrontCache($meta, 'site.test'))->mode()], [true, 'varnish']);
check('the cache is asked with PURGE / and the host of the site', [$cache->sendPurge("127.0.0.1:$port"), (string)@file_get_contents("$dir/asked.txt")], [200, 'PURGE / HTTP/1.1 site.test']);
check('an http address works too', $cache->sendPurge("http://127.0.0.1:$port"), 200);
check('no answer is 0', $cache->sendPurge('127.0.0.1:1'), 0);
check('emptying is asked for once per request', [$cache->purgeAll(), $cache->purgeAll()], [true, true]);
proc_terminate($server);
check('an empty address forgets it', [$cache->setAddress(''), $cache->mode()], [true, 'none']);

// CloudPanel's controller, as its controller.php sets it up.
class ClpVarnish
{
    public array $purged = [];
    public bool $cacheable = true;
    public static function getCacheTagPrefix(): string { return '057e'; }
    public function addTagsToPurge(array $tags) { $this->purged = array_merge($this->purged, $tags); }
    public function setIsCacheable($flag): void { $this->cacheable = $flag; }
}
$GLOBALS['clpVarnish'] = new ClpVarnish();
$cloud = new FrontCache($meta, 'site.test');
check('CloudPanel is found by itself, with its tag', [$cloud->mode(), $cloud->cloudPanelTag()], ['cloudpanel', '057e']);
check('it is handed the tag of the site once', [$cloud->purgeAll(), $cloud->purgeAll(), $GLOBALS['clpVarnish']->purged], [true, true, ['057e']]);
FrontCache::doNotKeep();
check('a response that must not be kept is not cached by it', $GLOBALS['clpVarnish']->cacheable, false);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exec('rm -rf ' . escapeshellarg($dir));
exit($fail === 0 ? 0 : 1);
