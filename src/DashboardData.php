<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the dashboard shows. Only what the signed-in role may see is worked out: an editor's dashboard is about
 * content, the super admin's adds people, backups, logs, storage and the health of the system.
 */
final class DashboardData
{
    /**
     * @param callable(): array<int, array<string, mixed>> $backups the backup archives, newest first
     * @param (callable(callable(string): bool): array<string, mixed>)|null $watch what only the running site knows, for what the person may see
     *        (the keys DashboardAttention reads, and 'languages' and 'submissions')
     */
    public function __construct(
        private ContentRepository $content,
        private UserRepository $users,
        private ActivityLogRepository $activity,
        private EmailLogRepository $emails,
        private $backups,
        private SiteLimits $limits,
        private SystemStatus $system,
        private $watch = null
    ) {
    }

    /**
     * @param callable(string): bool $can whether the person has a capability
     * @param callable(string): bool $mayOpenType whether the person may work with a content type
     * @return array<string, mixed>
     */
    public function build(callable $can, callable $mayOpenType): array
    {
        $types = array_values(array_filter($this->content->getTypes(), $mayOpenType));
        $contentTypes = [];
        $contentTotal = 0;
        $publishedTotal = 0;
        $draftTotal = 0;
        $recentContent = [];
        $extra = $this->watch !== null ? ($this->watch)($can) : [];
        $languages = array_values(array_filter(array_map('strval', $extra['languages'] ?? [])));
        $staleBefore = time() - DashboardAttention::STALE_DRAFT_DAYS * 86400;
        $drafts = [];
        $staleDrafts = 0;
        $translated = [];

        foreach ($types as $type) {
            $items = $this->content->getItems($type, null, true, false);
            $published = 0;
            $draft = 0;
            foreach ($items as $item) {
                $status = (string)($item->meta['status'] ?? 'published');
                if ($status === 'draft') {
                    $draft++;
                } else {
                    $published++;
                }
                $row = [
                    'type' => $type,
                    'slug' => $item->slug,
                    'lang' => $item->lang,
                    'title' => (string)($item->meta['title'] ?? $item->slug),
                    'status' => $status,
                    'mtime' => $item->mtime,
                    'updated_at' => date('Y-m-d H:i', $item->mtime),
                ];
                $recentContent[] = $row;
                if ($status === 'draft') {
                    $drafts[] = $row;
                    $staleDrafts += $item->mtime < $staleBefore ? 1 : 0;
                }
                if ($type !== 'forms' && count($languages) > 1) {
                    $translated[$type . '|' . $item->slug][$item->lang] = true;
                }
            }
            $count = count($items);
            $contentTypes[] = ['type' => $type, 'count' => $count, 'published' => $published, 'draft' => $draft];
            $contentTotal += $count;
            $publishedTotal += $published;
            $draftTotal += $draft;
        }

        usort($recentContent, static fn(array $a, array $b): int => ((int)$b['mtime']) <=> ((int)$a['mtime']));
        $recentContent = array_slice($recentContent, 0, 6);
        usort($drafts, static fn(array $a, array $b): int => ((int)$b['mtime']) <=> ((int)$a['mtime']));
        $draftTotal = count($drafts);
        $drafts = array_slice($drafts, 0, 5);
        $untranslated = 0;
        foreach ($translated as $has) {
            $untranslated += count(array_intersect($languages, array_keys($has))) < count($languages) ? 1 : 0;
        }

        $users = $can('users.manage') ? $this->users->all() : [];
        $activeUsers = array_values(array_filter($users, static fn(array $user): bool => (string)($user['status'] ?? '') === 'active'));
        $backups = $can('backups.manage') ? ($this->backups)() : [];
        $storage = $this->limits->summary();
        $canSeeSystem = $can('settings.manage');
        $systemChecks = $canSeeSystem ? $this->system->checks($storage) : [];
        $systemStatus = $canSeeSystem ? SystemStatus::summarize($systemChecks) : ['status' => 'ok', 'label' => '', 'detail' => ''];
        $canSeeLogs = $can('activity.manage');
        $canSeeEmails = $can('email_logs.manage');
        $failedWeek = $canSeeEmails ? $this->emails->count(['status' => 'failed', 'date_from' => gmdate('Y-m-d', time() - 7 * 86400)]) : 0;

        $storageFact = $canSeeSystem ? $storage + ['can_change' => $can('limits.manage')] : null;
        $attention = DashboardAttention::items(array_filter([
            'storage' => $storageFact,
            'checks' => $canSeeSystem ? $systemChecks : null,
            'backups' => $can('backups.manage') ? ['count' => count($backups), 'last' => (int)($backups[0]['mtime'] ?? 0) ?: (int)strtotime((string)($backups[0]['created_at'] ?? '')), 'schedule' => $extra['backup_schedule'] ?? []] : null,
            'failed_emails' => $failedWeek,
            'drafts_stale' => $this->mayEdit($can) ? $staleDrafts : 0,
            'untranslated' => $this->mayEdit($can) ? $untranslated : 0,
        ] + array_intersect_key($extra, array_flip(['update', 'seo', 'links', 'not_found', 'analytics', 'no_site_address'])), static fn($value): bool => $value !== null && $value !== 0 && $value !== []));

        return [
            'content_total' => $contentTotal,
            'content_published' => $publishedTotal,
            'content_draft' => $draftTotal,
            'content_types' => $contentTypes,
            'recent_content' => $recentContent,
            'users_total' => count($users),
            'users_active' => count($activeUsers),
            'backups_total' => count($backups),
            'last_backup' => $backups[0] ?? null,
            'recent_activity' => $canSeeLogs ? $this->activity->all([], 5, 0) : [],
            'activity_total' => $canSeeLogs ? $this->activity->count() : 0,
            'recent_emails' => $canSeeEmails ? $this->emails->all([], 5, 0) : [],
            'email_total' => $canSeeEmails ? $this->emails->count() : 0,
            'failed_emails' => $canSeeEmails ? $this->emails->count(['status' => 'failed']) : 0,
            'failed_emails_week' => $failedWeek,
            'drafts' => $drafts,
            'drafts_total' => $draftTotal,
            'drafts_stale' => $staleDrafts,
            'attention' => $attention,
            'submissions_week' => $extra['submissions'] ?? null,
            'storage' => $storage,
            'system_checks' => $systemChecks,
            'system_status' => $systemStatus,
            'php_version' => $canSeeSystem ? PHP_VERSION : '',
            'upload_limit' => $canSeeSystem ? (ini_get('upload_max_filesize') ?: '') : '',
            'memory_limit' => $canSeeSystem ? (ini_get('memory_limit') ?: '') : '',
        ];
    }

    /** Whether the person writes content: drafts and missing translations are theirs to deal with. @param callable(string): bool $can */
    private function mayEdit(callable $can): bool
    {
        return $can('content.manage');
    }
}
