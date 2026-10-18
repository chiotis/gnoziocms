<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The report a site sends to Omni (docs/omni-protocol.md): the version, storage, backups, analytics, the update check
 * and the health checks, in the shape the protocol fixes. Sizes are bytes, times are UTC, and a value the site cannot
 * know is null. It holds nothing private: no content, users, submissions, visitor figures or secrets.
 */
final class OmniReport
{
    public const PROTOCOL = 1;

    /** How far back failed backups are counted. */
    private const FAILED_WINDOW = 7 * 86400;

    /**
     * @param callable(): array<string, mixed> $settings the site settings
     * @param callable(): array{name: string, url: string, admin_url: string} $site
     * @param callable(): array{version: string, commit: string} $faros
     * @param callable(): array{summary: array<string, mixed>, measurement: array{parts: array<string, int>, measured_at: int}} $storage SiteLimits::summary() and measurement()
     * @param callable(int): array<int, array<string, mixed>> $backupRuns BackupRunRepository::recent(), newest first
     * @param callable(): array<string, mixed> $cron CronStatus::info()
     * @param callable(): ?array<string, mixed> $updates UpdateService::cachedStatus(), never a fresh check
     * @param callable(): array{checks: array<int, array<string, mixed>>, extensions: array<int, array<string, mixed>>, environment: array<string, string>} $system from SystemStatus
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        private $settings,
        private $site,
        private $faros,
        private $storage,
        private $backupRuns,
        private $cron,
        private $updates,
        private $system,
        private $clock = null
    ) {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        $settings = ($this->settings)();
        $site = ($this->site)();
        $faros = ($this->faros)();
        return [
            'protocol' => self::PROTOCOL,
            'sent_at' => self::utc($now),
            'site' => [
                'name' => (string)($site['name'] ?? ''),
                'url' => (string)($site['url'] ?? ''),
                'admin_url' => (string)($site['admin_url'] ?? ''),
            ],
            'faros' => ['version' => (string)($faros['version'] ?? ''), 'commit' => (string)($faros['commit'] ?? '')],
            'storage' => $this->storage(),
            'backups' => $this->backups($settings, $now),
            'analytics' => self::analytics($settings),
            'updates' => $this->updates((string)($faros['version'] ?? '')),
            'system' => $this->system(),
        ];
    }

    /** The report as it is sent, or shown in the admin with $pretty. */
    public function json(bool $pretty = false): string
    {
        return (string)json_encode($this->build(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $data = ($this->storage)();
        $summary = $data['summary'];
        $measured = $data['measurement'];
        $limit = max(0, (int)($summary['limit'] ?? 0));
        $parts = [];
        foreach (['uploads', 'content', 'system'] as $key) {
            $parts[$key] = max(0, (int)($measured['parts'][$key] ?? 0));
        }
        $level = (string)($summary['level'] ?? 'ok');
        return [
            'used' => max(0, (int)($summary['used'] ?? array_sum($parts))),
            'limit' => $limit,
            // A host can switch disk_free_space off; 0 then means it is not known.
            'disk_free' => (int)($summary['disk_free'] ?? 0) > 0 ? (int)$summary['disk_free'] : null,
            'percent_of_limit' => $limit > 0 ? (int)($summary['percent_of_limit'] ?? 0) : null,
            'level' => in_array($level, ['ok', 'warn', 'danger'], true) ? $level : 'ok',
            'full' => (bool)($summary['full'] ?? false),
            'parts' => $parts,
            'measured_at' => self::utc($measured['measured_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function backups(array $settings, int $now): array
    {
        $backup = is_array($settings['backup'] ?? null) ? $settings['backup'] : [];
        $auto = is_array($backup['auto'] ?? null) ? $backup['auto'] : [];
        $remote = is_array($backup['remote'] ?? null) ? $backup['remote'] : [];
        $scheduled = Format::isTruthy($auto['enabled'] ?? false);
        $schedule = strtolower((string)($auto['schedule'] ?? 'daily'));
        $remoteOn = Format::isTruthy($remote['enabled'] ?? false);

        $runs = ($this->backupRuns)(100);
        $last = $runs[0] ?? null;
        $lastSuccess = null;
        $failed = 0;
        foreach ($runs as $run) {
            $status = (string)($run['status'] ?? '');
            if ($lastSuccess === null && $status === 'success') {
                $lastSuccess = self::utc($run['created_at'] ?? null);
            }
            $at = strtotime((string)($run['created_at'] ?? ''));
            if ($status === 'failed' && $at !== false && $now - $at <= self::FAILED_WINDOW) {
                $failed++;
            }
        }

        $cron = ($this->cron)();
        $state = (string)($cron['state'] ?? 'never');
        return [
            'scheduled' => $scheduled,
            'schedule' => $scheduled ? (in_array($schedule, ['daily', 'weekly', 'monthly'], true) ? $schedule : 'daily') : null,
            'remote' => $remoteOn,
            // Without remote storage every archive stays on the server.
            'keep_local' => $remoteOn ? Format::isTruthy($remote['keep_local'] ?? false) : true,
            'last' => is_array($last) ? [
                'at' => self::utc($last['created_at'] ?? null),
                'status' => (string)($last['status'] ?? 'failed'),
                'size' => max(0, (int)($last['size_bytes'] ?? 0)),
                'message' => (string)($last['message'] ?? ''),
            ] : null,
            'last_success_at' => $lastSuccess,
            'failed_last_7_days' => $failed,
            'cron' => [
                'state' => in_array($state, ['fresh', 'stale', 'never'], true) ? $state : 'never',
                'last_seen' => self::utc($cron['last_seen'] ?? null),
                'after_visit' => (bool)($cron['after_visit'] ?? false),
            ],
        ];
    }

    /** Only whether visits are counted and how; never the tracking code or any figure. @param array<string, mixed> $settings @return array{enabled: bool, mode: string} */
    public static function analytics(array $settings): array
    {
        $mode = (string)AnalyticsSettings::from($settings)['mode'];
        return ['enabled' => $mode !== 'off', 'mode' => $mode];
    }

    /** @return array<string, mixed> */
    private function updates(string $current): array
    {
        $status = ($this->updates)();
        if (!is_array($status)) {
            // Never checked yet: the version is known, the rest is not.
            return ['current' => $current, 'latest' => null, 'has_update' => null, 'checked_at' => null, 'source_status' => null];
        }
        $latest = (string)($status['latest_version'] ?? '');
        return [
            'current' => $current,
            'latest' => $latest !== '' ? $latest : null,
            'has_update' => $latest !== '' ? (bool)($status['has_update'] ?? false) : null,
            'checked_at' => self::utc($status['checked_at'] ?? null),
            'source_status' => in_array($status['source_status'] ?? null, ['ok', 'unreachable', 'not_configured'], true) ? $status['source_status'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function system(): array
    {
        $data = ($this->system)();
        $checks = array_map(static fn(array $check): array => [
            'label' => (string)($check['label'] ?? ''),
            'value' => (string)($check['value'] ?? ''),
            'status' => (string)($check['status'] ?? 'ok'),
        ], array_values($data['checks']));
        return [
            'summary' => SystemStatus::summarize($checks),
            'checks' => $checks,
            'extensions' => array_map(static fn(array $row): array => [
                'name' => (string)($row['name'] ?? ''),
                'loaded' => (bool)($row['loaded'] ?? false),
                'status' => (string)($row['status'] ?? 'ok'),
            ], array_values($data['extensions'])),
            'environment' => array_map('strval', $data['environment']),
        ];
    }

    /** A time as ISO 8601 in UTC (`2026-10-13T09:00:00Z`), from a Unix time or a date string; null when there is none. */
    public static function utc(mixed $value): ?string
    {
        if (is_int($value)) {
            $time = $value > 0 ? $value : false;
        } else {
            $value = trim((string)$value);
            $time = $value !== '' ? strtotime($value) : false;
        }
        return $time !== false ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }
}
