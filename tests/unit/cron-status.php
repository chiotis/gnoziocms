<?php
/*
 * What the System screen says about the cron job: the line to add, the states (never seen, running, stopped), and the health check.
 *   php tests/unit/cron-status.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\CronStatus as C;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$now = 1760270400;
$iso = fn(int $t) => date('c', $t);
$info = fn(string $seen, bool $backups, ?string $finish) => C::info('/home/u/site', $seen, $backups, $now, $finish);

$i = $info('', true, null);
check('the line to add: every day at 03:00, the script of this site', [$i['line'], $i['schedule']], ["0 3 * * * php '/home/u/site/src/cron-backup.php'", '0 3 * * *']);
check('a path with a space is quoted', C::info('/home/my site', '', false, $now, null)['command'], "php '/home/my site/src/cron-backup.php'");
check('never seen, on a server that cannot start work after a visit: a warning while backups are on', [$i['state'], $i['check']], ['never', ['label' => 'Cron job', 'value' => 'not set up (due work waits for the admin)', 'status' => 'warning']]);
check('and only a note while they are off', $info('', false, null)['check']['status'], 'ok');
check('never seen, on a server where a visit starts it: fine', $info('', true, 'fastcgi_finish_request')['check'], ['label' => 'Cron job', 'value' => 'not set up (a visit starts due work)', 'status' => 'ok']);
check('the server says whether a visit starts due work', [$info('', true, 'fastcgi_finish_request')['after_visit'], $i['after_visit']], [true, false]);
$fresh = $info($iso($now - 3 * 3600), true, null);
check('seen a few hours ago: running, ok', [$fresh['state'], $fresh['check']['status'], str_starts_with($fresh['check']['value'], 'running, last ')], ['fresh', 'ok', true]);
check('seen 35 hours ago is still running (a daily job a little late)', $info($iso($now - 35 * 3600), true, null)['state'], 'fresh');
$stale = $info($iso($now - 37 * 3600), true, null);
check('seen 37 hours ago: a day was missed, a warning while backups are on', [$stale['state'], $stale['check']['status'], str_starts_with($stale['check']['value'], 'stopped? last ran ')], ['stale', 'warning', true]);
check('and not while they are off', $info($iso($now - 37 * 3600), false, null)['check']['status'], 'ok');
check('the time it was seen is given as it was', $fresh['last_seen'], $iso($now - 3 * 3600));
check('a time that is not a date counts as never seen', $info('whenever', true, null)['state'], 'never');
check('the function that ends a response early is one of two, or none', in_array(C::finishFunction(), [null, 'fastcgi_finish_request', 'litespeed_finish_request'], true), true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
