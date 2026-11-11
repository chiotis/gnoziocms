<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\ContentItem;
use GnozioCMS\Feed;

/**
 * The product feed (/feeds/products.xml, ?lang= for another language): the shop's physical products as Meta Commerce Manager and
 * Google Merchant Center read them, in Google's RSS 2.0 format, which both take. Each variant is an item of its own, grouped under
 * its product; a product with only options is one item. Its ids are those the Pixel's events use (ShopMarketing::contentId), so
 * Meta can match a visit or a sale to the catalogue. Meta and Google fetch it on their own schedule; nothing is pushed.
 */
final class ShopFeed
{
    /** Option names that are one of the attributes Google and Meta know, in English and Greek. */
    private const ATTRIBUTES = [
        'color' => ['color', 'colour', 'χρώμα', 'χρωμα'],
        'size' => ['size', 'μέγεθος', 'μεγεθος', 'νούμερο'],
        'material' => ['material', 'υλικό', 'υλικο', 'μέταλλο', 'μεταλλο'],
    ];

    /**
     * @param \Closure(string): list<ContentItem> $products the published products of a language
     * @param \Closure(ContentItem): string $url a product's address, from the site's root
     */
    public function __construct(
        private ShopCatalogue $catalogue,
        private ShopSettings $settings,
        private \Closure $products,
        private \Closure $url,
        private string $siteUrl,
        private string $siteTitle
    ) {
    }

    public function xml(string $lang): string
    {
        $shop = $this->settings->all();
        $m = $shop['marketing'];
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('rss');
        $w->writeAttribute('version', '2.0');
        $w->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $w->startElement('channel');
        $w->writeElement('title', $shop['store_name'] !== '' ? $shop['store_name'] : $this->siteTitle);
        $w->writeElement('link', rtrim($this->siteUrl, '/') . '/');
        $w->writeElement('description', 'Products');
        foreach ($this->items($lang) as $item) {
            if (!$m['out_of_stock'] && $item['availability'] !== 'in stock') {
                continue;
            }
            $w->startElement('item');
            foreach ($item as $key => $value) {
                foreach ((array)$value as $one) {
                    if ($one !== '') {
                        $w->writeElement('g:' . $key, (string)$one);
                    }
                }
            }
            $w->endElement();
        }
        $w->endElement();
        $w->endElement();
        $w->endDocument();
        return $w->outputMemory();
    }

    /**
     * The feed's items: one for a product, or one for each of its variants.
     *
     * @return list<array<string, string|list<string>>>
     */
    public function items(string $lang): array
    {
        $shop = $this->settings->all();
        $m = $shop['marketing'];
        $currency = (string)$shop['currency'];
        $out = [];
        foreach (($this->products)($lang) as $product) {
            $d = $this->catalogue->details($product, $lang);
            if ($d['kind'] !== 'physical' || $d['id'] === '' || $d['price'] === null) {
                continue;
            }
            $custom = is_array($product->meta['custom_fields'] ?? null) ? $product->meta['custom_fields'] : [];
            $was = is_numeric($custom['compare_at_price'] ?? null) ? (float)$custom['compare_at_price'] : null;
            $images = array_values(array_unique(array_filter(array_merge([$d['image']], $d['images']))));
            $text = $d['short_description'] !== '' ? $d['short_description'] : trim((string)($product->meta['excerpt'] ?? ''));
            $text = $text !== '' ? $text : Feed::plainText($product->html);
            $base = [
                'id' => $d['id'],
                'title' => mb_substr($d['title'], 0, 150),
                'description' => mb_substr($text !== '' ? $text : $d['title'], 0, 5000),
                'link' => $this->absolute(($this->url)($product)),
                'image_link' => $images !== [] ? $this->absolute($images[0]) : '',
                'additional_image_link' => array_map($this->absolute(...), array_slice($images, 1, 10)),
                'availability' => $d['available'] ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'brand' => mb_substr($d['brand'] !== '' ? $d['brand'] : ($m['brand'] !== '' ? $m['brand'] : ($shop['store_name'] !== '' ? $shop['store_name'] : $this->siteTitle)), 0, 70),
                'gtin' => preg_match('/^\d{8,14}$/', $d['barcode']) === 1 ? $d['barcode'] : '',
                'mpn' => mb_substr($d['sku'], 0, 70),
                'google_product_category' => $m['category'],
                'product_type' => $d['collection']['label'] ?? '',
            ];
            if ($base['gtin'] === '' && $base['mpn'] === '') {
                $base['identifier_exists'] = 'no';
            }
            if ($d['variants'] === []) {
                $out[] = $base + $this->price((float)$d['price'], $was, $currency);
                continue;
            }
            $attributes = [];
            foreach ($d['options'] as $i => $option) {
                foreach (self::ATTRIBUTES as $attribute => $names) {
                    if (in_array(mb_strtolower($option['name']), $names, true)) {
                        $attributes[$i] = $attribute;
                    }
                }
            }
            foreach ($d['variants'] as $variant) {
                $item = array_replace($base, [
                    'id' => ShopMarketing::contentId($d['id'], $variant['key']),
                    'item_group_id' => $d['id'],
                    'title' => mb_substr($d['title'] . ' - ' . implode(' / ', $variant['values']), 0, 150),
                    'availability' => $variant['available'] ? 'in stock' : 'out of stock',
                    'mpn' => mb_substr($variant['sku'] !== '' ? $variant['sku'] : $d['sku'], 0, 70),
                ]);
                if ($variant['image'] !== '') {
                    $item['image_link'] = $this->absolute($variant['image']);
                }
                foreach ($attributes as $i => $attribute) {
                    $item[$attribute] = (string)($variant['values'][$i] ?? '');
                }
                $out[] = $item + $this->price((float)($variant['price'] ?? $d['price']), $variant['was'] ?? null, $currency);
            }
        }
        return $out;
    }

    /** @return array<string, string> the price, and the reduced one when the "was" price is higher */
    private function price(float $price, ?float $was, string $currency): array
    {
        $money = static fn(float $amount): string => number_format($amount, 2, '.', '') . ' ' . $currency;
        return $was !== null && $was > $price ? ['price' => $money($was), 'sale_price' => $money($price)] : ['price' => $money($price)];
    }

    private function absolute(string $path): string
    {
        return preg_match('#^https?://#', $path) === 1 ? $path : rtrim($this->siteUrl, '/') . '/' . ltrim($path, '/');
    }
}
