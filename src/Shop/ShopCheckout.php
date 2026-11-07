<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\Shop\Payments\PaymentProvider;

/**
 * The checkout (docs/shop-plan.md, section 3): what a cart costs sent to an address, with a shipping method, a way to pay and a
 * coupon (quote()); placing the order, which prices everything again, holds the stock, counts the coupon's use and writes the
 * order (place()); and every later change of an order's status, with what it does to the stock (changeStatus()).
 *
 * Stock: an order waiting for an online payment holds its pieces (stock_held 1) until it is paid or its time runs out; an
 * order paid offline, or once paid, has taken them (stock_held 2). Cancelling gives back what was held or taken.
 */
final class ShopCheckout
{
    public const HELD = 1;
    public const TAKEN = 2;

    /**
     * @param array<string, PaymentProvider> $providers by id, in the order the checkout offers them
     * @param \Closure(string, string): string $t a string in a language
     */
    public function __construct(
        private ShopPricing $pricing,
        private ShopCoupons $coupons,
        private ShopOrders $orders,
        private ShopStock $stock,
        private ShopSettings $settings,
        private array $providers,
        private \Closure $t,
        private string $defaultLang
    ) {
    }

    public function provider(string $id): ?PaymentProvider
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * What the cart costs as the checkout shows it.
     *
     * @param list<array{product_id: string, variant_key: string, quantity: int}> $cart
     * @param array<string, string> $address at least country and postcode
     * @return array<string, mixed>
     */
    public function quote(array $cart, string $coupon, array $address, string $methodId, string $paymentId, string $lang, string $email = ''): array
    {
        $shop = $this->settings->all();
        $priced = $this->pricing->lines($cart, $lang);
        $zone = ($address['country'] ?? '') !== '' ? ShopShipping::zoneFor($shop['shipping'], (string)$address['country'], (string)($address['postcode'] ?? '')) : null;
        $methods = $zone !== null ? ShopShipping::methods($zone, $priced['items_cents'], $priced['grams'], $lang, $this->defaultLang, fn(string $key): string => ($this->t)($key, $lang)) : [];
        $method = null;
        foreach ($methods as $offered) {
            if ($offered['id'] === $methodId) {
                $method = $offered;
            }
        }
        $method ??= $methods[0] ?? null;
        $payments = [];
        foreach ($this->providers as $id => $provider) {
            $settings = is_array($shop['payments'][$id] ?? null) ? $shop['payments'][$id] : [];
            if ($provider->offered($settings, (string)($zone['id'] ?? ''))) {
                $payments[$id] = ['id' => $id, 'label' => ($this->t)('shop.pay_' . $id, $lang), 'fee' => $provider->fee($settings), 'instructions' => $provider->instructions($settings)];
            }
        }
        $payment = $payments[$paymentId] ?? (reset($payments) ?: null);
        $couponResult = trim($coupon) !== '' ? $this->coupons->apply($coupon, $priced['lines'], $email) : null;
        $totals = $this->pricing->totals($priced['lines'], $couponResult, (int)($method['cents'] ?? 0), (int)($payment['fee'] ?? 0));
        return [
            'priced' => $priced,
            'zone' => $zone,
            'methods' => $methods,
            'method' => $method,
            'payments' => array_values($payments),
            'payment' => $payment,
            'coupon' => $couponResult,
            'totals' => $totals,
            'countries' => ShopShipping::countries($shop['shipping']),
        ];
    }

    /**
     * Places an order from the checkout's form.
     *
     * @param array<string, mixed> $input the form: email, billing[…], ship_elsewhere, shipping[…], method, store, payment, coupon, gift_wrap, gift_note, note, terms
     * @param array{lines: list<array{product_id: string, variant_key: string, quantity: int}>, coupon: string} $cart
     * @param array<string, mixed>|null $customer the signed-in customer
     * @return array{ok: bool, errors: list<string>, order: array<string, mixed>|null, redirect: string, quote: array<string, mixed>|null}
     */
    public function place(array $input, array $cart, ?array $customer, string $lang): array
    {
        $shop = $this->settings->all();
        $errors = [];
        $email = $customer !== null ? (string)$customer['email'] : trim((string)($input['email'] ?? ''));
        if ($customer === null && !$shop['guest_checkout']) {
            $errors[] = 'account_needed';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'email';
        }
        $billing = ShopAddress::clean(is_array($input['billing'] ?? null) ? $input['billing'] : []);
        foreach (ShopAddress::missing($billing, true) as $field) {
            $errors[] = 'billing_' . $field;
        }
        $shipping = !empty($input['ship_elsewhere']) ? ShopAddress::clean(is_array($input['shipping'] ?? null) ? $input['shipping'] : []) : $billing;
        if (!empty($input['ship_elsewhere'])) {
            foreach (ShopAddress::missing($shipping) as $field) {
                $errors[] = 'shipping_' . $field;
            }
        }
        $couponCode = ShopCoupons::normalizeCode($input['coupon'] ?? $cart['coupon']);
        $quote = $this->quote($cart['lines'], $couponCode, $shipping, (string)($input['method'] ?? ''), (string)($input['payment'] ?? ''), $lang, $email);
        $priced = $quote['priced'];
        if ($priced['lines'] === []) {
            $errors[] = 'empty';
        }
        if ($priced['changes'] !== []) {
            $errors[] = 'cart_changed';
        }
        if ($quote['zone'] === null || $quote['method'] === null) {
            $errors[] = 'no_shipping';
        } elseif ((string)($input['method'] ?? '') !== '' && $quote['method']['id'] !== (string)$input['method']) {
            $errors[] = 'method_changed';
        }
        $store = '';
        if (($quote['method']['kind'] ?? '') === 'pickup' && $quote['method']['stores'] !== []) {
            $store = in_array((string)($input['store'] ?? ''), $quote['method']['stores'], true) ? (string)$input['store'] : '';
            if ($store === '') {
                $errors[] = 'store';
            }
        }
        if ($quote['payment'] === null || ($input['payment'] ?? '') !== $quote['payment']['id']) {
            $errors[] = 'payment';
        }
        if ($quote['coupon'] !== null && !$quote['coupon']['ok']) {
            $errors[] = 'coupon_' . $quote['coupon']['error'];
        }
        if ($shop['terms_page'] !== '' && empty($input['terms'])) {
            $errors[] = 'terms';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'order' => null, 'redirect' => '', 'quote' => $quote];
        }

        // The pieces are held first: an order is only written when everything in it is there.
        $held = [];
        foreach ($priced['lines'] as $line) {
            if (!$line['tracked']) {
                continue;
            }
            if (!$this->stock->reserve($line['product_id'], $line['variant_key'], $line['quantity'])) {
                foreach ($held as $back) {
                    $this->stock->release($back['product_id'], $back['variant_key'], $back['quantity']);
                }
                return ['ok' => false, 'errors' => ['sold_out'], 'order' => null, 'redirect' => '', 'quote' => $quote];
            }
            $held[] = $line;
        }
        $couponTaken = '';
        if ($quote['coupon'] !== null && $quote['coupon']['ok']) {
            if (!$this->coupons->take($quote['coupon']['code'])) {
                foreach ($held as $back) {
                    $this->stock->release($back['product_id'], $back['variant_key'], $back['quantity']);
                }
                return ['ok' => false, 'errors' => ['coupon_used_up'], 'order' => null, 'redirect' => '', 'quote' => $quote];
            }
            $couponTaken = $quote['coupon']['code'];
        }

        $provider = $this->providers[$quote['payment']['id']];
        $paymentSettings = is_array($shop['payments'][$provider->id()] ?? null) ? $shop['payments'][$provider->id()] : [];
        $totals = $quote['totals'];
        $lines = [];
        foreach ($priced['lines'] as $i => $line) {
            $lines[] = $line + ['tax_cents' => $totals['line_tax'][$i] ?? 0];
        }
        $giftWrap = !empty($input['gift_wrap']) && array_filter($priced['lines'], static fn(array $l): bool => $l['gift_wrap']) !== [];
        $order = [
            'status' => 'pending_payment',
            'customer_id' => $customer['id'] ?? null,
            'email' => $email,
            'lang' => $lang,
            'items_cents' => $totals['items'],
            'discount_cents' => $totals['discount'],
            'shipping_cents' => $totals['shipping'],
            'fee_cents' => $totals['fee'],
            'tax_cents' => $totals['tax'],
            'total_cents' => $totals['total'],
            'coupon' => $couponTaken,
            'payment_method' => $provider->id(),
            'shipping_method' => $quote['method']['id'],
            'shipping_label' => $quote['method']['label'] . ($store !== '' ? ' · ' . $store : ''),
            'billing' => $billing,
            'shipping' => ($quote['method']['kind'] ?? '') === 'pickup' ? [] : $shipping,
            'gift_wrap' => $giftWrap,
            'gift_note' => mb_substr(trim((string)($input['gift_note'] ?? '')), 0, 500),
            'customer_note' => mb_substr(trim((string)($input['note'] ?? '')), 0, 1000),
            'stock_held' => $held === [] ? 0 : self::HELD,
            'reserve_until' => time() + 60 * (int)$shop['reserve_minutes'],
        ];
        try {
            $created = $this->orders->create($order, $lines, (string)$shop['order_prefix']);
        } catch (\Throwable $e) {
            foreach ($held as $back) {
                $this->stock->release($back['product_id'], $back['variant_key'], $back['quantity']);
            }
            $this->coupons->giveBack($couponTaken);
            throw $e;
        }
        $this->orders->addEvent($created['id'], 'placed', 'Order placed' . ($customer !== null ? ' by the customer\'s account' : ' as a guest') . '.', $email);
        $start = $provider->start($order + $created, $paymentSettings);
        if ($start['status'] !== 'pending_payment') {
            $this->changeStatus($created['id'], $start['status'], 'checkout');
        }
        return ['ok' => true, 'errors' => [], 'order' => $this->orders->get($created['id']), 'redirect' => $start['redirect'], 'quote' => $quote];
    }

    /**
     * Moves an order to another status, as the order's screen (or a payment) asks, with what it does to the stock and the coupon.
     *
     * @return array{ok: bool, message: string, order: array<string, mixed>|null}
     */
    public function changeStatus(int $id, string $to, string $actor, string $note = ''): array
    {
        $order = $this->orders->get($id);
        if ($order === null) {
            return ['ok' => false, 'message' => 'There is no such order.', 'order' => null];
        }
        $from = (string)$order['status'];
        if (!in_array($to, ShopOrders::NEXT[$from] ?? [], true)) {
            return ['ok' => false, 'message' => 'An order that is ' . strtolower(ShopOrders::STATUSES[$from] ?? $from) . ' cannot become ' . strtolower(ShopOrders::STATUSES[$to] ?? $to) . '.', 'order' => $order];
        }
        if (!$this->orders->setStatus($id, $from, $to)) {
            return ['ok' => false, 'message' => 'The order was changed a moment ago; look at it again.', 'order' => $this->orders->get($id)];
        }
        $lines = $this->orders->lines($id);
        $stock = (int)$order['stock_held'];
        if ($to === 'cancelled') {
            foreach ($lines as $line) {
                if (!$line['tracked']) {
                    continue;
                }
                if ($stock === self::HELD) {
                    $this->stock->release($line['product_id'], $line['variant_key'], $line['quantity']);
                } elseif ($stock === self::TAKEN) {
                    $this->stock->restock($line['product_id'], $line['variant_key'], $line['quantity']);
                }
            }
            $this->orders->update($id, ['stock_held' => 0]);
            $this->coupons->giveBack((string)$order['coupon']);
        } elseif ($stock === self::HELD && $to !== 'refunded') {
            foreach ($lines as $line) {
                if ($line['tracked']) {
                    $this->stock->commit($line['product_id'], $line['variant_key'], $line['quantity']);
                }
            }
            $this->orders->update($id, ['stock_held' => self::TAKEN]);
        }
        $this->orders->addEvent($id, 'status', (ShopOrders::STATUSES[$from] ?? $from) . ' → ' . (ShopOrders::STATUSES[$to] ?? $to) . ($note !== '' ? '. ' . $note : '.'), $actor);
        return ['ok' => true, 'message' => 'The order is ' . strtolower(ShopOrders::STATUSES[$to]) . '.', 'order' => $this->orders->get($id)];
    }

    /** Cancels the orders whose time to be paid ran out, giving back their stock. How many. */
    public function expire(): int
    {
        $count = 0;
        foreach ($this->orders->expired(time()) as $order) {
            if ($this->changeStatus((int)$order['id'], 'cancelled', 'system', 'It was not paid in time.')['ok']) {
                $count++;
            }
        }
        return $count;
    }
}
