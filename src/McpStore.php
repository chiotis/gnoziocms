<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * What the MCP server keeps (docs/mcp.md): OAuth clients, the codes of a consent, connections and their tokens, and the counters
 * of the rate limits. A connection is one OAuth grant or one token made in the admin; it acts as one account. Codes and tokens are
 * kept only as SHA-256 hashes, so a copy of the database gives nobody a way in.
 */
final class McpStore
{
    public const ACCESS_SECONDS = 3600;
    public const REFRESH_SECONDS = 90 * 86400;
    public const CODE_SECONDS = 300;
    /** How long a refresh token that was just exchanged still works, so two refreshes at once do not end the connection. */
    public const REFRESH_GRACE_SECONDS = 60;
    public const MAX_TOKEN_DAYS = 365;
    public const DEFAULT_DAILY_LIMIT = 10;
    /** The most a price may move in one change, in percent (0: no limit). */
    public const DEFAULT_PRICE_LIMIT = 50;
    /** Changes to the shop (a product's prices, a row of stock) in 24 hours. */
    public const DEFAULT_SHOP_LIMIT = 500;

    /** @param \Closure(): int|null $clock the time, for tests */
    public function __construct(private SystemDatabase $database, private ?\Closure $clock = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    // ---- clients

    /** @param string[] $redirectUris @return array<string, mixed> */
    public function registerClient(string $name, array $redirectUris): array
    {
        $id = 'gnozio_' . self::random(24);
        $this->pdo()->prepare('INSERT INTO mcp_clients (client_id, client_name, redirect_uris, created_at) VALUES (:id, :name, :uris, :now)')
            ->execute(['id' => $id, 'name' => $name, 'uris' => json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES), 'now' => $this->stamp()]);
        return $this->client($id) ?? [];
    }

    /** @return array{client_id: string, client_name: string, redirect_uris: string[]}|null a client that was not revoked */
    public function client(string $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT client_id, client_name, redirect_uris FROM mcp_clients WHERE client_id = :id AND revoked_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $uris = json_decode((string)$row['redirect_uris'], true);
        return ['client_id' => (string)$row['client_id'], 'client_name' => (string)$row['client_name'], 'redirect_uris' => is_array($uris) ? array_map('strval', $uris) : []];
    }

    // ---- codes

    /** @param array{client_id: string, user_id: int, approved_by: int, redirect_uri: string, scope: string, code_challenge: string, resource: string} $data @return string the code */
    public function createCode(array $data): string
    {
        $code = 'gc_' . self::random(32);
        $this->pdo()->prepare(
            'INSERT INTO mcp_codes (code_hash, client_id, user_id, approved_by, redirect_uri, scope, code_challenge, resource, created_at, expires_at)
             VALUES (:hash, :client, :user, :by, :uri, :scope, :challenge, :resource, :now, :expires)'
        )->execute([
            'hash' => self::hash($code), 'client' => $data['client_id'], 'user' => $data['user_id'], 'by' => $data['approved_by'],
            'uri' => $data['redirect_uri'], 'scope' => $data['scope'], 'challenge' => $data['code_challenge'], 'resource' => $data['resource'],
            'now' => $this->stamp(), 'expires' => $this->stamp(self::CODE_SECONDS),
        ]);
        return $code;
    }

    /** The code's grant, once: a second use, an old code or another client's finds nothing. @return array<string, mixed>|null */
    public function consumeCode(string $code, string $clientId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM mcp_codes WHERE code_hash = :hash AND client_id = :client AND used_at IS NULL AND expires_at > :now');
        $stmt->execute(['hash' => self::hash($code), 'client' => $clientId, 'now' => $this->stamp()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $use = $this->pdo()->prepare('UPDATE mcp_codes SET used_at = :now WHERE id = :id AND used_at IS NULL');
        $use->execute(['now' => $this->stamp(), 'id' => (int)$row['id']]);
        return $use->rowCount() === 1 ? $row : null;
    }

    // ---- connections and tokens

    public function createConnection(string $kind, string $name, ?string $clientId, int $userId, ?int $approvedBy, string $scope, string $resource): int
    {
        $this->pdo()->prepare(
            'INSERT INTO mcp_connections (kind, name, client_id, user_id, approved_by, scope, resource, created_at)
             VALUES (:kind, :name, :client, :user, :by, :scope, :resource, :now)'
        )->execute(['kind' => $kind, 'name' => $name, 'client' => $clientId, 'user' => $userId, 'by' => $approvedBy, 'scope' => $scope, 'resource' => $resource, 'now' => $this->stamp()]);
        return (int)$this->pdo()->lastInsertId();
    }

    /**
     * A new pair of tokens for a connection: an access token for an hour (or `$accessSeconds`), and a refresh token unless
     * `$refresh` is false (a token made in the admin lasts as long as it was given and has none).
     *
     * @return array{access_token: string, expires_in: int, refresh_token?: string}
     */
    public function issueTokens(int $connectionId, bool $refresh = true, int $accessSeconds = self::ACCESS_SECONDS): array
    {
        $access = 'gat_' . self::random(32);
        $refreshToken = $refresh ? 'grt_' . self::random(32) : null;
        $this->pdo()->prepare(
            'INSERT INTO mcp_tokens (connection_id, access_hash, access_expires_at, refresh_hash, refresh_expires_at, created_at)
             VALUES (:connection, :access, :access_expires, :refresh, :refresh_expires, :now)'
        )->execute([
            'connection' => $connectionId, 'access' => self::hash($access), 'access_expires' => $this->stamp($accessSeconds),
            'refresh' => $refreshToken !== null ? self::hash($refreshToken) : null, 'refresh_expires' => $refreshToken !== null ? $this->stamp(self::REFRESH_SECONDS) : null,
            'now' => $this->stamp(),
        ]);
        if (random_int(1, 50) === 1) {
            $this->cleanUp();
        }
        $tokens = ['access_token' => $access, 'expires_in' => $accessSeconds];
        if ($refreshToken !== null) {
            $tokens['refresh_token'] = $refreshToken;
        }
        return $tokens;
    }

    /**
     * A new pair for a refresh token of `$clientId`'s connection to `$resource`. The old pair is marked replaced; within
     * REFRESH_GRACE_SECONDS of that it can be exchanged once more (two refreshes sent at the same time), never after.
     *
     * @return array{tokens: array{access_token: string, expires_in: int, refresh_token?: string}, connection: array<string, mixed>}|null
     */
    public function refresh(string $refreshToken, string $clientId, string $resource): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT t.id AS token_id, t.replaced_at, c.* FROM mcp_tokens t JOIN mcp_connections c ON c.id = t.connection_id
             WHERE t.refresh_hash = :hash AND t.refresh_expires_at > :now AND c.client_id = :client AND c.resource = :resource AND c.revoked_at IS NULL'
        );
        $stmt->execute(['hash' => self::hash($refreshToken), 'now' => $this->stamp(), 'client' => $clientId, 'resource' => $resource]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['replaced_at'] !== null && (string)$row['replaced_at'] <= $this->stamp(-self::REFRESH_GRACE_SECONDS)) {
            return null;
        }
        if ($row['replaced_at'] === null) {
            $this->pdo()->prepare('UPDATE mcp_tokens SET replaced_at = :now WHERE id = :id')->execute(['now' => $this->stamp(), 'id' => (int)$row['token_id']]);
        }
        return ['tokens' => $this->issueTokens((int)$row['id']), 'connection' => $row];
    }

    /**
     * The connection an access token belongs to, with its account (`username`, `role`, `user_status`, `source`), when the token
     * is current and the connection was not revoked; null otherwise. A paused connection is returned too, with `paused_at` set.
     *
     * @return array<string, mixed>|null
     */
    public function connectionForAccess(string $accessToken, string $resource): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT c.*, u.username, u.display_name, u.role, u.status AS user_status, u.source
             FROM mcp_tokens t JOIN mcp_connections c ON c.id = t.connection_id JOIN users u ON u.id = c.user_id
             WHERE t.access_hash = :hash AND t.access_expires_at > :now AND c.resource = :resource AND c.revoked_at IS NULL'
        );
        $stmt->execute(['hash' => self::hash($accessToken), 'now' => $this->stamp(), 'resource' => $resource]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $this->pdo()->prepare('UPDATE mcp_connections SET last_used_at = :now WHERE id = :id')->execute(['now' => $this->stamp(), 'id' => (int)$row['id']]);
        return $row;
    }

    /** @return array<int, array<string, mixed>> every connection that was not revoked, newest first, with its account and when it ends */
    public function connections(): array
    {
        $rows = $this->pdo()->query(
            'SELECT c.*, u.username, u.display_name, u.source,
                    (SELECT MAX(COALESCE(t.refresh_expires_at, t.access_expires_at)) FROM mcp_tokens t WHERE t.connection_id = c.id) AS ends_at
             FROM mcp_connections c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.revoked_at IS NULL ORDER BY c.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function connection(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM mcp_connections WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function setPaused(int $id, bool $paused): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE mcp_connections SET paused_at = :at WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['at' => $paused ? $this->stamp() : null, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** Ends a connection and every token of it at once. */
    public function revoke(int $id): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE mcp_connections SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['now' => $this->stamp(), 'id' => $id]);
        $this->pdo()->prepare('DELETE FROM mcp_tokens WHERE connection_id = :id')->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * What a connection may do beyond its scopes, set in Admin > MCP: the content types and languages it may write (none: all),
     * at most how many entries it publishes or schedules in 24 hours, the byline written as `author` when the job gives none, the
     * most a price may move in one change (percent) and at most how many changes to the shop it makes in 24 hours.
     *
     * @param array<string, mixed> $connection
     * @return array{types: string[], languages: string[], daily_limit: int, byline: string, price_limit: int, shop_limit: int}
     */
    public static function policy(array $connection): array
    {
        $raw = json_decode((string)($connection['policy'] ?? '{}'), true);
        $raw = is_array($raw) ? $raw : [];
        return [
            'types' => array_values(array_map('strval', is_array($raw['types'] ?? null) ? $raw['types'] : [])),
            'languages' => array_values(array_map('strval', is_array($raw['languages'] ?? null) ? $raw['languages'] : [])),
            'daily_limit' => max(0, min(500, (int)($raw['daily_limit'] ?? self::DEFAULT_DAILY_LIMIT))),
            'byline' => mb_substr(trim((string)($raw['byline'] ?? '')), 0, 120),
            'price_limit' => max(0, min(1000, (int)($raw['price_limit'] ?? self::DEFAULT_PRICE_LIMIT))),
            'shop_limit' => max(0, min(10000, (int)($raw['shop_limit'] ?? self::DEFAULT_SHOP_LIMIT))),
        ];
    }

    /** @param array{types?: string[], languages?: string[], daily_limit?: int, byline?: string, price_limit?: int, shop_limit?: int} $policy */
    public function setPolicy(int $id, array $policy): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE mcp_connections SET policy = :policy WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['policy' => json_encode(self::policy(['policy' => json_encode($policy)]), JSON_UNESCAPED_UNICODE), 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    // ---- sources and publications

    /** @return array{type: string, slug: string, lang: string, connection_id: int}|null the entry made for a source */
    public function source(string $key): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT type, slug, lang, connection_id FROM mcp_sources WHERE source_key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['type' => (string)$row['type'], 'slug' => (string)$row['slug'], 'lang' => (string)$row['lang'], 'connection_id' => (int)$row['connection_id']] : null;
    }

    public function rememberSource(string $key, int $connectionId, string $type, string $slug, string $lang): void
    {
        $this->pdo()->prepare('INSERT OR REPLACE INTO mcp_sources (source_key, connection_id, type, slug, lang, created_at) VALUES (:key, :connection, :type, :slug, :lang, :now)')
            ->execute(['key' => $key, 'connection' => $connectionId, 'type' => $type, 'slug' => $slug, 'lang' => $lang, 'now' => $this->stamp()]);
    }

    /** How many entries a connection published or scheduled in the last 24 hours. */
    public function publishedToday(int $connectionId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM mcp_publications WHERE connection_id = :connection AND published_at > :since');
        $stmt->execute(['connection' => $connectionId, 'since' => $this->stamp(-86400)]);
        return (int)$stmt->fetchColumn();
    }

    public function recordPublication(int $connectionId, string $type, string $slug, string $lang): void
    {
        $this->pdo()->prepare('INSERT INTO mcp_publications (connection_id, type, slug, lang, published_at) VALUES (:connection, :type, :slug, :lang, :now)')
            ->execute(['connection' => $connectionId, 'type' => $type, 'slug' => $slug, 'lang' => $lang, 'now' => $this->stamp()]);
    }

    /** How many changes to the shop a connection made in the last 24 hours. */
    public function shopChangesToday(int $connectionId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COALESCE(SUM(changes), 0) FROM mcp_shop_changes WHERE connection_id = :connection AND changed_at > :since');
        $stmt->execute(['connection' => $connectionId, 'since' => $this->stamp(-86400)]);
        return (int)$stmt->fetchColumn();
    }

    public function recordShopChanges(int $connectionId, int $changes): void
    {
        if ($changes > 0) {
            $this->pdo()->prepare('INSERT INTO mcp_shop_changes (connection_id, changes, changed_at) VALUES (:connection, :changes, :now)')
                ->execute(['connection' => $connectionId, 'changes' => $changes, 'now' => $this->stamp()]);
        }
    }

    // ---- rate limits

    /** A fixed window per bucket and identifier (kept as a hash). @return array{allowed: bool, retry_after: int, first_blocked: bool} */
    public function hit(string $bucket, string $identifier, int $limit, int $windowSeconds): array
    {
        $now = $this->now();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $key = self::hash($bucket . ':' . $identifier);
        $this->pdo()->prepare(
            'INSERT INTO mcp_rate_limits (rate_key, window_start, hits) VALUES (:key, :window, 1)
             ON CONFLICT(rate_key) DO UPDATE SET hits = CASE WHEN window_start = excluded.window_start THEN hits + 1 ELSE 1 END, window_start = excluded.window_start'
        )->execute(['key' => $key, 'window' => $window]);
        $stmt = $this->pdo()->prepare('SELECT hits FROM mcp_rate_limits WHERE rate_key = :key');
        $stmt->execute(['key' => $key]);
        $hits = (int)$stmt->fetchColumn();
        return ['allowed' => $hits <= $limit, 'retry_after' => max(1, $window + $windowSeconds - $now), 'first_blocked' => $hits === $limit + 1];
    }

    // ----

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Old codes, tokens and counters, so the tables stay small without a job of their own. */
    public function cleanUp(): void
    {
        $day = $this->stamp(-86400);
        $this->pdo()->prepare('DELETE FROM mcp_codes WHERE expires_at < :day')->execute(['day' => $day]);
        $this->pdo()->prepare('DELETE FROM mcp_tokens WHERE COALESCE(refresh_expires_at, access_expires_at) < :now OR (replaced_at IS NOT NULL AND replaced_at < :day AND access_expires_at < :now)')
            ->execute(['now' => $this->stamp(), 'day' => $day]);
        $this->pdo()->prepare('DELETE FROM mcp_rate_limits WHERE window_start < :old')->execute(['old' => $this->now() - 86400]);
        $this->pdo()->prepare('DELETE FROM mcp_publications WHERE published_at < :day')->execute(['day' => $this->stamp(-2 * 86400)]);
        $this->pdo()->prepare('DELETE FROM mcp_shop_changes WHERE changed_at < :day')->execute(['day' => $this->stamp(-2 * 86400)]);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** A UTC time as the tables keep it (sortable text), `$offset` seconds from now. */
    private function stamp(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', $this->now() + $offset);
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
