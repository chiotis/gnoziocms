<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The entries near one (geo_nearby) put under headings by their group: the top term of a taxonomy with a tree that they are filed
 * in (a point filed under "Gorges", a child of "Nature", goes under "Nature"). Nothing is stored for it: the group is read from the
 * taxonomy each time, so moving a term in Admin > Taxonomies moves its entries.
 *
 * The taxonomy is chosen from the entries themselves: among the taxonomies that have a tree (at least one term with a parent), the
 * one most of them are filed in; a site's own taxonomy wins a tie over the categories. When none of them is filed in such a taxonomy
 * there are no groups, and the list stays one list.
 */
final class NearbyGroups
{
    /**
     * @param array<int, array{item: ContentItem, km: float}> $entries nearest first, all of one type
     * @param \Closure(string, string): string $label the label of a term (taxonomy, term id) in the page's language
     * @param \Closure(string, string): string $url the address of a term's page (taxonomy, term id); '' when it has none
     * @return array<int, array{id: string, taxonomy: string, title: string, url: string, entries: array<int, array{item: ContentItem, km: float}>}>
     *         groups in the order of the taxonomy's tree, nearest first inside each; entries in no group last, with an empty id
     */
    public static function group(array $entries, Taxonomies $taxonomies, \Closure $label, \Closure $url): array
    {
        if ($entries === []) {
            return [];
        }
        $best = '';
        $bestCount = 0;
        $roots = [];
        foreach ($taxonomies->names() as $name) {
            $tree = $taxonomies->load($name);
            if (!$tree['hierarchical']) {
                continue;
            }
            $parents = [];
            foreach ($tree['terms'] as $term) {
                $parents[$term['id']] = $term['parent'];
            }
            if (!in_array(true, array_map(static fn(string $p): bool => $p !== '', $parents), true)) {
                continue;
            }
            $rootOf = [];
            $count = 0;
            foreach ($entries as $index => $entry) {
                $root = self::rootOf($entry['item']->meta[$name] ?? null, $parents);
                if ($root !== '') {
                    $rootOf[$index] = $root;
                    $count++;
                }
            }
            $better = $count > $bestCount || ($count === $bestCount && $count > 0 && $best === 'categories' && Taxonomies::isCustom($name));
            if ($better) {
                $best = $name;
                $bestCount = $count;
                $roots = $rootOf;
            }
        }
        if ($best === '') {
            return [];
        }
        $groups = [];
        foreach ($taxonomies->tree($best) as $term) {
            if ((int)$term['depth'] === 0) {
                $groups[$term['id']] = ['id' => $term['id'], 'taxonomy' => $best, 'title' => $label($best, $term['id']), 'url' => $url($best, $term['id']), 'entries' => []];
            }
        }
        $other = ['id' => '', 'taxonomy' => $best, 'title' => '', 'url' => '', 'entries' => []];
        foreach ($entries as $index => $entry) {
            $root = $roots[$index] ?? '';
            if ($root !== '' && isset($groups[$root])) {
                $groups[$root]['entries'][] = $entry;
            } else {
                $other['entries'][] = $entry;
            }
        }
        $out = array_values(array_filter($groups, static fn(array $g): bool => $g['entries'] !== []));
        if ($other['entries'] !== []) {
            $out[] = $other;
        }
        return $out;
    }

    /**
     * The top term above the first known term of a list (the term itself when it is at the top); '' when none is known.
     *
     * @param array<string, string> $parents term id => parent id
     */
    private static function rootOf(mixed $value, array $parents): string
    {
        foreach (Format::list($value) as $termId) {
            $at = Slug::plain((string)$termId);
            if (!isset($parents[$at])) {
                continue;
            }
            for ($guard = 0; $parents[$at] !== '' && isset($parents[$parents[$at]]) && $guard < 50; $guard++) {
                $at = $parents[$at];
            }
            return $at;
        }
        return '';
    }
}
