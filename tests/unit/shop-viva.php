<?php
/*
 * Viva Wallet (docs/shop-plan.md, phase 4) against a fake of its API: the OAuth token kept and asked again when it runs out, the
 * payment order and what it carries, the redirect, the transaction asked of the API and an order paid only when it matches (amount,
 * currency, number, once), a failed one, a payment after the order was cancelled, refunds, the webhook key, Check connection,
 * installments, the keys kept apart and the hosts it may call.
 *   php tests/unit/shop-viva.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, ContentTypes, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use GnozioCMS\Shop\{ShopCatalogue, ShopCheckout, ShopCoupons, ShopHttp, ShopOrders, ShopPayments, ShopPricing, ShopSecrets, ShopSettings, ShopStock};
use GnozioCMS\Shop\Payments\{BankTransfer, VivaWallet};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$root = sys_get_temp_dir() . '/shopviva' . getmypid();
mkdir("$root/storage", 0775, true);
mkdir("$root/custom", 0775, true);
mkdir("$root/content/products", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
file_put_contents("$root/content/products/ring.md", "---\ntitle: Ring\nstatus: published\ntranslation_id: ring0000000000001\ncustom_fields:\n  price: '124'\n  track_stock: true\n---\n");

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'content_types' => ['pages', 'products']];
$content = new ContentRepository("$root/content", new MarkdownConverter((new Environment([]))->addExtension(new CommonMarkCoreExtension())), $settings, fn(): array => $types->catalogue());
$db = new SystemDatabase("$root/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$stock = new ShopStock($db);
$stock->setOnHand('ring0000000000001', ['' => 3], ['']);
$shop = new ShopSettings($meta);
$taxonomies = new Taxonomies("$root/content", ['el', 'en']);
$catalogue = new ShopCatalogue($content, fn() => $taxonomies, fn() => $types, $stock, fn() => 'el');
$pricing = new ShopPricing($catalogue, $stock, $shop, fn(ContentItem $p): string => '/products/' . $p->slug);
$secrets = new ShopSecrets($meta);
$payments = new ShopPayments($db);
$orders = new ShopOrders($db);

// ---- the fake API
$calls = [];
$fake = ['token' => 0, 'tx' => [], 'orders' => 0];
$transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$calls, &$fake): array {
    $calls[] = [$method, $url, $headers, $body];
    $path = (string)parse_url($url, PHP_URL_PATH);
    $auth = implode("\n", $headers);
    if ($path === '/connect/token') {
        $fake['token']++;
        return str_contains($auth, base64_encode('client-1:client-secret-1'))
            ? ['status' => 200, 'body' => json_encode(['access_token' => 'tok' . $fake['token'], 'expires_in' => 3600]), 'error' => '']
            : ['status' => 400, 'body' => '{"error":"invalid_client"}', 'error' => ''];
    }
    if ($path === '/checkout/v2/orders') {
        return str_contains($auth, 'Bearer tok') ? ['status' => 200, 'body' => '{"orderCode":' . (1234567890123456 + $fake['orders']++) . '}', 'error' => ''] : ['status' => 401, 'body' => '', 'error' => ''];
    }
    if (preg_match('#^/checkout/v2/transactions/(.+)$#', $path, $m)) {
        return isset($fake['tx'][$m[1]]) ? ['status' => 200, 'body' => json_encode($fake['tx'][$m[1]]), 'error' => ''] : ['status' => 404, 'body' => '{"message":"Not found"}', 'error' => ''];
    }
    if ($path === '/api/messages/config/token') {
        return str_contains($auth, base64_encode('merchant-1:api-key-12345')) ? ['status' => 200, 'body' => '{"Key":"WEBHOOKKEY"}', 'error' => ''] : ['status' => 401, 'body' => '', 'error' => ''];
    }
    if (str_starts_with($path, '/api/transactions/') && $method === 'DELETE') {
        return ['status' => 200, 'body' => '{"TransactionId":"refund-tx","ErrorCode":0,"Success":true}', 'error' => ''];
    }
    return ['status' => 500, 'body' => '', 'error' => ''];
};
$viva = new VivaWallet($secrets, $payments, $meta, $transport);
$checkout = new ShopCheckout($pricing, new ShopCoupons($db), $orders, $stock, $shop, ['viva' => $viva, 'bank_transfer' => new BankTransfer()], fn(string $k, string $l): string => $k, 'el', $payments);

// ---- settings and keys
$shop->savePayments(['viva' => ['enabled' => '1', 'demo' => '1', 'merchant_id' => 'merchant-1', 'client_id' => 'client-1', 'source_code' => '1234', 'installments' => "100 = 3\n300=6\nx = 4\n50 = 1"], 'bank_transfer' => ['enabled' => '1', 'accounts' => 'Eurobank GR16']]);
$vs = $shop->all()['payments']['viva'];
check('the settings: demo, ids, the source, the installments table (rows that make sense)', [$vs['demo'], $vs['merchant_id'], $vs['source_code'], $vs['installments']], [true, 'merchant-1', '1234', [[10000, 3], [30000, 6]]]);
check('the most installments for an amount', [VivaWallet::maxInstallments($vs['installments'], 9999), VivaWallet::maxInstallments($vs['installments'], 10000), VivaWallet::maxInstallments($vs['installments'], 50000)], [0, 3, 6]);
check('not offered without its keys', [$viva->configured($vs), $viva->offered($vs, '')], [false, false]);
$secrets->save('viva', ['api_key' => 'api-key-12345', 'client_secret' => 'client-secret-1', 'base' => 'http://evil.example']);
check('the keys are kept apart; a screen sees only their ends; nothing else can be set', [$secrets->view('viva'), array_keys($secrets->of('viva'))], [['api_key' => ['set' => true, 'last4' => '2345'], 'client_secret' => ['set' => true, 'last4' => 'et-1']], ['api_key', 'client_secret']]);
$secrets->save('viva', ['api_key' => '  ']);
check('a blank key keeps the stored one', $secrets->of('viva')['api_key'], 'api-key-12345');
check('the keys are not in the Shop settings', str_contains(json_encode($shop->all()), 'api-key'), false);
check('now it is offered', $viva->offered($vs, ''), true);

// ---- the hosts
$http = new ShopHttp(['demo-api.vivapayments.com', '127.0.0.1']);
check('only its hosts, over https; a fake only on this machine', [$http->allowed('https://demo-api.vivapayments.com/x'), $http->allowed('http://demo-api.vivapayments.com/x'), $http->allowed('https://evil.example/x'), $http->allowed('http://127.0.0.1:9000/x'), $http->allowed('https://user@demo-api.vivapayments.com/')], [true, false, false, true, false]);

// ---- Check connection
check('Check connection: the token and the webhook key both work', $viva->check($vs), ['ok' => true, 'message' => 'Viva Wallet answers: the client and the merchant keys both work (demo).']);
check('the demo hosts were called', array_values(array_unique(array_map(static fn(array $c): string => (string)parse_url($c[1], PHP_URL_HOST), $calls))), ['demo-accounts.vivapayments.com', 'demo.vivapayments.com']);
$secrets->save('viva', ['client_secret' => 'wrong-secret']);
$wrong = $viva->check($vs);
check('a wrong client secret is said so, without the secret', [$wrong['ok'], str_contains($wrong['message'], 'not accepted'), str_contains($wrong['message'], 'wrong-secret')], [false, true, false]);
$secrets->save('viva', ['client_secret' => 'client-secret-1']);
$viva->check($vs);

// ---- placing an order with Viva
$calls = [];
$cart = ['lines' => [['product_id' => 'ring0000000000001', 'variant_key' => '', 'quantity' => 1]], 'coupon' => ''];
$shop->saveShipping(['zones' => [['name' => 'Greece', 'countries' => 'GR', 'methods' => [['kind' => 'flat', 'label' => ['el' => 'Courier'], 'cost' => '0']]]]], ['el']);
$form = ['email' => 'maria@example.com', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR', 'phone' => '+30 210 000'], 'payment' => 'viva'];
$placed = $checkout->place($form, $cart, null, 'el');
$order = $placed['order'];
check('the order waits for its payment and the customer goes to Viva', [$placed['ok'], $order['status'], $placed['redirect']], [true, 'pending_payment', 'https://demo.vivapayments.com/web/checkout?ref=1234567890123456']);
check('its stock is held, not taken', [$stock->forProduct('ring0000000000001')[''], $order['stock_held']], [['on_hand' => 3, 'reserved' => 1], ShopCheckout::HELD]);
$sent = json_decode((string)$calls[0][3], true);
check('the payment order: amount in cents, the number, the customer, the source, installments, the token kept', [count($calls), $sent['amount'], $sent['merchantTrns'], $sent['customer']['fullName'], $sent['customer']['phone'], $sent['customer']['requestLang'], $sent['sourceCode'], $sent['maxInstallments'], $sent['paymentTimeout'] > 1000], [1, 12400, $order['number'], 'Maria Patera', '+30210000', 'el-GR', '1234', 3, true]);
check('the payment is written with its reference', [$payments->byReference('viva', '1234567890123456')['order_id'], $payments->byReference('viva', '1234567890123456')['status']], [$order['id'], 'started']);

// ---- coming back
$tx = '7f9b0a2e-1111-2222-3333-444455556666';
$fake['tx'][$tx] = ['statusId' => 'F', 'amount' => 124.0, 'orderCode' => 1234567890123456, 'merchantTrns' => $order['number'], 'currencyCode' => '978', 'totalInstallments' => 0];
check('not a transaction id', $viva->transaction($vs, '../x')['ok'], false);
$wrongAmount = '7f9b0a2e-1111-2222-3333-444455550000';
$fake['tx'][$wrongAmount] = ['statusId' => 'F', 'amount' => 1.0, 'orderCode' => 1234567890123456, 'merchantTrns' => $order['number'], 'currencyCode' => '978'];
check('a payment of another amount does not pay the order', [$checkout->confirm('viva', $viva->transaction($vs, $wrongAmount), 'test')['outcome'], $orders->get($order['id'])['status']], ['mismatch', 'pending_payment']);
$otherNumber = '7f9b0a2e-1111-2222-3333-444455550001';
$fake['tx'][$otherNumber] = ['statusId' => 'F', 'amount' => 124.0, 'orderCode' => 1234567890123456, 'merchantTrns' => 'AL-OTHER', 'currencyCode' => '978'];
check('nor one for another order\'s number', $checkout->confirm('viva', $viva->transaction($vs, $otherNumber), 'test')['outcome'], 'mismatch');
$unknown = '7f9b0a2e-1111-2222-3333-444455550002';
$fake['tx'][$unknown] = ['statusId' => 'F', 'amount' => 124.0, 'orderCode' => 9999999999999999, 'merchantTrns' => $order['number'], 'currencyCode' => '978'];
check('nor a payment order this shop did not make', $checkout->confirm('viva', $viva->transaction($vs, $unknown), 'test')['outcome'], 'unknown');
$result = $checkout->confirm('viva', $viva->transaction($vs, $tx), 'test');
check('the right one pays it: stock taken, the time it was paid', [$result['outcome'], $result['order']['status'], $result['order']['stock_held'], $stock->forProduct('ring0000000000001')[''], $result['order']['paid_at'] !== null], ['paid', 'paid', ShopCheckout::TAKEN, ['on_hand' => 2, 'reserved' => 0], true]);
check('the same transaction again changes nothing', $checkout->confirm('viva', $viva->transaction($vs, $tx), 'webhook')['outcome'], 'already');
check('the timeline says how it was paid', str_contains(implode(' | ', array_column($orders->events($order['id']), 'message')), 'Paid with viva: 124.00 EUR, transaction ' . $tx), true);
check('an event is written once by its key', [$checkout->noteEvent('viva', '1796:' . $tx, 'webhook', $order['id'], 'paid'), $checkout->noteEvent('viva', '1796:' . $tx, 'webhook', $order['id'], 'already')], [true, false]);

// ---- a failed payment, then paying again
$second = $checkout->place($form, $cart, null, 'el');
$failedTx = '7f9b0a2e-1111-2222-3333-444455557777';
$secondCode = (int)$payments->forOrder($second['order']['id'])[0]['reference'];
$fake['tx'][$failedTx] = ['statusId' => 'E', 'amount' => 124.0, 'orderCode' => $secondCode, 'merchantTrns' => $second['order']['number'], 'currencyCode' => '978'];
check('a failed payment leaves the order waiting', [$checkout->confirm('viva', $viva->transaction($vs, $failedTx), 'test')['outcome'], $orders->get($second['order']['id'])['status']], ['failed', 'pending_payment']);
$again = $checkout->payAgain($orders->get($second['order']['id']));
check('it can be paid again while its stock is held', [$again['ok'], str_contains($again['redirect'], '/web/checkout?ref=')], [true, true]);
$db->connection()->exec('UPDATE shop_orders SET reserve_until = ' . (time() - 10) . ' WHERE id = ' . (int)$second['order']['id']);
check('not once its time ran out', $checkout->payAgain($orders->get($second['order']['id']))['error'], 'expired');
check('then it is cancelled and its stock is free', [$checkout->expire(), $orders->get($second['order']['id'])['status'], $stock->forProduct('ring0000000000001')['']], [1, 'cancelled', ['on_hand' => 2, 'reserved' => 0]]);
$late = '7f9b0a2e-1111-2222-3333-444455558888';
// The customer paid the second attempt at the last moment, and Viva says so after the order was cancelled.
$lateCode = (int)$payments->forOrder($second['order']['id'])[0]['reference'];
$fake['tx'][$late] = ['statusId' => 'F', 'amount' => 124.0, 'orderCode' => $lateCode, 'merchantTrns' => $second['order']['number'], 'currencyCode' => '978'];
check('a payment after the order was cancelled is noted for the shop to refund', [$checkout->confirm('viva', $viva->transaction($vs, $late), 'test')['outcome'], $orders->get($second['order']['id'])['status']], ['late', 'cancelled']);

// ---- refunds and the webhook key
$calls = [];
check('a refund through the API, with the merchant\'s keys', [$viva->refund($vs, $tx, 2400), $calls[0][0], parse_url($calls[0][1], PHP_URL_PATH), parse_url($calls[0][1], PHP_URL_QUERY)], [['ok' => true, 'error' => '', 'transaction_id' => 'refund-tx'], 'DELETE', '/api/transactions/' . $tx, 'amount=2400&sourceCode=1234']);
check('the webhook key, kept a day', [$viva->webhookKey($vs), $viva->webhookKey($vs), count(array_filter($calls, static fn(array $c): bool => str_contains($c[1], 'config/token')))], ['WEBHOOKKEY', 'WEBHOOKKEY', 0]);

// ---- a provider that cannot be reached
$down = new VivaWallet($secrets, $payments, $meta, fn() => ['status' => 0, 'body' => '', 'error' => 'Could not resolve host']);
$meta->setJson('shop_viva_token', []);
$blocked = new ShopCheckout($pricing, new ShopCoupons($db), $orders, $stock, $shop, ['viva' => $down], fn(string $k, string $l): string => $k, 'el', $payments);
$before = $stock->forProduct('ring0000000000001')[''];
$third = $blocked->place($form, $cart, null, 'el');
$last = $orders->search([])['rows'][0];
check('Viva down: the order is not placed, and gives back its stock', [$third['ok'], $third['errors'], $last['status'], $stock->forProduct('ring0000000000001')[''] === $before], [false, ['payment_start'], 'cancelled', true]);

// ---- live hosts
$calls = [];
$live = $vs;
$live['demo'] = false;
$meta->setJson('shop_viva_token', []);
$viva->transaction($live, $tx);
check('live mode calls the live hosts', array_values(array_unique(array_map(static fn(array $c): string => (string)parse_url($c[1], PHP_URL_HOST), $calls))), ['accounts.vivapayments.com', 'api.vivapayments.com']);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
