<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * Orders (tables shop_orders, shop_order_lines, shop_order_events; docs/shop-plan.md, section 3). Each keeps what was sold as it
 * was sold, its addresses, how it is paid and sent, and a timeline of everything that happened to it. Numbers follow each other
 * within a year, after the shop's prefix (AL-2026-00042), so they never meet the numbers of a shop the site came from.
 */
final class ShopOrders
{
    public const STATUSES = [
        'pending_payment' => 'Waiting for payment',
        'on_hold' => 'On hold',
        'paid' => 'Paid',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ];
    /** What an order can become from each status, on the order's screen. */
    public const NEXT = [
        'pending_payment' => ['paid', 'on_hold', 'processing', 'cancelled'],
        'on_hold' => ['paid', 'processing', 'cancelled'],
        'paid' => ['processing', 'shipped', 'completed', 'refunded', 'cancelled'],
        'processing' => ['paid', 'shipped', 'completed', 'cancelled'],
        'shipped' => ['completed', 'refunded'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];
    /** Statuses whose stock is out of the shop: taken, or held. */
    public const HOLDING = ['pending_payment', 'on_hold', 'paid', 'processing', 'shipped', 'completed'];
    public const PER_PAGE = 30;

    public function __construct(private SystemDatabase $database)
    {
    }

    /**
     * Writes a new order and its lines, with its number, in one transaction.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     * @return array{id: int, number: string, view_key: string}
     */
    public function create(array $order, array $lines, string $prefix): array
    {
        $pdo = $this->pdo();
        $now = time();
        $pdo->beginTransaction();
        try {
            $year = date('Y', $now);
            $pdo->prepare("INSERT INTO shop_sequences (name, value) VALUES (:name, 1) ON CONFLICT (name) DO UPDATE SET value = value + 1")->execute(['name' => 'order-' . $year]);
            $seq = $pdo->prepare('SELECT value FROM shop_sequences WHERE name = :name');
            $seq->execute(['name' => 'order-' . $year]);
            $number = ($prefix !== '' ? $prefix . '-' : '') . $year . '-' . str_pad((string)(int)$seq->fetchColumn(), 5, '0', STR_PAD_LEFT);
            $viewKey = bin2hex(random_bytes(16));
            $columns = ['status', 'customer_id', 'email', 'lang', 'currency', 'items_cents', 'discount_cents', 'shipping_cents', 'fee_cents', 'tax_cents', 'total_cents', 'coupon', 'payment_method', 'shipping_method', 'shipping_label', 'billing', 'shipping', 'gift_wrap', 'gift_note', 'customer_note', 'stock_held', 'reserve_until'];
            $values = [];
            foreach ($columns as $column) {
                $value = $order[$column] ?? null;
                $values[$column] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? (int)$value : $value);
            }
            $values['currency'] ??= 'EUR';
            foreach (['billing', 'shipping'] as $json) {
                $values[$json] ??= '{}';
            }
            foreach (['coupon', 'payment_method', 'shipping_method', 'shipping_label', 'gift_note', 'customer_note', 'lang'] as $text) {
                $values[$text] ??= '';
            }
            foreach (['items_cents', 'discount_cents', 'shipping_cents', 'fee_cents', 'tax_cents', 'total_cents', 'gift_wrap', 'stock_held', 'reserve_until'] as $int) {
                $values[$int] = (int)($values[$int] ?? 0);
            }
            $stmt = $pdo->prepare('INSERT INTO shop_orders (number, view_key, ' . implode(', ', $columns) . ', created_at, updated_at) VALUES (:number, :view_key, :' . implode(', :', $columns) . ', :now, :now2)');
            $stmt->execute($values + ['number' => $number, 'view_key' => $viewKey, 'now' => $now, 'now2' => $now]);
            $id = (int)$pdo->lastInsertId();
            $line = $pdo->prepare('INSERT INTO shop_order_lines (order_id, product_id, variant_key, title, variant, sku, quantity, unit_cents, total_cents, tax_rate, tax_cents, tracked) VALUES (:order, :product, :variant_key, :title, :variant, :sku, :quantity, :unit, :total, :rate, :tax, :tracked)');
            foreach ($lines as $row) {
                $line->execute([
                    'order' => $id,
                    'product' => (string)$row['product_id'],
                    'variant_key' => (string)$row['variant_key'],
                    'title' => mb_substr((string)$row['title'], 0, 300),
                    'variant' => mb_substr((string)$row['variant'], 0, 200),
                    'sku' => mb_substr((string)$row['sku'], 0, 80),
                    'quantity' => (int)$row['quantity'],
                    'unit' => (int)$row['unit_cents'],
                    'total' => (int)$row['total_cents'],
                    'rate' => (float)$row['tax_rate'],
                    'tax' => (int)($row['tax_cents'] ?? 0),
                    'tracked' => !empty($row['tracked']) ? 1 : 0,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'number' => $number, 'view_key' => $viewKey];
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function byNumber(string $number): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_orders WHERE number = :number');
        $stmt->execute(['number' => $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $orderId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_order_lines WHERE order_id = :id ORDER BY id');
        $stmt->execute(['id' => $orderId]);
        return array_map(static fn(array $r): array => [
            'product_id' => (string)$r['product_id'],
            'variant_key' => (string)$r['variant_key'],
            'title' => (string)$r['title'],
            'variant' => (string)$r['variant'],
            'sku' => (string)$r['sku'],
            'quantity' => (int)$r['quantity'],
            'unit_cents' => (int)$r['unit_cents'],
            'total_cents' => (int)$r['total_cents'],
            'tax_rate' => (float)$r['tax_rate'],
            'tax_cents' => (int)$r['tax_cents'],
            'tracked' => (bool)$r['tracked'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{at: int, kind: string, message: string, actor: string}> newest first */
    public function events(int $orderId): array
    {
        $stmt = $this->pdo()->prepare('SELECT at, kind, message, actor FROM shop_order_events WHERE order_id = :id ORDER BY id DESC');
        $stmt->execute(['id' => $orderId]);
        return array_map(static fn(array $r): array => ['at' => (int)$r['at'], 'kind' => (string)$r['kind'], 'message' => (string)$r['message'], 'actor' => (string)$r['actor']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addEvent(int $orderId, string $kind, string $message, string $actor = ''): void
    {
        $this->pdo()->prepare('INSERT INTO shop_order_events (order_id, at, kind, message, actor) VALUES (:order, :at, :kind, :message, :actor)')
            ->execute(['order' => $orderId, 'at' => time(), 'kind' => $kind, 'message' => mb_substr($message, 0, 2000), 'actor' => mb_substr($actor, 0, 120)]);
    }

    /**
     * Moves an order to a status, only from the one it was in (so two people pressing at once change it once).
     */
    public function setStatus(int $id, string $from, string $to): bool
    {
        if (!isset(self::STATUSES[$to])) {
            return false;
        }
        $stmt = $this->pdo()->prepare('UPDATE shop_orders SET status = :to, updated_at = :now, paid_at = CASE WHEN CAST(:paid AS INTEGER) = 1 AND paid_at IS NULL THEN :now2 ELSE paid_at END WHERE id = :id AND status = :from');
        $stmt->execute(['to' => $to, 'now' => time(), 'paid' => in_array($to, ['paid', 'completed'], true) ? 1 : 0, 'now2' => time(), 'id' => $id, 'from' => $from]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string, string|int|null> $fields carrier, tracking, stock_held, paid_at */
    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['carrier', 'tracking', 'stock_held', 'paid_at', 'customer_id']));
        if ($allowed === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn(string $k): string => $k . ' = :' . $k, array_keys($allowed)));
        $this->pdo()->prepare('UPDATE shop_orders SET ' . $sets . ', updated_at = :now WHERE id = :id')->execute($allowed + ['now' => time(), 'id' => $id]);
    }

    /**
     * Orders for the admin's list.
     *
     * @param array<string, mixed> $filters status, q, from, to (Y-m-d), customer
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $params] = self::where($filters);
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM shop_orders' . $where);
        $count->execute($params);
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_orders' . $where . ' ORDER BY id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage));
        $stmt->execute($params);
        return ['rows' => array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC)), 'total' => (int)$count->fetchColumn()];
    }

    /** @return array<string, int> how many orders are in each status */
    public function counts(): array
    {
        $counts = array_map(static fn(): int => 0, self::STATUSES);
        foreach ($this->pdo()->query('SELECT status, COUNT(*) AS n FROM shop_orders GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string)$row['status']] = (int)$row['n'];
        }
        return $counts;
    }

    /** @return list<array<string, mixed>> a customer's orders, newest first */
    public function forCustomer(int $customerId, int $limit = 100): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_orders WHERE customer_id = :id ORDER BY id DESC LIMIT ' . max(1, $limit));
        $stmt->execute(['id' => $customerId]);
        return array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Orders placed as a guest with an address become the customer's, once the customer has shown the address is theirs. */
    public function claim(string $email, int $customerId): int
    {
        $stmt = $this->pdo()->prepare('UPDATE shop_orders SET customer_id = :id WHERE customer_id IS NULL AND email = :email');
        $stmt->execute(['id' => $customerId, 'email' => $email]);
        return $stmt->rowCount();
    }

    /** A customer who deletes their account: their orders stay for the accounts, no longer linked to anyone. */
    public function unlink(int $customerId): void
    {
        $this->pdo()->prepare('UPDATE shop_orders SET customer_id = NULL WHERE customer_id = :id')->execute(['id' => $customerId]);
    }

    /** @return list<array<string, mixed>> orders waiting for payment whose hold on the stock has run out */
    public function expired(int $now): array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM shop_orders WHERE status = 'pending_payment' AND reserve_until > 0 AND reserve_until < :now ORDER BY id LIMIT 50");
        $stmt->execute(['now' => $now]);
        return array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function where(array $filters): array
    {
        $clauses = [];
        $params = [];
        $status = (string)($filters['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $clauses[] = 'status = :status';
            $params['status'] = $status;
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = '(number LIKE :q OR email LIKE :q2 OR billing LIKE :q3)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $params += ['q' => $like, 'q2' => $like, 'q3' => $like];
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $date = (string)($filters[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $time = strtotime($date . ' 00:00:00');
                $clauses[] = 'created_at ' . $op . ' :' . $key;
                $params[$key] = $key === 'to' ? $time + 86400 : $time;
            }
        }
        if (isset($filters['customer']) && (int)$filters['customer'] > 0) {
            $clauses[] = 'customer_id = :customer';
            $params['customer'] = (int)$filters['customer'];
        }
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $order = $row;
        foreach (['id', 'items_cents', 'discount_cents', 'shipping_cents', 'fee_cents', 'tax_cents', 'total_cents', 'gift_wrap', 'stock_held', 'reserve_until', 'created_at', 'updated_at'] as $int) {
            $order[$int] = (int)$row[$int];
        }
        $order['customer_id'] = $row['customer_id'] === null ? null : (int)$row['customer_id'];
        $order['paid_at'] = $row['paid_at'] === null ? null : (int)$row['paid_at'];
        foreach (['billing', 'shipping'] as $json) {
            $decoded = json_decode((string)$row[$json], true);
            $order[$json] = is_array($decoded) ? $decoded : [];
        }
        $order['status_label'] = self::STATUSES[(string)$row['status']] ?? (string)$row['status'];
        return $order;
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
