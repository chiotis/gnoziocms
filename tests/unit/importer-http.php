<?php
/*
 * The WordPress and WooCommerce imports read the other site through SafeHttp (audit S18): public addresses only, each redirect
 * checked, headers only to the host first asked, unless a local address is allowed for a site moved on the same server.
 *   php tests/unit/importer-http.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{SafeHttp, WordPressHttp};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dns = ['shop.example' => ['93.184.216.34'], 'cdn.example' => ['93.184.216.35'], 'inside.example' => ['10.0.0.5'], 'old.local' => ['127.0.0.1']];
$asked = [];
$pages = [
    'https://shop.example/wp-json/wc/store/v1/products' => [200, '', '[{"id":1}]'],
    'https://shop.example/moved' => [301, 'http://127.0.0.1/wp-json/', ''],
    'https://shop.example/to-cdn' => [302, 'https://cdn.example/a.jpg', ''],
    'https://cdn.example/a.jpg' => [200, '', 'JPEG'],
    'https://shop.example/private' => [401, '', '{"code":"rest_forbidden"}'],
    'http://old.local:8080/wp-json/' => [200, '', '{"name":"Old"}'],
];
WordPressHttp::$http = new SafeHttp(
    fn(string $host): array => $dns[$host] ?? [],
    function (string $url, string $ip, string $to, int $max, int $timeout, string $accept, array $headers = []) use (&$asked, $pages): array {
        $asked[] = [$url, $ip, array_values(array_filter($headers, static fn(string $h): bool => str_starts_with($h, 'Authorization')))];
        [$status, $location, $body] = $pages[$url] ?? [404, '', 'none'];
        file_put_contents($to, $body);
        return ['status' => $status, 'location' => $location, 'error' => ''];
    }
);

check('a public shop is read', WordPressHttp::get('https://shop.example/wp-json/wc/store/v1/products'), ['status' => 200, 'body' => '[{"id":1}]']);
check('a status other than 200 is still answered (the import reads 401)', WordPressHttp::get('https://shop.example/private')['status'] ?? null, 401);
$asked = [];
check('an address that redirects to 127.0.0.1 is refused', [WordPressHttp::get('https://shop.example/moved'), str_contains(WordPressHttp::$lastError, 'private or reserved'), count($asked)], [null, true, 1]);
check('an address on a private network is refused before anything is asked', [WordPressHttp::get('https://inside.example/wp-json/'), WordPressHttp::download('http://old.local:8080/wp-json/', sys_get_temp_dir() . '/imp' . getmypid())], [null, 0]);
$asked = [];
check('the keys go to the shop, never on to the host a redirect names', [WordPressHttp::get('https://shop.example/to-cdn', ['Authorization: Basic eHg6eXk='])['body'] ?? null, array_column($asked, 2)], ['JPEG', [['Authorization: Basic eHg6eXk='], []]]);
check('the connection goes to the address that was checked', $asked[0][1], '93.184.216.34');
WordPressHttp::$allowLocal = true;
check('allowed (--allow-local, or the import screen\'s box): a site being moved on this server, any port', WordPressHttp::get('http://old.local:8080/wp-json/'), ['status' => 200, 'body' => '{"name":"Old"}']);
WordPressHttp::$allowLocal = false;
check('not an http address: nothing', WordPressHttp::get('file:///etc/passwd'), null);
@unlink(sys_get_temp_dir() . '/imp' . getmypid());

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
