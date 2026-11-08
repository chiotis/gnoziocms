<?php
/*
 * Subscriptions (docs/shop-plan.md, phase 7) against a fake Viva Wallet: the plan a product's fields make and the next date of a
 * period, the first payment with its sign-up fee, only a way to pay that can charge again and only with an account, the first
 * order allowing recurring payments and starting the subscription with its token, a renewal charged as an order of its own, two
 * runs at once charging once, a refused charge and its tries after 1, 3 and 7 days and then the cancel (with the emails), the
 * customer paying a past-due renewal (a new token), a limited number of renewals ending it, pause, resume, skip and cancel, and a
 * first order never paid.
 *   php tests/unit/shop-subscriptions.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, ContentTypes, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use GnozioCMS\Shop\{ShopCatalogue, ShopCheckout, ShopCoupons, ShopCustomers, ShopOrders, ShopPayments, ShopPricing, ShopSecrets, ShopSettings, ShopStock, ShopSubscriptions};
use GnozioCMS\Shop\Payments\{BankTransfer, VivaWallet};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$root = sys_get_temp_dir() . '/shopsub' . getmypid();
mkdir("$root/storage", 0775, true);
mkdir("$root/custom", 0775, true);
mkdir("$root/content/products", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
file_put_contents("$root/content/products/club.md", "---\ntitle: Λέσχη\nstatus: published\ntranslation_id: club000000000001\ncustom_fields:\n  price: '10'\n  kind: subscription\n  interval: month\n  interval_count: 1\n  signup_fee: '5'\n---\n");
file_put_contents("$root/content/products/box.md", "---\ntitle: Κουτί\nstatus: published\ntranslation_id: box0000000000001\ncustom_fields:\n  price: '30'\n  kind: subscription\n  interval: week\n  interval_count: 2\n  renewals: 1\n---\n");

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'content_types' => ['pages', 'products']];
$content = new ContentRepository("$root/content", new MarkdownConverter((new Environment([]))->addExtension(new CommonMarkCoreExtension())), $settings, fn(): array => $types->catalogue());
$db = new SystemDatabase("$root/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$stock = new ShopStock($db);
$shop = new ShopSettings($meta);
$taxonomies = new Taxonomies("$root/content", ['el', 'en']);
$catalogue = new ShopCatalogue($content, fn() => $taxonomies, fn() => $types, $stock, fn() => 'el');
$pricing = new ShopPricing($catalogue, $stock, $shop, fn(ContentItem $p): string => '/products/' . $p->slug);
$secrets = new ShopSecrets($meta);
$payments = new ShopPayments($db);
$orders = new ShopOrders($db);
$customers = new ShopCustomers($db);
$pdo = $db->connection();

// ---- a fake Viva: orders, transactions, recurring charges
$fake = ['orders' => [], 'charges' => [], 'refuse' => false, 'n' => 0];
$transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$fake): array {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($path === '/connect/token') {
        return ['status' => 200, 'body' => '{"access_token":"tok","expires_in":3600}', 'error' => ''];
    }
    if ($path === '/checkout/v2/orders') {
        $code = (string)(1234567890123456 + $fake['n']++);
        $fake['orders'][$code] = json_decode((string)$body, true);
        return ['status' => 200, 'body' => '{"orderCode":' . $code . '}', 'error' => ''];
    }
    if (preg_match('#^/api/transactions/(.+)$#', $path, $m) && $method === 'POST') {
        $fake['charges'][] = [$m[1], json_decode((string)$body, true)];
        if ($fake['refuse']) {
            return ['status' => 200, 'body' => '{"ErrorCode":10051,"ErrorText":"Insufficient funds","Success":false,"StatusId":"E"}', 'error' => ''];
        }
        return ['status' => 200, 'body' => json_encode(['TransactionId' => sprintf('aaaaaaaa-0000-4000-8000-%012d', count($fake['charges'])), 'StatusId' => 'F', 'ErrorCode' => 0, 'Success' => true]), 'error' => ''];
    }
    return ['status' => 500, 'body' => '', 'error' => ''];
};
$viva = new VivaWallet($secrets, $payments, $meta, $transport);
$told = [];
$checkout = null;
$providers = ['viva' => $viva, 'bank_transfer' => new BankTransfer()];
$subs = new ShopSubscriptions($db, $orders, $payments, $shop, function () use (&$checkout) { return $checkout; }, fn(): array => $providers, function (string $kind, array $sub, ?array $order) use (&$told): void { $told[] = [$kind, $sub['id']]; });
$checkout = new ShopCheckout($pricing, new ShopCoupons($db), $orders, $stock, $shop, $providers, fn(string $k, string $l): string => $k, 'el', $payments, null, $subs);
$shop->savePayments(['viva' => ['enabled' => '1', 'demo' => '1', 'merchant_id' => 'merchant-1', 'client_id' => 'client-1'], 'bank_transfer' => ['enabled' => '1', 'accounts' => 'GR16']]);
$secrets->save('viva', ['api_key' => 'api-key-12345', 'client_secret' => 'client-secret-1']);
$vs = $shop->all()['payments']['viva'];
$paid = static function (string $code, int $cents, string $number, string $tx) use ($checkout): array {
    return $checkout->confirm('viva', ['ok' => true, 'error' => '', 'transaction_id' => $tx, 'reference' => $code, 'paid' => true, 'failed' => false, 'amount_cents' => $cents, 'currency' => '978', 'merchant_trns' => $number, 'status' => 'F', 'installments' => 0], 'viva');
};

// ---- plans and periods
check('a product\'s plan', ShopSubscriptions::plan(['interval' => 'week', 'interval_count' => '2', 'renewals' => '3', 'signup_fee' => '4,50']), ['interval' => 'week', 'count' => 2, 'renewals' => 3, 'signup_cents' => 450]);
check('an odd one: a month, once, until cancelled', ShopSubscriptions::plan(['interval' => 'decade', 'interval_count' => 99]), ['interval' => 'month', 'count' => 12, 'renewals' => 0, 'signup_cents' => 0]);
$at = static fn(string $date): int => (new DateTimeImmutable($date))->getTimestamp();
check('the next period: a month on, the month\'s last day when shorter', [date('Y-m-d', ShopSubscriptions::nextAt($at('2026-01-31 10:00'), 'month', 1)), date('Y-m-d', ShopSubscriptions::nextAt($at('2028-01-31 10:00'), 'month', 1)), date('Y-m-d', ShopSubscriptions::nextAt($at('2026-03-15 10:00'), 'month', 3))], ['2026-02-28', '2028-02-29', '2026-06-15']);
check('weeks and years', [date('Y-m-d', ShopSubscriptions::nextAt($at('2026-11-08 10:00'), 'week', 2)), date('Y-m-d', ShopSubscriptions::nextAt($at('2028-02-29 10:00'), 'year', 1))], ['2026-11-22', '2029-02-28']);

// ---- the checkout
$cart = ['lines' => [['product_id' => 'club000000000001', 'variant_key' => '', 'quantity' => 1]], 'coupon' => ''];
$quote = $checkout->quote($cart['lines'], '', ['country' => 'GR'], '', '', 'el');
check('the first payment is the price and the sign-up fee; nothing to send', [$quote['priced']['lines'][0]['unit_cents'], $quote['priced']['lines'][0]['subscription']['price_cents'], $quote['digital_only'], $quote['totals']['total']], [1500, 1000, true, 1500]);
check('only a way to pay that can charge again', array_column($quote['payments'], 'id'), ['viva']);
$form = ['email' => 'maria@example.com', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR', 'phone' => '210000'], 'payment' => 'viva', 'method' => 'download'];
check('not without an account', $checkout->place($form, $cart, null, 'el')['errors'], ['account_subscription']);
$customerId = $customers->create('maria@example.com', 'a long password', 'Maria', 'el');
$customer = $customers->get($customerId);
$placed = $checkout->place($form, $cart, $customer, 'el');
$order = $placed['order'];
$code = substr($placed['redirect'], strrpos($placed['redirect'], '=') + 1);
$sub = $subs->forOrder($order['id'])[0];
check('a subscription waits for its first order', [$placed['ok'], $sub['status'], $sub['price_cents'], $sub['interval'], $sub['customer_id']], [true, 'pending', 1000, 'month', $customerId]);
check('Viva is asked to allow charging again', $fake['orders'][$code]['allowRecurring'], true);
$paid($code, 1500, $order['number'], '7f9b0a2e-0000-4000-8000-000000000001');
$sub = $subs->get($sub['id']);
check('paid: active, with its token, due a month on', [$sub['status'], $sub['token'], date('Y-m-d', $sub['next_at'])], ['active', '7f9b0a2e-0000-4000-8000-000000000001', date('Y-m-d', ShopSubscriptions::nextAt(time(), 'month', 1))]);
check('nothing is due yet', $subs->renewDue(), 0);

// ---- a renewal
$due = time() - 60;
$pdo->exec('UPDATE shop_subscriptions SET next_at = ' . $due . ' WHERE id = ' . $sub['id']);
check('due: one renewal charged', $subs->renewDue(), 1);
check('on the token, for the price without the fee', [$fake['charges'][0][0], $fake['charges'][0][1]['amount']], ['7f9b0a2e-0000-4000-8000-000000000001', 1000]);
$renewal = $subs->orders($sub['id'])[0];
check('an order of its own, paid, with the address of the first', [$renewal['link_kind'], $renewal['status'], $renewal['total_cents'], $renewal['billing']['city'], $renewal['customer_id']], ['renewal', 'paid', 1000, 'Athens', $customerId]);
$sub = $subs->get($sub['id']);
check('the subscription moves a period on from when it was due, its token kept', [$sub['renewed'], date('Y-m-d', $sub['next_at']), $sub['token']], [1, date('Y-m-d', ShopSubscriptions::nextAt($due, 'month', 1)), '7f9b0a2e-0000-4000-8000-000000000001']);
check('and is not charged twice', $subs->renewDue(), 0);

// ---- refused, tried again, given up
$fake['refuse'] = true;
$pdo->exec('UPDATE shop_subscriptions SET next_at = ' . (time() - 60) . ' WHERE id = ' . $sub['id']);
$subs->renewDue();
$sub = $subs->get($sub['id']);
$refused = $subs->orders($sub['id'])[0];
check('refused: the renewal order cancelled, the subscription past due', [$refused['status'], $sub['status'], $sub['failures']], ['cancelled', 'past_due', 1]);
check('tried again a day later; the customer told', [abs($sub['next_at'] - (time() + 86400)) < 5, $told], [true, [['failed', $sub['id']]]]);
foreach ([3, 7] as $i => $days) {
    $pdo->exec('UPDATE shop_subscriptions SET next_at = ' . (time() - 60) . ' WHERE id = ' . $sub['id']);
    $subs->renewDue();
    $sub = $subs->get($sub['id']);
    check('then ' . $days . ' days later', [$sub['status'], $sub['failures'], abs($sub['next_at'] - (time() + 86400 * $days)) < 5], ['past_due', $i + 2, true]);
}

// ---- the customer pays it themselves
$fake['refuse'] = false;
$pay = $subs->payNow($sub['id'], $customerId);
check('not someone else\'s', $subs->payNow($sub['id'], $customerId + 1)['ok'], false);
$code2 = substr($pay['redirect'], strrpos($pay['redirect'], '=') + 1);
check('paying a past-due renewal: off to Viva, allowing to charge again', [$pay['ok'], $fake['orders'][$code2]['allowRecurring'], $fake['orders'][$code2]['amount']], [true, true, 1000]);
$paid($code2, 1000, $pay['order']['number'], '7f9b0a2e-0000-4000-8000-000000000002');
$sub = $subs->get($sub['id']);
check('paid: active again, the new card its token, no failures', [$sub['status'], $sub['token'], $sub['failures'], $sub['renewed']], ['active', '7f9b0a2e-0000-4000-8000-000000000002', 0, 2]);

// ---- given up after the last try
$fake['refuse'] = true;
$pdo->exec('UPDATE shop_subscriptions SET failures = 3, status = \'past_due\', next_at = ' . (time() - 60) . ' WHERE id = ' . $sub['id']);
$told = [];
$subs->renewDue();
$sub = $subs->get($sub['id']);
check('the fourth refusal: cancelled, the customer told, nothing more due', [$sub['status'], $sub['next_at'], $told], ['cancelled', 0, [['cancelled', $sub['id']]]]);
$fake['refuse'] = false;

// ---- a number of renewals, then it ends
$box = $checkout->place($form, ['lines' => [['product_id' => 'box0000000000001', 'variant_key' => '', 'quantity' => 2]], 'coupon' => ''], $customer, 'el');
$boxCode = substr($box['redirect'], strrpos($box['redirect'], '=') + 1);
$paid($boxCode, 6000, $box['order']['number'], '7f9b0a2e-0000-4000-8000-000000000003');
$boxSub = $subs->forOrder($box['order']['id'])[0];
check('every two weeks, two of them', [$boxSub['status'], $boxSub['quantity'], $boxSub['renewals'], date('Y-m-d', $boxSub['next_at'])], ['active', 2, 1, date('Y-m-d', time() + 14 * 86400)]);
$pdo->exec('UPDATE shop_subscriptions SET next_at = ' . (time() - 60) . ' WHERE id = ' . $boxSub['id']);
$subs->renewDue();
$boxSub = $subs->get($boxSub['id']);
check('its one renewal made: ended', [$boxSub['status'], $boxSub['renewed'], $boxSub['next_at'], end($fake['charges'])[1]['amount']], ['ended', 1, 0, 6000]);

// ---- the shop's actions, the customer's
$third = $checkout->place($form, $cart, $customer, 'el');
$c3 = substr($third['redirect'], strrpos($third['redirect'], '=') + 1);
$paid($c3, 1500, $third['order']['number'], '7f9b0a2e-0000-4000-8000-000000000004');
$s3 = $subs->forOrder($third['order']['id'])[0];
check('the customer cannot pause it', $subs->act($s3['id'], 'pause', $customerId)['ok'], false);
check('the shop pauses it: nothing is charged', [$subs->act($s3['id'], 'pause')['ok'], $subs->get($s3['id'])['status']], [true, 'paused']);
$pdo->exec('UPDATE shop_subscriptions SET next_at = ' . (time() - 60) . ' WHERE id = ' . $s3['id']);
check('not even when due', $subs->renewDue(), 0);
check('resumed: due now at the soonest', [$subs->act($s3['id'], 'resume')['ok'], $subs->get($s3['id'])['status'], $subs->get($s3['id'])['next_at'] >= time() - 1], [true, 'active', true]);
$before = $subs->get($s3['id'])['next_at'];
check('a period skipped', [$subs->act($s3['id'], 'skip')['ok'], date('Y-m-d', $subs->get($s3['id'])['next_at'])], [true, date('Y-m-d', ShopSubscriptions::nextAt($before, 'month', 1))]);
check('the customer cancels it', [$subs->act($s3['id'], 'cancel', $customerId)['ok'], $subs->get($s3['id'])['status'], $subs->act($s3['id'], 'resume')['ok']], [true, 'cancelled', false]);

// ---- a first order never paid
$fourth = $checkout->place($form, $cart, $customer, 'el');
$s4 = $subs->forOrder($fourth['order']['id'])[0];
$pdo->exec('UPDATE shop_orders SET reserve_until = ' . (time() - 60) . ' WHERE id = ' . (int)$fourth['order']['id']);
$checkout->expire();
check('its order let go: the subscription never starts', $subs->get($s4['id'])['status'], 'cancelled');
check('the counts by status', array_filter($subs->counts()), ['cancelled' => 3, 'ended' => 1]);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
