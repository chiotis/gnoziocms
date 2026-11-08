<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Reads a WooCommerce shop for the import (docs/shop-plan.md, section 10): its products with their variations and its product
 * categories, all in one shape whichever way they are read.
 *
 *  - With the shop's REST keys (WooCommerce > Settings > Advanced > REST API, read only): /wp-json/wc/v3, which has everything,
 *    stock quantities, weights and whether a product is a download among it. The keys travel in the query over https and are
 *    never written anywhere.
 *  - Without them: the public Store API (/wp-json/wc/store/v1), which shows what a shopper sees: no stock quantities (only in
 *    stock or not), no weights, no drafts.
 */
final class WooCommerceReader
{
    /** @param \Closure(string): (array{status: int, body: string}|null) $get */
    public function __construct(private string $site, private \Closure $get, private string $key = '', private string $secret = '')
    {
        $this->site = rtrim($site, '/');
    }

    public function full(): bool
    {
        return $this->key !== '' && $this->secret !== '';
    }

    public function site(): string
    {
        return $this->site;
    }

    /**
     * @return list<array{id: int, slug: string, name: string, parent: int, description: string, image: string, link: string}>
     */
    public function categories(): array
    {
        $out = [];
        foreach ($this->all($this->full() ? '/wp-json/wc/v3/products/categories' : '/wp-json/wc/store/v1/products/categories') as $row) {
            $image = $row['image'] ?? null;
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'slug' => rawurldecode((string)($row['slug'] ?? '')),
                'name' => html_entity_decode((string)($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'parent' => (int)($row['parent'] ?? 0),
                'description' => (string)($row['description'] ?? ''),
                'image' => is_array($image) ? (string)($image['src'] ?? '') : '',
                'link' => (string)($row['permalink'] ?? $row['link'] ?? ''),
            ];
        }
        return array_values(array_filter($out, static fn(array $c): bool => $c['id'] > 0 && $c['slug'] !== ''));
    }

    /**
     * Every product, published or not (drafts only with the keys).
     *
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        $out = [];
        foreach ($this->all($this->full() ? '/wp-json/wc/v3/products?status=any' : '/wp-json/wc/store/v1/products') as $row) {
            $out[] = $this->full() ? $this->fromV3($row) : $this->fromStore($row);
        }
        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function fromV3(array $row): array
    {
        $id = (int)($row['id'] ?? 0);
        $options = [];
        foreach (is_array($row['attributes'] ?? null) ? $row['attributes'] : [] as $attribute) {
            if (!empty($attribute['variation'])) {
                $options[] = ['name' => self::text($attribute['name'] ?? ''), 'values' => array_map([self::class, 'text'], is_array($attribute['options'] ?? null) ? $attribute['options'] : [])];
            }
        }
        $variations = [];
        if (($row['type'] ?? '') === 'variable') {
            foreach ($this->all('/wp-json/wc/v3/products/' . $id . '/variations') as $v) {
                $values = [];
                foreach (is_array($v['attributes'] ?? null) ? $v['attributes'] : [] as $a) {
                    $values[self::text($a['name'] ?? '')] = self::text($a['option'] ?? '');
                }
                $variations[] = [
                    'values' => $values,
                    'sku' => (string)($v['sku'] ?? ''),
                    'regular' => self::cents($v['regular_price'] ?? null),
                    'sale' => self::cents($v['sale_price'] ?? null),
                    'stock' => !empty($v['manage_stock']) && is_numeric($v['stock_quantity'] ?? null) ? (int)$v['stock_quantity'] : null,
                    'image' => is_array($v['image'] ?? null) ? (string)($v['image']['src'] ?? '') : '',
                    'grams' => self::grams($v['weight'] ?? null),
                ];
            }
        }
        return [
            'id' => $id,
            'slug' => rawurldecode((string)($row['slug'] ?? '')),
            'name' => self::text($row['name'] ?? ''),
            'status' => (string)($row['status'] ?? 'publish'),
            'type' => (string)($row['type'] ?? 'simple'),
            'sku' => (string)($row['sku'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'short' => (string)($row['short_description'] ?? ''),
            'regular' => self::cents($row['regular_price'] ?? null),
            'sale' => self::cents($row['sale_price'] ?? null),
            'images' => self::images($row['images'] ?? []),
            'categories' => array_values(array_map(static fn(array $c): int => (int)($c['id'] ?? 0), array_filter(is_array($row['categories'] ?? null) ? $row['categories'] : [], 'is_array'))),
            'options' => $options,
            'variations' => $variations,
            'manage_stock' => !empty($row['manage_stock']),
            'stock' => !empty($row['manage_stock']) && is_numeric($row['stock_quantity'] ?? null) ? (int)$row['stock_quantity'] : null,
            'backorders' => in_array((string)($row['backorders'] ?? 'no'), ['notify', 'yes'], true),
            'grams' => self::grams($row['weight'] ?? null),
            'digital' => !empty($row['downloadable']) && !empty($row['virtual']),
            'link' => (string)($row['permalink'] ?? ''),
            'date' => substr((string)($row['date_created'] ?? ''), 0, 10),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function fromStore(array $row): array
    {
        $prices = is_array($row['prices'] ?? null) ? $row['prices'] : [];
        $minor = (int)($prices['currency_minor_unit'] ?? 2);
        $money = static function (mixed $value) use ($minor): ?int {
            if (!is_numeric($value) || (string)$value === '') {
                return null;
            }
            return (int)round((int)$value * (10 ** (2 - $minor)));
        };
        $regular = $money($prices['regular_price'] ?? null);
        $sale = $money($prices['sale_price'] ?? null);
        $options = [];
        foreach (is_array($row['attributes'] ?? null) ? $row['attributes'] : [] as $attribute) {
            if (!empty($attribute['has_variations'])) {
                $options[] = ['name' => self::text($attribute['name'] ?? ''), 'values' => array_map(static fn(array $t): string => self::text($t['name'] ?? ''), array_filter(is_array($attribute['terms'] ?? null) ? $attribute['terms'] : [], 'is_array'))];
            }
        }
        $variations = [];
        foreach (is_array($row['variations'] ?? null) ? $row['variations'] : [] as $v) {
            $values = [];
            foreach (is_array($v['attributes'] ?? null) ? $v['attributes'] : [] as $a) {
                $values[self::text($a['name'] ?? '')] = self::optionName($options, self::text($a['name'] ?? ''), (string)($a['value'] ?? ''));
            }
            $one = $this->json('/wp-json/wc/store/v1/products/' . (int)($v['id'] ?? 0));
            $vp = is_array($one['prices'] ?? null) ? $one['prices'] : [];
            $variations[] = [
                'values' => $values,
                'sku' => (string)($one['sku'] ?? ''),
                'regular' => $money($vp['regular_price'] ?? null),
                'sale' => ($vp['sale_price'] ?? null) !== ($vp['regular_price'] ?? null) ? $money($vp['sale_price'] ?? null) : null,
                'stock' => null,
                'image' => (string)(self::images($one['images'] ?? [])[0]['src'] ?? ''),
                'grams' => null,
            ];
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'slug' => rawurldecode((string)($row['slug'] ?? '')),
            'name' => self::text($row['name'] ?? ''),
            'status' => 'publish',
            'type' => (string)($row['type'] ?? 'simple'),
            'sku' => (string)($row['sku'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'short' => (string)($row['short_description'] ?? ''),
            'regular' => $regular,
            'sale' => $sale !== $regular ? $sale : null,
            'images' => self::images($row['images'] ?? []),
            'categories' => array_values(array_map(static fn(array $c): int => (int)($c['id'] ?? 0), array_filter(is_array($row['categories'] ?? null) ? $row['categories'] : [], 'is_array'))),
            'options' => $options,
            'variations' => $variations,
            'manage_stock' => false,
            'stock' => null,
            'backorders' => false,
            'grams' => null,
            'digital' => false,
            'link' => (string)($row['permalink'] ?? ''),
            'date' => '',
        ];
    }

    /** The name of an attribute's term, from the slug the Store API gives a variation. */
    private static function optionName(array $options, string $attribute, string $slug): string
    {
        foreach ($options as $option) {
            if ($option['name'] === $attribute) {
                foreach ($option['values'] as $value) {
                    if (Slug::plain($value) === Slug::plain($slug) || mb_strtolower($value) === mb_strtolower($slug)) {
                        return $value;
                    }
                }
            }
        }
        return $slug;
    }

    /** @return list<array<string, mixed>> every page of a list */
    private function all(string $path): array
    {
        $rows = [];
        for ($page = 1; $page <= 200; $page++) {
            $batch = $this->json($path . (str_contains($path, '?') ? '&' : '?') . 'per_page=100&page=' . $page);
            if (!is_array($batch) || $batch === [] || !array_is_list($batch)) {
                break;
            }
            array_push($rows, ...array_filter($batch, 'is_array'));
            if (count($batch) < 100) {
                break;
            }
        }
        return $rows;
    }

    /** @return array<mixed>|null */
    private function json(string $path): ?array
    {
        $url = $this->site . $path;
        if ($this->full() && str_contains($path, '/wc/v3/')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(['consumer_key' => $this->key, 'consumer_secret' => $this->secret]);
        }
        $answer = ($this->get)($url);
        if ($answer === null || $answer['status'] !== 200) {
            if ($answer !== null && in_array($answer['status'], [401, 403], true) && $this->full()) {
                throw new \RuntimeException('The shop refused the REST keys (' . $answer['status'] . '). Check they are read keys of an admin.');
            }
            return null;
        }
        $data = json_decode($answer['body'], true);
        return is_array($data) ? $data : null;
    }

    /** @return list<array{src: string, alt: string}> */
    private static function images(mixed $images): array
    {
        $out = [];
        foreach (is_array($images) ? $images : [] as $image) {
            if (is_array($image) && (string)($image['src'] ?? '') !== '') {
                $out[] = ['src' => (string)$image['src'], 'alt' => self::text($image['alt'] ?? '')];
            }
        }
        return $out;
    }

    private static function cents(mixed $value): ?int
    {
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        return is_numeric($value) && (float)$value >= 0 ? (int)round((float)$value * 100) : null;
    }

    /** WooCommerce keeps weights in the shop's unit, kilograms by default. */
    private static function grams(mixed $kilos): ?int
    {
        $kilos = is_string($kilos) ? str_replace(',', '.', trim($kilos)) : $kilos;
        return is_numeric($kilos) && (float)$kilos > 0 ? (int)round((float)$kilos * 1000) : null;
    }

    public static function text(mixed $value): string
    {
        return trim(html_entity_decode(strip_tags(is_scalar($value) ? (string)$value : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
