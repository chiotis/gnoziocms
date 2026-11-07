<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * The shop's stock (table shop_stock, docs/shop-plan.md): what is on hand for each product and each of its variants, and what
 * is held for orders waiting to be paid. Products are their translation_id; variants their combination of options as a key.
 * Holding and releasing are single UPDATE statements that check what is free, so two orders can never take the same last piece.
 */
final class ShopStock
{
    public const MAX = 1_000_000;

    public function __construct(private SystemDatabase $database)
    {
    }

    /** The key of a variant: its combination as written ("Gold / M"), lower case, one space around each slash; '' for none. */
    public static function variantKey(string $combination): string
    {
        $parts = array_filter(array_map(static fn(string $part): string => trim(preg_replace('/\s+/u', ' ', $part) ?? ''), explode('/', $combination)), static fn(string $part): bool => $part !== '');
        return mb_strtolower(implode(' / ', $parts));
    }

    /**
     * The rows the product editor's Stock box shows: the product itself when it has no variants, otherwise one per variant.
     *
     * @param array<string, mixed> $custom the product's custom_fields
     * @return list<array{key: string, label: string, on_hand: int, reserved: int}>
     */
    public function rowsFor(string $productId, array $custom): array
    {
        $stored = $productId === '' ? [] : $this->forProduct($productId);
        $rows = [];
        foreach (self::variantLabels($custom) as $key => $label) {
            $rows[] = ['key' => $key, 'label' => $label, 'on_hand' => $stored[$key]['on_hand'] ?? 0, 'reserved' => $stored[$key]['reserved'] ?? 0];
        }
        return $rows;
    }

    /**
     * The variants of a product as key => label: '' => '' for a product without any.
     *
     * @param array<string, mixed> $custom
     * @return array<string, string>
     */
    public static function variantLabels(array $custom): array
    {
        $labels = [];
        foreach (is_array($custom['variants'] ?? null) ? $custom['variants'] : [] as $variant) {
            $label = is_array($variant) ? trim((string)($variant['values'] ?? '')) : '';
            $key = self::variantKey($label);
            if ($key !== '' && !isset($labels[$key])) {
                $labels[$key] = $label;
            }
        }
        return $labels === [] ? ['' => ''] : $labels;
    }

    /** @return array<string, array{on_hand: int, reserved: int}> by variant key */
    public function forProduct(string $productId): array
    {
        if (!$this->database->isAvailable()) {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT variant_key, on_hand, reserved FROM shop_stock WHERE product_id = :id');
        $stmt->execute(['id' => $productId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string)$row['variant_key']] = ['on_hand' => (int)$row['on_hand'], 'reserved' => (int)$row['reserved']];
        }
        return $rows;
    }

    /** How many can still be sold: on hand less what is held. */
    public function available(string $productId, string $variantKey = ''): int
    {
        $row = $this->forProduct($productId)[$variantKey] ?? null;
        return $row === null ? 0 : max(0, $row['on_hand'] - $row['reserved']);
    }

    /**
     * Sets what is on hand, as the Stock box sends it: variant key => quantity. Keys the product no longer has are removed,
     * unless something is held for them.
     *
     * @param array<string, mixed> $quantities
     * @param string[] $keys the variant keys the product has now
     */
    public function setOnHand(string $productId, array $quantities, array $keys): void
    {
        if (!$this->database->isAvailable() || $productId === '') {
            return;
        }
        $pdo = $this->pdo();
        $now = time();
        $pdo->beginTransaction();
        try {
            $upsert = $pdo->prepare(
                'INSERT INTO shop_stock (product_id, variant_key, on_hand, reserved, updated_at) VALUES (:id, :key, :qty, 0, :now)
                 ON CONFLICT (product_id, variant_key) DO UPDATE SET on_hand = excluded.on_hand, updated_at = excluded.updated_at'
            );
            foreach ($keys as $key) {
                if (!array_key_exists($key, $quantities) || !is_numeric($quantities[$key])) {
                    continue;
                }
                $upsert->execute(['id' => $productId, 'key' => $key, 'qty' => max(0, min(self::MAX, (int)$quantities[$key])), 'now' => $now]);
            }
            $placeholders = $keys === [] ? "''" : implode(',', array_fill(0, count($keys), '?'));
            $prune = $pdo->prepare("DELETE FROM shop_stock WHERE product_id = ? AND reserved = 0 AND variant_key NOT IN ($placeholders)");
            $prune->execute(array_merge([$productId], $keys));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Holds pieces for an order waiting to be paid; false (nothing held) when fewer are free. */
    public function reserve(string $productId, string $variantKey, int $quantity): bool
    {
        if (!$this->database->isAvailable() || $quantity < 1) {
            return false;
        }
        $stmt = $this->pdo()->prepare('UPDATE shop_stock SET reserved = reserved + CAST(:q AS INTEGER), updated_at = :now WHERE product_id = :id AND variant_key = :key AND on_hand - reserved >= CAST(:need AS INTEGER)');
        $stmt->execute(['q' => $quantity, 'need' => $quantity, 'now' => time(), 'id' => $productId, 'key' => $variantKey]);
        return $stmt->rowCount() === 1;
    }

    /** Gives back pieces held for an order that was not paid. */
    public function release(string $productId, string $variantKey, int $quantity): void
    {
        if (!$this->database->isAvailable() || $quantity < 1) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE shop_stock SET reserved = MAX(0, reserved - CAST(:q AS INTEGER)), updated_at = :now WHERE product_id = :id AND variant_key = :key');
        $stmt->execute(['q' => $quantity, 'now' => time(), 'id' => $productId, 'key' => $variantKey]);
    }

    /** A paid order takes what it held: on hand and held both go down. */
    public function commit(string $productId, string $variantKey, int $quantity): void
    {
        if (!$this->database->isAvailable() || $quantity < 1) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE shop_stock SET on_hand = MAX(0, on_hand - CAST(:q AS INTEGER)), reserved = MAX(0, reserved - CAST(:held AS INTEGER)), updated_at = :now WHERE product_id = :id AND variant_key = :key');
        $stmt->execute(['q' => $quantity, 'held' => $quantity, 'now' => time(), 'id' => $productId, 'key' => $variantKey]);
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
