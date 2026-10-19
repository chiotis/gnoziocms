<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The archive of any list of entries (a content type, or the entries of a category or tag): the filters it offers,
 * the order, and the page, from settings that a content type or a taxonomy chose. A filter comes from a chosen
 * taxonomy or, for a content type, from a filterable select field, and only offers values that some entry really has.
 */
final class ArchiveBuilder
{
    /**
     * @param callable(): string[] $taxonomyNames the taxonomies that exist
     * @param callable(string, string, string): string $termLabel the name of a term: taxonomy, term id, language
     */
    public function __construct(private $taxonomyNames, private $termLabel, private ?ArchiveQuery $query = null)
    {
    }

    /**
     * The archive of any list of entries (a content type, or the entries of a category or tag): filters, order, and pages
     * from settings that a content type or a taxonomy chose. Filters come from the chosen taxonomies and, for a content
     * type, from its filterable select fields.
     *
     * @param array<string, mixed> $settings resolved archive settings
     * @param array<string, array<string, mixed>> $fields the declared fields of the type (none for a taxonomy)
     * @param array<string, mixed>|null $definition the type's definition, when there is one
     * @param ContentItem[] $items
     * @param array<string, mixed> $query the address's query: `filter[name]=value` and `page`
     * @return array<string, mixed>
     */
    public function build(array $settings, array $fields, ?array $definition, string $lang, array $items, array $query): array
    {
        $facets = $this->facets($settings, $fields);
        $valuesOf = function (ContentItem $item, string $name, array $facet): array {
            if ($facet['kind'] === 'taxonomy') {
                return Format::list($item->meta[$name] ?? null);
            }
            $value = $item->meta['custom_fields'][$name] ?? '';
            return is_scalar($value) && (string)$value !== '' ? [(string)$value] : [];
        };

        $counts = [];
        foreach ($facets as $name => $facet) {
            $counts[$name] = [];
            foreach ($items as $item) {
                foreach ($valuesOf($item, $name, $facet) as $value) {
                    $counts[$name][(string)$value] = ($counts[$name][(string)$value] ?? 0) + 1;
                }
            }
        }
        [$filters, $selected] = $this->filters($facets, $counts, $query, $lang);

        foreach ($selected as $name => $choice) {
            $items = array_values(array_filter($items, fn(ContentItem $item): bool => in_array($choice, $valuesOf($item, $name, $facets[$name]), true)));
        }

        $title = static fn(ContentItem $item): string => mb_strtolower((string)($item->meta['title'] ?? $item->slug));
        if (preg_match('/^field:([a-z][a-z0-9_]*):(asc|desc)$/', (string)$settings['order'], $m)) {
            // By a declared field; items without a value go last in either direction.
            [$key, $direction] = [$m[1], $m[2]];
            $type_ = $fields[$key]['type'] ?? 'text';
            $sortKey = static function (ContentItem $item) use ($key, $type_): string|float|null {
                $value = $item->meta['custom_fields'][$key] ?? null;
                if ($value === null || $value === '') {
                    return null;
                }
                if ($type_ === 'number' || $type_ === 'decimal') {
                    return is_numeric($value) ? (float)$value : null;
                }
                if ($type_ === 'date' && is_int($value)) {
                    return gmdate('Y-m-d', $value);
                }
                return mb_strtolower((string)$value);
            };
            $withValue = array_values(array_filter($items, static fn(ContentItem $i): bool => $sortKey($i) !== null));
            $without = array_values(array_filter($items, static fn(ContentItem $i): bool => $sortKey($i) === null));
            usort($withValue, static fn(ContentItem $a, ContentItem $b): int => $direction === 'asc' ? $sortKey($a) <=> $sortKey($b) : $sortKey($b) <=> $sortKey($a));
            $items = array_merge($withValue, $without);
        } elseif ($settings['order'] === 'date_asc') {
            $items = array_reverse($items);
        } elseif ($settings['order'] === 'title_asc') {
            usort($items, static fn(ContentItem $a, ContentItem $b): int => strcmp($title($a), $title($b)));
        } elseif ($settings['order'] === 'title_desc') {
            usort($items, static fn(ContentItem $a, ContentItem $b): int => strcmp($title($b), $title($a)));
        } elseif ($settings['order'] === 'random') {
            // A new order for each visit, but the same one while someone pages through the list: the order comes from a number the
            // paging links carry (`seed`), so no entry is shown twice or missed from one page to the next. No global random state is used.
            $seed = (int)($query['seed'] ?? 0);
            $seed = $seed > 0 && $seed <= 2147483647 ? $seed : random_int(1, 2147483647);
            $mix = static fn(ContentItem $item): string => md5($seed . '|' . $item->type . '|' . $item->slug);
            usort($items, static fn(ContentItem $a, ContentItem $b): int => strcmp($mix($a), $mix($b)));
        }

        $total = count($items);
        $perPage = (int)$settings['per_page'];
        if (($settings['layout'] ?? '') === 'map') {
            // A map shows every entry that has a place; the list beside it scrolls instead of paging. A very large site is capped.
            $perPage = 0;
            $items = array_slice($items, 0, 800);
            $total = count($items);
        }
        $pages = $perPage > 0 ? max(1, (int)ceil($total / $perPage)) : 1;
        $page = max(1, min($pages, (int)($query['page'] ?? 1)));
        if ($perPage > 0) {
            $items = array_slice($items, ($page - 1) * $perPage, $perPage);
        }
        $seed = $settings['order'] === 'random' ? ($seed ?? 0) : 0;
        return $this->result($settings, $definition, $items, $total, $filters, $selected, $page, $pages, $seed);
    }

    /**
     * The same archive answered by the content index (ArchiveQuery) instead of a scan of every entry: only the entries on the page are
     * read. Null when the index cannot answer, and the caller builds it from the entries as before.
     *
     * @param array{types: string[], term: array{taxonomy: string, ids: string[]}|null} $scope the entries the list is about
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>|null $definition
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    public function buildIndexed(array $scope, bool $includeHidden, array $settings, array $fields, ?array $definition, string $lang, array $query): ?array
    {
        if ($this->query === null || !$this->query->ready()) {
            return null;
        }
        $facets = $this->facets($settings, $fields);
        $kinds = array_map(static fn(array $facet): string => $facet['kind'], $facets);
        $counts = $this->query->counts($scope, $lang, $includeHidden, $kinds);
        if ($counts === null) {
            return null;
        }
        [$filters, $selected] = $this->filters($facets, $counts, $query, $lang);

        $seed = 0;
        if ($settings['order'] === 'random') {
            $seed = (int)($query['seed'] ?? 0);
            $seed = $seed > 0 && $seed <= 2147483647 ? $seed : random_int(1, 2147483647);
        }
        $map = ($settings['layout'] ?? '') === 'map';
        $field = preg_match('/^field:([a-z][a-z0-9_]*):(asc|desc)$/', (string)$settings['order'], $m) === 1 ? (string)($fields[$m[1]]['type'] ?? 'text') : '';
        // A map shows every entry that has a place; the list beside it scrolls instead of paging. A very large site is capped.
        $page = $this->query->page($scope, $lang, $includeHidden, $selected, $kinds, (string)$settings['order'], $field, $seed, $map ? 0 : (int)$settings['per_page'], (int)($query['page'] ?? 1), $map ? ArchiveQuery::MAP_LIMIT : 0);
        if ($page === null) {
            return null;
        }
        return $this->result($settings, $definition, $page['items'], $page['total'], $filters, $selected, $page['page'], $page['pages'], $seed);
    }

    /**
     * The facets a list can filter by: the taxonomies it chose and the filterable fields of its type.
     *
     * @param array<string, mixed> $settings
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array{label: string, kind: string, labels: array<string, string>}>
     */
    private function facets(array $settings, array $fields): array
    {
        $facets = [];
        $taxonomyNames = ($this->taxonomyNames)();
        foreach ($settings['taxonomies'] as $taxonomy) {
            if (in_array($taxonomy, $taxonomyNames, true)) {
                $facets[$taxonomy] = ['label' => Slug::title($taxonomy), 'kind' => 'taxonomy', 'labels' => []];
            }
        }
        foreach ($fields as $key => $field) {
            if ($field['filterable'] && !$field['hidden']) {
                $facets[$key] = ['label' => $field['label'], 'kind' => 'field', 'labels' => $field['options']];
            }
        }
        return $facets;
    }

    /**
     * The filters to offer and the choices made, from how many entries each value has. A filter with one option is not offered unless
     * it is chosen (so it can be undone); a value nobody has is ignored.
     *
     * @param array<string, array<string, mixed>> $facets
     * @param array<string, array<string, int>> $counts facet => value => entries
     * @param array<string, mixed> $query
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private function filters(array $facets, array $counts, array $query, string $lang): array
    {
        $requested = is_array($query['filter'] ?? null) ? $query['filter'] : [];
        $filters = [];
        $selected = [];
        foreach ($facets as $name => $facet) {
            $options = [];
            foreach ($counts[$name] ?? [] as $value => $count) {
                $value = (string)$value;
                $label = $facet['kind'] === 'taxonomy'
                    ? ($this->termLabel)($name, $value, $lang)
                    : (string)($facet['labels'][$value] ?? $value);
                $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value, 'count' => $count];
            }
            usort($options, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
            $choice = is_scalar($requested[$name] ?? null) ? (string)$requested[$name] : '';
            if ($choice !== '' && !isset($counts[$name][$choice])) {
                $choice = '';
            }
            if ($choice !== '') {
                $selected[$name] = $choice;
            }
            if (count($options) >= 2 || $choice !== '') {
                $filters[] = ['name' => $name, 'label' => $facet['label'], 'kind' => $facet['kind'], 'options' => $options, 'selected' => $choice];
            }
        }
        return [$filters, $selected];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed>|null $definition
     * @param ContentItem[] $items the entries of the page
     * @param array<int, array<string, mixed>> $filters
     * @param array<string, string> $selected
     * @return array<string, mixed>
     */
    private function result(array $settings, ?array $definition, array $items, int $total, array $filters, array $selected, int $page, int $pages, int $seed): array
    {
        $link = static function (int $number) use ($selected, $seed): string {
            $next = [];
            if ($selected !== []) {
                $next['filter'] = $selected;
            }
            if ($seed > 0) {
                $next['seed'] = $seed;
            }
            if ($number > 1) {
                $next['page'] = $number;
            }
            return '?' . http_build_query($next);
        };

        return [
            'definition' => $definition,
            'settings' => $settings,
            'items' => $items,
            'total' => $total,
            'filters' => $filters,
            'filtered' => $selected !== [],
            'page' => $page,
            'pages' => $pages,
            'prev' => $page > 1 ? $link($page - 1) : '',
            'next' => $page < $pages ? $link($page + 1) : '',
            'page_links' => $pages > 1 ? array_map(static fn(int $n): array => ['number' => $n, 'href' => $link($n), 'current' => $n === $page], range(1, $pages)) : [],
        ];
    }
}
