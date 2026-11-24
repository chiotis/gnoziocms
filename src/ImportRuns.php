<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What every import did, file by file, so one row can be undone and a run that stopped can go on where it was.
 *
 * Each run of an importer (WordPress, WooCommerce, a CSV of a content type) is a folder under storage/import/runs/<id>: run.json
 * says which importer, from where, when, how it ended, and one row for each file of the content folder it wrote, with what was
 * there before (a copy kept beside it, or nothing) and the checksum of what it wrote. Undoing a row puts the file back as it was
 * before the import (removes it when the import made it), and only while it is still what the import wrote: a file edited since is
 * left alone unless the undo is forced. A run that stopped half way (the command was stopped, the server ended the request) stays
 * "running"; starting the same importer from the same source with resume on takes it up again and skips the rows already done.
 *
 * The rows are files of the content folder only (entries, products); pictures brought into the library and terms are kept.
 */
final class ImportRuns
{
    /** Runs kept; the oldest beyond this are removed when a new one starts. */
    public const KEEP = 30;

    public const IMPORTERS = ['wordpress' => 'WordPress', 'woocommerce' => 'WooCommerce', 'csv' => 'CSV'];

    public function __construct(private string $dir, private string $contentDir)
    {
        $this->dir = rtrim($dir, '/');
        $this->contentDir = rtrim($contentDir, '/');
    }

    /**
     * A new run, or with $resume the last one of this importer and source that did not finish.
     *
     * @param array<string, mixed> $meta what the screen shows about it (options, the person who started it)
     */
    public function start(string $importer, string $source, array $meta = [], bool $resume = false): ImportRun
    {
        if ($resume) {
            $open = $this->unfinished($importer, $source);
            if ($open !== null) {
                $run = $this->open($open['id']);
                if ($run !== null) {
                    $run->resumed();
                    return $run;
                }
            }
        }
        $this->prune();
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $data = [
            'id' => $id,
            'importer' => $importer,
            'source' => $source,
            'meta' => $meta,
            'status' => 'running',
            'started_at' => gmdate('c'),
            // Two runs can start in the same second (a stop and a new start); this keeps them in order.
            'started_us' => (int)(microtime(true) * 1000000),
            'finished_at' => '',
            'resumed' => 0,
            'summary' => [],
            'rows' => [],
        ];
        return new ImportRun($this->dir . '/' . $id, $this->contentDir, $data);
    }

    /** The last run of an importer and source that did not finish, if any. @return array<string, mixed>|null */
    public function unfinished(string $importer, string $source): ?array
    {
        foreach ($this->all() as $run) {
            if ($run['importer'] === $importer && $run['source'] === $source) {
                return $run['status'] === 'running' ? $run : null;
            }
        }
        return null;
    }

    public function open(string $id): ?ImportRun
    {
        $data = $this->read($id);
        return $data === null ? null : new ImportRun($this->dir . '/' . $id, $this->contentDir, $data);
    }

    /**
     * Every run, newest first, without their rows.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*/run.json') ?: [] as $file) {
            $data = $this->read(basename(dirname($file)));
            if ($data === null) {
                continue;
            }
            $counts = ['created' => 0, 'updated' => 0, 'undone' => 0];
            foreach ($data['rows'] as $row) {
                $counts[$row['undone'] !== '' ? 'undone' : $row['action']] = ($counts[$row['undone'] !== '' ? 'undone' : $row['action']] ?? 0) + 1;
            }
            unset($data['rows']);
            $out[] = $data + ['counts' => $counts];
        }
        usort($out, static fn(array $a, array $b): int => [(int)($b['started_us'] ?? 0), $b['started_at'], $b['id']] <=> [(int)($a['started_us'] ?? 0), $a['started_at'], $a['id']]);
        return $out;
    }

    /** One run with its rows, each told whether it can still be undone. @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $data = $this->read($id);
        if ($data === null) {
            return null;
        }
        foreach ($data['rows'] as $key => $row) {
            $data['rows'][$key]['key'] = (string)$key;
            $data['rows'][$key]['state'] = $this->rowState($row);
        }
        return $data;
    }

    /**
     * Puts the file of one row back as it was before the import: the earlier file, or none when the import made it.
     *
     * @return array{ok: bool, message: string, path?: string}
     */
    public function undo(string $id, string $key, bool $force = false): array
    {
        $run = $this->open($id);
        $row = $run?->row($key);
        if ($run === null || $row === null) {
            return ['ok' => false, 'message' => 'That import or row is not there any more.'];
        }
        if ($row['undone'] !== '') {
            return ['ok' => false, 'message' => 'That row was already undone.'];
        }
        $state = $this->rowState($row);
        if ($state === 'changed' && !$force) {
            return ['ok' => false, 'message' => $row['label'] . ' was changed after the import; undoing it would lose that change.'];
        }
        $path = $this->contentDir . '/' . $row['path'];
        if ($row['action'] === 'created') {
            if (is_file($path) && !@unlink($path)) {
                return ['ok' => false, 'message' => $row['label'] . ' could not be removed.'];
            }
        } else {
            $backup = $run->backupPath($row);
            if ($backup === null || !is_file($backup)) {
                return ['ok' => false, 'message' => 'The copy of ' . $row['label'] . ' from before the import is missing.'];
            }
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            $tmp = dirname($path) . '/.' . basename($path) . '.undo-' . bin2hex(random_bytes(3));
            if (!@copy($backup, $tmp) || !@rename($tmp, $path)) {
                @unlink($tmp);
                return ['ok' => false, 'message' => $row['label'] . ' could not be put back.'];
            }
        }
        $run->markUndone($key);
        return ['ok' => true, 'message' => ($row['action'] === 'created' ? 'Removed ' : 'Put back ') . $row['label'] . '.', 'path' => $row['path']];
    }

    /** done (as the import left it), changed (edited since), gone (removed since), undone, or pending (not written yet). */
    private function rowState(array $row): string
    {
        if ($row['undone'] !== '') {
            return 'undone';
        }
        if ($row['hash'] === '') {
            return 'pending';
        }
        $path = $this->contentDir . '/' . $row['path'];
        if (!is_file($path)) {
            return 'gone';
        }
        return hash_file('sha256', $path) === $row['hash'] ? 'done' : 'changed';
    }

    /** @return array<string, mixed>|null */
    private function read(string $id): ?array
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($this->dir . '/' . $id . '/run.json'), true);
        if (!is_array($data) || ($data['id'] ?? '') !== $id) {
            return null;
        }
        $data['rows'] = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        return $data;
    }

    private function prune(): void
    {
        $runs = $this->all();
        foreach (array_slice($runs, self::KEEP - 1) as $old) {
            if ($old['status'] === 'running') {
                continue;
            }
            $folder = $this->dir . '/' . $old['id'];
            foreach (glob($folder . '/before/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($folder . '/before');
            @unlink($folder . '/run.json');
            @rmdir($folder);
        }
    }
}
