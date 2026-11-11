<?php

declare(strict_types=1);

namespace GnozioCMS;

use GnozioCMS\Shop\Shop;
use GnozioCMS\Shop\ShopCatalogue;
use GnozioCMS\Shop\ShopSettings;
use GnozioCMS\Shop\ShopStock;

/**
 * The shop through MCP (docs/mcp-shop-plan.md): what the shop is, its products with their prices and stock, prices and sales
 * changed in batches (checked first, then saved), and stock. Only while the shop is on, and only for connections given the
 * shop scopes, which are off until a person ticks them. Products themselves are entries: create_entry and update_entry make
 * and change them. A price moves at most as far as the connection's rules allow in one change (priceProblems, also applied by
 * update_entry), and the shop changes of a connection in 24 hours are limited.
 */
final class McpShop
{
    public const MAX_BATCH = 200;

    public function __construct(
        private ContentRepository $content,
        private McpContent $writer,
        private ContentTypes $types,
        private Taxonomies $taxonomies,
        private ShopStock $stock,
        private ShopSettings $shopSettings,
        private McpStore $store,
        private string $defaultLang,
        private ?TaxonomyEditor $terms = null,
        /** @var string[] */
        private array $languages = [],
        /** @var (\Closure(string, string, string, string, array<string, mixed>): array{location: string, view: array<string, mixed>})|null deletes one language of a product as the admin does: slug, lang, after, after_target, connection */
        private ?\Closure $deleteEntry = null,
        /** @var (\Closure(): void)|null what visitors see changed: the page cache is cleared */
        private ?\Closure $changed = null,
    ) {
    }

    /** The taxonomies these tools may change. */
    public const TAXONOMIES = [Shop::CATEGORIES, Shop::COLLECTIONS];

    /** @return array<string, array{scopes: string[], permission: ?string, shop: bool, title: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>}> */
    public static function definitions(): array
    {
        $read = [McpOAuth::SCOPE_SHOP_READ, McpOAuth::SCOPE_SHOP_WRITE];
        $write = [McpOAuth::SCOPE_SHOP_WRITE];
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
        $str = static fn(string $description, ?int $max = null): array => ['type' => 'string', 'description' => $description] + ($max !== null ? ['maxLength' => $max] : []);
        $num = static fn(string $description): array => ['type' => ['number', 'string', 'null'], 'description' => $description];
        $schema = static fn(array $properties, array $required): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        $product = $str('The product\'s address (slug), as list_products gives it.');
        $variant = $str('The variant, by its combination as list_products or get_product gives it (such as "Gold / M"). Leave it out for the product itself.');
        return [
            'shop_info' => [
                'scopes' => $read, 'permission' => 'content.manage', 'shop' => true, 'title' => 'About the shop',
                'description' => 'The shop\'s currency and VAT rates, the fields of a product, its categories and collections with their term ids, '
                    . 'and this connection\'s limits (how far a price may move in one change, shop changes left in 24 hours). Call it before working on the shop.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
                'annotations' => $ro,
            ],
            'list_products' => [
                'scopes' => $read, 'permission' => 'content.manage', 'shop' => true, 'title' => 'List products',
                'description' => 'Products, newest first, drafts included: price, "was" price, whether on sale, variants and stock. Filters combine.',
                'inputSchema' => $schema([
                    'category' => $str('Only products in this product category (term id, see shop_info).'),
                    'collection' => $str('Only products in this collection (term id, see shop_info).'),
                    'status' => ['type' => 'string', 'enum' => McpContent::STATUSES],
                    'kind' => ['type' => 'string', 'enum' => ['physical', 'digital', 'subscription']],
                    'query' => $str('Words in the title or SKU.', 200),
                    'stock' => ['type' => 'string', 'enum' => ['out', 'low', 'in'], 'description' => 'out: nothing left of a tracked product (or of one of its variants); low: at or under its low-stock mark; in: some left.'],
                    'on_sale' => ['type' => 'boolean', 'description' => 'Only products (or variants) on sale, or only those not on sale.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ], []),
                'annotations' => $ro,
            ],
            'get_product' => [
                'scopes' => $read, 'permission' => 'content.manage', 'shop' => true, 'title' => 'Read a product',
                'description' => 'One product in full: its entry (text, fields, terms, pictures, translations, updated_at) and, for each variant, its price, "was" price, SKU and stock.',
                'inputSchema' => $schema(['product' => $product, 'lang' => $str('The language of the text; the default when left out. Prices and stock are the same in every language.')], ['product']),
                'annotations' => $ro,
            ],
            'update_prices' => [
                'scopes' => $write, 'permission' => 'content.manage', 'shop' => true, 'title' => 'Change prices and sales',
                'description' => 'Changes the prices of up to ' . self::MAX_BATCH . ' products or variants at once. Each change gives price and/or compare_at_price (the "was" price shown struck through), '
                    . 'or sale_price (starts a sale: the current price becomes the "was" price), or end_sale (the "was" price becomes the price again). '
                    . 'With dry_run (the default) nothing is saved: you get the table of what would change. Check it, then call again with dry_run false. '
                    . 'Every problem is named at once and nothing is saved while there is one. A price may move at most as far as this connection\'s limit (shop_info).',
                'inputSchema' => $schema([
                    'changes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_BATCH, 'items' => $schema([
                        'product' => $product, 'variant' => $variant,
                        'price' => $num('The new price, VAT included. For a variant, "" or null means the product\'s price.'),
                        'compare_at_price' => $num('The new "was" price; "" or null takes it away.'),
                        'sale_price' => $num('Starts a sale at this price, lower than the current one.'),
                        'end_sale' => ['type' => 'boolean', 'description' => 'Ends the sale: the "was" price becomes the price again.'],
                    ], ['product'])],
                    'dry_run' => ['type' => 'boolean', 'default' => true, 'description' => 'true (the default): only show what would change.'],
                ], ['changes']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            'set_stock' => [
                'scopes' => $write, 'permission' => 'content.manage', 'shop' => true, 'title' => 'Set stock',
                'description' => 'Sets what is on hand of up to ' . self::MAX_BATCH . ' products or variants: on_hand (the count) or adjust (+5 when goods come in, -2 for breakage). '
                    . 'Pieces held for orders waiting to be paid are kept apart. Stock counts only for a product whose track_stock is on (update_entry sets it).',
                'inputSchema' => $schema([
                    'changes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_BATCH, 'items' => $schema([
                        'product' => $product, 'variant' => $variant,
                        'on_hand' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ShopStock::MAX],
                        'adjust' => ['type' => 'integer', 'minimum' => -ShopStock::MAX, 'maximum' => ShopStock::MAX],
                    ], ['product'])],
                    'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'true: only show what would change.'],
                ], ['changes']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
        ] + self::termDefinitions($str, $schema) + [
            'archive_product' => [
                'scopes' => $write, 'permission' => 'content.manage', 'shop' => true, 'title' => 'Take a product off sale',
                'description' => 'Makes the product a draft in every language: it leaves the shop and its page, and is kept with its history, prices and stock. update_entry with status published puts it back (that needs content:publish).',
                'inputSchema' => $schema(['product' => $product], ['product']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            'delete_product' => [
                'scopes' => [McpOAuth::SCOPE_SHOP_DELETE], 'permission' => 'content.manage', 'shop' => true, 'title' => 'Delete a product',
                'description' => 'Deletes the product in every language, as Delete in the admin does: its text stays in the history, from where a person can bring it back. '
                    . 'Prefer archive_product, which keeps it. Visitors of its address are sent to redirect_to.',
                'inputSchema' => $schema([
                    'product' => $product,
                    'redirect_to' => $str('Where visitors of its address go: "shop" (the products page, the default), "home", "none" (they find nothing), or an address of the site such as /products/another.', 300),
                ], ['product']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * create_term, update_term and remove_term: the shop's categories and collections.
     *
     * @param \Closure(string, ?int=): array<string, mixed> $str
     * @param \Closure(array<string, mixed>, list<string>): array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private static function termDefinitions(\Closure $str, \Closure $schema): array
    {
        $perLang = static fn(string $description): array => ['type' => 'object', 'description' => $description . ' By language: {"el": "...", "en": "..."}.', 'additionalProperties' => ['type' => 'string']];
        $taxonomy = ['type' => 'string', 'enum' => self::TAXONOMIES, 'description' => 'product-categories (kinds of product) or collections.'];
        $term = [
            'labels' => $perLang('The name.'),
            'slug' => $str('Its address; made from the name when left out. A new address keeps the old one working.', 120),
            'parent' => $str('The id of the term it goes under, where the taxonomy has levels; "" for the top.'),
            'description' => $perLang('The text of its page.'),
            'image' => $str('A picture of the media library (the path add_image_from_url gave); "" takes it away.'),
            'seo_title' => $perLang('The title for search engines.'),
            'seo_description' => $perLang('The description for search engines.'),
            'number' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999, 'description' => 'Collections only: its chapter number (0: none).'],
            'tones' => ['type' => 'object', 'description' => 'Collections only: its colours as #rrggbb: bg (the page), surface (cards), deep (text and buttons, readable on bg).', 'properties' => ['bg' => ['type' => 'string'], 'surface' => ['type' => 'string'], 'deep' => ['type' => 'string']], 'additionalProperties' => false],
            'quote' => $perLang('Collections only: the quote at the start of its page.'),
            'quote_author' => $str('Collections only: who said it.', 120),
        ];
        $write = [McpOAuth::SCOPE_SHOP_WRITE];
        $annotations = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
        return [
            'create_term' => [
                'scopes' => $write, 'permission' => 'taxonomies.manage', 'shop' => true, 'title' => 'Add a category or collection',
                'description' => 'Adds a product category or a collection. labels needs at least the default language. Gives back its id, which terms in create_entry and update_entry take.',
                'inputSchema' => $schema(['taxonomy' => $taxonomy] + $term, ['taxonomy', 'labels']),
                'annotations' => $annotations,
            ],
            'update_term' => [
                'scopes' => $write, 'permission' => 'taxonomies.manage', 'shop' => true, 'title' => 'Change a category or collection',
                'description' => 'Changes what is given and keeps the rest. A language left out of labels or a text keeps what it has.',
                'inputSchema' => $schema(['taxonomy' => $taxonomy, 'term' => $str('Its id (shop_info lists them).')] + $term, ['taxonomy', 'term']),
                'annotations' => $annotations + ['idempotentHint' => true],
            ],
            'remove_term' => [
                'scopes' => [McpOAuth::SCOPE_SHOP_DELETE], 'permission' => 'taxonomies.manage', 'shop' => true, 'title' => 'Remove a category or collection',
                'description' => 'Removes a product category or a collection, as the admin does: it is taken off the products filed under it, its terms below move up, and its page is gone.',
                'inputSchema' => $schema(['taxonomy' => $taxonomy, 'term' => $str('Its id.')], ['taxonomy', 'term']),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    public function call(string $tool, array $args, array $connection): array
    {
        $known = array_keys((array)(self::definitions()[$tool]['inputSchema']['properties'] ?? []));
        $unknown = array_diff(array_map('strval', array_keys($args)), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException($tool . ' does not take ' . implode(', ', $unknown) . '. It takes: ' . implode(', ', $known) . '.');
        }
        return match ($tool) {
            'shop_info' => $this->info($connection),
            'list_products' => $this->listProducts($args),
            'get_product' => $this->getProduct($args, $connection),
            'update_prices' => $this->updatePrices($args, $connection),
            'set_stock' => $this->setStock($args, $connection),
            'create_term' => $this->saveTerm($args, $connection, true),
            'update_term' => $this->saveTerm($args, $connection, false),
            'remove_term' => $this->removeTerm($args, $connection),
            'archive_product' => $this->archiveProduct($args, $connection),
            'delete_product' => $this->deleteProduct($args, $connection),
        };
    }

    /**
     * Prices that move further in one change than the connection allows (percent; 0: no limit): the product's own and each
     * variant's, matched by its combination. A price set where there was none, or taken away, is not a move.
     *
     * @param array<string, mixed> $before custom_fields as they are
     * @param array<string, mixed> $after custom_fields as they would be
     * @return list<string>
     */
    public static function priceProblems(array $before, array $after, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $pairs = [['the price', self::number($before['price'] ?? null), self::number($after['price'] ?? null)]];
        $old = self::variantPrices($before);
        foreach (self::variantPrices($after) as $key => [$label, $price]) {
            if (isset($old[$key])) {
                $pairs[] = ['the price of ' . $label, $old[$key][1], $price];
            }
        }
        $problems = [];
        foreach ($pairs as [$what, $from, $to]) {
            if ($from === null || $to === null || $from <= 0 || $from === $to) {
                continue;
            }
            $move = abs($to - $from) / $from * 100;
            if ($move > $limit + 1e-9) {
                $problems[] = 'Changing ' . $what . ' from ' . self::plain($from) . ' to ' . self::plain($to) . ' moves it by ' . round($move) . '%, more than the ' . $limit
                    . '% this connection may in one change. Check the number; if it is right, change it in steps or ask the site owner to raise the limit in Admin > MCP.';
            }
        }
        return $problems;
    }

    // ---- reading

    /** @param array<string, mixed> $connection @return array<string, mixed> */
    private function info(array $connection): array
    {
        $fields = [];
        foreach ($this->types->editableFields(Shop::TYPE, 'en', $this->defaultLang) as $name => $field) {
            $fields[$name] = McpContent::describeField($field);
        }
        $taxonomies = [];
        foreach ($this->taxonomies->names(Shop::TYPE) as $name) {
            $taxonomy = $this->taxonomies->load($name);
            $terms = [];
            foreach ($taxonomy['terms'] as $term) {
                $id = (string)($term['id'] ?? '');
                if ($id !== '') {
                    $terms[] = ['id' => $id, 'label' => $this->taxonomies->label($name, $id, $this->defaultLang)] + (($term['parent'] ?? '') !== '' ? ['parent' => (string)$term['parent']] : []);
                }
            }
            $taxonomies[] = ['name' => $name, 'title' => $taxonomy['title'], 'terms' => $terms];
        }
        $settings = $this->shopSettings->all();
        $policy = McpStore::policy($connection);
        return [
            'currency' => (string)$settings['currency'],
            'prices_include_vat' => true,
            'vat_rates' => $settings['tax_rates'],
            'product_type' => Shop::TYPE,
            'how' => 'Products are entries of the type "' . Shop::TYPE . '": create_entry and update_entry make and change them, with the fields below (prices, variants, pictures). '
                . 'update_prices changes prices and sales in batches, with a check first; set_stock sets stock. Prices, SKU and variants are one for every language.',
            'fields' => $fields,
            'taxonomies' => $taxonomies,
            'limits' => [
                'price_change_percent' => $policy['price_limit'] ?: 'no limit',
                'shop_changes_per_24h' => $policy['shop_limit'] ?: 'no limit',
                'shop_changes_left' => $policy['shop_limit'] > 0 ? max(0, $policy['shop_limit'] - $this->store->shopChangesToday((int)$connection['id'])) : null,
            ],
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listProducts(array $args): array
    {
        $status = isset($args['status']) ? (string)$args['status'] : null;
        $kind = isset($args['kind']) ? (string)$args['kind'] : null;
        $query = mb_strtolower(trim((string)($args['query'] ?? '')));
        $filter = isset($args['stock']) ? (string)$args['stock'] : null;
        $onSale = isset($args['on_sale']) ? (bool)$args['on_sale'] : null;
        $terms = array_filter([Shop::CATEGORIES => $args['category'] ?? null, Shop::COLLECTIONS => $args['collection'] ?? null], static fn(mixed $v): bool => $v !== null && $v !== '');
        $rows = [];
        foreach ($this->content->getItems(Shop::TYPE, $this->defaultLang, true, false) as $item) {
            $custom = self::custom($item);
            if ($status !== null && self::statusOf($item) !== $status) {
                continue;
            }
            if ($kind !== null && (string)($custom['kind'] ?? 'physical') !== $kind) {
                continue;
            }
            foreach ($terms as $taxonomy => $id) {
                if (!in_array((string)$id, array_map('strval', (array)($item->meta[$taxonomy] ?? [])), true)) {
                    continue 2;
                }
            }
            if ($query !== '' && !str_contains(mb_strtolower((string)($item->meta['title'] ?? '') . ' ' . ($custom['sku'] ?? '')), $query)) {
                continue;
            }
            $row = $this->row($item);
            if ($filter !== null && !in_array($filter, $row['stock_state'], true)) {
                continue;
            }
            if ($onSale !== null && $row['on_sale'] !== $onSale) {
                continue;
            }
            $rows[] = $row;
        }
        $limit = max(1, min(100, (int)($args['limit'] ?? 50)));
        $offset = max(0, (int)($args['offset'] ?? 0));
        return ['total' => count($rows), 'products' => array_map(static function (array $row): array {
            unset($row['stock_state']);
            return $row;
        }, array_slice($rows, $offset, $limit))];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function getProduct(array $args, array $connection): array
    {
        $item = $this->product((string)($args['product'] ?? ''));
        $entry = $this->writer->call('get_entry', ['type' => Shop::TYPE, 'slug' => $item->slug] + (isset($args['lang']) ? ['lang' => (string)$args['lang']] : []), $connection);
        $row = $this->row($item);
        unset($row['stock_state']);
        return ['product' => $row, 'entry' => $entry];
    }

    /**
     * A product as the shop tools give it: its prices, its variants with theirs, and its stock.
     *
     * @return array<string, mixed>
     */
    private function row(ContentItem $item): array
    {
        $custom = self::custom($item);
        $id = (string)($item->meta['translation_id'] ?? '');
        $tracked = !empty($custom['track_stock']);
        $backorder = !empty($custom['allow_backorder']);
        $low = is_numeric($custom['low_stock_at'] ?? null) ? (int)$custom['low_stock_at'] : null;
        $stock = $id !== '' ? $this->stock->forProduct($id) : [];
        $price = self::number($custom['price'] ?? null);
        $was = self::number($custom['compare_at_price'] ?? null);
        $states = [];
        $count = function (string $key) use ($stock, $tracked, $low, &$states): ?array {
            if (!$tracked) {
                return null;
            }
            $onHand = (int)($stock[$key]['on_hand'] ?? 0);
            $reserved = (int)($stock[$key]['reserved'] ?? 0);
            $free = max(0, $onHand - $reserved);
            $states[] = $free === 0 ? 'out' : 'in';
            if ($low !== null && $free <= $low) {
                $states[] = 'low';
            }
            return ['on_hand' => $onHand, 'held_for_orders' => $reserved, 'free' => $free];
        };
        $variants = [];
        $onSale = $was !== null && $price !== null && $was > $price;
        foreach (self::variantRows($custom) as $variant) {
            $own = self::number($variant['price'] ?? null);
            $ownWas = self::number($variant['compare_at_price'] ?? null);
            $effective = $own ?? $price;
            $effectiveWas = $ownWas ?? $was;
            $sale = $effectiveWas !== null && $effective !== null && $effectiveWas > $effective;
            $onSale = $onSale || $sale;
            $variants[] = [
                'variant' => trim((string)$variant['values']),
                'sku' => (string)($variant['sku'] ?? ''),
                'price' => $effective,
                'own_price' => $own !== null,
                'compare_at_price' => $effectiveWas,
                'on_sale' => $sale,
                'stock' => $count(ShopStock::variantKey((string)$variant['values'])),
            ];
        }
        $summary = $variants === [] ? $count('') : null;
        return [
            'product' => $item->slug,
            'title' => (string)($item->meta['title'] ?? $item->slug),
            'status' => self::statusOf($item),
            'kind' => (string)($custom['kind'] ?? 'physical'),
            'sku' => (string)($custom['sku'] ?? ''),
            'price' => $price ?? ShopCatalogue::priceOf($custom),
            'compare_at_price' => $was,
            'on_sale' => $onSale,
            'track_stock' => $tracked,
            'allow_backorder' => $backorder,
            'low_stock_at' => $low,
            'stock' => $summary,
            'variants' => $variants,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', (int)@filemtime($item->filePath) ?: $item->mtime),
            'stock_state' => $tracked && !$backorder ? array_values(array_unique($states)) : ['in'],
        ];
    }

    // ---- prices

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function updatePrices(array $args, array $connection): array
    {
        $dryRun = ($args['dry_run'] ?? true) !== false;
        $changes = $this->changes($args);
        $policy = McpStore::policy($connection);
        $problems = [];
        /** @var array<string, array{item: ContentItem, before: array<string, mixed>, after: array<string, mixed>}> $products */
        $products = [];
        $table = [];
        foreach ($changes as $n => $change) {
            $where = 'Change ' . ($n + 1);
            $item = $this->findProduct((string)($change['product'] ?? ''), $where, $problems);
            if ($item === null) {
                continue;
            }
            $products[$item->slug] ??= ['item' => $item, 'before' => self::custom($item), 'after' => self::custom($item)];
            $after = &$products[$item->slug]['after'];
            $label = $item->slug;
            $target = &$after;
            $variantName = trim((string)($change['variant'] ?? ''));
            if ($variantName !== '') {
                $index = $this->variantIndex($after, $variantName, $where, $problems);
                if ($index === null) {
                    unset($after, $target);
                    continue;
                }
                $label .= ' (' . trim((string)$after['variants'][$index]['values']) . ')';
                $target = &$after['variants'][$index];
            }
            $productPrice = self::number($after['price'] ?? null);
            $current = self::number($target['price'] ?? null) ?? ($variantName !== '' ? $productPrice : null);
            $currentWas = self::number($target['compare_at_price'] ?? null) ?? ($variantName !== '' ? self::number($after['compare_at_price'] ?? null) : null);
            $given = array_intersect_key($change, array_flip(['price', 'compare_at_price', 'sale_price', 'end_sale']));
            if ($given === [] || (isset($given['end_sale']) && $given['end_sale'] !== true)) {
                $problems[] = $where . ' (' . $label . ') changes nothing: give price, compare_at_price, sale_price or end_sale.';
            } elseif ((isset($given['sale_price']) || isset($given['end_sale'])) && count($given) > 1) {
                $problems[] = $where . ' (' . $label . '): sale_price and end_sale go alone, without price or compare_at_price.';
            } elseif (array_key_exists('sale_price', $given)) {
                $sale = $this->money($given['sale_price'], $where . ' sale_price', $problems, false);
                if ($sale !== null) {
                    if ($current === null) {
                        $problems[] = $where . ' (' . $label . ') has no price to put on sale.';
                    } elseif ($sale >= $current) {
                        $problems[] = $where . ' (' . $label . '): sale_price ' . self::plain($sale) . ' must be lower than the price now, ' . self::plain($current) . '.';
                    } else {
                        // The "was" price stays if the product is already on sale; otherwise the price it had becomes it.
                        if ($currentWas === null || $currentWas <= $current) {
                            $target['compare_at_price'] = $current;
                        }
                        $target['price'] = $sale;
                    }
                }
            } elseif (isset($given['end_sale'])) {
                if ($currentWas === null || $current === null || $currentWas <= $current) {
                    $problems[] = $where . ' (' . $label . ') is not on sale.';
                } else {
                    $target['price'] = $currentWas;
                    $target['compare_at_price'] = '';
                }
            } else {
                if (array_key_exists('price', $given)) {
                    $price = $this->money($given['price'], $where . ' price', $problems, $variantName !== '');
                    if ($price !== null || ($variantName !== '' && ($given['price'] === '' || $given['price'] === null))) {
                        $target['price'] = $price ?? '';
                    }
                }
                if (array_key_exists('compare_at_price', $given)) {
                    $was = $this->money($given['compare_at_price'], $where . ' compare_at_price', $problems, true);
                    if ($was !== null || $given['compare_at_price'] === '' || $given['compare_at_price'] === null) {
                        $target['compare_at_price'] = $was ?? '';
                    }
                }
            }
            $newPrice = self::number($target['price'] ?? null) ?? ($variantName !== '' ? self::number($after['price'] ?? null) : null);
            $newWas = self::number($target['compare_at_price'] ?? null) ?? ($variantName !== '' ? self::number($after['compare_at_price'] ?? null) : null);
            $table[] = [
                'product' => $item->slug,
                'title' => (string)($item->meta['title'] ?? $item->slug),
                'variant' => $variantName !== '' ? trim((string)($target['values'] ?? $variantName)) : null,
                'price' => ['from' => $current, 'to' => $newPrice],
                'compare_at_price' => ['from' => $currentWas, 'to' => $newWas],
                'on_sale' => $newWas !== null && $newPrice !== null && $newWas > $newPrice,
            ];
            unset($after, $target);
        }
        foreach ($products as $slug => $product) {
            foreach (self::priceProblems($product['before'], $product['after'], $policy['price_limit']) as $problem) {
                $problems[] = $slug . ': ' . $problem;
            }
        }
        $changed = array_filter($products, static fn(array $p): bool => $p['before'] !== $p['after']);
        $this->withinShopLimit($connection, count($changed), $problems);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        if ($dryRun) {
            return ['dry_run' => true, 'saved' => false, 'products_changed' => count($changed), 'changes' => $table, 'next' => 'Nothing is saved. If this is right, call update_prices again with the same changes and dry_run false.'];
        }
        foreach ($changed as $product) {
            $fields = [];
            foreach (['price', 'compare_at_price', 'variants'] as $key) {
                if (($product['before'][$key] ?? null) !== ($product['after'][$key] ?? null)) {
                    $fields[$key] = $key === 'variants' ? array_values($product['after'][$key]) : ($product['after'][$key] ?? '');
                }
            }
            $this->writer->changeFields($product['item'], $fields, $connection);
        }
        $this->store->recordShopChanges((int)$connection['id'], count($changed));
        return ['dry_run' => false, 'saved' => true, 'products_changed' => count($changed), 'changes' => $table];
    }

    // ---- stock

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function setStock(array $args, array $connection): array
    {
        $dryRun = ($args['dry_run'] ?? false) === true;
        $changes = $this->changes($args);
        $problems = [];
        $warnings = [];
        $plan = [];
        $planned = [];
        foreach ($changes as $n => $change) {
            $where = 'Change ' . ($n + 1);
            $item = $this->findProduct((string)($change['product'] ?? ''), $where, $problems);
            if ($item === null) {
                continue;
            }
            $custom = self::custom($item);
            $id = (string)($item->meta['translation_id'] ?? '');
            $labels = ShopStock::variantLabels($custom);
            $variantName = trim((string)($change['variant'] ?? ''));
            $key = ShopStock::variantKey($variantName);
            if (!array_key_exists($key, $labels)) {
                $problems[] = $where . ' (' . $item->slug . '): ' . ($key === ''
                    ? 'it has variants, so name one: ' . implode(', ', $labels) . '.'
                    : ($labels === ['' => ''] ? 'it has no variants; leave variant out.' : 'it has no variant "' . $variantName . '" (it has: ' . implode(', ', $labels) . ').'));
                continue;
            }
            if (array_key_exists('on_hand', $change) === array_key_exists('adjust', $change)) {
                $problems[] = $where . ' (' . $item->slug . '): give on_hand or adjust, one of them.';
                continue;
            }
            if ($id === '') {
                $problems[] = $where . ' (' . $item->slug . ') has no id yet; save it once with update_entry, then set its stock.';
                continue;
            }
            if (empty($custom['track_stock']) && !isset($warnings[$item->slug])) {
                $warnings[$item->slug] = $item->slug . ' does not track stock, so the count is kept but not used; set track_stock true with update_entry to use it.';
            }
            $before = $planned[$id][$key] ?? (int)($this->stock->forProduct($id)[$key]['on_hand'] ?? 0);
            $after = array_key_exists('on_hand', $change) ? (int)$change['on_hand'] : $before + (int)$change['adjust'];
            $after = max(0, min(ShopStock::MAX, $after));
            $planned[$id][$key] = $after;
            $plan[] = ['item' => $item, 'id' => $id, 'key' => $key, 'labels' => $labels, 'adjust' => array_key_exists('adjust', $change) ? (int)$change['adjust'] : null, 'row' => [
                'product' => $item->slug,
                'variant' => $key !== '' ? $labels[$key] : null,
                'on_hand' => ['from' => $before, 'to' => $after],
            ]];
        }
        $this->withinShopLimit($connection, count($plan), $problems);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        if (!$dryRun) {
            foreach ($plan as $step) {
                if ($step['adjust'] !== null) {
                    $this->stock->adjust($step['id'], $step['key'], $step['adjust']);
                } else {
                    $this->stock->setOnHand($step['id'], [$step['key'] => $step['row']['on_hand']['to']], array_map('strval', array_keys($step['labels'])));
                }
            }
            $this->store->recordShopChanges((int)$connection['id'], count($plan));
            // "Sold out" and what is left show on the pages.
            $this->afterChange();
        }
        return ['dry_run' => $dryRun, 'saved' => !$dryRun, 'changes' => array_column($plan, 'row'), 'warnings' => array_values($warnings)];
    }

    // ---- categories and collections

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function saveTerm(array $args, array $connection, bool $new): array
    {
        $taxonomy = $this->shopTaxonomy($args['taxonomy'] ?? null);
        $terms = $this->taxonomies->load($taxonomy)['terms'];
        $byId = array_column($terms, null, 'id');
        $id = $new ? '' : Slug::plain((string)($args['term'] ?? ''));
        if (!$new && !isset($byId[$id])) {
            throw new \InvalidArgumentException('There is no ' . $taxonomy . ' "' . ($args['term'] ?? '') . '". shop_info lists their ids.');
        }
        $old = $new ? null : $byId[$id];
        $problems = [];
        $warnings = [];
        $row = ['id' => $id, 'slug' => trim((string)($args['slug'] ?? ''))];
        $row['labels'] = $this->perLanguage($args['labels'] ?? null, 'labels', $old['labels'] ?? [], $problems);
        if (trim((string)($row['labels'][$this->defaultLang] ?? '')) === '' && ($new || array_key_exists('labels', $args))) {
            $problems[] = 'labels needs a name in the default language, ' . $this->defaultLang . '.';
        }
        foreach (['description' => 'descriptions', 'seo_title' => 'seo_title', 'seo_description' => 'seo_description'] as $arg => $key) {
            if (array_key_exists($arg, $args)) {
                $row[$key] = $this->perLanguage($args[$arg], $arg, $old[$key] ?? [], $problems);
            }
        }
        if (array_key_exists('parent', $args)) {
            $parent = Slug::plain((string)$args['parent']);
            if ($parent !== '' && !$this->taxonomies->isHierarchical($taxonomy)) {
                $problems[] = $taxonomy . ' has no levels, so a term has no parent.';
            } elseif ($parent !== '' && (!isset($byId[$parent]) || $parent === $id)) {
                $problems[] = 'parent: there is no other ' . $taxonomy . ' "' . $args['parent'] . '".';
            }
            $row['parent'] = $parent;
        }
        if (array_key_exists('image', $args)) {
            $image = trim((string)$args['image']);
            $path = $image === '' ? '' : $this->writer->mediaPath($image);
            if ($path === null) {
                $problems[] = 'image must be a picture of the media library (the path add_image_from_url gave), or "" to take it away.';
            }
            $row['image'] = (string)$path;
        }
        $collection = $taxonomy === Shop::COLLECTIONS;
        foreach (['number', 'tones', 'quote', 'quote_author'] as $only) {
            if (array_key_exists($only, $args) && !$collection) {
                $problems[] = $only . ' is for collections only.';
            }
        }
        if ($collection) {
            if (array_key_exists('number', $args)) {
                $row['number'] = (string)(int)$args['number'];
            }
            if (array_key_exists('tones', $args)) {
                $given = is_array($args['tones']) ? $args['tones'] : [];
                $tones = ($old['tones'] ?? []) + ['bg' => '', 'surface' => '', 'deep' => ''];
                foreach ($given as $key => $hex) {
                    $hex = strtolower(trim((string)$hex));
                    if (!in_array($key, ['bg', 'surface', 'deep'], true) || ($hex !== '' && preg_match('/^#[0-9a-f]{6}$/', $hex) !== 1)) {
                        $problems[] = 'tones.' . $key . ' must be a colour as #rrggbb, or "" for none.';
                    } else {
                        $tones[$key] = $hex;
                    }
                }
                if ($tones['bg'] !== '' && $tones['deep'] !== '' && Contrast::ratio($tones['deep'], $tones['bg']) < 4.5) {
                    $warnings[] = 'tones.deep is hard to read on tones.bg (contrast ' . round(Contrast::ratio($tones['deep'], $tones['bg']), 1) . ':1; 4.5:1 is needed for text). Choose a darker deep or a lighter bg.';
                }
                $row['tones'] = $tones;
            }
            if (array_key_exists('quote', $args)) {
                $row['quote'] = $this->perLanguage($args['quote'], 'quote', $old['quote'] ?? [], $problems);
            }
            if (array_key_exists('quote_author', $args)) {
                $row['quote_author'] = trim((string)$args['quote_author']);
            }
        }
        $this->withinShopLimit($connection, 1, $problems);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $rows = [];
        foreach ($terms as $term) {
            $rows[] = $term['id'] === $id ? $row : ['id' => $term['id'], 'slug' => '', 'labels' => $term['labels']];
        }
        if ($new) {
            $rows[] = $row;
        }
        $before = array_column($terms, 'id');
        $result = $this->applyTerms($taxonomy, $rows, $connection);
        $after = $this->taxonomies->load($taxonomy)['terms'];
        $id = $new ? (array_values(array_diff(array_column($after, 'id'), $before))[0] ?? '') : $id;
        $saved = array_column($after, null, 'id')[$id] ?? null;
        if ($saved === null) {
            throw new \RuntimeException('The term was saved but could not be read back.');
        }
        return [
            'taxonomy' => $taxonomy,
            'term' => array_intersect_key($saved, array_flip(['id', 'slug', 'parent', 'labels', 'descriptions', 'image', 'seo_title', 'seo_description', 'number', 'tones', 'quote', 'quote_author'])),
            'address_changed' => $result['moved'] > 0,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function removeTerm(array $args, array $connection): array
    {
        $taxonomy = $this->shopTaxonomy($args['taxonomy'] ?? null);
        $terms = $this->taxonomies->load($taxonomy)['terms'];
        $id = Slug::plain((string)($args['term'] ?? ''));
        if (!in_array($id, array_column($terms, 'id'), true)) {
            throw new \InvalidArgumentException('There is no ' . $taxonomy . ' "' . ($args['term'] ?? '') . '". shop_info lists their ids.');
        }
        $problems = [];
        $this->withinShopLimit($connection, 1, $problems);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $rows = [];
        foreach ($terms as $term) {
            if ($term['id'] !== $id) {
                $rows[] = ['id' => $term['id'], 'slug' => '', 'labels' => $term['labels']];
            }
        }
        $result = $this->applyTerms($taxonomy, $rows, $connection);
        return ['taxonomy' => $taxonomy, 'removed' => $id, 'taken_off_entries' => $result['unfiled']];
    }

    /** @param list<array<string, mixed>> $rows @param array<string, mixed> $connection @return array{title: string, terms: int, moved: int, removed: int, added: int, unfiled: int, refused: int} */
    private function applyTerms(string $taxonomy, array $rows, array $connection): array
    {
        if ($this->terms === null) {
            throw new \InvalidArgumentException('Categories and collections cannot be changed on this site.');
        }
        $result = $this->terms->apply($taxonomy, [], $this->languages, $this->defaultLang, self::actor($connection), $rows);
        $this->taxonomies->forget();
        $this->store->recordShopChanges((int)$connection['id'], 1);
        $this->afterChange();
        return $result;
    }

    private function shopTaxonomy(mixed $value): string
    {
        $name = (string)$value;
        if (!in_array($name, self::TAXONOMIES, true) || !in_array($name, $this->taxonomies->names(), true)) {
            throw new \InvalidArgumentException('taxonomy must be one of: ' . implode(', ', array_values(array_intersect(self::TAXONOMIES, $this->taxonomies->names()))) . '.');
        }
        return $name;
    }

    /**
     * A text per language, merged into what the term has: a language left out keeps its text.
     *
     * @param array<string, string> $old
     * @param list<string> $problems
     * @return array<string, string>
     */
    private function perLanguage(mixed $value, string $name, array $old, array &$problems): array
    {
        if ($value === null) {
            return $old;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $problems[] = $name . ' must be an object by language, such as {"' . $this->defaultLang . '": "..."}.';
            return $old;
        }
        foreach ($value as $lang => $text) {
            if (!in_array((string)$lang, $this->languages, true)) {
                $problems[] = $name . ': the site has no language "' . $lang . '" (it has: ' . implode(', ', $this->languages) . ').';
                continue;
            }
            $old[(string)$lang] = trim((string)$text);
        }
        return $old;
    }

    // ---- taking products away

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function archiveProduct(array $args, array $connection): array
    {
        $versions = $this->versions($this->product((string)($args['product'] ?? '')));
        $problems = [];
        $this->withinShopLimit($connection, 1, $problems);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $changed = [];
        foreach ($versions as $item) {
            if (self::statusOf($item) !== 'draft') {
                $this->writer->changeFields($item, [], $connection, 'draft');
                $changed[] = $item->lang;
            }
        }
        if ($changed !== []) {
            $this->store->recordShopChanges((int)$connection['id'], 1);
        }
        return ['product' => $versions[0]->slug, 'archived_languages' => $changed, 'already_drafts' => $changed === [] ? true : null, 'kept' => 'its text, history, prices and stock'];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function deleteProduct(array $args, array $connection): array
    {
        if ($this->deleteEntry === null) {
            throw new \InvalidArgumentException('Products cannot be deleted on this site.');
        }
        $versions = $this->versions($this->product((string)($args['product'] ?? '')));
        $redirect = trim((string)($args['redirect_to'] ?? 'shop'));
        [$after, $target] = match ($redirect) {
            'shop', '' => ['archive', ''],
            'home' => ['home', ''],
            'none' => ['', ''],
            default => ['custom', $redirect],
        };
        $problems = [];
        $this->withinShopLimit($connection, 1, $problems);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $deleted = [];
        $sentTo = [];
        foreach ($versions as $item) {
            $result = ($this->deleteEntry)($item->slug, $item->lang, $after, $target, $connection);
            if ($result['location'] === '') {
                $error = (string)($result['view']['error'] ?? '');
                throw new \InvalidArgumentException('redirect_to "' . $redirect . '" ' . ($error === 'target_missing' ? 'is not a page of the site.' : 'cannot be used (' . $error . ').') . ' Give "shop", "home", "none" or an address of the site.'
                    . ($deleted !== [] ? ' Already deleted: ' . implode(', ', $deleted) . '.' : ''));
            }
            $deleted[] = $item->lang;
            parse_str((string)parse_url($result['location'], PHP_URL_QUERY), $query);
            $sentTo[$item->lang] = is_string($query['to'] ?? null) ? $query['to'] : null;
        }
        $this->content->forget();
        $this->store->recordShopChanges((int)$connection['id'], 1);
        $this->afterChange();
        return ['product' => $versions[0]->slug, 'deleted_languages' => $deleted, 'visitors_go_to' => $sentTo, 'note' => in_array(null, $sentTo, true) && $after !== '' ? 'Where visitors go is null: this account may not make redirects, or the product was a draft nobody could visit.' : null, 'restore' => 'A person can bring it back from Admin > History.'];
    }

    /** @return non-empty-list<ContentItem> the product in every language, the default first */
    private function versions(ContentItem $product): array
    {
        $id = (string)($product->meta['translation_id'] ?? '');
        $versions = [$product];
        foreach ($id === '' ? [] : $this->content->getItems(Shop::TYPE, null, true, false) as $item) {
            if ((string)($item->meta['translation_id'] ?? '') === $id && $item->filePath !== $product->filePath) {
                $versions[] = $item;
            }
        }
        return $versions;
    }

    private function afterChange(): void
    {
        if ($this->changed !== null) {
            ($this->changed)();
        }
    }

    /** @param array<string, mixed> $connection */
    private static function actor(array $connection): string
    {
        return (string)$connection['username'] . ' (MCP: ' . $connection['name'] . ')';
    }

    // ---- helpers

    /** @param array<string, mixed> $args @return list<array<string, mixed>> */
    private function changes(array $args): array
    {
        $changes = $args['changes'] ?? null;
        if (!is_array($changes) || $changes === [] || !array_is_list($changes)) {
            throw new \InvalidArgumentException('changes must be a list of at least one change.');
        }
        if (count($changes) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('At most ' . self::MAX_BATCH . ' changes at once; send the rest in another call.');
        }
        foreach ($changes as $n => $change) {
            if (!is_array($change) || array_is_list($change)) {
                throw new \InvalidArgumentException('Change ' . ($n + 1) . ' must be an object with product and what to change.');
            }
        }
        return $changes;
    }

    /** @param array<string, mixed> $connection @param list<string> $problems */
    private function withinShopLimit(array $connection, int $changes, array &$problems): void
    {
        $limit = McpStore::policy($connection)['shop_limit'];
        if ($limit > 0 && $changes > 0) {
            $left = max(0, $limit - $this->store->shopChangesToday((int)$connection['id']));
            if ($changes > $left) {
                $problems[] = 'This would make ' . $changes . ' changes to the shop, and this connection has ' . $left . ' of its ' . $limit . ' in 24 hours left. Send fewer, or try again later.';
            }
        }
    }

    private function product(string $slug): ContentItem
    {
        $problems = [];
        $item = $this->findProduct($slug, 'product', $problems);
        if ($item === null) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        return $item;
    }

    /** @param list<string> $problems */
    private function findProduct(string $slug, string $where, array &$problems): ?ContentItem
    {
        $plain = Slug::plain($slug);
        $item = $plain !== '' ? $this->content->find(Shop::TYPE, $plain, $this->defaultLang, true, false) : null;
        if ($item === null) {
            $problems[] = $where . ': there is no product "' . $slug . '". list_products gives their addresses.';
        }
        return $item;
    }

    /** @param array<string, mixed> $custom @param list<string> $problems */
    private function variantIndex(array $custom, string $name, string $where, array &$problems): ?int
    {
        $key = ShopStock::variantKey($name);
        $names = [];
        foreach (is_array($custom['variants'] ?? null) ? $custom['variants'] : [] as $index => $variant) {
            if (is_array($variant) && ShopStock::variantKey((string)($variant['values'] ?? '')) !== '') {
                if (ShopStock::variantKey((string)$variant['values']) === $key) {
                    return (int)$index;
                }
                $names[] = trim((string)$variant['values']);
            }
        }
        $problems[] = $where . ': ' . ($names === [] ? 'the product has no variants; leave variant out.' : 'there is no variant "' . $name . '" (there are: ' . implode(', ', $names) . ').');
        return null;
    }

    /** A price as given, or null with the reason added (an empty one is null without a problem when `$mayBeEmpty`). @param list<string> $problems */
    private function money(mixed $value, string $what, array &$problems, bool $mayBeEmpty): ?float
    {
        if ($mayBeEmpty && ($value === '' || $value === null)) {
            return null;
        }
        $number = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if ((!is_int($number) && !is_float($number) && !(is_string($number) && is_numeric($number))) || (float)$number < 0 || (float)$number > 1_000_000) {
            $problems[] = $what . ' must be a price such as 12.50, not ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '.';
            return null;
        }
        return round((float)$number, 2);
    }

    /** @param array<string, mixed> $custom @return array<string, array{0: string, 1: ?float}> variant key => [its combination, its own price or the product's] */
    private static function variantPrices(array $custom): array
    {
        $base = self::number($custom['price'] ?? null);
        $prices = [];
        foreach (self::variantRows($custom) as $variant) {
            $prices[ShopStock::variantKey((string)$variant['values'])] = [trim((string)$variant['values']), self::number($variant['price'] ?? null) ?? $base];
        }
        return $prices;
    }

    /** @param array<string, mixed> $custom @return list<array<string, mixed>> */
    private static function variantRows(array $custom): array
    {
        return array_values(array_filter(is_array($custom['variants'] ?? null) ? $custom['variants'] : [], static fn(mixed $v): bool => is_array($v) && ShopStock::variantKey((string)($v['values'] ?? '')) !== ''));
    }

    /** @return array<string, mixed> */
    private static function custom(ContentItem $item): array
    {
        return is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
    }

    private static function statusOf(ContentItem $item): string
    {
        $status = strtolower((string)($item->meta['status'] ?? 'draft'));
        return in_array($status, McpContent::STATUSES, true) ? $status : 'draft';
    }

    private static function number(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        return is_numeric($value) ? round((float)$value, 2) : null;
    }

    private static function plain(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
