<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Public search over the repository's parsed-content cache. Extraction, matching and presentation are independent. */
final class ContentSearch
{
    public const PER_PAGE = 10;
    private const WEIGHTS = ['title' => 12, 'terms' => 5, 'fields' => 4, 'excerpt' => 3, 'body' => 1];

    public function __construct(private ContentRepository $content, private SearchDocument $documents)
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
        $types = array_values(array_diff($this->content->getTypes(), ['forms']));
        $type = is_string($type) && in_array($type, $types, true) ? $type : '';
        $page = (is_string($page) || is_int($page)) && preg_match('/^[0-9]{1,9}$/D', (string)$page) ? max(1, (int)$page) : 1;
        $hits = [];
        $counts = [];
        foreach ($terms === [] ? [] : $types as $contentType) {
            // A result in another language could point at a page that does not exist in the current one.
            foreach ($this->content->getItems($contentType, $lang, $includeHidden, false) as $item) {
                $document = $this->documents->extract($item);
                $score = $this->score($document, $terms);
                if ($score === 0) {
                    continue;
                }
                $counts[$contentType] = ($counts[$contentType] ?? 0) + 1;
                if ($type === '' || $type === $contentType) {
                    $hits[] = ['item' => $item, 'score' => $score, 'document' => $document];
                }
            }
        }
        $this->documents->flush();
        usort($hits, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score'])
                ?: strcmp(SearchText::normalize($a['document']['title']), SearchText::normalize($b['document']['title']))
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

    /** @param array<string, string> $document @param string[] $terms */
    private function score(array $document, array $terms): int
    {
        $found = [];
        $score = 0;
        foreach (self::WEIGHTS as $field => $weight) {
            $text = SearchText::normalize($document[$field] ?? '');
            foreach ($terms as $term) {
                if (str_contains($text, $term)) {
                    $found[$term] = true;
                    $score += $weight;
                }
            }
        }
        if (count($found) !== count($terms)) {
            return 0;
        }
        $title = SearchText::normalize($document['title']);
        $phrase = implode(' ', $terms);
        // Exact titles and a phrase in a title win without rewarding repeated keywords in a long body.
        $score += $title === $phrase ? 40 : (str_contains($title, $phrase) ? 20 : 0);
        return $score;
    }

    /** Prefer a field containing the query over a generic excerpt unrelated to it. */
    private function snippetSource(array $document, array $terms): string
    {
        $best = '';
        $score = -1;
        foreach (['excerpt', 'body', 'fields', 'terms'] as $key) {
            $text = $document[$key] ?? '';
            if ($text === '') {
                continue;
            }
            $normal = SearchText::normalize($text);
            $matches = count(array_filter($terms, static fn(string $term): bool => str_contains($normal, $term)));
            if ($matches > $score) {
                $score = $matches;
                $best = $text;
            }
        }
        return $best;
    }
}
