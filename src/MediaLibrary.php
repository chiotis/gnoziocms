<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Media library: uploaded files live in public/uploads/media (legacy images/ and files/ are
 * adopted), and each item has YAML metadata in content/media/<id>.yaml.
 */
final class MediaLibrary
{
    /**
     * The kinds of file a site can allow (Settings > Limits). Uploads land inside the public web root, so every
     * extension here is an inert one; a site can only narrow this list, never widen it.
     */
    public const UPLOAD_GROUPS = [
        'images' => ['label' => 'Images', 'help' => 'JPG, PNG, GIF, WebP, AVIF, BMP, ICO', 'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico']],
        'svg' => ['label' => 'SVG drawings', 'help' => 'Checked for scripts and outside content before they are kept', 'extensions' => ['svg']],
        'documents' => ['label' => 'Documents', 'help' => 'PDF, Word, Excel, PowerPoint, OpenDocument, RTF, TXT, CSV, Markdown', 'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'md']],
        'archives' => ['label' => 'Archives', 'help' => 'ZIP', 'extensions' => ['zip']],
        'maps' => ['label' => 'Route files', 'help' => 'GPX, KML and GeoJSON, read as a route before they are kept', 'extensions' => ['gpx', 'kml', 'geojson']],
        'audio' => ['label' => 'Audio', 'help' => 'MP3, WAV, OGG, M4A', 'extensions' => ['mp3', 'wav', 'ogg', 'm4a']],
        'video' => ['label' => 'Video', 'help' => 'MP4, WebM, MOV', 'extensions' => ['mp4', 'webm', 'mov']],
    ];

    /** @var string[]|null the groups this site allows; null means all of them */
    private ?array $allowedGroups = null;

    public function __construct(
        private string $contentDir,
        private string $uploadsDir
    ) {
    }

    /** Limits uploads to some of the groups in UPLOAD_GROUPS (unknown names are ignored; none valid means all). @param string[] $groups */
    public function restrictTo(array $groups): void
    {
        $known = array_values(array_filter($groups, static fn($group): bool => is_string($group) && isset(self::UPLOAD_GROUPS[$group])));
        $this->allowedGroups = $known === [] ? null : $known;
    }

    /** @return string[] the groups uploads are accepted in */
    public function allowedGroups(): array
    {
        return $this->allowedGroups ?? array_keys(self::UPLOAD_GROUPS);
    }

    /** @return string[] file extensions accepted now, without the dot */
    public function allowedExtensions(): array
    {
        $extensions = [];
        foreach ($this->allowedGroups() as $group) {
            $extensions = array_merge($extensions, self::UPLOAD_GROUPS[$group]['extensions']);
        }
        return $extensions;
    }

    /** @return string[] */
    public function typeOptions(): array
    {
        return ['all', 'image', 'video', 'audio', 'document', 'archive', 'track', 'other'];
    }

    private function metaDir(): string
    {
        return $this->contentDir . '/media';
    }

    private function uploadsRootDir(): string
    {
        return $this->uploadsDir;
    }

    private function libraryDir(): string
    {
        return $this->uploadsRootDir() . '/media';
    }

    public function ensureDirectories(): void
    {
        foreach ([$this->metaDir(), $this->uploadsRootDir(), $this->libraryDir()] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    private function metaPath(string $id): string
    {
        return $this->metaDir() . '/' . $id . '.yaml';
    }

    public function sanitizeId(string $id): string
    {
        $id = strtolower(trim($id));
        return preg_match('/^[a-f0-9]{16}$/', $id) === 1 ? $id : '';
    }

    public function sanitizeTag(string $tag): string
    {
        $tags = $this->normalizeTags($tag);
        return $tags[0] ?? '';
    }

    /** @return array<int, string> */
    public function normalizeTags(mixed $tags): array
    {
        if (is_array($tags)) {
            $raw = [];
            foreach ($tags as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $raw[] = $value;
            }
            $tags = implode(',', $raw);
        }

        $csv = trim((string)$tags);
        if ($csv === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', $csv) ?: [];
        $normalized = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $part = $this->lower($part);
            $part = preg_replace('/[^\p{L}\p{N}_\- ]/u', '', $part) ?? '';
            $part = trim(preg_replace('/\s+/', ' ', $part) ?? '');
            if ($part === '') {
                continue;
            }
            $normalized[$part] = true;
        }
        $result = array_keys($normalized);
        sort($result);
        return $result;
    }

    private function sanitizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
        }
        $path = preg_replace('#/+#', '/', $path) ?? '';
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        if (preg_match('#^(images|media|files)/[^/]+$#u', $path) !== 1) {
            return '';
        }
        return $path;
    }

    /** @param array<string, mixed> $meta @return array<string, mixed>|null */
    private function normalizeMeta(array $meta, ?string $fallbackId = null): ?array
    {
        $id = $this->sanitizeId((string)($meta['id'] ?? ($fallbackId ?? '')));
        if ($id === '' && $fallbackId !== null) {
            $id = $this->sanitizeId($fallbackId);
        }
        if ($id === '') {
            return null;
        }

        $path = $this->sanitizeRelativePath((string)($meta['path'] ?? ''));
        if ($path === '') {
            $storedName = basename((string)($meta['stored_name'] ?? ''));
            if ($storedName !== '') {
                $path = $this->sanitizeRelativePath('media/' . $storedName);
            }
        }
        if ($path === '') {
            return null;
        }

        $absolutePath = $this->uploadsRootDir() . '/' . $path;
        $exists = is_file($absolutePath);
        $extension = strtolower((string)($meta['extension'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
        $mimeType = trim((string)($meta['mime_type'] ?? ''));
        if ($mimeType === '') {
            $mimeType = $this->detectMimeType($absolutePath, 'application/octet-stream');
        }
        $kind = trim((string)($meta['kind'] ?? ''));
        if ($kind === '') {
            $kind = $this->kindFor($mimeType, $extension);
        }

        $sizeBytes = (int)($meta['size_bytes'] ?? 0);
        if ($sizeBytes <= 0 && $exists) {
            $sizeBytes = (int)(filesize($absolutePath) ?: 0);
        }

        $fallbackTimestamp = $exists ? (int)(filemtime($absolutePath) ?: time()) : time();
        $createdAt = trim((string)($meta['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = gmdate('c', $fallbackTimestamp);
        }
        $updatedAt = trim((string)($meta['updated_at'] ?? ''));
        if ($updatedAt === '') {
            $updatedAt = $createdAt;
        }
        $createdTimestamp = strtotime($createdAt) ?: $fallbackTimestamp;
        $updatedTimestamp = strtotime($updatedAt) ?: $fallbackTimestamp;

        $tags = $this->normalizeTags($meta['tags'] ?? '');
        $originalName = trim((string)($meta['original_name'] ?? ''));
        if ($originalName === '') {
            $originalName = basename($path);
        }

        $url = '/uploads/' . $path;

        return [
            'id' => $id,
            'path' => $path,
            'stored_name' => basename($path),
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'kind' => $kind,
            'size_bytes' => max(0, $sizeBytes),
            'size_human' => Format::bytes(max(0, $sizeBytes)),
            'tags' => $tags,
            'tags_csv' => implode(', ', $tags),
            'alt' => trim((string)($meta['alt'] ?? '')),
            'uploaded_by' => trim((string)($meta['uploaded_by'] ?? '')),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'created_ts' => (int)$createdTimestamp,
            'updated_ts' => (int)$updatedTimestamp,
            'created_at_display' => date('Y-m-d H:i', (int)$createdTimestamp),
            'updated_at_display' => date('Y-m-d H:i', (int)$updatedTimestamp),
            'direct_url' => $url,
            'thumbnail_url' => $kind === 'image' ? $url : '',
            'exists' => $exists,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        $this->ensureDirectories();
        $items = [];
        foreach (glob($this->metaDir() . '/*.yaml') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $id = basename($path, '.yaml');
            $parsed = Yaml::parseFile($path) ?: [];
            if (!is_array($parsed)) {
                continue;
            }
            $item = $this->normalizeMeta($parsed, $id);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
        }

        $type = strtolower(trim((string)($filters['type'] ?? 'all')));
        if (!in_array($type, $this->typeOptions(), true)) {
            $type = 'all';
        }
        $tag = $this->sanitizeTag((string)($filters['tag'] ?? ''));
        $query = $this->lower(trim((string)($filters['q'] ?? '')));

        $items = array_values(array_filter($items, function (array $item) use ($type, $tag, $query): bool {
            if ($type !== 'all' && (string)($item['kind'] ?? 'other') !== $type) {
                return false;
            }
            if ($tag !== '') {
                $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
                if (!in_array($tag, $tags, true)) {
                    return false;
                }
            }
            if ($query !== '') {
                $haystack = $this->lower(
                    (string)($item['original_name'] ?? '') . ' ' .
                    (string)($item['mime_type'] ?? '') . ' ' .
                    (string)($item['tags_csv'] ?? '')
                );
                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }
            return true;
        }));

        usort($items, function (array $a, array $b): int {
            $aTs = (int)($a['created_ts'] ?? 0);
            $bTs = (int)($b['created_ts'] ?? 0);
            if ($aTs === $bTs) {
                return strcmp((string)($a['original_name'] ?? ''), (string)($b['original_name'] ?? ''));
            }
            return $bTs <=> $aTs;
        });

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $id = $this->sanitizeId($id);
        if ($id === '') {
            return null;
        }
        $path = $this->metaPath($id);
        if (!is_file($path)) {
            return null;
        }
        $parsed = Yaml::parseFile($path) ?: [];
        if (!is_array($parsed)) {
            return null;
        }
        return $this->normalizeMeta($parsed, $id);
    }

    /** @param array<string, mixed> $meta */
    private function saveMeta(array $meta): void
    {
        $id = $this->sanitizeId((string)($meta['id'] ?? ''));
        if ($id === '') {
            return;
        }
        $path = $this->sanitizeRelativePath((string)($meta['path'] ?? ''));
        if ($path === '') {
            return;
        }
        $tags = $this->normalizeTags($meta['tags'] ?? '');
        $payload = [
            'id' => $id,
            'path' => $path,
            'stored_name' => basename($path),
            'original_name' => trim((string)($meta['original_name'] ?? basename($path))),
            'extension' => strtolower((string)($meta['extension'] ?? pathinfo($path, PATHINFO_EXTENSION))),
            'mime_type' => trim((string)($meta['mime_type'] ?? 'application/octet-stream')),
            'kind' => trim((string)($meta['kind'] ?? 'other')),
            'size_bytes' => max(0, (int)($meta['size_bytes'] ?? 0)),
            'tags' => implode(',', $tags),
            'uploaded_by' => trim((string)($meta['uploaded_by'] ?? '')),
            'created_at' => trim((string)($meta['created_at'] ?? gmdate('c'))),
            'updated_at' => trim((string)($meta['updated_at'] ?? gmdate('c'))),
        ];
        // Default alternative text, used wherever the image is placed without its own alt.
        $alt = trim(preg_replace('/\s+/', ' ', (string)($meta['alt'] ?? '')) ?? '');
        if ($alt !== '') {
            $payload['alt'] = mb_substr($alt, 0, 300);
        }
        file_put_contents($this->metaPath($id), Yaml::dump($payload, 4, 2));
    }

    /** @return array<int, array<string, mixed>> */
    public function normalizeUploads(mixed $rawUpload): array
    {
        if (!is_array($rawUpload) || !array_key_exists('name', $rawUpload)) {
            return [];
        }

        if (is_array($rawUpload['name'] ?? null)) {
            $files = [];
            $count = count($rawUpload['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = [
                    'name' => (string)($rawUpload['name'][$i] ?? ''),
                    'type' => (string)($rawUpload['type'][$i] ?? ''),
                    'tmp_name' => (string)($rawUpload['tmp_name'][$i] ?? ''),
                    'error' => (int)($rawUpload['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($rawUpload['size'][$i] ?? 0),
                ];
            }
            return $files;
        }

        return [[
            'name' => (string)($rawUpload['name'] ?? ''),
            'type' => (string)($rawUpload['type'] ?? ''),
            'tmp_name' => (string)($rawUpload['tmp_name'] ?? ''),
            'error' => (int)($rawUpload['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($rawUpload['size'] ?? 0),
        ]];
    }

    /** @param array<string, mixed> $upload @return array<string, mixed> */
    public function upload(array $upload, string $tagsCsv = '', string $uploadedBy = '', int $maxBytes = 20971520, string $fixedId = '', string $alt = ''): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        $originalName = trim((string)($upload['name'] ?? ''));
        $sizeBytes = (int)($upload['size'] ?? 0);

        if ($tmpName === '' || !is_file($tmpName)) {
            throw new \RuntimeException('Uploaded file data is missing.');
        }
        if ($originalName === '') {
            throw new \RuntimeException('Uploaded file name is missing.');
        }
        if ($sizeBytes <= 0) {
            $sizeBytes = (int)(filesize($tmpName) ?: 0);
        }
        if ($sizeBytes <= 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }

        if ($maxBytes > 0 && $sizeBytes > $maxBytes) {
            throw new \RuntimeException('Uploaded file exceeds the maximum allowed size.');
        }

        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
        // Uploads land inside the public web root, so only inert file types are accepted.
        if (!in_array($extension, $this->allowedExtensions(), true)) {
            throw new \RuntimeException('This kind of file is not allowed on this site.');
        }
        $detectedMime = $this->detectMimeType($tmpName, '');
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'], true) && !str_starts_with($detectedMime, 'image/')) {
            throw new \RuntimeException('The uploaded file is not a valid image.');
        }
        if ($extension === 'svg' && !$this->isSafeSvg($tmpName)) {
            throw new \RuntimeException('SVG files with scripts or external content are not allowed.');
        }
        if (in_array($extension, GeoTrack::EXTENSIONS, true)) {
            // A route file is kept only when it can be read as one.
            if ($sizeBytes > GeoTrack::MAX_BYTES) {
                throw new \RuntimeException('Route files can be up to ' . (int)(GeoTrack::MAX_BYTES / 1_000_000) . ' MB.');
            }
            if (GeoTrack::parse((string)file_get_contents($tmpName), $extension) === null) {
                throw new \RuntimeException('This route file could not be read. It needs a line or a place, in GPX, KML or GeoJSON, without a DOCTYPE.');
            }
        }

        if ($fixedId !== '') {
            $id = $this->sanitizeId($fixedId);
            if ($id === '' || is_file($this->metaPath($id))) {
                throw new \RuntimeException('That media id is not available.');
            }
        } else {
            do {
                $id = bin2hex(random_bytes(8));
                $id = $this->sanitizeId($id);
            } while ($id === '' || is_file($this->metaPath($id)));
        }

        $storedName = $id . ($extension !== '' ? '.' . $extension : '');
        $relativePath = 'media/' . $storedName;
        $absolutePath = $this->uploadsRootDir() . '/' . $relativePath;
        if (is_file($absolutePath)) {
            throw new \RuntimeException('Upload conflict.');
        }

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $absolutePath)
            : rename($tmpName, $absolutePath);
        if (!$moved) {
            throw new \RuntimeException('Could not save the uploaded file.');
        }

        $mimeType = $this->detectMimeType($absolutePath, (string)($upload['type'] ?? 'application/octet-stream'));
        $kind = $this->kindFor($mimeType, $extension);
        $now = gmdate('c');

        $meta = [
            'id' => $id,
            'path' => $relativePath,
            'stored_name' => $storedName,
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'kind' => $kind,
            'size_bytes' => (int)(filesize($absolutePath) ?: $sizeBytes),
            'tags' => $this->normalizeTags($tagsCsv),
            'uploaded_by' => $uploadedBy,
            'created_at' => $now,
            'updated_at' => $now,
            'alt' => $alt,
        ];
        $this->saveMeta($meta);
        return $this->find($id) ?? $meta;
    }

    /**
     * Brings in a file that is already on disk (downloaded from another site, say) under an id chosen by the caller, so
     * bringing it in again finds it instead of keeping a second copy. Checked like an upload; the file is moved, not copied.
     *
     * @return array<string, mixed> the library entry
     */
    public function import(string $file, string $name, string $id, string $alt = '', string $by = '', int $maxBytes = 20971520): array
    {
        $id = $this->sanitizeId($id);
        if ($id === '') {
            throw new \RuntimeException('The media id must be 16 hexadecimal characters.');
        }
        $existing = $this->find($id);
        if ($existing !== null) {
            return $existing;
        }
        return $this->upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $file, 'name' => $name, 'size' => (int)(filesize($file) ?: 0)], '', $by, $maxBytes, $id, $alt);
    }

    private function isSafeSvg(string $path): bool
    {
        $content = (string)file_get_contents($path);
        if ($content === '') {
            return false;
        }
        $patterns = [
            '/<script\b/i',
            '/\son[a-z]+\s*=/i',
            '/javascript\s*:/i',
            '/<foreignObject\b/i',
            '/<!ENTITY/i',
            '/<(iframe|embed|object)\b/i',
            '/(xlink:)?href\s*=\s*["\']\s*(data|https?):/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        return true;
    }

    public function updateTags(string $id, string $tagsCsv, ?string $alt = null): bool
    {
        $item = $this->find($id);
        if ($item === null) {
            return false;
        }
        $item['tags'] = $this->normalizeTags($tagsCsv);
        if ($alt !== null) {
            $item['alt'] = $alt;
        }
        $item['updated_at'] = gmdate('c');
        $this->saveMeta($item);
        return true;
    }

    public function delete(string $id): bool
    {
        $item = $this->find($id);
        if ($item === null) {
            return false;
        }
        $relativePath = $this->sanitizeRelativePath((string)($item['path'] ?? ''));
        if ($relativePath !== '') {
            $absolutePath = $this->uploadsRootDir() . '/' . $relativePath;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            (new Images(dirname($this->uploadsRootDir())))->purge($relativePath);
        }
        $metaPath = $this->metaPath($id);
        if (is_file($metaPath)) {
            @unlink($metaPath);
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    public function findByFilename(string $filename): ?array
    {
        // Callers pass an already sanitized basename.
        $filename = basename($filename);
        if ($filename === '') {
            return null;
        }
        foreach ($this->list() as $item) {
            $base = basename((string)($item['path'] ?? ''));
            if ($base === $filename) {
                return $item;
            }
        }
        return null;
    }

    /** @return string[] */
    public function collectIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $id = $this->sanitizeId($value);
            if ($id === '') {
                continue;
            }
            $ids[$id] = true;
        }
        // An id made only of digits becomes a number as an array key; the callers want text.
        return array_map('strval', array_keys($ids));
    }

    /** @return string[] */
    public function availableTags(): array
    {
        $tags = [];
        foreach ($this->list() as $item) {
            foreach ((array)($item['tags'] ?? []) as $tag) {
                if (!is_string($tag) || $tag === '') {
                    continue;
                }
                $tags[$tag] = true;
            }
        }
        $result = array_keys($tags);
        sort($result);
        return $result;
    }

    public function migrateLegacyItems(): void
    {
        $this->ensureDirectories();
        $existing = [];
        foreach ($this->list() as $item) {
            $path = (string)($item['path'] ?? '');
            if ($path !== '') {
                $existing[$path] = true;
            }
        }

        $scanDirs = [
            'images' => $this->uploadsRootDir() . '/images',
            'media' => $this->uploadsRootDir() . '/media',
            'files' => $this->uploadsRootDir() . '/files',
        ];
        foreach ($scanDirs as $prefix => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $relativePath = $this->sanitizeRelativePath($prefix . '/' . basename($path));
                if ($relativePath === '' || isset($existing[$relativePath])) {
                    continue;
                }
                do {
                    $id = $this->sanitizeId(bin2hex(random_bytes(8)));
                } while ($id === '' || is_file($this->metaPath($id)));

                $mimeType = $this->detectMimeType($path, 'application/octet-stream');
                $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
                $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
                $kind = $prefix === 'images' ? 'image' : $this->kindFor($mimeType, $extension);
                $timestamp = (int)(filemtime($path) ?: time());
                $now = gmdate('c', $timestamp);
                $meta = [
                    'id' => $id,
                    'path' => $relativePath,
                    'stored_name' => basename($relativePath),
                    'original_name' => basename($relativePath),
                    'extension' => $extension,
                    'mime_type' => $mimeType,
                    'kind' => $kind,
                    'size_bytes' => (int)(filesize($path) ?: 0),
                    'tags' => '',
                    'uploaded_by' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $this->saveMeta($meta);
                $existing[$relativePath] = true;
            }
        }
    }

    private function detectMimeType(string $path, string $fallback = 'application/octet-stream'): string
    {
        if (!is_file($path)) {
            return $fallback;
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $value = finfo_file($finfo, $path);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }
        return $fallback;
    }

    private function kindFor(string $mimeType, string $extension): string
    {
        $mime = strtolower($mimeType);
        if (in_array($extension, GeoTrack::EXTENSIONS, true)) {
            return 'track';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'md', 'rtf'];
        $archiveExtensions = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
        if (in_array($extension, $archiveExtensions, true)) {
            return 'archive';
        }
        if (str_starts_with($mime, 'text/') || in_array($extension, $documentExtensions, true)) {
            return 'document';
        }
        return 'other';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds allowed size.',
            UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by a PHP extension.',
            default => 'Unknown upload error.',
        };
    }

    private function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}
