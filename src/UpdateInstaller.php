<?php

declare(strict_types=1);

namespace GnozioCMS;

use ZipArchive;

/**
 * Installs a new version of the code from a release package, and puts the old one back if the new one does not start.
 *
 * Only code is replaced: src/, admin/, vendor/, the themes the package holds, public/assets and a few single files.
 * content/, custom/, public/uploads/ and storage/ are not on the list, so nothing in a package can touch them (a
 * package with a file outside the list is refused as a whole). The steps are: check the machine can do it, download
 * the package, check its size and SHA-256, unpack it beside the live code, close the public site, keep a copy of the
 * database, move each live path aside and the new one in (undone in reverse if one fails), and ask the site, in a
 * request of its own, whether the new version works. If it does not, everything is put back, and the database too when
 * the new version had changed its structure. The old code stays in storage/updates/<version>/previous for a manual
 * roll back. Downloading and the request that checks the site are handed in, so they can be replaced in tests.
 */
final class UpdateInstaller
{
    /** Folders replaced as a whole. */
    public const DIRECTORIES = ['src', 'admin', 'vendor', 'starter', 'public/assets'];
    /** Single files, VERSION last so a half-done swap never says it is the new version. */
    public const FILES = ['public/index.php', 'public/.htaccess', 'public/uploads/.htaccess', 'custom/README.md', 'scripts/use-starter.php', 'CHANGELOG.md', 'README.md', 'LICENSE', 'update.md', 'composer.json', 'composer.lock', 'VERSION'];
    private const MAX_ENTRIES = 20000;
    private const MAX_UNPACKED_BYTES = 314572800;
    private const KEEP_RUNS = 2;

    /**
     * @param \Closure(string, string, int): ?string $download fetches a URL into a file, giving up past that many bytes: url, destination, limit; an error message, or null
     * @param \Closure(string, string): array{reached: bool, ok: bool, complete?: bool, message: string} $health asks the site, in a request of its own, whether this version works: the token that gets past the maintenance page, the version expected
     * @param \Closure(string, string, array<string, mixed>): void $log level, message, context
     */
    public function __construct(
        private string $basePath,
        private SystemDatabase $database,
        private MaintenanceMode $maintenance,
        private \Closure $download,
        private \Closure $health,
        private \Closure $log
    ) {
    }

    /** Whether a path inside a package is one an update may write. */
    public static function isAllowedPath(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[a-z]:/i', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                return false;
            }
        }
        if (in_array($name, self::FILES, true)) {
            return true;
        }
        foreach (self::DIRECTORIES as $directory) {
            if (str_starts_with($name, $directory . '/')) {
                return true;
            }
        }
        return (bool)preg_match('#^themes/[A-Za-z0-9_-]+/.+#', $name);
    }

    /**
     * What this machine needs to be able to install the package.
     *
     * @param array{version: string, min_php: string, size: int} $manifest
     * @return array<int, array{label: string, ok: bool, message: string}>
     */
    public function preflight(array $manifest, string $currentVersion): array
    {
        $checks = [];
        $add = static function (string $label, bool $ok, string $message) use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'message' => $message];
        };
        $newer = version_compare($manifest['version'], $currentVersion, '>');
        $add('A newer version', $newer, $newer ? $currentVersion . ' to ' . $manifest['version'] : 'This is ' . $currentVersion . ' already.');
        $add('PHP version', version_compare(PHP_VERSION, $manifest['min_php'], '>='), 'PHP ' . PHP_VERSION . ', the package needs ' . $manifest['min_php'] . ' or newer.');
        $add('ZIP support', class_exists(ZipArchive::class), 'The ZipArchive extension is needed to unpack the package.');
        $unwritable = [];
        foreach (['', 'public', 'themes', 'custom', 'public/uploads'] as $folder) {
            $path = $this->basePath . ($folder === '' ? '' : '/' . $folder);
            if (is_dir($path) && !is_writable($path)) {
                $unwritable[] = $folder === '' ? '(the site folder)' : $folder . '/';
            }
        }
        $add('Code folders writable', $unwritable === [], $unwritable === [] ? 'The code can be replaced.' : 'Not writable: ' . implode(', ', $unwritable));
        $updates = $this->basePath . '/storage/updates';
        $add('Working folder', (is_dir($updates) ? is_writable($updates) : (is_dir($this->basePath . '/storage') && is_writable($this->basePath . '/storage'))), 'storage/updates must be writable.');
        // A host can switch off any function; a disabled one is not there to call, so each optional one is asked about first.
        $free = function_exists('disk_free_space') ? @disk_free_space($this->basePath) : false;
        $add('Disk space', $free === false || $free >= 3 * $manifest['size'], 'At least three times the size of the package must be free.');
        $add('No update running', !$this->isLocked(), 'Another update is already in progress.');
        return $checks;
    }

    /**
     * Installs the package.
     *
     * @param array{version: string, min_php: string, package_url: string, sha256: string, size: int, requires_backup: bool} $manifest
     * @return array{status: string, step: string, message: string, from: string, to: string} status is installed, unverified (installed, but the check could not reach the site), failed (nothing changed) or rolled_back
     */
    public function install(array $manifest, string $currentVersion, bool $backupReady): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        // What this request remembers about files may be older than the folders as they are now.
        clearstatcache(true);
        $to = $manifest['version'];
        $result = static fn(string $status, string $step, string $message): array => ['status' => $status, 'step' => $step, 'message' => $message, 'from' => $currentVersion, 'to' => $to];

        foreach ($this->preflight($manifest, $currentVersion) as $check) {
            if (!$check['ok'] && $check['label'] !== 'No update running') {
                return $result('failed', 'preflight', $check['label'] . ': ' . $check['message']);
            }
        }
        if ($manifest['requires_backup'] && !$backupReady) {
            return $result('failed', 'backup', 'A verified backup made today, on this version, is needed first.');
        }
        $lock = $this->lock();
        if ($lock === null) {
            return $result('failed', 'preflight', 'Another update is already in progress.');
        }

        $run = $this->basePath . '/storage/updates/' . $to;
        $staged = $run . '/staged';
        $previous = $run . '/previous';
        $swapped = [];
        $closed = false;
        try {
            self::removeTree($run);
            if (!@mkdir($run, 0775, true) && !is_dir($run)) {
                return $result('failed', 'preflight', 'The working folder could not be made.');
            }
            ($this->log)('info', 'Downloading the package.', ['version' => $to, 'url' => $manifest['package_url']]);
            $error = ($this->download)($manifest['package_url'], $run . '/package.zip', $manifest['size']);
            if ($error !== null) {
                return $this->fail($run, $result('failed', 'download', 'The package could not be downloaded: ' . $error));
            }
            if (filesize($run . '/package.zip') !== $manifest['size'] || !hash_equals($manifest['sha256'], (string)hash_file('sha256', $run . '/package.zip'))) {
                return $this->fail($run, $result('failed', 'verify', 'The package is not the one that was published (its size or checksum is different). Nothing was changed.'));
            }
            $error = $this->unpack($run . '/package.zip', $staged, $to);
            if ($error !== null) {
                return $this->fail($run, $result('failed', 'unpack', $error));
            }
            $units = $this->units($staged);
            $error = $this->copyDatabase($run . '/database/app.sqlite');
            if ($error !== null) {
                return $this->fail($run, $result('failed', 'database', $error));
            }
            $migrationsBefore = $this->migrationCount();

            $token = $this->maintenance->enable($to);
            $closed = true;
            $error = $this->swap($units, $staged, $previous, $swapped);
            if ($error !== null) {
                $this->unswap($swapped, $previous, $run . '/failed');
                $this->maintenance->disable();
                return $this->fail($run, $result('failed', 'swap', $error . ' Everything was put back.'));
            }
            file_put_contents($run . '/report.json', json_encode(['from' => $currentVersion, 'to' => $to, 'at' => gmdate('c'), 'units' => $swapped, 'migrations_before' => $migrationsBefore, 'database' => 'database/app.sqlite'], JSON_PRETTY_PRINT));
            $stale = $this->clearCaches();

            $health = ($this->health)($token, $to);
            if ($health['reached'] && !$health['ok']) {
                ($this->log)('error', 'The new version did not start, so the old one is being put back.', ['version' => $to, 'check' => $health['message']]);
                $this->unswap($swapped, $previous, $run . '/failed');
                $this->restoreDatabaseIfChanged($run . '/database/app.sqlite', $migrationsBefore);
                $this->clearCaches();
                $this->maintenance->disable();
                self::removeTree($run);
                return $result('rolled_back', 'health', 'The new version did not pass the check (' . $health['message'] . '), so ' . $currentVersion . ' was put back. Nothing of the site was lost.');
            }
            $this->maintenance->disable();
            $closed = false;
            self::removeTree($staged);
            @unlink($run . '/package.zip');
            $this->prune($to);
            if ($stale !== '') {
                return $result('unverified', 'health', 'Version ' . $to . ' is installed. ' . $stale);
            }
            if (!$health['reached'] || !($health['complete'] ?? true)) {
                return $result('unverified', 'health', 'Version ' . $to . ' is installed, but the site could not be asked whether it works (' . $health['message'] . '). Open the site and the admin now; if something is wrong, roll back from this page.');
            }
            return $result('installed', 'done', 'Version ' . $to . ' is installed. ' . $health['message'] . '.');
        } catch (\Throwable $e) {
            if ($swapped !== []) {
                $this->unswap($swapped, $previous, $run . '/failed');
                $this->clearCaches();
            }
            if ($closed) {
                $this->maintenance->disable();
            }
            return $this->fail($run, $result('failed', 'error', 'The update stopped (' . $e->getMessage() . ')' . ($swapped !== [] ? ' and the old version was put back.' : '. Nothing was changed.')));
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * Puts the code of the version before back, from what the last install kept. The database is left as it is (it
     * has been used since): if the old version cannot work with it, the backup taken before the update is the way back.
     *
     * @return array{status: string, step: string, message: string, from: string, to: string}
     */
    public function rollback(string $version, string $currentVersion): array
    {
        $run = $this->basePath . '/storage/updates/' . $version;
        $report = json_decode((string)@file_get_contents($run . '/report.json'), true);
        $result = static fn(string $status, string $message): array => ['status' => $status, 'step' => 'rollback', 'message' => $message, 'from' => $currentVersion, 'to' => (string)($report['from'] ?? '')];
        if (!is_array($report) || !is_dir($run . '/previous') || !is_array($report['units'] ?? null)) {
            return $result('failed', 'What the update kept is not there any more.');
        }
        $lock = $this->lock();
        if ($lock === null) {
            return $result('failed', 'An update is in progress.');
        }
        try {
            $this->maintenance->enable((string)$report['from']);
            $this->unswap($report['units'], $run . '/previous', $run . '/failed');
            $this->clearCaches();
            $this->maintenance->disable();
            self::removeTree($run);
            ($this->log)('warning', 'The earlier version was put back.', ['from' => $currentVersion, 'to' => $report['from']]);
            return $result('rolled_back', 'Version ' . $report['from'] . ' is back. The pages, media and settings were not touched.');
        } catch (\Throwable $e) {
            $this->maintenance->disable();
            return $result('failed', 'The earlier version could not be put back (' . $e->getMessage() . ').');
        } finally {
            $this->unlock($lock);
        }
    }

    /** The version an install left a way back to, if what it kept is still there. */
    public function rollbackTarget(string $version): ?string
    {
        $run = $this->basePath . '/storage/updates/' . $version;
        $report = json_decode((string)@file_get_contents($run . '/report.json'), true);
        return is_array($report) && is_dir($run . '/previous') ? (string)($report['from'] ?? '') ?: null : null;
    }

    /** @param array{status: string, step: string, message: string, from: string, to: string} $result */
    private function fail(string $run, array $result): array
    {
        self::removeTree($run);
        return $result;
    }

    /** @return list<array{path: string, kind: string}> what the package replaces, in the order it is done */
    private function units(string $staged): array
    {
        $units = [];
        foreach (self::DIRECTORIES as $directory) {
            if (is_dir($staged . '/' . $directory)) {
                $units[] = ['path' => $directory, 'kind' => 'dir'];
            }
        }
        foreach (glob($staged . '/themes/*', GLOB_ONLYDIR) ?: [] as $theme) {
            $units[] = ['path' => 'themes/' . basename($theme), 'kind' => 'dir'];
        }
        foreach (self::FILES as $file) {
            if (is_file($staged . '/' . $file)) {
                $units[] = ['path' => $file, 'kind' => 'file'];
            }
        }
        return $units;
    }

    /** Unpacks the package, after checking every entry in it. An error message, or null. */
    private function unpack(string $zipPath, string $staged, string $version): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return 'The package is not a valid ZIP file.';
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                return 'The package has an unexpected number of files.';
            }
            $total = 0;
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string)$stat['name'] : '';
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                if (!self::isAllowedPath($name)) {
                    return 'The package holds a file an update may not write (' . $name . '), so it was refused.';
                }
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    return 'The package holds a link (' . $name . '), so it was refused.';
                }
                $total += (int)$stat['size'];
                $entries[] = [$i, $name, (int)$stat['size']];
            }
            if ($total > self::MAX_UNPACKED_BYTES) {
                return 'The package is larger than an update may be once unpacked.';
            }
            foreach ($entries as [$index, $name, $size]) {
                $target = $staged . '/' . $name;
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true)) {
                    return 'Could not unpack ' . $name . '.';
                }
                $in = $zip->getStream($zip->getNameIndex($index));
                $out = $in !== false ? @fopen($target, 'wb') : false;
                if ($in === false || $out === false) {
                    return 'Could not unpack ' . $name . '.';
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                if ($copied !== $size) {
                    return 'Could not unpack ' . $name . ' completely.';
                }
            }
        } finally {
            $zip->close();
        }
        if (trim((string)@file_get_contents($staged . '/VERSION')) !== $version) {
            return 'The package is not version ' . $version . '.';
        }
        foreach (['src/App.php', 'public/index.php', 'vendor/autoload.php', 'themes/default/theme.yaml'] as $required) {
            if (!is_file($staged . '/' . $required)) {
                return 'The package is incomplete (' . $required . ' is missing).';
            }
        }
        return null;
    }

    /**
     * Moves each live path aside and the new one in. On the first failure the caller undoes what was done.
     *
     * @param list<array{path: string, kind: string}> $units
     * @param list<array{path: string, existed: bool}> $done filled with what was swapped
     */
    private function swap(array $units, string $staged, string $previous, array &$done): ?string
    {
        foreach ($units as $unit) {
            $live = $this->basePath . '/' . $unit['path'];
            $aside = $previous . '/' . $unit['path'];
            $existed = file_exists($live);
            if (!is_dir(dirname($aside)) && !@mkdir(dirname($aside), 0775, true)) {
                return 'Could not prepare a place for the old ' . $unit['path'] . '.';
            }
            if ($existed && !@rename($live, $aside)) {
                return 'Could not move the old ' . $unit['path'] . ' aside.';
            }
            if (!is_dir(dirname($live))) {
                @mkdir(dirname($live), 0775, true);
            }
            if (!@rename($staged . '/' . $unit['path'], $live)) {
                if ($existed) {
                    @rename($aside, $live);
                }
                return 'Could not put the new ' . $unit['path'] . ' in place.';
            }
            $done[] = ['path' => $unit['path'], 'existed' => $existed];
        }
        return null;
    }

    /** @param array<int, array{path: string, existed: bool}> $done */
    private function unswap(array $done, string $previous, string $failed): void
    {
        foreach (array_reverse($done) as $unit) {
            $live = $this->basePath . '/' . $unit['path'];
            $aside = $previous . '/' . $unit['path'];
            if (file_exists($live)) {
                $discard = $failed . '/' . $unit['path'];
                @mkdir(dirname($discard), 0775, true);
                if (!@rename($live, $discard)) {
                    self::removeTree($live);
                }
            }
            if (!empty($unit['existed']) && file_exists($aside)) {
                @rename($aside, $live);
            }
        }
    }

    /** A copy of the database to go back to: SQLite's own `VACUUM INTO` where it can, else the file after its log is folded in. */
    private function copyDatabase(string $target): ?string
    {
        clearstatcache(true);
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true)) {
            return 'Could not make a copy of the database.';
        }
        $why = '';
        try {
            $pdo = $this->database->connection();
            $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
            if (is_file($target) && filesize($target) > 0) {
                return null;
            }
        } catch (\Throwable $e) {
            // Older SQLite versions do not have VACUUM INTO; the copy below does not need it.
            $why = $e->getMessage();
            @unlink($target);
        }
        try {
            $this->database->connection()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable) {
        }
        if (!@copy($this->database->path(), $target) || filesize($target) < 1) {
            $problem = error_get_last()['message'] ?? '';
            @unlink($target);
            return 'Could not make a copy of the database (' . trim($why . ' ' . $problem) . '; the database is ' . (is_file($this->database->path()) ? 'there' : 'not there') . ' at ' . $this->database->path() . ' (folder: ' . implode(',', array_map('basename', glob(dirname($this->database->path()) . '/*') ?: [])) . '; opened: ' . ($this->database->isAvailable() ? 'yes' : 'no: ' . (string)$this->database->lastError()) . '), the folder for the copy is ' . (is_dir(dirname($target)) ? 'there' : 'not there') . ').';
        }
        return null;
    }

    private function migrationCount(): int
    {
        try {
            return (int)$this->database->connection()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The new version may have changed the structure of the database; if it did, the old version could not read it. */
    private function restoreDatabaseIfChanged(string $copy, int $migrationsBefore): void
    {
        if ($this->migrationCount() === $migrationsBefore || !is_file($copy)) {
            return;
        }
        $live = $this->database->path();
        $this->database->close();
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($live . $suffix);
        }
        @copy($copy, $live);
        $this->database->initialize();
        ($this->log)('warning', 'The database was put back to what it was before the update.', []);
    }

    /**
     * Makes the running site look at the code as it is now. OPcache keeps compiled files and looks at them again only every
     * few seconds, so after the swap it is reset; when the host does not allow that, the install waits for it to look again.
     * Returns a note when the new code may still not be running (OPcache never looks at the files again), else an empty text.
     */
    private function clearCaches(): string
    {
        clearstatcache(true);
        $active = function_exists('ini_get') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN) && extension_loaded('Zend OPcache');
        if (!$active) {
            return '';
        }
        if (function_exists('opcache_reset') && @opcache_reset()) {
            return '';
        }
        if (!filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN)) {
            return 'PHP is set to keep compiled code and never look at the files again, so the new version starts only when PHP is restarted (or its cache cleared).';
        }
        // It looks at the files again after this many seconds; the check of the new version must come after that.
        sleep(max(0, (int)ini_get('opcache.revalidate_freq')) + 1);
        return '';
    }

    /** Keeps what the last few updates left to roll back from, and removes the rest. */
    private function prune(string $current): void
    {
        $runs = glob($this->basePath . '/storage/updates/*', GLOB_ONLYDIR) ?: [];
        usort($runs, static fn(string $a, string $b): int => version_compare(basename($b), basename($a)));
        foreach (array_slice($runs, self::KEEP_RUNS) as $old) {
            if (basename($old) !== $current) {
                self::removeTree($old);
            }
        }
    }

    private function lockPath(): string
    {
        return $this->basePath . '/storage/updates/install.lock';
    }

    private function isLocked(): bool
    {
        if (!is_file($this->lockPath())) {
            return false;
        }
        $handle = @fopen($this->lockPath(), 'c');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    /** @return resource|null */
    private function lock()
    {
        if (!is_dir(dirname($this->lockPath()))) {
            @mkdir(dirname($this->lockPath()), 0775, true);
        }
        $handle = @fopen($this->lockPath(), 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
