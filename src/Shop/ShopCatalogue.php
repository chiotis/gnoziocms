<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\ContentItem;
use GnozioCMS\ContentRepository;
use GnozioCMS\ContentTypes;
use GnozioCMS\Slug;
use GnozioCMS\Taxonomies;

/**
 * The products as the shop blocks and the product page show them (docs/shop-plan.md, sections 2 and 9): which products a
 * Product grid lists, and for one product its price, its options and variants, its pictures, its collection with the
 * collection's tones, and whether it can be bought. Prices are read from the product's file; stock from ShopStock.
 */
final class ShopCatalogue
{
    public const ORDERS = ['date_desc', 'date_asc', 'title_asc', 'price_asc', 'price_desc'];
    public const MAX_LIMIT = 48;

    /** @var array<string, array<string, ContentItem>> products by translation_id, per language */
    private array $byId = [];

    /**
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(): ContentTypes $types
     * @param \Closure(): string $defaultLanguage
     */
    public function __construct(
        private ContentRepository $content,
        private \Closure $taxonomies,
        private \Closure $types,
        private ShopStock $stock,
        private \Closure $defaultLanguage,
        private bool $includeHidden = false
    ) {
    }

    /**
     * Fills a shop block's values with what it shows (the 'shop' provider of BlockRenderer). Nothing to show: `_empty`.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context the page's (item, lang, …)
     * @return array<string, mixed>
     */
    public function blockData(string $type, array $values, array $context): array
    {
        $lang = (string)($context['lang'] ?? '') ?: ($this->defaultLanguage)();
        $item = ($context['item'] ?? null) instanceof ContentItem ? $context['item'] : null;
        $product = $item !== null && $item->type === Shop::TYPE ? $item : null;
        if ($type === 'product-grid') {
            $products = $this->products($values, $lang, $product);
            $values['products'] = array_map(fn(ContentItem $p): array => $this->card($p, $lang), $products);
            $values['_empty'] = $values['products'] === [];
            return $values;
        }
        if ($type === 'shop-the-look') {
            // The look chosen in the block; otherwise the page's own look, or the first look of the page's product.
            $look = $this->lookById((string)($values['look'] ?? ''), $lang);
            if ($look === null && $item !== null && $item->type === Shop::LOOKS) {
                $look = $item;
            }
            if ($look === null && $product !== null) {
                $ids = array_map('strval', (array)($this->custom($product)['looks'] ?? []));
                $look = $ids === [] ? null : $this->lookById($ids[0], $lang);
            }
            $values['look'] = $look !== null ? $this->look($look, $lang, $product) : null;
            $values['_empty'] = $values['look'] === null || $values['look']['pieces'] === [];
            // Drawn with the Image hotspots block's styles and script.
            $values['_types'] = ['image-hotspots'];
            return $values;
        }
        if ($type === 'looks') {
            $limit = max(1, min(24, (int)($values['limit'] ?? 6)));
            $looks = array_slice($this->content->getItems(Shop::LOOKS, $lang, $this->includeHidden, true), 0, $limit);
            $values['looks'] = array_values(array_filter(array_map(fn(ContentItem $l): array => $this->look($l, $lang), $looks), static fn(array $l): bool => $l['pieces'] !== []));
            $values['_empty'] = $values['looks'] === [];
            return $values;
        }
        // The other shop blocks draw the product of the page they are on (a template built with blocks for products).
        if ($product === null) {
            $values['_empty'] = true;
            return $values;
        }
        $values['product'] = $this->details($product, $lang);
        if ($type === 'product-price') {
            $values['_empty'] = $values['product']['price_text'] === '';
        } elseif ($type === 'product-gallery') {
            $values['_empty'] = $values['product']['images'] === [];
        } elseif ($type === 'product-details') {
            $values['_empty'] = $values['product']['details'] === [];
            // Drawn with the Accordion block's styles and script.
            $values['_types'] = ['accordion'];
        }
        return $values;
    }

    /**
     * The products a Product grid lists.
     *
     * @param array<string, mixed> $values the block's
     * @return ContentItem[]
     */
    public function products(array $values, string $lang, ?ContentItem $current = null): array
    {
        $source = (string)($values['source'] ?? 'all');
        $limit = max(1, min(self::MAX_LIMIT, (int)($values['limit'] ?? 8)));
        $items = $this->content->getItems(Shop::TYPE, $lang, $this->includeHidden, false);
        $term = Slug::plain((string)($values['term'] ?? ''));
        if ($source === 'collection' || $source === 'category') {
            $taxonomy = $source === 'collection' ? Shop::COLLECTIONS : Shop::CATEGORIES;
            $id = $term !== '' ? $this->termId($taxonomy, $term) : '';
            if ($id === '') {
                return [];
            }
            $items = array_filter($items, static fn(ContentItem $p): bool => in_array($id, self::terms($p, $taxonomy), true));
        } elseif ($source === 'picked') {
            $wanted = array_values(array_filter(array_map(static fn(string $line): string => Slug::plain(trim($line)), preg_split('/[\r\n,]+/', (string)($values['picked'] ?? '')) ?: [])));
            $bySlug = [];
            foreach ($items as $p) {
                $bySlug[$p->slug] = $p;
            }
            return array_slice(array_values(array_filter(array_map(static fn(string $slug): ?ContentItem => $bySlug[$slug] ?? null, $wanted))), 0, $limit);
        } elseif ($source === 'related' || $source === 'complete') {
            if ($current === null) {
                return [];
            }
            if ($source === 'complete') {
                $ids = array_map('strval', (array)($this->custom($current)['complete_the_look'] ?? []));
                return array_slice(array_values(array_filter(array_map(fn(string $id): ?ContentItem => $this->byTranslationId($id, $lang), $ids))), 0, $limit);
            }
            $mine = self::terms($current, Shop::COLLECTIONS);
            $items = array_filter($items, static fn(ContentItem $p): bool => $p->slug !== $current->slug && array_intersect($mine, self::terms($p, Shop::COLLECTIONS)) !== []);
        }
        $items = array_values($items);
        $order = in_array((string)($values['order'] ?? ''), self::ORDERS, true) ? (string)$values['order'] : 'date_desc';
        $title = static fn(ContentItem $p): string => mb_strtolower((string)($p->meta['title'] ?? $p->slug));
        $price = fn(ContentItem $p): float => self::priceOf($this->custom($p)) ?? INF;
        match ($order) {
            'date_asc' => $items = array_reverse($items),
            'title_asc' => usort($items, static fn(ContentItem $a, ContentItem $b): int => strcmp($title($a), $title($b))),
            'price_asc' => usort($items, static fn(ContentItem $a, ContentItem $b): int => $price($a) <=> $price($b)),
            'price_desc' => usort($items, static fn(ContentItem $a, ContentItem $b): int => ($price($b) === INF ? -INF : $price($b)) <=> ($price($a) === INF ? -INF : $price($a))),
            default => null,
        };
        return array_slice($items, 0, $limit);
    }

    /**
     * A product as a card shows it.
     *
     * @return array<string, mixed>
     */
    public function card(ContentItem $product, string $lang): array
    {
        $custom = $this->custom($product);
        $price = self::priceOf($custom);
        $was = self::number($custom['compare_at_price'] ?? null);
        $images = $this->images($product, $lang);
        return [
            'item' => $product,
            'title' => (string)($product->meta['title'] ?? $product->slug),
            'image' => (string)($product->meta['main_image'] ?? '') ?: ($images[0] ?? ''),
            'hover_image' => (string)($custom['hover_image'] ?? ''),
            'price' => $price,
            'price_text' => $price === null ? '' : self::money($price, $lang),
            'was_text' => $was !== null && $price !== null && $was > $price ? self::money($was, $lang) : '',
            'from' => self::variesInPrice($custom),
            'collection' => $this->collection($product, $lang),
            'available' => $this->available($product),
        ];
    }

    /**
     * A product as its page shows it: the card plus its pictures, options, variants and details.
     *
     * @return array<string, mixed>
     */
    public function details(ContentItem $product, string $lang): array
    {
        $custom = $this->custom($product);
        $names = self::optionNames((string)($custom['option_labels'] ?? ''));
        $translate = static fn(string $text): string => $names[mb_strtolower($text)] ?? $text;
        $options = [];
        foreach (is_array($custom['options'] ?? null) ? $custom['options'] : [] as $i => $option) {
            $name = is_array($option) ? trim((string)($option['name'] ?? '')) : '';
            $choices = [];
            foreach (preg_split('/\R/u', is_array($option) ? (string)($option['values'] ?? '') : '') ?: [] as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $choices[] = ['value' => $value, 'label' => $translate($value)];
                }
            }
            if ($name !== '' && $choices !== []) {
                $options[] = ['index' => count($options), 'name' => $name, 'label' => $translate($name), 'choices' => $choices];
            }
        }
        $id = (string)($product->meta['translation_id'] ?? '');
        $tracked = !empty($custom['track_stock']);
        $backorder = !empty($custom['allow_backorder']);
        $stock = $tracked && $id !== '' ? $this->stock->forProduct($id) : [];
        $base = self::priceOf($custom);
        $variants = [];
        foreach (is_array($custom['variants'] ?? null) ? $custom['variants'] : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $key = ShopStock::variantKey((string)($variant['values'] ?? ''));
            if ($key === '') {
                continue;
            }
            $price = self::number($variant['price'] ?? null) ?? $base;
            $free = isset($stock[$key]) ? max(0, $stock[$key]['on_hand'] - $stock[$key]['reserved']) : 0;
            $variants[] = [
                'key' => $key,
                'values' => array_map('trim', explode('/', (string)$variant['values'])),
                'sku' => (string)($variant['sku'] ?? ''),
                'price' => $price,
                'price_text' => $price === null ? '' : self::money($price, $lang),
                'image' => (string)($variant['image'] ?? ''),
                'available' => !$tracked || $backorder || $free > 0,
            ];
        }
        $details = [];
        foreach (is_array($custom['details'] ?? null) ? $custom['details'] : [] as $row) {
            $title = is_array($row) ? trim((string)($row['title'] ?? '')) : '';
            $text = is_array($row) ? trim((string)($row['text'] ?? '')) : '';
            if ($title !== '' && $text !== '') {
                $details[] = ['title' => $title, 'text' => $text];
            }
        }
        return $this->card($product, $lang) + [
            'id' => $id,
            'kind' => (string)($custom['kind'] ?? 'physical'),
            'sku' => (string)($custom['sku'] ?? ''),
            'barcode' => (string)($custom['barcode'] ?? ''),
            'brand' => (string)($custom['brand'] ?? ''),
            'short_description' => trim((string)($custom['short_description'] ?? '')),
            'images' => $this->images($product, $lang),
            'options' => $options,
            'variants' => $variants,
            'gift_wrap' => !empty($custom['gift_wrap']),
            'tracked' => $tracked,
            'details' => $details,
        ];
    }

    /** Whether a product can be bought now: not tracked, sold when out of stock, or with something free of it or of a variant. */
    public function available(ContentItem $product): bool
    {
        $custom = $this->custom($product);
        if (empty($custom['track_stock']) || !empty($custom['allow_backorder'])) {
            return true;
        }
        $id = (string)($product->meta['translation_id'] ?? '');
        foreach ($id === '' ? [] : $this->stock->forProduct($id) as $row) {
            if ($row['on_hand'] - $row['reserved'] > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The first collection of a product, with its tones: what the card's dot and the product page's colours come from.
     *
     * @return array{id: string, slug: string, label: string, number: int, tones: array{bg: string, surface: string, deep: string}}|null
     */
    public function collection(ContentItem $product, string $lang): ?array
    {
        $id = self::terms($product, Shop::COLLECTIONS)[0] ?? '';
        if ($id === '') {
            return null;
        }
        $taxonomies = ($this->taxonomies)();
        foreach ($taxonomies->load(Shop::COLLECTIONS)['terms'] as $term) {
            if ($term['id'] === $id) {
                return ['id' => $id, 'slug' => $term['slug'], 'label' => $taxonomies->label(Shop::COLLECTIONS, $id, $lang), 'number' => (int)($term['number'] ?? 0), 'tones' => $term['tones'] ?? Taxonomies::tonesChoice(null)];
            }
        }
        return null;
    }

    /**
     * The CSS of a collection's tones (`--c-bg`, `--c-surface`, `--c-deep`), for a style attribute; '' when it has none.
     *
     * @param array{bg?: string, surface?: string, deep?: string}|null $tones
     */
    public static function toneStyle(?array $tones): string
    {
        $css = [];
        foreach (['bg', 'surface', 'deep'] as $key) {
            $hex = (string)($tones[$key] ?? '');
            if (preg_match('/^#[0-9a-f]{6}$/', $hex) === 1) {
                $css[] = '--c-' . $key . ':' . $hex;
            }
        }
        return implode(';', $css);
    }

    /** A price as the shop writes it: €145, or €145,50 in Greek and €145.50 in English. */
    public static function money(float $amount, string $lang): string
    {
        $whole = abs($amount - round($amount)) < 0.005;
        return '€' . number_format($amount, $whole ? 0 : 2, $lang === 'en' ? '.' : ',', $lang === 'en' ? ',' : '.');
    }

    /** @param array<string, mixed> $custom */
    public static function priceOf(array $custom): ?float
    {
        $price = self::number($custom['price'] ?? null);
        if ($price !== null) {
            return $price;
        }
        // A product priced only by its variants starts at the lowest of them.
        $prices = array_filter(array_map(static fn(mixed $v): ?float => is_array($v) ? self::number($v['price'] ?? null) : null, is_array($custom['variants'] ?? null) ? $custom['variants'] : []), static fn(?float $p): bool => $p !== null);
        return $prices === [] ? null : (float)min($prices);
    }

    /**
     * "Finish = Φινίρισμα" lines as a map from the name in the site's language (lower case) to the name in this one.
     *
     * @return array<string, string>
     */
    public static function optionNames(string $lines): array
    {
        $names = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $line) {
            [$from, $to] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            if ($from !== '' && $to !== '') {
                $names[mb_strtolower($from)] = $to;
            }
        }
        return $names;
    }

    /** @param array<string, mixed> $custom whether its variants have prices of their own that differ (a card then says "from") */
    private static function variesInPrice(array $custom): bool
    {
        $base = self::number($custom['price'] ?? null);
        $prices = [];
        foreach (is_array($custom['variants'] ?? null) ? $custom['variants'] : [] as $variant) {
            $prices[] = (is_array($variant) ? self::number($variant['price'] ?? null) : null) ?? $base;
        }
        return count(array_unique(array_map('strval', array_filter($prices, static fn(?float $p): bool => $p !== null)))) > 1;
    }

    private static function number(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        return is_numeric($value) && (float)$value >= 0 ? (float)$value : null;
    }

    /** @return string[] the ids of a product's terms in a taxonomy */
    private static function terms(ContentItem $product, string $taxonomy): array
    {
        return array_values(array_map('strval', (array)($product->meta[$taxonomy] ?? [])));
    }

    /** @return array<string, mixed> */
    private function custom(ContentItem $product): array
    {
        return is_array($product->meta['custom_fields'] ?? null) ? $product->meta['custom_fields'] : [];
    }

    /** @return list<string> the product's pictures: its gallery, or its main image when it has none */
    private function images(ContentItem $product, string $lang): array
    {
        $images = ($this->types)()->gallery(Shop::TYPE, $product->meta, 'gallery', $lang, ($this->defaultLanguage)());
        $images = array_values(array_filter(array_map(static fn(mixed $url): string => is_string($url) ? $url : (is_array($url) ? (string)($url['image'] ?? $url['url'] ?? '') : ''), $images)));
        $main = (string)($product->meta['main_image'] ?? '');
        return $images === [] && $main !== '' ? [$main] : $images;
    }

    /** The id of a term given by its slug (or its id). */
    private function termId(string $taxonomy, string $slug): string
    {
        foreach (($this->taxonomies)()->load($taxonomy)['terms'] as $term) {
            if ($term['slug'] === $slug || $term['id'] === $slug) {
                return $term['id'];
            }
        }
        return '';
    }

    /**
     * A look as its blocks show it: its picture, and each piece with its place on the picture, its card and the variant
     * Add the look puts in the cart (the first that can be bought); the total of what can be bought. `$skip` is a product
     * left out (the product of the page, in Complete the look).
     *
     * @return array{item: ContentItem, title: string, image: string, pieces: list<array<string, mixed>>, count: int, total: float, total_text: string}
     */
    public function look(ContentItem $look, string $lang, ?ContentItem $skip = null): array
    {
        $pieces = [];
        $total = 0.0;
        foreach (is_array($this->custom($look)['pieces'] ?? null) ? $this->custom($look)['pieces'] : [] as $piece) {
            $id = is_array($piece) ? (string)(is_array($piece['product'] ?? null) ? ($piece['product'][0] ?? '') : ($piece['product'] ?? '')) : '';
            $product = $this->product($id, $lang);
            if ($product === null || ($skip !== null && (string)($skip->meta['translation_id'] ?? '') === $id)) {
                continue;
            }
            $details = $this->details($product, $lang);
            $variant = '';
            foreach ($details['variants'] as $v) {
                if ($v['available']) {
                    $variant = $v['key'];
                    break;
                }
            }
            $buyable = $details['kind'] === 'physical' && $details['price'] !== null && $details['available'] && ($details['variants'] === [] || $variant !== '');
            // The price of what Add the look puts in the cart: the variant's own, when it has one.
            $price = $details['variants'] !== [] && $variant !== '' ? ($this->variantPrice($details['variants'], $variant) ?? $details['price']) : $details['price'];
            if ($buyable && $price !== null) {
                $total += (float)$price;
            }
            $pieces[] = [
                'id' => $id,
                'variant' => $variant,
                'buyable' => $buyable,
                'price_text' => $price !== null ? self::money((float)$price, $lang) : '',
                'x' => max(0, min(100, (float)($piece['x'] ?? 50))),
                'y' => max(0, min(100, (float)($piece['y'] ?? 50))),
                'card' => $details,
            ];
        }
        return [
            'item' => $look,
            'title' => (string)($look->meta['title'] ?? $look->slug),
            'image' => (string)($look->meta['main_image'] ?? ''),
            'pieces' => $pieces,
            'count' => count($pieces),
            'total' => $total,
            'total_text' => $total > 0 ? self::money($total, $lang) : '',
        ];
    }

    /** @param list<array<string, mixed>> $variants */
    private function variantPrice(array $variants, string $key): ?float
    {
        foreach ($variants as $variant) {
            if ($variant['key'] === $key) {
                return $variant['price'];
            }
        }
        return null;
    }

    private function lookById(string $id, string $lang): ?ContentItem
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->content->getItems(Shop::LOOKS, $lang, $this->includeHidden, true) as $look) {
            if ((string)($look->meta['translation_id'] ?? '') === $id || $look->slug === $id) {
                return $look;
            }
        }
        return null;
    }

    public function defaultLanguage(): string
    {
        return ($this->defaultLanguage)();
    }

    /** A product by its translation_id, in a language, or in the site's language when it has no translation in that one. */
    public function product(string $id, string $lang): ?ContentItem
    {
        if ($id === '') {
            return null;
        }
        return $this->byTranslationId($id, $lang) ?? $this->byTranslationId($id, ($this->defaultLanguage)());
    }

    private function byTranslationId(string $id, string $lang): ?ContentItem
    {
        if (!isset($this->byId[$lang])) {
            $this->byId[$lang] = [];
            foreach ($this->content->getItems(Shop::TYPE, $lang, $this->includeHidden, true) as $p) {
                $this->byId[$lang][(string)($p->meta['translation_id'] ?? '')] = $p;
            }
        }
        return $this->byId[$lang][$id] ?? null;
    }
}
