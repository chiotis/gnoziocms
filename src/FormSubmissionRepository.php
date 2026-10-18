<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Form submissions stored as JSON files in content/forms-submissions/<form-slug>/<id>.json.
 * Submissions are shared by every language version of a form (they are keyed by slug).
 */
final class FormSubmissionRepository
{
    private FormUploads $uploads;

    /** @param string|null $uploadsDir where the files attached to submissions are kept (private, outside the web root); storage/form-uploads next to the content folder when not given */
    public function __construct(private string $contentDir, ?string $uploadsDir = null)
    {
        $this->uploads = new FormUploads($uploadsDir ?? dirname($contentDir) . '/storage/form-uploads');
    }

    /** The private folder of the files attached to submissions. */
    public function uploads(): FormUploads
    {
        return $this->uploads;
    }

    public function directory(string $slug): string
    {
        return $this->contentDir . '/forms-submissions/' . $slug;
    }

    public static function isValidId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 64 && (bool)preg_match('/^[A-Za-z0-9_-]+$/', $id);
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function all(string $slug): array
    {
        $dir = $this->directory($slug);
        if ($slug === '' || !is_dir($dir)) {
            return [];
        }
        $entries = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $data = json_decode((string)file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $entries[] = [
                'id' => (string)($data['id'] ?? basename($path, '.json')),
                'submitted_at' => (string)($data['submitted_at'] ?? ''),
                'form' => (string)($data['form'] ?? $slug),
                'lang' => (string)($data['lang'] ?? ''),
                'translation_id' => (string)($data['translation_id'] ?? ''),
                'ip' => (string)($data['ip'] ?? ''),
                'user_agent' => (string)($data['user_agent'] ?? ''),
                'fields' => is_array($data['fields'] ?? null) ? $data['fields'] : [],
                'files' => is_array($data['files'] ?? null) ? $data['files'] : [],
            ];
        }
        usort($entries, static fn(array $a, array $b): int => strcmp((string)$b['submitted_at'], (string)$a['submitted_at']));
        return $entries;
    }

    /** How many submissions of every form arrived in the last days, counted by the time of their files (nothing is opened). */
    public function recentCount(int $days = 7): int
    {
        $since = time() - $days * 86400;
        $count = 0;
        foreach (glob($this->contentDir . '/forms-submissions/*/*.json') ?: [] as $path) {
            if ((int)@filemtime($path) >= $since) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array{total: int, last_7_days: int, latest: string} */
    public function stats(string $slug): array
    {
        $entries = $this->all($slug);
        $since = time() - 7 * 86400;
        $recent = array_filter($entries, static fn(array $entry): bool => (int)strtotime((string)$entry['submitted_at']) >= $since);
        return [
            'total' => count($entries),
            'last_7_days' => count($recent),
            'latest' => (string)($entries[0]['submitted_at'] ?? ''),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array{lang?: string, q?: string, date_from?: string, date_to?: string, has_files?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function filter(array $entries, array $filters): array
    {
        $lang = trim((string)($filters['lang'] ?? ''));
        $query = mb_strtolower(trim((string)($filters['q'] ?? '')));
        $from = trim((string)($filters['date_from'] ?? ''));
        $to = trim((string)($filters['date_to'] ?? ''));
        $fromTs = $from !== '' ? strtotime($from . ' 00:00:00') : false;
        $toTs = $to !== '' ? strtotime($to . ' 23:59:59') : false;
        $hasFiles = trim((string)($filters['has_files'] ?? '')) !== '';

        return array_values(array_filter($entries, static function (array $entry) use ($lang, $query, $fromTs, $toTs, $hasFiles): bool {
            if ($lang !== '' && (string)$entry['lang'] !== $lang) {
                return false;
            }
            if ($hasFiles && self::fileCount($entry) === 0) {
                return false;
            }
            $submitted = (int)strtotime((string)$entry['submitted_at']);
            if ($fromTs !== false && $submitted < $fromTs) {
                return false;
            }
            if ($toTs !== false && $submitted > $toTs) {
                return false;
            }
            if ($query === '') {
                return true;
            }
            $haystack = [];
            foreach ($entry['fields'] as $key => $value) {
                $haystack[] = $key . ' ' . (is_array($value) ? implode(' ', array_map('strval', $value)) : (string)$value);
            }
            return str_contains(mb_strtolower(implode(' ', $haystack)), $query);
        }));
    }

    /** How many files a submission has (from the entry as `all()` gives it). @param array<string, mixed> $entry */
    public static function fileCount(array $entry): int
    {
        $count = 0;
        foreach (is_array($entry['files'] ?? null) ? $entry['files'] : [] as $files) {
            $count += is_array($files) ? count($files) : 0;
        }
        return $count;
    }

    public function find(string $slug, string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        foreach ($this->all($slug) as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /** A new submission's id (the files of a submission are kept under it, so it is decided before they are). */
    public function newId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    /** @param array<string, mixed> $payload */
    public function store(string $slug, array $payload, ?string $id = null): string
    {
        $dir = $this->directory($slug);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $id = $id !== null && self::isValidId($id) ? $id : $this->newId();
        $payload = ['id' => $id] + $payload;
        file_put_contents($dir . '/' . $id . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $id;
    }

    public function delete(string $slug, string $id): bool
    {
        if ($slug === '' || !self::isValidId($id)) {
            return false;
        }
        $path = $this->directory($slug) . '/' . $id . '.json';
        $deleted = is_file($path) && @unlink($path);
        // A submission's files go with it, and only with it.
        if ($deleted) {
            $this->uploads->remove($slug, $id);
        }
        return $deleted;
    }
}
