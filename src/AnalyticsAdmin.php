<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Admin > Analytics: the choice between no tracking, tracking code of the owner's own and the platform's analytics, the options of
 * each, and (when the platform's analytics are on) the reports. The class saves what the settings form sent, deletes what was
 * counted when asked, and gives the screen what it shows. The things that live in the running site are handed in as closures.
 */
final class AnalyticsAdmin
{
    /**
     * @param \Closure(): array<string, mixed> $settings the site settings the site runs with
     * @param \Closure(): void $reload makes the site run with what is stored
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity
     */
    public function __construct(
        private SiteSettings $store,
        private AnalyticsStore $data,
        private \Closure $settings,
        private \Closure $reload,
        private \Closure $log
    ) {
    }

    /** The tab of the address: the reports when the platform's analytics are on, otherwise (or when asked) the settings. */
    public function tab(string $tab): string
    {
        $on = AnalyticsSettings::from(($this->settings)())['mode'] === 'platform';
        return $on && $tab !== 'settings' ? 'reports' : 'settings';
    }

    /**
     * What the settings form sent: saved, or (do=clear) everything counted deleted. Where to go next is returned.
     *
     * @param array<string, mixed> $post
     */
    public function save(array $post, bool $mayWriteCode): string
    {
        if ((string)($post['do'] ?? '') === 'clear') {
            $this->data->clear();
            ($this->log)('analytics.clear', 'warning', 'settings', 'analytics', 'Analytics data deleted.', []);
            return '/admin/analytics?tab=settings&cleared=1';
        }
        $site = ($this->settings)();
        $before = is_array($site['analytics'] ?? null) ? $site['analytics'] : [];
        $after = AnalyticsSettings::apply($before, $post, $mayWriteCode);
        if (!$this->store->setSection('analytics', $after)) {
            return '/admin/analytics?tab=settings&error=store';
        }
        ($this->reload)();
        ($this->log)('analytics.update', 'info', 'settings', 'analytics', 'Analytics settings saved: ' . $after['mode'] . '.', ['mode' => $after['mode']]);
        return '/admin/analytics?tab=' . ($after['mode'] === 'platform' ? 'reports' : 'settings') . '&saved=1';
    }

    /** @return array<string, mixed> */
    public function settingsScreen(bool $mayWriteCode): array
    {
        $a = AnalyticsSettings::from(($this->settings)());
        return [
            'a' => $a,
            'modes' => AnalyticsSettings::MODES,
            'keep' => AnalyticsSettings::KEEP,
            'ignore_text' => implode("\n", $a['ignore_paths']),
            'may_write_code' => $mayWriteCode,
            'stats' => $this->data->stats(),
            'store_ok' => $this->data->isAvailable(),
        ];
    }

    /**
     * The reports for the stretch of days the address asks for.
     *
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function reportsScreen(array $get, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $span = AnalyticsReport::span((string)($get['range'] ?? '30d'), (string)($get['from'] ?? ''), (string)($get['to'] ?? ''), $today);
        $report = (new AnalyticsReport($this->data))->build($span, $today);
        return ['report' => $report, 'ranges' => AnalyticsReport::RANGES, 'more_ranges' => AnalyticsReport::MORE, 'cards' => AnalyticsReport::CARDS, 'now' => $this->data->now(), 'today' => $today];
    }

    /**
     * A list as a CSV file, or null when the list is not one the store keeps.
     *
     * @param array<string, mixed> $get
     * @return array{name: string, body: string}|null
     */
    public function export(string $kind, array $get, ?string $today = null): ?array
    {
        if (!array_key_exists($kind, AnalyticsStore::KINDS)) {
            return null;
        }
        $today ??= date('Y-m-d');
        $span = AnalyticsReport::span((string)($get['range'] ?? '30d'), (string)($get['from'] ?? ''), (string)($get['to'] ?? ''), $today);
        return ['name' => 'analytics-' . $kind . '-' . $span['from'] . '-' . $span['to'] . '.csv', 'body' => (new AnalyticsReport($this->data))->csv($kind, $span, $today)];
    }

    /** @return array{visitors: int, pages: array<string, int>} who is on the site now */
    public function now(): array
    {
        return $this->data->now();
    }
}
