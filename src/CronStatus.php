<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the System screen says about the cron job a site should have (`php src/cron-backup.php`, once a day): the line to add, whether
 * this server can start due work after a visit instead, when the cron job was last seen (it records the time each time it runs), and the
 * health check that follows from those. Without a cron job GnozioCMS still works: due work starts after a visit (PHP-FPM, LiteSpeed)
 * or when someone opens the admin; the cron job is what makes it happen on time on a quiet site.
 */
final class CronStatus
{
    /** Where the last run of the cron script is kept (system settings). */
    public const META_KEY = 'cron_last_seen';

    /** A daily job not seen for this long (36 hours) has missed a day. */
    public const STALE_AFTER = 129600;

    /** The recommended time: every day at 03:00. */
    public const SCHEDULE = '0 3 * * *';

    /** The function that lets a response end before the work after it starts, when this server has one. */
    public static function finishFunction(): ?string
    {
        foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $function) {
            if (function_exists($function)) {
                return $function;
            }
        }
        return null;
    }

    /**
     * @param string $lastSeen when the cron script last ran (ISO date), or empty
     * @param bool $backupsOn whether scheduled backups are switched on (the work that is waiting for the cron job)
     * @param string|null $finishFunction what finishFunction() says (a parameter for tests)
     * @return array{line: string, command: string, schedule: string, after_visit: bool, sapi: string, last_seen: string, state: string, check: array{label: string, value: string, status: string}}
     */
    public static function info(string $basePath, string $lastSeen, bool $backupsOn, ?int $now = null, ?string $finishFunction = null): array
    {
        $now ??= time();
        $afterVisit = ($finishFunction ?? self::finishFunction()) !== null;
        $seen = $lastSeen !== '' ? (int)strtotime($lastSeen) : 0;
        $command = 'php ' . escapeshellarg($basePath . '/src/cron-backup.php');
        $state = $seen <= 0 ? 'never' : (($now - $seen) > self::STALE_AFTER ? 'stale' : 'fresh');

        if ($state === 'fresh') {
            $check = ['value' => 'running, last ' . date('Y-m-d H:i', $seen), 'status' => 'ok'];
        } elseif ($state === 'stale') {
            $check = ['value' => 'stopped? last ran ' . date('Y-m-d H:i', $seen), 'status' => $backupsOn ? 'warning' : 'ok'];
        } elseif ($afterVisit) {
            $check = ['value' => 'not set up (a visit starts due work)', 'status' => 'ok'];
        } else {
            // Nothing starts due work after a visit here: it waits for someone to open the admin.
            $check = ['value' => 'not set up (due work waits for the admin)', 'status' => $backupsOn ? 'warning' : 'ok'];
        }

        return [
            'line' => self::SCHEDULE . ' ' . $command,
            'command' => $command,
            'schedule' => self::SCHEDULE,
            'after_visit' => $afterVisit,
            'sapi' => PHP_SAPI,
            'last_seen' => $seen > 0 ? date('c', $seen) : '',
            'state' => $state,
            'check' => ['label' => 'Cron job'] + $check,
        ];
    }
}
