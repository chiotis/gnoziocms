<?php
/*
 * What the logs keep (audit S21, S23): the activity log 180 days, the email log 90, the payment providers' events 180 (or what
 * Settings say), once a day; MCP clients that never connected, after a week.
 *   php tests/unit/log-retention.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ActivityLogRepository, EmailLogRepository, LogRetention, McpStore, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/retention' . getmypid();
mkdir("$dir/storage/db", 0775, true);
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$pdo = $db->connection();
$meta = new SystemMetaRepository($db);
$now = time();
$activity = new ActivityLogRepository($db);
$emails = new EmailLogRepository($db);
foreach ([10, 179, 181, 400] as $days) {
    $activity->record(['action' => 'test', 'message' => "$days days", 'ip_address' => '203.0.113.9', 'created_at' => gmdate('c', $now - $days * 86400)]);
}
foreach ([10, 89, 91] as $days) {
    $emails->record(['recipient' => 'a@example.com', 'subject' => "$days", 'status' => 'sent', 'provider' => 'smtp', 'created_at' => gmdate('c', $now - $days * 86400)]);
}
foreach ([10, 200] as $i => $days) {
    $pdo->prepare('INSERT INTO shop_gateway_events (provider, event_key, kind, order_id, outcome, at) VALUES (?, ?, ?, NULL, ?, ?)')->execute(['viva', "k$i", 'webhook', '', $now - $days * 86400]);
}
$clock = $now;
$mcp = new McpStore($db, function () use (&$clock): int { return $clock; });
$clock = $now - 8 * 86400;
$unused = $mcp->registerClient('Old unused', ['https://a.test/cb'])['client_id'];
$used = $mcp->registerClient('Old used', ['https://b.test/cb'])['client_id'];
$mcp->createConnection('oauth', 'Old used', $used, 1, 1, 'content:read', 'https://site.test/mcp');
$clock = $now - 86400;
$recent = $mcp->registerClient('New', ['https://c.test/cb'])['client_id'];
$clock = $now;

$retention = new LogRetention($db, $meta, $mcp);
check('the days, defaults and what settings say (7 to 3650)', [LogRetention::days([]), LogRetention::days(['retention' => ['activity_days' => 30, 'email_days' => 3, 'gateway_days' => 'x']])], [['activity_days' => 180, 'email_days' => 90, 'gateway_days' => 180], ['activity_days' => 30, 'email_days' => 90, 'gateway_days' => 180]]);
check('due the first time', $retention->isDue(), true);
check('what is older is cleared: activity, email, provider events, MCP clients that never connected', $retention->run([]), ['activity' => 2, 'email' => 1, 'gateway' => 1, 'mcp_clients' => 1]);
check('the rest is kept', [array_column($activity->all(), 'message'), count($emails->all()), (int)$pdo->query('SELECT COUNT(*) FROM shop_gateway_events')->fetchColumn()], [['10 days', '179 days'], 2, 1]);
check('a client with a connection, and a new one, stay', [$mcp->client($unused), $mcp->client($used) !== null, $mcp->client($recent) !== null], [null, true, true]);
check('once a day', [$retention->isDue(), $retention->run([])], [false, null]);
check('a shorter setting clears more', $retention->run(['retention' => ['activity_days' => 7]], true)['activity'], 2);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
