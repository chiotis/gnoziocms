<?php

declare(strict_types=1);

namespace GnozioCMS;

use GnozioCMS\Shop\Shop;
use GnozioCMS\Shop\ShopCustomers;
use GnozioCMS\Shop\ShopMoney;
use GnozioCMS\Shop\ShopStock;
use Symfony\Component\Yaml\Yaml;

/**
 * Brings a WooCommerce shop over (docs/shop-plan.md, section 10): its product categories as the Product categories taxonomy, its
 * products as Products (price and sale price, SKU, pictures, the description, the short description, options and variants with
 * their own prices, SKUs, pictures and weights, whether it is a download), their stock into the shop's stock, its customers
 * (from a CSV of its users, their WordPress password hashes kept so they sign in as before), and the old addresses that must
 * lead to the new ones (/product/…, /product-category/…).
 *
 * Like the WordPress import, it works in two steps: plan() reads everything and says what would be done, changing nothing (the
 * dry run); apply() does it. A product's id here is made from its WooCommerce id, so running it again updates what it brought;
 * a product file of the site's own that the import did not bring is never overwritten.
 */
final class WooCommerceImporter
{
    /** @var array<string, array{id: string, ext: string, name: string, alt: string}> address => picture */
    private array $media = [];

    /**
     * @param \Closure(string, string): int $download an address into a file: the answer's status
     * @param array<string, mixed> $options lang, default_lang, overwrite
     */
    public function __construct(
        private WooCommerceReader $reader,
        private string $contentDir,
        private MediaLibrary $library,
        private Taxonomies $taxonomies,
        private \Closure $download,
        private ?ShopStock $stock = null,
        private ?ShopCustomers $customers = null,
        private array $options = []
    ) {
        $this->contentDir = rtrim($contentDir, '/');
    }

    /**
     * Reads the shop and says what an import would do. Nothing is written and no file is downloaded.
     *
     * @param list<array<string, string>> $customers rows of the users' CSV (customersCsv())
     * @return array<string, mixed>
     */
    public function plan(array $customers = []): array
    {
        $this->media = [];
        $lang = $this->lang();
        $categories = $this->reader->categories();
        $byId = [];
        foreach ($categories as $category) {
            $byId[$category['id']] = $category;
        }
        $terms = [];
        foreach ($categories as $category) {
            $parent = $byId[$category['parent']]['slug'] ?? '';
            $terms[] = [
                'id' => Slug::plain($category['slug']),
                'slug' => Slug::plain($category['slug']),
                'parent' => Slug::plain($parent),
                'labels' => [$lang => $category['name']],
                'descriptions' => [$lang => trim((new HtmlToMarkdown())->convert($category['description']))],
                'image' => $category['image'] !== '' ? $this->picture($category['image'], $category['name']) : '',
                'imported_from' => $category['link'],
                'woo_id' => $category['id'],
            ];
        }

        $products = [];
        $problems = [];
        $redirects = [];
        $taken = [];
        foreach ($this->reader->products() as $product) {
            if (in_array($product['type'], ['grouped', 'external'], true)) {
                $problems[] = $product['name'] . ': a ' . $product['type'] . ' product is left out (this shop has no such kind).';
                continue;
            }
            $slug = $this->slug($product, $taken);
            $taken[$slug] = true;
            $file = $this->contentDir . '/' . Shop::TYPE . '/' . $slug . '.md';
            $state = $this->stateOf($file, $product['link']);
            $render = $this->render($product, $byId);
            foreach ($render['problems'] as $problem) {
                $problems[] = $product['name'] . ': ' . $problem;
            }
            $products[] = [
                'woo_id' => $product['id'],
                'name' => $product['name'],
                'slug' => $slug,
                'file' => $file,
                'state' => $state,
                'kind' => $render['kind'],
                'variants' => count($product['variations']),
                'pictures' => count($product['images']),
                'markdown' => $render['markdown'],
                'stock' => $render['stock'],
                'translation_id' => $render['translation_id'],
            ];
            if ($product['link'] !== '') {
                $redirects[] = [self::pathOf($product['link']), '/' . ($lang === $this->defaultLang() ? '' : $lang . '/') . Shop::TYPE . '/' . $slug];
            }
        }
        foreach ($categories as $category) {
            if ($category['link'] !== '') {
                // A nested category's address here is its whole path, as WooCommerce's was.
                $path = [Slug::plain($category['slug'])];
                for ($up = $byId[$category['parent']] ?? null, $guard = 0; $up !== null && $guard < 10; $up = $byId[$up['parent']] ?? null, $guard++) {
                    array_unshift($path, Slug::plain($up['slug']));
                }
                $redirects[] = [self::pathOf($category['link']), '/' . ($lang === $this->defaultLang() ? '' : $lang . '/') . Taxonomies::kind(Shop::CATEGORIES) . '/' . implode('/', $path)];
            }
        }
        $redirects = array_values(array_filter($redirects, static fn(array $r): bool => $r[0] !== '' && $r[0] !== '/' && rtrim($r[0], '/') !== rtrim($r[1], '/')));

        $people = ['new' => [], 'exists' => [], 'invalid' => [], 'no_password' => 0];
        foreach ($customers as $row) {
            $email = trim($row['email'] ?? '');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $people['invalid'][] = $email;
                continue;
            }
            if ($this->customers?->byEmail($email) !== null) {
                $people['exists'][] = $email;
                continue;
            }
            if (!ShopCustomers::isWordPressHash($row['hash'] ?? '') && password_get_info($row['hash'] ?? '')['algo'] === null) {
                $people['no_password']++;
            }
            $people['new'][] = $row;
        }

        return [
            'site' => $this->reader->site(),
            'full' => $this->reader->full(),
            'categories' => $terms,
            'products' => $products,
            'media' => array_values(array_map(static fn(string $url, array $m): array => $m + ['url' => $url], array_keys($this->media), $this->media)),
            'redirects' => $redirects,
            'customers' => $people,
            'problems' => $problems,
        ];
    }

    /**
     * Does what plan() said: the pictures, the categories, the products and their stock, the customers. The redirects are the
     * caller's to put into the site (ImportedRedirects), as the WordPress import does.
     *
     * @param array<string, mixed> $plan
     * @return array{media: int, media_failed: list<string>, categories: int, written: int, skipped: int, stock: int, customers: int}
     */
    public function apply(array $plan): array
    {
        $media = $this->applyMedia($plan, INF);
        return ['media' => $media['done'], 'media_failed' => $media['failed']] + $this->applyContent($plan);
    }

    /**
     * The pictures into the library, from the plan's picture number $from on, until $deadline (microtime) has passed; at least one
     * is fetched each time. A picture already in the library is not fetched again, so a run cut short starts again safely.
     *
     * @param array<string, mixed> $plan
     * @param (\Closure(int, int): void)|null $onEach after each picture: the number of the next one, and how many were fetched
     * @return array{next: int, total: int, done: int, failed: list<string>}
     */
    public function applyMedia(array $plan, float $deadline, int $from = 0, ?\Closure $onEach = null): array
    {
        $out = ['next' => $from, 'total' => count($plan['media']), 'done' => 0, 'failed' => []];
        $this->library->ensureDirectories();
        for ($i = $from; $i < $out['total']; $i++) {
            if ($i > $from && microtime(true) >= $deadline) {
                break;
            }
            $out['next'] = $i + 1;
            $file = $plan['media'][$i];
            if ($onEach !== null && $i > $from) {
                $onEach($i, $out['done']);
            }
            if ($this->library->find($file['id']) !== null) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'woo');
            $status = ($this->download)($file['url'], (string)$tmp);
            if ($status !== 200) {
                @unlink((string)$tmp);
                $out['failed'][] = $file['url'];
                continue;
            }
            try {
                $this->library->import((string)$tmp, $file['name'], $file['id'], $file['alt'], 'woocommerce-import');
                $out['done']++;
            } catch (\Throwable) {
                $out['failed'][] = $file['url'];
            } finally {
                @unlink((string)$tmp);
            }
        }
        return $out;
    }

    /**
     * The rest of the plan, once the pictures are in: the categories, the products and their stock, the customers.
     *
     * @param array<string, mixed> $plan
     * @return array{categories: int, written: int, skipped: int, stock: int, customers: int}
     */
    public function applyContent(array $plan): array
    {
        $done = ['categories' => 0, 'written' => 0, 'skipped' => 0, 'stock' => 0, 'customers' => 0];

        // Categories: the old ones joined with those already here, by id.
        $existing = $this->taxonomies->load(Shop::CATEGORIES);
        $merged = [];
        foreach ($existing['terms'] as $term) {
            $merged[$term['id']] = $term;
        }
        foreach ($plan['categories'] as $term) {
            $here = $merged[$term['id']] ?? null;
            $merged[$term['id']] = ($here ?? []) + array_diff_key($term, ['woo_id' => true]);
            if ($here !== null) {
                $merged[$term['id']]['labels'] = array_replace(array_filter($term['labels']), array_filter($here['labels']));
                $merged[$term['id']]['parent'] = $here['parent'] !== '' ? $here['parent'] : $term['parent'];
            }
            $done['categories']++;
        }
        // WooCommerce's categories nest: when the shop's do, so do they here.
        $nested = array_filter($plan['categories'], static fn(array $t): bool => $t['parent'] !== '') !== [];
        $this->taxonomies->save(Shop::CATEGORIES, $existing['title'], array_values($merged), null, $nested || $existing['hierarchical'] ? true : null);

        if (!is_dir($this->contentDir . '/' . Shop::TYPE)) {
            mkdir($this->contentDir . '/' . Shop::TYPE, 0775, true);
        }
        foreach ($plan['products'] as $product) {
            if ($product['state'] === 'skip') {
                $done['skipped']++;
                continue;
            }
            file_put_contents($product['file'], $product['markdown']);
            $done['written']++;
            if ($this->stock !== null && $product['stock'] !== null) {
                $this->stock->setOnHand($product['translation_id'], $product['stock'], array_keys($product['stock']));
                $done['stock']++;
            }
        }

        foreach ($plan['customers']['new'] as $row) {
            if ($this->customers === null) {
                break;
            }
            $address = ['first_name' => $row['first_name'] ?? '', 'last_name' => $row['last_name'] ?? '', 'company' => $row['company'] ?? '', 'address' => $row['address'] ?? '', 'address_2' => $row['address_2'] ?? '', 'city' => $row['city'] ?? '', 'postcode' => $row['postcode'] ?? '', 'country' => strtoupper($row['country'] ?? ''), 'phone' => $row['phone'] ?? ''];
            $name = trim(($row['name'] ?? '') !== '' ? (string)$row['name'] : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            if ($this->customers->import((string)$row['email'], (string)($row['hash'] ?? ''), $name, (string)($row['phone'] ?? ''), $address, $this->lang()) === 'new') {
                $done['customers']++;
            }
        }
        return $done;
    }

    /**
     * The users' CSV: one row per customer, with a header. The columns are found by their names, as phpMyAdmin or WP All Export
     * write them: user_email (or email), user_pass (the hash), display_name, first_name, last_name, and the billing_… ones.
     *
     * @return list<array<string, string>>
     */
    public static function customersCsv(string $path): array
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException('The customers file could not be read: ' . $path);
        }
        $first = (string)fgets($in);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(static fn(?string $h): string => strtolower(trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))), str_getcsv($first, $delimiter, '"', ''));
        $map = [
            'email' => ['user_email', 'email', 'billing_email'], 'hash' => ['user_pass', 'password_hash', 'pass'], 'name' => ['display_name', 'name'],
            'first_name' => ['first_name', 'billing_first_name'], 'last_name' => ['last_name', 'billing_last_name'], 'company' => ['billing_company'],
            'phone' => ['billing_phone', 'phone'], 'address' => ['billing_address_1'], 'address_2' => ['billing_address_2'], 'city' => ['billing_city'],
            'postcode' => ['billing_postcode'], 'country' => ['billing_country'],
        ];
        $rows = [];
        while (($cells = fgetcsv($in, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $row = [];
            foreach ($map as $key => $names) {
                foreach ($names as $name) {
                    $i = array_search($name, $header, true);
                    if ($i !== false && trim((string)($cells[$i] ?? '')) !== '' && !isset($row[$key])) {
                        $row[$key] = trim((string)$cells[$i]);
                    }
                }
            }
            if (($row['email'] ?? '') !== '') {
                $rows[] = $row;
            }
        }
        fclose($in);
        return $rows;
    }

    // ---- inside

    /**
     * A product's file: its front matter and its description as Markdown, and its stock by variant.
     *
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $categories
     * @return array{markdown: string, stock: array<string, int>|null, kind: string, translation_id: string, problems: list<string>}
     */
    private function render(array $product, array $categories): array
    {
        $problems = [];
        $id = 'woo' . str_pad((string)$product['id'], 13, '0', STR_PAD_LEFT);
        $pictures = array_map(fn(array $i): string => $this->picture($i['src'], $i['alt'] !== '' ? $i['alt'] : $product['name']), $product['images']);
        [$price, $was] = self::prices($product['regular'], $product['sale']);
        $custom = ['kind' => $product['digital'] ? 'digital' : 'physical'];
        $optionNames = array_map(static fn(array $o): string => $o['name'], $product['options']);
        $variants = [];
        $stock = null;
        if ($product['type'] === 'variable') {
            foreach ($product['variations'] as $v) {
                $values = [];
                foreach ($optionNames as $name) {
                    $values[] = (string)($v['values'][$name] ?? '');
                }
                if (in_array('', $values, true)) {
                    // WooCommerce's "any" value: one variation stands for several; here each combination is its own.
                    $problems[] = 'a variation for "any ' . implode('/', array_keys(array_filter(array_combine($optionNames, $values), static fn(string $v): bool => $v === ''))) . '" is left out; make one per value.';
                    continue;
                }
                [$vPrice] = self::prices($v['regular'], $v['sale']);
                $combination = implode(' / ', $values);
                $variants[] = array_filter([
                    'values' => $combination,
                    'sku' => $v['sku'],
                    'price' => $vPrice !== null ? ShopMoney::plain($vPrice) : null,
                    'image' => $v['image'] !== '' ? $this->picture($v['image'], $product['name'] . ' ' . $combination) : null,
                    'weight_g' => $v['grams'],
                ], static fn(mixed $x): bool => $x !== null && $x !== '');
                if ($v['stock'] !== null) {
                    $stock[ShopStock::variantKey($combination)] = max(0, (int)$v['stock']);
                }
                if ($price === null && $vPrice !== null) {
                    $price = $vPrice;
                }
            }
            $custom['options'] = array_map(static fn(array $o): array => ['name' => $o['name'], 'values' => implode("\n", $o['values'])], $product['options']);
            $custom['variants'] = $variants;
        } elseif ($product['stock'] !== null) {
            $stock = ['' => max(0, (int)$product['stock'])];
        }
        if ($price === null) {
            $problems[] = 'it has no price: it is brought, but not sold until it has one.';
        }
        $custom += array_filter([
            'price' => $price !== null ? ShopMoney::plain($price) : null,
            'compare_at_price' => $was !== null ? ShopMoney::plain($was) : null,
            'sku' => $product['sku'],
            'short_description' => WooCommerceReader::text(str_replace(['</p>', '<br>', '<br />'], "\n", $product['short'])),
            'gallery' => $pictures !== [] ? $pictures : null,
            'weight_g' => $product['grams'],
            'track_stock' => $product['manage_stock'] || $stock !== null ? true : null,
            'allow_backorder' => $product['backorders'] ? true : null,
        ], static fn(mixed $x): bool => $x !== null && $x !== '');
        $terms = [];
        foreach ($product['categories'] as $categoryId) {
            if (isset($categories[$categoryId])) {
                $terms[] = Slug::plain($categories[$categoryId]['slug']);
            }
        }
        $meta = array_filter([
            'title' => $product['name'],
            'status' => $product['status'] === 'publish' ? 'published' : 'draft',
            'date' => $product['date'] !== '' ? $product['date'] : date('Y-m-d'),
            'translation_id' => $id,
            'main_image' => $pictures[0] ?? null,
            Shop::CATEGORIES => $terms !== [] ? $terms : null,
            'imported_from' => $product['link'] !== '' ? $product['link'] : null,
            'custom_fields' => $custom,
        ], static fn(mixed $x): bool => $x !== null);
        $body = trim((new HtmlToMarkdown())->convert($product['description']));
        return [
            'markdown' => "---\n" . Yaml::dump($meta, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) . "---\n" . ($body !== '' ? "\n" . $body . "\n" : ''),
            'stock' => $stock,
            'kind' => $custom['kind'],
            'translation_id' => $id,
            'problems' => $problems,
        ];
    }

    /** @return array{0: ?int, 1: ?int} the price it sells for, and the "was" price when it is on sale */
    private static function prices(?int $regular, ?int $sale): array
    {
        if ($sale !== null && $regular !== null && $sale < $regular) {
            return [$sale, $regular];
        }
        return [$regular ?? $sale, null];
    }

    /** A picture of the old shop, as its path in this site's library (downloaded by apply()). */
    private function picture(string $url, string $alt): string
    {
        $original = strtok($url, '?#') ?: $url;
        $canonical = (string)preg_replace('/-(?:\d{2,5}x\d{2,5}|scaled)(\.[A-Za-z0-9]+)$/', '$1', $original);
        if (!isset($this->media[$canonical])) {
            $path = (string)parse_url($canonical, PHP_URL_PATH);
            $this->media[$canonical] = [
                'id' => substr(sha1($canonical), 0, 16),
                'ext' => strtolower((string)preg_replace('/[^a-z0-9]/i', '', pathinfo($path, PATHINFO_EXTENSION))),
                'name' => rawurldecode(basename($path)),
                'alt' => mb_substr($alt, 0, 200),
            ];
        }
        $file = $this->media[$canonical];
        return '/uploads/media/' . $file['id'] . ($file['ext'] !== '' ? '.' . $file['ext'] : '');
    }

    /** @param array<string, bool> $taken */
    private function slug(array $product, array $taken): string
    {
        $base = Slug::plain($product['slug'] !== '' ? $product['slug'] : $product['name']);
        $base = $base !== '' ? $base : 'product-' . $product['id'];
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /** new, update (brought by this import before), or skip (a file of the site's own, unless overwrite is asked). */
    private function stateOf(string $file, string $from): string
    {
        if (!is_file($file)) {
            return 'new';
        }
        $head = (string)file_get_contents($file, false, null, 0, 4096);
        if (str_contains($head, 'translation_id: woo') || ($from !== '' && str_contains($head, $from))) {
            return 'update';
        }
        return !empty($this->options['overwrite']) ? 'update' : 'skip';
    }

    private static function pathOf(string $address): string
    {
        $address = (string)preg_replace_callback('/[^\x21-\x7E]/', static fn(array $m): string => rawurlencode($m[0]), trim($address));
        $path = trim(rawurldecode((string)parse_url($address, PHP_URL_PATH)), '/');
        return $path === '' ? '' : '/' . $path;
    }

    private function lang(): string
    {
        return (string)($this->options['lang'] ?? 'el');
    }

    private function defaultLang(): string
    {
        return (string)($this->options['default_lang'] ?? $this->lang());
    }
}
