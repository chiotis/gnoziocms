<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * Counts what anonymous visitors make the site do (an email sent, an order placed) by an action and a subject (an address, an
 * email, a cart), and says when a subject has done an action as often as it may within a window.
 *
 * Subjects are kept as hashes: the table says how often something happened, not to whom. An empty subject (an address that
 * cannot be told, say) is never counted and never limited. Rows older than a day are cleared now and then.
 */
final class ActionLimit
{
    private const KEEP_SECONDS = 86400;

    /** @param \Closure(): int|null $clock the time, for tests */
    public function __construct(private SystemDatabase $database, private ?\Closure $clock = null)
    {
    }

    /** Whether the subject has done the action $max times within the last $window seconds. */
    public function reached(string $action, string $subject, int $max, int $window): bool
    {
        if ($subject === '' || !$this->database->isAvailable()) {
            return false;
        }
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM action_limits WHERE action = :action AND subject = :subject AND at > :since');
        $stmt->execute(['action' => $action, 'subject' => $this->hash($subject), 'since' => $this->now() - $window]);
        return (int)$stmt->fetchColumn() >= $max;
    }

    /** Counts one more of the action for each subject given. */
    public function record(string $action, string ...$subjects): void
    {
        $this->recordFor($action, '', ...$subjects);
    }

    /** The same, with what it was done to (an order's id, say), for refs(). */
    public function recordFor(string $action, string $ref, string ...$subjects): void
    {
        if (!$this->database->isAvailable()) {
            return;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO action_limits (action, subject, ref, at) VALUES (:action, :subject, :ref, :at)');
        foreach (array_unique($subjects) as $subject) {
            if ($subject !== '') {
                $stmt->execute(['action' => $action, 'subject' => $this->hash($subject), 'ref' => $ref, 'at' => $this->now()]);
            }
        }
        if (random_int(1, 50) === 1) {
            $this->pdo()->prepare('DELETE FROM action_limits WHERE at < :cutoff')->execute(['cutoff' => $this->now() - self::KEEP_SECONDS]);
        }
    }

    /**
     * What the subject did the action to within the window (recordFor()), newest first: the caller can tell which still count.
     *
     * @return list<string>
     */
    public function refs(string $action, string $subject, int $window): array
    {
        if ($subject === '' || !$this->database->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare("SELECT DISTINCT ref FROM action_limits WHERE action = :action AND subject = :subject AND at > :since AND ref <> '' ORDER BY at DESC");
        $stmt->execute(['action' => $action, 'subject' => $this->hash($subject), 'since' => $this->now() - $window]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Counts the action when none of the subjects has reached its limit, and says whether it did.
     *
     * @param array<string, int> $limits subject => the most within the window
     */
    public function take(string $action, array $limits, int $window): bool
    {
        foreach ($limits as $subject => $max) {
            if ($this->reached($action, (string)$subject, $max, $window)) {
                return false;
            }
        }
        $this->record($action, ...array_map('strval', array_keys($limits)));
        return true;
    }

    private function hash(string $subject): string
    {
        return hash('sha256', mb_strtolower(trim($subject)));
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
