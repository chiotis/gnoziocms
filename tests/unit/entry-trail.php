<?php
/*
 * EntryTrail: the trail above an entry's title written as a pattern (the Entry title block's Trail field). The trail of old for an
 * empty pattern (and without the type for a page), each token ({home}, {type}, {title}, {term:NAME} with the terms above it or :alone,
 * {taxonomy:NAME}), plain text with and without an address, the language prefix, the parts that have nothing to show left out,
 * and whether the last crumb is the page itself.
 *   php tests/unit/entry-trail.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{EntryTrail, Taxonomies};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/trail' . getmypid(); mkdir($dir, 0775, true);
register_shutdown_function(function () use ($dir) { @exec('rm -rf ' . escapeshellarg($dir)); });
$t = new Taxonomies($dir, ['en']);
$t->ensureDefaults();
$term = fn(string $id, string $label, string $parent = '') => ['id' => $id, 'slug' => $id, 'parent' => $parent, 'labels' => ['en' => $label]];
$t->save('collections', 'Collections', [$term('balos', 'Balos'), $term('elafonisi', 'Elafonisi')]);
$t->save('product-categories', 'Product categories', [$term('jewellery', 'Jewellery'), $term('earrings', 'Earrings', 'jewellery'), $term('rings', 'Rings', 'jewellery')], null, true);

$translate = fn(string $key, ?string $fallback): string => ['nav.main.home' => 'Αρχική', 'type.products' => 'Προϊόντα'][$key] ?? (string)$fallback;
$label = fn(string $name, string $id): string => $t->label($name, $id, 'en');
$url = fn(string $name, string $id): string => 'https://shop.test/' . $name . '/' . $t->slug($name, $id);
$trail = fn(string $pattern, array $meta = [], string $type = 'products', string $prefix = '') => EntryTrail::build($pattern, $type, $meta + ['title' => 'Balos Earrings'], $prefix, $t, $translate, $label, $url);
$labels = fn(array $result): string => implode(' / ', array_column($result['crumbs'], 'label'));
$product = ['collections' => ['balos'], 'product-categories' => ['earrings']];

// ---- an empty pattern is the trail of old
$old = $trail('');
check('empty: Home, the type and the title', $labels($old), 'Αρχική / Προϊόντα / Balos Earrings');
check('with their addresses, the title without one', array_column($old['crumbs'], 'url'), ['/', '/products', '']);
check('and the title is the page itself', $old['current'], true);
check('a page has no type in its trail', $labels($trail('', [], 'pages')), 'Αρχική / Balos Earrings');
check('the language prefix goes in front of the addresses', array_column($trail('', [], 'products', 'en/')['crumbs'], 'url'), ['/en/', '/en/products', '']);

// ---- the client's trail: Collections // Balos // Earrings
$shop = $trail('Collections|/shop > {term:collections} > {term:product-categories}', $product);
check('text, the collection and the category with the one above it', $labels($shop), 'Collections / Balos / Jewellery / Earrings');
check('each with its address', array_column($shop['crumbs'], 'url'), ['/shop', 'https://shop.test/collections/balos', 'https://shop.test/product-categories/jewellery', 'https://shop.test/product-categories/jewellery/earrings']);
check('the last crumb is not the page, so it keeps its link', $shop['current'], false);
check('{taxonomy:NAME} is the taxonomy\'s name, without an address', $trail('{taxonomy:collections} > {term:collections}', $product)['crumbs'][0], ['label' => 'Collections', 'url' => '']);
check('{term:NAME:alone} leaves out the terms above it', $labels($trail('Collections > {term:collections} > {term:product-categories:alone}', $product)), 'Collections / Balos / Earrings');
check('a term on a flat taxonomy is the term alone', $labels($trail('{term:collections}', $product)), 'Balos');

// ---- parts with nothing to show are left out
check('an entry with no term in the taxonomy', $labels($trail('Collections > {term:collections} > {title}', ['product-categories' => ['rings']])), 'Collections / Balos Earrings');
check('a term the taxonomy does not have', $labels($trail('{term:collections} > {title}', ['collections' => ['gone']])), 'Balos Earrings');
check('the first known term counts', $labels($trail('{term:collections}', ['collections' => ['gone', 'elafonisi', 'balos']])), 'Elafonisi');
check('the terms as one text, as front matter may have them', $labels($trail('{term:collections}', ['collections' => 'elafonisi, balos'])), 'Elafonisi');
check('an unknown taxonomy and an unknown token', $labels($trail('{term:colours} > {taxonomy:colours} > {weather} > {title}')), 'Balos Earrings');
check('empty parts and spaces', $labels($trail(' > Shop >> {title} > ')), 'Shop / Balos Earrings');

// ---- text and its address
check('text without an address', $trail('Shop')['crumbs'][0], ['label' => 'Shop', 'url' => '']);
check('a site path gets the language prefix', $trail('Shop|/shop', [], 'products', 'el/')['crumbs'][0]['url'], '/el/shop');
check('a full address stays as it is', $trail('Shop|https://example.com/x')['crumbs'][0]['url'], 'https://example.com/x');
check('anything else is no address', array_column($trail('A|javascript:alert(1) > B|//evil.test > C|shop')['crumbs'], 'url'), ['', '', '']);
check('the page counts as current only when {title} is last', [$trail('{title} > Shop')['current'], $trail('Shop > {title}')['current']], [false, true]);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
