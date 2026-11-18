<?php

declare(strict_types=1);

namespace GnozioCMS;

use GnozioCMS\Shop\Shop;

/**
 * create_term, update_term and remove_term through MCP: the terms of every taxonomy (categories, tags and the site's own), as
 * Admin > Taxonomies keeps them. The shop's categories and collections need the shop's scopes (shop:write; shop:delete to
 * remove) and the shop on, and count towards the connection's shop changes; every other taxonomy needs terms:write.
 */
final class McpTerms
{
    public function __construct(
        private Taxonomies $taxonomies,
        private TaxonomyEditor $editor,
        private McpContent $writer,
        private McpStore $store,
        private string $defaultLang,
        /** @var string[] */
        private array $languages,
        /** @var \Closure(): bool whether the shop is on */
        private \Closure $shopOn,
        /** @var (\Closure(): void)|null what visitors see changed: the page cache is cleared */
        private ?\Closure $changed = null,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $str = static fn(string $description, ?int $max = null): array => ['type' => 'string', 'description' => $description] + ($max !== null ? ['maxLength' => $max] : []);
        $schema = static fn(array $properties, array $required): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        $perLang = static fn(string $description): array => ['type' => 'object', 'description' => $description . ' By language: {"el": "...", "en": "..."}.', 'additionalProperties' => ['type' => 'string']];
        $taxonomy = $str('The taxonomy, as site_info lists it: categories, tags, the site\'s own, or the shop\'s product-categories and collections.');
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
        $annotations = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
        return [
            'create_term' => [
                'scopes' => [McpOAuth::SCOPE_TERMS, McpOAuth::SCOPE_SHOP_WRITE], 'permission' => 'taxonomies.manage', 'terms' => true, 'title' => 'Add a term',
                'description' => 'Adds a term to a taxonomy: a category, a tag, or a product category or collection of the shop. labels needs at least the default language. '
                    . 'Gives back its id, which terms in create_entry and update_entry take. Check list_terms first, so the same term is not added twice.',
                'inputSchema' => $schema(['taxonomy' => $taxonomy] + $term, ['taxonomy', 'labels']),
                'annotations' => $annotations,
            ],
            'update_term' => [
                'scopes' => [McpOAuth::SCOPE_TERMS, McpOAuth::SCOPE_SHOP_WRITE], 'permission' => 'taxonomies.manage', 'terms' => true, 'title' => 'Change a term',
                'description' => 'Changes what is given and keeps the rest. A language left out of labels or a text keeps what it has.',
                'inputSchema' => $schema(['taxonomy' => $taxonomy, 'term' => $str('Its id (list_terms lists them).')] + $term, ['taxonomy', 'term']),
                'annotations' => $annotations + ['idempotentHint' => true],
            ],
            'remove_term' => [
                'scopes' => [McpOAuth::SCOPE_TERMS, McpOAuth::SCOPE_SHOP_DELETE], 'permission' => 'taxonomies.manage', 'terms' => true, 'title' => 'Remove a term',
                'description' => 'Removes a term, as the admin does: it is taken off the entries filed under it, its terms below move up, and its page is gone.',
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
            'create_term' => $this->save($args, $connection, true),
            'update_term' => $this->save($args, $connection, false),
            'remove_term' => $this->remove($args, $connection),
        };
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function save(array $args, array $connection, bool $new): array
    {
        $taxonomy = $this->taxonomy($args['taxonomy'] ?? null, $connection, false);
        $shop = self::isShop($taxonomy);
        $terms = $this->taxonomies->load($taxonomy)['terms'];
        $byId = array_column($terms, null, 'id');
        $id = $new ? '' : Slug::plain((string)($args['term'] ?? ''));
        if (!$new && !isset($byId[$id])) {
            throw new \InvalidArgumentException('There is no ' . $taxonomy . ' "' . ($args['term'] ?? '') . '". list_terms lists their ids.');
        }
        $old = $new ? null : $byId[$id];
        $problems = [];
        $warnings = [];
        $row = ['id' => $id, 'slug' => trim((string)($args['slug'] ?? ''))];
        $row['labels'] = $this->perLanguage($args['labels'] ?? null, 'labels', $old['labels'] ?? [], $problems);
        if (trim((string)($row['labels'][$this->defaultLang] ?? '')) === '' && ($new || array_key_exists('labels', $args))) {
            $problems[] = 'labels needs a name in the default language, ' . $this->defaultLang . '.';
        }
        if ($new && $problems === []) {
            // The same name twice in one taxonomy is almost always a mistake of the assistant, not the site's wish.
            $name = mb_strtolower(trim((string)$row['labels'][$this->defaultLang]));
            foreach ($terms as $term) {
                if (mb_strtolower(trim((string)($term['labels'][$this->defaultLang] ?? ''))) === $name) {
                    $problems[] = $taxonomy . ' already has "' . $row['labels'][$this->defaultLang] . '" (id ' . $term['id'] . '). Use that id, or update_term to change it.';
                }
            }
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
            } elseif ($parent !== '' && !$new && in_array($parent, $this->taxonomies->descendantIds($taxonomy, $id), true)) {
                $problems[] = 'parent: "' . $args['parent'] . '" is below this term, so it cannot be above it.';
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
        if ($shop) {
            $this->withinShopLimit($connection, $problems);
        }
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
        $result = $this->apply($taxonomy, $rows, $connection);
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
    private function remove(array $args, array $connection): array
    {
        $taxonomy = $this->taxonomy($args['taxonomy'] ?? null, $connection, true);
        $terms = $this->taxonomies->load($taxonomy)['terms'];
        $id = Slug::plain((string)($args['term'] ?? ''));
        if (!in_array($id, array_column($terms, 'id'), true)) {
            throw new \InvalidArgumentException('There is no ' . $taxonomy . ' "' . ($args['term'] ?? '') . '". list_terms lists their ids.');
        }
        $problems = [];
        if (self::isShop($taxonomy)) {
            $this->withinShopLimit($connection, $problems);
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $rows = [];
        foreach ($terms as $term) {
            if ($term['id'] !== $id) {
                $rows[] = ['id' => $term['id'], 'slug' => '', 'labels' => $term['labels']];
            }
        }
        $result = $this->apply($taxonomy, $rows, $connection);
        return ['taxonomy' => $taxonomy, 'removed' => $id, 'taken_off_entries' => $result['unfiled']];
    }

    /** @param list<array<string, mixed>> $rows @param array<string, mixed> $connection @return array<string, mixed> */
    private function apply(string $taxonomy, array $rows, array $connection): array
    {
        $result = $this->editor->apply($taxonomy, [], $this->languages, $this->defaultLang, (string)$connection['username'] . ' (MCP: ' . $connection['name'] . ')', $rows);
        $this->taxonomies->forget();
        if (self::isShop($taxonomy)) {
            $this->store->recordShopChanges((int)$connection['id'], 1);
        }
        if ($this->changed !== null) {
            ($this->changed)();
        }
        return $result;
    }

    /**
     * The taxonomy named, if this connection may change it: the shop's need the shop on and its scopes, the rest terms:write.
     *
     * @param array<string, mixed> $connection
     */
    private function taxonomy(mixed $value, array $connection, bool $removing): string
    {
        $scope = (string)$connection['scope'];
        $shopOn = ($this->shopOn)();
        $shopScope = $removing ? McpOAuth::SCOPE_SHOP_DELETE : McpOAuth::SCOPE_SHOP_WRITE;
        $mayShop = $shopOn && McpOAuth::allows($scope, [$shopScope]);
        $mayOthers = McpOAuth::allows($scope, [McpOAuth::SCOPE_TERMS]);
        $names = $this->taxonomies->names();
        $allowed = array_values(array_filter($names, static fn(string $name): bool => self::isShop($name) ? $mayShop : $mayOthers));
        $name = (string)$value;
        if (!in_array($name, $names, true)) {
            throw new \InvalidArgumentException('There is no taxonomy "' . $name . '". This connection may change: ' . (implode(', ', $allowed) ?: 'none') . '.');
        }
        if (!in_array($name, $allowed, true)) {
            $needs = !self::isShop($name) ? 'the ' . McpOAuth::SCOPE_TERMS . ' scope' : ($shopOn ? 'the ' . $shopScope . ' scope' : 'the shop to be on');
            throw new \InvalidArgumentException('Changing ' . $name . ' needs ' . $needs . '. This connection may change: ' . (implode(', ', $allowed) ?: 'none') . '.');
        }
        return $name;
    }

    private static function isShop(string $taxonomy): bool
    {
        return in_array($taxonomy, McpShop::TAXONOMIES, true);
    }

    /** @param array<string, string> $old @param list<string> $problems @return array<string, string> */
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

    /** @param array<string, mixed> $connection @param list<string> $problems */
    private function withinShopLimit(array $connection, array &$problems): void
    {
        $limit = McpStore::policy($connection)['shop_limit'];
        if ($limit > 0 && $this->store->shopChangesToday((int)$connection['id']) >= $limit) {
            $problems[] = 'This would make 1 change to the shop, and this connection has 0 of its ' . $limit . ' in 24 hours left. Try again later.';
        }
    }
}
