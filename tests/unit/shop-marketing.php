<?php
/*
 * Shop settings › Marketing: the product feed for Meta Commerce Manager and Google Merchant Center (ids, variants as items of one
 * group, prices and "was" prices, availability, attributes, what is left out), the settings, the events the Meta Pixel is given,
 * and the purchase sent from the server (Conversions API): only with consent, hashed, once, with the page's event id.
 *   php tests/unit/shop-marketing.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, ContentTypes, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use GnozioCMS\Shop\{ShopAddress, ShopCatalogue, ShopCheckout, ShopCoupons, ShopFeed, ShopMarketing, ShopOrders, ShopPricing, ShopSecrets, ShopSettings, ShopStock};
use GnozioCMS\Shop\Payments\BankTransfer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = sys_get_temp_dir() . '/shopmk' . getmypid();
mkdir("$root/storage", 0775, true);
mkdir("$root/custom", 0775, true);
mkdir("$root/content/products", 0775, true);
mkdir("$root/content/taxonomies", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'content_types' => ['pages', 'products']];
$markdown = new MarkdownConverter((new Environment([]))->addExtension(new CommonMarkCoreExtension()));
$content = new ContentRepository("$root/content", $markdown, $settings, fn(): array => $types->catalogue());
$db = new SystemDatabase("$root/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$put = static function (string $file, string $front) use ($root): void {
    file_put_contents("$root/content/products/$file", "---\n$front\n---\n\nThe long text.\n");
};
$put('ring.md', "title: Δαχτυλίδι\nstatus: published\ntranslation_id: ring0000000000001\nmain_image: /uploads/ring.jpg\ncustom_fields:\n  price: '124'\n  compare_at_price: '150'\n  sku: RG-1\n  brand: Maria\n  track_stock: true\n  options:\n    - { name: Χρώμα, values: \"Gold\\nSilver\" }\n  variants:\n    - { values: Gold, price: '130', sku: RG-G, image: /uploads/gold.jpg }\n    - { values: Silver }");
$put('charm.md', "title: Charm\nstatus: published\ntranslation_id: charm000000000001\ncustom_fields:\n  price: '30'\n  short_description: A small charm.\n  barcode: '5201234567890'\n  options:\n    - { name: Colour, values: \"Red\\nBlue\" }");
$put('guide.md', "title: Guide\nstatus: published\ntranslation_id: guide000000000001\ncustom_fields:\n  price: '9'\n  kind: digital");
$put('draft.md', "title: Draft\nstatus: draft\ntranslation_id: draft000000000001\ncustom_fields:\n  price: '9'");

$stock = new ShopStock($db);
$stock->setOnHand('ring0000000000001', ['gold' => 2, 'silver' => 0], ['gold', 'silver']);
$shop = new ShopSettings($meta);
$secrets = new ShopSecrets($meta);
$taxonomies = new Taxonomies("$root/content", ['el', 'en']);
$catalogue = new ShopCatalogue($content, fn() => $taxonomies, fn() => $types, $stock, fn() => 'el');
$url = fn(ContentItem $p): string => '/products/' . $p->slug;
$pricing = new ShopPricing($catalogue, $stock, $shop, $url);
$orders = new ShopOrders($db);

// ---- settings
check('off and empty to start with', $shop->all()['marketing'], ['feed' => false, 'brand' => '', 'category' => '', 'out_of_stock' => true, 'pixel_id' => '', 'consent' => 'banner', 'privacy_page' => '', 'test_code' => '']);
$shop->saveGeneral(['store_name' => 'Aleon', 'store_email' => 'shop@example.com', 'order_prefix' => 'AL', 'guest_checkout' => '1']);
$shop->saveMarketing(['marketing' => ['feed' => '1', 'category' => '188', 'pixel_id' => ' 1234 5678 9012 ', 'consent' => 'nope', 'test_code' => 'TEST42', 'privacy_page' => '../x']]);
$m = $shop->all()['marketing'];
check('kept cleaned: a Pixel ID of digits, a known consent, a real page, a test code; an unticked box is off', [$m['feed'], $m['pixel_id'], $m['consent'], $m['privacy_page'], $m['test_code'], $m['out_of_stock']], [true, '123456789012', 'banner', '', 'TEST42', false]);
$shop->saveMarketing(['marketing' => ['feed' => '1', 'category' => '188', 'pixel_id' => '123456789012', 'test_code' => '', 'out_of_stock' => '1']]);
check('the General tab keeps it', [$shop->saveGeneral(['store_name' => 'Aleon', 'guest_checkout' => '1']), $shop->all()['marketing']['pixel_id']], [null, '123456789012']);

// ---- ids
check('a product is its id; a variant its id and a short mark of it', [ShopMarketing::contentId('ring0000000000001'), ShopMarketing::contentId('ring0000000000001', 'gold')], ['ring0000000000001', 'ring0000000000001-' . substr(hash('sha256', 'gold'), 0, 8)]);
check('only real variants count as variants', [ShopMarketing::hasVariants(['variants' => [['values' => ' ']]]), ShopMarketing::hasVariants(['variants' => [['values' => 'Gold']]])], [false, true]);

// ---- the feed
$feed = new ShopFeed($catalogue, $shop, fn(string $lang): array => $content->getItems('products', $lang, false, false), $url, 'https://shop.example', 'Site');
$items = $feed->items('el');
$byId = array_column($items, null, 'id');
$gold = ShopMarketing::contentId('ring0000000000001', 'gold');
$silver = ShopMarketing::contentId('ring0000000000001', 'silver');
$ids = array_keys($byId);
sort($ids);
$want = [$gold, $silver, 'charm000000000001'];
sort($want);
check('physical products only: each variant, and the product with only options as one', $ids, $want);
check('a variant: its group, title, own price under the "was" price, picture, sku and colour', [$byId[$gold]['item_group_id'], $byId[$gold]['title'], $byId[$gold]['price'], $byId[$gold]['sale_price'], $byId[$gold]['image_link'], $byId[$gold]['mpn'], $byId[$gold]['color']], ['ring0000000000001', 'Δαχτυλίδι - Gold', '150.00 EUR', '130.00 EUR', 'https://shop.example/uploads/gold.jpg', 'RG-G', 'Gold']);
check('a variant with none left is out of stock; the other in stock', [$byId[$silver]['availability'], $byId[$gold]['availability']], ['out of stock', 'in stock']);
check('brand, condition, category, link', [$byId[$gold]['brand'], $byId[$gold]['condition'], $byId[$gold]['google_product_category'], $byId[$gold]['link']], ['Maria', 'new', '188', 'https://shop.example/products/ring']);
check('a product of its own: the store as brand, its barcode, short description and price', [$byId['charm000000000001']['brand'], $byId['charm000000000001']['gtin'], $byId['charm000000000001']['description'], $byId['charm000000000001']['price'], isset($byId['charm000000000001']['sale_price'])], ['Aleon', '5201234567890', 'A small charm.', '30.00 EUR', false]);
$xml = $feed->xml('el');
$doc = simplexml_load_string($xml);
check('Google\'s RSS, which Meta reads too', [(string)$doc['version'], count($doc->channel->item), in_array((string)$doc->channel->item[0]->children('http://base.google.com/ns/1.0')->id, $want, true)], ['2.0', 3, true]);
$shop->saveMarketing(['marketing' => ['feed' => '1', 'pixel_id' => '123456789012']]);
check('out of stock left out when asked', count(simplexml_load_string($feed->xml('el'))->channel->item), 2);

// ---- events from the pages
$lines = $pricing->lines([['product_id' => 'ring0000000000001', 'variant_key' => 'gold', 'quantity' => 2], ['product_id' => 'charm000000000001', 'variant_key' => 'red', 'quantity' => 1]], 'el')['lines'];
check('priced lines carry the feed\'s ids', array_column($lines, 'content_id'), [$gold, 'charm000000000001']);
$event = ShopMarketing::event('AddToCart', $lines, 29000, 'EUR');
check('an event: ids, contents, items, value', [$event['data']['content_ids'], $event['data']['contents'][0], $event['data']['num_items'], $event['data']['value']], [[$gold, 'charm000000000001'], ['id' => $gold, 'quantity' => 2, 'item_price' => 130.0], 3, 290.0]);

// ---- the purchase from the server
$sent = [];
$answer = ['status' => 200, 'body' => '{"events_received":1}', 'error' => ''];
$transport = function (string $method, string $to, array $headers, ?string $body, int $timeout) use (&$sent, &$answer): array {
    $sent[] = ['url' => $to, 'body' => json_decode((string)$body, true)];
    return $answer;
};
$marketing = new ShopMarketing($shop, $secrets, $orders, $db, fn(string $id, string $lang) => $catalogue->product($id, $lang), 'el', $transport);
$shop->saveShipping(['zones' => [['name' => 'Greece', 'countries' => 'GR', 'methods' => [['kind' => 'flat', 'label' => ['el' => 'Courier'], 'cost' => '5']]]]], ['el', 'en']);
$shop->savePayments(['bank_transfer' => ['enabled' => '1', 'accounts' => 'GR16 0110 1250 0000 0001 2300 695']]);
$checkout = new ShopCheckout($pricing, new ShopCoupons($db), $orders, $stock, $shop, ['bank_transfer' => new BankTransfer()], fn(string $k, string $l): string => $k, 'el', null, null, null, fn(int $id) => $marketing->purchase($id));
$address = ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '105 63', 'country' => 'GR', 'phone' => '6900000000'];
$cart = ['lines' => [['product_id' => 'charm000000000001', 'variant_key' => 'red', 'quantity' => 1]], 'coupon' => ''];
$quote = $checkout->quote($cart['lines'], '', ShopAddress::clean($address), '', '', 'el');
$form = ['email' => ' Maria@Example.com ', 'billing' => $address, 'method' => $quote['methods'][0]['id'] ?? '', 'payment' => 'bank_transfer', 'terms' => '1'];
$cookies = ['gz_consent' => 'granted', '_fbp' => 'fb.1.1700000000000.123456789'];
$server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Test browser'];
$count = static fn(): int => (int)$db->connection()->query('SELECT COUNT(*) FROM shop_order_tracking')->fetchColumn();

$placed = $checkout->place($form, $cart, null, 'el');
check('an order is placed', $placed['errors'], []);
$first = $placed['order'];
$marketing->remember((int)$first['id'], $server, $cookies, 'https://shop.example/checkout');
check('without an access token nothing is kept', $count(), 0);
$secrets->save('meta', ['capi_token' => 'EAAtoken1234']);
$marketing->remember((int)$first['id'], $server, ['gz_consent' => 'denied'] + $cookies, 'https://shop.example/checkout');
check('nor for a visitor who did not agree', $count(), 0);
$marketing->remember((int)$first['id'], $server, $cookies, 'https://shop.example/checkout');
check('kept for one who did', $count(), 1);
$checkout->changeStatus((int)$first['id'], 'paid', 'admin');
check('paid: sent once to the Pixel\'s events, and forgotten', [count($sent), $sent[0]['url'] ?? '', $count()], [1, ShopMarketing::GRAPH . '123456789012/events', 0]);
$e = $sent[0]['body']['data'][0];
check('a Purchase with the page\'s event id, value and contents', [$e['event_name'], $e['event_id'], (float)$e['custom_data']['value'], $e['custom_data']['content_ids'], $e['custom_data']['order_id']], ['Purchase', 'purchase.' . $first['number'], (float)($first['total_cents'] / 100), ['charm000000000001'], $first['number']]);
check('who bought, hashed as Meta asks', [$e['user_data']['em'][0], $e['user_data']['ph'][0], $e['user_data']['zp'][0], $e['user_data']['country'][0], $e['user_data']['fn'][0]], [hash('sha256', 'maria@example.com'), hash('sha256', '306900000000'), hash('sha256', '10563'), hash('sha256', 'gr'), hash('sha256', 'maria')]);
check('and the browser, the token in the body, not in the log', [$e['user_data']['client_ip_address'], $e['user_data']['client_user_agent'], $e['user_data']['fbp'], $sent[0]['body']['access_token'], isset($sent[0]['body']['test_event_code'])], ['203.0.113.9', 'Test browser', 'fb.1.1700000000000.123456789', 'EAAtoken1234', false]);
check('the order\'s timeline says so', str_contains(json_encode($orders->events((int)$first['id']), JSON_UNESCAPED_UNICODE), 'sent to Meta'), true);
$checkout->changeStatus((int)$first['id'], 'processing', 'admin');
$checkout->changeStatus((int)$first['id'], 'paid', 'admin');
check('paid again: not sent twice', count($sent), 1);

$second = $checkout->place($form, $cart, null, 'el')['order'];
$checkout->changeStatus((int)$second['id'], 'paid', 'admin');
check('an order with nothing kept (no consent) is not sent', count($sent), 1);

$third = $checkout->place($form, $cart, null, 'el')['order'];
$marketing->remember((int)$third['id'], $server, $cookies, 'https://shop.example/checkout');
$answer = ['status' => 400, 'body' => '{"error":{"message":"Invalid parameter"}}', 'error' => ''];
$checkout->changeStatus((int)$third['id'], 'paid', 'admin');
check('a refusal is written on the order and the order is paid all the same', [$orders->get((int)$third['id'])['status'], str_contains(json_encode($orders->events((int)$third['id']), JSON_UNESCAPED_UNICODE), 'Invalid parameter')], ['paid', true]);

// ---- the test event
$answer = ['status' => 200, 'body' => '{"events_received":1}', 'error' => ''];
check('a test needs the test code', $marketing->test()['ok'], false);
$shop->saveMarketing(['marketing' => ['feed' => '1', 'pixel_id' => '123456789012', 'test_code' => 'TEST42']]);
$test = $marketing->test();
check('then it goes with it', [$test['ok'], end($sent)['body']['test_event_code'], end($sent)['body']['data'][0]['event_name']], [true, 'TEST42', 'PageView']);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
