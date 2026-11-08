<?php
/*
 * Brings a WooCommerce shop into this site's shop (docs/shop-plan.md, section 10): product categories, products with their
 * variants, pictures and stock, customers, and the redirects from the old addresses. By default it only says what it would do
 * (the dry run); add --apply to do it. Run it on a copy of the site first.
 *
 *   php scripts/import-woocommerce.php https://shop.example                      the dry run, from the public Store API
 *   WC_KEY=ck_… WC_SECRET=cs_… php scripts/import-woocommerce.php https://shop.example
 *                                                                                 the same with the REST keys: stock quantities,
 *                                                                                 weights, drafts and downloads too
 *   php scripts/import-woocommerce.php https://shop.example --customers=users.csv --apply
 *
 * The keys are read from the environment only, never from the command line, and never written anywhere. Make them in
 * WooCommerce > Settings > Advanced > REST API, Read permission. In a terminal:  read -s WC_KEY; read -s WC_SECRET; export WC_KEY WC_SECRET
 *
 * The customers' CSV comes from the old site's database, for example in phpMyAdmin (wp_ is the tables' prefix):
 *   SELECT u.user_email, u.user_pass, u.display_name,
 *     MAX(CASE WHEN m.meta_key = 'billing_first_name' THEN m.meta_value END) AS billing_first_name,
 *     MAX(CASE WHEN m.meta_key = 'billing_last_name' THEN m.meta_value END) AS billing_last_name,
 *     MAX(CASE WHEN m.meta_key = 'billing_phone' THEN m.meta_value END) AS billing_phone,
 *     MAX(CASE WHEN m.meta_key = 'billing_address_1' THEN m.meta_value END) AS billing_address_1,
 *     MAX(CASE WHEN m.meta_key = 'billing_city' THEN m.meta_value END) AS billing_city,
 *     MAX(CASE WHEN m.meta_key = 'billing_postcode' THEN m.meta_value END) AS billing_postcode,
 *     MAX(CASE WHEN m.meta_key = 'billing_country' THEN m.meta_value END) AS billing_country
 *   FROM wp_users u JOIN wp_usermeta r ON r.user_id = u.ID AND r.meta_key = 'wp_capabilities' AND r.meta_value LIKE '%customer%'
 *   LEFT JOIN wp_usermeta m ON m.user_id = u.ID GROUP BY u.ID;
 * Export the result as CSV with the column names in the first row. The file holds password hashes: keep it off the web and
 * delete it after the import. Customers keep their passwords: each is checked against the WordPress hash on their first
 * sign-in here, then hashed again the way this site does.
 *
 * Options:
 *   --apply              do it (otherwise only say what would be done)
 *   --lang=el            the language of the shop's texts (default el)
 *   --default-lang=el    this site's default language (default: the same as --lang)
 *   --customers=FILE     the users' CSV (above)
 *   --overwrite          also replace a product file of the site that the import did not bring
 *   --content=PATH       the content folder (default ./content)
 *   --uploads=PATH       the uploads folder (default ./public/uploads)
 *   --storage=PATH       the site's storage folder, for the stock, the customers and the redirects (default: beside --content)
 *   --out=PATH           where the report and the redirects list are written (default ./storage/import)
 *   --redirects=apply    also put the redirects into the site's Redirects (with --apply)
 */
require __DIR__ . '/../vendor/autoload.php';

use GnozioCMS\{ImportedRedirects, MediaLibrary, RedirectRepository, SystemDatabase, Taxonomies, WooCommerceImporter, WooCommerceReader, WordPressHttp};
use GnozioCMS\Shop\{ShopCustomers, ShopStock};

$root = dirname(__DIR__);
$site = '';
$opt = ['apply' => false, 'lang' => 'el', 'default-lang' => '', 'customers' => '', 'overwrite' => false, 'content' => $root . '/content', 'uploads' => $root . '/public/uploads', 'storage' => '', 'out' => $root . '/storage/import', 'redirects' => ''];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (!array_key_exists($key, $opt)) {
            fwrite(STDERR, "Unknown option --$key\n");
            exit(2);
        }
        $opt[$key] = is_bool($opt[$key]) ? true : (string)$value;
    } elseif ($site === '') {
        $site = $arg;
    }
}
if ($site === '') {
    fwrite(STDERR, "Usage: php scripts/import-woocommerce.php https://shop.example [--apply] [--customers=users.csv] ...\n");
    exit(2);
}
$site = preg_match('#^https?://#i', $site) ? $site : 'https://' . $site;
$lang = (string)$opt['lang'];
$defaultLang = $opt['default-lang'] !== '' ? (string)$opt['default-lang'] : $lang;
$key = (string)(getenv('WC_KEY') ?: '');
$secret = (string)(getenv('WC_SECRET') ?: '');
if (($key !== '' || $secret !== '') && !str_starts_with(strtolower($site), 'https://')) {
    fwrite(STDERR, "The REST keys are only sent over https.\n");
    exit(2);
}

$storage = $opt['storage'] !== '' ? (string)$opt['storage'] : dirname(rtrim((string)$opt['content'], '/')) . '/storage';
$database = new SystemDatabase($storage);
$database->initialize();
if (!$database->isAvailable()) {
    fwrite(STDERR, 'The site database could not be opened (' . $database->lastError() . "): point --storage at the site's storage folder.\n");
    exit(1);
}
$rows = [];
if ($opt['customers'] !== '') {
    try {
        $rows = WooCommerceImporter::customersCsv((string)$opt['customers']);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
}
$importer = new WooCommerceImporter(
    new WooCommerceReader($site, static fn(string $url): ?array => WordPressHttp::get($url), $key, $secret),
    (string)$opt['content'],
    new MediaLibrary((string)$opt['content'], (string)$opt['uploads']),
    new Taxonomies((string)$opt['content'], array_values(array_unique([$defaultLang, $lang]))),
    static fn(string $url, string $to): int => WordPressHttp::download($url, $to),
    new ShopStock($database),
    new ShopCustomers($database),
    ['lang' => $lang, 'default_lang' => $defaultLang, 'overwrite' => $opt['overwrite']]
);

try {
    echo "Reading $site " . ($key !== '' ? '(REST API, with the keys)' : '(the public Store API; set WC_KEY and WC_SECRET for stock quantities, weights, drafts and downloads)') . " ...\n";
    $plan = $importer->plan($rows);
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not read the shop: ' . $e->getMessage() . "\n");
    exit(1);
}

$states = array_count_values(array_column($plan['products'], 'state'));
$kinds = array_count_values(array_column($plan['products'], 'kind'));
$variable = count(array_filter($plan['products'], static fn(array $p): bool => $p['variants'] > 0));
$stocked = count(array_filter($plan['products'], static fn(array $p): bool => $p['stock'] !== null));
echo "\n";
printf("  product categories: %d\n", count($plan['categories']));
printf("  products: %d new, %d to update, %d left alone (files of this site's own)\n", $states['new'] ?? 0, $states['update'] ?? 0, $states['skip'] ?? 0);
printf("    %d physical, %d digital; %d with variants (%d variants in all); %d with stock quantities\n", $kinds['physical'] ?? 0, $kinds['digital'] ?? 0, $variable, array_sum(array_column($plan['products'], 'variants')), $stocked);
printf("  pictures for the media library: %d\n", count($plan['media']));
printf("  redirects from old addresses: %d\n", count($plan['redirects']));
if ($opt['customers'] !== '') {
    $c = $plan['customers'];
    printf("  customers: %d new (%d without a password they can use: they set one with \"Forgot your password\"), %d already here, %d without a valid address\n", count($c['new']), $c['no_password'], count($c['exists']), count($c['invalid']));
}
foreach ($plan['products'] as $p) {
    if ($p['state'] === 'skip') {
        echo "  left alone: products/{$p['slug']} (a file of this site's own; --overwrite replaces it)\n";
    }
}
if ($plan['problems'] !== []) {
    echo "\nWhat could not be carried over exactly (" . count($plan['problems']) . "):\n";
    foreach (array_slice($plan['problems'], 0, 25) as $line) {
        echo "  - $line\n";
    }
    if (count($plan['problems']) > 25) {
        echo '  ... ' . (count($plan['problems']) - 25) . " more (all of them in report.txt)\n";
    }
}

$out = rtrim((string)$opt['out'], '/');
if (!is_dir($out)) {
    @mkdir($out, 0775, true);
}
$list = implode("\n", array_map(static fn(array $r): string => $r[0] . ' ' . $r[1], $plan['redirects'])) . "\n";
@file_put_contents($out . '/woocommerce-redirects.txt', $list);
$report = ['site' => $plan['site'], 'at' => date('c'), 'applied' => $opt['apply'], 'products' => array_map(static fn(array $p): array => array_diff_key($p, ['markdown' => true]), $plan['products']), 'problems' => $plan['problems'], 'redirects' => $plan['redirects']];
@file_put_contents($out . '/woocommerce-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
@file_put_contents($out . '/report.txt', implode("\n", $plan['problems']) . "\n");
echo "\nThe redirects list and a report are in $out/.\n";

if (!$opt['apply']) {
    echo "\nNothing was changed. Add --apply to import.\n";
    exit(0);
}
$done = $importer->apply($plan);
printf("\nDone: %d pictures, %d categories, %d products written (%d left alone), stock of %d, %d customers.\n", $done['media'], $done['categories'], $done['written'], $done['skipped'], $done['stock'], $done['customers']);
foreach (array_slice($done['media_failed'], 0, 10) as $url) {
    echo "  picture not fetched: $url\n";
}
if ($opt['redirects'] === 'apply') {
    $result = (new ImportedRedirects(new RedirectRepository($database)))->apply(ImportedRedirects::parse($list), true, 'woocommerce-import');
    printf("  redirects put into the site: %d new, %d pointed at a new address, %d already there\n", $result['added'], $result['updated'], $result['kept']);
} else {
    echo "  The redirects are not in the site yet: add --redirects=apply, or paste the list on Settings > Redirects.\n";
}
echo "Switch the Products content type on (Admin > Content types) if it is not, and look the shop over before it goes live.\n";
