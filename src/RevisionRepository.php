<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;
use Symfony\Component\Yaml\Yaml;

/**
 * The history of every content file: each time it is saved, imported, restored, or deleted, its whole text is kept
 * (deflated) in the system database, so an earlier version can be compared and brought back.
 *
 * A revision belongs to an item (type, slug, language). When the address of an item changes its history moves along.
 */
final class RevisionRepository
{
    /** Versions kept per item; the oldest go first. */
    public const KEEP_PER_ITEM = 50;
    /** Items that were deleted stay restorable for this many days. */
    public const KEEP_DELETED_DAYS = 180;
    private const MAX_BYTES = 2000000;

    public function __construct(private SystemDatabase $database)
    {
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    /**
     * Records this text as the latest state of the item. Nothing is recorded when it is the same as the latest
     * version already kept (except deletes and restores, which always are).
     *
     * @return int the revision id, or 0 when nothing was recorded
     */
    public function capture(string $type, string $slug, string $lang, string $raw, string $action, string $actor): int
    {
        if (!$this->isAvailable() || strlen($raw) > self::MAX_BYTES) {
            return 0;
        }
        $checksum = sha1($raw);
        $latest = $this->latest($type, $slug, $lang);
        if ($latest !== null && $latest['checksum'] === $checksum && !in_array($action, ['delete', 'restore'], true) && $latest['action'] !== 'delete') {
            return 0;
        }
        [$title, $status] = $this->describe($raw);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO content_revisions (type, slug, lang, action, actor, title, status, checksum, size, content, created_at)
             VALUES (:type, :slug, :lang, :action, :actor, :title, :status, :checksum, :size, :content, :now)'
        );
        $stmt->bindValue('type', $type);
        $stmt->bindValue('slug', $slug);
        $stmt->bindValue('lang', $lang);
        $stmt->bindValue('action', $action);
        $stmt->bindValue('actor', $actor !== '' ? $actor : null);
        $stmt->bindValue('title', $title);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('checksum', $checksum);
        $stmt->bindValue('size', strlen($raw), PDO::PARAM_INT);
        $stmt->bindValue('content', (string)gzdeflate($raw, 6), PDO::PARAM_LOB);
        $stmt->bindValue('now', gmdate('c'));
        $stmt->execute();
        $id = (int)$this->pdo()->lastInsertId();
        $this->prune($type, $slug, $lang);
        return $id;
    }

    /**
     * Before a file is overwritten: keeps its current text if the history does not have it yet (the first time
     * the item is touched since history exists, or when the file was changed outside the editor).
     */
    public function baseline(string $type, string $slug, string $lang, string $path, string $actor): void
    {
        if (!$this->isAvailable() || !is_file($path)) {
            return;
        }
        $raw = (string)file_get_contents($path);
        $latest = $this->latest($type, $slug, $lang);
        if ($latest === null) {
            $this->capture($type, $slug, $lang, $raw, 'baseline', '');
        } elseif ($latest['checksum'] !== sha1($raw)) {
            $this->capture($type, $slug, $lang, $raw, 'external', '');
        }
    }

    /** The item's address changed: its history follows. */
    public function rename(string $type, string $oldSlug, string $oldLang, string $newSlug, string $newLang): void
    {
        if (!$this->isAvailable() || ($oldSlug === $newSlug && $oldLang === $newLang)) {
            return;
        }
        $this->pdo()->prepare('UPDATE content_revisions SET slug = :ns, lang = :nl WHERE type = :t AND slug = :os AND lang = :ol')
            ->execute(['ns' => $newSlug, 'nl' => $newLang, 't' => $type, 'os' => $oldSlug, 'ol' => $oldLang]);
    }

    /** @return array<string, mixed>|null the newest revision of an item, without its text */
    public function latest(string $type, string $slug, string $lang): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM content_revisions WHERE type = :t AND slug = :s AND lang = :l ORDER BY id DESC LIMIT 1');
        $stmt->execute(['t' => $type, 's' => $slug, 'l' => $lang]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private const COLUMNS = 'id, type, slug, lang, action, actor, title, status, checksum, size, created_at';

    /** @return array<int, array<string, mixed>> newest first, without the text */
    public function forItem(string $type, string $slug, string $lang, int $limit = 50): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM content_revisions WHERE type = :t AND slug = :s AND lang = :l ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute(['t' => $type, 's' => $slug, 'l' => $lang]);
        return $stmt->fetchAll() ?: [];
    }

    public function countForItem(string $type, string $slug, string $lang): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM content_revisions WHERE type = :t AND slug = :s AND lang = :l');
        $stmt->execute(['t' => $type, 's' => $slug, 'l' => $lang]);
        return (int)$stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null a revision with its text in `raw` */
    public function find(int $id): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ', content FROM content_revisions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $content = $row['content'];
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }
        $row['raw'] = (string)@gzinflate((string)$content);
        unset($row['content']);
        return $row;
    }

    /** @return array<string, mixed>|null the revision saved just before this one, for the same item */
    public function previous(array $revision): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM content_revisions WHERE type = :t AND slug = :s AND lang = :l AND id < :id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['t' => $revision['type'], 's' => $revision['slug'], 'l' => $revision['lang'], 'id' => $revision['id']]);
        $id = $stmt->fetchColumn();
        return $id ? $this->find((int)$id) : null;
    }

    /**
     * Recent changes across the site, one row per revision.
     *
     * @param array{q?: string, type?: string, actor?: string, action?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function recent(array $filters, int $limit, int $offset): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        [$where, $params] = $this->where($filters);
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM content_revisions' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function count(array $filters): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        [$where, $params] = $this->where($filters);
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM content_revisions' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @return array{0: string[], 1: array<string, mixed>} */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(IFNULL(title, '') LIKE :q ESCAPE '\\' OR slug LIKE :q ESCAPE '\\')";
            $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
        $exclude = trim((string)($filters['exclude_type'] ?? ''));
        if ($exclude !== '') {
            $where[] = 'type <> :exclude_type';
            $params['exclude_type'] = $exclude;
        }
        foreach (['type', 'actor', 'action'] as $key) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = $key . ' = :' . $key;
                $params[$key] = $value;
            }
        }
        return [$where, $params];
    }

    /**
     * Items whose newest revision is a delete: what can be brought back.
     *
     * @return array<int, array<string, mixed>>
     */
    public function deleted(int $limit = 100, string $excludeType = ''): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare(
            'SELECT ' . self::qualified() . ' FROM content_revisions r
             JOIN (SELECT type, slug, lang, MAX(id) AS mid FROM content_revisions GROUP BY type, slug, lang) m ON r.id = m.mid
             WHERE r.action = \'delete\' AND r.type <> :exclude ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(['exclude' => $excludeType]);
        return $stmt->fetchAll() ?: [];
    }

    private static function qualified(): string
    {
        return implode(', ', array_map(static fn(string $c): string => 'r.' . trim($c), explode(',', self::COLUMNS)));
    }

    /** Old versions beyond the limit, and items deleted long ago, are removed. */
    public function prune(string $type, string $slug, string $lang): void
    {
        $this->pdo()->prepare(
            'DELETE FROM content_revisions WHERE type = :t AND slug = :s AND lang = :l AND id NOT IN
             (SELECT id FROM content_revisions WHERE type = :t2 AND slug = :s2 AND lang = :l2 ORDER BY id DESC LIMIT ' . self::KEEP_PER_ITEM . ')'
        )->execute(['t' => $type, 's' => $slug, 'l' => $lang, 't2' => $type, 's2' => $slug, 'l2' => $lang]);

        if (random_int(1, 40) === 1) {
            $cutoff = gmdate('c', time() - self::KEEP_DELETED_DAYS * 86400);
            $this->pdo()->prepare(
                'DELETE FROM content_revisions WHERE (type, slug, lang) IN
                 (SELECT r.type, r.slug, r.lang FROM content_revisions r
                  JOIN (SELECT type, slug, lang, MAX(id) AS mid FROM content_revisions GROUP BY type, slug, lang) m ON r.id = m.mid
                  WHERE r.action = \'delete\' AND r.created_at < :cutoff)'
            )->execute(['cutoff' => $cutoff]);
        }
    }

    /** @return string[] distinct people who changed something, for the filter */
    public function actors(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        return array_map('strval', $this->pdo()->query("SELECT DISTINCT actor FROM content_revisions WHERE actor IS NOT NULL AND actor <> '' ORDER BY actor")->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array{0: string, 1: string} title and status from a file's front matter */
    private function describe(string $raw): array
    {
        [$front] = FrontMatter::split($raw);
        try {
            $data = $front !== '' ? Yaml::parse($front) : [];
        } catch (\Throwable) {
            $data = [];
        }
        return is_array($data) ? [mb_substr((string)($data['title'] ?? ''), 0, 200), (string)($data['status'] ?? '')] : ['', ''];
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
