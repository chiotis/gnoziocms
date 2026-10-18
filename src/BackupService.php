<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;
use Throwable;
use ZipArchive;

/**
 * Local backup archives: creation (with a checksum manifest and a consistent SQLite copy),
 * listing, retention, verification, and restore of data areas.
 *
 * Restore never touches code. It stages the selected areas next to the live ones, swaps them
 * with renames, and moves everything back if any swap fails.
 */
final class BackupService
{
    public const MANIFEST_NAME = 'faroscms-backup.json';
    public const MANIFEST_FORMAT = 1;
    private const DATABASE_ENTRY = 'storage/db/app.sqlite';
    private const EXCLUDED_PREFIXES = [
        '.git/',
        '.codex/',
        '.claude/',
        'node_modules/',
        'storage/cache/',
        'storage/backups/',
        'storage/restore/',
        'storage/updates/',
        // Responsive image variants are regenerated on demand.
        'public/uploads/_v/',
    ];

    public function __construct(
        private string $basePath,
        private SystemDatabase $database
    ) {
    }

    public function directory(): string
    {
        return $this->basePath . '/storage/backups';
    }

    public function ensureDirectory(): void
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /**
     * Areas a restore can bring back, keyed by id. Code (src/, vendor/, admin/, public/assets)
     * is deliberately absent: code comes from git or the update package, never from a backup.
     *
     * @return array<string, array{label: string, description: string, prefix: string}>
     */
    public function restoreScopes(): array
    {
        return [
            'content' => [
                'label' => 'Content',
                'description' => 'Pages, posts, projects, forms and submissions, menus, taxonomies, media metadata.',
                'prefix' => 'content/',
            ],
            'uploads' => [
                'label' => 'Uploads',
                'description' => 'Images and files in public/uploads.',
                'prefix' => 'public/uploads/',
            ],
            'form-uploads' => [
                'label' => 'Form files',
                'description' => 'Files visitors attached to form submissions (storage/form-uploads, private).',
                'prefix' => 'storage/form-uploads/',
            ],
            'custom' => [
                'label' => 'Site customizations',
                'description' => 'The custom/ folder: translation overrides from Admin > Translations, custom CSS/JS, and template overrides.',
                'prefix' => 'custom/',
            ],
            'database' => [
                'label' => 'System database',
                'description' => 'Settings, users, logs, notifications, and backup history.',
                'prefix' => self::DATABASE_ENTRY,
            ],
        ];
    }

    /** @return array{ok: bool, message: string, filename?: string, path?: string} */
    public function createFullSnapshot(string $siteSlug, array $meta = []): array
    {
        return $this->createSnapshot('full', $siteSlug, $meta);
    }

    /** @return array{ok: bool, message: string, filename?: string, path?: string} */
    public function createDatabaseSnapshot(string $siteSlug, array $meta = []): array
    {
        return $this->createSnapshot('database', $siteSlug, $meta);
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function list(): array
    {
        $this->ensureDirectory();
        $items = [];
        foreach (glob($this->directory() . '/*.zip') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $size = (int)(filesize($path) ?: 0);
            $mtime = (int)(filemtime($path) ?: 0);
            $manifest = $this->readManifest($path);
            $items[] = [
                'filename' => $name,
                'size' => $size,
                'size_human' => Format::bytes($size),
                'mtime' => $mtime,
                'created_at' => date('Y-m-d H:i', $mtime),
                'type' => (string)($manifest['type'] ?? (str_contains($name, '-database-backup-') ? 'database' : 'full')),
                'reason' => (string)($manifest['reason'] ?? ''),
                'version' => (string)($manifest['version'] ?? ''),
                'has_manifest' => $manifest !== null,
            ];
        }
        usort($items, fn(array $a, array $b): int => ((int)$b['mtime']) <=> ((int)$a['mtime']));
        return $items;
    }

    public function prune(int $keep): void
    {
        $keep = max(1, $keep);
        foreach (array_slice($this->list(), $keep) as $snapshot) {
            $path = $this->pathFor((string)$snapshot['filename']);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    /** @return array{ok: bool, message: string} */
    public function delete(string $filename): array
    {
        $path = $this->pathFor($filename);
        if ($path === null) {
            return ['ok' => false, 'message' => 'Backup file not found.'];
        }
        if (!@unlink($path)) {
            return ['ok' => false, 'message' => 'Could not delete backup file.'];
        }
        return ['ok' => true, 'message' => 'Backup deleted.'];
    }

    public function sanitizeFilename(string $value): string
    {
        $value = basename(trim($value));
        if ($value === '' || !preg_match('/^[a-z0-9._-]+\.zip$/i', $value)) {
            return '';
        }
        return $value;
    }

    public function pathFor(string $filename): ?string
    {
        $filename = $this->sanitizeFilename($filename);
        if ($filename === '') {
            return null;
        }
        $path = $this->directory() . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    /**
     * Checks archive structure and, when a manifest exists, every file's size and SHA-256.
     *
     * @return array{ok: bool, message: string, has_manifest: bool, manifest: array<string, mixed>|null, checked: int, errors: string[], areas: array<string, int>, uncompressed_bytes: int}
     */
    public function verify(string $filename): array
    {
        $result = [
            'ok' => false,
            'message' => '',
            'has_manifest' => false,
            'manifest' => null,
            'checked' => 0,
            'errors' => [],
            'areas' => [],
            'uncompressed_bytes' => 0,
        ];
        $path = $this->pathFor($filename);
        if ($path === null) {
            $result['message'] = 'Backup file not found.';
            return $result;
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $result['message'] = 'The archive could not be opened. It may be corrupt.';
            return $result;
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                $result['errors'][] = 'Unreadable entry #' . $i . '.';
                continue;
            }
            $name = (string)$stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (!$this->isSafeEntryName($name)) {
                $result['errors'][] = 'Unsafe path in archive: ' . $name;
                continue;
            }
            $entries[$name] = (int)$stat['size'];
            $result['uncompressed_bytes'] += (int)$stat['size'];
        }

        foreach ($this->restoreScopes() as $key => $scope) {
            $result['areas'][$key] = count(array_filter(
                array_keys($entries),
                fn(string $name): bool => $this->entryBelongsToScope($name, $key, $scope['prefix'])
            ));
        }

        $manifestRaw = $zip->getFromName(self::MANIFEST_NAME);
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (is_array($manifest) && is_array($manifest['files'] ?? null)) {
            $result['has_manifest'] = true;
            $result['manifest'] = $manifest;
            foreach ($manifest['files'] as $name => $info) {
                $name = (string)$name;
                if (!array_key_exists($name, $entries)) {
                    $result['errors'][] = 'Missing from archive: ' . $name;
                    continue;
                }
                if ((int)($info['size'] ?? -1) !== $entries[$name]) {
                    $result['errors'][] = 'Size mismatch: ' . $name;
                    continue;
                }
                $hash = $this->hashEntry($zip, $name);
                if ($hash === null || !hash_equals((string)($info['sha256'] ?? ''), $hash)) {
                    $result['errors'][] = 'Checksum mismatch: ' . $name;
                    continue;
                }
                $result['checked']++;
            }
            foreach (array_keys($entries) as $name) {
                if ($name !== self::MANIFEST_NAME && !array_key_exists($name, $manifest['files'])) {
                    $result['errors'][] = 'Unexpected file not listed in the manifest: ' . $name;
                }
            }
        }
        $zip->close();

        $result['errors'] = array_slice($result['errors'], 0, 25);
        if ($result['errors'] !== []) {
            $result['message'] = 'Verification failed: ' . count($result['errors']) . ' problem(s) found. ' . implode(' ', array_slice($result['errors'], 0, 3));
            return $result;
        }
        $result['ok'] = true;
        $result['message'] = $result['has_manifest']
            ? 'Archive verified: ' . $result['checked'] . ' files match their checksums.'
            : 'Archive structure is valid, but it has no checksum manifest (created before manifests existed).';
        return $result;
    }

    /**
     * Restores the chosen areas from an archive. The caller must verify the archive and take a
     * safety snapshot first.
     *
     * @param string[] $scopeKeys
     * @return array{ok: bool, message: string, restored: string[], skipped: string[], previous_dir?: string}
     */
    public function restore(string $filename, array $scopeKeys): array
    {
        $fail = static fn(string $message): array => ['ok' => false, 'message' => $message, 'restored' => [], 'skipped' => []];
        $path = $this->pathFor($filename);
        if ($path === null) {
            return $fail('Backup file not found.');
        }
        $scopes = array_intersect_key($this->restoreScopes(), array_flip($scopeKeys));
        if ($scopes === []) {
            return $fail('Select at least one area to restore.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $fail('The archive could not be opened.');
        }

        $runId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $work = $this->basePath . '/storage/restore/' . $runId;
        $staged = $work . '/staged';
        $previous = $work . '/previous';
        if (!@mkdir($staged, 0775, true) || !@mkdir($previous, 0775, true)) {
            $zip->close();
            return $fail('Could not create the restore working directory in storage/restore.');
        }

        // Stage: extract only the selected areas, entry by entry, never trusting archive paths.
        $counts = array_fill_keys(array_keys($scopes), 0);
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string)$stat['name'] : '';
            if ($name === '' || str_ends_with($name, '/') || $name === self::MANIFEST_NAME) {
                continue;
            }
            if (!$this->isSafeEntryName($name)) {
                $zip->close();
                $this->removeTree($work);
                return $fail('Unsafe path in archive: ' . $name);
            }
            $scopeKey = null;
            foreach ($scopes as $key => $scope) {
                if ($this->entryBelongsToScope($name, $key, $scope['prefix'], true)) {
                    $scopeKey = $key;
                    break;
                }
            }
            if ($scopeKey === null) {
                continue;
            }
            $bytes += (int)$stat['size'];
            if (!$this->extractEntry($zip, $name, $staged . '/' . $name)) {
                $zip->close();
                $this->removeTree($work);
                return $fail('Could not extract ' . $name . '.');
            }
            if ($name !== self::DATABASE_ENTRY . '-wal') {
                $counts[$scopeKey]++;
            }
        }
        $zip->close();

        // A host can switch the function off; then there is nothing to compare with.
        $free = function_exists('disk_free_space') ? @disk_free_space($this->basePath) : false;
        if ($free !== false && $bytes > $free) {
            $this->removeTree($work);
            return $fail('Not enough free disk space to restore this archive.');
        }

        $restorable = array_keys(array_filter($counts, static fn(int $count): bool => $count > 0));
        $skipped = array_values(array_diff(array_keys($scopes), $restorable));
        if ($restorable === []) {
            $this->removeTree($work);
            return $fail('The archive contains none of the selected areas.');
        }

        if (in_array('database', $restorable, true)) {
            $check = $this->prepareStagedDatabase($staged . '/' . self::DATABASE_ENTRY);
            if (!$check['ok']) {
                $this->removeTree($work);
                return $fail($check['message']);
            }
        }
        if (in_array('uploads', $restorable, true)) {
            // Archives made before the uploads .htaccess existed must not drop that protection.
            $liveHtaccess = $this->basePath . '/public/uploads/.htaccess';
            $stagedHtaccess = $staged . '/public/uploads/.htaccess';
            if (is_file($liveHtaccess) && !is_file($stagedHtaccess)) {
                @copy($liveHtaccess, $stagedHtaccess);
            }
        }

        if (in_array('form-uploads', $restorable, true)) {
            // The folder stays shut off for the web server, whatever the archive had.
            (new FormUploads($staged . '/storage/form-uploads'))->protect();
        }

        // Swap: move each live area aside, move the staged one in; undo everything on failure.
        $done = [];
        foreach ($restorable as $key) {
            $ok = $key === 'database'
                ? $this->swapDatabase($staged, $previous)
                : $this->swapDirectory(rtrim($scopes[$key]['prefix'], '/'), $staged, $previous);
            if (!$ok) {
                foreach (array_reverse($done) as $doneKey) {
                    $doneKey === 'database'
                        ? $this->unswapDatabase($previous)
                        : $this->unswapDirectory(rtrim($scopes[$doneKey]['prefix'], '/'), $previous, $work . '/failed');
                }
                return [
                    'ok' => false,
                    'message' => 'Restoring ' . $scopes[$key]['label'] . ' failed; all areas were put back as they were. Leftovers: storage/restore/' . $runId,
                    'restored' => [],
                    'skipped' => $skipped,
                ];
            }
            $done[] = $key;
        }

        $this->removeTree($staged);
        $this->pruneRestoreRuns(2);

        $labels = array_map(static fn(string $key): string => $scopes[$key]['label'], $done);
        return [
            'ok' => true,
            'message' => 'Restored ' . implode(', ', $labels) . ' from ' . basename($path) . '.',
            'restored' => $done,
            'skipped' => $skipped,
            'previous_dir' => 'storage/restore/' . $runId . '/previous',
        ];
    }

    /** @return array{ok: bool, message: string, filename?: string, path?: string} */
    private function createSnapshot(string $type, string $siteSlug, array $meta): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'message' => 'Zip extension is not available on this server.'];
        }
        $this->ensureDirectory();
        $siteSlug = $siteSlug !== '' ? $siteSlug : 'site';
        $filename = $siteSlug . ($type === 'database' ? '-database-backup-' : '-backup-') . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $finalPath = $this->directory() . '/' . $filename;
        // Build under a temporary name so a half-written archive never shows up in the list.
        $partialPath = $this->directory() . '/.' . $filename . '.partial';
        $dbCopy = $this->directory() . '/.' . $filename . '.sqlite';

        $zip = new ZipArchive();
        if ($zip->open($partialPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'message' => 'Could not create backup archive.'];
        }

        $files = [];
        if ($type === 'full') {
            $prefix = rtrim($this->basePath, '/') . '/';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $pathname = $fileInfo->getPathname();
                if (!str_starts_with($pathname, $prefix)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($pathname, strlen($prefix)));
                // The archive's own manifest takes this name; a file of the site that has it (a backup unzipped in the project) must not clash with it.
                if ($relative === self::MANIFEST_NAME || $this->shouldExclude($relative) || str_starts_with($relative, 'storage/db/app.sqlite')) {
                    continue;
                }
                if ($zip->addFile($pathname, $relative)) {
                    $files[$relative] = ['size' => (int)$fileInfo->getSize(), 'sha256' => (string)hash_file('sha256', $pathname)];
                }
            }
        }

        $databaseAdded = false;
        if ($this->database->isAvailable() || is_file($this->database->path())) {
            $copy = $this->copyDatabase($dbCopy);
            if ($copy['ok']) {
                $zip->addFile($dbCopy, self::DATABASE_ENTRY);
                $files[self::DATABASE_ENTRY] = ['size' => (int)(filesize($dbCopy) ?: 0), 'sha256' => (string)hash_file('sha256', $dbCopy)];
                $databaseAdded = true;
            } elseif ($type === 'database') {
                $zip->close();
                @unlink($partialPath);
                return ['ok' => false, 'message' => $copy['message']];
            }
        } elseif ($type === 'database') {
            $zip->close();
            @unlink($partialPath);
            return ['ok' => false, 'message' => 'System database file was not found.'];
        }

        if ($files === []) {
            $zip->close();
            @unlink($partialPath);
            @unlink($dbCopy);
            return ['ok' => false, 'message' => 'Backup archive is empty.'];
        }

        $manifest = [
            'format' => self::MANIFEST_FORMAT,
            'type' => $type,
            'reason' => (string)($meta['reason'] ?? 'manual'),
            'created_at' => gmdate('c'),
            'version' => (string)($meta['version'] ?? ''),
            'commit' => (string)($meta['commit'] ?? ''),
            'site' => $siteSlug,
            'database' => $databaseAdded,
            'files' => $files,
        ];
        $zip->addFromString(self::MANIFEST_NAME, (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $closed = $zip->close();
        @unlink($dbCopy);
        if (!$closed || !@rename($partialPath, $finalPath)) {
            @unlink($partialPath);
            return ['ok' => false, 'message' => 'Could not write backup archive.'];
        }

        return [
            'ok' => true,
            'message' => $type === 'database' ? 'Database backup created.' : 'Snapshot created.',
            'filename' => $filename,
            'path' => $finalPath,
        ];
    }

    /**
     * Copies the live SQLite database consistently. VACUUM INTO produces a clean single file even
     * while WAL mode has unflushed pages; a raw file copy could miss them or catch a torn write.
     *
     * @return array{ok: bool, message: string}
     */
    private function copyDatabase(string $target): array
    {
        @unlink($target);
        if ($this->database->isAvailable()) {
            try {
                $stmt = $this->database->connection()->prepare('VACUUM INTO :target');
                $stmt->execute(['target' => $target]);
                if (is_file($target)) {
                    return ['ok' => true, 'message' => 'Database copied.'];
                }
            } catch (Throwable) {
                // Older SQLite without VACUUM INTO: fall back to checkpoint + copy below.
            }
            try {
                $this->database->connection()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (Throwable) {
            }
        }
        if (is_file($this->database->path()) && @copy($this->database->path(), $target)) {
            return ['ok' => true, 'message' => 'Database copied.'];
        }
        return ['ok' => false, 'message' => 'Could not copy the system database.'];
    }

    /** @return array{ok: bool, message: string} */
    private function prepareStagedDatabase(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'The archive does not contain the system database.'];
        }
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            // Folds a WAL file from older raw-copy archives into the main file.
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
            $hasMeta = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'system_meta'")->fetchColumn();
            $pdo = null;
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The database in the archive could not be opened: ' . $e->getMessage()];
        }
        @unlink($path . '-wal');
        @unlink($path . '-shm');
        if (strtolower($integrity) !== 'ok') {
            return ['ok' => false, 'message' => 'The database in the archive failed its integrity check.'];
        }
        if (!$hasMeta) {
            return ['ok' => false, 'message' => 'The archive database is not a GnozioCMS system database.'];
        }
        return ['ok' => true, 'message' => 'Database is valid.'];
    }

    private function swapDirectory(string $relative, string $staged, string $previous): bool
    {
        $live = $this->basePath . '/' . $relative;
        $incoming = $staged . '/' . $relative;
        $aside = $previous . '/' . $relative;
        if (!is_dir($incoming)) {
            return false;
        }
        if (!is_dir(dirname($aside)) && !@mkdir(dirname($aside), 0775, true)) {
            return false;
        }
        if (is_dir($live)) {
            if (!@rename($live, $aside)) {
                return false;
            }
        } elseif (!@mkdir($aside, 0775, true)) {
            // An empty stand-in lets a rollback remove an area that did not exist before.
            return false;
        }
        if (!is_dir(dirname($live))) {
            @mkdir(dirname($live), 0775, true);
        }
        if (!@rename($incoming, $live)) {
            if (is_dir($aside)) {
                @rename($aside, $live);
            }
            return false;
        }
        return true;
    }

    private function unswapDirectory(string $relative, string $previous, string $failed): void
    {
        $live = $this->basePath . '/' . $relative;
        $aside = $previous . '/' . $relative;
        if (!is_dir($aside)) {
            return;
        }
        // Keep the half-restored copy inside the run directory for inspection, not in the site root.
        $discard = $failed . '/' . $relative;
        if (is_dir($live)) {
            @mkdir(dirname($discard), 0775, true);
            @rename($live, $discard);
        }
        @rename($aside, $live);
    }

    private function swapDatabase(string $staged, string $previous): bool
    {
        $live = $this->database->path();
        $incoming = $staged . '/' . self::DATABASE_ENTRY;
        $aside = $previous . '/' . self::DATABASE_ENTRY;
        if (!is_file($incoming) || (!is_dir(dirname($aside)) && !@mkdir(dirname($aside), 0775, true))) {
            return false;
        }

        $this->database->close();
        if (is_file($live) && !@rename($live, $aside)) {
            $this->database->initialize();
            return false;
        }
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($live . $suffix)) {
                @rename($live . $suffix, $aside . $suffix);
            }
        }
        if (!@rename($incoming, $live)) {
            @rename($aside, $live);
            $this->database->initialize();
            return false;
        }
        $this->database->initialize();
        return $this->database->isAvailable();
    }

    private function unswapDatabase(string $previous): void
    {
        $live = $this->database->path();
        $aside = $previous . '/' . self::DATABASE_ENTRY;
        if (!is_file($aside)) {
            return;
        }
        $this->database->close();
        @unlink($live);
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($live . $suffix);
            if (is_file($aside . $suffix)) {
                @rename($aside . $suffix, $live . $suffix);
            }
        }
        @rename($aside, $live);
        $this->database->initialize();
    }

    private function entryBelongsToScope(string $name, string $key, string $prefix, bool $includeWal = false): bool
    {
        if ($key === 'database') {
            return $name === self::DATABASE_ENTRY || ($includeWal && $name === self::DATABASE_ENTRY . '-wal');
        }
        return str_starts_with($name, $prefix);
    }

    private function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[a-z]:/i', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }
        return true;
    }

    private function extractEntry(ZipArchive $zip, string $name, string $target): bool
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }
        $in = $zip->getStream($name);
        if ($in === false) {
            return false;
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            return false;
        }
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        return $copied !== false;
    }

    private function hashEntry(ZipArchive $zip, string $name): ?string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return null;
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        return hash_final($context);
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $path): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        $raw = $zip->getFromName(self::MANIFEST_NAME);
        $zip->close();
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        unset($data['files']);
        return $data;
    }

    private function shouldExclude(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || basename($relative) === '.DS_Store') {
            return true;
        }
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function pruneRestoreRuns(int $keep): void
    {
        $runs = glob($this->basePath . '/storage/restore/*', GLOB_ONLYDIR) ?: [];
        rsort($runs);
        foreach (array_slice($runs, $keep) as $dir) {
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
