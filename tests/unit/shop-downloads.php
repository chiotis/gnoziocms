<?php
/*
 * Digital products (docs/shop-plan.md, phase 6): a product's private files kept under storage/shop-files, a cart of downloads only
 * (no shipping, no cash on delivery), a mixed cart, downloads given when the order is paid and not before, the signed link, the
 * count and the days, two at once not passing the limit, a cancel or refund turning them off, paying again bringing them back,
 * Allow again from the start, the paid email's links, file names, and a file removed.
 *   php tests/unit/shop-downloads.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, ContentTypes, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use GnozioCMS\Shop\{ShopCatalogue, ShopCheckout, ShopCoupons, ShopDownloads, ShopMail, ShopOrders, ShopPricing, ShopSettings, ShopStock};
use GnozioCMS\Shop\Payments\{BankTransfer, CashOnDelivery};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$root = sys_get_temp_dir() . '/shopdl' . getmypid();
mkdir("$root/storage", 0775, true);
mkdir("$root/custom", 0775, true);
mkdir("$root/content/products", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$put = static function (string $file, string $front) use ($root): void {
    file_put_contents("$root/content/products/$file", "---\n$front\n---\n\nText\n");
};
$put('ebook.md', "title: Βιβλίο\nstatus: published\ntranslation_id: ebook000000000001\ncustom_fields:\n  price: '9'\n  kind: digital\n  download_limit: 2\n  download_days: 10");
$put('font.md', "title: Γραμματοσειρά\nstatus: published\ntranslation_id: font0000000000001\ncustom_fields:\n  price: '30'\n  kind: digital\n  download_limit: 0\n  download_days: 0");
$put('ring.md', "title: Δαχτυλίδι\nstatus: published\ntranslation_id: ring0000000000001\ncustom_fields:\n  price: '124'\n  weight_g: 40\n  gift_wrap: true");

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
$orders = new ShopOrders($db);
$downloads = new ShopDownloads($db, $meta, "$root/storage/shop-files", 'https://shop.example', function (string $id) use ($catalogue): array {
    $custom = $catalogue->product($id, 'el')?->meta['custom_fields'] ?? [];
    return ['limit' => (int)($custom['download_limit'] ?? 5), 'days' => (int)($custom['download_days'] ?? 30)];
});
$checkout = new ShopCheckout($pricing, new ShopCoupons($db), $orders, $stock, $shop, ['bank_transfer' => new BankTransfer(), 'cod' => new CashOnDelivery()], fn(string $k, string $l): string => $k, 'el', null, $downloads);
$shop->savePayments(['bank_transfer' => ['enabled' => '1', 'accounts' => 'Eurobank GR16'], 'cod' => ['enabled' => '1']]);
$shop->saveShipping(['zones' => [['name' => 'Greece', 'countries' => 'GR', 'methods' => [['kind' => 'flat', 'label' => ['el' => 'Courier'], 'cost' => '4']]]]], ['el']);

// ---- files
$tmp = "$root/upload.tmp";
file_put_contents($tmp, str_repeat('PDF', 1000));
check('a file kept for a product, apart from the site', [$downloads->add('ebook000000000001', $tmp, '../../The Book (2026).pdf', false)['ok'], $downloads->files('ebook000000000001')[0]['name'], $downloads->files('ebook000000000001')[0]['size']], [true, 'The Book (2026).pdf', 3000]);
$kept = $downloads->files('ebook000000000001')[0];
check('under a random name in storage/shop-files, closed to the web', [preg_match('/^[a-f0-9]{32}\.bin$/', $kept['stored']), is_file("$root/storage/shop-files/" . $kept['stored']), is_file("$root/storage/shop-files/.htaccess")], [1, true, true]);
file_put_contents($tmp, 'font');
$downloads->add('font0000000000001', $tmp, 'font.zip', false);
file_put_contents($tmp, 'extras');
$downloads->add('ebook000000000001', $tmp, 'extras.epub', false);
check('nothing without a product or a file', [$downloads->add('', $tmp, 'x', false)['ok'], $downloads->add('ebook000000000001', "$root/none", 'x', false)['ok']], [false, false]);
check('names: no folders, odd characters made plain', [ShopDownloads::cleanName('C:\\x\\a<b>.pdf'), ShopDownloads::cleanName('Ελληνικά αρχεία.zip'), ShopDownloads::cleanName('...')], ['a_b_.pdf', 'Ελληνικά αρχεία.zip', '']);

// ---- a cart of downloads only
$cart = ['lines' => [['product_id' => 'ebook000000000001', 'variant_key' => '', 'quantity' => 1]], 'coupon' => ''];
$quote = $checkout->quote($cart['lines'], '', ['country' => 'US', 'postcode' => ''], '', '', 'el');
check('digital products are sold', [count($quote['priced']['lines']), $quote['priced']['lines'][0]['digital'], $quote['priced']['changes']], [1, true, []]);
check('downloads only: no shipping, one way that says so, any country', [$quote['digital_only'], $quote['method']['id'], $quote['totals']['shipping'], in_array('US', $quote['countries'], true)], [true, 'download', 0, true]);
check('and no cash on delivery', array_column($quote['payments'], 'id'), ['bank_transfer']);
$mixed = $checkout->quote([['product_id' => 'ebook000000000001', 'variant_key' => '', 'quantity' => 1], ['product_id' => 'ring0000000000001', 'variant_key' => '', 'quantity' => 1]], '', ['country' => 'GR', 'postcode' => '10563'], '', '', 'el');
check('a mixed cart is shipped as usual, the download weighing nothing', [$mixed['digital_only'], $mixed['method']['kind'], $mixed['totals']['shipping'], $mixed['priced']['grams'], array_column($mixed['payments'], 'id')], [false, 'flat', 400, 40, ['bank_transfer', 'cod']]);

$form = ['email' => 'maria@example.com', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => '5th Ave 1', 'city' => 'New York', 'postcode' => '10001', 'country' => 'US', 'phone' => '+1 212 000'], 'payment' => 'bank_transfer', 'method' => 'download'];
$placed = $checkout->place($form, $cart, null, 'el');
$order = $placed['order'];
check('an order of downloads only: no shipping address, on hold for the transfer', [$placed['ok'], $order['status'], $order['shipping'], $order['shipping_method']], [true, 'on_hold', [], 'download']);
check('nothing to download before it is paid', $downloads->forOrder($order['id']), []);
$checkout->changeStatus($order['id'], 'paid', 'admin');
$given = $downloads->forOrder($order['id']);
check('paid: each of its files can be downloaded', [array_column($given, 'name'), $given[0]['max_downloads'], $given[0]['left'], $given[0]['usable']], [['The Book (2026).pdf', 'extras.epub'], 2, 2, true]);
check('for the product\'s days', abs($given[0]['expires_at'] - (time() + 10 * 86400)) < 5, true);
check('the timeline says so', str_contains(json_encode($orders->events($order['id']), JSON_UNESCAPED_UNICODE), '2 files can be downloaded'), true);

// ---- the link
$url = $given[0]['url'];
$token = substr($url, strrpos($url, '/') + 1);
check('a link of the site, its id signed', [str_starts_with($url, 'https://shop.example/_shop/download/'), preg_match('/^\d+-[a-f0-9]{32}$/', $token)], [true, 1]);
check('it opens its file', [$downloads->open($token)['ok'], $downloads->open($token)['file']['name']], [true, 'The Book (2026).pdf']);
[$id, $sig] = explode('-', $token);
check('another id with the same signature does not', [$downloads->open(($id + 1) . '-' . $sig)['reason'], $downloads->open($id . '-' . str_repeat('a', 32))['reason'], $downloads->open('../etc')['reason']], ['gone', 'gone', 'gone']);
check('counted, up to its limit', [$downloads->count((int)$id), $downloads->count((int)$id), $downloads->count((int)$id)], [true, true, false]);
check('then used up', [$downloads->open($token)['ok'], $downloads->open($token)['reason']], [false, 'used']);
check('the other file has its own count', $downloads->open(substr($given[1]['url'], strrpos($given[1]['url'], '/') + 1))['ok'], true);
check('Allow again from the start', [$downloads->reset($order['id'], (int)$id), $downloads->open($token)['ok'], $downloads->reset($order['id'] + 99, (int)$id)], [true, true, false]);
$db->connection()->exec('UPDATE shop_downloads SET expires_at = ' . (time() - 1) . ' WHERE id = ' . (int)$id);
check('past its days', $downloads->open($token)['reason'], 'expired');
$downloads->reset($order['id'], (int)$id);

// ---- the paid email
$sent = [];
$mail = new ShopMail($shop, fn(): array => ['title' => 'Aleon'], fn(string $k, string $l, array $v): string => $k . ($v !== [] ? ':' . implode(',', $v) : ''), function (string $to, string $subject, string $body, array $headers) use (&$sent): bool { $sent[] = $body; return true; }, fn(string $id) => null, fn(int $o): array => $downloads->forOrder($o));
$mail->statusChanged($orders->get($order['id']), $orders->lines($order['id']), 'paid', 'https://shop.example/o');
check('the paid email has each file\'s link and the limits', [str_contains($sent[0], 'shop.mail_downloads'), str_contains($sent[0], $url), str_contains($sent[0], 'extras.epub'), str_contains($sent[0], 'shop.mail_downloads_times:2')], [true, true, true, true]);

// ---- refunds and cancels
$checkout->changeStatus($order['id'], 'refunded', 'admin');
check('refunded: the links stop working', [$downloads->open($token)['reason'], $downloads->forOrder($order['id'])[0]['usable']], ['off', false]);
$sent = [];
$mail->statusChanged($orders->get($order['id']), $orders->lines($order['id']), 'refunded', 'https://shop.example/o');
check('and no email gives them', str_contains($sent[0], '_shop/download'), false);

// ---- no limits, cash on delivery in a mixed order
$mixedCart = ['lines' => [['product_id' => 'font0000000000001', 'variant_key' => '', 'quantity' => 1], ['product_id' => 'ring0000000000001', 'variant_key' => '', 'quantity' => 1]], 'coupon' => ''];
$cod = $checkout->place(['payment' => 'cod', 'method' => ''] + array_replace_recursive($form, ['billing' => ['country' => 'GR', 'postcode' => '10563', 'city' => 'Athens']]), $mixedCart, null, 'el');
check('a mixed order paid on delivery: nothing to download yet', [$cod['ok'], $cod['order']['status'], $downloads->forOrder($cod['order']['id'])], [true, 'processing', []]);
$checkout->changeStatus($cod['order']['id'], 'shipped', 'admin');
$checkout->changeStatus($cod['order']['id'], 'completed', 'admin');
$font = $downloads->forOrder($cod['order']['id']);
check('completed: the font, without a limit or an end', [array_column($font, 'name'), $font[0]['left'], $font[0]['expires_at'], $font[0]['usable']], [['font.zip'], null, 0, true]);
$fontToken = substr($font[0]['url'], strrpos($font[0]['url'], '/') + 1);
for ($i = 0; $i < 20; $i++) { $downloads->count($font[0]['id']); }
check('downloaded as often as wanted', $downloads->open($fontToken)['ok'], true);

// ---- a file removed
$fontFile = $downloads->files('font0000000000001')[0];
check('a removed file: gone from the disk, its links not found', [$downloads->remove($fontFile['id']), is_file("$root/storage/shop-files/" . $fontFile['stored']), $downloads->open($fontToken)['reason']], [true, false, 'gone']);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
