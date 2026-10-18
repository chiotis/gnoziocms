<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The notifications the site raises by itself for the admin: a new version is available, and a system check that
 * needs attention. Each is created once (a new version once per version), whatever the screen asked. Nothing here
 * may get in the way of the screen, so every failure is swallowed.
 */
final class AdminNotices
{
    /**
     * @param \Closure(): UpdateService $updates
     * @param \Closure(): array<int, array<string, mixed>> $systemChecks the checks of the system status, each with a label, a value and a status
     */
    public function __construct(private NotificationRepository $notifications, private \Closure $updates, private \Closure $systemChecks)
    {
    }

    /** @param array<string, mixed> $status the status of the update check */
    public function syncUpdate(array $status): void
    {
        if (!($status['has_update'] ?? false)) {
            return;
        }
        try {
            $latest = (string)($status['latest_version'] ?? '');
            // Title carries the version, so each release notifies once even after being read.
            $this->notifications->createIfMissing([
                'type' => 'update.available',
                'title' => 'GnozioCMS ' . $latest . ' is available',
                'body' => 'You are running ' . (string)($status['current_version'] ?? '') . '. Review the release notes and create a backup before updating.',
                'severity' => 'info',
                'target_url' => '/admin/updates',
                'context' => [
                    'current_version' => (string)($status['current_version'] ?? ''),
                    'latest_version' => $latest,
                ],
            ], true);
        } catch (\Throwable) {
        }
    }

    /**
     * Tells the admin how an install ended. Each attempt is its own notice, and one that failed or could not be checked is a warning.
     *
     * @param array{status: string, message: string, from: string, to: string} $result
     */
    public function installResult(array $result): void
    {
        try {
            $installed = in_array($result['status'], ['installed', 'unverified'], true);
            $this->notifications->create([
                'type' => $installed ? 'update.installed' : 'update.install_failed',
                'title' => match ($result['status']) {
                    'installed' => 'GnozioCMS ' . $result['to'] . ' is installed',
                    'unverified' => 'GnozioCMS ' . $result['to'] . ' is installed, but was not checked',
                    'rolled_back' => 'Update to ' . $result['to'] . ' was undone',
                    default => 'Update to ' . $result['to'] . ' did not happen',
                },
                'body' => $result['message'],
                'severity' => $result['status'] === 'installed' ? 'success' : ($result['status'] === 'unverified' ? 'warning' : 'error'),
                'target_url' => '/admin/updates',
                'context' => ['from' => $result['from'], 'to' => $result['to'], 'status' => $result['status']],
            ]);
        } catch (\Throwable) {
        }
    }

    /** Raises what is due: the update notice for those who manage updates, and a notice for each system check that is not fine. */
    public function sync(bool $seesUpdates): void
    {
        if ($seesUpdates) {
            try {
                // Refreshes at most every 12 hours (hourly while the source is unreachable).
                $this->syncUpdate(($this->updates)()->status());
            } catch (\Throwable) {
            }
        }
        try {
            foreach (($this->systemChecks)() as $check) {
                $status = (string)($check['status'] ?? 'ok');
                if (!in_array($status, ['warning', 'error'], true)) {
                    continue;
                }
                $label = (string)($check['label'] ?? 'System check');
                $this->notifications->createIfMissing([
                    'type' => 'system.' . strtolower(str_replace(' ', '_', $label)),
                    'title' => 'System check: ' . $label,
                    'body' => (string)($check['value'] ?? ''),
                    'severity' => $status,
                    'target_url' => '/admin',
                    'context' => [
                        'check' => $check,
                    ],
                ], true);
            }
        } catch (\Throwable) {
        }
    }
}
