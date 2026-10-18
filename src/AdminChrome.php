<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What every admin screen gets besides its own data: the notifications and how many are unread, whether an update is
 * available (read from what was kept, never from the network), the installed version, the warning when the default
 * password is still in use, and the storage level, with a ready-made email to the person who can raise the limit
 * when the storage is almost full. What a screen passes itself is never replaced: the caller merges these under it.
 */
final class AdminChrome
{
    /**
     * @param \Closure(): UpdateService $updates
     * @param \Closure(): SiteLimits $limits
     * @param \Closure(): AdminNotices $notices
     * @param \Closure(): array<string, mixed> $settings
     */
    public function __construct(
        private NotificationRepository $notifications,
        private UserRepository $users,
        private \Closure $updates,
        private \Closure $limits,
        private \Closure $notices,
        private \Closure $settings
    ) {
    }

    /**
     * @param array<string, mixed> $data what the screen already has
     * @param string $currentPath the address being shown, with its query, for the way back from a notification
     * @return array<string, mixed>
     */
    public function defaults(array $data, bool $seesNotifications, bool $seesUpdates, bool $defaultPassword, string $currentPath): array
    {
        $defaults = [];
        // System notices (updates, failed backups, security) are for those who manage the site.
        if (!$seesNotifications) {
            $defaults += ['admin_notifications' => [], 'admin_notification_unread_count' => 0];
        }
        $sync = $seesNotifications && (!isset($data['admin_notifications']) || !isset($data['admin_notification_unread_count']));
        if ($sync) {
            ($this->notices)()->sync($seesUpdates);
        }
        $updates = ($this->updates)();
        $cached = $updates->cachedStatus();
        $defaults['admin_version'] = $updates->currentVersion();
        $defaults['admin_update_available'] = $cached !== null && $cached['has_update'];
        $defaults['admin_update_latest'] = $cached['latest_version'] ?? '';
        $defaults['admin_default_password'] = $defaultPassword;
        if (!isset($data['admin_storage_summary'])) {
            $defaults['admin_storage_summary'] = ($this->limits)()->summary();
        }
        $summary = $defaults['admin_storage_summary'] ?? $data['admin_storage_summary'] ?? [];
        if (($summary['level'] ?? 'ok') === 'danger') {
            $defaults['admin_storage_contact'] = $this->storageContact($summary);
        }
        if ($sync) {
            $defaults['admin_notifications'] = $this->notifications->recent(6);
            $defaults['admin_notification_unread_count'] = $this->notifications->unreadCount();
            $defaults['admin_current_url'] = $currentPath;
        }
        return $defaults;
    }

    /**
     * Who to write to about the storage, with the email already drafted; null when no active super admin has an address.
     *
     * @param array<string, mixed> $summary the storage summary
     * @return array{name: string, email: string, href: string}|null
     */
    private function storageContact(array $summary): ?array
    {
        foreach ($this->users->all(['role' => 'superadmin', 'status' => 'active']) as $account) {
            if ((string)($account['email'] ?? '') === '') {
                continue;
            }
            $siteName = (string)((($this->settings)())['title'] ?? 'GnozioCMS');
            $email = (string)$account['email'];
            return [
                'name' => (string)($account['display_name'] ?: $account['username']),
                'email' => $email,
                'href' => 'mailto:' . $email
                    . '?subject=' . rawurlencode('Storage almost full: ' . $siteName)
                    . '&body=' . rawurlencode("Hello,\n\nThe storage of " . $siteName . ' is ' . ($summary['percent_of_limit'] ?? $summary['percent']) . '% full (' . $summary['label'] . "). Could you raise the limit, or help me free some space?\n\nThank you"),
            ];
        }
        return null;
    }
}
