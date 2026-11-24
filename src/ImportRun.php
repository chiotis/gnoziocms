<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * One run of an import (see ImportRuns): the rows it wrote so far, the copies of what was there before, and whether it finished.
 * An importer calls write() for each file of the content folder (or before() and after() around its own writing), and skips a
 * row that isDone() when it was resumed.
 */
final class ImportRun
{
    /** Saving run.json after every row would be slow for thousands; it is saved at most this often (and at the end). */
    private const SAVE_EVERY = 1.0;

    private float $savedAt = 0.0;

    /** @param array<string, mixed> $data */
    public function __construct(private string $dir, private string $contentDir, private array $data)
    {
    }

    public function id(): string
    {
        return (string)$this->data['id'];
    }

    /** Whether this run was taken up again after it stopped. */
    public function isResumed(): bool
    {
        return (int)($this->data['resumed'] ?? 0) > 0;
    }

    /** Whether a row was written in this run already (a resumed run skips it). */
    public function isDone(string $key): bool
    {
        $row = $this->data['rows'][$key] ?? null;
        return is_array($row) && $row['hash'] !== '';
    }

    /** @return array<string, mixed>|null */
    public function row(string $key): ?array
    {
        $row = $this->data['rows'][$key] ?? null;
        return is_array($row) ? $row : null;
    }

    /** The rows written so far. */
    public function count(): int
    {
        return count(array_filter($this->data['rows'], static fn(array $row): bool => $row['hash'] !== ''));
    }

    /**
     * Before a file of the content folder is written: keeps a copy of what is there (once a run, so a resumed run keeps the copy
     * from before the first try).
     */
    public function before(string $key, string $file, string $label): void
    {
        if (isset($this->data['rows'][$key])) {
            return;
        }
        $relative = $this->relative($file);
        if ($relative === null) {
            return;
        }
        $backup = '';
        if (is_file($file)) {
            $backup = (string)(count($this->data['rows']) + 1);
            if (!is_dir($this->dir . '/before')) {
                @mkdir($this->dir . '/before', 0775, true);
            }
            if (!@copy($file, $this->dir . '/before/' . $backup)) {
                throw new \RuntimeException('The copy of ' . $relative . ' from before the import could not be kept.');
            }
        }
        $this->data['rows'][$key] = [
            'label' => $label,
            'path' => $relative,
            'action' => $backup === '' ? 'created' : 'updated',
            'backup' => $backup,
            'hash' => '',
            'at' => '',
            'undone' => '',
        ];
        $this->save(true);
    }

    /** After the file was written: its checksum, so an undo can tell whether it was edited since. */
    public function after(string $key, string $file): void
    {
        if (!isset($this->data['rows'][$key]) || !is_file($file)) {
            return;
        }
        $this->data['rows'][$key]['hash'] = (string)hash_file('sha256', $file);
        $this->data['rows'][$key]['at'] = gmdate('c');
        $this->save();
    }

    /** Writes a file of the content folder as one row (atomically: a temporary file, then a rename). */
    public function write(string $key, string $file, string $contents, string $label): void
    {
        $this->before($key, $file, $label);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $tmp = dirname($file) . '/.' . basename($file) . '.import-' . bin2hex(random_bytes(3));
        if (file_put_contents($tmp, $contents) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write ' . $file);
        }
        $this->after($key, $file);
    }

    /** @param array<string, mixed> $summary what the screen shows (counts, the report's file) */
    public function finish(string $status = 'done', array $summary = []): void
    {
        $this->data['status'] = $status;
        $this->data['finished_at'] = gmdate('c');
        $this->data['summary'] = $summary + (is_array($this->data['summary']) ? $this->data['summary'] : []);
        $this->save(true);
    }

    /** Saves what is known so far (a run that stops after this can be resumed from here). */
    public function flush(): void
    {
        $this->save(true);
    }

    /** @internal ImportRuns::start() when it takes the run up again */
    public function resumed(): void
    {
        $this->data['resumed'] = (int)($this->data['resumed'] ?? 0) + 1;
        $this->data['status'] = 'running';
        $this->save(true);
    }

    /** @internal ImportRuns::undo() */
    public function markUndone(string $key): void
    {
        $this->data['rows'][$key]['undone'] = gmdate('c');
        $this->save(true);
    }

    /** @internal @param array<string, mixed> $row */
    public function backupPath(array $row): ?string
    {
        return $row['backup'] !== '' && ctype_digit((string)$row['backup']) ? $this->dir . '/before/' . $row['backup'] : null;
    }

    /** The path inside the content folder, or null for a file outside it (never recorded, never undone). */
    private function relative(string $file): ?string
    {
        $base = (realpath($this->contentDir) ?: $this->contentDir) . '/';
        $dir = realpath(dirname($file));
        $full = ($dir !== false ? $dir : dirname($file)) . '/' . basename($file);
        if (!str_starts_with($full, $base) && !str_starts_with($file, $this->contentDir . '/')) {
            return null;
        }
        $relative = str_starts_with($full, $base) ? substr($full, strlen($base)) : substr($file, strlen($this->contentDir) + 1);
        return str_contains('/' . $relative . '/', '/../') ? null : $relative;
    }

    private function save(bool $now = false): void
    {
        if (!$now && microtime(true) - $this->savedAt < self::SAVE_EVERY) {
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('The folder of the import\'s history could not be made: ' . $this->dir);
        }
        $tmp = $this->dir . '/.run.json.' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false || !@rename($tmp, $this->dir . '/run.json')) {
            @unlink($tmp);
            throw new \RuntimeException('The import\'s history could not be saved in ' . $this->dir);
        }
        $this->savedAt = microtime(true);
    }
}
