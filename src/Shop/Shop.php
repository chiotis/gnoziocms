<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * Whether the shop is on, the one check every part of it asks (docs/shop-plan.md, section 1). The shop is on when the catalogue
 * type `products` is on: Admin > Content types switches it, and with it the Shop settings, the shop blocks and, later, the cart.
 */
final class Shop
{
    /** The content type whose being on turns the shop on. */
    public const TYPE = 'products';
    /** The type of the looks (a picture, its products and their places on it) that comes with the shop. */
    public const LOOKS = 'looks';
    /** The taxonomies the shop brings: the collections (with their tones) and the kinds of product. */
    public const COLLECTIONS = 'collections';
    public const CATEGORIES = 'product-categories';
    /** The features a block can ask for (`requires: shop` in its block.yaml). */
    public const FEATURES = ['shop'];

    /** @param string[] $types the content types that are on (ContentRepository::getTypes()) */
    public static function enabled(array $types): bool
    {
        return in_array(self::TYPE, $types, true);
    }

    /**
     * Writes the shop's two taxonomies when the shop is switched on and they do not exist yet: Collections (whose terms have
     * a chapter number, tones and a quote) and Product categories, both only for products. Terms are the site's own to add.
     */
    public static function ensureTaxonomies(\GnozioCMS\Taxonomies $taxonomies): void
    {
        $existing = $taxonomies->names();
        foreach ([self::COLLECTIONS => 'Collections', self::CATEGORIES => 'Product categories'] as $name => $title) {
            if (!in_array($name, $existing, true)) {
                $taxonomies->save($name, $title, [], null, false, [self::TYPE]);
            }
        }
    }

    /**
     * The features that are on, by name, for the blocks that ask for one.
     *
     * @param string[] $types the content types that are on
     * @return array<string, bool>
     */
    public static function features(array $types): array
    {
        return ['shop' => self::enabled($types)];
    }
}
