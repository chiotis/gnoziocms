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
        $this->applyMigration($pdo, '202611020001_mcp', fn(PDO $db) => $this->createMcpTables($db));
        $this->applyMigration($pdo, '202611020002_mcp_sources', fn(PDO $db) => $this->createMcpSources($db));
        $this->applyMigration($pdo, '202611060001_jobs', fn(PDO $db) => $this->createJobsTable($db));
        $this->applyMigration($pdo, '202611060002_ai_usage', fn(PDO $db) => $this->createAiTables($db));
        $this->applyMigration($pdo, '202611060003_ai_schedules', fn(PDO $db) => $this->createAiScheduleTables($db));
        $this->applyMigration($pdo, '202611070001_ai_material', fn(PDO $db) => $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ai_material_used (
                schedule_id INTEGER NOT NULL,
                item_key TEXT NOT NULL,
                used_at INTEGER NOT NULL,
                PRIMARY KEY (schedule_id, item_key)
            )'
        ));
        $this->applyMigration($pdo, '202611070002_shop_stock', fn(PDO $db) => $this->createShopStockTable($db));
        $this->applyMigration($pdo, '202611070003_shop_orders', fn(PDO $db) => $this->createShopOrderTables($db));
        $this->applyMigration($pdo, '202611080001_shop_payments', fn(PDO $db) => $this->createShopPaymentTables($db));
        $this->applyMigration($pdo, '202611080002_shop_downloads', fn(PDO $db) => $this->createShopDownloadTables($db));
        $this->applyMigration($pdo, '202611080003_shop_subscriptions', fn(PDO $db) => $this->createShopSubscriptionTables($db));
        // What an order keeps, with the visitor's consent, for its purchase to be sent to Meta when it is paid (ShopMarketing).
        $this->applyMigration($pdo, '202611100001_shop_order_tracking', fn(PDO $db) => $db->exec(
            "CREATE TABLE IF NOT EXISTS shop_order_tracking (
                order_id INTEGER PRIMARY KEY,
                ip TEXT NOT NULL DEFAULT '',
                agent TEXT NOT NULL DEFAULT '',
                fbp TEXT NOT NULL DEFAULT '',
                fbc TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL
            )"
        ));
    }

    /**
     * The stock of the shop (docs/shop-plan.md): how many of a product, or of one of its variants, are on hand, and how many are held
     * for orders waiting to be paid. A product is its translation_id, so a new address or language changes nothing; a variant is its
     * combination of options as a key (ShopStock::variantKey()), '' for a product without variants. Kept here, not in the product's
     * file, so two orders at the same moment can never sell the same last piece.
     */
    private function createShopStockTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_stock (
                product_id TEXT NOT NULL,
                variant_key TEXT NOT NULL DEFAULT '',
                on_hand INTEGER NOT NULL DEFAULT 0,
                reserved INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (product_id, variant_key)
            )"
        );
    }

    /**
     * The shop's carts, orders, customers and coupons (docs/shop-plan.md, sections 3 and 4). Money is in cents. A cart is found by
     * the hash of its cookie, never the cookie itself. An order keeps what was sold as it was sold (title, price, VAT of each line),
     * so a product changed or removed later changes no order. Customers are the shop's, never admin users.
     */
    private function createShopOrderTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_carts (
                id TEXT PRIMARY KEY,
                customer_id INTEGER,
                lines TEXT NOT NULL DEFAULT '[]',
                coupon TEXT NOT NULL DEFAULT '',
                updated_at INTEGER NOT NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_carts_customer ON shop_carts (customer_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL DEFAULT '',
                phone TEXT NOT NULL DEFAULT '',
                lang TEXT NOT NULL DEFAULT '',
                address TEXT NOT NULL DEFAULT '{}',
                verified_at INTEGER,
                token_hash TEXT NOT NULL DEFAULT '',
                token_kind TEXT NOT NULL DEFAULT '',
                token_expires INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                last_login_at INTEGER
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                number TEXT NOT NULL UNIQUE,
                view_key TEXT NOT NULL,
                status TEXT NOT NULL,
                customer_id INTEGER,
                email TEXT NOT NULL COLLATE NOCASE,
                lang TEXT NOT NULL DEFAULT '',
                currency TEXT NOT NULL DEFAULT 'EUR',
                items_cents INTEGER NOT NULL DEFAULT 0,
                discount_cents INTEGER NOT NULL DEFAULT 0,
                shipping_cents INTEGER NOT NULL DEFAULT 0,
                fee_cents INTEGER NOT NULL DEFAULT 0,
                tax_cents INTEGER NOT NULL DEFAULT 0,
                total_cents INTEGER NOT NULL DEFAULT 0,
                coupon TEXT NOT NULL DEFAULT '',
                payment_method TEXT NOT NULL DEFAULT '',
                shipping_method TEXT NOT NULL DEFAULT '',
                shipping_label TEXT NOT NULL DEFAULT '',
                billing TEXT NOT NULL DEFAULT '{}',
                shipping TEXT NOT NULL DEFAULT '{}',
                gift_wrap INTEGER NOT NULL DEFAULT 0,
                gift_note TEXT NOT NULL DEFAULT '',
                customer_note TEXT NOT NULL DEFAULT '',
                carrier TEXT NOT NULL DEFAULT '',
                tracking TEXT NOT NULL DEFAULT '',
                stock_held INTEGER NOT NULL DEFAULT 0,
                reserve_until INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                paid_at INTEGER,
                updated_at INTEGER NOT NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_orders_customer ON shop_orders (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_orders_email ON shop_orders (email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_orders_status ON shop_orders (status, created_at)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_order_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                product_id TEXT NOT NULL,
                variant_key TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL,
                variant TEXT NOT NULL DEFAULT '',
                sku TEXT NOT NULL DEFAULT '',
                quantity INTEGER NOT NULL,
                unit_cents INTEGER NOT NULL,
                total_cents INTEGER NOT NULL,
                tax_rate REAL NOT NULL DEFAULT 0,
                tax_cents INTEGER NOT NULL DEFAULT 0,
                tracked INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_order_lines_order ON shop_order_lines (order_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_order_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                at INTEGER NOT NULL,
                kind TEXT NOT NULL,
                message TEXT NOT NULL DEFAULT '',
                actor TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_order_events_order ON shop_order_events (order_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_sequences (
                name TEXT PRIMARY KEY,
                value INTEGER NOT NULL
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_coupons (
                code TEXT PRIMARY KEY COLLATE NOCASE,
                kind TEXT NOT NULL,
                amount REAL NOT NULL DEFAULT 0,
                min_cents INTEGER NOT NULL DEFAULT 0,
                starts_at TEXT NOT NULL DEFAULT '',
                ends_at TEXT NOT NULL DEFAULT '',
                usage_limit INTEGER NOT NULL DEFAULT 0,
                per_customer INTEGER NOT NULL DEFAULT 0,
                collections TEXT NOT NULL DEFAULT '',
                active INTEGER NOT NULL DEFAULT 1,
                used INTEGER NOT NULL DEFAULT 0,
                note TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL
            )"
        );
    }

    /**
     * The shop's payments through a provider (docs/shop-plan.md, section 5): one row each time an order is sent to pay (the provider's
     * reference, then its transaction, the amount, what was refunded), and every call a provider made to the site, once by its key,
     * so a notification that comes twice changes nothing the second time.
     */
    private function createShopPaymentTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                provider TEXT NOT NULL,
                reference TEXT NOT NULL,
                transaction_id TEXT NOT NULL DEFAULT '',
                amount_cents INTEGER NOT NULL,
                refunded_cents INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                detail TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_payments_order ON shop_payments (order_id)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS shop_payments_reference ON shop_payments (provider, reference)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_gateway_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider TEXT NOT NULL,
                event_key TEXT NOT NULL,
                kind TEXT NOT NULL,
                order_id INTEGER,
                outcome TEXT NOT NULL DEFAULT '',
                at INTEGER NOT NULL,
                UNIQUE (provider, event_key)
            )"
        );
    }

    /**
     * Digital products (docs/shop-plan.md, section 6): the private files a product sells, kept under storage/shop-files with a
     * random name and never under public/; and what each paid order may download, by a signed link, with a count and an end.
     */
    private function createShopDownloadTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id TEXT NOT NULL,
                name TEXT NOT NULL,
                stored TEXT NOT NULL,
                size INTEGER NOT NULL DEFAULT 0,
                mime TEXT NOT NULL DEFAULT 'application/octet-stream',
                created_at INTEGER NOT NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_files_product ON shop_files (product_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_downloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                file_id INTEGER NOT NULL,
                title TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL DEFAULT '',
                downloads INTEGER NOT NULL DEFAULT 0,
                max_downloads INTEGER NOT NULL DEFAULT 0,
                expires_at INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                last_at INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                UNIQUE (order_id, file_id)
            )"
        );
    }

    /**
     * Subscriptions (docs/shop-plan.md, section 7): what a customer is subscribed to, at what price every period, the provider's
     * token it is charged again with, its status and when it is next due; and the orders of each (the first and every renewal).
     */
    private function createShopSubscriptionTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                product_id TEXT NOT NULL,
                variant_key TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL,
                variant TEXT NOT NULL DEFAULT '',
                sku TEXT NOT NULL DEFAULT '',
                quantity INTEGER NOT NULL DEFAULT 1,
                price_cents INTEGER NOT NULL,
                tax_rate REAL NOT NULL DEFAULT 0,
                interval TEXT NOT NULL,
                interval_count INTEGER NOT NULL DEFAULT 1,
                renewals INTEGER NOT NULL DEFAULT 0,
                renewed INTEGER NOT NULL DEFAULT 0,
                lang TEXT NOT NULL DEFAULT '',
                provider TEXT NOT NULL DEFAULT '',
                token TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL,
                next_at INTEGER NOT NULL DEFAULT 0,
                failures INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                ended_at INTEGER
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_subscriptions_due ON shop_subscriptions (status, next_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_subscriptions_customer ON shop_subscriptions (customer_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_subscription_orders (
                subscription_id INTEGER NOT NULL,
                order_id INTEGER NOT NULL,
                kind TEXT NOT NULL DEFAULT 'renewal',
                PRIMARY KEY (subscription_id, order_id)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS shop_subscription_orders_order ON shop_subscription_orders (order_id)');
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

    /**
     * The MCP server (src/McpServer.php, docs/mcp.md): the OAuth clients that registered, the codes of a consent, the connections
     * (an OAuth grant or a token made in the admin, each acting as one account) and their tokens, and the counters of the rate
     * limits. Only hashes of codes and tokens are kept.
     */
    private function createMcpTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mcp_clients (
                client_id TEXT PRIMARY KEY,
                client_name TEXT NOT NULL,
                redirect_uris TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_used_at TEXT,
                revoked_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mcp_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code_hash TEXT NOT NULL UNIQUE,
                client_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                approved_by INTEGER NOT NULL,
                redirect_uri TEXT NOT NULL,
                scope TEXT NOT NULL,
                code_challenge TEXT NOT NULL,
                resource TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mcp_connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                name TEXT NOT NULL,
                client_id TEXT,
                user_id INTEGER NOT NULL,
                approved_by INTEGER,
                scope TEXT NOT NULL,
                resource TEXT NOT NULL,
                policy TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NOT NULL,
                last_used_at TEXT,
                paused_at TEXT,
                revoked_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mcp_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                connection_id INTEGER NOT NULL,
                access_hash TEXT NOT NULL UNIQUE,
                access_expires_at TEXT NOT NULL,
                refresh_hash TEXT UNIQUE,
                refresh_expires_at TEXT,
                created_at TEXT NOT NULL,
                replaced_at TEXT
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_tokens_connection ON mcp_tokens (connection_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mcp_rate_limits (
                rate_key TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0
            ) WITHOUT ROWID'
        );
    }

    /**
     * What MCP connections wrote: the entry made for each source (a job that runs again finds it instead of writing it twice),
     * and each publication, for the daily limit of a connection.
     */
    /**
     * Background work (JobRunner): one row per job, claimed by one worker at a time through its lock, which runs out if the worker
     * dies. Times are Unix seconds, so ordering and comparing them needs no date parsing.
     */
    private function createJobsTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                payload TEXT NOT NULL DEFAULT '{}',
                priority INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'queued',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                run_after INTEGER NOT NULL DEFAULT 0,
                unique_key TEXT,
                locked_by TEXT NOT NULL DEFAULT '',
                locked_until INTEGER NOT NULL DEFAULT 0,
                result TEXT NOT NULL DEFAULT '',
                error TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                started_at INTEGER NOT NULL DEFAULT 0,
                finished_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_claim ON jobs (status, priority, run_after)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_kind ON jobs (kind, status)');
        // One live job per key (a picture for an entry, a schedule's run): a finished job frees its key.
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_jobs_unique_live ON jobs (unique_key) WHERE unique_key IS NOT NULL AND status IN ('queued', 'running')");
    }

    /**
     * AI publishing: one row per call to a provider (`ai_usage`, tokens and the estimated cost at the prices of the day), and one per
     * picture kept in the media library (`ai_pictures`: how it was made, for audit and for making it again).
     */
    private function createAiTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ai_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                at INTEGER NOT NULL,
                source TEXT NOT NULL,
                ref TEXT NOT NULL DEFAULT '',
                entry TEXT NOT NULL DEFAULT '',
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                kind TEXT NOT NULL,
                ok INTEGER NOT NULL DEFAULT 1,
                text_input INTEGER NOT NULL DEFAULT 0,
                cached_input INTEGER NOT NULL DEFAULT 0,
                cache_write INTEGER NOT NULL DEFAULT 0,
                image_input INTEGER NOT NULL DEFAULT 0,
                text_output INTEGER NOT NULL DEFAULT 0,
                reasoning INTEGER NOT NULL DEFAULT 0,
                image_output INTEGER NOT NULL DEFAULT 0,
                searches INTEGER NOT NULL DEFAULT 0,
                pictures INTEGER NOT NULL DEFAULT 0,
                cost REAL,
                prices TEXT NOT NULL DEFAULT '{}'
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_usage_at ON ai_usage (at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_usage_entry ON ai_usage (entry)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ai_pictures (
                media_id TEXT PRIMARY KEY,
                job_id INTEGER NOT NULL DEFAULT 0,
                entry TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '',
                prompt TEXT NOT NULL,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                size TEXT NOT NULL DEFAULT '',
                quality TEXT NOT NULL DEFAULT '',
                references_used TEXT NOT NULL DEFAULT '[]',
                made_by TEXT NOT NULL DEFAULT '',
                made_at INTEGER NOT NULL
            )"
        );
    }

    /**
     * AI publishing schedules: each writes one post per run (`ai_schedules`), and each run of a time slot is written down once
     * (`ai_schedule_slots`, primary key schedule + slot), so a slot never runs twice however often cron fires; the row also keeps
     * the post it made, whose title the next runs are told not to repeat.
     */
    private function createAiScheduleTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ai_schedules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ai_schedule_slots (
                schedule_id INTEGER NOT NULL,
                slot TEXT NOT NULL,
                job_id INTEGER NOT NULL DEFAULT 0,
                entry TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                PRIMARY KEY (schedule_id, slot)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_schedule_slots_created ON ai_schedule_slots (schedule_id, created_at)');
    }

    private function createMcpSources(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mcp_sources (
                source_key TEXT PRIMARY KEY,
                connection_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                lang TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mcp_publications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                connection_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                lang TEXT NOT NULL,
                published_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_publications_connection ON mcp_publications (connection_id, published_at)');
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
