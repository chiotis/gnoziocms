<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Content as CSV: the export of one content type in every language, and the import of such a file. An import is
 * done in two steps: a preview (what would be created, changed, or skipped, and which rows are wrong) that changes
 * nothing, then applying that preview, which backs up what it touches and puts everything back if a write fails.
 */
final class ContentCsv
{
    /**
     * @param callable(): array<string, mixed> $settings the site settings, read each time
     * @param callable(): HtmlGuard $guard checks raw HTML in imported text
     * @param callable(string): string $normalizeDate the date as it is stored in front matter
     * @param callable(): string $currentUser who is importing (for the history)
     * @param callable(): bool $mayUseRawHtml whether the person may bring raw HTML in
     */
    public function __construct(
        private ContentRepository $content,
        private string $contentDir,
        private $settings,
        private RevisionRepository $revisions,
        private $guard,
        private $normalizeDate,
        private $currentUser,
        private $mayUseRawHtml
    ) {
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ($this->settings)();
    }

    private function paths(): ContentPaths
    {
        return new ContentPaths($this->settings());
    }

    /** A content type from a file or a request: a safe name, "pages" when there is none. */
    private static function type(string $value): string
    {
        $value = Slug::plain($value);
        return $value === '' ? 'pages' : $value;
    }

    /**
     * The table of an export: the header row, then one row per item in the same column order, with every field the
     * items have beyond the fixed columns as `meta.` columns (nested values flattened to `meta.a.b`, lists as JSON).
     *
     * @param ContentItem[] $items
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function exportTable(string $type, array $items, string $siteName): array
    {
        $metaHeaders = [];
        $reservedMetaHeaders = [
            'meta.slug', 'meta.type', 'meta.lang', 'meta.title', 'meta.status', 'meta.visible', 'meta.date', 'meta.author',
            'meta.tags', 'meta.categories', 'meta.translation_id', 'meta.main_image', 'meta.excerpt',
        ];

        $flatMetaRows = [];
        foreach ($items as $item) {
            $flatMeta = $this->flattenMeta($item->meta, 'meta');
            $flatMetaRows[$item->slug . '|' . $item->lang] = $flatMeta;
            foreach (array_keys($flatMeta) as $key) {
                if (in_array($key, $reservedMetaHeaders, true)) {
                    continue;
                }
                if (!in_array($key, $metaHeaders, true)) {
                    $metaHeaders[] = $key;
                }
            }
        }
        sort($metaHeaders);

        $headers = array_merge([
            'site_title', 'content_type', 'language', 'slug', 'title', 'status', 'visible', 'date', 'author', 'tags',
            'categories', 'translation_id', 'main_image', 'excerpt', 'updated_at', 'body',
        ], $metaHeaders);

        $rows = [];
        foreach ($items as $item) {
            $flatMeta = $flatMetaRows[$item->slug . '|' . $item->lang] ?? [];
            $row = [
                $siteName,
                $type,
                $item->lang,
                $item->slug,
                (string)($item->meta['title'] ?? ''),
                (string)($item->meta['status'] ?? ''),
                Format::isTruthy($item->meta['visible'] ?? true) ? 'true' : 'false',
                (string)($item->meta['date'] ?? ''),
                (string)($item->meta['author'] ?? ''),
                implode(', ', Format::list($item->meta['tags'] ?? null)),
                implode(', ', Format::list($item->meta['categories'] ?? null)),
                (string)($item->meta['translation_id'] ?? ''),
                (string)($item->meta['main_image'] ?? ''),
                (string)($item->meta['excerpt'] ?? ''),
                date('c', $item->mtime),
                $item->markdown,
            ];
            foreach ($metaHeaders as $metaHeader) {
                $row[] = (string)($flatMeta[$metaHeader] ?? '');
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @return array<string, string> */
    private function flattenMeta(array $meta, string $prefix = ''): array
    {
        $flat = [];
        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if ($this->isAssocArray($value)) {
                    $flat += $this->flattenMeta($value, $path);
                } else {
                    $flat[$path] = json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                }
                continue;
            }
            if (is_bool($value)) {
                $flat[$path] = $value ? 'true' : 'false';
                continue;
            }
            if ($value === null) {
                $flat[$path] = '';
                continue;
            }
            if (is_scalar($value)) {
                $flat[$path] = (string)$value;
                continue;
            }
            $flat[$path] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return $flat;
    }

    private function isAssocArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /** @return array{ok: bool, error?: string, headers?: array<int, string>, rows?: array<int, array<string, string>>} */
    public function parseImport(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Could not open CSV file.'];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV is empty.'];
        }
        $delimiter = $this->detectCsvDelimiter($firstLine);
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV headers are invalid.'];
        }

        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $header = (string)$header;
            if ($index === 0) {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            }
            $normalizedHeaders[] = $this->normalizeCsvHeader($header);
        }

        if (!in_array('language', $normalizedHeaders, true)) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV must contain a language column.'];
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $row = [];
            foreach ($normalizedHeaders as $i => $header) {
                if (!isset($line[$i])) {
                    $row[$header] = '';
                    continue;
                }
                $value = (string)$line[$i];
                $row[$header] = $header === 'body' ? $value : trim($value);
            }
            $isEmpty = true;
            foreach ($row as $value) {
                if ($value !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if (!$isEmpty) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return [
            'ok' => true,
            'headers' => $normalizedHeaders,
            'rows' => $rows,
        ];
    }

    private function detectCsvDelimiter(string $line): string
    {
        $comma = substr_count($line, ',');
        $semi = substr_count($line, ';');
        return $semi > $comma ? ';' : ',';
    }

    private function normalizeCsvHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(' ', '_', $value);
        return preg_replace('/[^a-z0-9._-]/', '', $value) ?? '';
    }

    /** @param array<int, array<string, string>> $rows @param array<int, string> $headers */
    public function preview(string $type, array $rows, array $headers): array
    {
        $availableLanguages = $this->settings()['languages']['available'] ?? [];
        $existingItems = $this->content->getItems($type, null, true, false);
        $bySlugLang = [];
        $byTranslationLang = [];
        foreach ($existingItems as $item) {
            $slugKey = $item->slug . '|' . $item->lang;
            $bySlugLang[$slugKey] = $item;
            $translationId = trim((string)($item->meta['translation_id'] ?? ''));
            if ($translationId !== '') {
                $byTranslationLang[$translationId . '|' . $item->lang] = $item;
            }
        }

        $rowsOut = [];
        $entries = [];
        $summary = [
            'create' => 0,
            'update' => 0,
            'skip' => 0,
            'error' => 0,
        ];
        $seenTargets = [];

        foreach ($rows as $index => $row) {
            $lineNo = $index + 2;
            $csvType = self::type((string)($row['content_type'] ?? $type));
            if ($csvType !== $type) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => (string)($row['slug'] ?? ''),
                    'lang' => (string)($row['language'] ?? ''),
                    'title' => (string)($row['title'] ?? ''),
                    'message' => 'content_type mismatch: expected ' . $type . ', got ' . ($csvType ?: '(empty)') . '.',
                ];
                $summary['error']++;
                continue;
            }

            $lang = strtolower(trim((string)($row['language'] ?? '')));
            if (!in_array($lang, $availableLanguages, true)) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => (string)($row['slug'] ?? ''),
                    'lang' => $lang,
                    'title' => (string)($row['title'] ?? ''),
                    'message' => 'Invalid language.',
                ];
                $summary['error']++;
                continue;
            }

            $slug = Slug::plain((string)($row['slug'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($slug === '' && $title !== '') {
                $slug = Slug::plain($title);
            }
            if ($slug === '') {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => '',
                    'lang' => $lang,
                    'title' => $title,
                    'message' => 'Missing slug/title.',
                ];
                $summary['error']++;
                continue;
            }

            $translationId = trim((string)($row['translation_id'] ?? ''));
            $matchByTranslation = null;
            if ($translationId !== '') {
                $matchByTranslation = $byTranslationLang[$translationId . '|' . $lang] ?? null;
            }
            $matchBySlug = $bySlugLang[$slug . '|' . $lang] ?? null;

            if ($matchByTranslation !== null && $matchBySlug !== null && $matchByTranslation->filePath !== $matchBySlug->filePath) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => $slug,
                    'lang' => $lang,
                    'title' => $title,
                    'message' => 'Conflict: translation_id and slug point to different items.',
                ];
                $summary['error']++;
                continue;
            }

            $matched = $matchByTranslation ?? $matchBySlug;
            $oldSlug = $matched?->slug ?? $slug;
            $oldPath = $matched?->filePath ?? '';
            $newPath = $this->contentDir . '/' . $type . '/' . $this->paths()->filename($slug, $lang);
            $targetKey = $slug . '|' . $lang;
            if (isset($seenTargets[$targetKey])) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => $slug,
                    'lang' => $lang,
                    'title' => $title,
                    'message' => 'Duplicate target slug+language in CSV.',
                ];
                $summary['error']++;
                continue;
            }
            $seenTargets[$targetKey] = true;

            $payload = $this->buildImportPayload($type, $row, $headers, $matched, $slug, $lang, $title);
            if (($payload['ok'] ?? false) !== true) {
                $rowsOut[] = [
                    'line' => $lineNo,
                    'action' => 'error',
                    'slug' => $slug,
                    'lang' => $lang,
                    'title' => $title,
                    'message' => (string)($payload['error'] ?? 'Invalid row data.'),
                ];
                $summary['error']++;
                continue;
            }

            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $body = (string)($payload['body'] ?? '');
            $newContent = $this->buildMarkdownPayload($data, $body);

            $action = 'create';
            if ($matched !== null) {
                $action = 'update';
                if ($oldPath === $newPath && is_file($oldPath)) {
                    $oldContent = (string)file_get_contents($oldPath);
                    if ($oldContent === $newContent) {
                        $action = 'skip';
                    }
                }
            }

            $rowsOut[] = [
                'line' => $lineNo,
                'action' => $action,
                'slug' => $slug,
                'lang' => $lang,
                'title' => (string)($data['title'] ?? $title ?: Slug::title($slug)),
                'message' => $action === 'skip' ? 'No changes detected.' : '',
            ];
            $summary[$action]++;

            if ($action === 'skip') {
                continue;
            }

            $entries[] = [
                'line' => $lineNo,
                'action' => $action,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'old_slug' => $oldSlug,
                'slug' => $slug,
                'lang' => $lang,
                'old_mtime' => $matched?->mtime ?? 0,
                'data' => $data,
                'body' => $body,
            ];
        }

        return [
            'rows' => $rowsOut,
            'entries' => $entries,
            'summary' => $summary,
        ];
    }

    /** @param array<string, string> $row @param array<int, string> $headers */
    private function buildImportPayload(string $type, array $row, array $headers, ?ContentItem $existing, string $slug, string $lang, string $title): array
    {
        $data = $existing ? $existing->meta : [];
        unset($data['slug'], $data['type'], $data['lang']);

        $has = fn (string $header): bool => in_array($header, $headers, true);

        if ($has('title')) {
            $data['title'] = $title !== '' ? $title : Slug::title($slug);
        } elseif (!isset($data['title']) || (string)$data['title'] === '') {
            $data['title'] = Slug::title($slug);
        }

        if ($has('status')) {
            $status = trim((string)($row['status'] ?? ''));
            $data['status'] = $status !== '' ? $status : 'published';
        } elseif (!isset($data['status'])) {
            $data['status'] = 'published';
        }

        if ($has('visible')) {
            $data['visible'] = $this->parseCsvBool((string)($row['visible'] ?? ''), true);
        } elseif (!isset($data['visible'])) {
            $data['visible'] = true;
        }

        if ($has('date')) {
            $date = trim((string)($row['date'] ?? ''));
            if ($date !== '') {
                $data['date'] = ($this->normalizeDate)($date);
            } else {
                unset($data['date']);
            }
        }

        if ($has('author')) {
            $author = trim((string)($row['author'] ?? ''));
            if ($author !== '') {
                $data['author'] = $author;
            } else {
                unset($data['author']);
            }
        }

        if ($has('tags')) {
            $tags = array_values(array_filter(array_map(fn($value) => Slug::plain((string)$value), Format::commaList((string)($row['tags'] ?? '')))));
            if (!empty($tags) && $type !== 'pages' && $type !== 'forms') {
                $data['tags'] = $tags;
            } else {
                unset($data['tags']);
            }
        }

        if ($has('categories')) {
            $categories = array_values(array_filter(array_map(fn($value) => Slug::plain((string)$value), Format::commaList((string)($row['categories'] ?? '')))));
            if (!empty($categories) && $type !== 'pages' && $type !== 'forms') {
                $data['categories'] = $categories;
            } else {
                unset($data['categories']);
            }
        }

        if ($has('translation_id')) {
            $translationId = trim((string)($row['translation_id'] ?? ''));
            if ($translationId !== '') {
                $data['translation_id'] = $translationId;
            } elseif (!isset($data['translation_id'])) {
                $data['translation_id'] = ContentEditor::newTranslationId();
            }
        } elseif (!isset($data['translation_id']) || (string)$data['translation_id'] === '') {
            $data['translation_id'] = ContentEditor::newTranslationId();
        }

        if ($has('main_image')) {
            $mainImage = trim((string)($row['main_image'] ?? ''));
            if ($mainImage !== '') {
                $data['main_image'] = $mainImage;
            } else {
                unset($data['main_image']);
            }
        }

        if ($has('excerpt')) {
            $excerpt = trim((string)($row['excerpt'] ?? ''));
            if ($excerpt !== '') {
                $data['excerpt'] = $excerpt;
            } else {
                unset($data['excerpt']);
            }
        }

        $metaSet = [];
        $metaUnset = [];
        foreach ($headers as $header) {
            if (!str_starts_with($header, 'meta.')) {
                continue;
            }
            $raw = (string)($row[$header] ?? '');
            $path = substr($header, 5);
            if ($path === '' || in_array($path, ['slug', 'type', 'lang'], true)) {
                continue;
            }
            if ($raw === '') {
                $metaUnset[] = $path;
                continue;
            }
            $metaSet[$path] = $this->parseCsvImportValue($raw);
        }

        foreach ($metaUnset as $path) {
            ArrayPath::unset($data, explode('.', $path));
        }
        foreach ($metaSet as $path => $value) {
            ArrayPath::set($data, explode('.', $path), $value);
        }

        foreach (array_keys($data) as $key) {
            if ($key === 'summary') {
                unset($data[$key]);
            }
        }

        $body = $has('body') ? (string)($row['body'] ?? '') : ($existing?->markdown ?? '');
        return [
            'ok' => true,
            'data' => $data,
            'body' => $body,
        ];
    }

    private function parseCsvBool(string $value, bool $default): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $default;
        }
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private function parseCsvImportValue(string $value): mixed
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $first = $value[0] ?? '';
        if ($first === '[' || $first === '{') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        return $value;
    }

    private function buildMarkdownPayload(array $data, string $body): string
    {
        $frontmatter = trim(Yaml::dump($data, 4, 2));
        $body = rtrim($body);
        return "---\n" . $frontmatter . "\n---\n\n" . $body . "\n";
    }

    /** @param array<int, array<string, mixed>> $entries */
    public function apply(string $type, array $entries): array
    {
        $writable = array_values(array_filter($entries, function (array $entry): bool {
            return in_array((string)($entry['action'] ?? ''), ['create', 'update'], true);
        }));
        if (empty($writable)) {
            return ['ok' => false, 'error' => 'Nothing to import.'];
        }

        foreach ($writable as $entry) {
            $oldPath = (string)($entry['old_path'] ?? '');
            $oldMtime = (int)($entry['old_mtime'] ?? 0);
            if ($oldPath !== '' && file_exists($oldPath) && $oldMtime > 0) {
                $current = (int)filemtime($oldPath);
                if ($current !== $oldMtime) {
                    return ['ok' => false, 'error' => 'Content changed since dry-run. Please rerun preview.'];
                }
            }
        }

        $touched = [];
        foreach ($writable as $entry) {
            $oldPath = (string)($entry['old_path'] ?? '');
            $newPath = (string)($entry['new_path'] ?? '');
            if ($oldPath !== '') {
                $touched[$oldPath] = true;
            }
            if ($newPath !== '') {
                $touched[$newPath] = true;
            }
        }
        $touchedPaths = array_keys($touched);
        $originalExists = [];
        foreach ($touchedPaths as $path) {
            $originalExists[$path] = file_exists($path);
        }

        $backupToken = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $backupDir = $this->contentDir . '/.import-backups/' . $type . '-' . $backupToken;
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            return ['ok' => false, 'error' => 'Could not create import backup directory.'];
        }

        foreach ($touchedPaths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            [$identitySlug, $identityLang] = $this->contentIdentity($path);
            $this->revisions->baseline($type, $identitySlug, $identityLang, $path, ($this->currentUser)());
            $relative = ltrim(str_replace($this->contentDir, '', $path), '/');
            $target = $backupDir . '/' . $relative;
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }
            if (!copy($path, $target)) {
                return ['ok' => false, 'error' => 'Failed to create backup copy before import.'];
            }
        }

        $temps = [];
        foreach ($writable as $entry) {
            $newPath = (string)($entry['new_path'] ?? '');
            $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $body = (string)($entry['body'] ?? '');
            $dir = dirname($newPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $tmpPath = $dir . '/.' . basename($newPath) . '.tmp-import-' . bin2hex(random_bytes(4));
            if (!($this->mayUseRawHtml)()) {
                // An import must not be a way around the raw HTML rule: HTML already in the file being replaced stays.
                $allowedHtml = ($this->guard)()->storedFragments((string)($entry['old_path'] ?? ''));
                $body = ($this->guard)()->neutralize($body, $allowedHtml);
                if (isset($data['blocks']) && is_array($data['blocks'])) {
                    $data['blocks'] = ($this->guard)()->eachMarkdownField(array_values($data['blocks']), fn(string $value): string => ($this->guard)()->neutralize($value, $allowedHtml));
                }
            }
            $payload = $this->buildMarkdownPayload($data, $body);
            if (file_put_contents($tmpPath, $payload) === false) {
                foreach ($temps as $temp) {
                    @unlink($temp['tmp']);
                }
                $this->restoreImportBackup($backupDir, $touchedPaths, $originalExists);
                return ['ok' => false, 'error' => 'Failed while preparing import files.'];
            }
            $temps[] = [
                'tmp' => $tmpPath,
                'new' => $newPath,
                'old' => (string)($entry['old_path'] ?? ''),
            ];
        }

        foreach ($temps as $temp) {
            if (!@rename($temp['tmp'], $temp['new'])) {
                foreach ($temps as $cleanup) {
                    @unlink($cleanup['tmp']);
                }
                $this->restoreImportBackup($backupDir, $touchedPaths, $originalExists);
                return ['ok' => false, 'error' => 'Failed while writing imported content.'];
            }
        }

        foreach ($temps as $temp) {
            $oldPath = $temp['old'];
            $newPath = $temp['new'];
            if ($oldPath !== '' && $oldPath !== $newPath && file_exists($oldPath)) {
                @unlink($oldPath);
            }
            [$identitySlug, $identityLang] = $this->contentIdentity($newPath);
            $this->revisions->capture($type, $identitySlug, $identityLang, (string)file_get_contents($newPath), 'import', ($this->currentUser)());
        }

        return ['ok' => true];
    }

    /**
     * The slug and language a content file name stands for ("about.md" is the default language, "about.en.md" is English).
     *
     * @return array{0: string, 1: string}
     */
    private function contentIdentity(string $path): array
    {
        $name = basename($path, '.md');
        $available = (array)($this->settings()['languages']['available'] ?? []);
        if (preg_match('/^(.+)\.([a-z0-9-]+)$/', $name, $m) === 1 && in_array($m[2], $available, true)) {
            return [$m[1], $m[2]];
        }
        return [$name, (string)($this->settings()['languages']['default'] ?? 'en')];
    }

    /** @param string[] $paths @param array<string, bool> $originalExists */
    private function restoreImportBackup(string $backupDir, array $paths, array $originalExists): void
    {
        foreach ($paths as $path) {
            $relative = ltrim(str_replace($this->contentDir, '', $path), '/');
            $backupPath = $backupDir . '/' . $relative;
            $existed = (bool)($originalExists[$path] ?? false);
            if ($existed) {
                if (file_exists($backupPath)) {
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    @copy($backupPath, $path);
                }
            } else {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }
}
