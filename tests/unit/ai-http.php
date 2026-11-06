<?php
/*
 * The client for AI provider APIs (AiHttp), without the network: only https and the provider's own hosts; JSON and multipart bodies
 * (a repeated image[] field, files with their type); the answer decoded with its time, size and rate-limit headers; every failure
 * sorted into its kind (no credit, rate limit, refused, key, bad request, timeout, server, network) for OpenAI, Anthropic and Google
 * error shapes; Retry-After and OpenAI's reset durations; and the key never in an error message.
 *   php tests/unit/ai-http.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\AiHttp;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$key = 'sk-proj-SECRETabcdef1234567890';
$sent = [];
$answer = ['status' => 200, 'headers' => [], 'body' => '{}', 'error' => '', 'timed_out' => false];
$http = new AiHttp(['api.openai.com'], [$key], function (string $method, string $url, array $headers, ?string $body, int $timeout, int $max) use (&$sent, &$answer): array {
    $sent[] = compact('method', 'url', 'headers', 'body', 'timeout', 'max');
    return $answer;
});
$auth = ['Authorization: Bearer ' . $key];

// ---- hosts
foreach ([
    'http://api.openai.com/v1/models' => 'plain http',
    'https://evil.example/v1/models' => 'another host',
    'https://api.openai.com.evil.example/v1' => 'a host that only starts like it',
    'https://api.openai.com:8443/v1/models' => 'another port',
    'https://user:pass@api.openai.com/v1/models' => 'a name and password',
] as $url => $label) {
    $before = count($sent);
    $r = $http->get($url, $auth);
    check('refused: ' . $label, [$r['ok'], $r['kind'], count($sent) === $before], [false, AiHttp::BAD_REQUEST, true]);
}

// ---- a good JSON answer
$answer = ['status' => 200, 'headers' => ['X-RateLimit-Remaining-Requests' => '499', 'x-request-id' => 'req_1'], 'body' => '{"id":"resp_1","usage":{"input_tokens":12}}', 'error' => '', 'timed_out' => false];
$r = $http->postJson('https://api.openai.com/v1/responses', ['model' => 'm', 'input' => 'Γεια'], $auth, 90);
$last = end($sent);
check('a JSON answer is decoded', [$r['ok'], $r['data']['usage']['input_tokens'] ?? null], [true, 12]);
check('the body is JSON, unicode kept', $last['body'], '{"model":"m","input":"Γεια"}');
check('JSON content type, the key header, the time limit', [in_array('Content-Type: application/json', $last['headers'], true), in_array($auth[0], $last['headers'], true), $last['timeout']], [true, true, 90]);
check('rate-limit headers kept, lower-case, others not', $r['rate'], ['x-ratelimit-remaining-requests' => '499']);
check('size of the answer', $r['bytes'], strlen($answer['body']));
check('a time is measured', is_int($r['ms']) && $r['ms'] >= 0, true);

$answer = ['status' => 200, 'headers' => [], 'body' => '<html>gateway</html>', 'error' => '', 'timed_out' => false];
$r = $http->get('https://api.openai.com/v1/models', $auth);
check('a 200 that is not JSON is a server problem', [$r['ok'], $r['kind']], [false, AiHttp::SERVER]);

// ---- multipart
$dir = sys_get_temp_dir() . '/aihttp' . getmypid();
mkdir($dir);
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$dir/one.png", $png);
file_put_contents("$dir/two.png", $png);
$answer = ['status' => 200, 'headers' => [], 'body' => '{"data":[]}', 'error' => '', 'timed_out' => false];
$r = $http->postMultipart('https://api.openai.com/v1/images/edits', ['model' => 'gpt-image', 'prompt' => 'A "calm" sea'], ['image[]' => ["$dir/one.png", "$dir/two.png"]], $auth);
$last = end($sent);
$type = array_values(array_filter($last['headers'], static fn(string $h): bool => str_starts_with($h, 'Content-Type: multipart/form-data; boundary=')));
check('multipart content type with a boundary', count($type), 1);
$boundary = substr($type[0] ?? '', strlen('Content-Type: multipart/form-data; boundary='));
check('the field image[] repeats once per picture', substr_count((string)$last['body'], 'name="image[]"; filename='), 2);
check('each picture with its type', substr_count((string)$last['body'], "Content-Type: image/png\r\n"), 2);
check('a field with its value', str_contains((string)$last['body'], "name=\"model\"\r\n\r\ngpt-image\r\n"), true);
check('the bytes of the picture are sent', str_contains((string)$last['body'], $png), true);
check('the body ends with the closing boundary', str_ends_with((string)$last['body'], '--' . $boundary . "--\r\n"), true);
$before = count($sent);
$r = $http->postMultipart('https://api.openai.com/v1/images/edits', [], ['image[]' => ["$dir/missing.png"]], $auth);
check('a missing file is refused before sending', [$r['ok'], $r['kind'], count($sent) === $before], [false, AiHttp::BAD_REQUEST, true]);

// ---- failures, by kind
$cases = [
    'OpenAI no credit' => [429, '{"error":{"message":"You exceeded your current quota.","type":"insufficient_quota","code":"insufficient_quota"}}', AiHttp::NO_CREDIT],
    'OpenAI rate limit' => [429, '{"error":{"message":"Rate limit reached","type":"requests","code":"rate_limit_exceeded"}}', AiHttp::RATE_LIMIT],
    'OpenAI refused picture' => [400, '{"error":{"message":"Your request was rejected by the safety system.","code":"moderation_blocked"}}', AiHttp::REFUSED],
    'OpenAI content policy' => [400, '{"error":{"message":"policy","code":"content_policy_violation"}}', AiHttp::REFUSED],
    'OpenAI wrong key' => [401, '{"error":{"message":"Incorrect API key provided: ' . $key . '.","code":"invalid_api_key"}}', AiHttp::AUTH],
    'OpenAI model not allowed' => [403, '{"error":{"message":"Your organization must be verified."}}', AiHttp::AUTH],
    'OpenAI bad request' => [400, '{"error":{"message":"Unknown parameter: \'foo\'.","type":"invalid_request_error"}}', AiHttp::BAD_REQUEST],
    'OpenAI unknown model' => [404, '{"error":{"message":"The model does not exist."}}', AiHttp::BAD_REQUEST],
    'OpenAI server' => [500, '{"error":{"message":"The server had an error."}}', AiHttp::SERVER],
    'gateway timeout' => [504, '', AiHttp::TIMEOUT],
    'Anthropic overloaded' => [529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', AiHttp::SERVER],
    'Anthropic rate limit' => [429, '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}', AiHttp::RATE_LIMIT],
    'Anthropic no credit' => [400, '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API."}}', AiHttp::NO_CREDIT],
    'Anthropic key' => [401, '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}', AiHttp::AUTH],
    'Google exhausted' => [429, '{"error":{"code":429,"message":"Quota exceeded","status":"RESOURCE_EXHAUSTED"}}', AiHttp::RATE_LIMIT],
    'Google key' => [400, '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT"}}', AiHttp::AUTH],
    'Google bad argument' => [400, '{"error":{"code":400,"message":"Invalid JSON payload.","status":"INVALID_ARGUMENT"}}', AiHttp::BAD_REQUEST],
];
foreach ($cases as $label => [$status, $body, $kind]) {
    $answer = ['status' => $status, 'headers' => [], 'body' => $body, 'error' => '', 'timed_out' => false];
    $r = $http->postJson('https://api.openai.com/v1/responses', [], $auth);
    check('kind: ' . $label, [$r['ok'], $r['kind']], [false, $kind]);
}
$answer = ['status' => 401, 'headers' => [], 'body' => $cases['OpenAI wrong key'][1], 'error' => '', 'timed_out' => false];
$r = $http->postJson('https://api.openai.com/v1/responses', [], $auth);
check('the key the provider repeats is cut out of the error', [str_contains($r['error'], $key), str_contains($r['error'], '[key]')], [false, true]);
check('the error is one plain sentence with the provider\'s words', str_starts_with($r['error'], 'The provider did not accept the key'), true);
$answer = ['status' => 400, 'headers' => [], 'body' => '{"error":{"message":"bad sk-other-0123456789abcdef value"}}', 'error' => '', 'timed_out' => false];
$r = $http->postJson('https://api.openai.com/v1/responses', [], $auth);
check('anything shaped like a key is cut out too', str_contains($r['error'], 'sk-other'), false);
check('the decoded error answer is kept for the caller', $r['data']['error']['message'] ?? null, 'bad sk-other-0123456789abcdef value');
$answer = ['status' => 400, 'headers' => [], 'body' => '{"error":{"message":"' . str_repeat('x', 1000) . '"}}', 'error' => '', 'timed_out' => false];
check('a long provider message is cut', mb_strlen($http->postJson('https://api.openai.com/v1/responses', [], $auth)['error']) < 400, true);

$answer = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Operation timed out after 180000 milliseconds', 'timed_out' => true];
$r = $http->postJson('https://api.openai.com/v1/responses', [], $auth);
check('a timeout', [$r['kind'], $r['error']], [AiHttp::TIMEOUT, 'The provider did not answer within 180 seconds.']);
$answer = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Could not resolve host', 'timed_out' => false];
check('no connection', $http->postJson('https://api.openai.com/v1/responses', [], $auth)['kind'], AiHttp::NETWORK);
check('retryable kinds', array_map(AiHttp::isRetryable(...), [AiHttp::RATE_LIMIT, AiHttp::TIMEOUT, AiHttp::SERVER, AiHttp::NETWORK, AiHttp::NO_CREDIT, AiHttp::REFUSED, AiHttp::AUTH, AiHttp::BAD_REQUEST]), [true, true, true, true, false, false, false, false]);

// ---- how long to wait
$answer = ['status' => 429, 'headers' => ['Retry-After' => '20'], 'body' => $cases['OpenAI rate limit'][1], 'error' => '', 'timed_out' => false];
check('Retry-After in seconds', $http->postJson('https://api.openai.com/v1/responses', [], $auth)['retry_after'], 20);
check('Retry-After as a date', AiHttp::retryAfter(['retry-after' => gmdate('D, d M Y H:i:s', 1000060) . ' GMT'], 1000000), 60);
check('OpenAI reset durations, the longest', AiHttp::retryAfter(['x-ratelimit-reset-requests' => '1s', 'x-ratelimit-reset-tokens' => '6m0s']), 360);
check('nothing said', AiHttp::retryAfter([]), null);
foreach (['120ms' => 1, '1.5s' => 2, '2m' => 120, '1h2m3s' => 3723, '0s' => 0, 'soon' => null, '' => null] as $text => $seconds) {
    check('duration ' . ($text === '' ? '(empty)' : $text), AiHttp::duration((string)$text), $seconds);
}

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
