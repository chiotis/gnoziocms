<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * Background work kept in the system database (`jobs`): queued with a kind, a payload and a priority, then worked one at a time by
 * whoever calls work() with a time budget (the cron script, the end of a public response, an admin screen that polls). A job is
 * claimed under a lock that runs out after its kind's lease, so a worker that dies leaves it for the next one, not stuck. Retries
 * wait longer each time (or as long as the handler says), up to the job's attempts.
 *
 * Handlers are registered per kind by the code that owns the work; a job whose kind has no handler in this request stays queued.
 * A handler returns its result (anything JSON can hold) or throws JobFailure; any other exception fails the job with a generic
 * sentence and its details go to the PHP log only.
 */
final class JobRunner
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const DEFAULT_LEASE = 300;
    /** Waits between attempts when the handler names none: 1, 5, 15 minutes, then 30. */
    private const BACKOFF = [60, 300, 900, 1800];

    /** @var array<string, array{handler: \Closure(array<string, mixed>, array<string, mixed>): mixed, lease: int}> */
    private array $handlers = [];
    private string $worker;

    /** @param (\Closure(): int)|null $clock Unix time (tests move it) */
    public function __construct(private SystemDatabase $db, private ?\Closure $clock = null)
    {
        $this->worker = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
    }

    /**
     * @param \Closure(array<string, mixed>, array<string, mixed>): mixed $handler gets the payload and the job (id, attempts,
     *   deadline: the microtime by which the worker hopes to be done)
     * @param int $lease seconds a claim holds before another worker may take the job over
     */
    public function register(string $kind, \Closure $handler, int $lease = self::DEFAULT_LEASE): void
    {
        $this->handlers[$kind] = ['handler' => $handler, 'lease' => max(30, $lease)];
    }

    /**
     * Queues a job; with a unique key, a job with the same key that is still queued or running is returned instead of a new one.
     * Higher priority is worked first; equal priority, oldest first. Returns the job's id, or 0 when the database is unavailable.
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $kind, array $payload = [], int $priority = 0, ?string $uniqueKey = null, int $maxAttempts = 3, int $delay = 0): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        if ($uniqueKey !== null && ($existing = $this->live($pdo, $uniqueKey)) !== 0) {
            return $existing;
        }
        $now = $this->now();
        try {
            $pdo->prepare('INSERT INTO jobs (kind, payload, priority, status, max_attempts, run_after, unique_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$kind, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $priority, self::QUEUED, max(1, $maxAttempts), $now + max(0, $delay), $uniqueKey, $now]);
        } catch (\PDOException $e) {
            // Another request queued the same key a moment ago: that one is the job.
            if ($uniqueKey !== null && ($existing = $this->live($pdo, $uniqueKey)) !== 0) {
                return $existing;
            }
            throw $e;
        }
        return (int)$pdo->lastInsertId();
    }

    /**
     * Works due jobs of the registered kinds (or of the ones given), one at a time, until none is left, $max are done, or the
     * deadline (microtime) has passed; a job already started is always finished. Returns how many were worked.
     * @param string[]|null $kinds
     */
    public function work(float $deadline, ?array $kinds = null, int $max = PHP_INT_MAX): int
    {
        $kinds = array_values(array_intersect($kinds ?? array_keys($this->handlers), array_keys($this->handlers)));
        $worked = 0;
        while ($kinds !== [] && $worked < $max && microtime(true) < $deadline) {
            $job = $this->claim($kinds);
            if ($job === null) {
                break;
            }
            $this->run($job, $deadline);
            $worked++;
        }
        return $worked;
    }

    /** @return array<string, mixed>|null the job with payload and result decoded */
    public function get(int $id): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::decode($row) : null;
    }

    /** Stops a job that has not finished: a queued one never runs; a running one keeps running but its result is not recorded. */
    public function cancel(int $id): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE jobs SET status = ?, finished_at = ?, locked_by = \'\', locked_until = 0 WHERE id = ? AND status IN (?, ?)');
        $stmt->execute([self::CANCELLED, $this->now(), $id, self::QUEUED, self::RUNNING]);
        return $stmt->rowCount() === 1;
    }

    /** Puts a failed or cancelled job back in the queue with fresh attempts. */
    public function retry(int $id): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        $job = $this->get($id);
        if ($job === null || !in_array($job['status'], [self::FAILED, self::CANCELLED], true)
            || ($job['unique_key'] !== null && $this->live($pdo, (string)$job['unique_key']) !== 0)) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE jobs SET status = ?, attempts = 0, run_after = ?, error = \'\', finished_at = 0 WHERE id = ?');
        $stmt->execute([self::QUEUED, $this->now(), $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Jobs waiting or running, per kind, or of one kind; due only counts the ones that may run now (not those waiting to retry).
     * @return int
     */
    public function pending(?string $kind = null, bool $dueOnly = false): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) FROM jobs WHERE status IN (?, ?)';
        $args = [self::QUEUED, self::RUNNING];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $args[] = $kind;
        }
        if ($dueOnly) {
            $sql .= ' AND (status = ? AND run_after <= ? OR status = ? AND locked_until < ?)';
            array_push($args, self::QUEUED, $this->now(), self::RUNNING, $this->now());
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Jobs of a kind, newest first, for screens and progress lines.
     * @param string[] $statuses empty for all
     * @return array<int, array<string, mixed>>
     */
    public function list(string $kind, array $statuses = [], int $limit = 50): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $sql = 'SELECT * FROM jobs WHERE kind = ?';
        $args = [$kind];
        if ($statuses !== []) {
            $sql .= ' AND status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $args = array_merge($args, array_values($statuses));
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute($args);
        return array_map(self::decode(...), $stmt->fetchAll());
    }

    /** Removes finished jobs (done, failed, cancelled) older than the given days. Returns how many. */
    public function purge(int $days): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        $stmt = $pdo->prepare('DELETE FROM jobs WHERE status IN (?, ?, ?) AND finished_at > 0 AND finished_at < ?');
        $stmt->execute([self::DONE, self::FAILED, self::CANCELLED, $this->now() - max(0, $days) * 86400]);
        return $stmt->rowCount();
    }

    /** @param string[] $kinds @return array<string, mixed>|null */
    private function claim(array $kinds): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        $now = $this->now();
        $in = implode(', ', array_fill(0, count($kinds), '?'));
        // One statement picks and locks, so two workers can never take the same job.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pick = $pdo->prepare("SELECT * FROM jobs WHERE kind IN ($in) AND (status = ? AND run_after <= ? OR status = ? AND locked_until < ?)
                ORDER BY priority DESC, id ASC LIMIT 1");
            $pick->execute(array_merge($kinds, [self::QUEUED, $now, self::RUNNING, $now]));
            $row = $pick->fetch();
            if (!is_array($row)) {
                $pdo->exec('COMMIT');
                return null;
            }
            $job = self::decode($row);
            // A job whose worker died with its last attempt is not started again.
            if ($job['status'] === self::RUNNING && $job['attempts'] >= $job['max_attempts']) {
                $pdo->prepare('UPDATE jobs SET status = ?, error = ?, finished_at = ?, locked_by = \'\', locked_until = 0 WHERE id = ?')
                    ->execute([self::FAILED, 'It stopped without finishing, ' . $job['attempts'] . ' times.', $now, $job['id']]);
                $pdo->exec('COMMIT');
                return $this->claim($kinds);
            }
            $lease = $this->handlers[$job['kind']]['lease'];
            $pdo->prepare('UPDATE jobs SET status = ?, attempts = attempts + 1, locked_by = ?, locked_until = ?, started_at = ? WHERE id = ?')
                ->execute([self::RUNNING, $this->worker, $now + $lease, $now, $job['id']]);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        $job['status'] = self::RUNNING;
        $job['attempts']++;
        return $job;
    }

    /** @param array<string, mixed> $job */
    private function run(array $job, float $deadline): void
    {
        $handler = $this->handlers[$job['kind']]['handler'];
        try {
            $result = $handler($job['payload'], ['id' => $job['id'], 'attempts' => $job['attempts'], 'deadline' => $deadline]);
            $this->finish($job, self::DONE, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null', '');
        } catch (JobFailure $failure) {
            if ($failure->retry && $job['attempts'] < $job['max_attempts']) {
                $wait = max($failure->retryAfter, self::BACKOFF[min($job['attempts'] - 1, count(self::BACKOFF) - 1)]);
                $this->requeue($job, $failure->getMessage(), $wait);
            } else {
                $this->finish($job, self::FAILED, '', $failure->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('GnozioCMS job ' . $job['id'] . ' (' . $job['kind'] . ') failed: ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->finish($job, self::FAILED, '', 'Something went wrong while working on it; the details are in the PHP log.');
        }
    }

    /** @param array<string, mixed> $job */
    private function finish(array $job, string $status, string $result, string $error): void
    {
        // Only the worker that holds the lock records the outcome: a cancelled job, or one taken over after its lease ran out, is left as it is.
        $this->pdo()?->prepare('UPDATE jobs SET status = ?, result = ?, error = ?, finished_at = ?, locked_by = \'\', locked_until = 0 WHERE id = ? AND status = ? AND locked_by = ?')
            ->execute([$status, $result, $error, $this->now(), $job['id'], self::RUNNING, $this->worker]);
    }

    /** @param array<string, mixed> $job */
    private function requeue(array $job, string $error, int $wait): void
    {
        $this->pdo()?->prepare('UPDATE jobs SET status = ?, error = ?, run_after = ?, locked_by = \'\', locked_until = 0 WHERE id = ? AND status = ? AND locked_by = ?')
            ->execute([self::QUEUED, $error, $this->now() + $wait, $job['id'], self::RUNNING, $this->worker]);
    }

    private function live(PDO $pdo, string $uniqueKey): int
    {
        $stmt = $pdo->prepare('SELECT id FROM jobs WHERE unique_key = ? AND status IN (?, ?) LIMIT 1');
        $stmt->execute([$uniqueKey, self::QUEUED, self::RUNNING]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function decode(array $row): array
    {
        foreach (['id', 'priority', 'attempts', 'max_attempts', 'run_after', 'locked_until', 'created_at', 'started_at', 'finished_at'] as $int) {
            $row[$int] = (int)$row[$int];
        }
        $payload = json_decode((string)$row['payload'], true);
        $row['payload'] = is_array($payload) ? $payload : [];
        $row['result'] = $row['result'] === '' ? null : json_decode((string)$row['result'], true);
        return $row;
    }

    private function pdo(): ?PDO
    {
        return $this->db->isAvailable() ? $this->db->connection() : null;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
