<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;
use Throwable;

final class SystemDatabase
{
    private string $path;
    private ?PDO $pdo = null;
    private ?string $lastError = null;

    public function __construct(string $storageDir)
    {
        $this->path = rtrim($storageDir, '/') . '/db/app.sqlite';
    }

    public function initialize(): void
    {
        $this->lastError = null;
        try {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                throw new \RuntimeException('PDO SQLite driver is not available.');
            }

            $dir = dirname($this->path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $pdo = $this->connection();
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $this->migrate($pdo);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->pdo = null;
        }
    }

    /** Flushes the WAL into the main file and drops the connection (used before swapping the file). */
    public function close(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (Throwable) {
                // Closing must not fail because of a busy checkpoint.
            }
        }
        $this->pdo = null;
    }

    public function isAvailable(): bool
    {
        return $this->pdo !== null && $this->lastError === null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $this->pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $this->pdo;
    }

    private function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $this->applyMigration($pdo, '202607060001_system_foundation', fn(PDO $db) => $this->createFoundationSchema($db));
        $this->applyMigration($pdo, '202607060002_users_google_auth', fn(PDO $db) => $this->addGoogleAuthColumns($db));
        $this->applyMigration($pdo, '202609280001_login_attempts', fn(PDO $db) => $this->createLoginAttemptsTable($db));
        $this->applyMigration($pdo, '202609280002_content_index_search', fn(PDO $db) => $this->addContentIndexSearchColumns($db));
        $this->applyMigration($pdo, '202609290001_redirects', fn(PDO $db) => $this->createRedirectTables($db));
        $this->applyMigration($pdo, '202609290002_content_revisions', fn(PDO $db) => $this->createRevisionTable($db));
        $this->applyMigration($pdo, '202610030001_analytics', fn(PDO $db) => $this->createAnalyticsTables($db));
        $this->applyMigration($pdo, '202610130001_not_found_ignored', fn(PDO $db) => $this->addNotFoundIgnoredColumn($db));
        $this->applyMigration($pdo, '202610190001_content_relations', fn(PDO $db) => $this->createContentRelations($db));
        $this->applyMigration($pdo, '202610190002_content_title_key', fn(PDO $db) => $this->addContentTitleKey($db));
        $this->applyMigration($pdo, '202610200001_archive_query', fn(PDO $db) => $this->createArchiveQuery($db));
    }

    /**
     * The id every language of an entry shares and a small card of it (title, excerpt, picture, date) in the content index, and
     * the references between entries (content_refs). All of it is read from the files and can be rebuilt at any time.
     */
    private function createContentRelations(PDO $pdo): void
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(content_index)') as $row) {
            $columns[] = (string)$row['name'];
        }
        if (!in_array('translation_id', $columns, true)) {
            $pdo->exec("ALTER TABLE content_index ADD COLUMN translation_id TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('summary', $columns, true)) {
            $pdo->exec("ALTER TABLE content_index ADD COLUMN summary TEXT NOT NULL DEFAULT ''");
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_index_translation ON content_index (translation_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS content_refs (
                from_type TEXT NOT NULL,
                from_slug TEXT NOT NULL,
                from_lang TEXT NOT NULL,
                field_path TEXT NOT NULL,
                row_path TEXT NOT NULL DEFAULT '',
                pos INTEGER NOT NULL DEFAULT 0,
                to_id TEXT NOT NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_refs_to ON content_refs (to_id, from_type, field_path)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_refs_from ON content_refs (from_type, from_slug, from_lang)');
    }

    /**
     * What a list of entries asks the index (ArchiveQuery): the date entries are ordered by, the terms each entry is filed under, and the
     * plain values of its fields (to filter and sort by). All of it is read from the files and can be rebuilt at any time.
     */
    private function createArchiveQuery(PDO $pdo): void
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(content_index)') as $row) {
            $columns[] = (string)$row['name'];
        }
        if (!in_array('sort_date', $columns, true)) {
            $pdo->exec('ALTER TABLE content_index ADD COLUMN sort_date INTEGER NOT NULL DEFAULT 0');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_index_listing ON content_index (type, lang, sort_date)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_terms (
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                lang TEXT NOT NULL,
                taxonomy TEXT NOT NULL,
                term_id TEXT NOT NULL,
                PRIMARY KEY (type, slug, lang, taxonomy, term_id)
            ) WITHOUT ROWID'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_terms_term ON content_terms (taxonomy, term_id, type, lang)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_values (
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                lang TEXT NOT NULL,
                field TEXT NOT NULL,
                raw TEXT NOT NULL,
                sort_text TEXT NOT NULL,
                sort_num REAL,
                PRIMARY KEY (type, slug, lang, field)
            ) WITHOUT ROWID'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_values_raw ON content_values (type, lang, field, raw)');
    }

    /** The title in lower case (any alphabet) so a search for a title is not case sensitive in Greek either; SQLite lowers ASCII only. */
    private function addContentTitleKey(PDO $pdo): void
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(content_index)') as $row) {
            $columns[] = (string)$row['name'];
        }
        if (!in_array('title_key', $columns, true)) {
            $pdo->exec("ALTER TABLE content_index ADD COLUMN title_key TEXT NOT NULL DEFAULT ''");
        }
    }

    /** Addresses the owner chose to ignore stay in the log (their visits are still counted) but are no longer listed. */
    private function addNotFoundIgnoredColumn(PDO $pdo): void
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(not_found_log)') as $row) {
            $columns[] = (string)$row['name'];
        }
        if (!in_array('ignored', $columns, true)) {
            $pdo->exec('ALTER TABLE not_found_log ADD COLUMN ignored INTEGER NOT NULL DEFAULT 0');
        }
    }

    private function addContentIndexSearchColumns(PDO $pdo): void
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(content_index)') as $row) {
            $columns[] = (string)$row['name'];
        }
        if (!in_array('mtime', $columns, true)) {
            $pdo->exec('ALTER TABLE content_index ADD COLUMN mtime INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('search_text', $columns, true)) {
            $pdo->exec('ALTER TABLE content_index ADD COLUMN search_text TEXT');
        }
    }

    private function createAnalyticsTables(PDO $pdo): void
    {
        // The visits of the last two days, one row for each page view or event, with no address and no cookie: visitor is a hash
        // that cannot be repeated tomorrow. Finished days are summed into analytics_daily and the rows go.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS analytics_hits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                day TEXT NOT NULL,
                visitor TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT \'view\',
                path TEXT NOT NULL DEFAULT \'\',
                name TEXT NOT NULL DEFAULT \'\',
                source TEXT NOT NULL DEFAULT \'\',
                channel TEXT NOT NULL DEFAULT \'direct\',
                campaign TEXT NOT NULL DEFAULT \'\',
                device TEXT NOT NULL DEFAULT \'\',
                browser TEXT NOT NULL DEFAULT \'\',
                os TEXT NOT NULL DEFAULT \'\',
                country TEXT NOT NULL DEFAULT \'\',
                lang TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_hits_day ON analytics_hits (day, visitor)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_hits_ts ON analytics_hits (ts)');
        // One row for each day, kind of count (total, page, entry, channel, source, ...) and value.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS analytics_daily (
                day TEXT NOT NULL,
                kind TEXT NOT NULL,
                key TEXT NOT NULL,
                views INTEGER NOT NULL DEFAULT 0,
                visitors INTEGER NOT NULL DEFAULT 0,
                bounces INTEGER NOT NULL DEFAULT 0,
                seconds INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, kind, key)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_analytics_daily_kind ON analytics_daily (kind, day)');
    }

    private function createRevisionTable(PDO $pdo): void
    {
        // One row per saved state of a content file. content is the whole file (front matter and body), deflated.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                lang TEXT NOT NULL,
                action TEXT NOT NULL,
                actor TEXT,
                title TEXT,
                status TEXT,
                checksum TEXT NOT NULL,
                size INTEGER NOT NULL DEFAULT 0,
                content BLOB NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_revisions_item ON content_revisions (type, slug, lang, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_revisions_created ON content_revisions (created_at)');
    }

    private function createRedirectTables(PDO $pdo): void
    {
        // source is the normalised path a visitor asks for (no leading or trailing slash, lower case).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS redirects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL UNIQUE,
                target TEXT NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 301,
                origin TEXT NOT NULL DEFAULT \'manual\',
                enabled INTEGER NOT NULL DEFAULT 1,
                note TEXT,
                content_type TEXT,
                hits INTEGER NOT NULL DEFAULT 0,
                last_hit_at TEXT,
                created_by TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_redirects_target ON redirects (target)');
        // Addresses visitors asked for that do not exist, so they can be fixed with a redirect.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS not_found_log (
                path TEXT PRIMARY KEY,
                hits INTEGER NOT NULL DEFAULT 1,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                last_referrer TEXT
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_not_found_last_seen ON not_found_log (last_seen_at)');
    }

    private function createLoginAttemptsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                address TEXT NOT NULL,
                username TEXT NOT NULL,
                succeeded INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_address ON login_attempts (address, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_username ON login_attempts (username, created_at)');
    }

    private function applyMigration(PDO $pdo, string $version, callable $callback): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
        $stmt->execute(['version' => $version]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $callback($pdo);
            $insert = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
            $insert->execute([
                'version' => $version,
                'applied_at' => gmdate('c'),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function createFoundationSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_meta (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT,
                display_name TEXT,
                password_hash TEXT,
                role TEXT NOT NULL DEFAULT "admin",
                status TEXT NOT NULL DEFAULT "active",
                source TEXT NOT NULL DEFAULT "sqlite",
                google_sub TEXT,
                google_email TEXT,
                last_login_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users (role)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_status ON users (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_google_sub ON users (google_sub)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                level TEXT NOT NULL DEFAULT "info",
                action TEXT NOT NULL,
                actor_username TEXT,
                actor_role TEXT,
                ip_address TEXT,
                user_agent TEXT,
                subject_type TEXT,
                subject_id TEXT,
                message TEXT,
                context_json TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs (action)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_actor ON activity_logs (actor_username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs (created_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient TEXT NOT NULL,
                subject TEXT NOT NULL,
                status TEXT NOT NULL,
                provider TEXT,
                error_message TEXT,
                context_json TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_logs_status ON email_logs (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_logs_created ON email_logs (created_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT,
                severity TEXT NOT NULL DEFAULT "info",
                is_read INTEGER NOT NULL DEFAULT 0,
                target_url TEXT,
                context_json TEXT,
                created_at TEXT NOT NULL,
                read_at TEXT
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications (is_read, created_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_index (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                lang TEXT NOT NULL,
                title TEXT,
                status TEXT,
                visible INTEGER NOT NULL DEFAULT 1,
                path TEXT NOT NULL,
                checksum TEXT,
                indexed_at TEXT NOT NULL,
                UNIQUE(type, slug, lang)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_index_type_lang ON content_index (type, lang)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_index_status ON content_index (status)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS backup_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT,
                status TEXT NOT NULL,
                size_bytes INTEGER,
                message TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_backup_runs_created ON backup_runs (created_at)');
    }

    private function addGoogleAuthColumns(PDO $pdo): void
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(users)') as $row) {
            $columns[] = (string)$row['name'];
        }
        if (!in_array('google_sub', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN google_sub TEXT');
        }
        if (!in_array('google_email', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN google_email TEXT');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_google_sub ON users (google_sub)');
    }
}
