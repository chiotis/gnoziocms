<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Settings screen does with a submitted form: saving the site settings, noting when the storage or upload
 * limits changed, saving the theme settings the form carries, and the three actions a button of the form asks for
 * (send a test email, test the remote backup, make a backup now). Where the screen goes next is returned. The
 * things that live in the running site (the settings it holds, the theme settings, sending mail) are handed in as
 * closures, so the class does not know how they are kept.
 */
final class SettingsAdmin
{
    public const TABS = ['basics', 'apis', 'smtp', 'auth', 'backup', 'updates', 'limits'];

    /**
     * @param \Closure(): SiteLimits $limits
     * @param \Closure(): BackupManager $backups
     * @param \Closure(): array<string, mixed> $settings the settings the site is running with
     * @param \Closure(array<string, mixed>): void $applySettings makes the site run with these settings
     * @param \Closure(?array<string, mixed>): array{ok?: bool, message?: string} $saveTheme saves the theme settings the form carries (null when it carries none) and makes the site use what is stored
     * @param \Closure(string, string, string, array<string, string>): bool $sendMail sends one email: to, subject, body, headers
     * @param \Closure(string): void $markBackupRun stores the time of the last backup
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     * @param (\Closure(): array{ok: bool, message: string})|null $testYouTube asks YouTube whether the saved key works
     */
    public function __construct(
        private SiteSettings $store,
        private MediaLibrary $media,
        private \Closure $limits,
        private \Closure $backups,
        private \Closure $settings,
        private \Closure $applySettings,
        private \Closure $saveTheme,
        private \Closure $sendMail,
        private \Closure $markBackupRun,
        private \Closure $log,
        private ?\Closure $testYouTube = null
    ) {
    }

    /** A tab name from the address or the form; the first tab when it is not one. */
    public static function tab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        return in_array($tab, self::TABS, true) ? $tab : 'basics';
    }

    /** Measures the storage again, when the person may; where to go next is returned. */
    public function recalculateStorage(bool $allowed): string
    {
        if (!$allowed) {
            return '/admin/settings?tab=limits';
        }
        ($this->limits)()->measure();
        ($this->log)('limits.storage_recalculate', 'info', 'settings', 'storage', 'Storage use measured again.', []);
        return '/admin/settings?tab=limits&storage=measured';
    }

    /**
     * Saves the submitted form, then does what a button of it asks for.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $limitFields the storage and upload limits read from the form, which only some people may change
     */
    public function save(array $post, array $limitFields, string $activeTab): string
    {
        $activeTab = self::tab((string)($post['active_tab'] ?? $activeTab));
        $raw = $this->store->raw('site_settings', $this->store->defaults());
        $form = SiteSettings::formFromPost($post) + $limitFields;
        $limits = ($this->limits)();
        $limitBefore = (int)((($this->settings)())['limits']['storage_mb'] ?? 1024);
        $uploadBefore = [$limits->uploadLimitMb(), $this->media->allowedGroups()];
        if (!$this->store->save($raw, $form)) {
            return '/admin/settings?' . http_build_query([
                'tab' => $activeTab,
                'settings_error' => 'Settings could not be saved because the SQLite system database is unavailable.',
            ]);
        }
        $settings = $this->store->load();
        ($this->applySettings)($settings);
        $limitAfter = (int)($settings['limits']['storage_mb'] ?? 1024);
        $this->media->restrictTo(is_array($settings['limits']['upload_types'] ?? null) ? $settings['limits']['upload_types'] : []);
        $uploadAfter = [$limits->uploadLimitMb(), $this->media->allowedGroups()];
        if ($uploadAfter !== $uploadBefore) {
            ($this->log)('limits.upload', 'warning', 'settings', 'upload', 'Upload limits changed.', ['from_mb' => $uploadBefore[0], 'to_mb' => $uploadAfter[0], 'kinds' => $uploadAfter[1]]);
        }
        if ($limitAfter !== $limitBefore) {
            ($this->log)('limits.storage', 'warning', 'settings', 'storage_mb', 'Storage limit changed.', ['from_mb' => $limitBefore, 'to_mb' => $limitAfter]);
        }
        $themeSave = ($this->saveTheme)(is_array($post['theme_settings'] ?? null) ? $post['theme_settings'] : null);
        if (($themeSave['ok'] ?? false) !== true) {
            return '/admin/theme?theme=fail&theme_msg=' . urlencode((string)($themeSave['message'] ?? 'Theme settings could not be saved.'));
        }
        ($this->log)('settings.update', 'info', 'settings', $activeTab, 'Settings updated.', ['tab' => $activeTab]);

        if (isset($post['send_test'])) {
            return $this->testEmail(trim((string)($post['test_email_to'] ?? '')), $settings);
        }
        if (isset($post['youtube_test']) && $this->testYouTube !== null) {
            $result = ($this->testYouTube)();
            ($this->log)($result['ok'] ? 'apis.youtube_test_success' : 'apis.youtube_test_failure', $result['ok'] ? 'info' : 'warning', 'settings', 'youtube', 'YouTube key tested: ' . $result['message'], []);
            return '/admin/settings?' . http_build_query(['saved' => '1', 'tab' => 'apis', 'youtube' => $result['ok'] ? 'ok' : 'fail', 'youtube_msg' => $result['message']]);
        }
        if (isset($post['test_remote_backup'])) {
            return $this->testRemoteBackup($settings);
        }
        if (isset($post['create_backup'])) {
            return $this->backupNow();
        }
        return '/admin/settings?saved=1&tab=' . urlencode($activeTab);
    }

    /**
     * What the screen shows.
     *
     * @param array<string, mixed> $get
     * @param array<int, array<string, mixed>> $snapshots the backups kept
     * @return array<string, mixed>
     */
    public function screen(array $get, bool $seesLimits, array $snapshots): array
    {
        $parsed = $this->store->parse($this->store->raw('site_settings', $this->store->defaults()));
        $serverCap = SiteLimits::serverUploadCap();
        return [
            'saved' => isset($get['saved']),
            'storage_measured' => (string)($get['storage'] ?? '') === 'measured',
            'test_status' => (string)($get['test'] ?? ''),
            'admin_section' => 'settings',
            'settings_form' => $this->store->formValues($parsed),
            'storage' => $seesLimits ? ($this->limits)()->summary() : [],
            'upload_groups' => MediaLibrary::UPLOAD_GROUPS,
            'server_upload_mb' => $serverCap > 0 ? (int)floor($serverCap / 1048576) : 0,
            'backup_snapshots' => $snapshots,
            'backup_status' => (string)($get['backup'] ?? ''),
            'backup_message' => trim((string)($get['backup_msg'] ?? '')),
            'youtube_status' => in_array((string)($get['youtube'] ?? ''), ['ok', 'fail'], true) ? (string)$get['youtube'] : '',
            'youtube_message' => trim((string)($get['youtube_msg'] ?? '')),
            'settings_error' => trim((string)($get['settings_error'] ?? '')),
            'active_tab' => self::tab((string)($get['tab'] ?? 'basics')),
        ];
    }

    /** @param array<string, mixed> $settings the settings just saved */
    private function testEmail(string $to, array $settings): string
    {
        if ($to === '') {
            return '/admin/settings?saved=1&tab=smtp&test=missing';
        }
        $title = trim((string)($settings['title'] ?? 'GnozioCMS'));
        $notifications = $settings['forms']['notifications'] ?? [];
        $from = trim((string)($notifications['from'] ?? ''));
        $fromName = trim((string)($notifications['from_name'] ?? ''));
        if ($from === '') {
            $from = 'noreply@localhost';
        }
        $ok = ($this->sendMail)($to, ($title !== '' ? $title : 'GnozioCMS') . ' - email test', 'This is a test email from GnozioCMS.', [
            'From' => $fromName !== '' ? $fromName . ' <' . $from . '>' : $from,
        ]);
        ($this->log)($ok ? 'email.test_success' : 'email.test_failure', $ok ? 'info' : 'error', 'email', $to, $ok ? 'Test email sent.' : 'Test email failed.', ['recipient' => $to]);
        return '/admin/settings?saved=1&tab=smtp&test=' . ($ok ? 'ok' : 'fail');
    }

    /** @param array<string, mixed> $settings */
    private function testRemoteBackup(array $settings): string
    {
        $result = ($this->backups)()->testRemote();
        $ok = ($result['ok'] ?? false) === true;
        ($this->log)($ok ? 'backup.remote_test_success' : 'backup.remote_test_failure', $ok ? 'info' : 'error', 'backup', 'remote_storage', (string)($result['message'] ?? 'Remote backup test completed.'), [
            'provider' => (string)($settings['backup']['remote']['provider'] ?? 'custom'),
            'bucket' => (string)($settings['backup']['remote']['bucket'] ?? ''),
        ]);
        return '/admin/settings?' . http_build_query([
            'saved' => '1',
            'tab' => 'backup',
            'backup' => $ok ? 'ok' : 'fail',
            'backup_msg' => (string)($result['message'] ?? ''),
        ]);
    }

    private function backupNow(): string
    {
        $manager = ($this->backups)();
        $result = $manager->createSnapshot();
        if (($result['ok'] ?? false) === true) {
            ($this->markBackupRun)(date('c'));
        }
        $manager->recordRun($result);
        ($this->log)(($result['ok'] ?? false) ? 'backup.create_success' : 'backup.create_failure', BackupManager::logLevel($result), 'backup', (string)($result['filename'] ?? ''), (string)($result['message'] ?? 'Backup action completed.'), ['result' => $result]);
        $manager->notify($result, false);
        return '/admin/settings?' . http_build_query([
            'saved' => '1',
            'tab' => 'backup',
            'backup' => BackupManager::queryStatus($result),
            'backup_msg' => (string)($result['message'] ?? ''),
        ]);
    }
}
