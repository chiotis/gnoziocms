<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * How long the logs keep what they hold, personal data among it (audit S21): the activity log 180 days, the email log 90 and the
 * payment providers' events 180, unless Settings (`retention:` in settings.yaml: `activity_days`, `email_days`, `gateway_days`,
 * 7 to 3650) say otherwise; MCP clients that never made a connection go after a week. Once a day, from the cron job's work or
 * after a visit.
 */
final class LogRetention
{
    public const META_KEY = 'log_retention_at';
    public const DEFAULTS = ['activity_days' => 180, 'email_days' => 90, 'gateway_days' => 180];

    public function __construct(private SystemDatabase $database, private SystemMetaRepository $meta, private ?McpStore $mcp = null)
    {
    }

    /** @param array<string, mixed> $settings the site's settings @return array{activity_days: int, email_days: int, gateway_days: int} */
    public static function days(array $settings): array
    {
        $given = is_array($settings['retention'] ?? null) ? $settings['retention'] : [];
        $days = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $given[$key] ?? null;
            $days[$key] = is_numeric($value) && (int)$value >= 7 && (int)$value <= 3650 ? (int)$value : $default;
        }
        return $days;
    }

    public function isDue(int $now = 0): bool
    {
        return (int)$this->meta->get(self::META_KEY) < ($now > 0 ? $now : time()) - 86400;
    }

    /**
     * Clears what is older than the settings allow, when a day has passed since the last time (or always, with `$force`).
     *
     * @param array<string, mixed> $settings
     * @return array{activity: int, email: int, gateway: int, mcp_clients: int}|null null when it was not due
     */
    public function run(array $settings, bool $force = false, int $now = 0): ?array
    {
        $now = $now > 0 ? $now : time();
        if (!$this->database->isAvailable() || (!$force && !$this->isDue($now))) {
            return null;
        }
        $this->meta->set(self::META_KEY, (string)$now);
        $days = self::days($settings);
        $pdo = $this->database->connection();
        $iso = static fn(int $d): string => gmdate('Y-m-d\TH:i:s', $now - $d * 86400);
        $removed = [
            'activity' => self::delete($pdo, 'DELETE FROM activity_logs WHERE created_at < :cutoff', $iso($days['activity_days'])),
            'email' => self::delete($pdo, 'DELETE FROM email_logs WHERE created_at < :cutoff', $iso($days['email_days'])),
            'gateway' => self::delete($pdo, 'DELETE FROM shop_gateway_events WHERE at < :cutoff', $now - $days['gateway_days'] * 86400),
            'mcp_clients' => 0,
        ];
        try {
            $removed['mcp_clients'] = $this->mcp?->removeUnusedClients() ?? 0;
        } catch (\Throwable) {
        }
        return $removed;
    }

    private static function delete(PDO $pdo, string $sql, string|int $cutoff): int
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['cutoff' => $cutoff]);
            return $stmt->rowCount();
        } catch (\Throwable) {
            // A table that is not there yet (no shop) holds nothing to clear.
            return 0;
        }
    }
}
