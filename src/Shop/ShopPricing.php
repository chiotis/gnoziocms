<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\ContentItem;

/**
 * What a cart costs, read again from the products every time (docs/shop-plan.md, section 3): each line's name, picture, price
 * (the variant's or the product's), VAT class and what is free of it in stock; then the totals with a coupon, shipping and a
 * fee. Prices have VAT in them; each line's VAT is of what is paid for it after its share of the discount. Lines whose product
 * is gone, or that are no longer sold, leave the cart; a quantity larger than what is free is lowered to it.
 */
final class ShopPricing
{
    /** Kinds sold. */
    public const SOLD_KINDS = ['physical', 'digital', 'subscription'];

    /**
     * @param \Closure(ContentItem): string $url the address of a product
     */
    public function __construct(
        private ShopCatalogue $catalogue,
        private ShopStock $stock,
        private ShopSettings $settings,
        private \Closure $url
    ) {
    }

    /**
     * The cart's lines as the cart and the checkout show them.
     *
     * @param list<array{product_id: string, variant_key: string, quantity: int}> $lines
     * @return array{lines: list<array<string, mixed>>, kept: list<array{product_id: string, variant_key: string, quantity: int}>, changes: list<array{title: string, reason: string}>, count: int, items_cents: int, grams: int}
     */
    public function lines(array $lines, string $lang): array
    {
        $priced = [];
        $kept = [];
        $changes = [];
        foreach ($lines as $line) {
            $product = $this->catalogue->product($line['product_id'], $lang);
            if ($product === null) {
                $changes[] = ['title' => '', 'reason' => 'gone'];
                continue;
            }
            // What is sold, and for how much, is the product in the site's own language (prices, variants and stock are one for every
            // translation); the translation gives the name, the address and the names of the options.
            $base = $this->catalogue->product($line['product_id'], $this->catalogue->defaultLanguage()) ?? $product;
            $custom = is_array($base->meta['custom_fields'] ?? null) ? $base->meta['custom_fields'] : [];
            $own = is_array($product->meta['custom_fields'] ?? null) ? $product->meta['custom_fields'] : [];
            if (isset($own['option_labels'])) {
                $custom['option_labels'] = $own['option_labels'];
            }
            $title = (string)($product->meta['title'] ?? $product->slug);
            $kind = (string)($custom['kind'] ?? 'physical');
            if (!in_array($kind, self::SOLD_KINDS, true)) {
                $changes[] = ['title' => $title, 'reason' => 'not_sold'];
                continue;
            }
            $variant = self::variant($custom, $line['variant_key']);
            if ($variant === false) {
                $changes[] = ['title' => $title, 'reason' => 'gone'];
                continue;
            }
            $unit = ShopMoney::cents($variant['price'] ?? null) ?? ShopMoney::cents($custom['price'] ?? null);
            if ($unit === null) {
                $changes[] = ['title' => $title, 'reason' => 'not_sold'];
                continue;
            }
            // A subscription's first payment is its price and its sign-up fee; every renewal, its price.
            $plan = $kind === 'subscription' ? ShopSubscriptions::plan($custom) + ['price_cents' => $unit] : null;
            if ($plan !== null) {
                $unit += $plan['signup_cents'];
            }
            $tracked = !empty($custom['track_stock']) && empty($custom['allow_backorder']);
            $stockKey = !empty($variant['one_stock']) ? '' : $line['variant_key'];
            $quantity = $line['quantity'];
            $max = null;
            if ($tracked) {
                $max = min(ShopCart::MAX_QUANTITY, $this->stock->available($line['product_id'], $stockKey));
                if ($max < 1) {
                    $changes[] = ['title' => $title, 'reason' => 'sold_out'];
                    continue;
                }
                if ($quantity > $max) {
                    $changes[] = ['title' => $title, 'reason' => 'reduced'];
                    $quantity = $max;
                }
            }
            $card = $this->catalogue->card($product, $lang);
            $class = (string)($custom['tax_class'] ?? 'standard');
            $rate = $this->settings->taxRate($class);
            $names = ShopCatalogue::optionNames((string)($custom['option_labels'] ?? ''));
            $label = $variant === null ? '' : implode(' / ', array_map(static fn(string $part): string => $names[mb_strtolower(trim($part))] ?? trim($part), explode('/', (string)$variant['values'])));
            $digital = $kind === 'digital' || $kind === 'subscription';
            $grams = $digital ? 0 : (int)(is_numeric($variant['weight_g'] ?? null) ? $variant['weight_g'] : ($custom['weight_g'] ?? 0));
            $kept[] = ['product_id' => $line['product_id'], 'variant_key' => $line['variant_key'], 'quantity' => $quantity];
            $priced[] = [
                'key' => ShopCart::key($line['product_id'], $line['variant_key']),
                'product_id' => $line['product_id'],
                'variant_key' => $line['variant_key'],
                'stock_key' => $stockKey,
                'title' => $title,
                'variant' => $label,
                'sku' => (string)(($variant['sku'] ?? '') ?: ($custom['sku'] ?? '')),
                'url' => ($this->url)($product),
                'image' => (string)(($variant['image'] ?? '') ?: $card['image']),
                'quantity' => $quantity,
                'max' => $max,
                'unit_cents' => $unit,
                'total_cents' => $unit * $quantity,
                'tax_class' => $class,
                'tax_rate' => $rate,
                'tracked' => $tracked,
                'grams' => max(0, $grams) * $quantity,
                'collections' => array_values(array_map('strval', (array)($base->meta[Shop::COLLECTIONS] ?? []))),
                'gift_wrap' => !$digital && !empty($custom['gift_wrap']),
                // The id the product feed and the Meta Pixel know it by.
                'content_id' => ShopMarketing::contentId($line['product_id'], ShopMarketing::hasVariants($custom) ? $line['variant_key'] : ''),
                'digital' => $digital,
                'subscription' => $plan,
            ];
        }
        return [
            'lines' => $priced,
            'kept' => $kept,
            'changes' => $changes,
            'count' => array_sum(array_column($priced, 'quantity')),
            'items_cents' => array_sum(array_column($priced, 'total_cents')),
            'grams' => array_sum(array_column($priced, 'grams')),
            // Nothing to send: a cart of downloads only has no shipping.
            'digital_only' => $priced !== [] && array_filter($priced, static fn(array $l): bool => !$l['digital']) === [],
            'subscriptions' => array_values(array_filter($priced, static fn(array $l): bool => $l['subscription'] !== null)) !== [],
        ];
    }

    /**
     * The order's totals. VAT is that of each line after its share of the discount, and the standard rate's on shipping and fees.
     *
     * @param list<array<string, mixed>> $lines priced lines
     * @param array{ok: bool, discount: int, free_shipping: bool, per_line: array<int, int>}|null $coupon ShopCoupons::apply()
     * @return array{items: int, discount: int, shipping: int, fee: int, tax: int, total: int, line_tax: array<int, int>, line_discount: array<int, int>}
     */
    public function totals(array $lines, ?array $coupon, int $shipping = 0, int $fee = 0): array
    {
        $ok = $coupon !== null && $coupon['ok'];
        $discounts = $ok ? $coupon['per_line'] : [];
        $taxes = [];
        $items = 0;
        foreach ($lines as $i => $line) {
            $items += (int)$line['total_cents'];
            $paid = (int)$line['total_cents'] - (int)($discounts[$i] ?? 0);
            $taxes[$i] = ShopMoney::vatIn($paid, (float)$line['tax_rate']);
        }
        $discount = $ok ? (int)$coupon['discount'] : 0;
        if ($ok && $coupon['free_shipping']) {
            $shipping = 0;
        }
        $standard = $this->settings->taxRate('standard');
        $tax = array_sum($taxes) + ShopMoney::vatIn($shipping, $standard) + ShopMoney::vatIn($fee, $standard);
        return [
            'items' => $items,
            'discount' => $discount,
            'shipping' => $shipping,
            'fee' => $fee,
            'tax' => $tax,
            'total' => max(0, $items - $discount) + $shipping + $fee,
            'line_tax' => $taxes,
            'line_discount' => array_map('intval', $discounts),
        ];
    }

    /**
     * The variant of a line: null for a product without variants (and an empty key), false when the product has variants and
     * none is this one (or it has none and a key was given).
     *
     * @param array<string, mixed> $custom
     * @return array<string, mixed>|null|false
     */
    private static function variant(array $custom, string $key): array|null|false
    {
        $variants = array_values(array_filter(is_array($custom['variants'] ?? null) ? $custom['variants'] : [], static fn(mixed $v): bool => is_array($v) && ShopStock::variantKey((string)($v['values'] ?? '')) !== ''));
        if ($variants === []) {
            // Options with no variants: any combination of them can be chosen, at the product's price and from its one stock.
            if ($key === '') {
                return null;
            }
            foreach (self::combinations($custom) as $combination) {
                if (ShopStock::variantKey($combination) === $key) {
                    return ['values' => $combination, 'one_stock' => true];
                }
            }
            return false;
        }
        foreach ($variants as $variant) {
            if (ShopStock::variantKey((string)$variant['values']) === $key) {
                return $variant;
            }
        }
        return false;
    }

    /**
     * Every combination of a product's options, as a variant writes it ("Gold / M").
     *
     * @param array<string, mixed> $custom
     * @return list<string>
     */
    private static function combinations(array $custom): array
    {
        $all = [''];
        foreach (is_array($custom['options'] ?? null) ? $custom['options'] : [] as $option) {
            $values = is_array($option) ? array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($option['values'] ?? '')) ?: []), static fn(string $v): bool => $v !== '')) : [];
            if ($values === [] || trim((string)($option['name'] ?? '')) === '') {
                continue;
            }
            $next = [];
            foreach ($all as $start) {
                foreach ($values as $value) {
                    $next[] = $start === '' ? $value : $start . ' / ' . $value;
                }
            }
            $all = $next;
        }
        return array_values(array_filter($all, static fn(string $c): bool => $c !== ''));
    }
}
