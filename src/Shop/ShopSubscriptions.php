<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\Shop\Payments\PaymentProvider;
use GnozioCMS\Shop\Payments\RecurringPayments;
use GnozioCMS\SystemDatabase;
use PDO;

/**
 * Subscriptions (docs/shop-plan.md, section 7). Buying a subscription product (signed in, paying with a provider that can charge
 * again: Viva Wallet) makes a subscription that waits for its first order to be paid; then it is active, with the payment's
 * transaction as the token it is charged again with, every period. A renewal is an order of its own, charged without the
 * customer; when the charge fails the subscription is past due and is tried again after 1, 3 and 7 days, the customer told each
 * time, then cancelled. The customer can pay a past-due renewal themselves (a new card, a new token) or cancel; the shop can
 * pause, resume, skip a period or cancel.
 *
 * Statuses: pending (its first order not paid yet), active, past_due, paused, cancelled, ended (all its renewals made).
 */
final class ShopSubscriptions
{
    public const STATUSES = ['pending' => 'Waiting for payment', 'active' => 'Active', 'past_due' => 'Past due', 'paused' => 'Paused', 'cancelled' => 'Cancelled', 'ended' => 'Ended'];
    public const INTERVALS = ['week', 'month', 'year'];
    /** Days to wait before the next try after the 1st, 2nd and 3rd failed charge; after the 4th it is cancelled. */
    public const RETRY_DAYS = [1, 3, 7];

    /**
     * @param \Closure(): ShopCheckout $checkout
     * @param \Closure(): array<string, PaymentProvider> $providers
     * @param \Closure(string, array<string, mixed>, ?array<string, mixed>): void $tell an email to the customer: failed, cancelled
     */
    public function __construct(
        private SystemDatabase $database,
        private ShopOrders $orders,
        private ShopPayments $payments,
        private ShopSettings $settings,
        private \Closure $checkout,
        private \Closure $providers,
        private ?\Closure $tell = null
    ) {
    }

    /**
     * What a subscription product's fields say: every how many weeks, months or years, how many renewals (0: until cancelled),
     * the sign-up fee.
     *
     * @param array<string, mixed> $custom
     * @return array{interval: string, count: int, renewals: int, signup_cents: int}
     */
    public static function plan(array $custom): array
    {
        $interval = in_array((string)($custom['interval'] ?? ''), self::INTERVALS, true) ? (string)$custom['interval'] : 'month';
        return [
            'interval' => $interval,
            'count' => max(1, min(12, (int)($custom['interval_count'] ?? 1))),
            'renewals' => max(0, (int)($custom['renewals'] ?? 0)),
            'signup_cents' => max(0, ShopMoney::cents($custom['signup_fee'] ?? null) ?? 0),
        ];
    }

    /** The moment a period after `$from` ends: the same day of the next week, month or year (the month's last day when shorter). */
    public static function nextAt(int $from, string $interval, int $count): int
    {
        $date = (new \DateTimeImmutable('@' . $from))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        if ($interval === 'week') {
            return $date->modify('+' . (7 * $count) . ' days')->getTimestamp();
        }
        $months = $interval === 'year' ? 12 * $count : $count;
        $day = (int)$date->format('j');
        $first = $date->setDate((int)$date->format('Y'), (int)$date->format('n'), 1)->modify('+' . $months . ' months');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, (int)$first->format('t')))->getTimestamp();
    }

    // ---- made with an order

    /**
     * A subscription for a line of an order just placed, waiting for that order to be paid.
     *
     * @param array<string, mixed> $line a priced line with its `subscription`
     */
    public function create(int $customerId, int $orderId, array $line, string $lang, string $provider): int
    {
        $plan = $line['subscription'];
        $now = time();
        $this->pdo()->prepare('INSERT INTO shop_subscriptions (customer_id, product_id, variant_key, title, variant, sku, quantity, price_cents, tax_rate, interval, interval_count, renewals, lang, provider, status, created_at, updated_at) VALUES (:customer, :product, :variant_key, :title, :variant, :sku, :quantity, :price, :rate, :interval, :count, :renewals, :lang, :provider, :status, :now, :now2)')
            ->execute([
                'customer' => $customerId,
                'product' => (string)$line['product_id'],
                'variant_key' => (string)$line['variant_key'],
                'title' => mb_substr((string)$line['title'], 0, 300),
                'variant' => mb_substr((string)$line['variant'], 0, 200),
                'sku' => mb_substr((string)$line['sku'], 0, 80),
                'quantity' => (int)$line['quantity'],
                'price' => (int)$plan['price_cents'],
                'rate' => (float)$line['tax_rate'],
                'interval' => $plan['interval'],
                'count' => $plan['count'],
                'renewals' => $plan['renewals'],
                'lang' => $lang,
                'provider' => $provider,
                'status' => 'pending',
                'now' => $now,
                'now2' => $now,
            ]);
        $id = (int)$this->pdo()->lastInsertId();
        $this->link($id, $orderId, 'first');
        return $id;
    }

    /** @return list<array<string, mixed>> the subscriptions an order is for, each with how the order came (first, renewal, payment) */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo()->prepare('SELECT s.*, l.kind AS link_kind FROM shop_subscriptions s JOIN shop_subscription_orders l ON l.subscription_id = s.id WHERE l.order_id = :order ORDER BY s.id');
        $stmt->execute(['order' => $orderId]);
        return array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * An order of subscriptions is paid. The first one starts them; a renewal moves them a period on; one the customer paid
     * themselves (a past-due renewal) also gives them a new token, the card they just used.
     */
    public function orderPaid(int $orderId): void
    {
        $payment = $this->payments->paidFor($orderId);
        foreach ($this->forOrder($orderId) as $sub) {
            $kind = (string)$sub['link_kind'];
            $fields = ['failures' => 0];
            if ($kind !== 'renewal' && $payment !== null && (string)$payment['transaction_id'] !== '') {
                $fields['token'] = (string)$payment['transaction_id'];
                $fields['provider'] = (string)$payment['provider'];
            }
            $renewed = $sub['renewed'] + ($kind === 'first' ? 0 : 1);
            $fields['renewed'] = $renewed;
            $from = $kind === 'renewal' && $sub['next_at'] > 0 ? $sub['next_at'] : time();
            if ($sub['renewals'] > 0 && $renewed >= $sub['renewals']) {
                $fields += ['status' => 'ended', 'next_at' => 0, 'ended_at' => time()];
            } elseif (in_array($sub['status'], ['pending', 'active', 'past_due'], true)) {
                $fields += ['status' => 'active', 'next_at' => self::nextAt($from, $sub['interval'], $sub['interval_count'])];
            }
            $this->update($sub['id'], $fields);
        }
    }

    /** An order of subscriptions is cancelled: those it was the first order of never start. */
    public function orderCancelled(int $orderId): void
    {
        foreach ($this->forOrder($orderId) as $sub) {
            if ($sub['link_kind'] === 'first' && $sub['status'] === 'pending') {
                $this->update($sub['id'], ['status' => 'cancelled', 'ended_at' => time()]);
            }
        }
    }

    // ---- renewals

    /**
     * Charges the subscriptions whose period has come (cron, and the shop's admin screens). How many were charged.
     */
    public function renewDue(int $limit = 20): int
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM shop_subscriptions WHERE status IN ('active', 'past_due') AND next_at > 0 AND next_at <= :now ORDER BY next_at LIMIT " . max(1, $limit));
        $stmt->execute(['now' => time()]);
        $count = 0;
        foreach (array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC)) as $sub) {
            // Claimed first, so two runs at once never charge the same period twice.
            $claim = $this->pdo()->prepare('UPDATE shop_subscriptions SET next_at = -next_at WHERE id = :id AND next_at = :at');
            $claim->execute(['id' => $sub['id'], 'at' => $sub['next_at']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $this->renew($sub);
            $count++;
        }
        return $count;
    }

    /**
     * One renewal: an order of its own, charged on the subscription's token. Paid, it moves the subscription a period on; refused,
     * the subscription is past due and tried again later, or cancelled after the last try.
     *
     * @param array<string, mixed> $sub
     * @return array{ok: bool, order: array<string, mixed>|null, message: string}
     */
    public function renew(array $sub): array
    {
        $provider = ($this->providers)()[$sub['provider']] ?? null;
        $settings = $this->settings->all()['payments'][$sub['provider']] ?? [];
        $order = $this->renewalOrder($sub, 'renewal', 0);
        if ($order === null) {
            $this->update($sub['id'], ['next_at' => abs($sub['next_at'])]);
            return ['ok' => false, 'order' => null, 'message' => 'There is no earlier order to take the address from.'];
        }
        $id = (int)$order['id'];
        $result = $provider instanceof RecurringPayments && $sub['token'] !== ''
            ? $provider->charge(is_array($settings) ? $settings : [], (string)$sub['token'], (int)$order['total_cents'], $order)
            : ['ok' => false, 'error' => 'Its way to pay cannot charge again.', 'transaction_id' => ''];
        // The period it was due for: a renewal moves on from there, not from today.
        $this->update($sub['id'], ['next_at' => abs($sub['next_at'])]);
        if ($result['ok']) {
            $payment = $this->payments->start($id, (string)$sub['provider'], 'renewal-' . $order['number'], (int)$order['total_cents']);
            $this->payments->markPaid($payment, $result['transaction_id'], 'Recurring charge');
            $this->orders->addEvent($id, 'payment', 'Charged again for subscription #' . $sub['id'] . ': ' . ShopMoney::plain((int)$order['total_cents']) . ' EUR, transaction ' . $result['transaction_id'] . '.', 'system');
            ($this->checkout)()->changeStatus($id, 'paid', 'system');
            return ['ok' => true, 'order' => $this->orders->get($id), 'message' => 'Renewed.'];
        }
        $this->orders->addEvent($id, 'payment', 'The charge was refused: ' . $result['error'], 'system');
        ($this->checkout)()->changeStatus($id, 'cancelled', 'system', 'The renewal could not be charged.');
        $failures = $sub['failures'] + 1;
        if ($failures > count(self::RETRY_DAYS)) {
            $this->update($sub['id'], ['status' => 'cancelled', 'failures' => $failures, 'next_at' => 0, 'ended_at' => time()]);
            $this->notify('cancelled', $sub['id'], $order);
            return ['ok' => false, 'order' => $order, 'message' => 'Charged in vain ' . $failures . ' times: cancelled.'];
        }
        $this->update($sub['id'], ['status' => 'past_due', 'failures' => $failures, 'next_at' => time() + 86400 * self::RETRY_DAYS[$failures - 1]]);
        $this->notify('failed', $sub['id'], $order);
        return ['ok' => false, 'order' => $order, 'message' => 'The charge was refused: ' . $result['error']];
    }

    /**
     * The customer pays a past-due renewal themselves: an order waiting for its payment, sent to the provider allowing it to
     * charge again (so the card used becomes the subscription's).
     *
     * @return array{ok: bool, redirect: string, order: array<string, mixed>|null}
     */
    public function payNow(int $id, int $customerId): array
    {
        $sub = $this->get($id);
        if ($sub === null || $sub['customer_id'] !== $customerId || $sub['status'] !== 'past_due') {
            return ['ok' => false, 'redirect' => '', 'order' => null];
        }
        $order = $this->renewalOrder($sub, 'payment', time() + 60 * (int)$this->settings->all()['reserve_minutes']);
        $provider = ($this->providers)()[$sub['provider']] ?? null;
        if ($order === null || $provider === null) {
            return ['ok' => false, 'redirect' => '', 'order' => null];
        }
        $start = $provider->start($order + ['recurring' => true], is_array($this->settings->all()['payments'][$sub['provider']] ?? null) ? $this->settings->all()['payments'][$sub['provider']] : []);
        if (($start['error'] ?? '') !== '' || $start['redirect'] === '') {
            ($this->checkout)()->changeStatus((int)$order['id'], 'cancelled', 'system', 'The payment could not start.');
            return ['ok' => false, 'redirect' => '', 'order' => null];
        }
        $this->orders->addEvent((int)$order['id'], 'payment', 'The customer pays the subscription\'s renewal themselves.', 'customer');
        return ['ok' => true, 'redirect' => $start['redirect'], 'order' => $order];
    }

    /**
     * What the customer or the shop asks: cancel (the customer or the shop), pause, resume, skip a period (the shop).
     *
     * @return array{ok: bool, message: string}
     */
    public function act(int $id, string $action, ?int $customerId = null): array
    {
        $sub = $this->get($id);
        if ($sub === null || ($customerId !== null && $sub['customer_id'] !== $customerId)) {
            return ['ok' => false, 'message' => 'There is no such subscription.'];
        }
        $live = in_array($sub['status'], ['active', 'past_due', 'paused'], true);
        return match (true) {
            $action === 'cancel' && ($live || $sub['status'] === 'pending') => $this->done($this->update($id, ['status' => 'cancelled', 'next_at' => 0, 'ended_at' => time()]), 'The subscription is cancelled: nothing more is charged.'),
            $customerId !== null => ['ok' => false, 'message' => 'Nothing was done.'],
            $action === 'pause' && in_array($sub['status'], ['active', 'past_due'], true) => $this->done($this->update($id, ['status' => 'paused']), 'Paused: nothing is charged until it is resumed.'),
            $action === 'resume' && $sub['status'] === 'paused' => $this->done($this->update($id, ['status' => 'active', 'failures' => 0, 'next_at' => max($sub['next_at'], time())]), 'Resumed.'),
            $action === 'skip' && in_array($sub['status'], ['active', 'paused'], true) && $sub['next_at'] > 0 => $this->done($this->update($id, ['next_at' => self::nextAt($sub['next_at'], $sub['interval'], $sub['interval_count'])]), 'The next period is skipped: it is charged a period later.'),
            default => ['ok' => false, 'message' => 'That cannot be done to a subscription that is ' . strtolower(self::STATUSES[$sub['status']] ?? $sub['status']) . '.'],
        };
    }

    // ---- reading

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function forCustomer(int $customerId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_subscriptions WHERE customer_id = :customer ORDER BY id DESC');
        $stmt->execute(['customer' => $customerId]);
        return array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> the list on Shop > Subscriptions, newest first, by status */
    public function search(string $status = ''): array
    {
        $sql = 'SELECT * FROM shop_subscriptions' . (isset(self::STATUSES[$status]) ? ' WHERE status = :status' : '') . ' ORDER BY id DESC LIMIT 500';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(isset(self::STATUSES[$status]) ? ['status' => $status] : []);
        return array_map(self::row(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, int> how many of each status */
    public function counts(): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($this->pdo()->query('SELECT status, COUNT(*) FROM shop_subscriptions GROUP BY status')->fetchAll(PDO::FETCH_NUM) as [$status, $n]) {
            $counts[(string)$status] = (int)$n;
        }
        return $counts;
    }

    /** @return list<array<string, mixed>> a subscription's orders, newest first */
    public function orders(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT order_id, kind FROM shop_subscription_orders WHERE subscription_id = :id ORDER BY order_id DESC');
        $stmt->execute(['id' => $id]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $order = $this->orders->get((int)$link['order_id']);
            if ($order !== null) {
                $out[] = $order + ['link_kind' => (string)$link['kind']];
            }
        }
        return $out;
    }

    // ---- inside

    /**
     * An order for one period of a subscription, with the address of its latest order. `$reserve` 0: it is charged at once
     * (a renewal); otherwise the customer pays it, and it waits that long.
     *
     * @param array<string, mixed> $sub
     * @return array<string, mixed>|null
     */
    private function renewalOrder(array $sub, string $kind, int $reserve): ?array
    {
        $latest = $this->orders($sub['id'])[0] ?? null;
        if ($latest === null) {
            return null;
        }
        $total = $sub['price_cents'] * $sub['quantity'];
        $tax = ShopMoney::vatIn($total, $sub['tax_rate']);
        $created = $this->orders->create([
            'status' => 'pending_payment',
            'customer_id' => $sub['customer_id'],
            'email' => (string)$latest['email'],
            'lang' => $sub['lang'] !== '' ? $sub['lang'] : (string)$latest['lang'],
            'items_cents' => $total,
            'tax_cents' => $tax,
            'total_cents' => $total,
            'payment_method' => $sub['provider'],
            'shipping_method' => 'download',
            'shipping_label' => 'Subscription',
            'billing' => $latest['billing'],
            'reserve_until' => $reserve,
        ], [[
            'product_id' => $sub['product_id'],
            'variant_key' => $sub['variant_key'],
            'title' => $sub['title'],
            'variant' => $sub['variant'],
            'sku' => $sub['sku'],
            'quantity' => $sub['quantity'],
            'unit_cents' => $sub['price_cents'],
            'total_cents' => $total,
            'tax_rate' => $sub['tax_rate'],
            'tax_cents' => $tax,
            'tracked' => false,
        ]], (string)$this->settings->all()['order_prefix']);
        $this->link($sub['id'], (int)$created['id'], $kind);
        $this->orders->addEvent((int)$created['id'], 'placed', 'Renewal of subscription #' . $sub['id'] . '.', $kind === 'renewal' ? 'system' : 'customer');
        return $this->orders->get((int)$created['id']);
    }

    private function notify(string $kind, int $id, ?array $order): void
    {
        $sub = $this->get($id);
        if ($this->tell !== null && $sub !== null) {
            ($this->tell)($kind, $sub, $order);
        }
    }

    private function link(int $subscriptionId, int $orderId, string $kind): void
    {
        $this->pdo()->prepare('INSERT OR IGNORE INTO shop_subscription_orders (subscription_id, order_id, kind) VALUES (:sub, :order, :kind)')
            ->execute(['sub' => $subscriptionId, 'order' => $orderId, 'kind' => $kind]);
    }

    /** @param array<string, mixed> $fields */
    private function update(int $id, array $fields): bool
    {
        $allowed = ['status', 'token', 'provider', 'next_at', 'failures', 'renewed', 'ended_at'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        $sets = [];
        $values = ['id' => $id, 'now' => time()];
        foreach ($fields as $name => $value) {
            $sets[] = $name . ' = :' . $name;
            $values[$name] = $value;
        }
        $this->pdo()->prepare('UPDATE shop_subscriptions SET ' . implode(', ', [...$sets, 'updated_at = :now']) . ' WHERE id = :id')->execute($values);
        return true;
    }

    /** @return array{ok: bool, message: string} */
    private function done(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $message];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function row(array $row): array
    {
        foreach (['id', 'customer_id', 'quantity', 'price_cents', 'interval_count', 'renewals', 'renewed', 'next_at', 'failures', 'created_at', 'updated_at'] as $int) {
            $row[$int] = (int)$row[$int];
        }
        $row['tax_rate'] = (float)$row['tax_rate'];
        $row['ended_at'] = $row['ended_at'] === null ? null : (int)$row['ended_at'];
        $row['status_label'] = self::STATUSES[(string)$row['status']] ?? (string)$row['status'];
        return $row;
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
