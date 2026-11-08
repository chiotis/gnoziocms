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
        private string $defaultLang,
        private ?ShopPayments $payments = null,
        private ?ShopDownloads $downloads = null,
        private ?ShopSubscriptions $subscriptions = null
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
        if ($priced['digital_only']) {
            return $this->digitalQuote($priced, $coupon, $paymentId, $lang, $email);
        }
        $zone = ($address['country'] ?? '') !== '' ? ShopShipping::zoneFor($shop['shipping'], (string)$address['country'], (string)($address['postcode'] ?? '')) : null;
        $methods = $zone !== null ? ShopShipping::methods($zone, $priced['items_cents'], $priced['grams'], $lang, $this->defaultLang, fn(string $key): string => ($this->t)($key, $lang)) : [];
        $method = null;
        foreach ($methods as $offered) {
            if ($offered['id'] === $methodId) {
                $method = $offered;
            }
        }
        $method ??= $methods[0] ?? null;
        $payments = $this->ways($shop, (string)($zone['id'] ?? ''), $lang, false, $priced['subscriptions']);
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
            'digital_only' => false,
        ];
    }

    /**
     * What a cart of downloads only costs: no shipping, one method that says so, every way to pay but cash on delivery.
     *
     * @param array<string, mixed> $priced
     * @return array<string, mixed>
     */
    private function digitalQuote(array $priced, string $coupon, string $paymentId, string $lang, string $email): array
    {
        $shop = $this->settings->all();
        $method = ['id' => 'download', 'kind' => 'download', 'label' => ($this->t)('shop.ship_download', $lang), 'cents' => 0, 'stores' => []];
        $payments = $this->ways($shop, '', $lang, true, $priced['subscriptions']);
        $payment = $payments[$paymentId] ?? (reset($payments) ?: null);
        $couponResult = trim($coupon) !== '' ? $this->coupons->apply($coupon, $priced['lines'], $email) : null;
        return [
            'priced' => $priced,
            'zone' => ['id' => 'download', 'name' => ''],
            'methods' => [$method],
            'method' => $method,
            'payments' => array_values($payments),
            'payment' => $payment,
            'coupon' => $couponResult,
            'totals' => $this->pricing->totals($priced['lines'], $couponResult, 0, (int)($payment['fee'] ?? 0)),
            // The billing address is still asked (an invoice needs it), in any country the shop knows.
            'countries' => array_keys(ShopCountries::NAMES),
            'digital_only' => true,
        ];
    }

    /**
     * The ways to pay the checkout offers: those switched on for the zone; not cash on delivery when nothing is sent; only those
     * that can charge again when the cart has a subscription.
     *
     * @param array<string, mixed> $shop
     * @return array<string, array<string, mixed>>
     */
    private function ways(array $shop, string $zoneId, string $lang, bool $nothingSent, bool $subscriptions): array
    {
        $payments = [];
        foreach ($this->providers as $id => $provider) {
            $settings = is_array($shop['payments'][$id] ?? null) ? $shop['payments'][$id] : [];
            if (($nothingSent && $id === 'cod') || ($subscriptions && !$provider instanceof Payments\RecurringPayments) || !$provider->offered($settings, $zoneId)) {
                continue;
            }
            $payments[$id] = ['id' => $id, 'label' => ($this->t)('shop.pay_' . $id, $lang), 'fee' => $provider->fee($settings), 'instructions' => $provider->instructions($settings)];
        }
        return $payments;
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
        if ($priced['subscriptions'] && $customer === null) {
            $errors[] = 'account_subscription';
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
            'shipping' => in_array($quote['method']['kind'] ?? '', ['pickup', 'download'], true) ? [] : $shipping,
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
        $recurring = false;
        if ($this->subscriptions !== null && $customer !== null) {
            foreach ($priced['lines'] as $line) {
                if ($line['subscription'] !== null) {
                    $sub = $this->subscriptions->create((int)$customer['id'], $created['id'], $line, $lang, $provider->id());
                    $this->orders->addEvent($created['id'], 'subscription', 'Subscription #' . $sub . ' starts once this order is paid.', 'checkout');
                    $recurring = true;
                }
            }
        }
        $start = $provider->start(($this->orders->get($created['id']) ?? ($order + $created)) + ['recurring' => $recurring], $paymentSettings);
        if (($start['error'] ?? '') !== '') {
            // The provider could not be reached: the order goes, and its stock and coupon come back.
            $this->orders->addEvent($created['id'], 'payment', 'The payment could not start: ' . $start['error'], 'checkout');
            $this->changeStatus($created['id'], 'cancelled', 'checkout', 'The payment could not start.');
            return ['ok' => false, 'errors' => ['payment_start'], 'order' => null, 'redirect' => '', 'quote' => $quote];
        }
        if ($start['status'] !== 'pending_payment') {
            $this->changeStatus($created['id'], $start['status'], 'checkout');
        } else {
            $this->orders->addEvent($created['id'], 'payment', 'Sent to pay with ' . $provider->id() . '.', 'checkout');
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
        if ($this->downloads !== null) {
            // Downloads come with the payment (or, for an order paid on delivery, when it is completed) and go with a cancel or refund.
            if (in_array($to, ['paid', 'completed'], true)) {
                $given = $this->downloads->grant($id, $lines);
                if ($given > 0) {
                    $this->orders->addEvent($id, 'download', $given === 1 ? 'A file can be downloaded.' : $given . ' files can be downloaded.', 'system');
                }
            } elseif (in_array($to, ['cancelled', 'refunded'], true)) {
                $this->downloads->revoke($id);
            }
        }
        if ($this->subscriptions !== null) {
            if ($to === 'paid') {
                $this->subscriptions->orderPaid($id);
            } elseif ($to === 'cancelled') {
                $this->subscriptions->orderCancelled($id);
            }
        }
        return ['ok' => true, 'message' => 'The order is ' . strtolower(ShopOrders::STATUSES[$to]) . '.', 'order' => $this->orders->get($id)];
    }

    /**
     * Sends an order that is still waiting for its payment to pay again (its page's Pay now), while its stock is still held.
     *
     * @param array<string, mixed> $order
     * @return array{ok: bool, redirect: string, error: string}
     */
    public function payAgain(array $order): array
    {
        $provider = $this->providers[(string)$order['payment_method']] ?? null;
        if ($order['status'] !== 'pending_payment' || $provider === null || (int)$order['reserve_until'] <= time()) {
            return ['ok' => false, 'redirect' => '', 'error' => 'expired'];
        }
        $settings = $this->settings->all()['payments'][$provider->id()] ?? [];
        $recurring = $this->subscriptions !== null && $this->subscriptions->forOrder((int)$order['id']) !== [];
        $start = $provider->start($order + ['recurring' => $recurring], is_array($settings) ? $settings : []);
        if (($start['error'] ?? '') !== '' || $start['redirect'] === '') {
            $this->orders->addEvent((int)$order['id'], 'payment', 'The payment could not start again: ' . ($start['error'] ?? ''), 'customer');
            return ['ok' => false, 'redirect' => '', 'error' => 'payment_start'];
        }
        $this->orders->addEvent((int)$order['id'], 'payment', 'Sent to pay again with ' . $provider->id() . '.', 'customer');
        return ['ok' => true, 'redirect' => $start['redirect'], 'error' => ''];
    }

    /**
     * What a provider said about a payment, asked of its own API (never what a browser or a notification claims): an order is paid
     * only when the provider says the transaction is paid, for this order's number, for exactly its amount in euros, once.
     *
     * @param array{ok: bool, error: string, transaction_id: string, reference: string, paid: bool, failed: bool, amount_cents: int, currency: string, merchant_trns: string, status: string, installments?: int} $tx
     * @return array{outcome: string, order: array<string, mixed>|null, message: string}
     *   outcome: paid, already, pending, failed, mismatch, late (paid after the order was cancelled), unknown, error
     */
    public function confirm(string $providerId, array $tx, string $actor): array
    {
        if ($this->payments === null) {
            return ['outcome' => 'error', 'order' => null, 'message' => 'Payments are not available.'];
        }
        if (!$tx['ok']) {
            return ['outcome' => 'error', 'order' => null, 'message' => $tx['error']];
        }
        $payment = $tx['reference'] !== '' ? $this->payments->byReference($providerId, $tx['reference']) : null;
        $order = $payment !== null ? $this->orders->get((int)$payment['order_id']) : null;
        if ($payment === null || $order === null) {
            return ['outcome' => 'unknown', 'order' => null, 'message' => 'No order of this shop has that payment.'];
        }
        $id = (int)$order['id'];
        if ($tx['failed']) {
            $this->payments->markFailed((int)$payment['id'], 'Status ' . $tx['status']);
            $this->orders->addEvent($id, 'payment', 'The payment did not go through (' . $providerId . ', status ' . $tx['status'] . ').', $actor);
            return ['outcome' => 'failed', 'order' => $order, 'message' => ''];
        }
        if (!$tx['paid']) {
            return ['outcome' => 'pending', 'order' => $order, 'message' => ''];
        }
        $currencyOk = in_array(strtoupper($tx['currency']), ['', '978', 'EUR'], true);
        if ($tx['amount_cents'] !== (int)$payment['amount_cents'] || (int)$payment['amount_cents'] !== (int)$order['total_cents'] || !$currencyOk || (($tx['merchant_trns'] ?? null) !== null && $tx['merchant_trns'] !== (string)$order['number'])) {
            $this->orders->addEvent($id, 'payment', 'A payment that does not match the order was refused: ' . ShopMoney::plain(max(0, $tx['amount_cents'])) . ' ' . $tx['currency'] . ' for "' . mb_substr((string)($tx['merchant_trns'] ?? $tx['reference']), 0, 40) . '" (transaction ' . $tx['transaction_id'] . ').', $actor);
            return ['outcome' => 'mismatch', 'order' => $order, 'message' => ''];
        }
        if (!$this->payments->markPaid((int)$payment['id'], $tx['transaction_id'], 'Status ' . $tx['status'] . (($tx['installments'] ?? 0) > 1 ? ', ' . $tx['installments'] . ' installments' : ''))) {
            return ['outcome' => 'already', 'order' => $order, 'message' => ''];
        }
        $this->orders->addEvent($id, 'payment', 'Paid with ' . $providerId . ': ' . ShopMoney::plain($tx['amount_cents']) . ' EUR, transaction ' . $tx['transaction_id'] . (($tx['installments'] ?? 0) > 1 ? ', in ' . $tx['installments'] . ' installments' : '') . '.', $actor);
        if ($order['status'] === 'pending_payment') {
            $this->changeStatus($id, 'paid', $actor);
            return ['outcome' => 'paid', 'order' => $this->orders->get($id), 'message' => ''];
        }
        if ($order['status'] === 'cancelled') {
            $this->orders->addEvent($id, 'payment', 'The payment came after the order was cancelled: refund it, or take the order again by hand.', $actor);
            return ['outcome' => 'late', 'order' => $order, 'message' => ''];
        }
        return ['outcome' => 'already', 'order' => $order, 'message' => ''];
    }

    /** @return array<string, mixed>|null the order a provider's reference belongs to */
    public function orderOfPayment(string $providerId, string $reference): ?array
    {
        $payment = $this->payments?->byReference($providerId, $reference);
        return $payment !== null ? $this->orders->get((int)$payment['order_id']) : null;
    }

    /** A payment the customer gave up (back from the provider without paying): noted on the order, which keeps waiting while it can. */
    public function paymentFailed(string $providerId, string $reference, string $why): void
    {
        $payment = $this->payments?->byReference($providerId, $reference);
        if ($payment === null || $payment['status'] !== ShopPayments::STARTED) {
            return;
        }
        $this->payments->markFailed((int)$payment['id'], $why);
        $this->orders->addEvent((int)$payment['order_id'], 'payment', $why, $providerId);
    }

    /** Writes a provider's call once by its key; false when it came before. */
    public function noteEvent(string $providerId, string $key, string $kind, ?int $orderId, string $outcome): bool
    {
        return $this->payments?->once($providerId, $key, $kind, $orderId, $outcome) ?? false;
    }

    /** Cancels the orders whose time to be paid ran out, giving back their stock. How many. */
    /**
     * Before an order is let go for not being paid in time, a provider that can be asked (Cardlink, whose answer comes only with
     * the customer's browser) is asked about the payment it started: true when it was paid after all, or when the provider could
     * not be asked (it is asked again ten minutes later, for six hours at most, and then the order is let go).
     *
     * @param array<string, mixed> $order
     */
    private function paidAfterAll(array $order): bool
    {
        $provider = $this->providers[(string)$order['payment_method']] ?? null;
        if (!$provider instanceof Payments\ChecksPayments || $this->payments === null) {
            return false;
        }
        $settings = $this->settings->all()['payments'][$provider->id()] ?? [];
        $giveUp = time() > (int)$order['reserve_until'] + 6 * 3600;
        foreach ($this->payments->forOrder((int)$order['id']) as $payment) {
            if ($payment['status'] !== ShopPayments::STARTED) {
                continue;
            }
            if (!$this->payments->once($provider->id(), 'lookup:' . $payment['reference'] . ':' . intdiv(time(), 600), 'lookup', (int)$order['id'])) {
                return !$giveUp;
            }
            $tx = $provider->lookup($payment, is_array($settings) ? $settings : []);
            if (!$tx['ok']) {
                if ($giveUp) {
                    $this->orders->addEvent((int)$order['id'], 'payment', 'Could not ask ' . $provider->id() . ' about the payment (' . $tx['error'] . '); check it in the bank\'s back office.', 'system');
                    continue;
                }
                return true;
            }
            $result = $this->confirm($provider->id(), $tx, 'system');
            if (in_array($result['outcome'], ['paid', 'already'], true)) {
                return true;
            }
        }
        return false;
    }

    public function expire(): int
    {
        $count = 0;
        foreach ($this->orders->expired(time()) as $order) {
            if ($this->paidAfterAll($order)) {
                continue;
            }
            if ($this->changeStatus((int)$order['id'], 'cancelled', 'system', 'It was not paid in time.')['ok']) {
                $count++;
            }
        }
        return $count;
    }
}
