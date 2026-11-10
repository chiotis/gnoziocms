<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * Coupons (table shop_coupons, docs/shop-plan.md, section 3): a percentage or an amount off, or free shipping; with a minimum,
 * dates, a limit of uses in all and per customer, and optionally only for some collections or products. A use is counted when an order is
 * placed, by one UPDATE that checks the limit, so the last use cannot be taken twice.
 */
final class ShopCoupons
{
    public const KINDS = ['percent' => 'Percentage off', 'fixed' => 'Amount off', 'free_shipping' => 'Free shipping'];

    public function __construct(private SystemDatabase $database)
    {
    }

    public static function normalizeCode(mixed $code): string
    {
        return strtoupper(substr((string)preg_replace('/[^A-Za-z0-9_-]/', '', is_scalar($code) ? (string)$code : ''), 0, 40));
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $code = self::normalizeCode($code);
        if ($code === '' || !$this->database->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_coupons WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (!$this->database->isAvailable()) {
            return [];
        }
        return array_map(self::row(...), $this->pdo()->query('SELECT * FROM shop_coupons ORDER BY active DESC, created_at DESC')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Writes a coupon from the Coupons form. Returns an error, or '' when it was saved.
     *
     * @param array<string, mixed> $post
     */
    public function save(array $post, string $original = ''): string
    {
        $code = self::normalizeCode($post['code'] ?? '');
        if ($code === '') {
            return 'A coupon needs a code: letters, digits, dashes.';
        }
        $kind = isset(self::KINDS[(string)($post['kind'] ?? '')]) ? (string)$post['kind'] : 'percent';
        $amount = is_numeric(str_replace(',', '.', (string)($post['amount'] ?? ''))) ? (float)str_replace(',', '.', (string)$post['amount']) : 0.0;
        if ($kind === 'percent' && ($amount <= 0 || $amount > 100)) {
            return 'A percentage is between 1 and 100.';
        }
        if ($kind === 'fixed' && $amount <= 0) {
            return 'An amount off must be more than zero.';
        }
        $date = static fn(mixed $value): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value) === 1 ? (string)$value : '';
        $original = self::normalizeCode($original);
        if ($original !== $code && $this->find($code) !== null) {
            return 'There is already a coupon with this code.';
        }
        $values = [
            'code' => $code,
            'kind' => $kind,
            'amount' => $kind === 'free_shipping' ? 0 : round($amount, 2),
            'min_cents' => ShopMoney::cents($post['min'] ?? '') ?? 0,
            'starts_at' => $date($post['starts_at'] ?? ''),
            'ends_at' => $date($post['ends_at'] ?? ''),
            'usage_limit' => max(0, (int)($post['usage_limit'] ?? 0)),
            'per_customer' => max(0, (int)($post['per_customer'] ?? 0)),
            'collections' => implode(',', array_slice(array_values(array_filter(array_map(static fn(mixed $id): string => (string)preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$id)), is_array($post['collections'] ?? null) ? $post['collections'] : []))), 0, 50)),
            // Products by their id, as the product picker sends them.
            'products' => implode(',', array_slice(array_values(array_unique(array_filter(array_map(static fn(mixed $id): string => (string)preg_replace('/[^A-Za-z0-9_-]/', '', (string)$id), is_array($post['products'] ?? null) ? $post['products'] : [])))), 0, 200)),
            'active' => !empty($post['active']) ? 1 : 0,
            'note' => mb_substr(trim((string)($post['note'] ?? '')), 0, 200),
        ];
        $pdo = $this->pdo();
        if ($original !== '' && $this->find($original) !== null) {
            $stmt = $pdo->prepare('UPDATE shop_coupons SET code = :code, kind = :kind, amount = :amount, min_cents = :min_cents, starts_at = :starts_at, ends_at = :ends_at, usage_limit = :usage_limit, per_customer = :per_customer, collections = :collections, products = :products, active = :active, note = :note WHERE code = :original');
            $stmt->execute($values + ['original' => $original]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO shop_coupons (code, kind, amount, min_cents, starts_at, ends_at, usage_limit, per_customer, collections, products, active, note, used, created_at) VALUES (:code, :kind, :amount, :min_cents, :starts_at, :ends_at, :usage_limit, :per_customer, :collections, :products, :active, :note, 0, :now)');
            $stmt->execute($values + ['now' => time()]);
        }
        return '';
    }

    public function delete(string $code): void
    {
        if ($this->database->isAvailable()) {
            $this->pdo()->prepare('DELETE FROM shop_coupons WHERE code = :code')->execute(['code' => self::normalizeCode($code)]);
        }
    }

    /**
     * Whether a coupon can be used on these lines, and what it takes off them.
     *
     * @param list<array<string, mixed>> $lines priced lines (ShopPricing), with total_cents and collections
     * @return array{ok: bool, error: string, code: string, discount: int, free_shipping: bool, per_line: array<int, int>}
     */
    public function apply(string $code, array $lines, string $email = '', ?string $today = null): array
    {
        $none = ['ok' => false, 'error' => 'unknown', 'code' => self::normalizeCode($code), 'discount' => 0, 'free_shipping' => false, 'per_line' => []];
        $coupon = $this->find($code);
        if ($coupon === null || !$coupon['active']) {
            return $none;
        }
        $today ??= date('Y-m-d');
        if (($coupon['starts_at'] !== '' && $today < $coupon['starts_at']) || ($coupon['ends_at'] !== '' && $today > $coupon['ends_at'])) {
            return ['error' => 'expired'] + $none;
        }
        if ($coupon['usage_limit'] > 0 && $coupon['used'] >= $coupon['usage_limit']) {
            return ['error' => 'used_up'] + $none;
        }
        if ($coupon['per_customer'] > 0 && $email !== '' && $this->usesBy($coupon['code'], $email) >= $coupon['per_customer']) {
            return ['error' => 'used_up'] + $none;
        }
        $weights = [];
        foreach ($lines as $i => $line) {
            // Limited to collections, products or both: a line is in when it is in one of the collections or is one of the products.
            $eligible = ($coupon['collections'] === [] && $coupon['products'] === [])
                || array_intersect($coupon['collections'], (array)($line['collections'] ?? [])) !== []
                || in_array((string)($line['product_id'] ?? ''), $coupon['products'], true);
            $weights[$i] = $eligible ? (int)$line['total_cents'] : 0;
        }
        $base = array_sum($weights);
        if ($base <= 0) {
            return ['error' => 'not_for_these'] + $none;
        }
        if ($coupon['min_cents'] > 0 && array_sum(array_map(static fn(array $l): int => (int)$l['total_cents'], $lines)) < $coupon['min_cents']) {
            return ['error' => 'minimum'] + $none;
        }
        $discount = match ($coupon['kind']) {
            'percent' => (int)round($base * $coupon['amount'] / 100),
            'fixed' => min($base, (int)round($coupon['amount'] * 100)),
            default => 0,
        };
        return ['ok' => true, 'error' => '', 'code' => $coupon['code'], 'discount' => $discount, 'free_shipping' => $coupon['kind'] === 'free_shipping', 'per_line' => ShopMoney::spread($discount, $weights)];
    }

    /** Counts a use when an order is placed; false when the last use was taken a moment ago. */
    public function take(string $code): bool
    {
        if (!$this->database->isAvailable()) {
            return false;
        }
        $stmt = $this->pdo()->prepare('UPDATE shop_coupons SET used = used + 1 WHERE code = :code AND active = 1 AND (usage_limit = 0 OR used < usage_limit)');
        $stmt->execute(['code' => self::normalizeCode($code)]);
        return $stmt->rowCount() === 1;
    }

    /** Gives a use back, when its order is cancelled. */
    public function giveBack(string $code): void
    {
        if ($code !== '' && $this->database->isAvailable()) {
            $this->pdo()->prepare('UPDATE shop_coupons SET used = MAX(0, used - 1) WHERE code = :code')->execute(['code' => self::normalizeCode($code)]);
        }
    }

    private function usesBy(string $code, string $email): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM shop_orders WHERE coupon = :code AND email = :email AND status <> 'cancelled'");
        $stmt->execute(['code' => $code, 'email' => $email]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return [
            'code' => (string)$row['code'],
            'kind' => (string)$row['kind'],
            'amount' => (float)$row['amount'],
            'min_cents' => (int)$row['min_cents'],
            'starts_at' => (string)$row['starts_at'],
            'ends_at' => (string)$row['ends_at'],
            'usage_limit' => (int)$row['usage_limit'],
            'per_customer' => (int)$row['per_customer'],
            'collections' => array_values(array_filter(explode(',', (string)$row['collections']))),
            'products' => array_values(array_filter(explode(',', (string)($row['products'] ?? '')))),
            'active' => (bool)$row['active'],
            'used' => (int)$row['used'],
            'note' => (string)$row['note'],
        ];
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
