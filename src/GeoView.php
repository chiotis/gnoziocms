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
     */
    public function __construct(
        private GeoMap $geo,
        private \Closure $items,
        private \Closure $typeLabel,
        private \Closure $termLabel,
        private \Closure $itemUrl,
        private \Closure $placeTypes,
        private array $maps
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
     * The data of a map of entries. Entries with no place are left out. `lines` draws a route as a line too.
     *
     * @param array<int, ContentItem> $items
     * @param array{lines?: bool, cluster?: bool, current?: string, limit?: int} $options
     * @return array<string, mixed>
     */
    public function dataset(array $items, string $lang, array $options = []): array
    {
        $features = [];
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
            $types[$item->type] ??= ['id' => $item->type, 'label' => $kind, 'count' => 0];
            $types[$item->type]['count']++;
            foreach ($terms as $term) {
                $cats[$term['slug']] ??= ['slug' => $term['slug'], 'label' => $term['label'], 'count' => 0];
                $cats[$term['slug']]['count']++;
            }
        }
        usort($cats, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['label']), mb_strtolower($b['label'])));
        return [
            'items' => $features,
            'types' => array_values($types),
            'cats' => array_values($cats),
            'tiles' => $this->tiles(),
            'cluster' => $options['cluster'] ?? true,
            'current' => $options['current'] ?? '',
        ];
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
        $near = $this->nearby($item, $options['types'] ?? [], (float)($options['radius'] ?? 5), $options['limit'] ?? 12, $lang);
        $set = [$item];
        foreach ($near as $entry) {
            $set[] = $entry['item'];
        }
        $data = $this->dataset($set, $lang, ['lines' => false, 'cluster' => false, 'current' => $item->type . '/' . $item->slug]);
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
        return $this->geo->nearby($item, $this->gather($types, $lang), max(0.1, min(100.0, $radiusKm)), is_array($limit) ? array_map(static fn($n): int => max(0, min(50, (int)$n)), $limit) : max(0, min(50, $limit)));
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
