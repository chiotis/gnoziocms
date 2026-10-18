<?php
/*
 * The report a site sends to Omni (docs/omni-protocol.md): its shape, the values it takes from the site, and what it never holds.
 *   php tests/unit/omni-report.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\OmniReport;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$now = 1791882000; // 2026-10-13T09:00:00Z
$settings = [
    'title' => 'Doxiadis',
    'backup' => ['auto' => ['enabled' => true, 'schedule' => 'weekly'], 'remote' => ['enabled' => true, 'keep_local' => false, 'access_key' => 'AKIA-SECRET', 'secret_key' => 'very-secret']],
    'analytics' => ['mode' => 'custom', 'tag_id' => 'G-ABC123', 'head_code' => '<script>track()</script>'],
    'mail' => ['smtp' => ['password' => 'mail-secret']],
];
$summary = ['used' => 300, 'limit' => 1000, 'disk_free' => 5000, 'percent_of_limit' => 30, 'level' => 'ok', 'full' => false, 'label' => '300 B / 1000 B'];
$measurement = ['parts' => ['uploads' => 200, 'content' => 40, 'system' => 60], 'measured_at' => $now - 3600];
$runs = [
    ['created_at' => '2026-10-13T03:00:40+00:00', 'status' => 'warning', 'size_bytes' => 4096, 'message' => 'Remote upload failed.'],
    ['created_at' => '2026-10-12T03:00:40+00:00', 'status' => 'failed', 'size_bytes' => 0, 'message' => 'Disk full.'],
    ['created_at' => '2026-10-11T03:00:40+00:00', 'status' => 'success', 'size_bytes' => 4000, 'message' => ''],
    ['created_at' => '2026-09-01T03:00:40+00:00', 'status' => 'failed', 'size_bytes' => 0, 'message' => 'Old.'],
];
$cron = ['state' => 'fresh', 'last_seen' => '2026-10-13T05:00:05+02:00', 'after_visit' => true, 'command' => "php '/home/u/site/src/cron-backup.php'"];
$update = ['checked_at' => '2026-10-13T08:12:00+00:00', 'source' => 'https://example.com/VERSION', 'source_status' => 'ok', 'latest_version' => '0.2.0', 'has_update' => true];
$checks = [
    ['label' => 'PHP', 'value' => '8.3.12', 'status' => 'ok'],
    ['label' => 'Email provider', 'value' => 'not configured', 'status' => 'warning'],
];
$asked = null;
$make = function () use (&$settings, &$summary, &$measurement, &$runs, &$cron, &$update, &$checks, &$asked, $now): OmniReport {
    return new OmniReport(
        fn() => $settings,
        fn() => ['name' => 'Doxiadis', 'url' => 'https://doxiadis.com/', 'admin_url' => 'https://doxiadis.com/admin'],
        fn() => ['version' => '0.1.100', 'commit' => 'e39dc33'],
        fn() => ['summary' => $summary, 'measurement' => $measurement],
        function (int $limit) use (&$runs, &$asked) { $asked = $limit; return $runs; },
        fn() => $cron,
        fn() => $update,
        fn() => ['checks' => $checks, 'extensions' => [['name' => 'zip', 'purpose' => 'Backups', 'loaded' => true, 'status' => 'ok']], 'environment' => ['PHP' => '8.3.12 (fpm-fcgi)', 'HTTPS' => 'yes']],
        fn() => $now
    );
};
$r = $make()->build();

check('the sections of protocol v1, in order', array_keys($r), ['protocol', 'sent_at', 'site', 'faros', 'storage', 'backups', 'analytics', 'updates', 'system']);
check('protocol 1, sent now in UTC', [$r['protocol'], $r['sent_at']], [1, '2026-10-13T09:00:00Z']);
check('the site and its admin link', $r['site'], ['name' => 'Doxiadis', 'url' => 'https://doxiadis.com/', 'admin_url' => 'https://doxiadis.com/admin']);
check('the version and commit', $r['faros'], ['version' => '0.1.100', 'commit' => 'e39dc33']);

check('storage in bytes, with the parts as numbers', $r['storage'], ['used' => 300, 'limit' => 1000, 'disk_free' => 5000, 'percent_of_limit' => 30, 'level' => 'ok', 'full' => false, 'parts' => ['uploads' => 200, 'content' => 40, 'system' => 60], 'measured_at' => '2026-10-13T08:00:00Z']);
$summary = ['used' => 300, 'limit' => 0, 'disk_free' => 0, 'percent_of_limit' => null, 'level' => 'ok', 'full' => false];
$s = $make()->build()['storage'];
check('without a limit: limit 0 and no percentage; a disk the host hides is unknown', [$s['limit'], $s['percent_of_limit'], $s['disk_free']], [0, null, null]);

$b = $r['backups'];
check('the schedule and remote copy, from the settings', [$b['scheduled'], $b['schedule'], $b['remote'], $b['keep_local']], [true, 'weekly', true, false]);
check('the last run, whatever its outcome', $b['last'], ['at' => '2026-10-13T03:00:40Z', 'status' => 'warning', 'size' => 4096, 'message' => 'Remote upload failed.']);
check('the last success is the newest run that succeeded', $b['last_success_at'], '2026-10-11T03:00:40Z');
check('failures are counted over the last 7 days only', $b['failed_last_7_days'], 1);
check('enough runs are asked for', $asked, 100);
check('the cron job, its time in UTC, without the command', $b['cron'], ['state' => 'fresh', 'last_seen' => '2026-10-13T03:00:05Z', 'after_visit' => true]);
$settings['backup'] = ['auto' => ['enabled' => false, 'schedule' => 'weekly'], 'remote' => ['enabled' => false, 'keep_local' => false]];
$runs = [];
$b = $make()->build()['backups'];
check('scheduled backups off: no schedule', [$b['scheduled'], $b['schedule']], [false, null]);
check('no remote storage: every archive stays on the server', [$b['remote'], $b['keep_local']], [false, true]);
check('never backed up: no last run and no success', [$b['last'], $b['last_success_at'], $b['failed_last_7_days']], [null, null, 0]);

check('analytics: on, and which kind', $r['analytics'], ['enabled' => true, 'mode' => 'custom']);
check('GnozioCMS analytics', OmniReport::analytics(['analytics' => ['mode' => 'platform']]), ['enabled' => true, 'mode' => 'platform']);
check('no tracking, or a mode that is not known, is off', [OmniReport::analytics([]), OmniReport::analytics(['analytics' => ['mode' => 'spy']])], [['enabled' => false, 'mode' => 'off'], ['enabled' => false, 'mode' => 'off']]);

check('updates from the cached check', $r['updates'], ['current' => '0.1.100', 'latest' => '0.2.0', 'has_update' => true, 'checked_at' => '2026-10-13T08:12:00Z', 'source_status' => 'ok']);
$update = null;
check('never checked: only the version is known', $make()->build()['updates'], ['current' => '0.1.100', 'latest' => null, 'has_update' => null, 'checked_at' => null, 'source_status' => null]);

$sys = $r['system'];
check('the health verdict is worked out from the checks', $sys['summary'], ['label' => 'Warnings', 'status' => 'warning', 'detail' => '1 check to review']);
check('the checks as they are', $sys['checks'], $checks);
check('extensions without their purpose', $sys['extensions'], [['name' => 'zip', 'loaded' => true, 'status' => 'ok']]);
check('the environment table', $sys['environment'], ['PHP' => '8.3.12 (fpm-fcgi)', 'HTTPS' => 'yes']);

$json = $make()->json();
check('the JSON reads back as the report', json_decode($json, true)['site']['admin_url'] ?? null, 'https://doxiadis.com/admin');
check('slashes are not escaped', str_contains($json, 'https://doxiadis.com/admin'), true);
check('the pretty form is for the admin preview', [str_contains($make()->json(true), "\n    "), str_contains($json, "\n")], [true, false]);
foreach (['AKIA-SECRET', 'very-secret', 'G-ABC123', 'track()', 'mail-secret', 'cron-backup.php'] as $secret) {
    check('never in the report: ' . $secret, str_contains($json, $secret), false);
}

check('utc: a date with an offset', OmniReport::utc('2026-10-13T05:00:00+02:00'), '2026-10-13T03:00:00Z');
check('utc: a Unix time', OmniReport::utc(1791882000), '2026-10-13T09:00:00Z');
check('utc: nothing, zero or a word is null', [OmniReport::utc(''), OmniReport::utc(null), OmniReport::utc(0), OmniReport::utc('whenever')], [null, null, null, null]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
