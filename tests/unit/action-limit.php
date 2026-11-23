<?php
/*
 * ActionLimit (audit S4): what anonymous visitors make the site do, counted by action and subject within a window; a subject
 * that reached its number is refused until the oldest count leaves the window; subjects kept as hashes; an empty subject is
 * never limited.
 *   php tests/unit/action-limit.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ActionLimit, SystemDatabase};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }

$dir = sys_get_temp_dir() . '/actionlimit' . getmypid();
mkdir("$dir/storage", 0775, true);
register_shutdown_function(function () use ($dir) { @exec('rm -rf ' . escapeshellarg($dir)); });
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$now = 1_800_000_000;
$limits = new ActionLimit($db, function () use (&$now): int { return $now; });

check('nothing counted: not reached', $limits->reached('shop.mail', 'to:maria@example.test', 3, 3600), false);
$taken = [];
for ($i = 0; $i < 4; $i++) {
    $taken[] = $limits->take('shop.mail', ['to:maria@example.test' => 3, 'ip:203.0.113.5' => 6], 3600);
    $now += 60;
}
check('three taken, the fourth refused', $taken, [true, true, true, false]);
check('a refused one is not counted', (int)$db->connection()->query("SELECT COUNT(*) FROM action_limits WHERE action = 'shop.mail'")->fetchColumn(), 6);
check('reached for the email, not for the address (6 allowed)', [$limits->reached('shop.mail', 'to:maria@example.test', 3, 3600), $limits->reached('shop.mail', 'ip:203.0.113.5', 6, 3600)], [true, false]);
check('the same email in another case is the same subject', $limits->reached('shop.mail', 'to:MARIA@example.test ', 3, 3600), true);
check('another action, or another email, is not limited by it', [$limits->reached('shop.order', 'to:maria@example.test', 3, 3600), $limits->reached('shop.mail', 'to:eleni@example.test', 3, 3600)], [false, false]);
$now += 3600 - 4 * 60 + 1;
check('free again once the oldest count leaves the window', $limits->take('shop.mail', ['to:maria@example.test' => 3], 3600), true);
check('and refused again at the number', $limits->take('shop.mail', ['to:maria@example.test' => 3], 3600), false);
check('subjects are kept as hashes', (int)$db->connection()->query("SELECT COUNT(*) FROM action_limits WHERE subject LIKE '%maria%' OR subject LIKE '%203.0%'")->fetchColumn(), 0);
$limits->record('shop.order', '', '');
check('an empty subject is never counted, and never limited', [(int)$db->connection()->query("SELECT COUNT(*) FROM action_limits WHERE action = 'shop.order'")->fetchColumn(), $limits->reached('shop.order', '', 0, 3600)], [0, false]);
$limits->recordFor('shop.unpaid', '41', 'ip:203.0.113.5', 'cart:abc');
$now += 10;
$limits->recordFor('shop.unpaid', '42', 'ip:203.0.113.5');
check('what was done to, newest first, by subject and window', [$limits->refs('shop.unpaid', 'ip:203.0.113.5', 3600), $limits->refs('shop.unpaid', 'cart:abc', 3600), $limits->refs('shop.unpaid', 'ip:203.0.113.5', 5)], [['42', '41'], ['41'], ['42']]);
check('without the database nothing is limited', (new ActionLimit(new SystemDatabase('/nonexistent/' . getmypid())))->reached('shop.mail', 'x', 0, 60), false);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
