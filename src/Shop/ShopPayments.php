<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * Payments through a provider (tables shop_payments and shop_gateway_events, docs/shop-plan.md, section 5): each attempt to pay an
 * order with its reference at the provider, the transaction that paid it and what was refunded; and the calls providers made, each
 * written once by its key, so the same notification twice is noticed and changes nothing.
 */
final class ShopPayments
{
    public const STARTED = 'started';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    public function __construct(private SystemDatabase $database)
    {
    }

    public function start(int $orderId, string $provider, string $reference, int $amount): int
    {
        $now = time();
        $this->pdo()->prepare('INSERT INTO shop_payments (order_id, provider, reference, amount_cents, status, created_at, updated_at) VALUES (:order, :provider, :reference, :amount, :status, :now, :now2)')
            ->execute(['order' => $orderId, 'provider' => $provider, 'reference' => $reference, 'amount' => $amount, 'status' => self::STARTED, 'now' => $now, 'now2' => $now]);
        return (int)$this->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function byReference(string $provider, string $reference): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_payments WHERE provider = :provider AND reference = :reference');
        $stmt->execute(['provider' => $provider, 'reference' => $reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @return list<array<string, mixed>> an order's payments, newest first */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_payments WHERE order_id = :order ORDER BY id DESC');
        $stmt->execute(['order' => $orderId]);
        return array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The payment of an order that went through, if any. @return array<string, mixed>|null */
    public function paidFor(int $orderId): ?array
    {
        foreach ($this->forOrder($orderId) as $payment) {
            if ($payment['status'] === self::PAID) {
                return $payment;
            }
        }
        return null;
    }

    /** Marks a payment paid by a transaction, once: false when it was already. */
    public function markPaid(int $id, string $transactionId, string $detail = ''): bool
    {
        $stmt = $this->pdo()->prepare("UPDATE shop_payments SET status = 'paid', transaction_id = :tx, detail = :detail, updated_at = :now WHERE id = :id AND status <> 'paid'");
        $stmt->execute(['tx' => $transactionId, 'detail' => mb_substr($detail, 0, 500), 'now' => time(), 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public function markFailed(int $id, string $detail = ''): void
    {
        $this->pdo()->prepare("UPDATE shop_payments SET status = 'failed', detail = :detail, updated_at = :now WHERE id = :id AND status = 'started'")
            ->execute(['detail' => mb_substr($detail, 0, 500), 'now' => time(), 'id' => $id]);
    }

    public function addRefund(int $id, int $cents): void
    {
        $this->pdo()->prepare('UPDATE shop_payments SET refunded_cents = refunded_cents + CAST(:c AS INTEGER), updated_at = :now WHERE id = :id')
            ->execute(['c' => $cents, 'now' => time(), 'id' => $id]);
    }

    /**
     * Writes a provider's call once by its key; false when the same key came before (nothing more is done for it).
     */
    public function once(string $provider, string $key, string $kind, ?int $orderId, string $outcome = ''): bool
    {
        $stmt = $this->pdo()->prepare('INSERT OR IGNORE INTO shop_gateway_events (provider, event_key, kind, order_id, outcome, at) VALUES (:provider, :key, :kind, :order, :outcome, :at)');
        $stmt->execute(['provider' => $provider, 'key' => mb_substr($key, 0, 200), 'kind' => $kind, 'order' => $orderId, 'outcome' => mb_substr($outcome, 0, 300), 'at' => time()]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function row(array $row): array
    {
        foreach (['id', 'order_id', 'amount_cents', 'refunded_cents', 'created_at', 'updated_at'] as $int) {
            $row[$int] = (int)$row[$int];
        }
        return $row;
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
