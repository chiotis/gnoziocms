<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Backups screens do: verifying, creating (a full one or the database alone), deleting and fetching from remote storage an archive, the
 * restore (only after a safety snapshot of what is there now, and only of the areas chosen), the verified backup made
 * before an update, and the data of the screens. The screens, the permission checks, and the redirects are the caller's.
 */
final class BackupAdmin
{
    /**
     * @param callable(): array<string, mixed> $settings the site settings, read each time
     * @param callable(string): void $storeLastRun records the time of a backup in the settings
     * @param callable(): void $afterRestore brings the running request up to date with restored files: settings, menus, taxonomies, the content index
     * @param callable(string, string, ?string, ?string, string, array<string, mixed>, ?array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context, actor
     */
    public function __construct(
        private BackupService $backups,
        private BackupManager $manager,
        private BackupRunRepository $runs,
        private NotificationRepository $notifications,
        private SystemMetaRepository $meta,
        private $settings,
        private $storeLastRun,
        private $afterRestore,
        private $log
    ) {
    }

    /**
     * Carries out a submitted action other than the restore.
     *
     * @param array<string, mixed> $post
     * @return string|null where to send the browser, or null when the form asked for nothing this knows
     */
    public function apply(string $action, array $post): ?string
    {
        $filename = $this->backups->sanitizeFilename((string)($post['filename'] ?? ''));
        if ($action === 'verify') {
            $v = $this->backups->verify($filename);
            $this->log($v['ok'] ? 'backup.verify_success' : 'backup.verify_failure', $v['ok'] ? 'info' : 'error', 'backup', $filename, $v['message'], ['checked' => $v['checked'], 'has_manifest' => $v['has_manifest'], 'errors' => $v['errors']]);
            return '/admin/backups?' . http_build_query([
                'backup' => $v['ok'] ? ($v['has_manifest'] ? 'ok' : 'warn') : 'fail',
                'backup_msg' => $filename . ': ' . $v['message'],
            ]);
        }
        if ($action === 'create_full' || $action === 'create_database') {
            $full = $action === 'create_full';
            $result = $full ? $this->manager->createSnapshot() : $this->manager->createDatabaseSnapshot();
            if ($full && ($result['ok'] ?? false) === true) {
                ($this->storeLastRun)(date('c'));
            }
            $this->manager->recordRun($result);
            $ok = ($result['ok'] ?? false);
            $this->log(
                $full ? ($ok ? 'backup.create_success' : 'backup.create_failure') : ($ok ? 'backup.database_success' : 'backup.database_failure'),
                BackupManager::logLevel($result),
                'backup',
                (string)($result['filename'] ?? ''),
                (string)($result['message'] ?? ($full ? 'Backup action completed.' : 'Database backup action completed.')),
                ['result' => $result, 'source' => 'backups_module']
            );
            $this->manager->notify($result, false);
            return '/admin/backups?' . http_build_query(['backup' => BackupManager::queryStatus($result), 'backup_msg' => (string)($result['message'] ?? '')]);
        }
        if ($action === 'fetch_remote') {
            $result = $this->manager->fetchRemote($filename);
            $ok = $result['ok'];
            $this->log($ok ? 'backup.fetch_success' : 'backup.fetch_failure', $ok ? 'info' : 'error', 'backup', $filename, $result['message'], ['filename' => $filename]);
            return '/admin/backups?' . http_build_query(['backup' => $ok ? 'ok' : 'fail', 'backup_msg' => $result['message']]);
        }
        if ($action === 'delete') {
            $result = $this->backups->delete($filename);
            $ok = ($result['ok'] ?? false);
            $this->log($ok ? 'backup.delete_success' : 'backup.delete_failure', $ok ? 'warning' : 'error', 'backup', $filename, (string)($result['message'] ?? 'Backup delete action completed.'), ['filename' => $filename]);
            return '/admin/backups?' . http_build_query(['deleted' => $ok ? 'ok' : 'fail', 'backup_msg' => (string)($result['message'] ?? '')]);
        }
        return null;
    }

    /**
     * The archives in remote storage, read from the bucket now.
     *
     * @return array{ok: bool, message: string, backups: array<int, array<string, mixed>>}
     */
    public function remoteBackups(): array
    {
        return $this->manager->remoteBackups();
    }

    /**
     * The archives, their runs, and the schedule and remote storage, for the list.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $settings = ($this->settings)();
        $snapshots = $this->backups->list();
        $schedule = is_array($settings['backup']['auto'] ?? null) ? $settings['backup']['auto'] : [];
        $remote = is_array($settings['backup']['remote'] ?? null) ? $settings['backup']['remote'] : [];
        return [
            'backup_snapshots' => $snapshots,
            'backup_runs' => $this->runs->recent(20),
            'backup_total' => count($snapshots),
            'backup_storage_human' => Format::bytes((int)array_sum(array_map(static fn(array $snapshot): int => (int)($snapshot['size'] ?? 0), $snapshots))),
            'last_backup' => $snapshots[0] ?? null,
            'backup_schedule' => [
                'enabled' => Format::isTruthy($schedule['enabled'] ?? false),
                'frequency' => (string)($schedule['schedule'] ?? 'daily'),
                'last_run' => (string)($schedule['last_run'] ?? ''),
                'keep' => max(1, (int)($settings['backup']['local']['keep'] ?? 20)),
            ],
            'backup_remote' => [
                'enabled' => Format::isTruthy($remote['enabled'] ?? false),
                'provider' => (string)($remote['provider'] ?? 'custom'),
                'bucket' => (string)($remote['bucket'] ?? ''),
                'prefix' => (string)($remote['prefix'] ?? ''),
                'keep' => (int)($remote['keep'] ?? 20),
            ],
        ];
    }

    /**
     * What the restore screen shows for an archive: its verification, the areas it holds and which are ticked.
     *
     * @param string[] $selected the areas to tick (none means all it has)
     * @return array{filename: string, snapshot: array<string, mixed>|null, verification: array<string, mixed>, scopes: array<int, array<string, mixed>>}|null null when there is no such archive
     */
    public function restoreScreen(string $filename, array $selected = []): ?array
    {
        $filename = $this->backups->sanitizeFilename($filename);
        if ($this->backups->pathFor($filename) === null) {
            return null;
        }
        $verification = $this->backups->verify($filename);
        $scopes = [];
        foreach ($this->backups->restoreScopes() as $key => $scope) {
            $count = (int)($verification['areas'][$key] ?? 0);
            $scopes[] = $scope + [
                'key' => $key,
                'count' => $count,
                'available' => $count > 0,
                'checked' => $count > 0 && ($selected === [] || in_array($key, $selected, true)),
            ];
        }
        $snapshot = null;
        foreach ($this->backups->list() as $item) {
            if ($item['filename'] === $filename) {
                $snapshot = $item;
                break;
            }
        }
        return ['filename' => $filename, 'snapshot' => $snapshot, 'verification' => $verification, 'scopes' => $scopes];
    }

    /**
     * Restores the areas chosen from an archive. A safety snapshot of the current state comes first and is required:
     * without it there is no way back.
     *
     * @param array<string, mixed> $post filename, scopes[], confirm_filename, ack_unverified
     * @param array<string, mixed>|null $actor who is doing it (the session may not survive a restored database)
     * @return array{location: ?string, error: string, filename: string, selected: string[]} `location` when done (or the archive is missing); otherwise the screen is shown again with the error
     */
    public function restore(array $post, ?array $actor): array
    {
        $filename = $this->backups->sanitizeFilename((string)($post['filename'] ?? ''));
        $selected = array_values(array_intersect(array_map('strval', is_array($post['scopes'] ?? null) ? $post['scopes'] : []), array_keys($this->backups->restoreScopes())));
        $again = static fn(string $error, array $keep = []): array => ['location' => null, 'error' => $error, 'filename' => $filename, 'selected' => $keep];

        if ($filename === '' || $this->backups->pathFor($filename) === null) {
            return ['location' => '/admin/backups?' . http_build_query(['backup' => 'fail', 'backup_msg' => 'Backup file not found.']), 'error' => '', 'filename' => $filename, 'selected' => []];
        }
        if ($selected === []) {
            return $again('Select at least one area to restore.');
        }
        if (trim((string)($post['confirm_filename'] ?? '')) !== $filename) {
            return $again('Type the archive name exactly as shown to confirm the restore.', $selected);
        }
        $verification = $this->backups->verify($filename);
        if (!$verification['ok']) {
            return $again('The archive failed verification, so nothing was restored.', $selected);
        }
        if (!$verification['has_manifest'] && empty($post['ack_unverified'])) {
            return $again('This archive has no checksum manifest. Tick the acknowledgement to restore it anyway.', $selected);
        }

        $safety = $this->manager->createSnapshot('pre-restore', false, false);
        if (($safety['ok'] ?? false) !== true) {
            $this->manager->recordRun($safety);
            return $again('The safety snapshot failed, so the restore was not started: ' . (string)($safety['message'] ?? ''), $selected);
        }

        $result = $this->backups->restore($filename, $selected);
        // The system database may have been swapped: bring the request up to date and write the history into the active one.
        ($this->afterRestore)($result['ok']);
        $this->manager->recordRun($safety);
        $safetyName = (string)($safety['filename'] ?? '');
        $this->log($result['ok'] ? 'backup.restore_success' : 'backup.restore_failure', $result['ok'] ? 'warning' : 'error', 'backup', $filename, $result['message'], [
            'scopes' => $selected,
            'restored' => $result['restored'],
            'skipped' => $result['skipped'],
            'safety_snapshot' => $safetyName,
            'previous_dir' => (string)($result['previous_dir'] ?? ''),
        ], $actor);
        try {
            $this->notifications->create([
                'type' => $result['ok'] ? 'backup.restored' : 'backup.restore_failed',
                'title' => $result['ok'] ? 'Backup restored' : 'Backup restore failed',
                'body' => $result['message'] . ' Safety snapshot: ' . $safetyName . '.',
                'severity' => $result['ok'] ? 'warning' : 'error',
                'target_url' => '/admin/backups',
            ]);
        } catch (\Throwable) {
            // Notifications must never block the restore response.
        }

        $message = $result['message'] . ' Safety snapshot: ' . $safetyName . '.';
        if (!$result['ok']) {
            return $again($message, $selected);
        }
        if ($result['skipped'] !== []) {
            $message .= ' Not in archive: ' . implode(', ', $result['skipped']) . '.';
        }
        if (in_array('database', $result['restored'], true)) {
            $message .= ' If your account does not exist in the restored database you will be signed out.';
        }
        return ['location' => '/admin/backups?' . http_build_query(['backup' => 'ok', 'backup_msg' => $message]), 'error' => '', 'filename' => $filename, 'selected' => $selected];
    }

    /**
     * Makes a backup and checks it, to be kept as the one taken just before an update.
     *
     * @return array{ok: bool, message: string, filename: string, result: array<string, mixed>}
     */
    public function preUpdateBackup(string $currentVersion): array
    {
        $result = $this->manager->createSnapshot('pre-update');
        $this->manager->recordRun($result);
        $made = ($result['ok'] ?? false) === true;
        $verification = $made ? $this->backups->verify((string)$result['filename']) : ['ok' => false, 'message' => (string)($result['message'] ?? 'Backup failed.')];
        if ($made) {
            $this->meta->setJson('pre_update_backup', [
                'filename' => (string)$result['filename'],
                'created_at' => gmdate('c'),
                'verified' => $verification['ok'] === true,
                'version' => $currentVersion,
            ]);
        }
        $this->log($verification['ok'] ? 'updates.pre_backup_success' : 'updates.pre_backup_failure', $verification['ok'] ? 'info' : 'error', 'backup', (string)($result['filename'] ?? ''), (string)$verification['message'], ['result' => $result]);
        $this->manager->notify($result, false);
        return [
            'ok' => (bool)$verification['ok'],
            'message' => $verification['ok'] ? 'Verified pre-update backup created: ' . (string)$result['filename'] : (string)$verification['message'],
            'filename' => (string)($result['filename'] ?? ''),
            'result' => $result,
        ];
    }

    /** The backup taken before the update, when there is one and its file is still there. @return array{filename: string, created_at: string, verified: bool, version: string}|null */
    public function preUpdateStatus(): ?array
    {
        $meta = $this->meta->getJson('pre_update_backup');
        if (!is_array($meta) || ($meta['filename'] ?? '') === '' || $this->backups->pathFor((string)$meta['filename']) === null) {
            return null;
        }
        return [
            'filename' => (string)$meta['filename'],
            'created_at' => (string)($meta['created_at'] ?? ''),
            'verified' => ($meta['verified'] ?? false) === true,
            'version' => (string)($meta['version'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $context @param array<string, mixed>|null $actor */
    private function log(string $action, string $level, ?string $type, ?string $id, string $message, array $context, ?array $actor = null): void
    {
        ($this->log)($action, $level, $type, $id, $message, $context, $actor);
    }
}
