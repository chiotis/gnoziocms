<?php
/*
 * Background jobs (JobRunner) with a clock the test moves: priority then age, only registered kinds, the result kept, retries after
 * a wait that grows (or the one the handler names) up to the attempts, a final failure not retried, any other exception failed with
 * a generic sentence, a unique key giving one live job, a lease that runs out so another worker takes over (and the first one's late
 * result is not recorded), a job whose worker died on its last attempt, cancel, retry, pending counts, the time budget, and purge.
 *   php tests/unit/job-runner.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{JobFailure, JobRunner, SystemDatabase};

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$dir = sys_get_temp_dir() . '/jobs' . getmypid();
mkdir("$dir/db", 0775, true);
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$db = new SystemDatabase($dir);
$db->initialize();
check('the database is ready', $db->isAvailable(), true);

$now = 1_000_000;
$clock = static function () use (&$now): int { return $now; };
$runner = new JobRunner($db, $clock);
$soon = static fn(): float => microtime(true) + 10;
$seen = [];
$runner->register('note', function (array $payload, array $job) use (&$seen): array {
    $seen[] = $payload['n'];
    return ['done' => $payload['n'], 'attempt' => $job['attempts']];
});

// ---- order and results
$low = $runner->enqueue('note', ['n' => 'low'], 0);
$high = $runner->enqueue('note', ['n' => 'high'], 10);
$low2 = $runner->enqueue('note', ['n' => 'low2'], 0);
$other = $runner->enqueue('unknown-kind', ['n' => 'x']);
check('ids are given', $low > 0 && $high > $low && $low2 > $high, true);
check('pending counts', [$runner->pending(), $runner->pending('note')], [4, 3]);
check('three worked, the unknown kind left alone', $runner->work($soon()), 3);
check('higher priority first, then oldest', $seen, ['high', 'low', 'low2']);
$job = $runner->get($high);
check('done with its result', [$job['status'], $job['result'], $job['attempts']], [JobRunner::DONE, ['done' => 'high', 'attempt' => 1], 1]);
check('a kind with no handler stays queued', $runner->get($other)['status'], JobRunner::QUEUED);

// ---- a delay and the most to work
$seen = [];
$later = $runner->enqueue('note', ['n' => 'later'], 0, null, 3, 60);
check('a delayed job waits', $runner->work($soon()), 0);
$now += 60;
$runner->enqueue('note', ['n' => 'second']);
check('at most one when asked', $runner->work($soon(), null, 1), 1);
check('the delayed one ran when due', $seen, ['later']);
$runner->work($soon());
check('a deadline already past works nothing', [$runner->enqueue('note', ['n' => 'late']) > 0, $runner->work(microtime(true) - 1)], [true, 0]);
$runner->work($soon());

// ---- retries
$calls = 0;
$runner->register('flaky', function (array $payload) use (&$calls): string {
    $calls++;
    if ($calls < 3) {
        throw JobFailure::later('The provider asked to slow down.', $payload['wait'] ?? 0);
    }
    return 'fine';
});
$flaky = $runner->enqueue('flaky', [], 0, null, 3);
$runner->work($soon());
$job = $runner->get($flaky);
check('a retryable failure goes back in the queue', [$job['status'], $job['attempts'], $job['error']], [JobRunner::QUEUED, 1, 'The provider asked to slow down.']);
check('first wait one minute', $job['run_after'], $now + 60);
check('not before its time', $runner->work($soon(), ['flaky']), 0);
$now += 60;
$runner->work($soon(), ['flaky']);
check('second wait five minutes', $runner->get($flaky)['run_after'], $now + 300);
check('due-only counts leave out waiting retries', [$runner->pending('flaky'), $runner->pending('flaky', true)], [1, 0]);
$now += 300;
$runner->work($soon(), ['flaky']);
$job = $runner->get($flaky);
check('the third attempt succeeds', [$job['status'], $job['result'], $job['attempts']], [JobRunner::DONE, 'fine', 3]);

$calls = 0;
$named = $runner->enqueue('flaky', ['wait' => 3600]);
$runner->work($soon(), ['flaky']);
check('the handler\'s longer wait wins', $runner->get($named)['run_after'], $now + 3600);
$runner->cancel($named);

$runner->register('always-busy', static function (): never { throw JobFailure::later('Busy.'); });
$busy = $runner->enqueue('always-busy', [], 0, null, 2);
$runner->work($soon(), ['always-busy']);
$now += 60;
$runner->work($soon(), ['always-busy']);
$job = $runner->get($busy);
check('out of attempts: failed', [$job['status'], $job['attempts'], $job['error']], [JobRunner::FAILED, 2, 'Busy.']);

$runner->register('refused', static function (): never { throw JobFailure::final('The provider refused the request under its safety rules.'); });
$refused = $runner->enqueue('refused');
$runner->work($soon(), ['refused']);
$job = $runner->get($refused);
check('a final failure is not retried', [$job['status'], $job['attempts']], [JobRunner::FAILED, 1]);

$runner->register('broken', static function (): never { throw new RuntimeException('secret detail at /var/www'); });
$broken = $runner->enqueue('broken');
$logFile = "$dir/php.log";
$previousLog = ini_set('error_log', $logFile);
$runner->work($soon(), ['broken']);
ini_set('error_log', (string)$previousLog);
$job = $runner->get($broken);
check('any other exception fails the job with a generic sentence', [$job['status'], str_contains($job['error'], 'secret'), str_contains($job['error'], 'PHP log')], [JobRunner::FAILED, false, true]);
check('its details go to the PHP log', str_contains((string)@file_get_contents($logFile), 'secret detail'), true);

// ---- retry and cancel
check('a failed job can be put back', $runner->retry($refused), true);
$job = $runner->get($refused);
check('back in the queue with fresh attempts', [$job['status'], $job['attempts'], $job['error']], [JobRunner::QUEUED, 0, '']);
check('a queued job cannot be retried', $runner->retry($refused), false);
check('a queued job can be cancelled', $runner->cancel($refused), true);
check('a cancelled job is not worked', [$runner->work($soon(), ['refused']), $runner->get($refused)['status']], [0, JobRunner::CANCELLED]);
check('a finished job cannot be cancelled', $runner->cancel($high), false);

// ---- unique keys
$a = $runner->enqueue('note', ['n' => 'pic'], 0, 'picture:posts/storm/en');
$b = $runner->enqueue('note', ['n' => 'pic again'], 0, 'picture:posts/storm/en');
check('one live job per key', $a, $b);
$seen = [];
$runner->work($soon(), ['note']);
check('worked once', $seen, ['pic']);
$c = $runner->enqueue('note', ['n' => 'pic new'], 0, 'picture:posts/storm/en');
check('a finished job frees its key', $c !== $a && $c > 0, true);
$runner->work($soon(), ['note']);

// ---- a lease that runs out
$first = new JobRunner($db, $clock);
$second = new JobRunner($db, $clock);
$stall = null;
$first->register('slow', function () use (&$stall, $second, &$now, $soon): string {
    // While this worker is still busy, its lease runs out and the second worker takes the job and finishes it.
    $now += 120;
    $stall = $second->work($soon(), ['slow']);
    return 'first';
}, 60);
$second->register('slow', static fn(): string => 'second', 60);
$slow = $first->enqueue('slow');
$first->work($soon(), ['slow']);
$job = $first->get($slow);
check('another worker takes a job whose lease ran out', $stall, 1);
check('the late worker\'s result is not recorded', [$job['status'], $job['result'], $job['attempts']], [JobRunner::DONE, 'second', 2]);

// ---- a worker that died on the last attempt
$dead = $runner->enqueue('note', ['n' => 'dead'], 0, null, 1);
$pdo = $db->connection();
$pdo->prepare("UPDATE jobs SET status = 'running', attempts = 1, locked_by = 'gone', locked_until = ? WHERE id = ?")->execute([$now - 1, $dead]);
$seen = [];
$runner->work($soon(), ['note']);
$job = $runner->get($dead);
check('not started again after its last attempt', [$seen, $job['status'], str_contains($job['error'], 'stopped without finishing')], [[], JobRunner::FAILED, true]);

// ---- lists and purge
check('list by kind, newest first', array_column($runner->list('note', [JobRunner::DONE], 2), 'id'), [$c, $a]);
$now += 31 * 86400;
$kept = $runner->enqueue('note', ['n' => 'fresh']);
$purged = $runner->purge(30);
check('purge removes old finished jobs only', [$purged > 5, $runner->get($kept) !== null, $runner->get($high)], [true, true, null]);

// ---- no database
$none = new JobRunner(new SystemDatabase("$dir/missing-and-never-initialised"), $clock);
check('without a database nothing breaks', [$none->enqueue('note'), $none->work($soon()), $none->pending(), $none->get(1)], [0, 0, 0, null]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
