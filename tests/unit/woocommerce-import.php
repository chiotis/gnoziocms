<?php
/*
 * The WooCommerce import (docs/shop-plan.md, section 10) against a fake shop: the REST API with keys (stock, weights, drafts,
 * downloads, variations with their own prices) and the public Store API (prices in minor units, variations by slug), the dry
 * run changing nothing, the import (categories with their tree, products with their fields, pictures, stock), running it again,
 * a file of the site's own left alone, grouped products and "any" variations reported, the redirects, the customers' CSV and
 * their WordPress password hashes (phpass and the newer bcrypt) signing in and hashed again.
 *   php tests/unit/woocommerce-import.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentRepository, ContentTypes, MediaLibrary, SystemDatabase, Taxonomies, Theme, WooCommerceImporter, WooCommerceReader};
use GnozioCMS\Shop\{ShopCustomers, ShopStock};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$root = sys_get_temp_dir() . '/wooimp' . getmypid();
mkdir("$root/storage", 0775, true);
mkdir("$root/content/products", 0775, true);
mkdir("$root/public/uploads", 0775, true);
mkdir("$root/custom", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });

// ---- a fake shop
$site = 'https://shop.example';
$v3 = [
    '/wp-json/wc/v3/products/categories' => [
        ['id' => 10, 'slug' => 'skoularikia', 'name' => 'Σκουλαρίκια', 'parent' => 0, 'description' => '<p>Χειροποίητα</p>', 'image' => ['src' => $site . '/wp-content/uploads/cat.jpg']],
        ['id' => 11, 'slug' => 'krikoi', 'name' => 'Κρίκοι &amp; κρεμαστά', 'parent' => 10, 'description' => '', 'image' => null],
    ],
    '/wp-json/wc/v3/products?status=any' => [
        ['id' => 101, 'slug' => 'balos-earrings', 'name' => 'Σκουλαρίκια Μπάλος', 'status' => 'publish', 'type' => 'variable', 'sku' => 'BAL', 'description' => '<p>Από <strong>ασήμι</strong>.</p>', 'short_description' => '<p>Χειροποίητα στην Κρήτη.</p>', 'regular_price' => '', 'sale_price' => '',
         'images' => [['src' => $site . '/wp-content/uploads/2026/01/balos-600x600.jpg', 'alt' => 'Μπάλος'], ['src' => $site . '/wp-content/uploads/2026/01/balos-worn.jpg', 'alt' => '']],
         'categories' => [['id' => 11]], 'attributes' => [['name' => 'Φινίρισμα', 'variation' => true, 'options' => ['Χρυσό', 'Ασήμι']], ['name' => 'Υλικό', 'variation' => false, 'options' => ['925']]],
         'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no', 'weight' => '0.02', 'downloadable' => false, 'virtual' => false, 'permalink' => $site . '/product/balos-earrings/', 'date_created' => '2025-05-01T10:00:00'],
        ['id' => 102, 'slug' => 'odigos', 'name' => 'Οδηγός φροντίδας', 'status' => 'publish', 'type' => 'simple', 'sku' => 'GUIDE', 'description' => '', 'short_description' => '', 'regular_price' => '9', 'sale_price' => '6.50',
         'images' => [], 'categories' => [], 'attributes' => [], 'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no', 'weight' => '', 'downloadable' => true, 'virtual' => true, 'permalink' => $site . '/product/odigos/', 'date_created' => '2025-06-01T10:00:00'],
        ['id' => 103, 'slug' => 'krikoi-mikroi', 'name' => 'Κρίκοι', 'status' => 'draft', 'type' => 'simple', 'sku' => '', 'description' => '<p>Μικροί.</p>', 'short_description' => '', 'regular_price' => '45', 'sale_price' => '',
         'images' => [['src' => $site . '/wp-content/uploads/krikoi.jpg', 'alt' => '']], 'categories' => [['id' => 11], ['id' => 99]], 'attributes' => [], 'manage_stock' => true, 'stock_quantity' => 7, 'backorders' => 'notify', 'weight' => '0.01', 'downloadable' => false, 'virtual' => false, 'permalink' => $site . '/?post_type=product&p=103', 'date_created' => '2026-01-01T10:00:00'],
        ['id' => 104, 'slug' => 'set', 'name' => 'Σετ', 'status' => 'publish', 'type' => 'grouped', 'permalink' => $site . '/product/set/'],
        ['id' => 105, 'slug' => 'mine', 'name' => 'Δικό μου', 'status' => 'publish', 'type' => 'simple', 'sku' => '', 'description' => '', 'short_description' => '', 'regular_price' => '10', 'sale_price' => '', 'images' => [], 'categories' => [], 'attributes' => [], 'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no', 'weight' => '', 'downloadable' => false, 'virtual' => false, 'permalink' => $site . '/product/mine/', 'date_created' => ''],
    ],
    '/wp-json/wc/v3/products/101/variations' => [
        ['attributes' => [['name' => 'Φινίρισμα', 'option' => 'Χρυσό']], 'sku' => 'BAL-G', 'regular_price' => '186', 'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 2, 'image' => ['src' => $site . '/wp-content/uploads/2026/01/balos-gold.jpg'], 'weight' => ''],
        ['attributes' => [['name' => 'Φινίρισμα', 'option' => 'Ασήμι']], 'sku' => 'BAL-S', 'regular_price' => '124', 'sale_price' => '99', 'manage_stock' => true, 'stock_quantity' => 5, 'image' => ['src' => $site . '/wp-content/uploads/2026/01/balos-600x600.jpg'], 'weight' => '0.03'],
        ['attributes' => [], 'sku' => 'BAL-ANY', 'regular_price' => '150', 'sale_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'image' => null, 'weight' => ''],
    ],
];
$asked = [];
// How the fake shop takes the keys: 'both', 'header' (a host whose server ignores them in the query) or 'query' (one that strips the header).
$takes = 'both';
$get = function (string $url, array $headers = []) use (&$asked, &$takes, $v3, $site): ?array {
    $asked[] = $url;
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);
    $path = $parts['path'];
    $inHeader = in_array('Authorization: Basic ' . base64_encode('ck_test:cs_test'), $headers, true) && $takes !== 'query';
    $inQuery = ($query['consumer_key'] ?? '') === 'ck_test' && ($query['consumer_secret'] ?? '') === 'cs_test' && $takes !== 'header';
    if (str_contains($path, '/wc/v3/') && !$inHeader && !$inQuery) {
        return ['status' => 401, 'body' => '{"code":"woocommerce_rest_cannot_view"}'];
    }
    $key = $path . (isset($query['status']) ? '?status=' . $query['status'] : '');
    if (isset($v3[$key])) {
        return ['status' => 200, 'body' => json_encode((int)($query['page'] ?? 1) === 1 ? $v3[$key] : [])];
    }
    // The Store API: what a shopper sees.
    if ($path === '/wp-json/wc/store/v1/products/categories') {
        return ['status' => 200, 'body' => json_encode((int)($query['page'] ?? 1) === 1 ? [['id' => 10, 'slug' => 'skoularikia', 'name' => 'Σκουλαρίκια', 'parent' => 0, 'description' => '', 'image' => null, 'link' => $site . '/product-category/skoularikia/']] : [])];
    }
    if ($path === '/wp-json/wc/store/v1/products') {
        return ['status' => 200, 'body' => json_encode((int)($query['page'] ?? 1) === 1 ? [
            ['id' => 101, 'slug' => 'balos-earrings', 'name' => 'Σκουλαρίκια Μπάλος', 'type' => 'variable', 'sku' => 'BAL', 'description' => '<p>Ασήμι.</p>', 'short_description' => '', 'permalink' => $site . '/product/balos-earrings/',
             'prices' => ['price' => '12400', 'regular_price' => '12400', 'sale_price' => '12400', 'currency_minor_unit' => 2], 'images' => [], 'categories' => [['id' => 10]],
             'attributes' => [['name' => 'Φινίρισμα', 'has_variations' => true, 'terms' => [['name' => 'Χρυσό', 'slug' => 'chryso'], ['name' => 'Ασήμι', 'slug' => 'asimi']]]],
             'variations' => [['id' => 201, 'attributes' => [['name' => 'Φινίρισμα', 'value' => 'chryso']]], ['id' => 202, 'attributes' => [['name' => 'Φινίρισμα', 'value' => 'asimi']]]]],
        ] : [])];
    }
    if (preg_match('#^/wp-json/wc/store/v1/products/(20[12])$#', $path, $m)) {
        return ['status' => 200, 'body' => json_encode(['id' => (int)$m[1], 'sku' => $m[1] === '201' ? 'BAL-G' : 'BAL-S', 'prices' => ['regular_price' => $m[1] === '201' ? '18600' : '12400', 'sale_price' => $m[1] === '201' ? '18600' : '9900', 'currency_minor_unit' => 2], 'images' => []])];
    }
    return ['status' => 404, 'body' => '[]'];
};
$downloaded = [];
$download = function (string $url, string $to) use (&$downloaded): int {
    $downloaded[] = $url;
    // A tiny real JPEG, so the library takes it.
    $img = imagecreatetruecolor(4, 4);
    imagejpeg($img, $to);
    return str_contains($url, 'missing') ? 404 : 200;
};

$db = new SystemDatabase("$root/storage");
$db->initialize();
$stock = new ShopStock($db);
$customers = new ShopCustomers($db);
$taxonomies = new Taxonomies("$root/content", ['el']);
$make = fn(WooCommerceReader $reader, array $options = []) => new WooCommerceImporter($reader, "$root/content", new MediaLibrary("$root/content", "$root/public/uploads"), $taxonomies, $download, $stock, $customers, $options + ['lang' => 'el']);

// ---- wrong keys
$wrong = $make(new WooCommerceReader($site, $get, 'ck_nope', 'cs_nope'));
try {
    $wrong->plan();
    check('wrong keys are said so', false, true);
} catch (RuntimeException $e) {
    check('wrong keys are said so, without them', [str_contains($e->getMessage(), 'refused the REST keys'), str_contains($e->getMessage(), 'ck_nope')], [true, false]);
}

// ---- the dry run, with the keys
file_put_contents("$root/content/products/mine.md", "---\ntitle: Mine\n---\nHand written.\n");
$csv = "$root/users.csv";
$phpass = '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0';
$bcrypt = '$wp' . password_hash(base64_encode(hash_hmac('sha384', 'secret pass', 'wp-sha384', true)), PASSWORD_BCRYPT);
file_put_contents($csv, "\xEF\xBB\xBFuser_email;user_pass;display_name;billing_first_name;billing_last_name;billing_phone;billing_address_1;billing_city;billing_postcode;billing_country\nmaria@example.com;$phpass;Maria P;Maria;Patera;2100000;Ermou 1;Athens;10563;GR\nnikos@example.com;$bcrypt;;Nikos;K;;;;;GR\nnot-an-email;x;;;;;;;;\n");
$customers->create('eleni@example.com', 'a long password', 'Eleni', 'el');
file_put_contents($csv, "eleni@example.com;" . $phpass . ";;;;;;;;\n", FILE_APPEND);
$rows = WooCommerceImporter::customersCsv($csv);
check('the users\' CSV: found by their column names, the hashes kept', [count($rows), $rows[0]['email'], $rows[0]['hash'], $rows[0]['city'], $rows[1]['name'] ?? ''], [4, 'maria@example.com', $phpass, 'Athens', '']);
$importer = $make(new WooCommerceReader($site, $get, 'ck_test', 'cs_test'));
$plan = $importer->plan($rows);
check('the dry run reads everything and changes nothing', [count($plan['products']), count($plan['categories']), is_file("$root/content/products/balos-earrings.md"), $downloaded, $plan['full']], [4, 2, false, [], true]);
$states = array_column($plan['products'], 'state', 'slug');
check('new products; a file of the site\'s own left alone', $states, ['balos-earrings' => 'new', 'odigos' => 'new', 'krikoi-mikroi' => 'new', 'mine' => 'skip']);
check('what cannot be carried over is said', [count($plan['problems']), str_contains(implode(' ', $plan['problems']), 'a grouped product is left out'), str_contains(implode(' ', $plan['problems']), '"any Φινίρισμα"')], [2, true, true]);
check('one copy of a picture, whatever its size in the address', count(array_filter($plan['media'], static fn(array $m): bool => str_contains($m['url'], 'balos.jpg'))), 1);
check('the redirects: products and categories', $plan['redirects'], [
    ['/product/balos-earrings', '/products/balos-earrings'], ['/product/odigos', '/products/odigos'], ['/product/mine', '/products/mine'],
    ['/product-category/skoularikia', '/product-categories/skoularikia'], ['/product-category/skoularikia/krikoi', '/product-categories/skoularikia/krikoi'],
]);
check('customers: new, here already, not an address', [array_column($plan['customers']['new'], 'email'), $plan['customers']['exists'], $plan['customers']['invalid']], [['maria@example.com', 'nikos@example.com'], ['eleni@example.com'], ['not-an-email']]);

// ---- the import
$done = $importer->apply($plan);
check('done: pictures, categories, products, stock, customers', [$done['media'], $done['categories'], $done['written'], $done['skipped'], $done['stock'], $done['customers']], [5, 2, 3, 1, 2, 2]);
$read = static function (string $slug) use ($root): array {
    $text = (string)file_get_contents("$root/content/products/$slug.md");
    preg_match('/^---\n(.*?)\n---\n(.*)$/s', $text, $m);
    return [Yaml::parse($m[1]), trim($m[2])];
};
[$balos, $body] = $read('balos-earrings');
$cf = $balos['custom_fields'];
check('a variable product: its options and variants with their own prices and SKUs', [$cf['options'], array_column($cf['variants'], 'values'), array_column($cf['variants'], 'price'), array_column($cf['variants'], 'sku')], [[['name' => 'Φινίρισμα', 'values' => "Χρυσό\nΑσήμι"]], ['Χρυσό', 'Ασήμι'], ['186.00', '99.00'], ['BAL-G', 'BAL-S']]);
check('its price the first variant\'s, its weight in grams, its category, its id', [$cf['price'], $cf['weight_g'], $cf['variants'][1]['weight_g'], $balos['product-categories'], $balos['translation_id'], $balos['status'], $balos['date']], ['186.00', 20, 30, ['krikoi'], 'woo0000000000101', 'published', '2025-05-01']);
check('its pictures in the library, the first the main one; a variant\'s own', [count($cf['gallery']), $balos['main_image'] === $cf['gallery'][0], str_starts_with($cf['variants'][0]['image'], '/uploads/media/'), $cf['variants'][1]['image'] === $cf['gallery'][0]], [2, true, true, true]);
check('its texts: the description as Markdown, the short one as text', [$body, $cf['short_description']], ['Από **ασήμι**.', 'Χειροποίητα στην Κρήτη.']);
check('stock by variant', [$stock->forProduct('woo0000000000101')['χρυσό']['on_hand'] ?? null, $stock->forProduct('woo0000000000101')['ασήμι']['on_hand'] ?? null, $cf['track_stock']], [2, 5, true]);
[$guide] = $read('odigos');
check('a download sold at a sale price, its old price kept', [$guide['custom_fields']['kind'], $guide['custom_fields']['price'], $guide['custom_fields']['compare_at_price']], ['digital', '6.50', '9.00']);
[$krikoi] = $read('krikoi-mikroi');
check('a draft stays a draft; stock, sold when out of it; an unknown category left out', [$krikoi['status'], $stock->forProduct('woo0000000000103')['']['on_hand'], $krikoi['custom_fields']['allow_backorder'], $krikoi['product-categories']], ['draft', 7, true, ['krikoi']]);
check('the site\'s own file is as it was', file_get_contents("$root/content/products/mine.md"), "---\ntitle: Mine\n---\nHand written.\n");
$terms = (new Taxonomies("$root/content", ['el']))->load('product-categories')['terms'];
check('the nested address is one this site answers', (new Taxonomies("$root/content", ['el']))->findByPath('product-categories', 'skoularikia/krikoi')['id'] ?? null, 'krikoi');
check('categories with their tree, names and picture', [array_column($terms, 'id'), $terms[1]['parent'] ?? '', $terms[1]['labels']['el'], $terms[0]['descriptions']['el'], str_starts_with($terms[0]['image'], '/uploads/media/')], [['skoularikia', 'krikoi'], 'skoularikia', 'Κρίκοι & κρεμαστά', 'Χειροποίητα', true]);

// ---- the products as the shop reads them
$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$content = new ContentRepository("$root/content", new MarkdownConverter((new Environment([]))->addExtension(new CommonMarkCoreExtension())), ['languages' => ['default' => 'el', 'available' => ['el']], 'content_types' => ['pages', 'products']], fn(): array => $types->catalogue());
$catalogue = new GnozioCMS\Shop\ShopCatalogue($content, fn() => $taxonomies, fn() => $types, $stock, fn() => 'el');
$product = $catalogue->product('woo0000000000101', 'el');
$details = $catalogue->details($product, 'el');
check('the shop sells them: prices, variants, availability', [$details['price_text'], count($details['variants']), $details['variants'][1]['price_text'], $details['available']], ['€186', 2, '€99', true]);

// ---- again
$downloaded = [];
$again = $make(new WooCommerceReader($site, $get, 'ck_test', 'cs_test'));
$plan2 = $again->plan($rows);
check('run again: what it brought is updated, nothing doubled', [array_column($plan2['products'], 'state', 'slug'), $again->apply($plan2)['media'], $plan2['customers']['exists']], [['balos-earrings' => 'update', 'odigos' => 'update', 'krikoi-mikroi' => 'update', 'mine' => 'skip'], 0, ['maria@example.com', 'nikos@example.com', 'eleni@example.com']]);

// ---- customers sign in with their old passwords
$maria = $customers->byEmail('maria@example.com');
check('a customer brought over: confirmed, with the address, the old hash kept', [$maria['verified'] ?? ($maria['verified_at'] !== null), $maria['address']['city'] ?? '', $maria['password_hash']], [true, 'Athens', $phpass]);
check('a wrong password does not sign in', $customers->authenticate('maria@example.com', 'test12346'), null);
check('the old WordPress password signs in, and is hashed again', [$customers->authenticate('maria@example.com', 'test12345')['email'] ?? null, str_starts_with($customers->byEmail('maria@example.com')['password_hash'], '$2y$'), $customers->authenticate('maria@example.com', 'test12345') !== null], ['maria@example.com', true, true]);
check('WordPress 6.8\'s hashes too', [$customers->authenticate('nikos@example.com', 'secret pass')['email'] ?? null, $customers->authenticate('nikos@example.com', 'nope')], ['nikos@example.com', null]);
check('one already here is kept as it was', password_verify('a long password', $customers->byEmail('eleni@example.com')['password_hash']), true);

// ---- how the keys are sent
foreach (['header', 'query'] as $way) {
    $takes = $way;
    $asked = [];
    $read = new WooCommerceReader($site, $get, 'ck_test', 'cs_test');
    $found = [count($read->categories()), count($read->products())];
    $inUrl = array_filter($asked, static fn(string $u): bool => str_contains($u, 'cs_test'));
    check("a shop that takes the keys only in the $way: read all the same", $found, [count($v3['/wp-json/wc/v3/products/categories']), count($v3['/wp-json/wc/v3/products?status=any'])]);
    check("  and the keys in an address only when the shop needs them there ($way)", $inUrl !== [], $way === 'query');
}
$takes = 'both';

// ---- the public Store API
$asked = [];
$store = $make(new WooCommerceReader($site, $get))->plan();
$item = $store['products'][0];
check('without keys: the Store API, prices from minor units, variants named', [$store['full'], count($store['products']), str_contains($item['markdown'], "values: Χρυσό\n"), str_contains($item['markdown'], "price: '99.00'"), str_contains($item['markdown'], "price: '186.00'"), $item['stock']], [false, 1, true, true, true, null]);
check('and nothing sent to the REST API', array_filter($asked, static fn(string $u): bool => str_contains($u, '/wc/v3/')), []);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
