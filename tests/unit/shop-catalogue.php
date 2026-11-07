<?php
/*
 * The shop's catalogue (docs/shop-plan.md, phase 1): the shop is on with the Products type, blocks that ask for it (`requires: shop`)
 * exist only then, fields every translation shares (`shared: true`), the stock and its holds, a collection's number, tones and quote,
 * the products a Product grid lists with their prices, options and variants, the Shop settings, and the Product in structured data.
 *   php tests/unit/shop-catalogue.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, ContentItem, ContentRepository, ContentTypes, SharedFields, StructuredData, SystemDatabase, SystemMetaRepository, Taxonomies, Theme};
use GnozioCMS\Shop\{Shop, ShopCatalogue, ShopSettings, ShopStock};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = sys_get_temp_dir() . '/shopcat' . getmypid();
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
$content = fn(): ContentRepository => new ContentRepository("$root/content", $markdown, $settings, fn(): array => $types->catalogue());
$db = new SystemDatabase("$root/storage");
$db->initialize();
$put = static function (string $file, string $front) use ($root): void {
    file_put_contents("$root/content/products/$file", "---\n$front\n---\n\nText\n");
};

// ---- the switch
check('the shop is on with the Products type', [Shop::enabled(['pages', 'products']), Shop::enabled(['pages', 'posts'])], [true, false]);
check('the features blocks ask for', [Shop::features(['products']), Shop::features([])], [['shop' => true], ['shop' => false]]);
check('Products and Looks are in the catalogue', array_values(array_intersect(['looks', 'products'], $types->catalogue())), ['looks', 'products']);
check('price, SKU, options and variants are shared by every translation; texts are not',
    [in_array('price', $types->sharedKeys('products'), true), in_array('variants', $types->sharedKeys('products'), true), in_array('short_description', $types->sharedKeys('products'), true), in_array('option_labels', $types->sharedKeys('products'), true)],
    [true, true, false, false]);

// ---- blocks that need the shop
check('a block asks for a feature by name or as a map', [BlockRegistry::requiredFeature('shop'), BlockRegistry::requiredFeature(['feature' => 'shop']), BlockRegistry::requiredFeature('Bad Name'), BlockRegistry::requiredFeature(null)], ['shop', 'shop', '', '']);
$off = new BlockRegistry($theme);
$on = new BlockRegistry($theme, [], fn(): array => ['shop' => true]);
check('with the shop off, no shop block exists', [$off->get('product-grid'), $off->get('product-summary'), $off->get('hero') !== null], [null, null, true]);
check('with it on, they do', [$on->get('product-grid')['requires'] ?? '', $on->get('product-price') !== null], ['shop', true]);
$offered = static fn(BlockRegistry $r, bool|string $scope): array => array_values(array_intersect(['product-details', 'product-gallery', 'product-grid', 'product-price', 'product-summary'], array_column($r->editorDefinitions($scope), 'type')));
check('a page offers the grid and the price; a product template also the gallery, summary and details',
    [$offered($on, false), $offered($on, 'template'), $offered($off, 'template')],
    [['product-grid', 'product-price'], ['product-details', 'product-gallery', 'product-grid', 'product-price', 'product-summary'], []]);
check('a shop block kept in a page while the shop is off is kept as it is', $off->sanitizeForStorage([['type' => 'product-grid', 'source' => 'all']]), [['type' => 'product-grid', 'source' => 'all']]);

// ---- fields every translation shares
$put('ring.md', "title: Ring\ntranslation_id: aaaa000000000001\ncustom_fields:\n  price: 100\n  sku: R-1\n  short_description: Χειροποίητο");
$put('ring.en.md', "title: Ring EN\ntranslation_id: aaaa000000000001\ncustom_fields:\n  short_description: Handmade\n  price: 90");
$keys = $types->sharedKeys('products');
check('a translation that lacks them is given those of the default language', SharedFields::fromSiblings($content(), 'products', 'aaaa000000000001', 'en', ['sku'], 'el'), ['sku' => 'R-1']);
$written = [];
$count = SharedFields::spread($content(), 'products', 'aaaa000000000001', 'el', ['price' => 120, 'sku' => 'R-1', 'short_description' => 'Χειροποίητο'], $keys, function (string $path, string $lang) use (&$written): void { $written[] = $lang; });
$en = (new ContentRepository("$root/content", $markdown, $settings))->parseFile('products', "$root/content/products/ring.en.md");
check('saving one writes its shared values into the others, and keeps their own texts', [$count, $written, $en->meta['custom_fields']], [1, ['en'], ['short_description' => 'Handmade', 'price' => 120, 'sku' => 'R-1']]);
check('the other file keeps its title and text', [$en->meta['title'], trim($en->markdown)], ['Ring EN', 'Text']);
check('a second save with the same values writes nothing', SharedFields::spread($content(), 'products', 'aaaa000000000001', 'el', ['price' => 120, 'sku' => 'R-1'], $keys, fn() => null), 0);
SharedFields::spread($content(), 'products', 'aaaa000000000001', 'el', ['price' => 120], $keys, fn() => null);
$en = (new ContentRepository("$root/content", $markdown, $settings))->parseFile('products', "$root/content/products/ring.en.md");
check('a shared value emptied in one is removed from the others', array_key_exists('sku', $en->meta['custom_fields']), false);
check('without an id there is nothing to share', SharedFields::spread($content(), 'products', '', 'el', ['price' => 1], $keys, fn() => null), 0);

// ---- stock
$stock = new ShopStock($db);
check('a variant key is its combination, in lower case, one space around each slash', [ShopStock::variantKey(' Gold/M '), ShopStock::variantKey('Χρυσό  /  Large'), ShopStock::variantKey('')], ['gold / m', 'χρυσό / large', '']);
check('a product without variants has one row', ShopStock::variantLabels([]), ['' => '']);
check('variants have a row each, the first of a repeated one', ShopStock::variantLabels(['variants' => [['values' => 'Gold / S'], ['values' => 'gold/s'], ['values' => 'Silver / S'], ['values' => '']]]), ['gold / s' => 'Gold / S', 'silver / s' => 'Silver / S']);
$stock->setOnHand('p1', ['gold / s' => '3', 'silver / s' => -4, 'other' => 9], ['gold / s', 'silver / s']);
check('on hand is set for the product\'s variants only, never below zero', $stock->forProduct('p1'), ['gold / s' => ['on_hand' => 3, 'reserved' => 0], 'silver / s' => ['on_hand' => 0, 'reserved' => 0]]);
check('holding takes from what is free', [$stock->reserve('p1', 'gold / s', 2), $stock->reserve('p1', 'gold / s', 2), $stock->available('p1', 'gold / s')], [true, false, 1]);
$stock->release('p1', 'gold / s', 1);
$stock->commit('p1', 'gold / s', 1);
check('a release frees a held piece; a paid one leaves the stock', $stock->forProduct('p1')['gold / s'], ['on_hand' => 2, 'reserved' => 0]);
$stock->reserve('p1', 'silver / s', 1);
$stock->setOnHand('p1', ['gold / s' => 5], ['gold / s']);
check('a variant that is gone is removed, unless something is held for it', array_keys($stock->forProduct('p1')), ['gold / s']);
$stock->reserve('p1', 'gold / s', 1);
$stock->setOnHand('p1', [], []);
check('a variant with a hold stays', array_keys($stock->forProduct('p1')), ['gold / s']);
check('the editor\'s rows', $stock->rowsFor('p1', ['variants' => [['values' => 'Gold / S']]]), [['key' => 'gold / s', 'label' => 'Gold / S', 'on_hand' => 5, 'reserved' => 1]]);

// ---- a collection's number, tones and quote
$taxonomies = new Taxonomies("$root/content", ['el', 'en']);
Shop::ensureTaxonomies($taxonomies);
check('switching the shop on writes Collections and Product categories, for products only', [in_array('collections', $taxonomies->names(), true), $taxonomies->load('product-categories')['assign'], $taxonomies->acceptsType('collections', 'posts')], [true, ['products'], false]);
$prepared = $taxonomies->prepare('collections', [
    ['id' => '', 'slug' => 'balos', 'labels' => ['el' => 'Μπάλος', 'en' => 'Balos'], 'number' => '2', 'tones' => ['bg' => '#E3ECEA', 'surface' => '#c3d6d3', 'deep' => 'blue'], 'quote' => ['el' => 'Η θάλασσα', 'en' => ''], 'quote_author' => ' Maria  Patera '],
    ['id' => '', 'slug' => 'kampos', 'labels' => ['el' => 'Κάμπος', 'en' => 'Kampos']],
], 'el');
$taxonomies->save('collections', 'Collections', $prepared['terms']);
$taxonomies->forget();
$balos = $taxonomies->load('collections')['terms'][0];
check('a collection keeps its chapter, valid tones in lower case, quote and author', [$balos['number'], $balos['tones'], $balos['quote'], $balos['quote_author']], [2, ['bg' => '#e3ecea', 'surface' => '#c3d6d3', 'deep' => ''], ['el' => 'Η θάλασσα', 'en' => ''], 'Maria Patera']);
$file = (string)file_get_contents("$root/content/taxonomies/collections.yaml");
check('a collection without them writes none', [substr_count($file, 'number:'), substr_count($file, 'tones:'), substr_count($file, 'quote_author:')], [1, 1, 1]);
$again = $taxonomies->prepare('collections', [['id' => 'balos', 'slug' => 'balos', 'labels' => ['el' => 'Μπάλος', 'en' => 'Balos']]], 'el');
check('a form that does not send them keeps them', [$again['terms'][0]['number'], $again['terms'][0]['tones']['bg']], [2, '#e3ecea']);
check('the CSS of the tones', [ShopCatalogue::toneStyle($balos['tones']), ShopCatalogue::toneStyle(null)], ['--c-bg:#e3ecea;--c-surface:#c3d6d3', '']);

// ---- what the blocks show
$put('ring.md', "title: Lampi Ring\ntranslation_id: aaaa000000000001\ndate: 2026-01-01\ncollections: [balos]\ncustom_fields:\n  price: 125\n  track_stock: true");
@unlink("$root/content/products/ring.en.md");
$put('cuff.md', "title: Balos Cuff\ntranslation_id: aaaa000000000002\ndate: 2026-02-01\ncollections: [balos]\ncustom_fields:\n  price: 210\n  compare_at_price: 240\n  hover_image: /uploads/worn.jpg");
$put('earrings.md', "title: Balos Earrings\ntranslation_id: aaaa000000000003\ndate: 2026-03-01\ncollections: [balos]\ncustom_fields:\n  price: 145\n  options:\n    - { name: Finish, values: \"Gold\\nSilver\" }\n  variants:\n    - { values: Gold, price: 160 }\n    - { values: Silver }\n  option_labels: \"Finish = Φινίρισμα\\nGold = Χρυσό\"\n  track_stock: true\n  details:\n    - { title: Care, text: 'Keep it **dry**.' }\n    - { title: '', text: 'no title' }");
$put('studs.md', "title: Kampos Studs\ntranslation_id: aaaa000000000004\ndate: 2026-04-01\ncollections: [kampos]\ncustom_fields:\n  price: 95.5");
$put('draft.md', "title: Draft\nstatus: draft\ntranslation_id: aaaa000000000005\ndate: 2026-05-01\ncustom_fields:\n  price: 1");
$stock = new ShopStock($db);
$stock->setOnHand('aaaa000000000003', ['silver' => 2], ['gold', 'silver']);
$catalogue = new ShopCatalogue($content(), fn(): Taxonomies => $taxonomies, fn(): ContentTypes => $types, $stock, fn(): string => 'el');
$titles = static fn(array $items): array => array_map(static fn(ContentItem $p): string => (string)$p->meta['title'], $items);
check('all products, newest first, no drafts', $titles($catalogue->products(['source' => 'all'], 'el')), ['Kampos Studs', 'Balos Earrings', 'Balos Cuff', 'Lampi Ring']);
check('one collection, by its address, cheapest first', $titles($catalogue->products(['source' => 'collection', 'term' => 'balos', 'order' => 'price_asc'], 'el')), ['Lampi Ring', 'Balos Earrings', 'Balos Cuff']);
check('an unknown collection lists nothing', $catalogue->products(['source' => 'collection', 'term' => 'nowhere'], 'el'), []);
check('products chosen by address, in that order, a missing one left out', $titles($catalogue->products(['source' => 'picked', 'picked' => "studs\nmissing\nring"], 'el')), ['Kampos Studs', 'Lampi Ring']);
$cuff = $content()->find('products', 'cuff', 'el');
check('more from the product\'s collection leaves the product out', $titles($catalogue->products(['source' => 'related', 'limit' => 2], 'el', $cuff)), ['Balos Earrings', 'Lampi Ring']);
check('related lists nothing outside a product\'s page', $catalogue->products(['source' => 'related'], 'el'), []);
$card = $catalogue->card($cuff, 'el');
check('a card: price, the old price, the hover picture, the collection with its tones', [$card['price_text'], $card['was_text'], $card['hover_image'], $card['collection']['label'], $card['collection']['tones']['bg'], $card['available']], ['€210', '€240', '/uploads/worn.jpg', 'Μπάλος', '#e3ecea', true]);
check('prices in Greek and English', [ShopCatalogue::money(95.5, 'el'), ShopCatalogue::money(1250, 'el'), ShopCatalogue::money(1250.5, 'en')], ['€95,50', '€1.250', '€1,250.50']);
check('a tracked product with nothing on hand is sold out', $catalogue->available($content()->find('products', 'ring', 'el')), false);
$earrings = $catalogue->details($content()->find('products', 'earrings', 'el'), 'el');
check('options in this language', $earrings['options'], [['index' => 0, 'name' => 'Finish', 'label' => 'Φινίρισμα', 'choices' => [['value' => 'Gold', 'label' => 'Χρυσό'], ['value' => 'Silver', 'label' => 'Silver']]]]);
check('variants with their own price or the product\'s, and their stock', array_map(static fn(array $v): array => [$v['key'], $v['price_text'], $v['available']], $earrings['variants']), [['gold', '€160', false], ['silver', '€145', true]]);
check('a card says "from" when the variants differ in price', [$earrings['from'], $card['from']], [true, false]);
check('Details sections without a title are left out', $earrings['details'], [['title' => 'Care', 'text' => 'Keep it **dry**.']]);
check('the grid block lists products; the page blocks need a product', [count($catalogue->blockData('product-grid', ['source' => 'all', 'limit' => 2], ['lang' => 'el'])['products']), $catalogue->blockData('product-summary', [], ['lang' => 'el'])['_empty']], [2, true]);
check('the details block loads the Accordion\'s styles', $catalogue->blockData('product-details', [], ['lang' => 'el', 'item' => $content()->find('products', 'earrings', 'el')])['_types'], ['accordion']);
check('a gallery without pictures is not drawn', $catalogue->blockData('product-gallery', [], ['lang' => 'el', 'item' => $cuff])['_empty'], true);

// ---- Shop settings
$shop = new ShopSettings(new SystemMetaRepository($db));
check('defaults: euro, VAT in the price, Greek rates, guest checkout, a hold of 30 minutes', [$shop->all()['currency'], $shop->all()['prices_include_vat'], $shop->all()['tax_rates'], $shop->all()['guest_checkout'], $shop->all()['reserve_minutes']], ['EUR', true, ['standard' => 24.0, 'reduced' => 13.0, 'super_reduced' => 6.0, 'zero' => 0.0], true, 30]);
$shop->saveGeneral(['store_name' => '  ALALEON  ', 'store_email' => 'not an email', 'order_prefix' => 'al-2<b>', 'reserve_minutes' => '1', 'tax_rates' => ['standard' => '24,5', 'reduced' => '200'], 'terms_page' => '../x']);
$saved = $shop->all();
check('the General tab is checked: name, email, prefix, minutes, rates, pages', [$saved['store_name'], $saved['store_email'], $saved['order_prefix'], $saved['reserve_minutes'], $saved['tax_rates']['standard'], $saved['tax_rates']['reduced'], $saved['guest_checkout'], $saved['terms_page']], ['ALALEON', '', 'AL-2B', 30, 24.5, 13.0, false, '']);
check('a tax class\'s rate; an unknown class is the standard one', [$shop->taxRate('reduced'), $shop->taxRate('luxury')], [13.0, 24.5]);

// ---- the Product in structured data
$structured = new StructuredData(fn(): array => ['title' => 'ALALEON', 'base_url' => 'https://shop.test'], fn(): array => [], fn(string $k, ?string $f = null): string => (string)$f, fn(string $p): string => 'https://shop.test/' . ltrim($p, '/'), fn(string $l): string => '', fn(ContentItem $p): bool => $catalogue->available($p));
$product = static function (array $graph): ?array {
    foreach ($graph as $node) {
        if (($node['@type'] ?? '') === 'Product') {
            return $node;
        }
    }
    return null;
};
$p = $product($structured->forItem($cuff, 'el', 'https://shop.test/products/cuff', false));
check('a product has a Product with its Offer in euro', [$p['name'] ?? null, $p['offers']['@type'] ?? null, $p['offers']['price'] ?? null, $p['offers']['priceCurrency'] ?? null, $p['offers']['availability'] ?? null, $p['brand']['name'] ?? null], ['Balos Cuff', 'Offer', '210.00', 'EUR', 'https://schema.org/InStock', 'ALALEON']);
$e = $product($structured->forItem($content()->find('products', 'earrings', 'el'), 'el', 'https://shop.test/products/earrings', false));
check('variants that differ in price give an AggregateOffer', [$e['offers']['@type'], $e['offers']['lowPrice'], $e['offers']['highPrice']], ['AggregateOffer', '145.00', '160.00']);
$r = $product($structured->forItem($content()->find('products', 'ring', 'el'), 'el', 'https://shop.test/products/ring', false));
check('a sold-out product says so', $r['offers']['availability'], 'https://schema.org/OutOfStock');
$put('free.md', "title: No price\ntranslation_id: aaaa000000000006\n");
check('a product without a price has no Product (search engines refuse an offer without one)', $product($structured->forItem((new ContentRepository("$root/content", $markdown, $settings))->parseFile('products', "$root/content/products/free.md"), 'el', 'https://shop.test/x', false)), null);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
