<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use GnozioCMS\SystemMetaRepository;
use PDO;

/**
 * Digital products (docs/shop-plan.md, section 6). A product's files are kept under storage/shop-files with a random name, never
 * under public/ (table shop_files). When an order is paid, each file of each of its products becomes a download of that order
 * (shop_downloads) with the product's limit on how many times and for how many days; cancelling or refunding the order turns
 * them off. A download's link is its id and an HMAC of it with the shop's own key, so the same link works in the email, on the
 * order's page and in the customer's account, and no other id can be guessed from it.
 */
final class ShopDownloads
{
    public const KEY_META = 'shop_download_key';
    public const MAX_BYTES = 512 * 1024 * 1024;

    /**
     * @param \Closure(string): array{limit: int, days: int} $rules a product's download limit and days (0: no limit)
     */
    public function __construct(
        private SystemDatabase $database,
        private SystemMetaRepository $meta,
        private string $dir,
        private string $siteUrl,
        private \Closure $rules
    ) {
    }

    // ---- files

    /**
     * Keeps an uploaded file for a product.
     *
     * @return array{ok: bool, message: string}
     */
    public function add(string $productId, string $tmpPath, string $name, bool $uploaded = true): array
    {
        $name = self::cleanName($name);
        if ($productId === '' || $name === '' || !is_file($tmpPath)) {
            return ['ok' => false, 'message' => 'Choose a product and a file.'];
        }
        $size = (int)filesize($tmpPath);
        if ($size < 1 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'A file is between 1 byte and 512 MB.'];
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true)) {
            return ['ok' => false, 'message' => 'The folder for the files could not be made (storage/shop-files).'];
        }
        $this->protect();
        $stored = bin2hex(random_bytes(16)) . '.bin';
        $target = $this->dir . '/' . $stored;
        $moved = $uploaded ? @move_uploaded_file($tmpPath, $target) : @copy($tmpPath, $target);
        if (!$moved) {
            return ['ok' => false, 'message' => 'The file could not be kept.'];
        }
        $mime = function_exists('mime_content_type') ? (string)(@mime_content_type($target) ?: '') : '';
        $this->pdo()->prepare('INSERT INTO shop_files (product_id, name, stored, size, mime, created_at) VALUES (:product, :name, :stored, :size, :mime, :now)')
            ->execute(['product' => $productId, 'name' => $name, 'stored' => $stored, 'size' => $size, 'mime' => $mime !== '' ? $mime : 'application/octet-stream', 'now' => time()]);
        return ['ok' => true, 'message' => $name . ' is kept.'];
    }

    public function remove(int $id): bool
    {
        $file = $this->file($id);
        if ($file === null) {
            return false;
        }
        @unlink($this->dir . '/' . $file['stored']);
        $this->pdo()->prepare('DELETE FROM shop_files WHERE id = :id')->execute(['id' => $id]);
        return true;
    }

    /** @return array<string, mixed>|null */
    public function file(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_files WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::fileRow($row) : null;
    }

    /** @return list<array<string, mixed>> a product's files, oldest first */
    public function files(string $productId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_files WHERE product_id = :product ORDER BY id');
        $stmt->execute(['product' => $productId]);
        return array_map(self::fileRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, list<array<string, mixed>>> every product's files, by product */
    public function allFiles(): array
    {
        $out = [];
        foreach ($this->pdo()->query('SELECT * FROM shop_files ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['product_id']][] = self::fileRow($row);
        }
        return $out;
    }

    public function path(array $file): string
    {
        return $this->dir . '/' . basename((string)$file['stored']);
    }

    // ---- what an order may download

    /**
     * Gives a paid order its downloads: each file of each product in it, once (paying twice gives nothing more).
     *
     * @param list<array<string, mixed>> $lines the order's lines
     * @return int how many were given
     */
    public function grant(int $orderId, array $lines): int
    {
        $count = 0;
        $seen = [];
        foreach ($lines as $line) {
            $product = (string)$line['product_id'];
            if (isset($seen[$product])) {
                continue;
            }
            $seen[$product] = true;
            $files = $this->files($product);
            if ($files === []) {
                continue;
            }
            $rules = ($this->rules)($product);
            foreach ($files as $file) {
                $stmt = $this->pdo()->prepare('INSERT OR IGNORE INTO shop_downloads (order_id, file_id, title, name, max_downloads, expires_at, created_at) VALUES (:order, :file, :title, :name, :max, :until, :now)');
                $stmt->execute([
                    'order' => $orderId,
                    'file' => $file['id'],
                    'title' => mb_substr((string)$line['title'], 0, 200),
                    'name' => $file['name'],
                    'max' => max(0, $rules['limit']),
                    'until' => $rules['days'] > 0 ? time() + 86400 * $rules['days'] : 0,
                    'now' => time(),
                ]);
                $count += $stmt->rowCount();
            }
        }
        if ($count === 0) {
            // Paid again after a cancel: what was given before comes back.
            $this->pdo()->prepare('UPDATE shop_downloads SET active = 1 WHERE order_id = :order')->execute(['order' => $orderId]);
        }
        return $count;
    }

    /** Cancelled or refunded: the order's links stop working. */
    public function revoke(int $orderId): void
    {
        $this->pdo()->prepare('UPDATE shop_downloads SET active = 0 WHERE order_id = :order')->execute(['order' => $orderId]);
    }

    /** From the order's screen: the count back to zero and the days counted from now. */
    public function reset(int $orderId, int $downloadId): bool
    {
        $row = $this->download($downloadId);
        if ($row === null || $row['order_id'] !== $orderId) {
            return false;
        }
        $file = $this->file($row['file_id']);
        $days = $file !== null ? ($this->rules)((string)$file['product_id'])['days'] : 0;
        $this->pdo()->prepare('UPDATE shop_downloads SET downloads = 0, expires_at = :until, active = 1 WHERE id = :id')
            ->execute(['until' => $days > 0 ? time() + 86400 * $days : 0, 'id' => $downloadId]);
        return true;
    }

    /** @return array<string, mixed>|null */
    public function download(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_downloads WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::downloadRow($row) : null;
    }

    /**
     * An order's downloads as a page, an email or the order's screen shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_downloads WHERE order_id = :order ORDER BY id');
        $stmt->execute(['order' => $orderId]);
        return array_map(fn(array $row): array => $this->view(self::downloadRow($row)), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A customer's downloads, over all their orders, newest first.
     *
     * @param list<int> $orderIds
     * @return list<array<string, mixed>>
     */
    public function forOrders(array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $rows = $this->pdo()->query('SELECT * FROM shop_downloads WHERE order_id IN (' . implode(',', $ids) . ') ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->view(self::downloadRow($row)), $rows);
    }

    /**
     * The download a link stands for, when it may be had now: why not otherwise (gone, off, used, expired).
     *
     * @return array{ok: bool, reason: string, download: array<string, mixed>|null, file: array<string, mixed>|null}
     */
    public function open(string $token): array
    {
        $none = ['ok' => false, 'reason' => 'gone', 'download' => null, 'file' => null];
        if (preg_match('/^(\d{1,12})-([a-f0-9]{32})$/', $token, $m) !== 1 || !hash_equals($this->sign((int)$m[1]), $m[2])) {
            return $none;
        }
        $download = $this->download((int)$m[1]);
        $file = $download !== null ? $this->file($download['file_id']) : null;
        if ($download === null || $file === null || !is_file($this->path($file))) {
            return $none;
        }
        $reason = match (true) {
            !$download['active'] => 'off',
            $download['max_downloads'] > 0 && $download['downloads'] >= $download['max_downloads'] => 'used',
            $download['expires_at'] > 0 && $download['expires_at'] < time() => 'expired',
            default => '',
        };
        return ['ok' => $reason === '', 'reason' => $reason, 'download' => $download, 'file' => $file];
    }

    /** One more download counted, only while it is still allowed (two at once cannot pass the limit). */
    public function count(int $id): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE shop_downloads SET downloads = downloads + 1, last_at = :now WHERE id = :id AND active = 1 AND (max_downloads = 0 OR downloads < max_downloads)');
        $stmt->execute(['now' => time(), 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public function url(int $id): string
    {
        return rtrim($this->siteUrl, '/') . '/_shop/download/' . $id . '-' . $this->sign($id);
    }

    /** A file's name as it is offered: letters, digits, dots, dashes, spaces; no folders. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $name);
        return mb_substr(trim($name, " ._"), 0, 160);
    }

    /** @param array<string, mixed> $download @return array<string, mixed> */
    private function view(array $download): array
    {
        $left = $download['max_downloads'] > 0 ? max(0, $download['max_downloads'] - $download['downloads']) : null;
        $usable = $download['active'] && ($left === null || $left > 0) && ($download['expires_at'] === 0 || $download['expires_at'] >= time());
        return $download + ['url' => $this->url($download['id']), 'left' => $left, 'usable' => $usable];
    }

    private function sign(int $id): string
    {
        $key = $this->meta->getJson(self::KEY_META)['key'] ?? '';
        if (!is_string($key) || strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            $this->meta->setJson(self::KEY_META, ['key' => $key]);
        }
        return substr(hash_hmac('sha256', 'download:' . $id, $key), 0, 32);
    }

    /** No web server lists or serves the folder, should it ever be under the site's root. */
    private function protect(): void
    {
        if (!is_file($this->dir . '/.htaccess')) {
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($this->dir . '/index.html')) {
            @file_put_contents($this->dir . '/index.html', '');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    /**
     * How each file has done: the orders given a link to it, the times it was downloaded, and when last.
     *
     * @return array<int, array{links: int, downloads: int, last_at: int}> by file id; a file nobody has bought is not in it
     */
    public function stats(): array
    {
        $out = [];
        foreach ($this->pdo()->query('SELECT file_id, COUNT(*) AS links, COALESCE(SUM(downloads), 0) AS downloads, MAX(last_at) AS last_at FROM shop_downloads GROUP BY file_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['file_id']] = ['links' => (int)$row['links'], 'downloads' => (int)$row['downloads'], 'last_at' => (int)$row['last_at']];
        }
        return $out;
    }

    private static function fileRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['size'] = (int)$row['size'];
        $row['created_at'] = (int)$row['created_at'];
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function downloadRow(array $row): array
    {
        foreach (['id', 'order_id', 'file_id', 'downloads', 'max_downloads', 'expires_at', 'last_at', 'created_at'] as $int) {
            $row[$int] = (int)$row[$int];
        }
        $row['active'] = (bool)$row['active'];
        return $row;
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
