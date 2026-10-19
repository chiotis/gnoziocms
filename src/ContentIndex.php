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

    private const META_SIGNATURE = 'content_refs_signature';
    private const META_CHECKED = 'content_index_checked_at';
    private const META_REVISION = 'content_revision';

    /** Changes when the way references are read or stored changes, so an index made by older code is built again. */
    private const REFS_VERSION = '3';

    /** @var (\Closure(string): array<string, array<string, mixed>>)|null the normalized fields of a content type */
    private ?\Closure $fieldsOf = null;

    /** @var (\Closure(): string[])|null the taxonomies the site has (the terms of an entry are kept for them) */
    private ?\Closure $taxonomyNames = null;

    /** Longest value of a field the index keeps to filter by. */
    private const VALUE_LIMIT = 255;

    public function __construct(
        private SystemDatabase $database,
        private string $contentDir,
        private ?SystemMetaRepository $meta = null
    ) {
    }

    /**
     * Lets the index keep the references between entries: `$fieldsOf` gives the checked field definitions of a content type, the
     * relation fields among them say which values of an entry are references (RelationFields).
     *
     * @param \Closure(string): array<string, array<string, mixed>> $fieldsOf
     */
    public function keepReferences(\Closure $fieldsOf): void
    {
        $this->fieldsOf = $fieldsOf;
    }

    /**
     * Lets the index keep the terms entries are filed under, for the taxonomies this gives (a list lists them at once, ArchiveQuery).
     *
     * @param \Closure(): string[] $names
     */
    public function keepTerms(\Closure $names): void
    {
        $this->taxonomyNames = $names;
    }

    /** The connection, for the queries that read the index (ArchiveQuery); null when there is none. */
    public function connection(): ?PDO
    {
        return $this->isAvailable() ? $this->pdo() : null;
    }

    /** Whether the index was made with today's definitions and taxonomies (a list may be answered from it). @param string[] $types */
    public function isCurrent(array $types): bool
    {
        return $this->fieldsOf !== null && $this->taxonomyNames !== null && $this->meta !== null && $this->meta->isAvailable()
            && (string)$this->meta->get(self::META_SIGNATURE) === $this->signature($types);
    }

    /**
     * A token that changes whenever the index learns of a change (a save, a delete, an import, a file changed outside the admin that
     * a refresh found) or the site calls bump(). What is derived from the whole of the content (the maps' data) keeps it with
     * the data and is stale when it differs. Empty when there is no index to say.
     */
    public function revision(): string
    {
        return $this->meta !== null && $this->isAvailable() && $this->meta->isAvailable() ? (string)$this->meta->get(self::META_REVISION) : '';
    }

    /** Says that something the content depends on has changed (the admin calls it for every change it makes). */
    public function bump(): void
    {
        if ($this->meta !== null && $this->meta->isAvailable()) {
            $this->meta->set(self::META_REVISION, bin2hex(random_bytes(6)));
        }
    }

    /** Makes the next read look at the files again (what changed them did not go through the index). */
    public function markStale(): void
    {
        if ($this->meta !== null && $this->meta->isAvailable()) {
            $this->meta->set(self::META_CHECKED, '0');
        }
    }

    /** @param string[] $types */
    private function signature(array $types): string
    {
        if ($this->fieldsOf === null) {
            return '';
        }
        $fields = [];
        foreach ($types as $type) {
            $fields[$type] = ($this->fieldsOf)($type);
        }
        $taxonomies = $this->taxonomyNames !== null ? ($this->taxonomyNames)() : [];
        sort($taxonomies);
        return self::REFS_VERSION . ':' . RelationFields::signature($fields) . ':' . implode(',', $taxonomies);
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
                    $this->drop((string)$row['type'], (string)$row['slug'], (string)$row['lang']);
                    $removed++;
                }
            }
            foreach (['content_terms', 'content_values'] as $table) {
                $pdo->exec('DELETE FROM ' . $table . ' WHERE NOT EXISTS (SELECT 1 FROM content_index ci WHERE ci.type = ' . $table . '.type AND ci.slug = ' . $table . '.slug AND ci.lang = ' . $table . '.lang)');
            }
            foreach ($pdo->query('SELECT DISTINCT from_type, from_slug, from_lang FROM content_refs')->fetchAll() as $row) {
                if (!isset($keep[$row['from_type'] . '|' . $row['from_slug'] . '|' . $row['from_lang']])) {
                    $this->forgetReferences((string)$row['from_type'], (string)$row['from_slug'], (string)$row['from_lang']);
                }
            }
            $pdo->commit();
            if ($this->meta !== null && $this->meta->isAvailable()) {
                $this->meta->set(self::META_SIGNATURE, $this->signature($types));
            }
            $this->bump();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'indexed' => $indexed, 'removed' => $removed, 'took_ms' => (int)round((microtime(true) - $started) * 1000)];
    }

    /**
     * Brings the index up to date with the files without reading the ones that have not changed: a new or changed file is read and
     * written, a file that is gone is forgotten. An index made with other definitions than today's is built again from scratch.
     *
     * @param string[] $types
     * @return array{ok: bool, indexed: int, removed: int}
     */
    public function refresh(ContentRepository $content, array $types): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'indexed' => 0, 'removed' => 0];
        }
        if ($this->fieldsOf !== null && $this->meta !== null && $this->meta->isAvailable() && (string)$this->meta->get(self::META_SIGNATURE) !== $this->signature($types)) {
            $rebuilt = $this->rebuild($content, $types);
            return ['ok' => $rebuilt['ok'], 'indexed' => $rebuilt['indexed'], 'removed' => $rebuilt['removed']];
        }
        $pdo = $this->pdo();
        $known = [];
        foreach ($pdo->query('SELECT type, slug, lang, path, mtime FROM content_index')->fetchAll() as $row) {
            $known[(string)$row['path']] = $row;
        }
        $indexed = 0;
        $seen = [];
        $pdo->beginTransaction();
        try {
            foreach ($types as $type) {
                foreach (glob($this->contentDir . '/' . $type . '/*.md') ?: [] as $file) {
                    $path = $type . '/' . basename($file);
                    $seen[$path] = true;
                    if (!isset($known[$path]) || (int)$known[$path]['mtime'] !== (int)(filemtime($file) ?: 0)) {
                        $this->write($content->parseFile($type, $file));
                        $indexed++;
                    }
                }
            }
            $removed = 0;
            foreach (array_diff_key($known, $seen) as $row) {
                $this->drop((string)$row['type'], (string)$row['slug'], (string)$row['lang']);
                $removed++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($indexed + $removed > 0) {
            $this->bump();
        }
        return ['ok' => true, 'indexed' => $indexed, 'removed' => $removed];
    }

    /**
     * `refresh()` at most every `$seconds` seconds: files edited outside the admin (a copied folder, git) are seen within that time
     * by whatever reads the index, at the cost of looking at the modification time of every file once in a while.
     *
     * @param string[] $types
     */
    public function ensureFresh(ContentRepository $content, array $types, int $seconds = 30): void
    {
        if ($this->meta === null || !$this->isAvailable() || !$this->meta->isAvailable()) {
            return;
        }
        $last = (int)$this->meta->get(self::META_CHECKED);
        if ($last > 0 && time() - $last < $seconds) {
            return;
        }
        $this->meta->set(self::META_CHECKED, (string)time());
        try {
            $this->refresh($content, $types);
        } catch (\Throwable) {
            // The index is rebuildable; whatever is not read now is read next time.
        }
    }

    public function upsert(ContentItem $item): void
    {
        if ($this->isAvailable()) {
            $this->write($item);
            $this->bump();
        }
    }

    public function remove(string $type, string $slug, string $lang): void
    {
        if ($this->isAvailable()) {
            $this->drop($type, $slug, $lang);
            $this->bump();
        }
    }

    /** Forgets one entry; the callers that do many at once (rebuild, refresh) say once at the end that the content changed. */
    private function drop(string $type, string $slug, string $lang): void
    {
        $this->pdo()->prepare('DELETE FROM content_index WHERE type = :type AND slug = :slug AND lang = :lang')
            ->execute(['type' => $type, 'slug' => $slug, 'lang' => $lang]);
        $this->forgetReferences($type, $slug, $lang);
        $key = ['type' => $type, 'slug' => $slug, 'lang' => $lang];
        $this->pdo()->prepare('DELETE FROM content_terms WHERE type = :type AND slug = :slug AND lang = :lang')->execute($key);
        $this->pdo()->prepare('DELETE FROM content_values WHERE type = :type AND slug = :slug AND lang = :lang')->execute($key);
    }

    private function forgetReferences(string $type, string $slug, string $lang): void
    {
        $this->pdo()->prepare('DELETE FROM content_refs WHERE from_type = :type AND from_slug = :slug AND from_lang = :lang')
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
        // The references were read with other definitions (or older code) than today's: built again with the rest.
        if ($this->fieldsOf !== null && $this->meta !== null && $this->meta->isAvailable() && (string)$this->meta->get(self::META_SIGNATURE) !== $this->signature($types)) {
            $status['stale'] = true;
        }
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
            'INSERT INTO content_index (type, slug, lang, title, status, visible, path, checksum, mtime, search_text, indexed_at, translation_id, summary, title_key, sort_date)
             VALUES (:type, :slug, :lang, :title, :status, :visible, :path, :checksum, :mtime, :search_text, :indexed_at, :translation_id, :summary, :title_key, :sort_date)
             ON CONFLICT(type, slug, lang) DO UPDATE SET
                title = excluded.title, status = excluded.status, visible = excluded.visible, path = excluded.path,
                checksum = excluded.checksum, mtime = excluded.mtime, search_text = excluded.search_text, indexed_at = excluded.indexed_at,
                translation_id = excluded.translation_id, summary = excluded.summary, title_key = excluded.title_key, sort_date = excluded.sort_date'
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
            'translation_id' => (string)($meta['translation_id'] ?? ''),
            'title_key' => mb_strtolower((string)($meta['title'] ?? $item->slug)),
            // The order lists keep entries in (the repository's): the date, or when the file changed when there is none.
            'sort_date' => strtotime((string)($meta['date'] ?? '')) ?: $item->mtime,
            'summary' => (string)json_encode([
                'title' => (string)($meta['title'] ?? $item->slug),
                'excerpt' => mb_substr(trim((string)($meta['excerpt'] ?? '')), 0, 300),
                'main_image' => (string)($meta['main_image'] ?? ''),
                // An unquoted YAML date is read as a timestamp.
                'date' => is_int($meta['date'] ?? null) ? gmdate('Y-m-d', $meta['date']) : (is_scalar($meta['date'] ?? null) ? (string)$meta['date'] : ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->writeReferences($item);
        $this->writeFacets($item);
    }

    /** The terms an entry is filed under and the plain values of its fields: what a list filters and sorts by. */
    private function writeFacets(ContentItem $item): void
    {
        $pdo = $this->pdo();
        $key = ['type' => $item->type, 'slug' => $item->slug, 'lang' => $item->lang];
        $pdo->prepare('DELETE FROM content_terms WHERE type = :type AND slug = :slug AND lang = :lang')->execute($key);
        $pdo->prepare('DELETE FROM content_values WHERE type = :type AND slug = :slug AND lang = :lang')->execute($key);
        if ($this->taxonomyNames !== null) {
            $insert = $pdo->prepare('INSERT OR IGNORE INTO content_terms (type, slug, lang, taxonomy, term_id) VALUES (:type, :slug, :lang, :taxonomy, :term)');
            foreach (($this->taxonomyNames)() as $taxonomy) {
                foreach (Format::list($item->meta[$taxonomy] ?? null) as $term) {
                    $insert->execute($key + ['taxonomy' => $taxonomy, 'term' => (string)$term]);
                }
            }
        }
        $custom = is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
        if ($custom === []) {
            return;
        }
        $fields = $this->fieldsOf !== null ? ($this->fieldsOf)($item->type) : [];
        $insert = $pdo->prepare('INSERT OR REPLACE INTO content_values (type, slug, lang, field, raw, sort_text, sort_num) VALUES (:type, :slug, :lang, :field, :raw, :text, :num)');
        foreach ($custom as $name => $value) {
            if (!is_scalar($value) || !is_string($name) || (string)$value === '') {
                continue;
            }
            // The same keys a list sorts by: dates the YAML reader made numbers as Y-m-d, other values in lower case, numbers as numbers.
            $type = (string)($fields[$name]['type'] ?? 'text');
            $text = $type === 'date' && is_int($value) ? gmdate('Y-m-d', $value) : mb_strtolower((string)$value);
            $insert->execute($key + [
                'field' => $name,
                'raw' => mb_substr((string)$value, 0, self::VALUE_LIMIT),
                'text' => mb_substr($text, 0, self::VALUE_LIMIT),
                'num' => is_numeric($value) ? (float)$value : null,
            ]);
        }
    }

    private function writeReferences(ContentItem $item): void
    {
        if ($this->fieldsOf === null) {
            return;
        }
        $this->forgetReferences($item->type, $item->slug, $item->lang);
        $custom = is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
        if ($custom === []) {
            return;
        }
        $insert = $this->pdo()->prepare(
            'INSERT INTO content_refs (from_type, from_slug, from_lang, field_path, row_path, pos, to_id)
             VALUES (:type, :slug, :lang, :path, :row, :pos, :to)'
        );
        foreach (RelationFields::extract(($this->fieldsOf)($item->type), $custom) as $ref) {
            $insert->execute(['type' => $item->type, 'slug' => $item->slug, 'lang' => $item->lang, 'path' => $ref['path'], 'row' => $ref['row'], 'pos' => $ref['pos'], 'to' => $ref['id']]);
        }
    }

    /**
     * The entries that carry these ids (every language of each), as index rows with their small card.
     *
     * @param string[] $ids
     * @param string[] $types the content types allowed (none: any)
     * @return array<int, array<string, mixed>>
     */
    public function entriesById(array $ids, array $types = []): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
        if ($ids === [] || !$this->isAvailable()) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $sql = 'SELECT type, slug, lang, title, status, visible, translation_id, summary FROM content_index WHERE translation_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            $params = $chunk;
            if ($types !== []) {
                $sql .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
                $params = array_merge($params, array_values($types));
            }
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            $rows = array_merge($rows, $stmt->fetchAll());
        }
        return $rows;
    }

    /**
     * Who points at an entry through a field: the places in `content_refs` for an id, a content type and a field path.
     *
     * @return array<int, array{from_slug: string, from_lang: string, row_path: string, pos: int}>
     */
    public function referencesTo(string $id, string $fromType, string $fieldPath): array
    {
        if ($id === '' || !$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT from_slug, from_lang, row_path, pos FROM content_refs WHERE to_id = :id AND from_type = :type AND field_path = :path');
        $stmt->execute(['id' => $id, 'type' => $fromType, 'path' => $fieldPath]);
        return $stmt->fetchAll();
    }

    /**
     * The entries an editor can choose from, one for each address (the version in the site's own language when there is one), by title.
     *
     * @param string[] $types
     * @return array<int, array<string, mixed>>
     */
    public function choices(array $types, string $defaultLang, int $limit, string $query = ''): array
    {
        if ($types === [] || !$this->isAvailable()) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($types), '?'));
        $params = array_merge(array_values($types), [$defaultLang, $defaultLang]);
        $where = '';
        $order = 'title_key';
        $query = trim(mb_strtolower($query));
        if ($query !== '') {
            // Titles and addresses that contain the text, the ones that start with it first.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $where = " AND (title_key LIKE ? ESCAPE '\\' OR slug LIKE ? ESCAPE '\\')";
            $order = "CASE WHEN title_key LIKE ? ESCAPE '\\' THEN 0 ELSE 1 END, title_key";
            $params = array_merge($params, ['%' . $escaped . '%', '%' . $escaped . '%', $escaped . '%']);
        }
        $stmt = $this->pdo()->prepare(
            "SELECT type, slug, lang, title, status, visible, translation_id FROM content_index
             WHERE type IN ($marks) AND (lang = ? OR NOT EXISTS (SELECT 1 FROM content_index c2 WHERE c2.type = content_index.type AND c2.slug = content_index.slug AND c2.lang = ?))$where
             ORDER BY $order LIMIT " . max(1, $limit)
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Every entry that points at an id, with its title and status: type, slug, language, title, status, the field paths.
     *
     * @return array<int, array<string, mixed>>
     */
    public function referrers(string $id): array
    {
        if ($id === '' || !$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare(
            'SELECT r.from_type AS type, r.from_slug AS slug, r.from_lang AS lang, r.field_path AS field, c.title, c.status
             FROM content_refs r LEFT JOIN content_index c ON c.type = r.from_type AND c.slug = r.from_slug AND c.lang = r.from_lang
             WHERE r.to_id = :id ORDER BY lower(c.title)'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    /**
     * References that lead to no entry at all (the entry was deleted): who holds them, through which field.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brokenReferences(int $limit = 500): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->query(
            'SELECT r.from_type AS type, r.from_slug AS slug, r.from_lang AS lang, r.field_path AS field, r.to_id, c.title
             FROM content_refs r LEFT JOIN content_index c ON c.type = r.from_type AND c.slug = r.from_slug AND c.lang = r.from_lang
             WHERE NOT EXISTS (SELECT 1 FROM content_index t WHERE t.translation_id = r.to_id)
             ORDER BY r.from_type, lower(c.title) LIMIT ' . max(1, $limit)
        );
        return $stmt->fetchAll();
    }

    /** Every entry that points at an id, from any content type: type, slug, language, field path. @return array<int, array<string, mixed>> */
    public function allReferencesTo(string $id): array
    {
        if ($id === '' || !$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT from_type, from_slug, from_lang, field_path FROM content_refs WHERE to_id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    /**
     * The index rows of entries by address, for the languages given.
     *
     * @param array<int, array{0: string, 1: string}> $pairs type and slug
     * @return array<int, array<string, mixed>>
     */
    public function entriesByAddress(array $pairs, array $langs): array
    {
        if ($pairs === [] || !$this->isAvailable()) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($pairs, 200) as $chunk) {
            $where = implode(' OR ', array_fill(0, count($chunk), '(type = ? AND slug = ?)'));
            $params = [];
            foreach ($chunk as [$type, $slug]) {
                $params[] = $type;
                $params[] = $slug;
            }
            $sql = 'SELECT type, slug, lang, title, status, visible, translation_id, summary FROM content_index WHERE (' . $where . ') AND lang IN (' . implode(',', array_fill(0, count($langs), '?')) . ')';
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute(array_merge($params, array_values($langs)));
            $rows = array_merge($rows, $stmt->fetchAll());
        }
        return $rows;
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
