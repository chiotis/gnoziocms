<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Public search over the repository's parsed-content cache. Extraction, matching and presentation are independent. */
final class ContentSearch
{
    public const PER_PAGE = 10;
    private const WEIGHTS = ['title' => 12, 'terms' => 5, 'fields' => 4, 'related' => 4, 'excerpt' => 3, 'body' => 1];
    /** The field of a place that names its area (a municipality, a village), as the bundled place types and the maps have it. */
    public const AREA_FIELD = 'area';
    /** @var array<string, string[]> category id => the ids above it, looked up once a search */
    private array $ancestors = [];

    /**
     * @param string[] $placeTypes the types whose entries are places (they have a position); the area and category filters are
     *   offered when results include one
     */
    public function __construct(
        private ContentRepository $content,
        private SearchDocument $documents,
        private ?Relations $relations = null,
        private array $placeTypes = [],
        private ?Taxonomies $taxonomies = null,
    ) {
    }

    /**
     * `results` remains the complete list for existing custom search templates without pagination. Only `hits` is paginated.
     * No cached result set can outlive a save or cross a language/visibility boundary.
     * @return array<string, mixed>
     */
    public function search(mixed $input, string $lang, bool $includeHidden = false, mixed $type = '', mixed $page = 1, mixed $area = '', mixed $category = ''): array
    {
        $query = SearchText::query($input);
        $terms = SearchText::terms($query);
        $phrase = SearchText::phrase($query);
        $types = array_values(array_diff($this->content->getTypes(), ['forms']));
        $type = is_string($type) && in_array($type, $types, true) ? $type : '';
        $page = (is_string($page) || is_int($page)) && preg_match('/^[0-9]{1,9}$/D', (string)$page) ? max(1, (int)$page) : 1;
        $area = SearchText::query(is_string($area) ? mb_substr($area, 0, 120, 'UTF-8') : '');
        $areaKey = SearchText::normalize($area);
        $category = is_string($category) ? Slug::plain(mb_substr($category, 0, 120, 'UTF-8')) : '';
        // Short words («ο», «της») only raise the score when the query has longer words; a query of short words needs them all.
        $required = array_values(array_filter($terms, static fn(string $term): bool => !SearchText::isShort($term)) ?: $terms);
        $itemsOf = [];
        foreach ($terms === [] ? [] : $types as $contentType) {
            // A result in another language could point at a page that does not exist in the current one.
            $itemsOf[$contentType] = $this->content->getItems($contentType, $lang, $includeHidden, false);
            $this->documents->sync($itemsOf[$contentType]);
        }
        // A word that is nowhere on the site may be misspelt: the site's words a letter or two away from it count too.
        $similar = [];
        foreach ($terms === [] ? [] : $required as $term) {
            if (!SearchText::isShort($term) && ($words = $this->documents->similar($term, $includeHidden)) !== []) {
                $similar[$term] = $words;
            }
        }
        // The index reads only the entries that have, for every required word, the word, its stem or a word close to it.
        $groups = array_map(static fn(string $term): array => array_values(array_filter([$term, SearchText::stem($term), ...($similar[$term] ?? [])])), $required);
        $all = [];
        foreach ($itemsOf as $contentType => $items) {
            $documents = $this->documents->matching($items, $groups);
            $related = $this->relatedTitles(array_values($documents), $lang, $includeHidden);
            foreach ($documents as $n => $document) {
                $document = $this->withRelated($document, $related);
                $used = [];
                $score = $this->score($document, $terms, $phrase, $similar, $used);
                if ($score === 0) {
                    continue;
                }
                $item = $items[$n];
                $all[] = ['item' => $item, 'score' => $score, 'document' => $document, 'used' => $used,
                    'area' => $this->areaOf($item), 'categories' => $this->categoriesOf($item)];
            }
        }
        // Only a signed-in scan visits drafts and hidden entries, so only it can tell which kept entries are gone.
        $this->documents->flush($includeHidden);
        $inType = static fn(array $hit): bool => $type === '' || $hit['item']->type === $type;
        $inArea = static fn(array $hit): bool => $areaKey === '' || SearchText::normalize($hit['area']) === $areaKey;
        $inCategory = static fn(array $hit): bool => $category === '' || in_array($category, $hit['categories'], true);
        $counts = [];
        $areas = [];
        $categories = [];
        $hasPlaces = false;
        $areaBase = 0;
        $categoryBase = 0;
        foreach ($all as $hit) {
            $hasPlaces = $hasPlaces || in_array($hit['item']->type, $this->placeTypes, true);
            if ($inArea($hit) && $inCategory($hit)) {
                $counts[$hit['item']->type] = ($counts[$hit['item']->type] ?? 0) + 1;
            }
            $areaBase += $inType($hit) && $inCategory($hit) ? 1 : 0;
            $categoryBase += $inType($hit) && $inArea($hit) ? 1 : 0;
            if ($inType($hit) && $inCategory($hit) && $hit['area'] !== '') {
                $key = SearchText::normalize($hit['area']);
                $areas[$key] ??= ['value' => $hit['area'], 'count' => 0];
                $areas[$key]['count']++;
            }
            if ($inType($hit) && $inArea($hit)) {
                foreach ($hit['categories'] as $id) {
                    $categories[$id] = ($categories[$id] ?? 0) + 1;
                }
            }
        }
        $hits = array_values(array_filter($all, static fn(array $hit): bool => $inType($hit) && $inArea($hit) && $inCategory($hit)));
        usort($hits, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score'])
                ?: strcmp($a['document']['normal']['title'], $b['document']['normal']['title'])
                ?: strcmp($a['item']->type . '/' . $a['item']->slug, $b['item']->type . '/' . $b['item']->slug);
        });
        // The close words the results were found by, as the first of them writes it («ξενοδοχείο», not «ξενοδοχειο»).
        $corrections = [];
        foreach ($hits as $hit) {
            foreach ($hit['used'] as $term => [$word, $field]) {
                if (!isset($corrections[$term][$word])) {
                    $written = array_values(array_filter(SearchText::parts((string)$hit['document'][$field], [], [$word]), static fn(array $part): bool => $part['match']));
                    $corrections[$term][$word] = $written === [] ? $word : $written[0]['text'];
                }
            }
        }
        $total = count($hits);
        $results = array_column($hits, 'item');
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $hits = array_slice($hits, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $words = array_values(array_unique(array_merge([], ...array_values($similar))));
        foreach ($hits as &$hit) {
            $document = $hit['document'];
            $snippet = $this->snippetSource($document, $terms, $words);
            $hit['title_parts'] = SearchText::parts($document['title'], $terms, $words);
            $hit['snippet_parts'] = SearchText::parts(SearchText::snippet($snippet, $terms, 220, $words), $terms, $words);
            unset($hit['document'], $hit['score'], $hit['used'], $hit['area'], $hit['categories']);
        }
        unset($hit);
        return [
            'query' => $query, 'results' => $results, 'hits' => $hits,
            'total' => $total, 'counts' => $counts, 'type' => $type,
            'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE,
            'similar' => array_map(static fn(array $found): array => array_values(array_unique($found)), $corrections),
            'area' => $area, 'category' => $category,
            'areas' => $hasPlaces ? $this->areaFacet($areas, $area, $areaKey, $areaBase) : [],
            'categories' => $hasPlaces ? $this->categoryFacet($categories, $category, $lang, $categoryBase) : [],
        ];
    }

    /** The area an entry names in its `area` field, as written (empty when it names none). */
    private function areaOf(ContentItem $item): string
    {
        $fields = is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
        $area = $fields[self::AREA_FIELD] ?? '';
        return is_string($area) ? trim(preg_replace('/\s+/u', ' ', $area) ?? '') : '';
    }

    /** The categories an entry is filed under, with the ones above them (an entry under «Beaches» is also under «Nature»). @return string[] */
    private function categoriesOf(ContentItem $item): array
    {
        $ids = [];
        foreach (Format::list($item->meta['categories'] ?? null) as $id) {
            $id = Slug::plain((string)$id);
            if ($id === '') {
                continue;
            }
            $ids[$id] = true;
            $this->ancestors[$id] ??= array_map(static fn(array $above): string => (string)$above['id'], $this->taxonomies?->ancestors('categories', $id) ?? []);
            foreach ($this->ancestors[$id] as $above) {
                $ids[$above] = true;
            }
        }
        return array_map('strval', array_keys($ids));
    }

    /**
     * The areas of the results, most results first: offered when one is chosen, or when choosing one would leave out some of the
     * `$base` results the other filters let through.
     *
     * @param array<string, array{value: string, count: int}> $areas
     * @return list<array{value: string, label: string, count: int, active: bool}>
     */
    private function areaFacet(array $areas, string $area, string $areaKey, int $base): array
    {
        if ($areaKey === '' && array_filter($areas, static fn(array $found): bool => $found['count'] < $base) === []) {
            return [];
        }
        if ($areaKey !== '' && !isset($areas[$areaKey])) {
            $areas[$areaKey] = ['value' => $area, 'count' => 0];
        }
        uksort($areas, static fn(string $a, string $b): int => ($areas[$b]['count'] <=> $areas[$a]['count']) ?: strcmp($a, $b));
        $facet = [];
        foreach ($areas as $key => $found) {
            $facet[] = ['value' => $found['value'], 'label' => $found['value'], 'count' => $found['count'], 'active' => $key === $areaKey];
        }
        return $facet;
    }

    /**
     * The categories of the results, in the order of the category tree: offered as the areas are (areaFacet()).
     *
     * @param array<string, int> $categories id => results
     * @return list<array{value: string, label: string, count: int, depth: int, active: bool}>
     */
    private function categoryFacet(array $categories, string $category, string $lang, int $base): array
    {
        if ($this->taxonomies === null || ($category === '' && array_filter($categories, static fn(int $count): bool => $count < $base) === [])) {
            return [];
        }
        if ($category !== '') {
            $categories[$category] ??= 0;
        }
        $facet = [];
        foreach ($this->taxonomies->tree('categories') as $term) {
            $id = (string)$term['id'];
            if (!isset($categories[$id])) {
                continue;
            }
            $label = $this->taxonomies->label('categories', $id, $lang);
            $facet[] = ['value' => $id, 'label' => $label !== '' ? $label : $id, 'count' => $categories[$id], 'depth' => (int)($term['depth'] ?? 0), 'active' => $id === $category];
            unset($categories[$id]);
        }
        // An id no longer in the taxonomy is not offered, unless it is the one chosen (so it can be cleared).
        if (isset($categories[$category]) && $category !== '') {
            $facet[] = ['value' => $category, 'label' => $category, 'count' => $categories[$category], 'depth' => 0, 'active' => true];
        }
        return $facet;
    }

    /**
     * The titles of the entries the documents point at, as the searcher would see them: the page's language else the site's own,
     * and a draft or hidden target only for someone signed in (Relations applies that rule). One lookup for a whole type, read from
     * the content index at the time of the search, so a renamed or unpublished target is reflected at once without touching the
     * extracted-text cache of the entries that point at it.
     *
     * @param list<array<string, mixed>> $documents
     * @return array<string, array{title: string, normal: string}> id => title
     */
    private function relatedTitles(array $documents, string $lang, bool $includeHidden): array
    {
        $ids = array_values(array_unique(array_merge([], ...array_column($documents, 'relations'))));
        if ($this->relations === null || $ids === []) {
            return [];
        }
        $titles = [];
        foreach ($this->relations->resolve($ids, [], $lang, $includeHidden) as $card) {
            if (trim((string)$card['title']) !== '') {
                $titles[$card['id']] = ['title' => (string)$card['title'], 'normal' => SearchText::normalize((string)$card['title'])];
            }
        }
        return $titles;
    }

    /** @param array<string, mixed> $document @param array<string, array{title: string, normal: string}> $titles @return array<string, mixed> */
    private function withRelated(array $document, array $titles): array
    {
        $found = array_values(array_filter(array_map(static fn(string $id): ?array => $titles[$id] ?? null, $document['relations'])));
        $document['related'] = implode(' ', array_column($found, 'title'));
        $document['normal']['related'] = implode(' ', array_column($found, 'normal'));
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param string[] $terms
     * @param string $phrase normalized query word sequence
     * @param array<string, string[]> $similar term => words close to it (for a misspelt term)
     * @param array<string, array{0: string, 1: string}> $used term => the close word that matched and the field it is in, filled in
     */
    private function score(array $document, array $terms, string $phrase, array $similar = [], array &$used = []): int
    {
        $found = [];
        $score = 0;
        foreach (self::WEIGHTS as $field => $weight) {
            $text = $document['normal'][$field];
            foreach ($terms as $term) {
                $strength = SearchText::strength($text, $term);
                if ($strength === 0) {
                    // Another form of the word («πόλη» for «πόλεις») counts, below the word as typed.
                    if (SearchText::inflected($text, $term)) {
                        $found[$term] = true;
                        $score += max(1, intdiv($weight * 5, 8));
                        continue;
                    }
                    // A word close to a misspelt one («ξενοδοχιο» for «ξενοδοχειο») counts as a whole word, below other forms.
                    foreach ($similar[$term] ?? [] as $word) {
                        if (SearchText::strength($text, $word) === 3) {
                            $found[$term] = true;
                            $used[$term] ??= [$word, $field];
                            $score += max(1, intdiv($weight * 3, 8));
                            break;
                        }
                    }
                    continue;
                }
                $found[$term] = true;
                // A whole word earns the full weight, a word start most of it, the middle of a word half. Short terms are
                // worth one point per field however heavy the field, so «ο» or «της» cannot outweigh the real words.
                $score += SearchText::isShort($term) ? 1 : match ($strength) {
                    3 => $weight,
                    2 => intdiv($weight * 3, 4),
                    default => intdiv($weight, 2),
                };
            }
        }
        $required = array_filter($terms, static fn(string $term): bool => !SearchText::isShort($term)) ?: $terms;
        if (array_diff($required, array_keys($found)) !== []) {
            return 0;
        }
        // Compare word sequences, not raw text: titles keep punctuation ("Hello, World!") that terms drop,
        // and the phrase keeps repeated words ("new new york") that unique terms collapse.
        $title = implode(' ', SearchText::words($document['normal']['title'], true));
        // Exact titles and a phrase in a title win without rewarding repeated keywords in a long body.
        $score += $title === $phrase ? 40 : (str_contains(" $title ", " $phrase ") ? 20 : 0);
        return $score;
    }

    /** Prefer a field containing the query over a generic excerpt unrelated to it. @param string[] $words close words of misspelt terms */
    private function snippetSource(array $document, array $terms, array $words = []): string
    {
        $best = '';
        $score = -1;
        foreach (['excerpt', 'body', 'fields', 'related', 'terms'] as $key) {
            $text = $document[$key] ?? '';
            if ($text === '') {
                continue;
            }
            $normal = $document['normal'][$key];
            $matches = count(array_filter($terms, static fn(string $term): bool => SearchText::strength($normal, $term) > 0 || SearchText::inflected($normal, $term)))
                + count(array_filter($words, static fn(string $word): bool => SearchText::strength($normal, $word) === 3));
            if ($matches > $score) {
                $score = $matches;
                $best = $text;
            }
        }
        return $best;
    }
}
