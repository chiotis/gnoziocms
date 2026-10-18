<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The things on the dashboard that ask for a person's eye: a new version, a system check that is not fine, storage nearly
 * full, no recent backup, mail that did not go out, a site that asks search engines to stay away, links that still use an old
 * address, drafts left to age. The dashboard gathers the facts it may (each key is there only when the signed-in person is
 * allowed to see it) and this turns them into a short list, the most serious first, each with where to go.
 */
final class DashboardAttention
{
    /** A draft not touched for this long is one that was forgotten. */
    public const STALE_DRAFT_DAYS = 30;

    /** A backup older than this is worth a word. */
    public const OLD_BACKUP_DAYS = 14;

    /** What the system checks that have a note of their own are called, so they are not told twice. */
    private const OWN_NOTE = ['Disk free', 'Scheduled backups'];

    /**
     * @param array<string, mixed> $facts
     * @return array<int, array{level: string, title: string, note: string, href: string, action: string}> level is bad, warn or info
     */
    public static function items(array $facts, ?int $now = null): array
    {
        $now ??= time();
        $items = [];
        $add = static function (string $level, string $title, string $note, string $href = '', string $action = 'Review') use (&$items): void {
            $items[] = ['level' => $level, 'title' => $title, 'note' => $note, 'href' => $href, 'action' => $action];
        };
        $plural = static fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);

        $update = is_array($facts['update'] ?? null) ? $facts['update'] : null;
        if ($update !== null) {
            $add('info', 'GnozioCMS ' . (string)($update['latest'] ?? '') . ' is available', 'You run ' . (string)($update['current'] ?? '') . '. Read what changed and take a backup first.', '/admin/updates', 'See the update');
        }

        $storage = is_array($facts['storage'] ?? null) ? $facts['storage'] : null;
        if ($storage !== null && in_array($storage['level'] ?? 'ok', ['warn', 'danger'], true)) {
            $of = ($storage['limit'] ?? 0) > 0 ? ' of the ' . $storage['limit_human'] . ' limit' : '';
            $add(
                $storage['level'] === 'danger' ? 'bad' : 'warn',
                $storage['level'] === 'danger' ? 'Storage is almost full' : 'Storage is filling up',
                ($storage['used_human'] ?? '') . ' used' . $of . '. Delete files nobody uses, or raise the limit.',
                !empty($storage['can_change']) ? '/admin/settings' : '/admin/media?usage=unused',
                !empty($storage['can_change']) ? 'Change the limit' : 'Find unused files'
            );
        }

        foreach (is_array($facts['checks'] ?? null) ? $facts['checks'] : [] as $check) {
            $status = (string)($check['status'] ?? 'ok');
            if ($status === 'ok' || in_array((string)($check['label'] ?? ''), self::OWN_NOTE, true)) {
                continue;
            }
            $add($status === 'error' ? 'bad' : 'warn', (string)$check['label'] . ': ' . (string)$check['value'], $status === 'error' ? 'The site may not work properly until this is fixed.' : 'Worth a look on the System screen.', '/admin/system', 'Open System');
        }

        $backups = is_array($facts['backups'] ?? null) ? $facts['backups'] : null;
        if ($backups !== null) {
            $last = (int)($backups['last'] ?? 0);
            $schedule = is_array($backups['schedule'] ?? null) ? $backups['schedule'] : [];
            if ((int)($backups['count'] ?? 0) === 0) {
                $add('warn', 'There is no backup yet', 'Take one before changing anything big, and set up a schedule.', '/admin/backups', 'Back up now');
            } elseif ($last > 0 && $now - $last > self::OLD_BACKUP_DAYS * 86400) {
                $add('warn', 'The last backup is ' . intdiv($now - $last, 86400) . ' days old', 'Take a new one, or schedule them so it happens by itself.', '/admin/backups', 'Back up now');
            } elseif (($schedule['status'] ?? 'ok') !== 'ok') {
                $add('warn', 'Scheduled backups are ' . (string)($schedule['value'] ?? 'not running'), 'The schedule has not run when it should have.', '/admin/backups', 'Open Backups');
            }
        }

        $failed = (int)($facts['failed_emails'] ?? 0);
        if ($failed > 0) {
            $add('warn', $plural($failed, 'email', 'emails') . ' could not be sent this week', 'Form notifications and password mails may not be reaching anyone.', '/admin/email-logs?status=failed', 'See why');
        }

        $seo = is_array($facts['seo'] ?? null) ? $facts['seo'] : null;
        if ($seo !== null) {
            if (!empty($seo['discourage'])) {
                $add('bad', 'The site asks search engines to stay away', 'Nothing is found in search, and there is no sitemap. Turn it off when the site is ready.', '/admin/seo?tab=crawling', 'Open SEO');
            }
            if ((int)($seo['no_description'] ?? 0) > 0) {
                $add('warn', $plural((int)$seo['no_description'], 'page has', 'pages have') . ' no description for search', 'Search shows a line from the page instead of what you would write.', '/admin/seo', 'Open SEO');
            }
            if ((int)($seo['duplicate_titles'] ?? 0) > 0) {
                $add('info', $plural((int)$seo['duplicate_titles'], 'page shares', 'pages share') . ' a title with another', 'Pages with the same title compete with each other in search.', '/admin/seo', 'Open SEO');
            }
        }

        $links = (int)($facts['links'] ?? 0);
        if ($links > 0) {
            $add('warn', $links . ($links === 1 ? ' link in content still uses' : ' links in content still use') . ' an old address', 'Visitors reach the page through a redirect. Pointing the links at the new address saves the detour.', '/admin/redirects', 'Update links');
        }
        $missing = (int)($facts['not_found'] ?? 0);
        if ($missing > 0) {
            $add('info', $plural($missing, 'address was', 'addresses were') . ' asked for and not found', 'A redirect to the right page keeps those visitors.', '/admin/redirects?tab=missing', 'See them');
        }

        $stale = (int)($facts['drafts_stale'] ?? 0);
        if ($stale > 0) {
            $add('info', $plural($stale, 'draft', 'drafts') . ' not touched for over ' . self::STALE_DRAFT_DAYS . ' days', 'Finish them, or delete what will not be used.', '/admin/content?status=draft', 'Open drafts');
        }
        $untranslated = (int)($facts['untranslated'] ?? 0);
        if ($untranslated > 0) {
            $add('info', $plural($untranslated, 'entry is', 'entries are') . ' missing a language', 'Visitors in the other languages see the default one, or nothing.', '/admin/content', 'Open content');
        }

        $analytics = is_array($facts['analytics'] ?? null) ? $facts['analytics'] : null;
        if ($analytics !== null) {
            if (($analytics['mode'] ?? 'off') === 'off') {
                $add('info', 'Visits are not being counted', 'Use the analytics of GnozioCMS, or add the tracking code of the service you use.', '/admin/analytics?tab=settings', 'Set up');
            } elseif (($analytics['mode'] ?? '') === 'custom' && !empty($analytics['empty'])) {
                $add('warn', 'Your own tracking code is chosen, but empty', 'Nothing is counted until a tag ID or code is added.', '/admin/analytics?tab=settings', 'Add the code');
            }
        }
        if (!empty($facts['no_site_address'])) {
            $add('warn', 'The site address is not set', 'Canonical links, the sitemap and shared links use the address the visitor came with.', '/admin/settings', 'Open Settings');
        }

        $order = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($items, static fn(array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);
        return $items;
    }
}
