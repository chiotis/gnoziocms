<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What a template needs to draw a map of content: the data of a set of entries (for the archive layout and the Map block), the
 * data of the page of one point, route or business (its own place or line and the places near it), the entries near one, and the
 * profile of a route's height. It joins GeoMap (what an entry is on a map) to the site (its content, labels and addresses).
 */
final class GeoView
{
    /** The tiles a map draws when the site has not chosen others. */
    public const DEFAULT_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    public const DEFAULT_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    /**
     * @param \Closure(string, string): array<int, ContentItem> $items entries of a type in a language: (type, lang)
     * @param \Closure(string, string): string $typeLabel the plural name of a type: (type, lang)
     * @param \Closure(string, string): string $termLabel the name of a category: (slug, lang)
     * @param \Closure(ContentItem, string): string $itemUrl the address of an entry's page: (item, lang)
     * @param \Closure(): array<int, string> $placeTypes the types whose entries can have a place
     * @param array{tiles_url: string, attribution: string} $maps the tiles the site chose
     * @param (\Closure(string[], string[]|null, string): array<int, array{name: string, label: string, tree: bool, terms: array<int, array{id: string, label: string, depth: int, parents: string[]}>}>)|null $facets
     *        the taxonomies the bar of a map offers, with their terms (MapFilters::facets): types, what the list chose (null: as each type
     *        says), language. Without it a map offers its categories only, as a menu.
     */
    public function __construct(
        private GeoMap $geo,
        private \Closure $items,
        private \Closure $typeLabel,
        private \Closure $termLabel,
        private \Closure $itemUrl,
        private \Closure $placeTypes,
        private array $maps,
        private ?GeoCache $cache = null,
        private ?\Closure $facets = null
    ) {
    }

    public function map(): GeoMap
    {
        return $this->geo;
    }

    /** The types whose entries can be put on a map. @return array<int, string> */
    public function placeTypes(): array
    {
        return ($this->placeTypes)();
    }

    /**
     * Entries of any types, gathered for a map.
     *
     * @param array<int, string> $types
     * @return array<int, ContentItem>
     */
    public function gather(array $types, string $lang, string $term = ''): array
    {
        $found = [];
        foreach ($types as $type) {
            foreach (($this->items)($type, $lang) as $item) {
                if ($term !== '' && !in_array($term, array_map('strval', (array)($item->meta['categories'] ?? [])), true)) {
                    continue;
                }
                $found[] = $item;
            }
        }
        return $found;
    }

    /**
     * The data of a map of entries. Entries with no place are left out. `lines` draws a route as a line too. `filters` names the
     * taxonomies the bar offers (null or missing: as the card of each type chose); each entry carries its terms of them (`tax`, a term
     * under a tree with its parents), and `facets` lists each taxonomy with the terms the entries on the map have and how many have
     * each. A taxonomy with fewer than two such terms is left out.
     *
     * @param array<int, ContentItem> $items
     * @param array{lines?: bool, cluster?: bool, current?: string, limit?: int, filters?: string[]|null} $options
     * @return array<string, mixed>
     */
    public function dataset(array $items, string $lang, array $options = []): array
    {
        $identities = array_map(self::identity(...), $items);
        if ($this->cache === null || in_array(null, $identities, true)) {
            return $this->makeDataset($items, $lang, $options);
        }
        return $this->cache->remember('data', [$lang, $options, $identities], fn(): array => $this->makeDataset($items, $lang, $options));
    }

    /**
     * The map of the entries of some types in a language (and under a category), as the Map block shows them. Kept as a whole, so a
     * visit does not even gather the entries.
     *
     * @param array<int, string> $types
     * @param array{lines?: bool, cluster?: bool, current?: string, limit?: int, filters?: string[]|null} $options
     * @return array<string, mixed>
     */
    public function collection(array $types, string $lang, string $term = '', array $options = []): array
    {
        $make = fn(): array => $this->makeDataset($this->gather($types, $lang, $term), $lang, $options);
        return $this->cache === null ? $make() : $this->cache->remember('collection', [$types, $lang, $term, $options], $make);
    }

    /**
     * What identifies an entry as it was saved: its address and the file it was read from, with that file's time. Null for an entry that
     * is not a saved file (the preview of an unsaved one), which nothing is kept for.
     */
    private static function identity(ContentItem $item): ?string
    {
        return $item->filePath === '' || $item->mtime <= 0 ? null : $item->type . '|' . $item->slug . '|' . $item->lang . '|' . $item->mtime . '|' . md5($item->filePath);
    }

    /** @param array<int, ContentItem> $items @param array<string, mixed> $options @return array<string, mixed> */
    private function makeDataset(array $items, string $lang, array $options): array
    {
        $features = [];
        $placed = [];
        $types = [];
        $cats = [];
        foreach ($items as $item) {
            if (count($features) >= ($options['limit'] ?? 800)) {
                break;
            }
            $terms = [];
            foreach (Format::list($item->meta['categories'] ?? null) as $slug) {
                $slug = Slug::plain((string)$slug);
                if ($slug !== '') {
                    $terms[] = ['slug' => $slug, 'label' => ($this->termLabel)($slug, $lang)];
                }
            }
            $kind = $types[$item->type]['label'] ?? ($this->typeLabel)($item->type, $lang);
            $feature = $this->geo->feature($item, [
                'type_label' => $kind,
                'url' => ($this->itemUrl)($item, $lang),
                'terms' => $terms,
                'lines' => $options['lines'] ?? true,
            ]);
            if ($feature === null) {
                continue;
            }
            $features[] = $feature;
            $placed[] = $item;
            $types[$item->type] ??= ['id' => $item->type, 'label' => $kind, 'count' => 0];
            $types[$item->type]['count']++;
            foreach ($terms as $term) {
                $cats[$term['slug']] ??= ['slug' => $term['slug'], 'label' => $term['label'], 'count' => 0];
                $cats[$term['slug']]['count']++;
            }
        }
        usort($cats, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['label']), mb_strtolower($b['label'])));
        // The taxonomies of the bar, for the types of the entries on the map (only types with a place, whose definitions the cache's
        // stamp holds): each entry gets its terms of them, a term of a tree with its parents, and each term how many entries have it.
        $facets = $this->facets !== null && ($options['facets'] ?? true) ? ($this->facets)(array_keys($types), $options['filters'] ?? null, $lang) : null;
        $counts = [];
        foreach ($facets ?? [] as $facet) {
            $under = [];
            foreach ($facet['terms'] as $term) {
                $under[$term['id']] = array_merge([$term['id']], $term['parents']);
            }
            foreach ($placed as $i => $item) {
                $ids = [];
                foreach (Format::list($item->meta[$facet['name']] ?? null) as $id) {
                    $ids = array_merge($ids, $under[(string)$id] ?? []);
                }
                foreach ($ids = array_values(array_unique($ids)) as $id) {
                    $counts[$facet['name']][$id] = ($counts[$facet['name']][$id] ?? 0) + 1;
                }
                if ($ids !== []) {
                    $features[$i]['tax'][$facet['name']] = $ids;
                }
            }
        }
        $data = [
            'items' => $features,
            'types' => array_values($types),
            'cats' => array_values($cats),
            'tiles' => $this->tiles(),
            'cluster' => $options['cluster'] ?? true,
            'current' => $options['current'] ?? '',
        ];
        if ($facets !== null) {
            $data['facets'] = self::offered($facets, $counts);
        }
        return $data;
    }

    /**
     * The taxonomies the bar offers: each with the terms some entry on the map has, how many have each, a tree in its order (with each
     * term's depth) and the others by name. One with fewer than two such terms filters nothing and is left out.
     *
     * @param array<int, array{name: string, label: string, tree: bool, terms: array<int, array{id: string, label: string, depth: int, parents: string[]}>}> $facets
     * @param array<string, array<string, int>> $counts taxonomy => term => entries
     * @return array<int, array{name: string, label: string, tree: bool, options: array<int, array{id: string, label: string, depth: int, count: int}>}>
     */
    private static function offered(array $facets, array $counts): array
    {
        $offered = [];
        foreach ($facets as $facet) {
            $options = [];
            foreach ($facet['terms'] as $term) {
                $count = $counts[$facet['name']][$term['id']] ?? 0;
                if ($count > 0) {
                    $options[] = ['id' => $term['id'], 'label' => $term['label'] !== '' ? $term['label'] : $term['id'], 'depth' => $term['depth'], 'count' => $count];
                }
            }
            if (!$facet['tree']) {
                usort($options, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['label']), mb_strtolower($b['label'])));
            }
            if (count($options) >= 2) {
                $offered[] = ['name' => $facet['name'], 'label' => $facet['label'], 'tree' => $facet['tree'], 'options' => $options];
            }
        }
        return $offered;
    }

    /**
     * The map of the page of one entry: its own place (or its route, drawn whole, with the places the file marks) and the entries
     * near it, as the types and the distance ask.
     *
     * @param array{types?: array<int, string>, radius?: float, limit?: int|array<string, int>} $options
     * @return array<string, mixed>
     */
    public function single(ContentItem $item, string $lang, array $options = []): array
    {
        $identity = self::identity($item);
        if ($this->cache === null || $identity === null) {
            return $this->makeSingle($item, $lang, $options);
        }
        return $this->cache->remember('single', [$identity, $lang, $options], fn(): array => $this->makeSingle($item, $lang, $options));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function makeSingle(ContentItem $item, string $lang, array $options): array
    {
        $near = $this->nearby($item, $options['types'] ?? [], (float)($options['radius'] ?? 5), $options['limit'] ?? 12, $lang);
        $set = [$item];
        foreach ($near as $entry) {
            $set[] = $entry['item'];
        }
        $data = $this->makeDataset($set, $lang, ['lines' => false, 'cluster' => false, 'current' => $item->type . '/' . $item->slug, 'facets' => false]);
        $track = $this->geo->track($item);
        if ($track !== null) {
            $data['route'] = [
                'lines' => array_map(static fn(array $line): array => array_map(static fn(array $p): array => [(float)$p[0], (float)$p[1]], $line), $track['lines']),
                'waypoints' => $track['waypoints'],
                'loop' => (bool)($track['stats']['loop'] ?? false),
            ];
        }
        return $data;
    }

    /**
     * The entries of some types near one, nearest first, each with its distance in kilometres.
     *
     * @param array<int, string> $types
     * @return array<int, array{item: ContentItem, km: float}>
     */
    public function nearby(ContentItem $item, array $types, float $radiusKm, int|array $limit, string $lang): array
    {
        $types = array_values(array_filter($types, static fn(string $t): bool => preg_match('/^[a-z][a-z0-9_-]*$/', $t) === 1));
        if ($types === []) {
            return [];
        }
        $radius = max(0.1, min(100.0, $radiusKm));
        $limit = is_array($limit) ? array_map(static fn($n): int => max(0, min(50, (int)$n)), $limit) : max(0, min(50, $limit));
        $identity = self::identity($item);
        if ($this->cache === null || $identity === null) {
            return $this->geo->nearby($item, $this->gather($types, $lang), $radius, $limit);
        }
        // Only the addresses and distances are kept; the entries themselves are the ones the content has now.
        $kept = $this->cache->remember('near', [$identity, $types, $radius, $limit, $lang], fn(): array => array_map(
            static fn(array $found): array => [$found['item']->type, $found['item']->slug, $found['km']],
            $this->geo->nearby($item, $this->gather($types, $lang), $radius, $limit)
        ));
        $entries = [];
        foreach (array_values(array_unique(array_column($kept, 0))) as $type) {
            foreach (($this->items)($type, $lang) as $candidate) {
                $entries[$type . '|' . $candidate->slug] = $candidate;
            }
        }
        $near = [];
        foreach ($kept as [$type, $slug, $km]) {
            if (!isset($entries[$type . '|' . $slug])) {
                // Cannot happen while the stamp is right; if it does, work it out again rather than show less.
                return $this->geo->nearby($item, $this->gather($types, $lang), $radius, $limit);
            }
            $near[] = ['item' => $entries[$type . '|' . $slug], 'km' => $km];
        }
        return $near;
    }

    /** The settings of the tiles every map draws. @return array{url: string, attribution: string, max_zoom: int} */
    public function tiles(): array
    {
        $url = trim($this->maps['tiles_url']);
        $custom = $url !== '' && preg_match('#^https://[^\s"\'<>]+\{z\}[^\s"\'<>]*\{x\}[^\s"\'<>]*\{y\}[^\s"\'<>]*$#', $url) === 1;
        return [
            'url' => $custom ? $url : self::DEFAULT_TILES,
            'attribution' => $custom && trim($this->maps['attribution']) !== '' ? self::cleanAttribution($this->maps['attribution']) : self::DEFAULT_ATTRIBUTION,
            'max_zoom' => 19,
        ];
    }

    /** An attribution may hold plain text and links only (it goes into the map's corner as HTML): every link is an http(s) one, and every link is closed. */
    public static function cleanAttribution(string $text): string
    {
        $text = mb_substr(strip_tags($text, '<a>'), 0, 300);
        $parts = preg_split('#(<a\b[^>]*>|</a\s*>)#i', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        $open = false;
        foreach ($parts as $part) {
            if (preg_match('#^<a\b#i', $part)) {
                if (!$open && preg_match('#href=["\'](https?://[^"\'\s<>]+)["\']#i', $part, $m)) {
                    $out .= '<a href="' . htmlspecialchars($m[1], ENT_QUOTES) . '" rel="noopener">';
                    $open = true;
                }
            } elseif (preg_match('#^</a#i', $part)) {
                if ($open) {
                    $out .= '</a>';
                    $open = false;
                }
            } else {
                $out .= $part;
            }
        }
        return $out . ($open ? '</a>' : '');
    }

    /**
     * The profile of a route's height as a small drawing. It carries the facts in its label, so the line does not have to be seen
     * to be understood.
     *
     * @param array<int, array{0: float, 1: int}> $profile [[kilometres, metres], …]
     */
    public static function profileSvg(array $profile, string $label): string
    {
        $count = count($profile);
        if ($count < 2) {
            return '';
        }
        $width = 640.0;
        $height = 150.0;
        $left = 4.0;
        $top = 10.0;
        $bottom = 18.0;
        $length = max(0.001, (float)$profile[$count - 1][0]);
        $heights = array_column($profile, 1);
        $min = min($heights);
        $max = max($heights);
        $span = max(20, $max - $min);
        $floor = $min - $span * 0.08;
        $ceiling = $max + $span * 0.08;
        $x = static fn(float $km): float => $left + ($width - 2 * $left) * $km / $length;
        $y = static fn(float $m): float => $top + ($height - $top - $bottom) * (1 - ($m - $floor) / ($ceiling - $floor));
        $line = '';
        foreach ($profile as $i => $p) {
            $line .= ($i === 0 ? 'M' : 'L') . round($x((float)$p[0]), 1) . ' ' . round($y((float)$p[1]), 1);
        }
        $area = $line . 'L' . round($x($length), 1) . ' ' . ($height - $bottom) . 'L' . round($x(0), 1) . ' ' . ($height - $bottom) . 'Z';
        $label = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<svg class="route-profile" viewBox="0 0 ' . (int)$width . ' ' . (int)$height . '" role="img" aria-label="' . $label . '" preserveAspectRatio="none">'
            . '<path class="route-profile-area" d="' . $area . '"/>'
            . '<path class="route-profile-line" d="' . $line . '" vector-effect="non-scaling-stroke"/>'
            . '</svg>';
    }
}
