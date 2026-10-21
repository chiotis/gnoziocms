<?php
/*
 * The site's side of the Omni protocol: the address it accepts, connecting with a code, signed reports and when they are due,
 * every answer Omni can give, and disconnecting. Omni is played by a function that checks what it is sent.
 *   php tests/unit/omni-client.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{OmniClient as C, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

// ---- the address
check('https is kept, without a trailing slash', C::normalizeUrl('https://Omni.FarosCMS.com/'), 'https://omni.faroscms.com');
check('no scheme means https', C::normalizeUrl('omni.example.com'), 'https://omni.example.com');
check('a path and a port are kept', C::normalizeUrl('https://example.com:8443/omni/'), 'https://example.com:8443/omni');
check('http only for a local address', [C::normalizeUrl('http://localhost:8121'), C::normalizeUrl('http://127.0.0.1:9'), C::normalizeUrl('http://omni.test'), C::normalizeUrl('http://omni.example.com')], ['http://localhost:8121', 'http://127.0.0.1:9', 'http://omni.test', '']);
check('no query, fragment, user or other scheme', [C::normalizeUrl('https://x.com/?a=1'), C::normalizeUrl('https://x.com/#a'), C::normalizeUrl('https://u:p@x.com'), C::normalizeUrl('ftp://x.com'), C::normalizeUrl('')], ['', '', '', '', '']);

// ---- a pretend Omni
$dir = sys_get_temp_dir() . '/omniclient' . getmypid();
mkdir("$dir/storage/db", 0775, true);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);

$now = 1791882000;
$omni = ['secret' => 'S3cret-from-omni_0123456789abcdefghijklmnop', 'site_id' => 's_0123456789ab', 'mode' => 'ok', 'seen' => [], 'requests' => []];
$http = function (string $method, string $url, array $headers, string $body, int $timeout) use (&$omni, &$now): array {
    $path = (string)parse_url($url, PHP_URL_PATH);
    $omni['requests'][] = [$method, $path, $body];
    $h = [];
    foreach ($headers as $line) { [$k, $v] = explode(':', $line, 2); $h[strtolower($k)] = trim($v); }
    $json = fn(int $status, array $data, array $headers = []) => ['status' => $status, 'body' => json_encode($data), 'headers' => $headers, 'error' => ''];
    if ($omni['mode'] === 'down') {
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Connection refused'];
    }
    if ($path === '/omni/api/v1/ping') {
        return $omni['mode'] === 'not-omni' ? ['status' => 200, 'body' => '<html>', 'headers' => [], 'error' => ''] : $json(200, ['omni' => true, 'name' => 'Test Omni', 'protocols' => $omni['mode'] === 'v2' ? [2] : [1]]);
    }
    if ($path === '/omni/api/v1/connect') {
        $data = json_decode($body, true);
        if (($data['code'] ?? '') !== 'K7QM-4TZP-9WXC-2HRD') return $json(403, ['ok' => false, 'error' => 'invalid_code']);
        $omni['connected_with'] = $data;
        return $json(200, ['site_id' => $omni['site_id'], 'secret' => $omni['secret'], 'report_every' => 7200]);
    }
    // Signed requests, checked as Omni checks them.
    $toSign = implode("\n", ['POST', $path, $h['x-omni-timestamp'] ?? '', $h['x-omni-nonce'] ?? '', hash('sha256', $body)]);
    $good = ($h['x-omni-site'] ?? '') === $omni['site_id'] && hash_equals('v1=' . hash_hmac('sha256', $toSign, $omni['secret']), $h['x-omni-signature'] ?? '')
        && abs($now - (int)($h['x-omni-timestamp'] ?? 0)) <= 300 && preg_match('/^[0-9a-f]{32}$/', $h['x-omni-nonce'] ?? '') && !in_array($h['x-omni-nonce'], $omni['seen'], true);
    $omni['seen'][] = $h['x-omni-nonce'] ?? '';
    if (!$good) return $json(401, ['ok' => false, 'error' => 'invalid_signature']);
    return match ($omni['mode']) {
        'removed' => $json(410, ['ok' => false, 'error' => 'site_removed']),
        'busy' => $json(429, ['ok' => false, 'error' => 'rate_limited'], ['retry-after' => '120']),
        'broken' => $json(500, ['ok' => false]),
        default => $path === '/omni/api/v1/report' ? $json(200, ['ok' => true, 'report_every' => $omni['every'] ?? 7200]) : $json(200, ['ok' => true]),
    };
};
$fingerprint = 'run1|success|0.1.102';
$client = new C($meta, $http, fn() => '{"protocol":1,"site":{"name":"Doxiadis"}}', fn() => ['name' => 'Doxiadis', 'url' => 'https://doxiadis.com/', 'admin_url' => 'https://doxiadis.com/admin', 'version' => '0.1.102'], function () use (&$fingerprint) { return $fingerprint; }, function () use (&$now) { return $now; });

// ---- connecting
check('not connected at first', [$client->isConnected(), $client->state(), $client->sendIfDue()], [false, [], null]);
check('an address that is not allowed is refused before anything is sent', [$client->connect('http://omni.example.com', 'K7QM-4TZP-9WXC-2HRD')['ok'], count($omni['requests'])], [false, 0]);
check('no code, no connection', $client->connect('https://omni.test/omni', ' ')['ok'], false);
$omni['mode'] = 'down';
$r = $client->connect('https://omni.test/omni', 'K7QM-4TZP-9WXC-2HRD');
check('nobody answering is said so, with the reason', [$r['ok'], str_contains($r['message'], 'No Omni answered at https://omni.test/omni (Connection refused)')], [false, true]);
$omni['mode'] = 'not-omni';
check('a page that is not an Omni is not taken for one', str_contains($client->connect('https://omni.test/omni', 'K7QM-4TZP-9WXC-2HRD')['message'], 'No Omni answered'), true);
$omni['mode'] = 'v2';
check('an Omni that does not speak protocol 1', str_contains($client->connect('https://omni.test/omni', 'K7QM-4TZP-9WXC-2HRD')['message'], 'does not speak protocol 1'), true);
$omni['mode'] = 'ok';
$r = $client->connect('https://omni.test/omni/', 'WRONG-CODE');
check('a wrong code: what to do about it', [$r['ok'], str_contains($r['message'], 'Make a new one in Omni')], [false, true]);
check('and nothing is kept', $client->state(), []);
$omni['requests'] = [];
$r = $client->connect('https://omni.test/omni/', 'K7QM-4TZP-9WXC-2HRD');
check('the right code connects, and the first report goes at once', [$r['ok'], $r['message']], [true, 'Connected to https://omni.test/omni. The first report was sent.']);
check('ping, connect, report, in that order', array_map(fn($q) => $q[0] . ' ' . $q[1], $omni['requests']), ['GET /omni/api/v1/ping', 'POST /omni/api/v1/connect', 'POST /omni/api/v1/report']);
check('the site says who it is and where its admin is', [$omni['connected_with']['protocol'], $omni['connected_with']['site']['admin_url'], $omni['connected_with']['site']['version']], [1, 'https://doxiadis.com/admin', '0.1.102']);
$state = $client->state();
check('what is kept: the address, the id, the secret, the interval Omni asked for, the first report', [$state['status'], $state['url'], $state['site_id'], $state['secret'], $state['report_every'], $state['last_sent_at'], $state['fingerprint']], ['connected', 'https://omni.test/omni', 's_0123456789ab', $omni['secret'], 7200, gmdate('c', $now), 'run1|success|0.1.102']);

// ---- when a report is due
check('just sent: not due', [$client->isDue(), $client->sendIfDue()], [false, null]);
$now += 7199;
check('one second before the interval: not due', $client->isDue(), false);
$now += 1;
check('at the interval: due, and sent', [$client->isDue(), $client->sendIfDue()], [true, ['ok' => true, 'message' => 'Report sent.']]);
$now += 60;
$fingerprint = 'run2|failed|0.1.102';
check('a new backup run makes it due at once', [$client->isDue(), $client->sendIfDue()['ok'] ?? null, $client->isDue()], [true, true, false]);
$fingerprint = 'run2|failed|0.1.103';
check('so does another version', $client->isDue(), true);
$omni['every'] = 60;
$client->send();
check('the interval Omni asks for is kept within 15 minutes and a day', $client->state()['report_every'], 900);
$omni['every'] = 999999;
$client->send();
check('(and not more than a day)', $client->state()['report_every'], 86400);

// ---- what Omni can answer
$omni['mode'] = 'broken';
$r = $client->send();
$state = $client->state();
check('a server error is kept to show, and the next try waits 15 minutes', [$r['ok'], $state['last_error'], $state['retry_at']], [false, 'Omni refused the report (HTTP 500).', $now + 900]);
$fingerprint = 'run3|success|0.1.103';
check('even a new backup does not try again before then', $client->isDue(), false);
$now += 901;
$omni['mode'] = 'ok';
check('after it, it does, and a success clears the problem', [$client->sendIfDue()['ok'] ?? null, isset($client->state()['last_error']), isset($client->state()['retry_at'])], [true, false, false]);
$omni['mode'] = 'busy';
$client->send();
check('rate limited: waits as long as Omni says', $client->state()['retry_at'], $now + 120);
$omni['mode'] = 'down';
check('Omni unreachable', $client->send()['message'], 'Omni could not be reached: Connection refused');
$omni['mode'] = 'ok';
$real = $omni['secret'];
$omni['secret'] = 'Omni-forgot-it_0123456789abcdefghijklmnopq';
check('a signature Omni does not accept: connect again', str_contains($client->send()['message'], 'Connect again with a new code'), true);
$omni['secret'] = $real;
$now += 400;
check('minutes later, a report is signed with the time it is sent', $client->send()['ok'], true);
$omni['mode'] = 'removed';
$r = $client->send();
check('removed by Omni: the secret is forgotten, the address kept', [$r['ok'], $client->isConnected(), $client->state()['status'], $client->state()['url'], isset($client->state()['secret'])], [false, false, 'removed', 'https://omni.test/omni', false]);
check('and nothing is sent any more', [$client->isDue(), $client->send()['ok']], [false, false]);

// ---- disconnecting
$omni['mode'] = 'ok';
$client->connect('https://omni.test/omni', 'K7QM-4TZP-9WXC-2HRD');
$omni['requests'] = [];
$r = $client->disconnect();
check('disconnecting tells Omni, signed, then forgets the secret', [$r['message'], $omni['requests'][0][1] ?? null, $omni['requests'][0][2] ?? null, $client->state()], ['Disconnected from https://omni.test/omni.', '/omni/api/v1/disconnect', '{}', ['url' => 'https://omni.test/omni']]);
$client->connect('https://omni.test/omni', 'K7QM-4TZP-9WXC-2HRD');
$omni['mode'] = 'down';
$r = $client->disconnect();
check('when Omni cannot be told, the site still leaves, and says so', [$r['ok'], str_contains($r['message'], 'Omni could not be told'), $client->isConnected()], [true, true, false]);

// ---- commands from Omni
$omni['mode'] = 'ok';
$client->connect('https://omni.test/omni', 'K7QM-4TZP-9WXC-2HRD');
$cmd = function (array $over = [], ?string $body = null) use (&$omni, &$now, $client) {
    $body ??= json_encode(['protocol' => 1, 'command' => 'update', 'command_id' => 'c_0011223344556677']);
    $nonce = $over['nonce'] ?? bin2hex(random_bytes(16));
    $time = (string)($over['time'] ?? $now);
    $headers = ['x-omni-site' => $over['site'] ?? $omni['site_id'], 'x-omni-timestamp' => $time, 'x-omni-nonce' => $nonce,
        'x-omni-signature' => C::signature($over['secret'] ?? $omni['secret'], '/_omni/command', $body, $time, $nonce)];
    return $client->receiveCommand('/_omni/command', $headers, $body);
};
check('another secret is refused', [$cmd(['secret' => 'nope'])['status'], $cmd(['secret' => 'nope'])['body']['error']], [401, 'invalid_signature']);
check('another site id is refused', $cmd(['site' => 's_ffffffffffff'])['body']['error'], 'unknown_site');
check('a time more than five minutes off is refused', $cmd(['time' => $now - 301])['body']['error'], 'stale_timestamp');
check('a command other than update is refused', $cmd([], json_encode(['protocol' => 1, 'command' => 'shell', 'command_id' => 'c_0011223344556677']))['body']['error'], 'unknown_command');
check('another protocol is refused', $cmd([], json_encode(['protocol' => 2, 'command' => 'update', 'command_id' => 'c_0011223344556677']))['status'], 426);
check('updates are allowed until switched off', $client->allowsUpdates(), true);
$client->setAllowUpdates(false);
check('switched off: refused', [$cmd()['status'], $cmd()['body']['error'], $cmd()['run']], [403, 'not_allowed', null]);
$client->setAllowUpdates(true);
$r = $cmd(['nonce' => str_repeat('ab', 16)]);
check('a good command is accepted and given to run', [$r['status'], $r['body'], $r['run']['id'] ?? null, $client->state()['command']['state'] ?? null], [202, ['ok' => true, 'command_id' => 'c_0011223344556677', 'state' => 'accepted'], 'c_0011223344556677', 'accepted']);
check('the same nonce again is a replay', $cmd(['nonce' => str_repeat('ab', 16)])['body']['error'], 'replayed_nonce');
check('a second command while one is open is refused', $cmd()['body']['error'], 'busy');
$omni['requests'] = [];
$client->runCommand($r['run'], fn() => ['status' => 'installed', 'message' => 'GnozioCMS 0.2.0 is installed.', 'from' => '0.1.9', 'to' => '0.2.0']);
$sent = json_decode($omni['requests'][0][2] ?? '{}', true);
check('running it reports at once, with how it ended', [$omni['requests'][0][1] ?? null, $sent['command']['state'] ?? null, $sent['command']['to'] ?? null, $sent['command']['id'] ?? null], ['/omni/api/v1/report', 'installed', '0.2.0', 'c_0011223344556677']);
check('and the report keeps the rest of itself', $sent['site']['name'] ?? null, 'Doxiadis');
check('no secret or nonce goes into the report', [isset($sent['command']['secret']), str_contains($omni['requests'][0][2], $omni['secret']), str_contains($omni['requests'][0][2], str_repeat('ab', 16))], [false, false, false]);
$client->runCommand(['id' => 'c_9999999999999999', 'type' => 'update', 'state' => 'accepted', 'accepted_at' => gmdate('c', $now)], function () { throw new RuntimeException('disk gone'); });
check('a command that is no longer the current one is not recorded over it', $client->state()['command']['id'], 'c_0011223344556677');
$r = $cmd();
$client->runCommand($r['run'], function () { throw new RuntimeException('disk gone'); });
check('an update that throws ends as failed, with why', [$client->state()['command']['state'], $client->state()['command']['message']], ['failed', 'The update stopped: disk gone']);

// ---- the signature itself, against a known value
check('the headers of a signed request', C::signatureHeaders('s_x', 'secret', '/api/v1/report', '{}', 1700000000, '00112233445566778899aabbccddeeff'), [
    'X-Omni-Site: s_x', 'X-Omni-Timestamp: 1700000000', 'X-Omni-Nonce: 00112233445566778899aabbccddeeff',
    'X-Omni-Signature: v1=' . hash_hmac('sha256', "POST\n/api/v1/report\n1700000000\n00112233445566778899aabbccddeeff\n" . hash('sha256', '{}'), 'secret'),
]);

// ---- the transport: no answer is an error, never an exception or a warning
$r = C::request('GET', 'http://127.0.0.1:9/api/v1/ping', [], '', 2);
check('nothing listening: status 0 and the reason', [$r['status'], $r['error'] !== ''], [0, true]);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
