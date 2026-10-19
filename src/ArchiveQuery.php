<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * What a list of entries (the archive of a type, the page of a term) asks the content index: which entries, in what order, which page,
 * and how many entries each value of a filter has. The answer is a page of addresses and numbers, so only the entries on the page are
 * read from their files. It answers exactly what ArchiveBuilder works out by scanning every entry, and says "no" (null) whenever it
 * cannot be sure of that (no index, one made with other definitions, a file that is not where the index says), so the caller scans.
 * Nothing here knows a content type: fields, taxonomies and orders come from the settings and definitions it is given.
 */
final class ArchiveQuery
{
    /** The most entries a map shows. */
    public const MAP_LIMIT = 800;

    private bool $tried = false;

    public function __construct(private ContentIndex $index, private ContentRepository $content)
    {
    }

    /**
     * Whether the index can be trusted now: files changed outside the admin are looked at (at most every 30 seconds) and an index made
     * with other definitions is made again.
     */
    public function ready(): bool
    {
        if ($this->content->hasDraft()) {
            // The live preview of the editor draws the page with an entry that is in no file.
            return false;
        }
        $types = $this->content->getTypes();
        if ($this->index->connection() === null) {
            return false;
        }
        if (!$this->sameFiles($types)) {
            // A file was added or taken away outside the admin: the files are looked at now, not at the next 30 seconds.
            $this->index->markStale();
        }
        $this->index->ensureFresh($this->content, $types);
        if (!$this->index->isCurrent($types) && !$this->tried) {
            // Made with other definitions than today's (or never): made again, once.
            $this->tried = true;
            try {
                $this->index->refresh($this->content, $types);
            } catch (\Throwable) {
                return false;
            }
        }
        return $this->index->isCurrent($types);
    }

    /** Whether the index holds as many entries of each type as there are files (a name read from the folder, no file is opened). @param string[] $types */
    private function sameFiles(array $types): bool
    {
        $pdo = $this->index->connection();
        if ($pdo === null) {
            return false;
        }
        try {
            $known = $pdo->query('SELECT type, COUNT(*) AS n FROM content_index GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return false;
        }
        foreach ($types as $type) {
            if ((int)($known[$type] ?? 0) !== count(glob($this->content->directory() . '/' . $type . '/*.md') ?: [])) {
                return false;
            }
        }
        return true;
    }

    /**
     * How many entries each value of each facet has among the entries of the scope (before any filter is applied).
     *
     * @param array{types: string[], term: array{taxonomy: string, ids: string[]}|null} $scope
     * @param array<string, string> $facets name => 'taxonomy' or 'field'
     * @return array<string, array<string, int>>|null
     */
    public function counts(array $scope, string $lang, bool $includeHidden, array $facets): ?array
    {
        $pdo = $this->index->connection();
        if ($pdo === null) {
            return null;
        }
        $counts = [];
        try {
            foreach ($facets as $name => $kind) {
                $params = [];
                $where = $this->scope($scope, $lang, $includeHidden, $params);
                [$table, $key, $nameColumn, $valueColumn, $extra] = self::facetTable($kind);
                $stmt = $pdo->prepare(
                    'SELECT x.' . $valueColumn . ' AS value, COUNT(*) AS n FROM content_index ci JOIN ' . $table . ' x ON x.' . $key[0] . ' = ci.type AND x.' . $key[1] . ' = ci.slug AND x.' . $key[2] . ' = ci.lang
                     WHERE ' . $where . ' AND x.' . $nameColumn . ' = :facet' . $extra . ' GROUP BY x.' . $valueColumn
                );
                $stmt->execute($params + ['facet' => (string)$name]);
                foreach ($stmt->fetchAll() as $row) {
                    $counts[$name][(string)$row['value']] = (int)$row['n'];
                }
                $counts[$name] ??= [];
            }
        } catch (\Throwable) {
            return null;
        }
        return $counts;
    }

    /**
     * One page of the entries of the scope that match the chosen filters, in an order, as the entries themselves.
     *
     * @param array{types: string[], term: array{taxonomy: string, ids: string[]}|null} $scope
     * @param array<string, string> $selected facet name => the chosen value
     * @param array<string, string> $facets name => 'taxonomy' or 'field'
     * @param string $order an archive order (`date_desc`, `date_asc`, `title_asc`, `title_desc`, `random`, `field:<key>:asc|desc`)
     * @param string $fieldType the declared type of the field when the order is by a field (numbers sort as numbers)
     * @param int $perPage 0 for every entry; `$cap` limits how many entries the list has at all (0: no limit)
     * @return array{items: ContentItem[], total: int, page: int, pages: int}|null
     */
    public function page(array $scope, string $lang, bool $includeHidden, array $selected, array $facets, string $order, string $fieldType, int $seed, int $perPage, int $requestedPage, int $cap = 0): ?array
    {
        $pdo = $this->index->connection();
        if ($pdo === null) {
            return null;
        }
        try {
            $params = [];
            $where = $this->scope($scope, $lang, $includeHidden, $params);
            foreach (array_values(array_keys($selected)) as $i => $name) {
                $kind = $facets[$name] ?? null;
                if ($kind === null) {
                    return null;
                }
                [$table, $key, $nameColumn, $valueColumn, $extra] = self::facetTable($kind);
                $f = 'f' . $i;
                $where .= ' AND EXISTS (SELECT 1 FROM ' . $table . ' ' . $f . ' WHERE ' . $f . '.' . $key[0] . ' = ci.type AND ' . $f . '.' . $key[1] . ' = ci.slug AND ' . $f . '.' . $key[2] . ' = ci.lang AND ' . $f . '.' . $nameColumn . ' = :fn' . $i . ' AND ' . $f . '.' . $valueColumn . ' = :fv' . $i . str_replace('x.', $f . '.', $extra) . ')';
                $params['fn' . $i] = (string)$name;
                $params['fv' . $i] = (string)$selected[$name];
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM content_index ci WHERE ' . $where);
            $count->execute($params);
            $total = (int)$count->fetchColumn();
            if ($cap > 0) {
                $total = min($total, $cap);
            }
            $pages = $perPage > 0 ? max(1, (int)ceil($total / $perPage)) : 1;
            $page = max(1, min($pages, $requestedPage));

            $join = '';
            $by = 'ci.sort_date DESC, ci.type ASC, ci.path ASC';
            if ($order === 'date_asc') {
                $by = 'ci.sort_date ASC, ci.type DESC, ci.path DESC';
            } elseif ($order === 'title_asc') {
                $by = 'ci.title_key ASC, ' . $by;
            } elseif ($order === 'title_desc') {
                $by = 'ci.title_key DESC, ' . $by;
            } elseif (preg_match('/^field:([a-z][a-z0-9_]*):(asc|desc)$/', $order, $m) === 1) {
                // Entries without a value go last in either direction.
                $column = in_array($fieldType, ['number', 'decimal'], true) ? 'o.sort_num' : 'o.sort_text';
                $join = ' LEFT JOIN content_values o ON o.type = ci.type AND o.slug = ci.slug AND o.lang = ci.lang AND o.field = :order_field';
                $params['order_field'] = $m[1];
                $by = '(' . $column . ' IS NULL) ASC, ' . $column . ' ' . strtoupper($m[2]) . ', ' . $by;
            }
            $sql = 'SELECT ci.type, ci.slug, ci.lang, ci.path, ci.mtime FROM content_index ci' . $join . ' WHERE ' . $where . ' ORDER BY ' . $by;
            $random = $order === 'random';
            $limit = $random ? 0 : $perPage;
            $offset = $random ? 0 : ($page - 1) * $perPage;
            if ($cap > 0 && !$random) {
                $limit = $perPage > 0 ? max(0, min($perPage, $cap - $offset)) : $cap;
            }
            if ($limit > 0) {
                $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
            } elseif ($cap > 0 && !$random) {
                $sql .= ' LIMIT ' . $cap;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($random) {
                // A new order for each visit, the same one while paging through the list (the seed comes from the address).
                usort($rows, static fn(array $a, array $b): int => strcmp(md5($seed . '|' . $a['type'] . '|' . $a['slug']), md5($seed . '|' . $b['type'] . '|' . $b['slug'])));
                if ($cap > 0) {
                    $rows = array_slice($rows, 0, $cap);
                }
                if ($perPage > 0) {
                    $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
                }
            }
        } catch (\Throwable) {
            return null;
        }
        $items = $this->content->itemsAt($rows);
        if ($items === null) {
            // The index and the files disagree: the files are looked at at the next visit, and this one scans.
            $this->index->markStale();
        }
        return $items === null ? null : ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Where a kind of facet lives in the index: the table, the columns that say which entry, the column for the facet's name and for the
     * value, and a condition that belongs to it (a relation of the entry itself, not of a row in a list).
     *
     * @return array{0: string, 1: array{0: string, 1: string, 2: string}, 2: string, 3: string, 4: string}
     */
    private static function facetTable(string $kind): array
    {
        return match ($kind) {
            'taxonomy' => ['content_terms', ['type', 'slug', 'lang'], 'taxonomy', 'term_id', ''],
            'relation' => ['content_refs', ['from_type', 'from_slug', 'from_lang'], 'field_path', 'to_id', " AND x.row_path = ''"],
            default => ['content_values', ['type', 'slug', 'lang'], 'field', 'raw', ''],
        };
    }

    /**
     * The conditions that choose the entries of a scope: the languages and types, the ones a visitor may see, and the ones filed under
     * one of the term ids of a taxonomy.
     *
     * @param array{types: string[], term: array{taxonomy: string, ids: string[]}|null} $scope
     * @param array<string, string> $params filled with what the conditions use
     */
    private function scope(array $scope, string $lang, bool $includeHidden, array &$params): string
    {
        $params['lang'] = $lang;
        $marks = [];
        foreach (array_values($scope['types']) as $i => $type) {
            $marks[] = ':type' . $i;
            $params['type' . $i] = $type;
        }
        // No type at all lists nothing.
        $where = 'ci.lang = :lang AND ci.type IN (' . ($marks === [] ? "''" : implode(', ', $marks)) . ')';
        if (!$includeHidden) {
            $where .= " AND lower(ci.status) = 'published' AND ci.visible = 1";
        }
        if ($scope['term'] !== null) {
            $ids = [];
            foreach (array_values($scope['term']['ids']) as $i => $id) {
                $ids[] = ':term' . $i;
                $params['term' . $i] = (string)$id;
            }
            $params['term_taxonomy'] = $scope['term']['taxonomy'];
            $where .= ' AND EXISTS (SELECT 1 FROM content_terms t WHERE t.type = ci.type AND t.slug = ci.slug AND t.lang = ci.lang AND t.taxonomy = :term_taxonomy AND t.term_id IN (' . ($ids === [] ? "''" : implode(', ', $ids)) . '))';
        }
        return $where;
    }
}
