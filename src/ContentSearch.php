<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Public search over the repository's parsed-content cache. Extraction, matching and presentation are independent. */
final class ContentSearch
{
    public const PER_PAGE = 10;
    private const WEIGHTS = ['title' => 12, 'terms' => 5, 'fields' => 4, 'related' => 4, 'excerpt' => 3, 'body' => 1];

    public function __construct(private ContentRepository $content, private SearchDocument $documents, private ?Relations $relations = null)
    {
    }

    /**
     * `results` remains the complete list for existing custom search templates without pagination. Only `hits` is paginated.
     * No cached result set can outlive a save or cross a language/visibility boundary.
     * @return array<string, mixed>
     */
    public function search(mixed $input, string $lang, bool $includeHidden = false, mixed $type = '', mixed $page = 1): array
    {
        $query = SearchText::query($input);
        $terms = SearchText::terms($query);
        $phrase = SearchText::phrase($query);
        $types = array_values(array_diff($this->content->getTypes(), ['forms']));
        $type = is_string($type) && in_array($type, $types, true) ? $type : '';
        $page = (is_string($page) || is_int($page)) && preg_match('/^[0-9]{1,9}$/D', (string)$page) ? max(1, (int)$page) : 1;
        $hits = [];
        $counts = [];
        foreach ($terms === [] ? [] : $types as $contentType) {
            // A result in another language could point at a page that does not exist in the current one.
            $items = $this->content->getItems($contentType, $lang, $includeHidden, false);
            $documents = array_map($this->documents->extract(...), $items);
            $related = $this->relatedTitles($documents, $lang, $includeHidden);
            foreach ($items as $n => $item) {
                $document = $this->withRelated($documents[$n], $related);
                $score = $this->score($document, $terms, $phrase);
                if ($score === 0) {
                    continue;
                }
                $counts[$contentType] = ($counts[$contentType] ?? 0) + 1;
                if ($type === '' || $type === $contentType) {
                    $hits[] = ['item' => $item, 'score' => $score, 'document' => $document];
                }
            }
        }
        // Only a signed-in scan visits drafts and hidden entries, so only it can tell which cached entries are gone.
        $this->documents->flush($includeHidden);
        usort($hits, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score'])
                ?: strcmp($a['document']['normal']['title'], $b['document']['normal']['title'])
                ?: strcmp($a['item']->type . '/' . $a['item']->slug, $b['item']->type . '/' . $b['item']->slug);
        });
        $total = count($hits);
        $results = array_column($hits, 'item');
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $hits = array_slice($hits, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        foreach ($hits as &$hit) {
            $document = $hit['document'];
            $snippet = $this->snippetSource($document, $terms);
            $hit['title_parts'] = SearchText::parts($document['title'], $terms);
            $hit['snippet_parts'] = SearchText::parts(SearchText::snippet($snippet, $terms), $terms);
            unset($hit['document'], $hit['score']);
        }
        unset($hit);
        return [
            'query' => $query, 'results' => $results, 'hits' => $hits,
            'total' => $total, 'counts' => $counts, 'type' => $type,
            'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE,
        ];
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

    /** @param array<string, mixed> $document @param string[] $terms @param string $phrase normalized query word sequence */
    private function score(array $document, array $terms, string $phrase): int
    {
        $found = [];
        $score = 0;
        foreach (self::WEIGHTS as $field => $weight) {
            $text = $document['normal'][$field];
            foreach ($terms as $term) {
                $strength = SearchText::strength($text, $term);
                if ($strength === 0) {
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
        // Short words («ο», «της») only raise the score when the query has longer words; a query of short words needs them all.
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

    /** Prefer a field containing the query over a generic excerpt unrelated to it. */
    private function snippetSource(array $document, array $terms): string
    {
        $best = '';
        $score = -1;
        foreach (['excerpt', 'body', 'fields', 'related', 'terms'] as $key) {
            $text = $document[$key] ?? '';
            if ($text === '') {
                continue;
            }
            $normal = $document['normal'][$key];
            $matches = count(array_filter($terms, static fn(string $term): bool => SearchText::strength($normal, $term) > 0));
            if ($matches > $score) {
                $score = $matches;
                $best = $text;
            }
        }
        return $best;
    }
}
