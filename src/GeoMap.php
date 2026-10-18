<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What a map needs to know about content: where each entry is, how to describe it in a popup, the line of a route, and which
 * entries are near which. An entry keeps its position in the field `location` ("35.2012, 26.2744"); an entry with a `track` field
 * (a route file) is also a line, and without a `location` it is placed at the start of the line.
 *
 * Used by the map layout of an archive, by the Map block, and by the pages of one point, route or business.
 */
final class GeoMap
{
    /** @var array<string, array<int, array{0: float, 1: float}>> */
    private array $lines = [];

    public function __construct(private GeoLibrary $library, private Images $images)
    {
    }

    /** @return array<string, mixed> */
    private static function fields(ContentItem $item): array
    {
        return is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
    }

    /** The route file of an entry, read (and kept), or null. @return array<string, mixed>|null */
    public function track(ContentItem $item): ?array
    {
        $file = self::fields($item)['track'] ?? '';
        return is_string($file) && $file !== '' ? $this->library->track($file) : null;
    }

    /** Where an entry is: its `location`, else the start of its route. @return array{lat: float, lng: float}|null */
    public function position(ContentItem $item): ?array
    {
        $position = Geo::parse(self::fields($item)['location'] ?? null);
        if ($position !== null) {
            return $position;
        }
        $track = $this->track($item);
        $start = $track['stats']['start'] ?? null;
        return is_array($start) ? ['lat' => (float)$start[0], 'lng' => (float)$start[1]] : null;
    }

    /**
     * The facts of a route: those worked out from its file, over which anything the editor typed wins (distance_km, ascent_m,
     * descent_m). Null for an entry that is not a route (no file and nothing typed).
     *
     * @return array{distance_km: float|null, ascent_m: int|null, descent_m: int|null, min_m: int|null, max_m: int|null, loop: bool, profile: array<int, array{0: float, 1: int}>, has_file: bool}|null
     */
    public function routeFacts(ContentItem $item): ?array
    {
        $fields = self::fields($item);
        $track = $this->track($item);
        $stats = $track['stats'] ?? [];
        $typed = static fn(string $key): ?float => isset($fields[$key]) && is_numeric($fields[$key]) ? (float)$fields[$key] : null;
        $distance = $typed('distance_km') ?? (isset($stats['distance_km']) ? (float)$stats['distance_km'] : null);
        $ascent = $typed('ascent_m') ?? (isset($stats['ascent_m']) && !empty($stats['has_height']) ? (float)$stats['ascent_m'] : null);
        $descent = $typed('descent_m') ?? (isset($stats['descent_m']) && !empty($stats['has_height']) ? (float)$stats['descent_m'] : null);
        if ($track === null && $distance === null && $ascent === null) {
            return null;
        }
        return [
            'distance_km' => $distance,
            'ascent_m' => $ascent === null ? null : (int)round($ascent),
            'descent_m' => $descent === null ? null : (int)round($descent),
            'min_m' => $stats['min_m'] ?? null,
            'max_m' => $stats['max_m'] ?? null,
            'loop' => (bool)($stats['loop'] ?? false),
            'profile' => $stats['profile'] ?? [],
            'has_file' => $track !== null,
        ];
    }

    /**
     * One entry as a map draws it. Null when the entry has no position.
     *
     * @param array{type_label: string, url: string, terms: array<int, array{slug: string, label: string}>, lines: bool} $context
     * @return array<string, mixed>|null
     */
    public function feature(ContentItem $item, array $context): ?array
    {
        $position = $this->position($item);
        if ($position === null) {
            return null;
        }
        $fields = self::fields($item);
        $excerpt = isset($item->meta['excerpt']) && is_string($item->meta['excerpt']) ? trim(strip_tags($item->meta['excerpt'])) : '';
        if ($excerpt === '') {
            $excerpt = Feed::plainText($item->html, true);
        }
        $facts = [];
        $area = isset($fields['area']) && is_string($fields['area']) ? trim($fields['area']) : '';
        $line = [];
        $route = $this->routeFacts($item);
        if ($route !== null) {
            if ($route['distance_km'] !== null) {
                $facts[] = rtrim(rtrim(number_format($route['distance_km'], 1, '.', ''), '0'), '.') . ' km';
            }
            if ($route['ascent_m'] !== null && $route['ascent_m'] > 0) {
                $facts[] = '↑ ' . $route['ascent_m'] . ' m';
            }
            if ($context['lines'] && ($track = $this->track($item)) !== null) {
                $line = GeoLibrary::overview($track);
            }
        }
        $image = isset($item->meta['main_image']) && is_string($item->meta['main_image']) ? $item->meta['main_image'] : '';
        return [
            'id' => $item->type . '/' . $item->slug,
            'type' => $item->type,
            'kind' => $context['type_label'],
            'title' => (string)($item->meta['title'] ?? $item->slug),
            'url' => $context['url'],
            'img' => $image === '' ? '' : $this->images->thumbUrl($image, 480),
            'text' => mb_strlen($excerpt) > 150 ? rtrim(mb_substr($excerpt, 0, 150)) . '…' : $excerpt,
            'area' => $area,
            'facts' => $facts,
            'lat' => $position['lat'],
            'lng' => $position['lng'],
            'cats' => array_map(static fn(array $t): string => $t['slug'], $context['terms']),
            'line' => $line,
        ];
    }

    /**
     * The entries nearest to one, within a distance. A point or a business is near a route when it is near its line; a route
     * is near an entry when its line passes close to it; two points are near when their positions are.
     *
     * @param ContentItem[] $candidates
     * @param int|array<string, int> $limit how many in all, or how many of each content type
     * @return array<int, array{item: ContentItem, km: float}> nearest first
     */
    public function nearby(ContentItem $subject, array $candidates, float $radiusKm, int|array $limit): array
    {
        $position = $this->position($subject);
        $ownLine = $this->line($subject);
        if ($position === null && $ownLine === []) {
            return [];
        }
        $found = [];
        foreach ($candidates as $candidate) {
            if ($candidate->type === $subject->type && $candidate->slug === $subject->slug) {
                continue;
            }
            $where = $this->position($candidate);
            $line = $this->line($candidate);
            if ($where === null && $line === []) {
                continue;
            }
            $km = INF;
            if ($where !== null) {
                $km = $ownLine !== []
                    ? Geo::distanceToLineKm($where['lat'], $where['lng'], $ownLine)
                    : ($position !== null ? Geo::distanceKm($position['lat'], $position['lng'], $where['lat'], $where['lng']) : INF);
            }
            if ($line !== [] && $position !== null) {
                $km = min($km, Geo::distanceToLineKm($position['lat'], $position['lng'], $line));
            }
            if ($km <= $radiusKm) {
                $found[] = ['item' => $candidate, 'km' => round($km, 2)];
            }
        }
        usort($found, static fn(array $a, array $b): int => $a['km'] <=> $b['km']);
        if (is_array($limit)) {
            // A limit for each kind (content type), so a crowded kind cannot push another out; a kind with no limit is not cut.
            $count = [];
            $kept = [];
            foreach ($found as $entry) {
                $type = $entry['item']->type;
                $count[$type] = ($count[$type] ?? 0) + 1;
                if (!isset($limit[$type]) || $count[$type] <= max(0, (int)$limit[$type])) {
                    $kept[] = $entry;
                }
            }
            return $kept;
        }
        return array_slice($found, 0, max(0, $limit));
    }

    /** The line of an entry as [[lat, lng], …] with the few points a comparison needs; empty when it is not a route. @return array<int, array{0: float, 1: float}> */
    private function line(ContentItem $item): array
    {
        $key = $item->type . '/' . $item->slug . '/' . $item->lang;
        return $this->lines[$key] ??= $this->buildLine($item);
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function buildLine(ContentItem $item): array
    {
        $track = $this->track($item);
        if ($track === null) {
            return [];
        }
        $all = [];
        foreach (GeoLibrary::overview($track, 200) as $line) {
            foreach ($line as $p) {
                $all[] = $p;
            }
        }
        return $all;
    }
}
