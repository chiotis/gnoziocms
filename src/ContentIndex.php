<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * SQLite index of the Markdown content files (`content_index`). The files stay the source of
 * truth; the index is rebuildable at any time and powers admin search and status counts.
 */
final class ContentIndex
{
    private const SEARCH_TEXT_LIMIT = 20000;

    public function __construct(
        private SystemDatabase $database,
        private string $contentDir
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    /**
     * @param string[] $types
     * @return array{ok: bool, indexed: int, removed: int, took_ms: int}
     */
    public function rebuild(ContentRepository $content, array $types): array
    {
        $started = microtime(true);
        if (!$this->isAvailable()) {
            return ['ok' => false, 'indexed' => 0, 'removed' => 0, 'took_ms' => 0];
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $keep = [];
            $indexed = 0;
            foreach ($types as $type) {
                foreach ($content->getItems($type, null, true, false) as $item) {
                    $this->write($item);
                    $keep[$item->type . '|' . $item->slug . '|' . $item->lang] = true;
                    $indexed++;
                }
            }
            $removed = 0;
            foreach ($pdo->query('SELECT id, type, slug, lang FROM content_index')->fetchAll() as $row) {
                if (!isset($keep[$row['type'] . '|' . $row['slug'] . '|' . $row['lang']])) {
                    $pdo->prepare('DELETE FROM content_index WHERE id = :id')->execute(['id' => $row['id']]);
                    $removed++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'indexed' => $indexed, 'removed' => $removed, 'took_ms' => (int)round((microtime(true) - $started) * 1000)];
    }

    public function upsert(ContentItem $item): void
    {
        if ($this->isAvailable()) {
            $this->write($item);
        }
    }

    public function remove(string $type, string $slug, string $lang): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $this->pdo()->prepare('DELETE FROM content_index WHERE type = :type AND slug = :slug AND lang = :lang')
            ->execute(['type' => $type, 'slug' => $slug, 'lang' => $lang]);
    }

    /**
     * Compares the index with the files on disk without parsing them.
     *
     * @param string[] $types
     * @return array{available: bool, rows: int, files: int, missing: int, orphaned: int, changed: int, stale: bool, last_indexed_at: string}
     */
    public function status(array $types): array
    {
        $status = ['available' => $this->isAvailable(), 'rows' => 0, 'files' => 0, 'missing' => 0, 'orphaned' => 0, 'changed' => 0, 'stale' => false, 'last_indexed_at' => ''];
        if (!$status['available']) {
            return $status;
        }
        $rows = [];
        foreach ($this->pdo()->query('SELECT path, mtime, indexed_at FROM content_index')->fetchAll() as $row) {
            $rows[(string)$row['path']] = $row;
            if ((string)$row['indexed_at'] > $status['last_indexed_at']) {
                $status['last_indexed_at'] = (string)$row['indexed_at'];
            }
        }
        $status['rows'] = count($rows);
        $seen = [];
        foreach ($types as $type) {
            foreach (glob($this->contentDir . '/' . $type . '/*.md') ?: [] as $path) {
                $relative = $type . '/' . basename($path);
                $seen[$relative] = true;
                $status['files']++;
                if (!isset($rows[$relative])) {
                    $status['missing']++;
                } elseif ((int)(filemtime($path) ?: 0) !== (int)$rows[$relative]['mtime']) {
                    $status['changed']++;
                }
            }
        }
        $status['orphaned'] = count(array_diff_key($rows, $seen));
        $status['stale'] = $status['missing'] + $status['orphaned'] + $status['changed'] > 0;
        return $status;
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 50): array
    {
        $query = trim(mb_strtolower($query));
        if ($query === '' || !$this->isAvailable()) {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $stmt = $this->pdo()->prepare(
            "SELECT type, slug, lang, title, status, visible, path, mtime,
                    CASE WHEN lower(title) LIKE :like ESCAPE '\\' THEN 0 WHEN slug LIKE :like ESCAPE '\\' THEN 1 ELSE 2 END AS rank
             FROM content_index
             WHERE lower(title) LIKE :like ESCAPE '\\' OR slug LIKE :like ESCAPE '\\' OR search_text LIKE :like ESCAPE '\\'
             ORDER BY rank ASC, mtime DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function write(ContentItem $item): void
    {
        $path = $item->type . '/' . basename($item->filePath);
        $meta = $item->meta;
        $terms = [];
        foreach (['tags', 'categories'] as $key) {
            $value = $meta[$key] ?? [];
            $terms[] = is_array($value) ? implode(' ', array_map('strval', $value)) : (string)$value;
        }
        $searchText = mb_strtolower(implode("\n", [
            (string)($meta['title'] ?? ''),
            (string)($meta['excerpt'] ?? ''),
            implode(' ', $terms),
            trim(strip_tags($item->html)),
        ]));
        $stmt = $this->pdo()->prepare(
            'INSERT INTO content_index (type, slug, lang, title, status, visible, path, checksum, mtime, search_text, indexed_at)
             VALUES (:type, :slug, :lang, :title, :status, :visible, :path, :checksum, :mtime, :search_text, :indexed_at)
             ON CONFLICT(type, slug, lang) DO UPDATE SET
                title = excluded.title, status = excluded.status, visible = excluded.visible, path = excluded.path,
                checksum = excluded.checksum, mtime = excluded.mtime, search_text = excluded.search_text, indexed_at = excluded.indexed_at'
        );
        $stmt->execute([
            'type' => $item->type,
            'slug' => $item->slug,
            'lang' => $item->lang,
            'title' => (string)($meta['title'] ?? $item->slug),
            'status' => (string)($meta['status'] ?? 'published'),
            'visible' => ($meta['visible'] ?? true) === false ? 0 : 1,
            'path' => $path,
            'checksum' => is_file($item->filePath) ? (string)sha1_file($item->filePath) : '',
            'mtime' => $item->mtime,
            'search_text' => mb_substr($searchText, 0, self::SEARCH_TEXT_LIMIT),
            'indexed_at' => gmdate('c'),
        ]);
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
