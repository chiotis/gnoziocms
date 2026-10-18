<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Updates screen shows and does: the current and latest version, the release notes (from the project when
 * it can be reached, else the local ones), the checks to pass before updating, the verified backup made just before,
 * and the two actions (check for an update, make that backup). It only reads and checks: updating itself is not done
 * from here. Permissions are the caller's.
 */
final class UpdateAdmin
{
    /**
     * @param \Closure(): UpdateService $updates
     * @param \Closure(): UpdateInstaller $installer
     * @param \Closure(): BackupAdmin $backupAdmin
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(
        private \Closure $updates,
        private AdminNotices $notices,
        private \Closure $backupAdmin,
        private \Closure $installer,
        private SystemMetaRepository $meta,
        private BackupService $backups,
        private string $contentDir,
        private string $basePath,
        private \Closure $log
    ) {
    }

    /** Looks for an update right now (the screen otherwise reads what was kept) and records that it did. */
    public function check(): string
    {
        $updates = ($this->updates)();
        $status = $updates->status(true);
        $updates->releaseManifest(true);
        $this->notices->syncUpdate($status);
        ($this->log)('updates.check', 'info', 'updates', 'local', 'Read-only update check completed.', [
            'current_version' => $status['current_version'],
            'remote_version' => $status['remote_version'],
            'source_status' => $status['source_status'],
            'source' => $status['source'],
        ]);
        return '/admin/updates?checked=1';
    }

    /**
     * Installs the latest release from its package, with every safeguard, and says how it ended.
     *
     * @return string where to go next
     */
    public function install(): string
    {
        $updates = ($this->updates)();
        $manifest = $updates->releaseManifest(true);
        $current = $updates->currentVersion();
        if ($manifest === null) {
            return $this->installLocation('fail', 'The package of the latest release could not be found. Check again in a few minutes.');
        }
        // What is needed after the code has been replaced is loaded now, from the code that is running, so that nothing is read half from the old version and half from the new.
        foreach ([AdminNotices::class, NotificationRepository::class, SystemMetaRepository::class, ActivityLogRepository::class, MaintenanceMode::class, UpdateNetwork::class] as $class) {
            class_exists($class);
        }
        $result = ($this->installer)()->install($manifest, $current, self::backupReady(($this->backupAdmin)()->preUpdateStatus(), $current));
        $this->meta->setJson('last_install', $result + ['at' => gmdate('c')]);
        $this->notices->installResult($result);
        ($this->log)('updates.install_' . $result['status'], $result['status'] === 'installed' ? 'info' : ($result['status'] === 'unverified' ? 'warning' : 'error'), 'updates', $result['to'], $result['message'], $result);
        return $this->installLocation($result['status'], $result['message']);
    }

    /** Puts back the version before the last install, from what it kept. @return string where to go next */
    public function rollback(): string
    {
        $current = ($this->updates)()->currentVersion();
        $result = ($this->installer)()->rollback($current, $current);
        $this->meta->setJson('last_install', $result + ['at' => gmdate('c')]);
        $this->notices->installResult(['to' => $result['from'], 'status' => $result['status'] === 'rolled_back' ? 'rolled_back' : 'failed'] + $result);
        ($this->log)('updates.rollback_' . $result['status'], $result['status'] === 'rolled_back' ? 'warning' : 'error', 'updates', $current, $result['message'], $result);
        return $this->installLocation($result['status'], $result['message']);
    }

    private function installLocation(string $status, string $message): string
    {
        return '/admin/updates?' . http_build_query(['install' => $status, 'install_msg' => $message]);
    }

    /** Makes the verified backup to have before updating; where to go next is returned. */
    public function backupFirst(): string
    {
        $made = ($this->backupAdmin)()->preUpdateBackup(($this->updates)()->currentVersion());
        return '/admin/updates?' . http_build_query([
            'pre_backup' => $made['ok'] ? 'ok' : 'fail',
            'pre_backup_msg' => $made['message'],
        ]);
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function screen(array $get): array
    {
        $updates = ($this->updates)();
        $source = $updates->sourceConfig();
        $status = $updates->status();
        $this->notices->syncUpdate($status);
        $changelog = $updates->readChangelogEntries();
        $changelogSource = 'local';
        $remote = $updates->fetchRemoteChangelogEntries((string)$source['changelog_url']);
        if (!empty($remote)) {
            $changelog = $remote;
            $changelogSource = 'remote';
        }
        $last = $changelog[0] ?? null;
        $backups = $this->backups->list();
        $preBackup = ($this->backupAdmin)()->preUpdateStatus();
        $current = $status['current_version'];
        $manifest = $updates->releaseManifest();
        $available = $manifest !== null && version_compare($manifest['version'], $current, '>');
        $installer = ($this->installer)();
        $checks = $available ? $installer->preflight($manifest, $current) : [];
        $backupReady = self::backupReady($preBackup, $current);
        $blockers = array_values(array_map(static fn(array $c): string => $c['message'], array_filter($checks, static fn(array $c): bool => !$c['ok'])));
        if ($available && $manifest['requires_backup'] && !$backupReady) {
            $blockers[] = 'Create the verified backup first (below).';
        }
        $last = $this->meta->getJson('last_install');

        return [
            'title' => 'Updates',
            'admin_section' => 'updates',
            'current_type' => 'pages',
            'current_version' => $status['current_version'],
            'current_commit' => $updates->currentGitCommit(),
            'latest_version' => $status['latest_version'] !== '' ? $status['latest_version'] : (string)($last['version'] ?? $status['current_version']),
            'remote_version' => $status['remote_version'],
            'has_update' => $status['has_update'],
            'update_checked_at' => $status['checked_at'],
            'update_source' => (string)$source['version_url'],
            'update_source_status' => $status['source_status'],
            'update_repository' => (string)$source['repository'],
            'update_branch' => (string)$source['branch'],
            'update_changelog_url' => (string)$source['changelog_url'],
            'update_package_url' => (string)$source['package_url'],
            'update_channel' => $updates->channel(),
            'checked' => isset($get['checked']),
            'changelog_entries' => $changelog,
            'changelog_source' => $changelogSource,
            'update_guide' => $updates->readUpdateGuideSummary(),
            'latest_backup' => $backups[0] ?? null,
            'backup_total' => count($backups),
            'preflight_checks' => $this->preflight($source, $preBackup),
            'pre_update_backup' => $preBackup,
            'pre_backup_status' => (string)($get['pre_backup'] ?? ''),
            'pre_backup_message' => trim((string)($get['pre_backup_msg'] ?? '')),
            'release' => $manifest,
            'install_available' => $available,
            'install_checks' => $checks,
            'install_blockers' => $blockers,
            'install_ready' => $available && $blockers === [],
            'last_install' => is_array($last) ? $last : null,
            'rollback_target' => $installer->rollbackTarget($current),
            'install_status' => (string)($get['install'] ?? ''),
            'install_message' => trim((string)($get['install_msg'] ?? '')),
        ];
    }

    /**
     * What has to be in order before updating.
     *
     * @param array<string, mixed> $source the update source configuration
     * @param array<string, mixed>|null $preBackup the verified backup made before updating, if any
     * @return array<int, array{label: string, value: string, status: string}>
     */
    public function preflight(array $source, ?array $preBackup): array
    {
        $backupDir = $this->backups->directory();
        $preBackupReady = self::backupReady($preBackup, ($this->updates)()->currentVersion());
        $storage = is_writable($this->basePath . '/storage');
        $content = is_writable($this->contentDir);
        $backupReady = is_dir($backupDir) && is_writable($backupDir);
        $configured = (string)$source['version_url'] !== '';
        return [
            [
                'label' => 'Verified pre-update backup',
                'value' => $preBackupReady ? 'ready' : ($preBackup === null ? 'missing' : ($preBackup['verified'] ? 'older than 24h' : 'not verified')),
                'status' => $preBackupReady ? 'ok' : 'warning',
            ],
            [
                'label' => 'PHP compatibility',
                'value' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, '8.5.0', '>=') ? 'ok' : 'error',
            ],
            ['label' => 'Content writable', 'value' => $content ? 'yes' : 'no', 'status' => $content ? 'ok' : 'error'],
            ['label' => 'Storage writable', 'value' => $storage ? 'yes' : 'no', 'status' => $storage ? 'ok' : 'error'],
            ['label' => 'Backup directory', 'value' => $backupReady ? 'ready' : 'not ready', 'status' => $backupReady ? 'ok' : 'warning'],
            ['label' => 'Update source', 'value' => $configured ? 'configured' : 'not configured', 'status' => $configured ? 'ok' : 'warning'],
        ];
    }

    /** Whether the backup taken for an update is verified, from the last day, and of the version being replaced. @param array<string, mixed>|null $backup */
    private static function backupReady(?array $backup, string $currentVersion): bool
    {
        return $backup !== null && $backup['verified'] && time() - (int)strtotime((string)$backup['created_at']) < 86400 && $backup['version'] === $currentVersion;
    }
}
