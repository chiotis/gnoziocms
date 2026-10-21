<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * Old addresses that lead to a new one, and a log of addresses visitors asked for that do not exist.
 *
 * A source is the path a visitor asks for, normalised (decoded, lower case, no leading or trailing slash).
 * A target is either a path on this site ("/en/about", "/" for the home page) or a full http(s) address.
 */
final class RedirectRepository
{
    public const MAX_HOPS = 6;
    private const NOT_FOUND_LIMIT = 1000;

    public function __construct(private SystemDatabase $database)
    {
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    public static function normalizePath(string $raw): string
    {
        $raw = trim($raw);
        if (!str_contains($raw, '://')) {
            // "//x" would otherwise be read as a host name.
            $raw = '/' . ltrim($raw, '/');
        }
        // parse_url() mangles bytes outside ASCII ("/activity/πεζοπορία" as an import or a paste writes it), so they are
        // encoded first, as a browser sends them, and decoded below.
        $raw = (string)preg_replace_callback('/[^\x21-\x7E]/', static fn(array $m): string => rawurlencode($m[0]), $raw);
        $path = (string)(parse_url($raw, PHP_URL_PATH) ?? '');
        $path = rawurldecode($path);
        // A broken byte sequence is made valid, so a path normalised twice is the same path.
        $path = mb_check_encoding($path, 'UTF-8') ? $path : mb_scrub($path, 'UTF-8');
        $path = mb_strtolower($path, 'UTF-8');
        $path = (string)preg_replace('#/{2,}#', '/', $path);
        return mb_substr(trim($path, '/'), 0, 300);
    }

    public static function isExternal(string $target): bool
    {
        return (bool)preg_match('#^https?://#i', $target);
    }

    /** The form a target is stored in: "/path" for this site, the address itself for another site. */
    public static function canonicalTarget(string $target): string
    {
        $target = trim($target);
        if (self::isExternal($target)) {
            return $target;
        }
        // Keep a query string or anchor the person typed ("/en/pricing#plans"), but tidy the path part.
        $suffix = '';
        if (preg_match('/^([^?#]*)([?#].*)$/', $target, $m)) {
            $target = $m[1];
            $suffix = $m[2];
        }
        return '/' . self::normalizePathKeepCase($target) . $suffix;
    }

    private static function normalizePathKeepCase(string $raw): string
    {
        $path = (string)preg_replace('#/{2,}#', '/', trim($raw));
        return trim($path, '/');
    }

    /** Why a redirect cannot be saved, or null when it is fine. */
    public function validate(string $source, string $target, int $code, ?int $ignoreId = null): ?string
    {
        $normalized = self::normalizePath($source);
        if ($normalized === '') {
            return 'source_empty';
        }
        if ($normalized === 'admin' || str_starts_with($normalized, 'admin/')) {
            return 'source_admin';
        }
        if (str_starts_with($normalized, 'uploads/') || str_starts_with($normalized, 'assets/')) {
            return 'source_files';
        }
        $target = trim($target);
        if ($target === '') {
            return 'target_empty';
        }
        if (!in_array($code, [301, 302], true)) {
            return 'code';
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) && !self::isExternal($target)) {
            return 'target_scheme';
        }
        if (self::isExternal($target)) {
            if (filter_var($target, FILTER_VALIDATE_URL) === false) {
                return 'target_invalid';
            }
        } elseif (!str_starts_with($target, '/')) {
            return 'target_relative';
        }
        if (!self::isExternal($target)) {
            $next = self::normalizePath($target);
            if ($next === $normalized) {
                return 'same';
            }
            if ($this->leadsBackTo($next, $normalized, $ignoreId)) {
                return 'loop';
            }
        }
        return null;
    }

    /** Whether following redirects from $start ends up at $goal. */
    private function leadsBackTo(string $start, string $goal, ?int $ignoreId): bool
    {
        $current = $start;
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if ($current === $goal) {
                return true;
            }
            $row = $this->findBySource($current, false);
            if ($row === null || ($ignoreId !== null && (int)$row['id'] === $ignoreId) || self::isExternal((string)$row['target'])) {
                return false;
            }
            $current = self::normalizePath((string)$row['target']);
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    public function findBySource(string $source, bool $onlyEnabled = true): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM redirects WHERE source = :source' . ($onlyEnabled ? ' AND enabled = 1' : '') . ' LIMIT 1');
        $stmt->execute(['source' => $source]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM redirects WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Where a request for $path should go, following redirects that lead to redirects.
     *
     * @return array{id: int, target: string, code: int}|null
     */
    public function resolve(string $path): ?array
    {
        $source = self::normalizePath($path);
        if ($source === '') {
            return null;
        }
        $first = $this->findBySource($source);
        if ($first === null) {
            return null;
        }
        $target = (string)$first['target'];
        $seen = [$source => true];
        for ($hop = 0; $hop < self::MAX_HOPS && !self::isExternal($target); $hop++) {
            $next = self::normalizePath($target);
            if (isset($seen[$next])) {
                return null; // A loop: better a 404 than a browser error.
            }
            $seen[$next] = true;
            $row = $this->findBySource($next);
            if ($row === null) {
                break;
            }
            $target = (string)$row['target'];
        }
        return ['id' => (int)$first['id'], 'target' => $target, 'code' => (int)$first['status_code']];
    }

    public function recordHit(int $id): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE redirects SET hits = hits + 1, last_hit_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('c'), 'id' => $id]);
    }

    /** @return int the new id, or 0 when nothing was saved */
    public function create(string $source, string $target, int $code, string $origin, string $note, string $by, ?string $contentType = null): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $source = self::normalizePath($source);
        $now = gmdate('c');
        $stmt = $this->pdo()->prepare(
            'INSERT INTO redirects (source, target, status_code, origin, enabled, note, content_type, created_by, created_at, updated_at)
             VALUES (:source, :target, :code, :origin, 1, :note, :type, :by, :now, :now)'
        );
        $stmt->execute([
            'source' => $source,
            'target' => self::canonicalTarget($target),
            'code' => $code,
            'origin' => $origin === 'auto' ? 'auto' : 'manual',
            'note' => $note !== '' ? mb_substr($note, 0, 300) : null,
            'type' => $contentType,
            'by' => $by !== '' ? $by : null,
            'now' => $now,
        ]);
        $this->pdo()->prepare('DELETE FROM not_found_log WHERE path = :path')->execute(['path' => $source]);
        return (int)$this->pdo()->lastInsertId();
    }

    public function update(int $id, string $source, string $target, int $code, bool $enabled, string $note): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $stmt = $this->pdo()->prepare(
            'UPDATE redirects SET source = :source, target = :target, status_code = :code, enabled = :enabled, note = :note, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'source' => self::normalizePath($source),
            'target' => self::canonicalTarget($target),
            'code' => $code,
            'enabled' => $enabled ? 1 : 0,
            'note' => $note !== '' ? mb_substr($note, 0, 300) : null,
            'now' => gmdate('c'),
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        if ($this->isAvailable()) {
            $this->pdo()->prepare('DELETE FROM redirects WHERE id = :id')->execute(['id' => $id]);
        }
    }

    /** @param int[] $ids */
    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids === [] || !$this->isAvailable()) {
            return 0;
        }
        $stmt = $this->pdo()->prepare('DELETE FROM redirects WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /**
     * An address changed: the old one now leads to the new one. Redirects that already pointed at the old address are
     * repointed (so nobody is sent through a chain), and a redirect from the new address is removed (it would loop).
     */
    public function moved(string $from, string $to, string $by, ?string $contentType = null): void
    {
        $from = self::normalizePath($from);
        $to = self::normalizePath($to);
        if (!$this->isAvailable() || $from === '' || $from === $to) {
            return;
        }
        $newTarget = '/' . $to;
        $oldTarget = '/' . $from;

        $this->pdo()->prepare('DELETE FROM redirects WHERE source = :to')->execute(['to' => $to]);
        $this->pdo()->prepare('UPDATE redirects SET target = :new, updated_at = :now WHERE target = :old')
            ->execute(['new' => $newTarget, 'now' => gmdate('c'), 'old' => $oldTarget]);

        $existing = $this->findBySource($from, false);
        if ($existing !== null) {
            $this->pdo()->prepare('UPDATE redirects SET target = :target, origin = \'auto\', status_code = 301, enabled = 1, content_type = :type, updated_at = :now WHERE id = :id')
                ->execute(['target' => $newTarget, 'type' => $contentType, 'now' => gmdate('c'), 'id' => $existing['id']]);
            return;
        }
        $this->create($from, $newTarget, 301, 'auto', 'Address changed', $by, $contentType);
    }

    /**
     * An entry was deleted and its visitors are sent somewhere else. Like moved(), but the target can be anywhere on
     * this site or on another one. Redirects that led to the deleted address are pointed at the new place, so nobody
     * goes through a chain.
     */
    public function replacedBy(string $from, string $target, string $by, ?string $contentType = null): void
    {
        $from = self::normalizePath($from);
        if (!$this->isAvailable() || $from === '' || trim($target) === '') {
            return;
        }
        $target = self::canonicalTarget($target);
        $this->pdo()->prepare('UPDATE redirects SET target = :new, updated_at = :now WHERE target = :old AND source <> :from')
            ->execute(['new' => $target, 'now' => gmdate('c'), 'old' => '/' . $from, 'from' => $from]);

        $existing = $this->findBySource($from, false);
        if ($existing !== null) {
            $this->pdo()->prepare('UPDATE redirects SET target = :target, origin = \'auto\', status_code = 301, enabled = 1, content_type = :type, note = \'Deleted\', updated_at = :now WHERE id = :id')
                ->execute(['target' => $target, 'type' => $contentType, 'now' => gmdate('c'), 'id' => $existing['id']]);
            return;
        }
        $this->create($from, $target, 301, 'auto', 'Deleted', $by, $contentType);
    }

    /** Content now lives at $path, so a redirect away from it would never be used and only confuse. */
    public function removeSource(string $path): void
    {
        $source = self::normalizePath($path);
        if ($this->isAvailable() && $source !== '') {
            $this->pdo()->prepare('DELETE FROM redirects WHERE source = :source')->execute(['source' => $source]);
        }
    }

    /** @return array<int, array<string, mixed>> Redirects that lead to a path on this site. */
    public function pointingTo(string $path): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM redirects WHERE target = :target ORDER BY hits DESC, source LIMIT 50');
        $stmt->execute(['target' => '/' . self::normalizePath($path)]);
        return $stmt->fetchAll() ?: [];
    }

    /** The addresses of the permanent redirects that are on and stay on this site: the ones whose links in content can be updated. @return string[] */
    public function permanentSources(int $limit = 500): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $rows = $this->pdo()->query('SELECT source FROM redirects WHERE enabled = 1 AND status_code = 301 ORDER BY id DESC LIMIT ' . max(1, min(2000, $limit)))->fetchAll() ?: [];
        return array_values(array_map(static fn(array $row): string => (string)$row['source'], $rows));
    }

    /**
     * @param array{q?: string, origin?: string, state?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        [$where, $params] = $this->where($filters);
        $sql = 'SELECT * FROM redirects' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function count(array $filters = []): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        [$where, $params] = $this->where($filters);
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM redirects' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : ''));
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
            $where[] = '(source LIKE :q ESCAPE \'\\\' OR target LIKE :q ESCAPE \'\\\' OR IFNULL(note, \'\') LIKE :q ESCAPE \'\\\')';
            $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q, 'UTF-8')) . '%';
        }
        if (in_array($filters['origin'] ?? '', ['auto', 'manual'], true)) {
            $where[] = 'origin = :origin';
            $params['origin'] = $filters['origin'];
        }
        if (($filters['state'] ?? '') === 'off') {
            $where[] = 'enabled = 0';
        } elseif (($filters['state'] ?? '') === 'unused') {
            $where[] = 'hits = 0';
        }
        return [$where, $params];
    }

    /** @return array{total: int, active: int, hits: int, unused: int} */
    public function stats(): array
    {
        if (!$this->isAvailable()) {
            return ['total' => 0, 'active' => 0, 'hits' => 0, 'unused' => 0];
        }
        $row = $this->pdo()->query('SELECT COUNT(*) AS total, SUM(enabled) AS active, SUM(hits) AS hits, SUM(CASE WHEN hits = 0 THEN 1 ELSE 0 END) AS unused FROM redirects')->fetch() ?: [];
        return ['total' => (int)($row['total'] ?? 0), 'active' => (int)($row['active'] ?? 0), 'hits' => (int)($row['hits'] ?? 0), 'unused' => (int)($row['unused'] ?? 0)];
    }

    // ---- addresses that were asked for and not found

    public function recordNotFound(string $path, string $referrer): void
    {
        $path = self::normalizePath($path);
        if (!$this->isAvailable() || $path === '') {
            return;
        }
        $now = gmdate('c');
        $stmt = $this->pdo()->prepare(
            'INSERT INTO not_found_log (path, hits, first_seen_at, last_seen_at, last_referrer) VALUES (:path, 1, :now, :now, :ref)
             ON CONFLICT(path) DO UPDATE SET hits = hits + 1, last_seen_at = excluded.last_seen_at, last_referrer = COALESCE(excluded.last_referrer, last_referrer)'
        );
        $stmt->execute(['path' => $path, 'now' => $now, 'ref' => $referrer !== '' ? mb_substr($referrer, 0, 200) : null]);

        // Never let random requests grow this table without limit: the oldest entries go first.
        if (random_int(1, 25) === 1 && (int)$this->pdo()->query('SELECT COUNT(*) FROM not_found_log')->fetchColumn() > self::NOT_FOUND_LIMIT) {
            $this->pdo()->exec('DELETE FROM not_found_log WHERE path IN (SELECT path FROM not_found_log ORDER BY last_seen_at ASC LIMIT 100)');
        }
    }

    /** How many addresses are listed (the ones chosen to be ignored are not counted). */
    public function notFoundCount(): int
    {
        return $this->isAvailable() ? (int)$this->pdo()->query('SELECT COUNT(*) FROM not_found_log WHERE ignored = 0')->fetchColumn() : 0;
    }

    public function notFoundIgnoredCount(): int
    {
        return $this->isAvailable() ? (int)$this->pdo()->query('SELECT COUNT(*) FROM not_found_log WHERE ignored = 1')->fetchColumn() : 0;
    }

    /** @return array<int, array<string, mixed>> the listed addresses, or (with $ignored) the ones chosen to be ignored */
    public function notFound(string $q = '', int $limit = 100, bool $ignored = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM not_found_log WHERE ignored = :ignored' . ($q !== '' ? ' AND path LIKE :q ESCAPE \'\\\'' : '')
            . ' ORDER BY hits DESC, last_seen_at DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute(['ignored' => $ignored ? 1 : 0] + ($q !== '' ? ['q' => self::likePattern($q)] : []));
        return $stmt->fetchAll() ?: [];
    }

    public function deleteNotFound(string $path): void
    {
        if ($this->isAvailable()) {
            $this->pdo()->prepare('DELETE FROM not_found_log WHERE path = :path')->execute(['path' => self::normalizePath($path)]);
        }
    }

    /** Hides an address from the list for good: it is still counted when asked for, but it does not come back. */
    public function ignoreNotFound(string $path): void
    {
        $this->setIgnored(self::normalizePath($path), '', true);
    }

    /** Hides every listed address (only those matching $q when it is given) and returns how many. */
    public function ignoreAllNotFound(string $q = ''): int
    {
        return $this->setIgnored(null, $q, true);
    }

    /** Lists an ignored address again. */
    public function restoreNotFound(string $path): void
    {
        $this->setIgnored(self::normalizePath($path), '', false);
    }

    /** Lists every ignored address again (only those matching $q when it is given) and returns how many. */
    public function restoreAllNotFound(string $q = ''): int
    {
        return $this->setIgnored(null, $q, false);
    }

    /** Empties the list; the addresses chosen to be ignored are kept so they stay hidden. */
    public function clearNotFound(): void
    {
        if ($this->isAvailable()) {
            $this->pdo()->exec('DELETE FROM not_found_log WHERE ignored = 0');
        }
    }

    /** @param ?string $path one address, or null for all that are in the other state (narrowed by $q) */
    private function setIgnored(?string $path, string $q, bool $ignored): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $sql = 'UPDATE not_found_log SET ignored = :new WHERE ignored = :old';
        $params = ['new' => $ignored ? 1 : 0, 'old' => $ignored ? 0 : 1];
        if ($path !== null) {
            $sql .= ' AND path = :path';
            $params['path'] = $path;
        } elseif ($q !== '') {
            $sql .= ' AND path LIKE :q ESCAPE \'\\\'';
            $params['q'] = self::likePattern($q);
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function likePattern(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q, 'UTF-8')) . '%';
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
