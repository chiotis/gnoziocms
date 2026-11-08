<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * The Shop group of the admin (docs/shop-plan.md, section 8): Orders (the list with its status chips, search and dates, a CSV for
 * the accountant, and each order with its timeline, status changes, shipping and tracking, notes, its email sent again and a
 * packing slip), Customers (the list, each with their orders, deleting one) and Coupons.
 */
final class ShopAdmin
{
    /**
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log
     * @param \Closure(array<string, mixed>): string $orderUrl the public address of an order, with its key
     */
    public function __construct(
        private ShopServices $shop,
        private \Closure $log,
        private \Closure $orderUrl
    ) {
    }

    // ---- orders

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function orders(array $get): array
    {
        $this->shop->checkout()->expire();
        $filters = ['status' => (string)($get['status'] ?? ''), 'q' => (string)($get['q'] ?? ''), 'from' => (string)($get['from'] ?? ''), 'to' => (string)($get['to'] ?? ''), 'customer' => (int)($get['customer'] ?? 0)];
        $page = max(1, (int)($get['page'] ?? 1));
        $found = $this->shop->orders()->search($filters, $page);
        return [
            'orders' => array_map(fn(array $o): array => $o + ['total' => ShopMoney::format((int)$o['total_cents'], 'en'), 'name' => ShopAddress::name($o['billing'])], $found['rows']),
            'total' => $found['total'],
            'page' => $page,
            'pages' => max(1, (int)ceil($found['total'] / ShopOrders::PER_PAGE)),
            'filters' => $filters,
            'counts' => $this->shop->orders()->counts(),
            'statuses' => ShopOrders::STATUSES,
            'query' => array_filter($filters),
        ];
    }

    /**
     * The orders the list shows, as a CSV for the accountant: one row per order with its VAT, and one per line.
     *
     * @param array<string, mixed> $get
     */
    public function csv(array $get): string
    {
        $filters = ['status' => (string)($get['status'] ?? ''), 'q' => (string)($get['q'] ?? ''), 'from' => (string)($get['from'] ?? ''), 'to' => (string)($get['to'] ?? '')];
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Order', 'Date', 'Status', 'Paid', 'Customer', 'Email', 'VAT number', 'Country', 'Items', 'Discount', 'Coupon', 'Shipping', 'Fee', 'Total', 'VAT', 'Payment', 'Shipping method', 'Line', 'SKU', 'Quantity', 'Unit price', 'Line total', 'VAT rate', 'Line VAT'], ',', '"', '');
        $page = 1;
        do {
            $found = $this->shop->orders()->search($filters, $page, 200);
            foreach ($found['rows'] as $order) {
                $head = [
                    $order['number'], date('Y-m-d H:i', $order['created_at']), $order['status'], $order['paid_at'] ? date('Y-m-d', $order['paid_at']) : '',
                    ShopAddress::name($order['billing']), $order['email'], (string)($order['billing']['vat_number'] ?? ''), (string)($order['billing']['country'] ?? ''),
                    ShopMoney::plain($order['items_cents']), ShopMoney::plain($order['discount_cents']), $order['coupon'], ShopMoney::plain($order['shipping_cents']),
                    ShopMoney::plain($order['fee_cents']), ShopMoney::plain($order['total_cents']), ShopMoney::plain($order['tax_cents']), $order['payment_method'], $order['shipping_label'],
                ];
                foreach ($this->shop->orders()->lines($order['id']) as $line) {
                    fputcsv($out, array_map([self::class, 'cell'], [...$head, $line['title'] . ($line['variant'] !== '' ? ' (' . $line['variant'] . ')' : ''), $line['sku'], $line['quantity'], ShopMoney::plain($line['unit_cents']), ShopMoney::plain($line['total_cents']), $line['tax_rate'], ShopMoney::plain($line['tax_cents'])]), ',', '"', '');
                }
            }
            $page++;
        } while (($page - 1) * 200 < $found['total']);
        rewind($out);
        return (string)stream_get_contents($out);
    }

    /** @return array<string, mixed>|null */
    public function order(int $id): ?array
    {
        $order = $this->shop->orders()->get($id);
        if ($order === null) {
            return null;
        }
        $lines = $this->shop->orders()->lines($id);
        $provider = $this->shop->checkout()->provider((string)$order['payment_method']);
        $customer = $order['customer_id'] !== null ? $this->shop->customers()->get($order['customer_id']) : null;
        return [
            'order' => $order + [
                'name' => ShopAddress::name($order['billing']),
                'billing_lines' => ShopAddress::lines($order['billing'], 'en'),
                'shipping_lines' => ShopAddress::lines($order['shipping'], 'en'),
                'payment_label' => ['viva' => 'Viva Wallet', 'cardlink' => 'Cardlink', 'bank_transfer' => 'Bank transfer', 'cod' => 'Cash on delivery'][$order['payment_method']] ?? $order['payment_method'],
                'money' => array_map(static fn(int $c): string => ShopMoney::format($c, 'en'), ['items' => $order['items_cents'], 'discount' => -$order['discount_cents'], 'shipping' => $order['shipping_cents'], 'fee' => $order['fee_cents'], 'tax' => $order['tax_cents'], 'total' => $order['total_cents']]),
                'public_url' => ($this->orderUrl)($order),
            ],
            'lines' => array_map(static fn(array $l): array => $l + ['unit' => ShopMoney::format($l['unit_cents'], 'en'), 'total' => ShopMoney::format($l['total_cents'], 'en')], $lines),
            'events' => $this->shop->orders()->events($id),
            'downloads' => $this->shop->downloads()->forOrder($id),
            'payments' => array_map(static fn(array $p): array => $p + ['amount' => ShopMoney::format($p['amount_cents'], 'en'), 'refunded' => ShopMoney::format($p['refunded_cents'], 'en'), 'left' => ShopMoney::plain($p['amount_cents'] - $p['refunded_cents'])], $this->shop->payments()->forOrder($id)),
            'next' => array_intersect_key(ShopOrders::STATUSES, array_flip(ShopOrders::NEXT[$order['status']] ?? [])),
            'customer' => $customer,
            'instructions' => $provider !== null ? $provider->instructions(is_array($this->shop->settings()->all()['payments'][$order['payment_method']] ?? null) ? $this->shop->settings()->all()['payments'][$order['payment_method']] : []) : [],
            'store' => $this->shop->settings()->all(),
        ];
    }

    /**
     * An action on an order's screen.
     *
     * @param array<string, mixed> $post action: status (to, note, notify), ship (carrier, tracking, notify), note (text), resend
     * @return array{ok: bool, message: string}
     */
    public function orderAction(int $id, array $post, string $actor): array
    {
        $order = $this->shop->orders()->get($id);
        if ($order === null) {
            return ['ok' => false, 'message' => 'There is no such order.'];
        }
        $action = (string)($post['action'] ?? '');
        $notify = !empty($post['notify']);
        if ($action === 'ship') {
            $carrier = mb_substr(trim((string)($post['carrier'] ?? '')), 0, 60);
            $tracking = mb_substr(trim((string)($post['tracking'] ?? '')), 0, 80);
            $this->shop->orders()->update($id, ['carrier' => $carrier, 'tracking' => $tracking]);
            if ($order['status'] !== 'shipped') {
                $result = $this->shop->checkout()->changeStatus($id, 'shipped', $actor, $tracking !== '' ? 'Tracking: ' . trim($carrier . ' ' . $tracking) . '.' : '');
                if (!$result['ok']) {
                    return ['ok' => false, 'message' => $result['message']];
                }
            } else {
                $this->shop->orders()->addEvent($id, 'note', 'Tracking: ' . trim($carrier . ' ' . $tracking) . '.', $actor);
            }
            $told = $this->tell($id, 'shipped', $notify);
            $this->log('shop.order_shipped', $order, 'Order ' . $order['number'] . ' shipped.');
            return ['ok' => true, 'message' => 'The order is shipped.' . self::told($told)];
        }
        if ($action === 'status') {
            $to = (string)($post['to'] ?? '');
            $result = $this->shop->checkout()->changeStatus($id, $to, $actor, mb_substr(trim((string)($post['note'] ?? '')), 0, 500));
            $told = null;
            if ($result['ok']) {
                $told = $this->tell($id, $to, $notify);
                $this->log('shop.order_status', $order, 'Order ' . $order['number'] . ': ' . $order['status'] . ' → ' . $to . '.');
            }
            return ['ok' => $result['ok'], 'message' => $result['message'] . self::told($told)];
        }
        if ($action === 'refund') {
            return $this->refund($order, (string)($post['amount'] ?? ''), $notify, $actor);
        }
        if ($action === 'note') {
            $text = mb_substr(trim((string)($post['text'] ?? '')), 0, 2000);
            if ($text === '') {
                return ['ok' => false, 'message' => 'Write the note first.'];
            }
            $this->shop->orders()->addEvent($id, 'note', $text, $actor);
            return ['ok' => true, 'message' => 'The note is added.'];
        }
        if ($action === 'download_reset') {
            $ok = $this->shop->downloads()->reset($id, (int)($post['download'] ?? 0));
            if ($ok) {
                $this->shop->orders()->addEvent($id, 'download', 'A download was allowed again from the start.', $actor);
            }
            return ['ok' => $ok, 'message' => $ok ? 'The download can be had again from the start.' : 'There is no such download on this order.'];
        }
        if ($action === 'resend') {
            $sent = $this->shop->mail()->orderPlaced($order, $this->shop->orders()->lines($id), ($this->orderUrl)($order), '');
            $this->shop->orders()->addEvent($id, 'email', $sent ? 'The order\'s email was sent again.' : 'The order\'s email could not be sent.', $actor);
            return ['ok' => $sent, 'message' => $sent ? 'The email is sent again.' : 'The email could not be sent; see Logs > Email.'];
        }
        return ['ok' => false, 'message' => 'Nothing was done.'];
    }

    /**
     * Gives money back through the provider that took it: all that is left, or a part. All of it makes the order refunded.
     *
     * @param array<string, mixed> $order
     * @return array{ok: bool, message: string}
     */
    private function refund(array $order, string $amount, bool $notify, string $actor): array
    {
        $payment = $this->shop->payments()->paidFor((int)$order['id']);
        if ($payment === null || !in_array($payment['provider'], [Payments\VivaWallet::ID, Payments\Cardlink::ID], true)) {
            return ['ok' => false, 'message' => 'This order was not paid through a provider; mark it refunded once the money is back.'];
        }
        $left = (int)$payment['amount_cents'] - (int)$payment['refunded_cents'];
        $cents = trim($amount) === '' ? $left : (ShopMoney::cents($amount) ?? 0);
        if ($cents < 1 || $cents > $left) {
            return ['ok' => false, 'message' => 'A refund is more than zero and at most ' . ShopMoney::format($left, 'en') . '.'];
        }
        $settings = $this->shop->settings()->all()['payments'][$payment['provider']];
        $name = $payment['provider'] === Payments\Cardlink::ID ? 'Cardlink' : 'Viva Wallet';
        $result = $payment['provider'] === Payments\Cardlink::ID
            ? $this->shop->cardlink()->refund($settings, $payment, $cents)
            : $this->shop->viva()->refund($settings, (string)$payment['transaction_id'], $cents);
        if (!$result['ok']) {
            $this->shop->orders()->addEvent((int)$order['id'], 'payment', 'A refund of ' . ShopMoney::plain($cents) . ' EUR was refused: ' . $result['error'], $actor);
            return ['ok' => false, 'message' => $name . ' did not make the refund: ' . $result['error']];
        }
        $this->shop->payments()->addRefund((int)$payment['id'], $cents);
        $this->shop->orders()->addEvent((int)$order['id'], 'payment', 'Refunded ' . ShopMoney::plain($cents) . ' EUR through ' . $name . ($result['transaction_id'] !== '' ? ' (transaction ' . $result['transaction_id'] . ')' : '') . '.', $actor);
        ($this->log)('shop.refund', 'info', 'shop', 'order:' . $order['id'], 'Refunded ' . ShopMoney::plain($cents) . ' EUR on order ' . $order['number'] . '.', []);
        if ($cents === $left && in_array('refunded', ShopOrders::NEXT[$order['status']] ?? [], true)) {
            $this->shop->checkout()->changeStatus((int)$order['id'], 'refunded', $actor, 'All of it was given back.');
            return ['ok' => true, 'message' => 'The money is given back and the order is refunded.' . self::told($this->tell((int)$order['id'], 'refunded', $notify))];
        }
        return ['ok' => true, 'message' => ShopMoney::format($cents, 'en') . ' is given back.'];
    }

    /** Emails the customer about a new status, when asked and when it is one they are told about. Null: nothing was sent. */
    private function tell(int $id, string $status, bool $notify): ?bool
    {
        if (!$notify || !in_array($status, ShopMail::TOLD, true)) {
            return null;
        }
        $order = $this->shop->orders()->get($id);
        if ($order === null) {
            return null;
        }
        $sent = $this->shop->mail()->statusChanged($order, $this->shop->orders()->lines($id), $status, ($this->orderUrl)($order));
        $this->shop->orders()->addEvent($id, 'email', $sent ? 'The customer was told (' . (ShopOrders::STATUSES[$status] ?? $status) . ').' : 'The email to the customer could not be sent.', 'system');
        return $sent;
    }

    private static function told(?bool $sent): string
    {
        return $sent === null ? '' : ($sent ? ' The customer is told.' : ' The email to the customer could not be sent; see Logs > Email.');
    }

    // ---- customers

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    /**
     * Shop > Files: the digital products and the files each one sells.
     *
     * @param array<string, string> $products digital products, id => title
     * @return array<string, mixed>
     */
    public function files(array $products): array
    {
        $all = $this->shop->downloads()->allFiles();
        $rows = [];
        foreach ($products as $id => $title) {
            $rows[] = ['id' => $id, 'title' => $title, 'files' => $all[$id] ?? []];
        }
        $orphans = array_diff_key($all, $products);
        return ['products' => $rows, 'orphans' => $orphans, 'max_mb' => intdiv(ShopDownloads::MAX_BYTES, 1024 * 1024)];
    }

    /**
     * An upload or a removal on Shop > Files.
     *
     * @param array<string, mixed> $post action: upload (product), remove (id)
     * @param array<string, mixed>|null $upload the file from $_FILES
     * @param array<string, string> $products digital products, id => title
     * @return array{ok: bool, message: string}
     */
    public function fileAction(array $post, ?array $upload, array $products, string $actor): array
    {
        $action = (string)($post['action'] ?? '');
        if ($action === 'remove') {
            $file = $this->shop->downloads()->file((int)($post['id'] ?? 0));
            if ($file === null || !$this->shop->downloads()->remove($file['id'])) {
                return ['ok' => false, 'message' => 'There is no such file.'];
            }
            ($this->log)('shop.file_removed', 'info', 'shop', 'file:' . $file['id'], 'Removed the file ' . $file['name'] . '.', []);
            return ['ok' => true, 'message' => $file['name'] . ' is removed. Links already given for it stop working.'];
        }
        if ($action === 'upload') {
            $product = (string)($post['product'] ?? '');
            if (!isset($products[$product])) {
                return ['ok' => false, 'message' => 'Choose one of the digital products.'];
            }
            $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'message' => in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is larger than the server takes; see System status for the upload limit.' : 'Choose a file to upload.'];
            }
            $result = $this->shop->downloads()->add($product, (string)$upload['tmp_name'], (string)($upload['name'] ?? 'file'));
            if ($result['ok']) {
                ($this->log)('shop.file_added', 'info', 'shop', 'product:' . $product, 'Added a file to ' . $products[$product] . '.', ['by' => $actor]);
            }
            return $result;
        }
        return ['ok' => false, 'message' => 'Nothing was done.'];
    }

    public function customers(array $get): array
    {
        $page = max(1, (int)($get['page'] ?? 1));
        $q = (string)($get['q'] ?? '');
        $found = $this->shop->customers()->search($q, $page);
        return [
            'customers' => array_map(static fn(array $c): array => $c + ['spent_text' => ShopMoney::format($c['spent'], 'en')], $found['rows']),
            'total' => $found['total'],
            'page' => $page,
            'pages' => max(1, (int)ceil($found['total'] / 30)),
            'q' => $q,
        ];
    }

    /** @return array<string, mixed>|null */
    public function customer(int $id): ?array
    {
        $customer = $this->shop->customers()->get($id);
        if ($customer === null) {
            return null;
        }
        return [
            'customer' => $customer + ['address_lines' => ShopAddress::lines($customer['address'], 'en')],
            'orders' => array_map(static fn(array $o): array => $o + ['total' => ShopMoney::format((int)$o['total_cents'], 'en')], $this->shop->orders()->forCustomer($id)),
        ];
    }

    /** Deletes a customer's account (their right to be forgotten); their orders stay for the accounts, linked to nobody. */
    public function deleteCustomer(int $id): bool
    {
        $customer = $this->shop->customers()->get($id);
        if ($customer === null) {
            return false;
        }
        $this->shop->orders()->unlink($id);
        $this->shop->customers()->delete($id);
        ($this->log)('shop.customer_deleted', 'warning', 'shop', 'customer:' . $id, 'A customer account was deleted.', []);
        return true;
    }

    // ---- coupons

    /**
     * @param array<string, mixed> $get
     * @param array<string, string> $collections id => name
     * @return array<string, mixed>
     */
    public function coupons(array $get, array $collections): array
    {
        $editing = isset($get['code']) ? $this->shop->coupons()->find((string)$get['code']) : null;
        return [
            'coupons' => $this->shop->coupons()->all(),
            'editing' => $editing,
            'creating' => isset($get['new']),
            'kinds' => ShopCoupons::KINDS,
            'collections' => $collections,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    public function couponAction(array $post): array
    {
        if (($post['action'] ?? '') === 'delete') {
            $this->shop->coupons()->delete((string)($post['code'] ?? ''));
            return ['ok' => true, 'message' => 'The coupon is deleted.'];
        }
        $error = $this->shop->coupons()->save($post, (string)($post['original'] ?? ''));
        if ($error === '') {
            ($this->log)('shop.coupon_saved', 'info', 'shop', 'coupon:' . ShopCoupons::normalizeCode($post['code'] ?? ''), 'A coupon was saved.', []);
        }
        return ['ok' => $error === '', 'message' => $error === '' ? 'The coupon is saved.' : $error];
    }

    /** @param array<string, mixed> $order */
    private function log(string $action, array $order, string $message): void
    {
        ($this->log)($action, 'info', 'shop', 'order:' . $order['id'], $message, []);
    }

    private static function cell(mixed $value): string
    {
        $text = (string)$value;
        // A cell a spreadsheet would read as a formula is written as text.
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true) && !is_numeric($text) ? "'" . $text : $text;
    }
}
