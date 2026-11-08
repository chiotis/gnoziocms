<?php
/*
 * Cardlink (docs/shop-plan.md, phase 5) against a fake of its XML API: the settings and the shared secret kept apart, the order
 * sent to this site's signed "go" page, the installments its amount allows, the form's fields in the spec's order and its digest,
 * the answer believed only with the right digest and merchant and paying the order once for its amount, a cancel, an order whose
 * answer never came asked about before it is let go (paid after all, never paid, the bank not reachable), refunds and the void on
 * the day, the XML message's digest, Check connection and the hosts.
 *   php tests/unit/shop-cardlink.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, ContentTypes, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use GnozioCMS\Shop\{ShopCatalogue, ShopCheckout, ShopCoupons, ShopOrders, ShopPayments, ShopPricing, ShopSecrets, ShopSettings, ShopStock};
use GnozioCMS\Shop\Payments\{BankTransfer, Cardlink};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$root = sys_get_temp_dir() . '/shopcardlink' . getmypid();
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
$stock->setOnHand('ring0000000000001', ['' => 10], ['']);
$shop = new ShopSettings($meta);
$taxonomies = new Taxonomies("$root/content", ['el', 'en']);
$catalogue = new ShopCatalogue($content, fn() => $taxonomies, fn() => $types, $stock, fn() => 'el');
$pricing = new ShopPricing($catalogue, $stock, $shop, fn(ContentItem $p): string => '/products/' . $p->slug);
$secrets = new ShopSecrets($meta);
$payments = new ShopPayments($db);
$orders = new ShopOrders($db);
$pdo = $db->connection();

// ---- the fake XML API: answers by what the test sets in $fake['next']
$calls = [];
$fake = ['next' => null];
$transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$calls, &$fake): array {
    $calls[] = [$method, $url, $headers, (string)$body];
    if ($fake['next'] instanceof Closure) {
        return ($fake['next'])((string)$body);
    }
    return ['status' => 0, 'body' => '', 'error' => 'Could not resolve host'];
};
$xmlAnswer = static fn(string $inner): array => ['status' => 200, 'body' => '<?xml version="1.0" encoding="UTF-8"?><VPOS xmlns="http://www.modirum.com/schemas/vposxmlapi41"><Message messageId="M1" timeStamp="x" version="2.1">' . $inner . '</Message><Digest>abc</Digest></VPOS>', 'error' => ''];
$cardlink = new Cardlink($secrets, $payments, $orders, 'https://shop.example', $transport);
$checkout = new ShopCheckout($pricing, new ShopCoupons($db), $orders, $stock, $shop, ['cardlink' => $cardlink, 'bank_transfer' => new BankTransfer()], fn(string $k, string $l): string => $k, 'el', $payments);

// ---- settings and the shared secret
$shop->savePayments(['cardlink' => ['enabled' => '1', 'test' => '1', 'acquirer' => 'worldline', 'merchant_id' => '0022000230', 'installments' => "100 = 3\n500 = 12"], 'bank_transfer' => ['enabled' => '1']]);
$cs = $shop->all()['payments']['cardlink'];
check('the settings: test, the acquirer, the Merchant ID, installments', [$cs['test'], $cs['acquirer'], $cs['merchant_id'], $cs['installments']], [true, 'worldline', '0022000230', [[10000, 3], [50000, 12]]]);
$shop->savePayments(['cardlink' => ['acquirer' => 'evil', 'merchant_id' => 'abc']]);
check('an unknown acquirer is Cardlink; a Merchant ID is digits', [$shop->all()['payments']['cardlink']['acquirer'], $shop->all()['payments']['cardlink']['merchant_id']], ['cardlink', '']);
$shop->savePayments(['cardlink' => ['enabled' => '1', 'test' => '1', 'acquirer' => 'worldline', 'merchant_id' => '0022000230', 'installments' => "100 = 3\n500 = 12"], 'bank_transfer' => ['enabled' => '1']]);
$cs = $shop->all()['payments']['cardlink'];
check('not offered without the shared secret', $cardlink->offered($cs, ''), false);
$secrets->save('cardlink', ['shared_secret' => 'Cardlink1', 'base' => 'http://evil.example']);
check('the secret is kept apart; a screen sees its end; nothing else can be set', [$secrets->view('cardlink'), array_keys($secrets->of('cardlink'))], [['shared_secret' => ['set' => true, 'last4' => 'ink1']], ['shared_secret']]);
check('the secret is not in the Shop settings', str_contains(json_encode($shop->all()), 'Cardlink1'), false);
check('now it is offered', $cardlink->offered($cs, ''), true);

// ---- placing an order: off to this site's signed go page
$shop->saveShipping(['zones' => [['name' => 'Greece', 'countries' => 'GR', 'methods' => [['kind' => 'flat', 'label' => ['el' => 'Courier'], 'cost' => '0']]]]], ['el']);
$cart = ['lines' => [['product_id' => 'ring0000000000001', 'variant_key' => '', 'quantity' => 1]], 'coupon' => ''];
$form = ['email' => 'maria@example.com', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR', 'phone' => '+30 210 000'], 'payment' => 'cardlink'];
$placed = $checkout->place($form, $cart, null, 'el');
$order = $placed['order'];
parse_str((string)parse_url($placed['redirect'], PHP_URL_QUERY), $go);
check('the order waits; the customer goes to the go page', [$placed['ok'], $order['status'], (string)parse_url($placed['redirect'], PHP_URL_PATH)], [true, 'pending_payment', '/_shop/pay/cardlink/go']);
$payment = $payments->byReference('cardlink', (string)$go['ref']);
check('a payment is started under its own order id at Cardlink', [$payment['status'], $payment['amount_cents'], preg_match('/^[A-Za-z0-9]{4,50}$/', $payment['reference']), str_starts_with($payment['reference'], preg_replace('/[^A-Za-z0-9]/', '', $order['number']))], ['started', 12400, 1, true]);
check('the go page\'s address is signed for that payment only', [$cardlink->signed($go['ref'], $go['k']), $cardlink->signed($go['ref'], str_repeat('0', 32)), $cardlink->signed($go['ref'] . 'X', $go['k'])], [true, false, false]);
check('another attempt has another order id', Cardlink::reference($order['number']) !== $payment['reference'], true);
$pending = $cardlink->pending($go['ref'], $cs);
check('the go page: the order and the most installments for €124', [$pending['order']['id'], $pending['max_installments']], [$order['id'], 3]);

// ---- the form
$bank = $cardlink->form($pending['order'], $pending['payment'], $cs, 3);
check('posted to the acquirer\'s test host', $bank['action'], 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi');
check('the fields in the spec\'s order, the digest last', array_keys($bank['fields']), ['version', 'mid', 'lang', 'orderid', 'orderDesc', 'orderAmount', 'currency', 'payerEmail', 'payerPhone', 'billCountry', 'billZip', 'billCity', 'billAddress', 'shipCountry', 'shipZip', 'shipCity', 'shipAddress', 'trType', 'extInstallmentoffset', 'extInstallmentperiod', 'confirmUrl', 'cancelUrl', 'digest']);
check('what they say', [$bank['fields']['version'], $bank['fields']['mid'], $bank['fields']['lang'], $bank['fields']['orderAmount'], $bank['fields']['currency'], $bank['fields']['payerPhone'], $bank['fields']['trType'], $bank['fields']['extInstallmentperiod'], $bank['fields']['confirmUrl'], $bank['fields']['cancelUrl']], ['2', '0022000230', 'el', '124.00', 'EUR', '+30210000', '1', '3', 'https://shop.example/_shop/pay/cardlink/confirm', 'https://shop.example/_shop/pay/cardlink/cancel']);
$values = $bank['fields'];
unset($values['digest']);
check('the digest: base64 of sha256 over the values, then the secret', $bank['fields']['digest'], base64_encode(hash('sha256', implode('', $values) . 'Cardlink1', true)));
check('no installments: no installment fields', array_key_exists('extInstallmentperiod', $cardlink->form($pending['order'], $pending['payment'], $cs, 0)['fields']), false);
$shop->savePayments(['cardlink' => ['enabled' => '1', 'test' => '', 'acquirer' => 'nexi', 'merchant_id' => '0022000230']]);
check('live: the acquirer\'s own host', $cardlink->form($pending['order'], $pending['payment'], $shop->all()['payments']['cardlink'])['action'], 'https://www.alphaecommerce.gr/vpos/shophandlermpi');
$shop->savePayments(['cardlink' => ['enabled' => '1', 'test' => '1', 'acquirer' => 'worldline', 'merchant_id' => '0022000230', 'installments' => "100 = 3\n500 = 12"], 'bank_transfer' => ['enabled' => '1']]);
$cs = $shop->all()['payments']['cardlink'];

// ---- the answer
$sign = static function (array $fields, string $secret = 'Cardlink1'): array {
    $fields['digest'] = base64_encode(hash('sha256', implode('', $fields) . $secret, true));
    return $fields;
};
$answer = static fn(string $ref, string $status, string $amount = '124.00', string $mid = '0022000230'): array => ['version' => '2', 'mid' => $mid, 'orderid' => $ref, 'status' => $status, 'orderAmount' => $amount, 'currency' => 'EUR', 'paymentTotal' => $amount, 'message' => 'OK', 'riskScore' => '0', 'payMethod' => 'visa', 'txId' => '91000123', 'paymentRef' => '123456'];
$tx = $cardlink->answer($sign($answer($go['ref'], 'CAPTURED')), $cs);
check('a signed answer is believed', [$tx['ok'], $tx['paid'], $tx['amount_cents'], $tx['transaction_id'], $tx['reference']], [true, true, 12400, '91000123', $go['ref']]);
check('a wrong digest is not', $cardlink->answer($sign($answer($go['ref'], 'CAPTURED'), 'guess'), $cs)['ok'], false);
$moved = $sign($answer($go['ref'], 'CAPTURED'));
$moved = ['status' => $moved['status']] + $moved;
check('nor the same fields in another order', $cardlink->answer($moved, $cs)['ok'], false);
check('nor one for another merchant', $cardlink->answer($sign($answer($go['ref'], 'CAPTURED', '124.00', '999')), $cs)['ok'], false);
$bonus = $sign($answer($go['ref'], 'CAPTURED')) + ['xlsbonusadjamt' => '1.00', 'xlsbonustxid' => '7', 'xlsbonusstatus' => 'OK', 'xlsbonusdetails' => '', 'xlsbonusdigest' => 'nope'];
check('a loyalty bonus with a wrong digest of its own is not', $cardlink->answer($bonus, $cs)['ok'], false);
$bonus['xlsbonusdigest'] = base64_encode(hash('sha256', '1.007OK' . 'Cardlink1', true));
check('one with the right one is', $cardlink->answer($bonus, $cs)['ok'], true);

$wrongAmount = $cardlink->answer($sign($answer($go['ref'], 'CAPTURED', '1.00')), $cs);
check('a signed answer for another amount does not pay the order', [$checkout->confirm('cardlink', $wrongAmount, 'cardlink')['outcome'], $orders->get($order['id'])['status']], ['mismatch', 'pending_payment']);
$result = $checkout->confirm('cardlink', $tx, 'cardlink');
check('the right one pays it; its stock is taken', [$result['outcome'], $result['order']['status'], $result['order']['stock_held']], ['paid', 'paid', ShopCheckout::TAKEN]);
check('the same answer again changes nothing', $checkout->confirm('cardlink', $tx, 'cardlink')['outcome'], 'already');
check('the go page no longer offers to pay', $cardlink->pending($go['ref'], $cs), null);

// ---- a cancel
$second = $checkout->place($form, $cart, null, 'el');
parse_str((string)parse_url($second['redirect'], PHP_URL_QUERY), $go2);
$cancel = $cardlink->answer($sign($answer($go2['ref'], 'CANCELED')), $cs);
check('a cancel: the payment failed, the order still waits to be paid', [$checkout->confirm('cardlink', $cancel, 'cardlink')['outcome'], $payments->byReference('cardlink', $go2['ref'])['status'], $orders->get($second['order']['id'])['status']], ['failed', 'failed', 'pending_payment']);
$again = $checkout->payAgain($orders->get($second['order']['id']));
parse_str((string)parse_url($again['redirect'], PHP_URL_QUERY), $go3);
check('Pay again starts another attempt', [$again['ok'], $go3['ref'] !== $go2['ref'], $payments->byReference('cardlink', $go3['ref'])['status']], [true, true, 'started']);

// ---- the answer never came: asked about before the order is let go
$late = static function (int $id) use ($pdo): void { $pdo->exec('UPDATE shop_orders SET reserve_until = ' . (time() - 60) . ' WHERE id = ' . $id); };
$late($second['order']['id']);
$asked = '';
$fake['next'] = function (string $body) use (&$asked, $xmlAnswer, $go3): array {
    $asked = $body;
    return $xmlAnswer('<StatusResponse><TransactionDetails><OrderId>' . $go3['ref'] . '</OrderId><OrderAmount>124.00</OrderAmount><Currency>EUR</Currency><Status>CAPTURED</Status><TxId>91000999</TxId><PaymentRef>654321</PaymentRef></TransactionDetails></StatusResponse>');
};
$checkout->expire();
check('paid after all: the order is paid, not cancelled', [$orders->get($second['order']['id'])['status'], $payments->byReference('cardlink', $go3['ref'])['transaction_id']], ['paid', '91000999']);
check('it was asked with a StatusRequest for that order id', [str_contains($asked, '<StatusRequest><Authentication><Mid>0022000230</Mid></Authentication><TransactionInfo><OrderId>' . $go3['ref'] . '</OrderId>'), (string)parse_url($calls[array_key_last($calls)][1], PHP_URL_PATH)], [true, '/vpos/xmlpayvpos']);

$third = $checkout->place($form, $cart, null, 'el');
$late($third['order']['id']);
$fake['next'] = fn(string $body): array => $xmlAnswer('<ErrorMessage><ErrorCode>1</ErrorCode><Description>Order not found</Description></ErrorMessage>');
$checkout->expire();
check('never paid: the order is let go and its stock comes back', [$orders->get($third['order']['id'])['status'], $stock->forProduct('ring0000000000001')['']['reserved']], ['cancelled', 0]);

$fourth = $checkout->place($form, $cart, null, 'el');
$late($fourth['order']['id']);
$fake['next'] = null;
$checkout->expire();
check('the bank not reachable: the order waits to be asked again', $orders->get($fourth['order']['id'])['status'], 'pending_payment');
$calls = [];
$checkout->expire();
check('and is not asked again within ten minutes', $calls, []);
$pdo->exec('UPDATE shop_orders SET reserve_until = ' . (time() - 7 * 3600) . ' WHERE id = ' . (int)$fourth['order']['id']);
$pdo->exec("DELETE FROM shop_gateway_events WHERE kind = 'lookup'");
$checkout->expire();
check('six hours on, it is let go with a note to check the bank', [$orders->get($fourth['order']['id'])['status'], str_contains(json_encode($orders->events($fourth['order']['id'])), 'back office')], ['cancelled', true]);

// ---- refunds
$paid = $payments->paidFor($order['id']);
$sent = '';
$fake['next'] = function (string $body) use (&$sent, $xmlAnswer): array {
    $sent = $body;
    return $xmlAnswer('<RefundResponse><OrderId>x</OrderId><OrderAmount>20.00</OrderAmount><Status>CAPTURED</Status><TxId>91000124</TxId></RefundResponse>');
};
$refund = $cardlink->refund($cs, $paid, 2000);
check('a part refunded with a RefundRequest', [$refund, str_contains($sent, '<RefundRequest><Authentication><Mid>0022000230</Mid></Authentication><OrderInfo><OrderId>' . $paid['reference'] . '</OrderId><OrderAmount>20.00</OrderAmount><Currency>EUR</Currency></OrderInfo></RefundRequest>')], [['ok' => true, 'error' => '', 'transaction_id' => '91000124'], true]);
preg_match('#<Message ([^>]*)>(.*)</Message><Digest>([^<]+)</Digest>#s', $sent, $m);
check('the message\'s digest: over the Message element with its namespaces, then the secret', $m[3], base64_encode(hash('sha256', '<Message xmlns="http://www.modirum.com/schemas/vposxmlapi41" xmlns:ns2="http://www.w3.org/2000/09/xmldsig#" ' . $m[1] . '>' . $m[2] . '</Message>' . 'Cardlink1', true)));
$kinds = [];
$fake['next'] = function (string $body) use (&$kinds, $xmlAnswer): array {
    preg_match('#<(\w+Request)>#', $body, $k);
    $kinds[] = $k[1];
    return $k[1] === 'RefundRequest'
        ? $xmlAnswer('<RefundResponse><Status>ERROR</Status><Description>Transaction not settled</Description></RefundResponse>')
        : $xmlAnswer('<CancelResponse><Status>CANCELED</Status><TxId>91000125</TxId></CancelResponse>');
};
check('on the day of the payment, all of it: a void when a refund is not yet possible', [$cardlink->refund($cs, $paid, 12400)['ok'], $kinds], [true, ['RefundRequest', 'CancelRequest']]);
$kinds = [];
$part = $cardlink->refund($cs, $paid, 1000);
check('a part on that day is not voided: the refusal is said', [$part['ok'], $kinds, str_contains($part['error'], 'not settled')], [false, ['RefundRequest'], true]);

// ---- reading answers, Check connection
check('an answer read: the response\'s fields, TransactionDetails on its own', Cardlink::parse('<VPOS xmlns="http://www.modirum.com/schemas/vposxmlapi41"><Message><StatusResponse><TransactionDetails><Status>CAPTURED</Status></TransactionDetails></StatusResponse></Message></VPOS>'), ['TransactionDetails' => ['Status' => 'CAPTURED']]);
check('not XML', Cardlink::parse('<html>'), null);
$fake['next'] = fn(string $body): array => $xmlAnswer('<ErrorMessage><ErrorCode>1</ErrorCode><Description>Order not found</Description></ErrorMessage>');
check('Check connection: the right keys get "no such order"', $cardlink->check($cs)['ok'], true);
$fake['next'] = fn(string $body): array => $xmlAnswer('<ErrorMessage><ErrorCode>3</ErrorCode><Description>Invalid digest</Description></ErrorMessage>');
$wrong = $cardlink->check($cs);
check('a wrong secret is said so, without the secret', [$wrong['ok'], str_contains($wrong['message'], 'did not accept'), str_contains($wrong['message'], 'Cardlink1')], [false, true, false]);
$fake['next'] = null;
check('the bank not reachable', $cardlink->check($cs)['ok'], false);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
