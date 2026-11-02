<?php
/*
 * The MCP server without a web server, with a clock the test moves: codes used once and only for five minutes, access tokens
 * that end, refresh tokens that rotate with a minute of grace, removed connections, rate limits, client registration, the
 * consent (only scopes asked for and left ticked), and the endpoint: a token's account must be active, a paused connection
 * and a missing permission are told to the model, and a request from another origin is refused.
 *   php tests/unit/mcp.php
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnozioCMS\{McpOAuth, McpServer, McpStore, SystemDatabase, UserRepository};

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$dir = sys_get_temp_dir() . '/mcp' . getmypid();
mkdir("$dir/storage/db", 0775, true);
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$users = new UserRepository($db, "$dir/none.yaml");
$botId = $users->create(['username' => 'newsroom', 'role' => 'editor', 'source' => UserRepository::AUTOMATION]);
$now = 1_800_000_000;
$store = new McpStore($db, function () use (&$now): int { return $now; });
$log = [];
$logger = function (string $action, string $level, ?string $type, ?string $id, string $message, array $context) use (&$log): void { $log[] = $action; };
$oauth = new McpOAuth($store, 'https://site.test', $logger);
$resource = 'https://site.test/mcp';

// ---- registration
check('a public client with an HTTPS address registers', $oauth->register(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])['status'], 201);
check('http is only for localhost', [$oauth->register(['redirect_uris' => ['http://x.test/cb']])['status'], $oauth->register(['redirect_uris' => ['http://127.0.0.1:3000/cb']])['status']], [400, 201]);
check('a client with a secret is refused', $oauth->register(['redirect_uris' => ['https://a.test/cb'], 'token_endpoint_auth_method' => 'client_secret_post'])['status'], 400);
check('so is an implicit grant', $oauth->register(['redirect_uris' => ['https://a.test/cb'], 'grant_types' => ['implicit']])['status'], 400);
check('and no JSON object', $oauth->register('nope')['status'], 400);
$client = $oauth->register(['client_name' => 'Job runner', 'redirect_uris' => ['https://runner.test/cb']])['json'];

// ---- the consent request
$verifier = str_repeat('v', 50);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$ask = ['client_id' => $client['client_id'], 'redirect_uri' => 'https://runner.test/cb', 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 's1', 'resource' => $resource, 'scope' => 'content:read content:write'];
$checked = $oauth->authorizationRequest($ask);
check('a good request is accepted with what it asked', [$checked['ok'], $checked['request']['scope']], [true, 'content:read content:write']);
check('no scope asked is every scope', $oauth->authorizationRequest(['scope' => ''] + $ask)['request']['scope'], implode(' ', array_keys(McpOAuth::scopes())));
check('an unknown scope goes back with invalid_scope', [$oauth->authorizationRequest(['scope' => 'admin'] + $ask)['problem'], $oauth->authorizationRequest(['scope' => 'admin'] + $ask)['redirect']], ['invalid_scope', true]);
check('another resource goes back with invalid_target', $oauth->authorizationRequest(['resource' => 'https://other.test/mcp'] + $ask)['problem'], 'invalid_target');
check('an unknown client is shown, never followed', $oauth->authorizationRequest(['client_id' => 'x'] + $ask)['redirect'], false);
check('an address not registered is shown, never followed', $oauth->authorizationRequest(['redirect_uri' => 'https://evil.test/cb'] + $ask)['redirect'], false);
$refused = $oauth->decide($checked['request'], false, $botId, 1, []);
check('refusing goes back with access_denied and the state', $refused, 'https://runner.test/cb?error=access_denied&state=s1');
$back = $oauth->decide($checked['request'], true, $botId, 1, ['content:read', 'content:publish']);
parse_str((string)parse_url($back, PHP_URL_QUERY), $q);
check('approving gives a code', isset($q['code']) && $q['state'] === 's1', true);

// ---- tokens
$active = fn(int $id): bool => ($u = $users->find($id)) !== null && $users->isActive($u);
$token = static fn(array $post) => $oauth->token($post + ['client_id' => $client['client_id'], 'redirect_uri' => 'https://runner.test/cb', 'resource' => $resource], $active);
$now += 301;
check('a code is good for five minutes', $token(['grant_type' => 'authorization_code', 'code' => $q['code'], 'code_verifier' => $verifier])['status'], 400);
$now -= 301;
$wrongClient = $oauth->register(['redirect_uris' => ['https://runner.test/cb']])['json'];
check('another client cannot use it', $oauth->token(['grant_type' => 'authorization_code', 'client_id' => $wrongClient['client_id'], 'code' => $q['code'], 'code_verifier' => $verifier, 'redirect_uri' => 'https://runner.test/cb', 'resource' => $resource], $active)['status'], 400);
$back = $oauth->decide($checked['request'], true, $botId, 1, ['content:read', 'content:publish']);
parse_str((string)parse_url($back, PHP_URL_QUERY), $q);
$pair = $token(['grant_type' => 'authorization_code', 'code' => $q['code'], 'code_verifier' => $verifier]);
check('the code gives a pair, with only the scope asked and left ticked', [$pair['status'], $pair['json']['scope'], isset($pair['json']['refresh_token'])], [200, 'content:read', true]);
check('the code works once', $token(['grant_type' => 'authorization_code', 'code' => $q['code'], 'code_verifier' => $verifier])['status'], 400);
check('the access token finds its connection and account', [$store->connectionForAccess($pair['json']['access_token'], $resource)['username'] ?? null, $store->connectionForAccess($pair['json']['access_token'], 'https://other.test/mcp')], ['newsroom', null]);
$now += McpStore::ACCESS_SECONDS + 1;
check('the access token ends after an hour', $store->connectionForAccess($pair['json']['access_token'], $resource), null);
$next = $token(['grant_type' => 'refresh_token', 'refresh_token' => $pair['json']['refresh_token']]);
check('the refresh token gives a new pair', [$next['status'], $next['json']['refresh_token'] !== $pair['json']['refresh_token']], [200, true]);
$now += 30;
check('within a minute the old refresh token still works', $token(['grant_type' => 'refresh_token', 'refresh_token' => $pair['json']['refresh_token']])['status'], 200);
$now += 31;
check('after a minute it does not', $token(['grant_type' => 'refresh_token', 'refresh_token' => $pair['json']['refresh_token']])['status'], 400);
$bot = $users->find($botId);
$users->update($botId, ['status' => 'inactive'] + $bot);
check('an inactive account gets no new tokens', $token(['grant_type' => 'refresh_token', 'refresh_token' => $next['json']['refresh_token']])['status'], 400);
$users->update($botId, $bot);
$now += McpStore::REFRESH_SECONDS + 10;
check('a refresh token ends after 90 days', $token(['grant_type' => 'refresh_token', 'refresh_token' => $next['json']['refresh_token']])['status'], 400);
check('only the two grants exist', $token(['grant_type' => 'password'])['status'], 400);

// ---- connections
$manual = $store->createConnection('token', 'Daily news', null, $botId, 1, 'content:read', $resource);
$hand = $store->issueTokens($manual, false, 86400);
check('a token made by hand has no refresh token', array_key_exists('refresh_token', $hand), false);
check('and works for its days', $store->connectionForAccess($hand['access_token'], $resource)['name'] ?? null, 'Daily news');
$store->setPaused($manual, true);
check('a paused connection is still found, marked paused', $store->connectionForAccess($hand['access_token'], $resource)['paused_at'] !== null, true);
$store->revoke($manual);
check('a removed one is not', $store->connectionForAccess($hand['access_token'], $resource), null);

// ---- rate limits
$hits = [];
for ($i = 0; $i < 4; $i++) { $hits[] = $store->hit('b', 'ip', 3, 60)['allowed']; }
check('a limit lets its number through, then refuses', $hits, [true, true, true, false]);
$now += 60;
check('a new window starts again', $store->hit('b', 'ip', 3, 60)['allowed'], true);

// ---- the endpoint
$server = new McpServer($store, $oauth, '9.9.9', fn(array $account, string $permission): bool => true, fn(array $connection): array => ['site' => 'x'], $logger);
$live = $store->createConnection('token', 'Live', null, $botId, 1, 'content:read', $resource);
$bearer = $store->issueTokens($live, false, 86400)['access_token'];
$call = static fn(array $message, array $headers = []) => $server->handle('POST', $headers + ['authorization' => 'Bearer ' . $bearer, 'content-type' => 'application/json'], json_encode($message), '10.0.0.1');
$body = static fn(array $answer): array => json_decode((string)$answer['body'], true);
check('initialize offers the version asked when known', $body($call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]))['result']['protocolVersion'], '2025-03-26');
check('and its own otherwise', $body($call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]))['result']['protocolVersion'], McpServer::PROTOCOL_VERSIONS[0]);
check('site_info answers', $body($call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'site_info']]))['result']['structuredContent'], ['site' => 'x']);
check('the same origin is fine', $call(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], ['origin' => 'https://site.test'])['status'], 200);
check('another origin is not', $call(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], ['origin' => 'https://evil.test'])['status'], 403);
check('a request without an id is a notification', $call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])['status'], 202);
$store->setPaused($live, true);
$paused = $body($call(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'site_info']]))['result'];
check('a paused connection is told so', [$paused['isError'], str_contains($paused['content'][0]['text'], 'paused')], [true, true]);
$store->setPaused($live, false);
$users->update($botId, ['status' => 'inactive'] + $bot);
check('an inactive account is refused at the door', $call(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping'])['status'], 401);
$users->update($botId, $bot);
$noRights = new McpServer($store, $oauth, '9.9.9', fn(array $account, string $permission): bool => false, fn(array $connection): array => ['site' => 'y'], $logger);
$answer = json_decode((string)$noRights->handle('POST', ['authorization' => 'Bearer ' . $bearer, 'content-type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list']), '10.0.0.1')['body'], true);
check('site_info asks for a scope only, so any account sees it', array_column($answer['result']['tools'], 'name'), ['site_info']);
check('the log names the calls', in_array('mcp.tool_called', $log, true) && in_array('mcp.tool_refused', $log, true), true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
