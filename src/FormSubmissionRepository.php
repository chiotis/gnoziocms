<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Form submissions stored as JSON files in content/forms-submissions/<form-slug>/<id>.json.
 * Submissions are shared by every language version of a form (they are keyed by slug).
 * An archived submission is moved, as it is, to content/forms-submissions/<form-slug>/archive/<id>.json: out of the
 * list and the counts, kept with its files, and brought back with restore().
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

    /** Where the archived submissions of a form are kept. */
    public function archiveDirectory(string $slug): string
    {
        return $this->directory($slug) . '/archive';
    }

    public static function isValidId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 64 && (bool)preg_match('/^[A-Za-z0-9_-]+$/', $id);
    }

    /** @return array<int, array<string, mixed>> newest first; the archived ones instead with $archived */
    public function all(string $slug, bool $archived = false): array
    {
        $entries = [];
        foreach ($this->paths($slug, $archived) as $path) {
            $entry = $this->read($path, $slug, $archived);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn(array $a, array $b): int => strcmp((string)$b['submitted_at'], (string)$a['submitted_at']));
        return $entries;
    }

    /**
     * One page of a form's submissions, newest first, opening only the files of that page: the ids begin with the time they
     * were made (newId()), so the names alone give the order. Null when some name does not (then use all()).
     *
     * @return array{entries: array<int, array<string, mixed>>, total: int}|null
     */
    public function newest(string $slug, int $offset, int $limit, bool $archived = false): ?array
    {
        $paths = $this->datedPaths($slug, $archived);
        if ($paths === null) {
            return null;
        }
        $entries = [];
        foreach (array_slice($paths, max(0, $offset), max(0, $limit)) as $path) {
            $entry = $this->read($path, $slug, $archived);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        return ['entries' => $entries, 'total' => count($paths)];
    }

    /** @return array<int, string> */
    private function paths(string $slug, bool $archived = false): array
    {
        $dir = $archived ? $this->archiveDirectory($slug) : $this->directory($slug);
        if ($slug === '' || !is_dir($dir)) {
            return [];
        }
        return glob($dir . '/*.json') ?: [];
    }

    /** The files of a form's submissions, newest name first; null when a name does not begin with its time. @return array<int, string>|null */
    private function datedPaths(string $slug, bool $archived = false): ?array
    {
        $paths = $this->paths($slug, $archived);
        foreach ($paths as $path) {
            if (preg_match('/^\d{8}-\d{6}-/', basename($path)) !== 1) {
                return null;
            }
        }
        rsort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array<string, mixed>|null */
    private function read(string $path, string $slug, bool $archived = false): ?array
    {
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        return [
            'id' => (string)($data['id'] ?? basename($path, '.json')),
            'submitted_at' => (string)($data['submitted_at'] ?? ''),
            'form' => (string)($data['form'] ?? $slug),
            'lang' => (string)($data['lang'] ?? ''),
            'translation_id' => (string)($data['translation_id'] ?? ''),
            'ip' => (string)($data['ip'] ?? ''),
            'user_agent' => (string)($data['user_agent'] ?? ''),
            'fields' => is_array($data['fields'] ?? null) ? $data['fields'] : [],
            'files' => is_array($data['files'] ?? null) ? $data['files'] : [],
            'archived' => $archived,
        ];
    }

    /** How many submissions of a form are archived (nothing is opened). */
    public function archivedCount(string $slug): int
    {
        return count($this->paths($slug, true));
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
        $paths = $this->datedPaths($slug);
        if ($paths === null) {
            $entries = $this->all($slug);
            $since = time() - 7 * 86400;
            $recent = array_filter($entries, static fn(array $entry): bool => (int)strtotime((string)$entry['submitted_at']) >= $since);
            return [
                'total' => count($entries),
                'last_7_days' => count($recent),
                'latest' => (string)($entries[0]['submitted_at'] ?? ''),
            ];
        }
        // Counted from the names, which begin with the time of the submission; only the newest one is opened.
        $since = date('Ymd-His', time() - 7 * 86400);
        $recent = 0;
        foreach ($paths as $path) {
            if (strcmp(substr(basename($path), 0, 15), $since) >= 0) {
                $recent++;
            }
        }
        $latest = $paths === [] ? null : $this->read($paths[0], $slug);
        return [
            'total' => count($paths),
            'last_7_days' => $recent,
            'latest' => (string)($latest['submitted_at'] ?? ''),
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

    /** One submission, archived or not. */
    public function find(string $slug, string $id): ?array
    {
        if ($slug === '' || !self::isValidId($id)) {
            return null;
        }
        foreach ([false, true] as $archived) {
            $path = ($archived ? $this->archiveDirectory($slug) : $this->directory($slug)) . '/' . $id . '.json';
            if (is_file($path)) {
                $entry = $this->read($path, $slug, $archived);
                return $entry !== null && $entry['id'] === $id ? $entry : null;
            }
        }
        return null;
    }

    /** Moves a submission into the archive (true), or back out of it (false). True when it was moved. */
    public function archive(string $slug, string $id, bool $archive = true): bool
    {
        if ($slug === '' || !self::isValidId($id)) {
            return false;
        }
        $from = ($archive ? $this->directory($slug) : $this->archiveDirectory($slug)) . '/' . $id . '.json';
        $toDir = $archive ? $this->archiveDirectory($slug) : $this->directory($slug);
        if (!is_file($from) || is_file($toDir . '/' . $id . '.json')) {
            return false;
        }
        if (!is_dir($toDir) && !@mkdir($toDir, 0775, true) && !is_dir($toDir)) {
            return false;
        }
        return @rename($from, $toDir . '/' . $id . '.json');
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
        if (!is_file($path)) {
            $path = $this->archiveDirectory($slug) . '/' . $id . '.json';
        }
        $deleted = is_file($path) && @unlink($path);
        // A submission's files go with it, and only with it.
        if ($deleted) {
            $this->uploads->remove($slug, $id);
        }
        return $deleted;
    }
}
