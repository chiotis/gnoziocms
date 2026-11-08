<?php
/*
 * The shop's cart and checkout (docs/shop-plan.md, phase 3): money in cents and VAT in prices, carts, pricing read again from the
 * products, shipping zones and methods, coupons, placing an order (stock held and taken, the coupon's use, the order's number),
 * status changes with what they do to the stock, and customers' accounts.
 *   php tests/unit/shop-checkout.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, ContentTypes, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use GnozioCMS\Shop\{ShopAddress, ShopCart, ShopCatalogue, ShopCheckout, ShopCoupons, ShopCustomers, ShopMoney, ShopOrders, ShopPricing, ShopSettings, ShopShipping, ShopStock};
use GnozioCMS\Shop\Payments\{BankTransfer, CashOnDelivery};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = sys_get_temp_dir() . '/shopco' . getmypid();
mkdir("$root/storage", 0775, true);
mkdir("$root/custom", 0775, true);
mkdir("$root/content/products", 0775, true);
mkdir("$root/content/taxonomies", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'content_types' => ['pages', 'products', 'looks']];
$markdown = new MarkdownConverter((new Environment([]))->addExtension(new CommonMarkCoreExtension()));
$content = new ContentRepository("$root/content", $markdown, $settings, fn(): array => $types->catalogue());
$db = new SystemDatabase("$root/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$put = static function (string $file, string $front) use ($root): void {
    file_put_contents("$root/content/products/$file", "---\n$front\n---\n\nText\n");
};
$put('ring.md', "title: Δαχτυλίδι\nstatus: published\ntranslation_id: ring0000000000001\ncollections: [balos]\ncustom_fields:\n  price: '124'\n  tax_class: standard\n  weight_g: 40\n  gift_wrap: true\n  track_stock: true\n  options:\n    - { name: Finish, values: \"Gold\\nSilver\" }\n  option_labels: \"Gold = Χρυσό\"\n  variants:\n    - { values: Gold, price: '186', sku: R-G }\n    - { values: Silver }");
$put('ring.en.md', "title: Ring\nstatus: published\ntranslation_id: ring0000000000001\ncollections: [balos]\ncustom_fields:\n  price: '124'\n  track_stock: true\n  variants:\n    - { values: Gold, price: '186', sku: R-G }\n    - { values: Silver }");
$put('book.md', "title: Βιβλίο\nstatus: published\ntranslation_id: book0000000000001\ncustom_fields:\n  price: '20'\n  tax_class: super_reduced\n  weight_g: 500");
$put('ebook.md', "title: Rental\nstatus: published\ntranslation_id: ebook000000000001\ncustom_fields:\n  price: '9'\n  kind: rental");
$put('draft.md', "title: Draft\nstatus: draft\ntranslation_id: draft000000000001\ncustom_fields:\n  price: '9'");

$stock = new ShopStock($db);
$stock->setOnHand('ring0000000000001', ['gold' => 2, 'silver' => 5], ['gold', 'silver']);
$shop = new ShopSettings($meta);
$taxonomies = new Taxonomies("$root/content", ["el", "en"]);
$catalogue = new ShopCatalogue($content, fn() => $taxonomies, fn() => $types, $stock, fn() => 'el');
$pricing = new ShopPricing($catalogue, $stock, $shop, fn(ContentItem $p): string => '/products/' . $p->slug);
$coupons = new ShopCoupons($db);
$orders = new ShopOrders($db);
$checkout = new ShopCheckout($pricing, $coupons, $orders, $stock, $shop, ['bank_transfer' => new BankTransfer(), 'cod' => new CashOnDelivery()], fn(string $k, string $l): string => $k, 'el');

// ---- money
check('prices as cents, with a comma or a dot', [ShopMoney::cents('145,50'), ShopMoney::cents(19.99), ShopMoney::cents('x'), ShopMoney::cents(-1)], [14550, 1999, null, null]);
check('written in each language', [ShopMoney::format(14550, 'el'), ShopMoney::format(14550, 'en'), ShopMoney::format(14500, 'el'), ShopMoney::format(-500, 'en')], ['€145,50', '€145.50', '€145', '−€5']);
check('the VAT in a price that has it in', [ShopMoney::vatIn(12400, 24), ShopMoney::vatIn(2000, 6), ShopMoney::vatIn(1000, 0)], [2400, 113, 0]);
check('a discount spread over lines adds up exactly', [ShopMoney::spread(1000, [3 => 3000, 5 => 0, 7 => 6000]), array_sum(ShopMoney::spread(1001, [1, 1, 1]))], [[3 => 333, 5 => 0, 7 => 667], 1001]);

// ---- carts
$carts = new ShopCart($db);
$token = ShopCart::newToken();
$lines = ShopCart::add([], 'ring0000000000001', 'gold', 1);
$lines = ShopCart::add($lines, 'ring0000000000001', 'gold', 2);
$lines = ShopCart::add($lines, 'book0000000000001', '', 1);
check('adding the same piece again adds to its quantity', [count($lines), $lines[0]['quantity'], ShopCart::count($lines)], [2, 3, 4]);
$carts->save($token, $lines, 'SPRING');
check('a cart is kept by its cookie', $carts->load($token), ['lines' => $lines, 'coupon' => 'SPRING', 'customer_id' => null]);
check('a cookie that is not one finds nothing', [$carts->load('nope')['lines'], ShopCart::validToken('../x'), ShopCart::validToken($token)], [[], false, true]);
check('a line set to 0 leaves the cart', count(ShopCart::set($lines, ShopCart::key('book0000000000001', ''), 0)), 1);

// ---- pricing
$priced = $pricing->lines([
    ['product_id' => 'ring0000000000001', 'variant_key' => 'gold', 'quantity' => 3],
    ['product_id' => 'book0000000000001', 'variant_key' => '', 'quantity' => 2],
    ['product_id' => 'ebook000000000001', 'variant_key' => '', 'quantity' => 1],
    ['product_id' => 'draft000000000001', 'variant_key' => '', 'quantity' => 1],
    ['product_id' => 'ring0000000000001', 'variant_key' => 'bronze', 'quantity' => 1],
], 'el');
check('prices are read again: the variant\'s own, the product\'s', array_column($priced['lines'], 'unit_cents'), [18600, 2000]);
check('more than is in stock is lowered to it', [$priced['lines'][0]['quantity'], $priced['lines'][0]['max']], [2, 2]);
check('a variant\'s name in the language, its SKU', [$priced['lines'][0]['variant'], $priced['lines'][0]['sku']], ['Χρυσό', 'R-G']);
check('digital pieces, drafts and missing variants leave the cart, and say why', array_column($priced['changes'], 'reason'), ['reduced', 'not_sold', 'gone', 'gone']);
check('the totals of the lines, the weight', [$priced['items_cents'], $priced['grams'], $priced['count']], [41200, 1080, 4]);
$totals = $pricing->totals($priced['lines'], null, 500, 0);
check('VAT per class, and the standard rate on shipping', [$totals['line_tax'], $totals['tax'], $totals['total']], [[7200, 226], 7200 + 226 + 97, 41700]);

// ---- shipping
$zones = ShopShipping::normalize([
    ['name' => 'Islands', 'countries' => 'GR', 'postcodes' => '84, 85', 'methods' => [['kind' => 'flat', 'label' => ['el' => 'Courier νησιά'], 'cost' => '8']]],
    ['name' => 'Greece', 'countries' => 'gr, cy', 'methods' => [
        ['kind' => 'flat', 'label' => ['el' => 'Courier', 'en' => 'Courier'], 'cost' => '4,50', 'free_over' => '100'],
        ['kind' => 'pickup', 'label' => ['el' => 'Παραλαβή'], 'cost' => '', 'stores' => "Athens\nGlyfada"],
    ]],
    ['name' => 'World', 'countries' => '*', 'methods' => [['kind' => 'weight', 'table' => "500 = 10\n2000=18"], ['kind' => 'free', 'free_over' => '300']]],
    ['name' => '', 'countries' => ''],
], ['el', 'en']);
check('zones keep their countries in capitals; an empty one is dropped', [count($zones), $zones[1]['countries'], $zones[0]['postcodes']], [3, ['GR', 'CY'], ['84', '85']]);
check('the first zone that matches: islands by postcode, the mainland, the world', [ShopShipping::zoneFor($zones, 'GR', '84100')['name'], ShopShipping::zoneFor($zones, 'gr', '10558')['name'], ShopShipping::zoneFor($zones, 'DE', '')['name']], ['Islands', 'Greece', 'World']);
$t = fn(string $k): string => $k;
check('a flat rate free over its amount, and pickup', [array_column(ShopShipping::methods($zones[1], 5000, 0, 'el', 'el', $t), 'cents'), array_column(ShopShipping::methods($zones[1], 15000, 0, 'el', 'el', $t), 'cents')], [[450, 0], [0, 0]]);
check('by weight, heavier than the table is not offered; free over an amount', [array_column(ShopShipping::methods($zones[2], 5000, 400, 'en', 'el', $t), 'cents'), array_column(ShopShipping::methods($zones[2], 40000, 3000, 'en', 'el', $t), 'cents')], [[1000], [0]]);
check('a method without a label is named by its kind', ShopShipping::methods($zones[2], 5000, 400, 'en', 'el', $t)[0]['label'], 'shop.ship_weight');
check('the countries of the checkout: every one when a zone is everywhere', [ShopShipping::countries(array_slice($zones, 0, 2)), count(ShopShipping::countries($zones)) > 30], [['GR', 'CY'], true]);
check('the weight table back as text', ShopShipping::tableText($zones[2]['methods'][0]['table']), "500 = 10.00\n2000 = 18.00");

// ---- settings
$shop->saveGeneral(['store_name' => 'Aleon', 'store_email' => 'shop@example.com', 'order_prefix' => 'al', 'terms_page' => 'terms', 'guest_checkout' => '1']);
$shop->saveShipping(['zones' => [
    ['name' => 'Greece', 'countries' => 'GR', 'methods' => [['kind' => 'flat', 'label' => ['el' => 'Courier'], 'cost' => '5', 'free_over' => '200'], ['kind' => 'pickup', 'label' => ['el' => 'Παραλαβή'], 'stores' => "Athens\nGlyfada"]]],
]], ['el', 'en']);
$zoneId = $shop->all()['shipping'][0]['id'];
$shop->savePayments(['bank_transfer' => ['enabled' => '1', 'accounts' => "Eurobank GR16 0110 1250 0000 0001 2300 695"], 'cod' => ['enabled' => '1', 'fee' => '2', 'zones' => [$zoneId, 'nope']]]);
$all = $shop->all();
check('the payments keep the accounts, the fee and real zones only', [$all['payments']['bank_transfer']['enabled'], $all['payments']['cod']['fee'], $all['payments']['cod']['zones']], [true, 200, [$zoneId]]);
check('the General tab keeps the shipping that was set', [count($all['shipping']), $all['order_prefix'], $all['accounts']], [1, 'AL', ['signup' => true, 'verify' => false]]);
$shop->saveGeneral(['store_name' => 'Aleon', 'store_email' => 'shop@example.com', 'order_prefix' => 'AL', 'terms_page' => 'terms', 'guest_checkout' => '1']);
check('and saving it again does too', count($shop->all()['shipping']), 1);

// ---- coupons
check('a coupon needs a code and a sensible amount', [$coupons->save(['code' => '', 'kind' => 'percent', 'amount' => 10]) !== '', $coupons->save(['code' => 'x', 'kind' => 'percent', 'amount' => 120]) !== ''], [true, true]);
check('one is saved', [$coupons->save(['code' => 'spring-10', 'kind' => 'percent', 'amount' => '10', 'active' => '1', 'usage_limit' => 1]), $coupons->find('Spring-10')['code']], ['', 'SPRING-10']);
$coupons->save(['code' => 'BALOS', 'kind' => 'fixed', 'amount' => '50', 'active' => '1', 'collections' => ['balos'], 'min' => '100']);
$coupons->save(['code' => 'SHIPFREE', 'kind' => 'free_shipping', 'active' => '1', 'ends_at' => '2020-01-01']);
check('a code that is there twice is refused', $coupons->save(['code' => 'balos', 'kind' => 'fixed', 'amount' => '5']) !== '', true);
$two = $pricing->lines([['product_id' => 'ring0000000000001', 'variant_key' => 'silver', 'quantity' => 1], ['product_id' => 'book0000000000001', 'variant_key' => '', 'quantity' => 1]], 'el')['lines'];
$balos = $coupons->apply('balos', $two);
check('an amount off only the collection\'s pieces', [$balos['ok'], $balos['discount'], $balos['per_line']], [true, 5000, [0 => 5000, 1 => 0]]);
check('a percentage off everything', $coupons->apply('SPRING-10', $two)['per_line'], [0 => 1240, 1 => 200]);
check('expired, unknown, and under the minimum', [$coupons->apply('SHIPFREE', $two)['error'], $coupons->apply('NOPE', $two)['error'], $coupons->apply('BALOS', array_slice($two, 1))['error']], ['expired', 'unknown', 'not_for_these']);
$totals = $pricing->totals($two, $balos, 500);
check('VAT is of what is paid after the discount', [$totals['line_tax'][0], $totals['total']], [ShopMoney::vatIn(12400 - 5000, 24), 12400 + 2000 - 5000 + 500]);
check('the last use is taken once', [$coupons->take('SPRING-10'), $coupons->take('SPRING-10'), $coupons->apply('SPRING-10', $two)['error']], [true, false, 'used_up']);
$coupons->giveBack('SPRING-10');

// ---- placing an order
$cart = ['lines' => [['product_id' => 'ring0000000000001', 'variant_key' => 'gold', 'quantity' => 2], ['product_id' => 'book0000000000001', 'variant_key' => '', 'quantity' => 1]], 'coupon' => ''];
$address = ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'gr', 'phone' => '210 000'];
$form = ['email' => 'maria@example.com', 'billing' => $address, 'method' => '', 'payment' => 'bank_transfer', 'terms' => '1'];
$quote = $checkout->quote($cart['lines'], '', ShopAddress::clean($address), '', '', 'el');
check('the quote: zone, methods, payments with the fee', [$quote['zone']['name'], array_column($quote['methods'], 'cents'), array_column($quote['payments'], 'fee'), $quote['totals']['total']], ['Greece', [0, 0], [0, 200], 37200 + 2000]);
$form['method'] = $quote['methods'][0]['id'];
check('an order without terms, an address or a way to pay is not placed', $checkout->place(['terms' => '', 'payment' => 'x', 'billing' => ['first_name' => 'M']] + $form, $cart, null, 'el')['errors'], ['billing_last_name', 'billing_address', 'billing_city', 'billing_postcode', 'billing_country', 'billing_phone', 'no_shipping', 'payment', 'terms']);
check('a cart that changed is shown again first', $checkout->place($form, ['lines' => [['product_id' => 'ring0000000000001', 'variant_key' => 'gold', 'quantity' => 5]], 'coupon' => ''], null, 'el')['errors'], ['cart_changed']);
check('nothing was held for them', $stock->forProduct('ring0000000000001')['gold'], ['on_hand' => 2, 'reserved' => 0]);
$placed = $checkout->place($form, $cart, null, 'el');
$order = $placed['order'];
check('the order is placed with its number', [$placed['ok'], $order['number'], $order['status'], $order['email']], [true, 'AL-' . date('Y') . '-00001', 'on_hold', 'maria@example.com']);
check('its totals and VAT', [$order['items_cents'], $order['shipping_cents'], $order['total_cents'], $order['tax_cents']], [39200, 0, 39200, ShopMoney::vatIn(37200, 24) + ShopMoney::vatIn(2000, 6)]);
check('a transfer has taken the stock', [$stock->forProduct('ring0000000000001')['gold'], $order['stock_held']], [['on_hand' => 0, 'reserved' => 0], ShopCheckout::TAKEN]);
check('its lines keep what was sold', array_map(static fn(array $l): array => [$l['title'], $l['variant'], $l['quantity'], $l['unit_cents'], $l['tax_rate']], $orders->lines($order['id'])), [['Δαχτυλίδι', 'Χρυσό', 2, 18600, 24.0], ['Βιβλίο', '', 1, 2000, 6.0]]);
check('the next order sees none left', $checkout->place($form, $cart, null, 'el')['errors'], ['cart_changed']);
$second = $checkout->place(['payment' => 'cod', 'coupon' => 'SPRING-10'] + $form, ['lines' => [['product_id' => 'book0000000000001', 'variant_key' => '', 'quantity' => 2]], 'coupon' => ''], null, 'el');
check('cash on delivery: processing, its fee, the coupon counted', [$second['order']['status'], $second['order']['fee_cents'], $second['order']['discount_cents'], $coupons->find('SPRING-10')['used']], ['processing', 200, 400, 1]);
check('numbers follow each other', $second['order']['number'], 'AL-' . date('Y') . '-00002');
check('a guest cannot order when the shop wants accounts', (function () use ($shop, $checkout, $form, $cart) {
    $shop->saveGeneral(['store_name' => 'Aleon', 'order_prefix' => 'AL', 'terms_page' => 'terms']);
    $errors = $checkout->place($form, $cart, null, 'el')['errors'];
    $shop->saveGeneral(['store_name' => 'Aleon', 'store_email' => 'shop@example.com', 'order_prefix' => 'AL', 'terms_page' => 'terms', 'guest_checkout' => '1']);
    return in_array('account_needed', $errors, true);
})(), true);

// ---- status changes
check('an order on hold can be paid, not shipped back to waiting', [$checkout->changeStatus($order['id'], 'pending_payment', 'admin')['ok'], $checkout->changeStatus($order['id'], 'paid', 'admin')['ok']], [false, true]);
check('paid sets the time it was paid', $orders->get($order['id'])['paid_at'] !== null, true);
check('the timeline has each step, newest first', array_column($orders->events($order['id']), 'kind'), ['status', 'status', 'placed']);
$checkout->changeStatus($second['order']['id'], 'cancelled', 'admin');
check('cancelling gives back its stock and its coupon\'s use', [$coupons->find('SPRING-10')['used'], $orders->get($second['order']['id'])['stock_held']], [0, 0]);
$checkout->changeStatus($order['id'], 'cancelled', 'admin');
check('and the taken pieces come back on hand', $stock->forProduct('ring0000000000001')['gold'], ['on_hand' => 2, 'reserved' => 0]);
check('a cancelled order changes no more', $checkout->changeStatus($order['id'], 'paid', 'admin')['ok'], false);
check('the list finds orders by number, email and name, and counts them by status', [$orders->search(['q' => 'Patera'])['total'], $orders->search(['status' => 'cancelled'])['total'], $orders->counts()['cancelled']], [2, 2, 2]);

// ---- an order waiting for an online payment, whose time runs out
$held = $orders->create(['status' => 'pending_payment', 'email' => 'x@example.com', 'stock_held' => ShopCheckout::HELD, 'reserve_until' => time() - 5], [['product_id' => 'ring0000000000001', 'variant_key' => 'silver', 'title' => 'Ring', 'variant' => 'Silver', 'sku' => '', 'quantity' => 2, 'unit_cents' => 100, 'total_cents' => 200, 'tax_rate' => 24, 'tracked' => true]], 'AL');
$stock->reserve('ring0000000000001', 'silver', 2);
check('its pieces are held', $stock->forProduct('ring0000000000001')['silver'], ['on_hand' => 5, 'reserved' => 2]);
check('the time ran out: cancelled, and the pieces are free again', [$checkout->expire(), $orders->get($held['id'])['status'], $stock->forProduct('ring0000000000001')['silver']], [1, 'cancelled', ['on_hand' => 5, 'reserved' => 0]]);

// ---- customers
$customers = new ShopCustomers($db);
$id = $customers->create('Maria@Example.com', 'long enough', 'Maria', 'el');
check('a customer with an email and a password of 8 or more', [is_int($id), $customers->create('maria@example.com', 'long enough', '', 'el'), $customers->create('bad', 'long enough', '', 'el'), $customers->create('b@example.com', 'short', '', 'el')], [true, 'taken', 'email', 'password']);
check('signing in, in any case of the address; a wrong password signs in nobody', [$customers->authenticate('maria@example.com', 'long enough')['id'] ?? null, $customers->authenticate('maria@example.com', 'wrong'), $customers->authenticate('who@example.com', 'long enough')], [$id, null, null]);
$tokenR = $customers->issueToken($id, 'reset');
check('a link\'s token finds its customer, only for its purpose', [$customers->byToken($tokenR, 'reset')['id'] ?? null, $customers->byToken($tokenR, 'verify'), $customers->byToken('abc', 'reset')], [$id, null, null]);
$customers->setPassword($id, 'a new password');
check('a new password, and the link is used up', [$customers->byToken($tokenR, 'reset'), $customers->authenticate('maria@example.com', 'a new password')['id'] ?? null], [null, $id]);
check('guest orders with the address become the customer\'s', [$orders->claim('maria@example.com', $id), count($orders->forCustomer($id))], [2, 2]);
$customers->updateProfile($id, 'Maria P', '210', ['first_name' => 'Maria', 'country' => 'gr', 'street' => 'x']);
check('the profile keeps an address of known fields', [$customers->get($id)['name'], $customers->get($id)['address']['country'], array_key_exists('street', $customers->get($id)['address'])], ['Maria P', 'GR', false]);
check('the customers\' list counts orders', $customers->search('maria')['rows'][0]['orders'], 2);
$orders->unlink($id);
$customers->delete($id);
check('a deleted account leaves its orders, linked to nobody', [$customers->get($id), $orders->get($order['id'])['customer_id'], $orders->get($order['id'])['number']], [null, null, $order['number']]);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
