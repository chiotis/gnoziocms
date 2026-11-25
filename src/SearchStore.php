<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * The search index: a disposable SQLite file (`storage/cache/search/index.sqlite`) that keeps, for each entry, its extracted
 * document (SearchDocument, its display text), the normalized text it is matched by, and the words of that text (for typo tolerance). A search asks
 * SQLite which entries contain the query's words at all and reads only those, instead of reading every entry's words on every
 * search. It never decides what a visitor may see: ContentRepository does, and the index only narrows the scan.
 *
 * Every failure (no SQLite, an unwritable folder, a locked or corrupt file) turns the index off for the request; the caller then
 * extracts the entries itself, as without a cache. Deleting the file only costs the next search the time to build it again.
 */
final class SearchStore
{
    /** Bump when the tables change: an index made by older code is dropped and built again. */
    private const FORMAT = '2';
    /** The words kept for typo tolerance: shorter words are not corrected, longer ones are not words. */
    public const WORD_MIN = 4;
    public const WORD_MAX = 40;

    private ?PDO $pdo = null;
    private bool $broken = false;
    private bool $writing = false;
    private ?\PDOStatement $insertWord = null;

    public function __construct(private string $file)
    {
    }

    public function available(): bool
    {
        return $this->pdo() !== null;
    }

    private function pdo(): ?PDO
    {
        if ($this->broken) {
            return null;
        }
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        try {
            $dir = dirname($this->file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('No folder for the search index.');
            }
            $pdo = new PDO('sqlite:' . $this->file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $format = $pdo->query("SELECT value FROM meta WHERE key = 'format'")->fetchColumn();
            if ($format !== self::FORMAT) {
                $pdo->exec('DROP TABLE IF EXISTS docs');
                $pdo->exec('DROP TABLE IF EXISTS words');
                $pdo->exec('CREATE TABLE docs (scope TEXT NOT NULL, slug TEXT NOT NULL, schema TEXT NOT NULL, fingerprint TEXT NOT NULL,
                    public INTEGER NOT NULL, related INTEGER NOT NULL, haystack TEXT NOT NULL, doc BLOB NOT NULL, UNIQUE (scope, slug))');
                $pdo->exec('CREATE TABLE words (scope TEXT NOT NULL, word TEXT NOT NULL, PRIMARY KEY (scope, word)) WITHOUT ROWID');
                $pdo->exec("INSERT OR REPLACE INTO meta (key, value) VALUES ('format', '" . self::FORMAT . "')");
                // The cache of earlier versions: one serialized file for each type and language.
                foreach (glob($dir . '/*.ser') ?: [] as $old) {
                    @unlink($old);
                }
            }
            return $this->pdo = $pdo;
        } catch (\Throwable) {
            $this->broken = true;
            return null;
        }
    }

    /** Turns the index off for the rest of the request after an error, dropping what was not written yet. */
    private function fail(): void
    {
        if ($this->pdo !== null && $this->writing) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable) {
            }
        }
        $this->writing = false;
        $this->broken = true;
        $this->pdo = null;
    }

    /**
     * Opens a scope (a content type in a language) made with `$schema` (the definitions its documents were extracted with): what
     * another schema left is forgotten. Null when the index is off.
     *
     * @return array<string, string>|null slug => fingerprint of what is kept
     */
    public function open(string $scope, string $schema): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        try {
            $stale = $pdo->prepare('SELECT COUNT(*) FROM docs WHERE scope = ? AND schema <> ?');
            $stale->execute([$scope, $schema]);
            if ((int)$stale->fetchColumn() > 0) {
                $this->begin();
                $pdo->prepare('DELETE FROM docs WHERE scope = ? AND schema <> ?')->execute([$scope, $schema]);
                $pdo->prepare('DELETE FROM words WHERE scope = ?')->execute([$scope]);
            }
            $rows = $pdo->prepare('SELECT slug, fingerprint FROM docs WHERE scope = ?');
            $rows->execute([$scope]);
            $known = [];
            foreach ($rows->fetchAll(PDO::FETCH_NUM) as [$slug, $fingerprint]) {
                $known[(string)$slug] = (string)$fingerprint;
            }
            return $known;
        } catch (\Throwable) {
            $this->fail();
            return null;
        }
    }

    private function begin(): void
    {
        if (!$this->writing && $this->pdo !== null) {
            $this->pdo->beginTransaction();
            $this->writing = true;
        }
    }

    /**
     * Keeps an entry's document. `$public` says a visitor may see the entry (published and not hidden), `$related` that it points
     * at other entries (whose titles are matched at search time, so it is always read).
     *
     * @param array<string, mixed> $document
     */
    public function put(string $scope, string $schema, string $slug, string $fingerprint, bool $public, bool $related, string $haystack, array $document): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            $this->begin();
            $pdo->prepare('INSERT OR REPLACE INTO docs (scope, slug, schema, fingerprint, public, related, haystack, doc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$scope, $slug, $schema, $fingerprint, $public ? 1 : 0, $related ? 1 : 0, $haystack, serialize(array_diff_key($document, ['normal' => true]))]);
            $this->insertWord ??= $pdo->prepare('INSERT OR IGNORE INTO words (scope, word) VALUES (?, ?)');
            foreach (self::wordsOf($haystack) as $word) {
                $this->insertWord->execute([$scope, $word]);
            }
            return true;
        } catch (\Throwable) {
            $this->fail();
            return false;
        }
    }

    /**
     * The words of a normalized text that typo tolerance may offer: letters only, WORD_MIN to WORD_MAX letters.
     *
     * @return string[]
     */
    public static function wordsOf(string $haystack): array
    {
        $words = [];
        foreach (array_unique(SearchText::words($haystack, true)) as $word) {
            $length = mb_strlen($word, 'UTF-8');
            if ($length >= self::WORD_MIN && $length <= self::WORD_MAX && preg_match('/^\p{L}+$/u', $word) === 1) {
                $words[] = $word;
            }
        }
        return $words;
    }

    /**
     * The documents of a scope whose text contains, for every group, at least one of the group's strings (and every entry that
     * points at others, whose related titles are not in its text). No groups: every document.
     *
     * @param list<list<string>> $groups
     * @return array<string, array{fingerprint: string, doc: string, haystack: string}>|null slug => what is kept; null when the index is off
     */
    public function matching(string $scope, array $groups): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        [$where, $params] = self::condition($groups);
        try {
            $rows = $pdo->prepare('SELECT slug, fingerprint, doc, haystack FROM docs WHERE scope = ?' . ($where === '' ? '' : ' AND (related = 1 OR ' . $where . ')'));
            $rows->execute([$scope, ...$params]);
            $found = [];
            foreach ($rows->fetchAll(PDO::FETCH_NUM) as [$slug, $fingerprint, $doc, $haystack]) {
                $found[(string)$slug] = ['fingerprint' => (string)$fingerprint, 'doc' => (string)$doc, 'haystack' => (string)$haystack];
            }
            return $found;
        } catch (\Throwable) {
            $this->fail();
            return null;
        }
    }

    /** What is kept for one entry, or null. @return array{fingerprint: string, doc: string, haystack: string}|null */
    public function get(string $scope, string $slug): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        try {
            $row = $pdo->prepare('SELECT fingerprint, doc, haystack FROM docs WHERE scope = ? AND slug = ?');
            $row->execute([$scope, $slug]);
            $found = $row->fetch(PDO::FETCH_NUM);
            return $found === false ? null : ['fingerprint' => (string)$found[0], 'doc' => (string)$found[1], 'haystack' => (string)$found[2]];
        } catch (\Throwable) {
            $this->fail();
            return null;
        }
    }

    /**
     * Whether any document of the scopes contains one of the strings; only entries a visitor may see unless `$includeHidden`.
     * Null when the index is off.
     *
     * @param string[] $scopes
     * @param string[] $alternatives
     */
    public function contains(array $scopes, array $alternatives, bool $includeHidden): ?bool
    {
        $pdo = $this->pdo();
        if ($pdo === null || $scopes === [] || $alternatives === []) {
            return $pdo === null ? null : false;
        }
        [$where, $params] = self::condition([$alternatives]);
        try {
            $query = $pdo->prepare('SELECT 1 FROM docs WHERE scope IN (' . implode(', ', array_fill(0, count($scopes), '?')) . ')'
                . ($includeHidden ? '' : ' AND public = 1') . ' AND ' . $where . ' LIMIT 1');
            $query->execute([...$scopes, ...$params]);
            return $query->fetchColumn() !== false;
        } catch (\Throwable) {
            $this->fail();
            return null;
        }
    }

    /**
     * The words of the scopes with a length (in letters) between the two.
     *
     * @param string[] $scopes
     * @return string[]
     */
    public function words(array $scopes, int $min, int $max): array
    {
        $pdo = $this->pdo();
        if ($pdo === null || $scopes === []) {
            return [];
        }
        try {
            // A length is a number: bound as text it would never be between the two.
            $query = $pdo->prepare('SELECT DISTINCT word FROM words WHERE scope IN (' . implode(', ', array_fill(0, count($scopes), '?')) . ') AND length(word) BETWEEN ' . (int)$min . ' AND ' . (int)$max);
            $query->execute($scopes);
            return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            $this->fail();
            return [];
        }
    }

    /**
     * The words of the scopes that start with a prefix (at most 500).
     *
     * @param string[] $scopes
     * @return string[]
     */
    public function startingWith(array $scopes, string $prefix): array
    {
        $pdo = $this->pdo();
        if ($pdo === null || $scopes === [] || $prefix === '') {
            return [];
        }
        try {
            // A range over the words' own order (the primary key), not LIKE, which would read every word.
            $query = $pdo->prepare('SELECT DISTINCT word FROM words WHERE scope IN (' . implode(', ', array_fill(0, count($scopes), '?')) . ') AND word >= ? AND word < ? LIMIT 500');
            $query->execute([...$scopes, $prefix, $prefix . "\u{10FFFF}"]);
            return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            $this->fail();
            return [];
        }
    }

    /**
     * Forgets the entries of a scope that are not in `$keep` (they were deleted), and then the words only they had.
     *
     * @param array<string, mixed> $keep slug => anything
     */
    public function prune(string $scope, array $keep): void
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return;
        }
        try {
            $rows = $pdo->prepare('SELECT slug FROM docs WHERE scope = ?');
            $rows->execute([$scope]);
            $gone = array_values(array_filter(array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN)), static fn(string $slug): bool => !isset($keep[$slug])));
            if ($gone === []) {
                return;
            }
            $this->begin();
            $delete = $pdo->prepare('DELETE FROM docs WHERE scope = ? AND slug = ?');
            foreach ($gone as $slug) {
                $delete->execute([$scope, $slug]);
            }
            // The words are a set for the whole scope: made again from what is left.
            $pdo->prepare('DELETE FROM words WHERE scope = ?')->execute([$scope]);
            $texts = $pdo->prepare('SELECT haystack FROM docs WHERE scope = ?');
            $texts->execute([$scope]);
            $this->insertWord ??= $pdo->prepare('INSERT OR IGNORE INTO words (scope, word) VALUES (?, ?)');
            while (($haystack = $texts->fetchColumn()) !== false) {
                foreach (self::wordsOf((string)$haystack) as $word) {
                    $this->insertWord->execute([$scope, $word]);
                }
            }
        } catch (\Throwable) {
            $this->fail();
        }
    }

    /** Writes what this request added. */
    public function commit(): void
    {
        if ($this->pdo === null || !$this->writing) {
            return;
        }
        try {
            $this->pdo->commit();
            $this->writing = false;
        } catch (\Throwable) {
            $this->fail();
        }
    }

    /** @param list<list<string>> $groups @return array{0: string, 1: list<string>} */
    private static function condition(array $groups): array
    {
        $and = [];
        $params = [];
        foreach ($groups as $group) {
            $or = [];
            foreach (array_values(array_unique(array_filter($group, static fn(string $s): bool => $s !== ''))) as $needle) {
                $or[] = 'instr(haystack, ?) > 0';
                $params[] = $needle;
            }
            if ($or !== []) {
                $and[] = '(' . implode(' OR ', $or) . ')';
            }
        }
        return [implode(' AND ', $and), $params];
    }
}
