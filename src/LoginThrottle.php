<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * Rate-limits password logins by client address and by username.
 *
 * The address is REMOTE_ADDR on purpose: X-Forwarded-For is client-controlled and would let
 * an attacker rotate addresses for free.
 */
final class LoginThrottle
{
    public const WINDOW_SECONDS = 900;
    public const MAX_FAILURES_PER_ADDRESS = 8;
    public const MAX_FAILURES_PER_USERNAME = 12;

    public function __construct(private SystemDatabase $database)
    {
    }

    /** @return array{blocked: bool, retry_after: int} */
    public function check(string $address, string $username): array
    {
        if (!$this->database->isAvailable()) {
            return ['blocked' => false, 'retry_after' => 0];
        }

        $since = gmdate('c', time() - self::WINDOW_SECONDS);
        $byAddress = $this->failuresSince('address', $address, $since);
        $byUsername = $username !== '' ? $this->failuresSince('username', $this->normalizeUsername($username), $since) : [];

        $blocking = [];
        if (count($byAddress) >= self::MAX_FAILURES_PER_ADDRESS) {
            $blocking[] = $byAddress[count($byAddress) - self::MAX_FAILURES_PER_ADDRESS];
        }
        if (count($byUsername) >= self::MAX_FAILURES_PER_USERNAME) {
            $blocking[] = $byUsername[count($byUsername) - self::MAX_FAILURES_PER_USERNAME];
        }
        if ($blocking === []) {
            return ['blocked' => false, 'retry_after' => 0];
        }

        // The block lifts when the oldest failure that still counts leaves the window.
        $oldestCounted = min(array_map('strtotime', $blocking));
        $retryAfter = max(1, ($oldestCounted + self::WINDOW_SECONDS) - time());
        return ['blocked' => true, 'retry_after' => $retryAfter];
    }

    public function recordFailure(string $address, string $username): void
    {
        $this->record($address, $username, false);
    }

    public function recordSuccess(string $address, string $username): void
    {
        $this->record($address, $username, true);
        if (!$this->database->isAvailable()) {
            return;
        }
        // A successful login clears that account's failure streak from this address.
        $stmt = $this->pdo()->prepare('DELETE FROM login_attempts WHERE succeeded = 0 AND address = :address AND username = :username');
        $stmt->execute(['address' => $address, 'username' => $this->normalizeUsername($username)]);
    }

    private function record(string $address, string $username, bool $succeeded): void
    {
        if (!$this->database->isAvailable()) {
            return;
        }
        $stmt = $this->pdo()->prepare(
            'INSERT INTO login_attempts (address, username, succeeded, created_at)
             VALUES (:address, :username, :succeeded, :created_at)'
        );
        $stmt->execute([
            'address' => $address,
            'username' => $this->normalizeUsername($username),
            'succeeded' => $succeeded ? 1 : 0,
            'created_at' => gmdate('c'),
        ]);

        if (random_int(1, 50) === 1) {
            $cleanup = $this->pdo()->prepare('DELETE FROM login_attempts WHERE created_at < :cutoff');
            $cleanup->execute(['cutoff' => gmdate('c', time() - 86400)]);
        }
    }

    /** @return string[] ISO timestamps, oldest first */
    private function failuresSince(string $column, string $value, string $since): array
    {
        $column = $column === 'username' ? 'username' : 'address';
        $stmt = $this->pdo()->prepare(
            'SELECT created_at FROM login_attempts
             WHERE succeeded = 0 AND ' . $column . ' = :value AND created_at >= :since
             ORDER BY created_at ASC'
        );
        $stmt->execute(['value' => $value, 'since' => $since]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
