<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the site may use: the storage limit the super admin sets (Settings > Limits) against what the content, the
 * system folder, and the uploads take, and the size of one upload (the site's limit, never more than the server
 * takes). Walking every file is slow, so the measurement is kept in the system database for twelve hours; uploads and
 * deletions adjust the kept copy, so the limit still holds between measurements. 0 means no limit.
 */
final class SiteLimits
{
    /** How long a measurement of the folders is trusted, in seconds. */
    public const CACHE_SECONDS = 43200;

    /** @var array<string, mixed>|null */
    private ?array $summary = null;

    /** @param callable(): array<string, mixed> $settings the site settings, read each time */
    public function __construct(
        private SystemMetaRepository $meta,
        private string $basePath,
        private string $contentDir,
        private $settings
    ) {
    }

    /**
     * What the site uses (content, the system database and backups, and uploads) against the limit. `level` is ok,
     * warn (from 80%), or danger (from 90%); without a limit it is always ok and the bar shows the share of the disk.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        if ($this->summary !== null) {
            return $this->summary;
        }
        $stored = $this->measurement();
        $parts = $stored['parts'];
        $used = array_sum($parts);
        // A host can switch the function off; the share of the disk is then simply not known.
        $diskFree = function_exists('disk_free_space') ? (int)(@disk_free_space($this->basePath) ?: 0) : 0;
        $limit = $this->limitBytes();
        $base = $limit > 0 ? $limit : $used + $diskFree;
        $ratio = $base > 0 ? $used / $base : 0.0;
        $percent = (int)round($ratio * 100);
        if ($used > 0 && $percent === 0) {
            $percent = 1;
        }
        $level = $limit <= 0 ? 'ok' : ($ratio >= 0.9 ? 'danger' : ($ratio >= 0.8 ? 'warn' : 'ok'));

        return $this->summary = [
            'used' => $used,
            'used_human' => Format::bytes($used),
            'parts' => array_map(static fn(int $bytes): string => Format::bytes($bytes), $parts),
            'disk_free' => $diskFree,
            'disk_free_human' => Format::bytes($diskFree),
            'limit' => $limit,
            'limit_human' => $limit > 0 ? Format::bytes($limit) : '',
            'percent' => max(0, min(100, $percent)),
            'percent_of_limit' => $limit > 0 ? $percent : null,
            'level' => $level,
            'measured_at' => $stored['measured_at'],
            'full' => $limit > 0 && $used >= $limit,
            'label' => $limit > 0 ? Format::bytes($used) . ' / ' . Format::bytes($limit) : Format::bytes($used),
        ];
    }

    /** Walks the folders now and keeps the result. @return array{parts: array{uploads: int, content: int, system: int, forms: int}, measured_at: int} */
    public function measure(): array
    {
        $forms = self::directorySize($this->basePath . '/storage/form-uploads');
        $measured = [
            'parts' => [
                'uploads' => self::directorySize($this->basePath . '/public/uploads'),
                'content' => self::directorySize($this->contentDir),
                // Files visitors attached to forms are counted on their own; the system folder is the rest of storage/.
                'system' => max(0, self::directorySize($this->basePath . '/storage') - $forms),
                'forms' => $forms,
            ],
            'measured_at' => time(),
        ];
        $this->meta->setJson('storage_usage', $measured);
        $this->summary = null;
        return $measured;
    }

    /** Adds or takes off bytes in the kept measurement of the uploads folder, so a new file counts at once. */
    public function uploadsChanged(int $bytes): void
    {
        $this->partChanged('uploads', $bytes);
    }

    /** The same for the files visitors attached to forms. */
    public function formFilesChanged(int $bytes): void
    {
        $this->partChanged('forms', $bytes);
    }

    private function partChanged(string $part, int $bytes): void
    {
        $kept = $this->meta->getJson('storage_usage');
        if ($bytes === 0 || !is_array($kept) || !is_int($kept['parts'][$part] ?? null)) {
            return;
        }
        $kept['parts'][$part] = max(0, $kept['parts'][$part] + $bytes);
        $this->meta->setJson('storage_usage', $kept);
        $this->summary = null;
    }

    /** The storage the site may use in bytes, or 0 for no limit. */
    public function limitBytes(): int
    {
        return max(0, (int)($this->settings()['limits']['storage_mb'] ?? 1024)) * 1048576;
    }

    /** Whether a file of this size still fits, so uploads stop at the limit while everything else keeps working. */
    public function allows(int $bytes): bool
    {
        $limit = $this->limitBytes();
        return $limit <= 0 || $this->summary()['used'] + max(0, $bytes) <= $limit;
    }

    public function fullMessage(): string
    {
        $summary = $this->summary();
        return 'The storage limit is reached (' . $summary['used_human'] . ' of ' . $summary['limit_human'] . '). Delete files you no longer need, or ask the super admin to raise the limit.';
    }

    /** The largest file the site allows, in MB (Settings > Limits); 0 means no limit of its own. */
    public function uploadLimitMb(): int
    {
        return self::uploadMbFrom($this->settings());
    }

    /** The upload limit in MB that a site's settings hold (0 for none). @param array<string, mixed> $settings */
    public static function uploadMbFrom(array $settings): int
    {
        return max(0, (int)($settings['limits']['upload_mb'] ?? $settings['media']['max_upload_mb'] ?? 20));
    }

    /** The size limit that really applies: the site's, but never more than the server can take. In bytes, 0 for none. */
    public function maxUploadBytes(): int
    {
        $mine = $this->uploadLimitMb() * 1024 * 1024;
        $server = self::serverUploadCap();
        return $mine > 0 && $server > 0 ? min($mine, $server) : max($mine, $server);
    }

    /** What the server lets through in one request (post_max_size), in bytes; 0 when unknown. */
    public static function serverPostCap(): int
    {
        return self::iniBytes('post_max_size');
    }

    /** What the server itself lets through in one upload (upload_max_filesize and post_max_size), in bytes; 0 when unknown. */
    public static function serverUploadCap(): int
    {
        $sizes = array_filter([self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')], static fn(int $bytes): bool => $bytes > 0);
        return $sizes === [] ? 0 : min($sizes);
    }

    private static function iniBytes(string $name): int
    {
        return (static function (string $name): int {
            $raw = trim((string)ini_get($name));
            if ($raw === '' || $raw === '-1') {
                return 0;
            }
            $number = (int)$raw;
            return match (strtolower(substr($raw, -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        })($name);
    }

    /** The limit typed on the settings form in megabytes, or null when it was left empty or is not a number. @param array<string, mixed> $post */
    public static function storageLimitFromForm(array $post): ?int
    {
        $value = str_replace(',', '.', trim((string)($post['storage_limit_value'] ?? '')));
        if ($value === '' || !is_numeric($value) || (float)$value < 0) {
            return null;
        }
        $megabytes = (float)$value * ((string)($post['storage_limit_unit'] ?? 'gb') === 'mb' ? 1 : 1024);
        return (int)min(10485760, round($megabytes));
    }

    /**
     * The upload size and the kinds of file typed on the settings form; null for what was left out or cannot be
     * used. Allowing no kind at all would lock everyone out of the media library, so an empty choice changes nothing.
     *
     * @param array<string, mixed> $post
     * @return array{mb: ?int, types: ?string}
     */
    public static function uploadSettingsFromForm(array $post): array
    {
        $value = str_replace(',', '.', trim((string)($post['upload_limit_mb'] ?? '')));
        $mb = $value !== '' && is_numeric($value) && (float)$value >= 0 ? (int)min(102400, round((float)$value)) : null;
        $types = null;
        if (isset($post['upload_types_present'])) {
            $chosen = is_array($post['upload_types'] ?? null) ? array_map('strval', $post['upload_types']) : [];
            $chosen = array_values(array_intersect(array_keys(MediaLibrary::UPLOAD_GROUPS), $chosen));
            $types = $chosen === [] ? null : implode(',', $chosen);
        }
        return ['mb' => $mb, 'types' => $types];
    }

    /** The size of every file under a folder, in bytes. */
    public static function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $size += (int)$fileInfo->getSize();
            }
        }
        return $size;
    }

    /**
     * The size of uploads, content and the system folder, from the copy kept while it is younger than twelve hours,
     * otherwise measured again (and kept). In bytes, where summary() has them for reading.
     *
     * @return array{parts: array{uploads: int, content: int, system: int, forms: int}, measured_at: int}
     */
    public function measurement(): array
    {
        $kept = $this->meta->getJson('storage_usage');
        $keys = ['uploads', 'content', 'system', 'forms'];
        if (is_array($kept) && is_array($kept['parts'] ?? null)) {
            $age = time() - (int)($kept['measured_at'] ?? 0);
            $whole = count(array_filter($keys, static fn(string $key): bool => is_int($kept['parts'][$key] ?? null))) === count($keys);
            // A clock that moved backwards (a negative age) counts as expired too.
            if ($whole && $age >= 0 && $age < self::CACHE_SECONDS) {
                return ['parts' => array_intersect_key($kept['parts'], array_flip($keys)), 'measured_at' => (int)$kept['measured_at']];
            }
        }
        return $this->measure();
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ($this->settings)();
    }
}
