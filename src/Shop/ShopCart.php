<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * Carts (table shop_carts, docs/shop-plan.md, section 3). A cart is found by its cookie (gz_cart, a random token kept for 30
 * days), stored by the token's hash. It holds only what was chosen: product, variant and quantity. Prices, names and stock are
 * read again from the products each time it is shown or ordered (ShopPricing), so a page kept by a cache can never set a price.
 */
final class ShopCart
{
    public const COOKIE = 'gz_cart';
    /** How many pieces are in the cart, readable by the page's script for the header's count; never trusted by the server. */
    public const COUNT_COOKIE = 'gz_cart_n';
    public const DAYS = 30;
    public const MAX_LINES = 50;
    public const MAX_QUANTITY = 99;

    public function __construct(private SystemDatabase $database)
    {
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function validToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    /** @return array{lines: list<array{product_id: string, variant_key: string, quantity: int}>, coupon: string, customer_id: ?int} */
    public function load(string $token): array
    {
        $empty = ['lines' => [], 'coupon' => '', 'customer_id' => null];
        if (!self::validToken($token) || !$this->database->isAvailable()) {
            return $empty;
        }
        $stmt = $this->pdo()->prepare('SELECT lines, coupon, customer_id FROM shop_carts WHERE id = :id');
        $stmt->execute(['id' => self::id($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $empty;
        }
        return ['lines' => self::clean(json_decode((string)$row['lines'], true)), 'coupon' => (string)$row['coupon'], 'customer_id' => $row['customer_id'] === null ? null : (int)$row['customer_id']];
    }

    /** @param list<array{product_id: string, variant_key: string, quantity: int}> $lines */
    public function save(string $token, array $lines, string $coupon = '', ?int $customerId = null): void
    {
        if (!self::validToken($token) || !$this->database->isAvailable()) {
            return;
        }
        $stmt = $this->pdo()->prepare(
            'INSERT INTO shop_carts (id, customer_id, lines, coupon, updated_at) VALUES (:id, :customer, :lines, :coupon, :now)
             ON CONFLICT (id) DO UPDATE SET customer_id = COALESCE(excluded.customer_id, shop_carts.customer_id), lines = excluded.lines, coupon = excluded.coupon, updated_at = excluded.updated_at'
        );
        $stmt->execute(['id' => self::id($token), 'customer' => $customerId, 'lines' => json_encode(self::clean($lines), JSON_UNESCAPED_UNICODE), 'coupon' => mb_substr($coupon, 0, 40), 'now' => time()]);
    }

    public function delete(string $token): void
    {
        if (self::validToken($token) && $this->database->isAvailable()) {
            $this->pdo()->prepare('DELETE FROM shop_carts WHERE id = :id')->execute(['id' => self::id($token)]);
        }
    }

    /**
     * When a customer signs in: what they left in a cart on another device joins this one, and that cart is let go.
     */
    public function adopt(string $token, int $customerId): void
    {
        if (!self::validToken($token) || !$this->database->isAvailable()) {
            return;
        }
        $mine = self::id($token);
        $stmt = $this->pdo()->prepare('SELECT id, lines FROM shop_carts WHERE customer_id = :customer AND id <> :mine ORDER BY updated_at DESC');
        $stmt->execute(['customer' => $customerId, 'mine' => $mine]);
        $current = $this->load($token);
        $lines = $current['lines'];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (self::clean(json_decode((string)$row['lines'], true)) as $line) {
                $lines = self::add($lines, $line['product_id'], $line['variant_key'], $line['quantity']);
            }
            $this->pdo()->prepare('DELETE FROM shop_carts WHERE id = :id')->execute(['id' => $row['id']]);
        }
        $this->save($token, $lines, $current['coupon'], $customerId);
    }

    /** Carts nobody has touched for DAYS are let go. */
    public function purge(): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }
        $stmt = $this->pdo()->prepare('DELETE FROM shop_carts WHERE updated_at < :before');
        $stmt->execute(['before' => time() - self::DAYS * 86400]);
        return $stmt->rowCount();
    }

    /**
     * @param list<array{product_id: string, variant_key: string, quantity: int}> $lines
     * @return list<array{product_id: string, variant_key: string, quantity: int}>
     */
    public static function add(array $lines, string $productId, string $variantKey, int $quantity): array
    {
        foreach ($lines as $i => $line) {
            if ($line['product_id'] === $productId && $line['variant_key'] === $variantKey) {
                $lines[$i]['quantity'] = min(self::MAX_QUANTITY, $line['quantity'] + max(1, $quantity));
                return $lines;
            }
        }
        if (count($lines) < self::MAX_LINES) {
            $lines[] = ['product_id' => $productId, 'variant_key' => $variantKey, 'quantity' => min(self::MAX_QUANTITY, max(1, $quantity))];
        }
        return $lines;
    }

    /**
     * Sets the quantity of a line, found by its key (ShopCart::key()); 0 takes it out.
     *
     * @param list<array{product_id: string, variant_key: string, quantity: int}> $lines
     * @return list<array{product_id: string, variant_key: string, quantity: int}>
     */
    public static function set(array $lines, string $key, int $quantity): array
    {
        $kept = [];
        foreach ($lines as $line) {
            if (self::key($line['product_id'], $line['variant_key']) === $key) {
                if ($quantity < 1) {
                    continue;
                }
                $line['quantity'] = min(self::MAX_QUANTITY, $quantity);
            }
            $kept[] = $line;
        }
        return $kept;
    }

    /** The key of a line in forms and scripts: the product and the variant. */
    public static function key(string $productId, string $variantKey): string
    {
        return substr(hash('sha256', $productId . "\0" . $variantKey), 0, 16);
    }

    /** @param list<array{quantity: int}> $lines */
    public static function count(array $lines): int
    {
        return array_sum(array_map(static fn(array $line): int => $line['quantity'], $lines));
    }

    /**
     * @param mixed $lines
     * @return list<array{product_id: string, variant_key: string, quantity: int}>
     */
    private static function clean(mixed $lines): array
    {
        $clean = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (!is_array($line) || count($clean) >= self::MAX_LINES) {
                continue;
            }
            $id = (string)($line['product_id'] ?? '');
            $quantity = (int)($line['quantity'] ?? 0);
            if ($id === '' || strlen($id) > 64 || $quantity < 1) {
                continue;
            }
            $clean[] = ['product_id' => $id, 'variant_key' => mb_substr((string)($line['variant_key'] ?? ''), 0, 200), 'quantity' => min(self::MAX_QUANTITY, $quantity)];
        }
        return $clean;
    }

    private static function id(string $token): string
    {
        return hash('sha256', $token);
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
