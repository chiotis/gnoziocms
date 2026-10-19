<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The templates built with blocks for a list of entries (Admin > Theme > Archive Layouts > Edit template): the archive of a content type
 * and the page of a category, a tag or a taxonomy of the site's own. They are stored in the theme settings as `archive_templates.<key>`,
 * where the key is a content type (`books`) or `taxonomy:<name>` (`taxonomy:categories`). Each holds `use` (whether the list is drawn
 * from the template), `blocks` (the page: the blocks around and including the list), `card` (the blocks that draw one entry of the
 * list) and, for a list of entries of several types (a category), `card_types` (a card for a content type, by its name; the entries of
 * the others are drawn with `card`), all in the shape of the Footer Blocks (a plain list, or a map of `default` and a set for each
 * language, FooterBlocks).
 * Turning the template off (Reset) leaves the blocks where they are. Nothing here knows a content type.
 */
final class ArchiveTemplates
{
    public const KEY = 'archive_templates';

    /** The block that draws the entries of the list. */
    public const LIST_BLOCK = 'entry-list';

    /** Whether a key names a list: a content type, or `taxonomy:<name>`. */
    public static function validKey(string $key): bool
    {
        return preg_match('/^(taxonomy:)?[a-z][a-z0-9_-]*$/', $key) === 1;
    }

    public static function forTaxonomy(string $name): string
    {
        return 'taxonomy:' . $name;
    }

    /** @param array<string, mixed> $themeSettings @return array{use: bool, blocks: mixed, card: mixed, card_types: array<string, mixed>} */
    public static function entry(array $themeSettings, string $key): array
    {
        $stored = is_array($themeSettings[self::KEY] ?? null) ? ($themeSettings[self::KEY][$key] ?? null) : null;
        $stored = is_array($stored) ? $stored : [];
        $types = [];
        foreach (is_array($stored['card_types'] ?? null) ? $stored['card_types'] : [] as $type => $blocks) {
            if (is_string($type) && preg_match('/^[a-z][a-z0-9_-]*$/', $type) === 1 && is_array($blocks) && $blocks !== []) {
                $types[$type] = $blocks;
            }
        }
        return ['use' => ($stored['use'] ?? false) === true, 'blocks' => $stored['blocks'] ?? [], 'card' => $stored['card'] ?? [], 'card_types' => $types];
    }

    /**
     * The card of each content type that has one of its own on this list, in a language: type => blocks.
     *
     * @param array<string, mixed> $themeSettings
     * @return array<string, array<int, mixed>>
     */
    public static function cards(array $themeSettings, string $key, string $lang, string $defaultLang): array
    {
        $cards = [];
        foreach (self::entry($themeSettings, $key)['card_types'] as $type => $stored) {
            $blocks = FooterBlocks::forLanguage($stored, $lang, $defaultLang);
            if ($blocks !== []) {
                $cards[$type] = $blocks;
            }
        }
        return $cards;
    }

    /**
     * The `archive_templates` map after the card of one content type on a list was changed (null or an empty list takes it away).
     *
     * @param array<string, mixed> $themeSettings the settings now
     * @param array<string, array<int, mixed>>|null $sets the sets to keep, by key (FooterBlocks::toStore)
     * @return array<string, array<string, mixed>>
     */
    public static function withTypeCard(array $themeSettings, string $key, string $type, ?array $sets): array
    {
        $all = is_array($themeSettings[self::KEY] ?? null) ? $themeSettings[self::KEY] : [];
        if (!self::validKey($key) || preg_match('/^[a-z][a-z0-9_-]*$/', $type) !== 1) {
            return $all;
        }
        $entry = self::entry($themeSettings, $key);
        $kept = $entry['card_types'];
        $stored = $sets === null ? [] : FooterBlocks::toStore($sets);
        if ($stored === []) {
            unset($kept[$type]);
        } else {
            $kept[$type] = $stored;
        }
        $all[$key] = self::compose($entry['use'], $entry['blocks'], $entry['card'], $kept);
        return $all;
    }

    /** The stored entry of a list. @param array<string, mixed> $cardTypes @return array<string, mixed> */
    private static function compose(bool $use, mixed $blocks, mixed $card, array $cardTypes): array
    {
        $blocks = is_array($blocks) ? $blocks : [];
        $card = is_array($card) ? $card : [];
        return ['use' => $use, 'blocks' => $blocks] + ($card !== [] ? ['card' => $card] : []) + ($cardTypes !== [] ? ['card_types' => $cardTypes] : []);
    }

    /** The blocks of the page in a language (the site's own set when the language has none). @param array<string, mixed> $themeSettings @return array<int, mixed> */
    public static function blocks(array $themeSettings, string $key, string $lang, string $defaultLang): array
    {
        return FooterBlocks::forLanguage(self::entry($themeSettings, $key)['blocks'], $lang, $defaultLang);
    }

    /** The blocks of the card in a language. @param array<string, mixed> $themeSettings @return array<int, mixed> */
    public static function card(array $themeSettings, string $key, string $lang, string $defaultLang): array
    {
        return FooterBlocks::forLanguage(self::entry($themeSettings, $key)['card'], $lang, $defaultLang);
    }

    /** Whether the list is drawn from its template in this language: the template is on and has blocks to draw. @param array<string, mixed> $themeSettings */
    public static function applies(array $themeSettings, string $key, string $lang, string $defaultLang): bool
    {
        return self::validKey($key) && self::entry($themeSettings, $key)['use'] && self::blocks($themeSettings, $key, $lang, $defaultLang) !== [];
    }

    /**
     * The `archive_templates` map to store after a change to one list: `$use` switches the template on or off (null leaves it), `$page`
     * and `$card` replace the sets of blocks (null leaves them; an empty list takes them away). A list left with nothing to keep is dropped.
     *
     * @param array<string, mixed> $themeSettings the settings now
     * @param array<string, array<int, mixed>>|null $page
     * @param array<string, array<int, mixed>>|null $card
     * @return array<string, array<string, mixed>>
     */
    public static function with(array $themeSettings, string $key, ?bool $use, ?array $page, ?array $card = null): array
    {
        $all = is_array($themeSettings[self::KEY] ?? null) ? $themeSettings[self::KEY] : [];
        if (!self::validKey($key)) {
            return $all;
        }
        $entry = self::entry($themeSettings, $key);
        $use ??= $entry['use'];
        $blocks = $page === null ? $entry['blocks'] : FooterBlocks::toStore($page);
        $cards = $card === null ? $entry['card'] : FooterBlocks::toStore($card);
        $blocks = is_array($blocks) ? $blocks : [];
        $cards = is_array($cards) ? $cards : [];
        if ($blocks === [] && $cards === [] && $entry['card_types'] === [] && !$use) {
            unset($all[$key]);
            return $all;
        }
        $all[$key] = self::compose($use, $blocks, $cards, $entry['card_types']);
        return $all;
    }

    /**
     * The theme settings after the Archive Layouts cards were sent: for each list that exists its choice, `built` or anything else for
     * the theme's. A list switched to `built` while it has no blocks gets its starting template, so it does not start from an empty page.
     *
     * @param array<string, mixed> $data the theme settings being stored
     * @param array<string, mixed> $choices `archive_template[<key>]` as sent
     * @param string[] $known the keys of the lists the site has
     * @param (\Closure(string): array{page: array<string, array<int, mixed>>, card: array<string, array<int, mixed>>})|null $starting
     * @return array<string, mixed>
     */
    public static function withChoices(array $data, array $choices, array $known, ?\Closure $starting = null): array
    {
        foreach ($choices as $key => $choice) {
            if (in_array((string)$key, $known, true) && is_string($choice)) {
                $entry = self::entry($data, (string)$key);
                $start = $choice === 'built' && !$entry['use'] && $entry['blocks'] === [] && $starting !== null ? $starting((string)$key) : ['page' => [], 'card' => []];
                $data[self::KEY] = self::with($data, (string)$key, $choice === 'built', $start['page'] === [] ? null : $start['page'], $start['card'] === [] ? null : $start['card']);
            }
        }
        if (($data[self::KEY] ?? []) === []) {
            unset($data[self::KEY]);
        }
        return $data;
    }

    /**
     * The first list block of a page's blocks (at the top, or in a column of a container), as stored: its choices of order and page size
     * replace the archive's own while the page is drawn from the template.
     *
     * @param array<int, mixed> $blocks
     * @return array<string, mixed>|null
     */
    public static function listBlock(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === self::LIST_BLOCK && ($block['hidden'] ?? false) !== true) {
                return $block;
            }
            if (($block['type'] ?? '') === BlockContainer::TYPE) {
                foreach (is_array($block['columns'] ?? null) ? $block['columns'] : [] as $column) {
                    $found = self::listBlock(is_array($column['blocks'] ?? null) ? $column['blocks'] : []);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }
        return null;
    }

    /**
     * The archive settings with what the list block chose: the number of entries on a page and the order (the block says which of the
     * archive's orders; an order the list does not know is ignored).
     *
     * @param array<string, mixed> $settings the archive settings now
     * @param array<string, mixed>|null $block the stored list block
     * @param array<string, string> $orders the orders the list knows (ContentTypes::orderOptions)
     * @return array<string, mixed>
     */
    public static function withListChoices(array $settings, ?array $block, array $orders): array
    {
        if ($block === null) {
            return $settings;
        }
        if (isset($block['per_page']) && is_numeric($block['per_page'])) {
            $settings['per_page'] = max(0, min(60, (int)$block['per_page']));
        }
        $order = (string)($block['order'] ?? '');
        if ($order !== '' && isset($orders[$order])) {
            $settings['order'] = $order;
        }
        if (isset($block['columns']) && in_array((string)$block['columns'], ['2', '3', '4'], true)) {
            $settings['columns'] = (string)$block['columns'];
        }
        // The orders a visitor may choose from (none unless the block lets them), as order => label, in the order the block lists them.
        $settings['sort_choices'] = [];
        if (($block['visitor_sort'] ?? false) === true) {
            $asked = array_column(is_array($block['sort_choices'] ?? null) ? array_filter($block['sort_choices'], 'is_array') : [], 'order');
            $asked = array_values(array_filter(array_map('strval', $asked), static fn(string $order): bool => isset($orders[$order])));
            $asked = $asked !== [] ? $asked : array_values(array_diff(array_keys($orders), ['random']));
            foreach ($asked as $order) {
                $settings['sort_choices'][$order] = $orders[$order];
            }
        }
        // A list drawn by blocks is a grid, a list or a compact list; the map layout is the theme's.
        if (($settings['layout'] ?? '') === 'map') {
            $settings['layout'] = 'cards';
        }
        return $settings;
    }
}
