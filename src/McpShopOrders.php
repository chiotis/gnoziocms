<?php

declare(strict_types=1);

namespace GnozioCMS;

use GnozioCMS\Shop\Shop;
use GnozioCMS\Shop\ShopAddress;
use GnozioCMS\Shop\ShopAdmin;
use GnozioCMS\Shop\ShopCoupons;
use GnozioCMS\Shop\ShopMoney;
use GnozioCMS\Shop\ShopOrders;
use GnozioCMS\Shop\ShopServices;

/**
 * The shop's orders and coupons through MCP (docs/mcp-shop-plan.md, step 5). Orders hold shoppers' names and addresses, so they
 * need the shop:orders scope (off until a person ticks it) and the account's shop.orders permission. An order moves only along
 * the steps the order screen allows, and never to paid or refunded: money is confirmed and given back by a person in the admin.
 * Status, shipping and notes go through the order screen's own actions (ShopAdmin::orderAction), so stock, coupons, emails to
 * the customer and the order's timeline follow as there. Coupons need shop.manage.
 */
final class McpShopOrders
{
    /** What an order can be moved to through MCP; paid and refunded stay with a person. */
    public const MOVES = ['on_hold', 'processing', 'shipped', 'completed', 'cancelled'];

    public function __construct(
        private ShopServices $shop,
        private ShopAdmin $admin,
        private ContentRepository $content,
        private Taxonomies $taxonomies,
        private McpStore $store,
        private string $defaultLang,
        /** @var (\Closure(): void)|null what visitors see changed (stock given back by a cancelled order) */
        private ?\Closure $changed = null,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $str = static fn(string $description, ?int $max = null): array => ['type' => 'string', 'description' => $description] + ($max !== null ? ['maxLength' => $max] : []);
        $schema = static fn(array $properties, array $required): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
        $orders = [McpOAuth::SCOPE_SHOP_ORDERS];
        $number = $str('The order number, such as AL-2026-00042.', 40);
        $date = static fn(string $description): array => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => $description . ' (YYYY-MM-DD)'];
        $coupon = [
            'kind' => ['type' => 'string', 'enum' => array_keys(ShopCoupons::KINDS), 'description' => 'percent: a percentage off; fixed: an amount off; free_shipping.'],
            'amount' => ['type' => ['number', 'string'], 'description' => 'The percentage (1 to 100) or the amount off; not for free_shipping.'],
            'min' => ['type' => ['number', 'string'], 'description' => 'The least the items must cost for it to apply; 0 for none.'],
            'starts_at' => $date('The first day it works; "" for now.') + ['pattern' => '^(\d{4}-\d{2}-\d{2})?$'],
            'ends_at' => $date('The last day it works; "" for no end.') + ['pattern' => '^(\d{4}-\d{2}-\d{2})?$'],
            'usage_limit' => ['type' => 'integer', 'minimum' => 0, 'description' => 'How many times it can be used in all; 0 for no limit.'],
            'per_customer' => ['type' => 'integer', 'minimum' => 0, 'description' => 'How many times one customer can use it; 0 for no limit.'],
            'collections' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only for products of these collections (term ids, see shop_info); [] for all.'],
            'products' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only for these products (their addresses, as list_products gives them); [] for all.'],
            'active' => ['type' => 'boolean'],
            'note' => $str('A note for the shop, not shown to customers.', 200),
        ];
        return [
            'list_orders' => [
                'scopes' => $orders, 'permission' => 'shop.orders', 'shop' => true, 'title' => 'List orders',
                'description' => 'Orders, newest first: number, date, status, total, customer, payment, shipping and tracking. Filters combine.',
                'inputSchema' => $schema([
                    'status' => ['type' => 'string', 'enum' => array_keys(ShopOrders::STATUSES)],
                    'from' => $date('Placed on or after this day.'),
                    'to' => $date('Placed on or before this day.'),
                    'query' => $str('Words in the number, email or name.', 80),
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30],
                ], []),
                'annotations' => $ro,
            ],
            'get_order' => [
                'scopes' => $orders, 'permission' => 'shop.orders', 'shop' => true, 'title' => 'Read an order',
                'description' => 'One order in full: its lines, money, addresses, payment, shipping, the timeline of what happened to it, and the statuses update_order can move it to.',
                'inputSchema' => $schema(['order' => $number], ['order']),
                'annotations' => $ro,
            ],
            'update_order' => [
                'scopes' => $orders, 'permission' => 'shop.orders', 'shop' => true, 'title' => 'Move an order along',
                'description' => 'Changes an order\'s status (to ' . implode(', ', self::MOVES) . ', along the steps the order allows), marks it shipped with its carrier and tracking number, or adds a note to its timeline. '
                    . 'notify_customer emails the customer about a shipped or cancelled order. Marking an order paid or refunded, and refunds, are done by a person in the admin.',
                'inputSchema' => $schema([
                    'order' => $number,
                    'status' => ['type' => 'string', 'enum' => self::MOVES],
                    'carrier' => $str('Who carries it (ELTA, ACS, …). With carrier or tracking the order becomes shipped.', 60),
                    'tracking' => $str('The tracking number.', 80),
                    'note' => $str('A note for the order\'s timeline (with a status: why it changed). Customers do not see it.', 2000),
                    'notify_customer' => ['type' => 'boolean', 'default' => false, 'description' => 'Email the customer about the new status (shipped, cancelled).'],
                ], ['order']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true],
            ],
            'list_coupons' => [
                'scopes' => [McpOAuth::SCOPE_SHOP_READ, McpOAuth::SCOPE_SHOP_WRITE], 'permission' => 'shop.manage', 'shop' => true, 'title' => 'List coupons',
                'description' => 'The shop\'s coupons: code, kind, amount, minimum, dates, limits, how often used, the collections and products they are for, and whether active.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'annotations' => $ro,
            ],
            'save_coupon' => [
                'scopes' => [McpOAuth::SCOPE_SHOP_WRITE], 'permission' => 'shop.manage', 'shop' => true, 'title' => 'Add or change a coupon',
                'description' => 'Adds a coupon, or changes the one with this code: what is given changes, the rest stays. A new coupon needs kind (and amount unless free_shipping). new_code renames it.',
                'inputSchema' => $schema(['code' => $str('The code customers type: letters, digits, dashes.', 40), 'new_code' => $str('A new code for it.', 40)] + $coupon, ['code']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'delete_coupon' => [
                'scopes' => [McpOAuth::SCOPE_SHOP_DELETE], 'permission' => 'shop.manage', 'shop' => true, 'title' => 'Delete a coupon',
                'description' => 'Deletes a coupon. Orders that used it keep it. To stop it for a while, save it with active false instead.',
                'inputSchema' => $schema(['code' => $str('Its code.', 40)], ['code']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    public function call(string $tool, array $args, array $connection): array
    {
        return match ($tool) {
            'list_orders' => $this->listOrders($args),
            'get_order' => $this->getOrder($args),
            'update_order' => $this->updateOrder($args, $connection),
            'list_coupons' => ['coupons' => array_map($this->coupon(...), $this->shop->coupons()->all())],
            'save_coupon' => $this->saveCoupon($args, $connection),
            'delete_coupon' => $this->deleteCoupon($args, $connection),
        };
    }

    // ---- orders

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listOrders(array $args): array
    {
        // Orders waiting for payment whose hold ran out are cancelled first, as the order list does.
        $this->shop->checkout()->expire();
        $perPage = max(1, min(100, (int)($args['per_page'] ?? 30)));
        $page = max(1, (int)($args['page'] ?? 1));
        $found = $this->shop->orders()->search(['status' => (string)($args['status'] ?? ''), 'q' => (string)($args['query'] ?? ''), 'from' => (string)($args['from'] ?? ''), 'to' => (string)($args['to'] ?? '')], $page, $perPage);
        return [
            'total' => $found['total'],
            'page' => $page,
            'pages' => max(1, (int)ceil($found['total'] / $perPage)),
            'counts' => $this->shop->orders()->counts(),
            'orders' => array_map($this->summary(...), $found['rows']),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function getOrder(array $args): array
    {
        $order = $this->order($args['order'] ?? null);
        $full = $this->admin->order((int)$order['id']) ?? throw new \InvalidArgumentException('That order cannot be read.');
        return $this->summary($order) + [
            'phone' => (string)($order['billing']['phone'] ?? ''),
            'billing' => array_values($full['order']['billing_lines']),
            'shipping' => array_values($full['order']['shipping_lines']),
            'gift_wrap' => (bool)$order['gift_wrap'],
            'gift_note' => (string)($order['gift_note'] ?? ''),
            'customer_note' => (string)($order['customer_note'] ?? ''),
            'coupon' => (string)$order['coupon'],
            'money' => [
                'items' => ShopMoney::plain($order['items_cents']),
                'discount' => ShopMoney::plain($order['discount_cents']),
                'shipping' => ShopMoney::plain($order['shipping_cents']),
                'fee' => ShopMoney::plain($order['fee_cents']),
                'vat' => ShopMoney::plain($order['tax_cents']),
                'total' => ShopMoney::plain($order['total_cents']),
            ],
            'lines' => array_map(static fn(array $l): array => [
                'title' => (string)$l['title'], 'variant' => (string)$l['variant'], 'sku' => (string)$l['sku'], 'quantity' => (int)$l['quantity'],
                'unit_price' => ShopMoney::plain((int)$l['unit_cents']), 'total' => ShopMoney::plain((int)$l['total_cents']), 'vat_rate' => $l['tax_rate'],
            ], $full['lines']),
            'payments' => array_map(static fn(array $p): array => [
                'provider' => (string)$p['provider'], 'status' => (string)($p['status'] ?? ''), 'amount' => ShopMoney::plain((int)$p['amount_cents']), 'refunded' => ShopMoney::plain((int)$p['refunded_cents']),
            ], $full['payments']),
            'timeline' => array_map(static fn(array $e): array => [
                'at' => gmdate('Y-m-d\TH:i:s\Z', (int)$e['at']), 'kind' => (string)($e['kind'] ?? ''), 'message' => (string)($e['message'] ?? ''), 'by' => (string)($e['actor'] ?? ''),
            ], $full['events']),
            'can_move_to' => array_values(array_intersect(ShopOrders::NEXT[$order['status']] ?? [], self::MOVES)),
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function updateOrder(array $args, array $connection): array
    {
        $order = $this->order($args['order'] ?? null);
        $id = (int)$order['id'];
        $status = isset($args['status']) ? (string)$args['status'] : null;
        $ships = array_key_exists('carrier', $args) || array_key_exists('tracking', $args);
        $note = trim((string)($args['note'] ?? ''));
        $notify = ($args['notify_customer'] ?? false) === true;
        $problems = [];
        if ($status === null && !$ships && $note === '') {
            $problems[] = 'update_order changes nothing: give status, carrier and tracking, or a note.';
        }
        if ($status !== null && !in_array($status, self::MOVES, true)) {
            $problems[] = 'status must be one of: ' . implode(', ', self::MOVES) . '. Paid and refunded are marked by a person in the admin.';
        }
        if ($ships && $status !== null && $status !== 'shipped') {
            $problems[] = 'carrier and tracking mark the order shipped; leave status out, or give shipped.';
        }
        $to = $ships ? 'shipped' : $status;
        $allowed = array_values(array_intersect(ShopOrders::NEXT[$order['status']] ?? [], self::MOVES));
        if ($to !== null && $to !== $order['status'] && !in_array($to, $allowed, true)) {
            $problems[] = 'Order ' . $order['number'] . ' is ' . $order['status'] . ' and cannot become ' . $to . ' through MCP' . ($allowed !== [] ? ' (it can become: ' . implode(', ', $allowed) . ')' : '') . '.';
        }
        if ($to !== null && $to === $order['status'] && !$ships) {
            $problems[] = 'Order ' . $order['number'] . ' is already ' . $to . '.';
        }
        if (($problem = $this->store->shopLimitProblem($connection, 1)) !== null) {
            $problems[] = $problem;
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $actor = (string)$connection['username'] . ' (MCP: ' . $connection['name'] . ')';
        $messages = [];
        if ($ships) {
            $result = $this->admin->orderAction($id, ['action' => 'ship', 'carrier' => (string)($args['carrier'] ?? $order['carrier'] ?? ''), 'tracking' => (string)($args['tracking'] ?? $order['tracking'] ?? ''), 'notify' => $notify ? '1' : ''], $actor);
            $messages[] = $result['message'];
            if ($result['ok'] && $note !== '') {
                $messages[] = $this->admin->orderAction($id, ['action' => 'note', 'text' => $note], $actor)['message'];
            }
        } elseif ($to !== null) {
            $result = $this->admin->orderAction($id, ['action' => 'status', 'to' => $to, 'note' => $note, 'notify' => $notify ? '1' : ''], $actor);
            $messages[] = $result['message'];
        } else {
            $result = $this->admin->orderAction($id, ['action' => 'note', 'text' => $note], $actor);
            $messages[] = $result['message'];
        }
        if (!$result['ok']) {
            throw new \InvalidArgumentException(implode(' ', $messages));
        }
        $this->store->recordShopChanges((int)$connection['id'], 1);
        if ($to === 'cancelled' && $this->changed !== null) {
            // A cancelled order gives its stock back, and "sold out" may no longer be true.
            ($this->changed)();
        }
        return ['done' => implode(' ', $messages), 'order' => $this->summary($this->shop->orders()->get($id) ?? $order)];
    }

    /** @return array<string, mixed> */
    private function order(mixed $number): array
    {
        $order = is_string($number) && trim($number) !== '' ? $this->shop->orders()->byNumber(trim($number)) : null;
        if ($order === null) {
            throw new \InvalidArgumentException('There is no order "' . (is_scalar($number) ? $number : '') . '". list_orders finds it.');
        }
        return $order;
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function summary(array $order): array
    {
        return [
            'order' => (string)$order['number'],
            'placed_at' => gmdate('Y-m-d\TH:i:s\Z', (int)$order['created_at']),
            'status' => (string)$order['status'],
            'paid_at' => $order['paid_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int)$order['paid_at']) : null,
            'total' => ShopMoney::plain((int)$order['total_cents']),
            'currency' => (string)($order['currency'] ?? 'EUR'),
            'customer' => ShopAddress::name($order['billing']),
            'email' => (string)$order['email'],
            'payment' => (string)$order['payment_method'],
            'shipping_method' => (string)($order['shipping_label'] ?? ''),
            'carrier' => (string)($order['carrier'] ?? ''),
            'tracking' => (string)($order['tracking'] ?? ''),
        ];
    }

    // ---- coupons

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function saveCoupon(array $args, array $connection): array
    {
        $code = ShopCoupons::normalizeCode($args['code'] ?? '');
        if ($code === '' || $code !== strtoupper(trim((string)($args['code'] ?? '')))) {
            throw new \InvalidArgumentException('code must be letters, digits and dashes, at most 40 (it is kept in capitals).');
        }
        $existing = $this->shop->coupons()->find($code);
        $problems = [];
        if ($existing === null && !isset($args['kind'])) {
            $problems[] = 'A new coupon needs kind: ' . implode(', ', array_keys(ShopCoupons::KINDS)) . '.';
        }
        $newCode = isset($args['new_code']) ? ShopCoupons::normalizeCode($args['new_code']) : $code;
        if (isset($args['new_code']) && ($newCode === '' || $existing === null)) {
            $problems[] = $existing === null ? 'new_code renames a coupon that exists; there is no coupon ' . $code . '.' : 'new_code must be letters, digits and dashes.';
        }
        $kind = (string)($args['kind'] ?? $existing['kind'] ?? 'percent');
        $amount = $args['amount'] ?? $existing['amount'] ?? null;
        if ($kind !== 'free_shipping' && !is_numeric(str_replace(',', '.', (string)$amount))) {
            $problems[] = 'amount is needed: the percentage (1 to 100) or the amount off.';
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            if (isset($args[$key]) && $args[$key] !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$args[$key]) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$args[$key]) === false)) {
                $problems[] = $key . ' must be a day as YYYY-MM-DD, or "".';
            }
        }
        $starts = (string)($args['starts_at'] ?? $existing['starts_at'] ?? '');
        $ends = (string)($args['ends_at'] ?? $existing['ends_at'] ?? '');
        if ($starts !== '' && $ends !== '' && $ends < $starts) {
            $problems[] = 'ends_at is before starts_at.';
        }
        $collections = $existing['collections'] ?? [];
        if (isset($args['collections'])) {
            $known = array_column($this->taxonomies->load(Shop::COLLECTIONS)['terms'], 'id');
            $collections = array_map('strval', is_array($args['collections']) ? $args['collections'] : []);
            $unknown = array_diff($collections, $known);
            if ($unknown !== []) {
                $problems[] = 'Unknown collections: ' . implode(', ', $unknown) . ' (shop_info lists their ids).';
            }
        }
        $products = $existing['products'] ?? [];
        if (isset($args['products'])) {
            $products = [];
            foreach (is_array($args['products']) ? $args['products'] : [] as $slug) {
                $item = $this->content->find(Shop::TYPE, Slug::plain((string)$slug), $this->defaultLang, true, false);
                $id = $item !== null ? (string)($item->meta['translation_id'] ?? '') : '';
                if ($id === '') {
                    $problems[] = 'There is no product "' . $slug . '" (list_products gives their addresses).';
                } else {
                    $products[] = $id;
                }
            }
        }
        if (($problem = $this->store->shopLimitProblem($connection, 1)) !== null) {
            $problems[] = $problem;
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $min = $args['min'] ?? (isset($existing['min_cents']) ? ShopMoney::plain((int)$existing['min_cents']) : '');
        $post = [
            'code' => $newCode,
            'kind' => $kind,
            'amount' => (string)$amount,
            'min' => (string)$min,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'usage_limit' => (int)($args['usage_limit'] ?? $existing['usage_limit'] ?? 0),
            'per_customer' => (int)($args['per_customer'] ?? $existing['per_customer'] ?? 0),
            'collections' => $collections,
            'products' => $products,
            'active' => ($args['active'] ?? $existing['active'] ?? true) ? '1' : '',
            'note' => (string)($args['note'] ?? $existing['note'] ?? ''),
        ];
        $error = $this->shop->coupons()->save($post, $existing !== null ? $code : '');
        if ($error !== '') {
            throw new \InvalidArgumentException($error);
        }
        $this->store->recordShopChanges((int)$connection['id'], 1);
        $saved = $this->shop->coupons()->find($newCode);
        return ['created' => $existing === null, 'coupon' => $saved !== null ? $this->coupon($saved) : null];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function deleteCoupon(array $args, array $connection): array
    {
        $code = ShopCoupons::normalizeCode($args['code'] ?? '');
        if ($this->shop->coupons()->find($code) === null) {
            throw new \InvalidArgumentException('There is no coupon "' . ($args['code'] ?? '') . '". list_coupons lists them.');
        }
        if (($problem = $this->store->shopLimitProblem($connection, 1)) !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $this->admin->couponAction(['action' => 'delete', 'code' => $code]);
        $this->store->recordShopChanges((int)$connection['id'], 1);
        return ['deleted' => $code];
    }

    /** @param array<string, mixed> $coupon @return array<string, mixed> */
    private function coupon(array $coupon): array
    {
        $slugs = [];
        foreach ($coupon['products'] as $id) {
            foreach ($this->content->getItems(Shop::TYPE, $this->defaultLang, true, false) as $item) {
                if ((string)($item->meta['translation_id'] ?? '') === $id) {
                    $slugs[] = $item->slug;
                    break;
                }
            }
        }
        return [
            'code' => $coupon['code'],
            'kind' => $coupon['kind'],
            'amount' => $coupon['kind'] === 'free_shipping' ? null : $coupon['amount'],
            'min' => ShopMoney::plain((int)$coupon['min_cents']),
            'starts_at' => $coupon['starts_at'],
            'ends_at' => $coupon['ends_at'],
            'usage_limit' => $coupon['usage_limit'],
            'per_customer' => $coupon['per_customer'],
            'used' => $coupon['used'],
            'collections' => $coupon['collections'],
            'products' => $slugs,
            'active' => $coupon['active'],
            'note' => $coupon['note'],
        ];
    }
}
