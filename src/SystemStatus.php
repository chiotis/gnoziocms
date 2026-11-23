<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The health of the installation as the dashboard and the System screen show it: one check per thing that can go
 * wrong (PHP, the database, folders that must be writable, mail, disk space, the content index, scheduled backups),
 * a one-line verdict, the PHP extensions the site uses, and the environment in a table.
 */
final class SystemStatus
{
    /** The extensions the site uses and what each is for. */
    private const EXTENSIONS = [
        'pdo_sqlite' => 'System database',
        'zip' => 'Backups',
        'curl' => 'Remote backups, Google sign-in, SES',
        'mbstring' => 'Text handling',
        'fileinfo' => 'Upload type detection',
        'openssl' => 'SMTP TLS, HTTPS',
        'simplexml' => 'S3 listings',
        'sodium' => 'Signed updates',
        'intl' => 'Optional',
    ];

    /**
     * @param callable(): string $mailProvider 'none' when no email service is set up
     * @param callable(): array{available: bool, stale?: bool, rows?: int} $contentIndex the state of the content index, rebuilt first when it is small and out of date
     * @param callable(): array{label: string, value: string, status: string} $backupSchedule the check for scheduled backups
     * @param (callable(): array{label: string, value: string, status: string})|null $cronJob the check for the cron job (CronStatus)
     * @param (callable(): array{label: string, value: string, status: string})|null $svgHeader the check that the web server sends
     *   uploaded SVG files with their Content-Security-Policy (svgHeaderCheck())
     */
    public function __construct(
        private string $basePath,
        private string $contentDir,
        private SystemDatabase $database,
        private $mailProvider,
        private $contentIndex,
        private $backupSchedule,
        private $cronJob = null,
        private $svgHeader = null
    ) {
    }

    /**
     * @param array<string, mixed> $storage from SiteLimits::summary()
     * @return array<int, array{label: string, value: string, status: string}> status is ok, warning, or error
     */
    public function checks(array $storage): array
    {
        $mail = ($this->mailProvider)();
        $upload = ini_get('upload_max_filesize') ?: '';
        $memory = ini_get('memory_limit') ?: '';
        $uploads = $this->basePath . '/public/uploads';
        return [
            ['label' => 'PHP', 'value' => PHP_VERSION, 'status' => version_compare(PHP_VERSION, '8.5.0', '>=') ? 'ok' : 'error'],
            ['label' => 'Upload limit', 'value' => $upload !== '' ? $upload : 'unknown', 'status' => $upload !== '' ? 'ok' : 'warning'],
            ['label' => 'Memory limit', 'value' => $memory !== '' ? $memory : 'unknown', 'status' => $memory !== '' ? 'ok' : 'warning'],
            [
                'label' => 'SQLite',
                'value' => $this->database->isAvailable() ? 'available' : ($this->database->lastError() ?: 'unavailable'),
                'status' => $this->database->isAvailable() ? 'ok' : 'error',
            ],
            ['label' => 'Content directory', 'value' => is_writable($this->contentDir) ? 'writable' : 'not writable', 'status' => is_writable($this->contentDir) ? 'ok' : 'error'],
            ['label' => 'Storage directory', 'value' => is_writable($this->basePath . '/storage') ? 'writable' : 'not writable', 'status' => is_writable($this->basePath . '/storage') ? 'ok' : 'error'],
            ['label' => 'Uploads directory', 'value' => is_writable($uploads) ? 'writable' : 'not writable', 'status' => is_writable($uploads) ? 'ok' : 'warning'],
            ['label' => 'Email provider', 'value' => $mail === 'none' ? 'not configured' : strtoupper($mail), 'status' => $mail === 'none' ? 'warning' : 'ok'],
            ['label' => 'Disk free', 'value' => (string)$storage['disk_free_human'], 'status' => ((int)$storage['percent']) > 90 ? 'warning' : 'ok'],
            $this->contentIndexCheck(),
            ($this->backupSchedule)(),
            ...($this->cronJob !== null ? [($this->cronJob)()] : []),
            ...($this->svgHeader !== null ? [($this->svgHeader)()] : []),
        ];
    }

    /** One line for the lot: needs attention (any error), warnings, or healthy. @param array<int, array<string, mixed>> $checks @return array{label: string, status: string, detail: string} */
    public static function summarize(array $checks): array
    {
        $errors = count(array_filter($checks, static fn(array $check): bool => (string)($check['status'] ?? '') === 'error'));
        $warnings = count(array_filter($checks, static fn(array $check): bool => (string)($check['status'] ?? '') === 'warning'));
        if ($errors > 0) {
            return ['label' => 'Needs attention', 'status' => 'error', 'detail' => $errors . ($errors === 1 ? ' critical check' : ' critical checks')];
        }
        if ($warnings > 0) {
            return ['label' => 'Warnings', 'status' => 'warning', 'detail' => $warnings . ($warnings === 1 ? ' check to review' : ' checks to review')];
        }
        return ['label' => 'Healthy', 'status' => 'ok', 'detail' => 'All checks passing'];
    }

    /** @return array<int, array{name: string, purpose: string, loaded: bool, status: string}> */
    public static function extensions(): array
    {
        $rows = [];
        foreach (self::EXTENSIONS as $extension => $purpose) {
            $loaded = extension_loaded($extension);
            $rows[] = ['name' => $extension, 'purpose' => $purpose, 'loaded' => $loaded, 'status' => $loaded ? 'ok' : ($extension === 'intl' ? 'warning' : 'error')];
        }
        return $rows;
    }

    /** The version of SQLite the database runs on, or '' when it is not available. */
    public function sqliteVersion(): string
    {
        try {
            return $this->database->isAvailable() ? (string)$this->database->connection()->query('SELECT sqlite_version()')->fetchColumn() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, string> label => value
     */
    public function environment(string $version, string $commit, bool $https): array
    {
        return [
            'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'SQLite' => $this->sqliteVersion() ?: 'unavailable',
            'GnozioCMS' => $version . ($commit !== '' ? ' @ ' . $commit : ''),
            'Memory limit' => (string)ini_get('memory_limit'),
            'Upload max / post max' => ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'),
            'Max execution time' => (string)ini_get('max_execution_time') . 's',
            'Timezone' => date_default_timezone_get() . (date_default_timezone_get() !== SiteSettings::serverTimeZoneName() ? ' (server: ' . SiteSettings::serverTimeZoneName() . ')' : ''),
            'OPcache' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)) ? 'enabled' : 'disabled',
            'HTTPS' => $https ? 'yes' : 'no',
        ];
    }

    /** The policy an uploaded SVG must be served with (public/uploads/.htaccess, and the nginx line in docs/security.md). */
    public const SVG_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:";
    /** A small drawing kept under the picture variants' folder (never listed in the library), asked for to see the header. */
    public const SVG_PROBE = '_v/header-check.svg';

    /**
     * Whether the web server sends uploaded SVG files with a Content-Security-Policy that stops scripts (audit S17): Apache has
     * it from public/uploads/.htaccess, nginx only when the operator added the line. A probe file is asked for at the site's
     * address once a day (the answer kept in $cacheFile).
     *
     * @param \Closure(string): (array<string, string>|null) $headers the response headers of an address, names in lower case;
     *   null when it could not be asked
     * @return array{label: string, value: string, status: string}
     */
    public static function svgHeaderCheck(string $publicDir, string $baseUrl, string $cacheFile, \Closure $headers, int $now = 0): array
    {
        $label = 'SVG uploads header';
        $now = $now > 0 ? $now : time();
        $kept = is_file($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
        if (is_array($kept) && (int)($kept['at'] ?? 0) > $now - 86400 && ($kept['base'] ?? '') === $baseUrl && is_array($kept['check'] ?? null)) {
            return $kept['check'];
        }
        if ($baseUrl === '') {
            return ['label' => $label, 'value' => 'not checked: set the site address in Settings', 'status' => 'warning'];
        }
        $probe = rtrim($publicDir, '/') . '/uploads/' . self::SVG_PROBE;
        if (!is_file($probe)) {
            @mkdir(dirname($probe), 0775, true);
            @file_put_contents($probe, '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>' . "\n");
        }
        $answer = $headers(rtrim($baseUrl, '/') . '/uploads/' . self::SVG_PROBE);
        if ($answer === null) {
            $check = ['label' => $label, 'value' => 'could not be checked (the site did not answer itself)', 'status' => 'warning'];
        } else {
            $policy = strtolower((string)($answer['content-security-policy'] ?? ''));
            $check = str_contains($policy, "default-src 'none'") || (str_contains($policy, 'script-src') && !str_contains($policy, 'unsafe'))
                ? ['label' => $label, 'value' => 'sent', 'status' => 'ok']
                : ['label' => $label, 'value' => 'missing: add the uploads block of docs/security.md to nginx', 'status' => 'warning'];
        }
        @file_put_contents($cacheFile, json_encode(['at' => $now, 'base' => $baseUrl, 'check' => $check]));
        return $check;
    }

    /** @return array<string, string>|null the headers of a HEAD request to the site's own address, names in lower case */
    public static function headersOf(string $url): ?array
    {
        if (!function_exists('curl_init') || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $ok = curl_exec($ch) !== false && (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        return $ok ? $headers : null;
    }

    /** @return array{label: string, value: string, status: string} */
    private function contentIndexCheck(): array
    {
        try {
            $index = ($this->contentIndex)();
        } catch (\Throwable) {
            return ['label' => 'Content index', 'value' => 'unavailable', 'status' => 'warning'];
        }
        if (!$index['available']) {
            return ['label' => 'Content index', 'value' => 'SQLite unavailable', 'status' => 'warning'];
        }
        return [
            'label' => 'Content index',
            'value' => $index['stale'] ? 'out of date' : $index['rows'] . ' entries',
            'status' => $index['stale'] ? 'warning' : 'ok',
        ];
    }
}
