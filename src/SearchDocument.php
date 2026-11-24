<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Searchable words from an entry, using the same field definitions as its editor. No templates or
 * dynamic block providers run here: URLs, layout settings and undeclared/private fields are omitted.
 *
 * With cacheIn() the documents are kept in the search index (SearchStore): an entry is extracted again only when its content or
 * the definitions it was extracted with change, and a search reads only the documents that can match (matching()).
 */
final class SearchDocument
{
    private const TEXT_KEYS = ['title', 'terms', 'fields', 'excerpt', 'body'];
    /** Part of the schema hash: bump when the stored document shape changes so older documents are extracted again. */
    private const FORMAT = 4;
    private ?SearchStore $store = null;
    private string $cacheSalt = '';
    private ?string $blockFingerprint = null;
    /**
     * The scopes (a content type in a language) this request opened: the schema, what the index keeps (slug => fingerprint), the
     * entries this request saw with their fingerprints now, and documents extracted when the index could not keep them.
     *
     * @var array<string, array{schema: string, known: array<string, string>, seen: array<string, string>, loose: array<string, array<string, mixed>>}>
     */
    private array $scopes = [];

    public function __construct(
        private BlockRegistry $blocks,
        private ContentTypes $types,
        private \Closure $markdownToHtml,
        private string $defaultLang = 'en',
    ) {
    }

    /** Keeps the documents in the search index in this folder (disposable); visibility and results are always evaluated afresh. */
    public function cacheIn(string $dir, string $salt): void
    {
        $this->store = new SearchStore(rtrim($dir, '/') . '/index.sqlite');
        $this->cacheSalt = $salt;
        $this->scopes = [];
    }

    /**
     * Call after a search scan: writes what it extracted. Only a $complete scan (one that visited every entry, drafts and hidden
     * ones included) may forget unvisited entries: a public scan skips drafts a signed-in scan kept, and forgetting them would make
     * alternating requests write them again every time. Deleted entries are forgotten on the next complete scan.
     */
    public function flush(bool $complete = false): void
    {
        if ($this->store === null) {
            return;
        }
        if ($complete) {
            foreach ($this->scopes as $scope => $state) {
                $this->store->prune($scope, $state['seen']);
            }
        }
        $this->store->commit();
        foreach ($this->scopes as &$state) {
            $state['seen'] = [];
        }
        unset($state);
    }

    /**
     * Display text per field, plus the same fields run through SearchText::normalize() once so a search does not repeat it.
     * `relations` lists the ids the entry points at through its searchable relation fields (also in repeaters); the titles behind
     * them are not part of the document, because a target's title changes without this entry changing (ContentSearch adds them).
     * @return array{title: string, terms: string, fields: string, excerpt: string, body: string, normal: array{title: string, terms: string, fields: string, excerpt: string, body: string}, relations: list<string>}
     */
    public function extract(ContentItem $item): array
    {
        if ($this->store === null) {
            return $this->build($item);
        }
        $scope = $this->sync([$item])[0];
        $state = $this->scopes[$scope];
        if (isset($state['loose'][$item->slug])) {
            return $state['loose'][$item->slug];
        }
        $row = $this->store->get($scope, $item->slug);
        $document = $row !== null && $row['fingerprint'] === $state['seen'][$item->slug] ? self::decode($row['doc'], $row['haystack']) : null;
        return $document ?? $this->build($item);
    }

    /**
     * Brings the index up to date with these entries: an entry whose content changed since it was kept (or that is new) is
     * extracted and kept again. Nothing is read back.
     *
     * @param ContentItem[] $items
     * @return string[] the scope of each entry, in order
     */
    public function sync(array $items): array
    {
        $scopes = [];
        foreach ($items as $item) {
            $scope = $item->type . '|' . $item->lang;
            $scopes[] = $scope;
            if ($this->store === null) {
                continue;
            }
            if (!isset($this->scopes[$scope])) {
                $schema = $this->schema($item);
                $this->scopes[$scope] = ['schema' => $schema, 'known' => $this->store->open($scope, $schema) ?? [], 'seen' => [], 'loose' => []];
            }
            $state = &$this->scopes[$scope];
            // Actual content, not timestamps: saves and draft previews cannot reuse an older document of the same size.
            $fingerprint = hash('xxh128', serialize([$item->slug, $item->meta, $item->html]));
            $state['seen'][$item->slug] = $fingerprint;
            if (($state['known'][$item->slug] ?? null) !== $fingerprint) {
                $document = $this->build($item);
                $status = strtolower((string)($item->meta['status'] ?? 'published'));
                $public = $status === 'published' && ($item->meta['visible'] ?? true) !== false;
                if ($this->store->put($scope, $state['schema'], $item->slug, $fingerprint, $public, $document['relations'] !== [], self::haystack($document), $document)) {
                    $state['known'][$item->slug] = $fingerprint;
                    unset($state['loose'][$item->slug]);
                } else {
                    $state['loose'][$item->slug] = $document;
                }
            }
            unset($state);
        }
        return $scopes;
    }

    /**
     * The documents of the entries that can match: every group (one for each word of the query that must match) has one of its
     * strings in the entry's text. A superset of what matches (the scoring decides); entries that point at others are always
     * included, because the titles they are found by are not in their text. Without the index, every entry's document.
     *
     * @param ContentItem[] $items entries of one type in one language
     * @param list<list<string>> $groups
     * @return array<int, array<string, mixed>> the position of the entry in $items => its document
     */
    public function matching(array $items, array $groups): array
    {
        if ($items === []) {
            return [];
        }
        if ($this->store === null) {
            return array_map($this->build(...), $items);
        }
        $scopes = $this->sync($items);
        $documents = [];
        $rows = [];
        foreach (array_unique($scopes) as $scope) {
            $rows[$scope] = $this->store->matching($scope, $groups);
        }
        foreach ($items as $n => $item) {
            $scope = $scopes[$n];
            $state = $this->scopes[$scope] ?? null;
            if ($state !== null && isset($state['loose'][$item->slug])) {
                $documents[$n] = $state['loose'][$item->slug];
                continue;
            }
            if ($rows[$scope] === null) {
                // The index failed during this search: the entry is read the slow way.
                $documents[$n] = $this->build($item);
                continue;
            }
            $row = $rows[$scope][$item->slug] ?? null;
            if ($row === null) {
                continue;
            }
            // Another request may have kept another version meanwhile (a draft preview): this entry's own is extracted.
            $document = $state !== null && $row['fingerprint'] === ($state['seen'][$item->slug] ?? '') ? self::decode($row['doc'], $row['haystack']) : null;
            $documents[$n] = $document ?? $this->build($item);
        }
        return $documents;
    }

    /**
     * Words of the entries this request synced that are close to the term (one letter different from a word of five to eight
     * letters, two from a longer one; a swap of two letters side by side counts as one), closest first, at most `$limit`. Only when
     * the term itself (or its other forms, SearchText::stem()) is in none of those entries a visitor may see: a word that is on the
     * site is never corrected. Empty for short terms, terms with digits, and without the index.
     *
     * @return string[]
     */
    public function similar(string $term, bool $includeHidden, int $limit = 8): array
    {
        $length = mb_strlen($term, 'UTF-8');
        $distance = $length >= 9 ? 2 : ($length >= 5 ? 1 : 0);
        if ($this->store === null || $distance === 0 || preg_match('/^\p{L}+$/u', $term) !== 1 || $this->scopes === []) {
            return [];
        }
        $scopes = array_keys($this->scopes);
        // On the site as typed (also inside a longer word), or in another form of it («βιβλιο» where the text says «βιβλια»).
        $known = $this->store->contains($scopes, [$term], $includeHidden);
        if ($known !== false) {
            return [];
        }
        $stem = SearchText::stem($term);
        foreach ($stem === '' ? [] : $this->store->startingWith($scopes, $stem) as $word) {
            if (SearchText::inflected($word, $term)) {
                return [];
            }
        }
        $close = [];
        $target = self::bytes($term);
        foreach ($this->store->words($scopes, max(SearchStore::WORD_MIN, $length - $distance), $length + $distance) as $word) {
            $found = SearchText::distance($target, self::bytes($word));
            if ($found <= $distance) {
                $close[$word] = $found;
            }
        }
        uksort($close, static fn(string $a, string $b): int => ($close[$a] <=> $close[$b]) ?: strcmp($a, $b));
        return array_slice(array_map('strval', array_keys($close)), 0, $limit);
    }

    /** @var array<string, string> a letter => the one byte it is compared as */
    private static array $letters = [];

    /** A word as one byte per letter, so that levenshtein() counts letters, not the bytes of Greek letters. */
    private static function bytes(string $word): string
    {
        $out = '';
        foreach (mb_str_split($word, 1, 'UTF-8') as $letter) {
            if (!isset(self::$letters[$letter])) {
                // 255 different letters are more than any site's words have; the rest share the last byte.
                self::$letters[$letter] = chr(min(255, count(self::$letters) + 1));
            }
            $out .= self::$letters[$letter];
        }
        return $out;
    }

    /** Between the normalized fields in the text the index matches; a field that holds it is not kept (decode() refuses it). */
    private const SEPARATOR = "\x1F";

    /** The text an entry is matched by in the index: its normalized fields. @param array<string, mixed> $document */
    private static function haystack(array $document): string
    {
        return implode(self::SEPARATOR, array_map(static fn(string $key): string => (string)$document['normal'][$key], self::TEXT_KEYS));
    }

    /**
     * A kept document from its row: the display text and relations, and the normalized fields from the text the index matches
     * (they are kept once). Null when it is not one (an older shape, a corrupt row).
     *
     * @return array<string, mixed>|null
     */
    private static function decode(string $raw, string $haystack): ?array
    {
        try {
            $document = @unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }
        $normal = explode(self::SEPARATOR, $haystack);
        if (!is_array($document) || count($normal) !== count(self::TEXT_KEYS) || array_key_exists('normal', $document)) {
            return null;
        }
        $relations = $document['relations'] ?? null;
        unset($document['relations']);
        $document += ['normal' => array_combine(self::TEXT_KEYS, $normal), 'relations' => $relations];
        if (!is_array($document) || array_keys($document) !== [...self::TEXT_KEYS, 'normal', 'relations'] || !is_array($document['normal'])
            || !is_array($document['relations']) || count(array_filter($document['relations'], 'is_string')) !== count($document['relations'])
            || !self::texts(array_diff_key($document, ['normal' => true, 'relations' => true])) || !self::texts($document['normal'])) {
            return null;
        }
        return $document;
    }

    /** What the documents of a type in a language are extracted with: the format, the release, the block and field definitions. */
    private function schema(ContentItem $item): string
    {
        if ($this->blockFingerprint === null) {
            $definitions = [];
            foreach ($this->blocks->all() as $type => $definition) {
                $definitions[$type] = array_intersect_key($definition, array_flip(['fields', 'common', 'design_fields', 'variant_migrations', 'width_moved_to']));
            }
            $this->blockFingerprint = hash('sha256', serialize($definitions));
        }
        return hash('sha256', serialize([
            self::FORMAT, $this->cacheSalt, $this->defaultLang, $item->type, $item->lang, $this->blockFingerprint,
            $this->types->definition($item->type, $item->lang, $this->defaultLang)['fields'],
        ]));
    }

    /** @param array<mixed> $texts Exactly the five text fields, all strings. */
    private static function texts(array $texts): bool
    {
        return array_keys($texts) === self::TEXT_KEYS && count(array_filter($texts, 'is_string')) === count(self::TEXT_KEYS);
    }

    /** @return array{title: string, terms: string, fields: string, excerpt: string, body: string, normal: array<string, string>, relations: list<string>} */
    private function build(ContentItem $item): array
    {
        $terms = [];
        foreach (['categories', 'tags'] as $key) {
            $value = $item->get($key, []);
            foreach (is_array($value) ? $value : [$value] as $term) {
                if (is_scalar($term)) {
                    $terms[] = self::plain((string)$term);
                }
            }
        }
        $definitions = $this->types->definition($item->type, $item->lang, $this->defaultLang)['fields'];
        $values = $this->types->values($item->type, $item->meta, $item->lang, $this->defaultLang);
        $rawBlocks = $item->get('blocks', []);
        $ids = [];
        $texts = [
            'title' => self::plain(self::scalar($item->get('title', $item->slug))),
            'terms' => self::join($terms),
            'fields' => $this->fields($definitions, $values, $values, $item->lang, true, $ids),
            'excerpt' => self::plain(self::scalar($item->get('excerpt', ''))),
            'body' => self::join([
                self::plain($item->html),
                $this->blockText(is_array($rawBlocks) ? $rawBlocks : [], $item->lang),
            ]),
        ];
        return $texts + ['normal' => array_map(SearchText::normalize(...), $texts), 'relations' => array_values(array_unique($ids))];
    }

    /** @param array<int, mixed> $blocks */
    private function blockText(array $blocks, string $lang, bool $containers = true): string
    {
        $texts = [];
        foreach ($blocks as $raw) {
            if (!is_array($raw) || !is_string($raw['type'] ?? null)) {
                continue;
            }
            if ($raw['type'] === BlockContainer::TYPE) {
                // Match the renderer: only top-level containers and the columns it actually accepts.
                if ($containers) {
                    $container = BlockContainer::resolve($raw);
                    if (!$container['settings']['hidden']) {
                        foreach ($container['columns'] as $column) {
                            $texts[] = $this->blockText($column['blocks'], $lang, false);
                        }
                    }
                }
                continue;
            }
            $raw = $this->blocks->migrate($raw);
            $definition = $this->blocks->get($raw['type']);
            if ($definition === null) {
                continue;
            }
            $common = FieldSchema::resolve($definition['common'], array_intersect_key($raw, $definition['common']));
            if ($common['hidden']) {
                continue;
            }
            $values = array_intersect_key($raw, $definition['fields']);
            $context = $common + FieldSchema::resolve($definition['fields'], $values);
            $fields = array_diff_key($definition['fields'], array_fill_keys($definition['design_fields'], true));
            $texts[] = $this->fields($fields, $values, $context, $lang);
        }
        return self::join($texts);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, mixed> $values stored values only, so unused defaults do not become search results
     * @param array<string, mixed> $context resolved values for conditional fields
     */
    private function fields(array $definitions, array $values, array $context, string $lang, bool $custom = false, ?array &$ids = null): string
    {
        $texts = [];
        foreach ($definitions as $key => $field) {
            if (!array_key_exists($key, $values) || $field['hidden'] || ($field['searchable'] ?? null) === false
                || !$this->whenMatches($field['when'] ?? [], $context)) {
                continue;
            }
            if ($custom && ($field['show'] ?? true) === false && ($field['card'] ?? false) !== true
                && ($field['searchable'] ?? false) !== true) {
                continue;
            }
            if ($field['type'] === 'repeater') {
                // As FieldSchema does, discard malformed/empty rows before applying the public row limit.
                $rows = array_values(array_filter(is_array($values[$key]) ? $values[$key] : [], static function (mixed $row) use ($field): bool {
                    return is_array($row) && array_filter(array_intersect_key($row, $field['fields']), static fn(mixed $value): bool => !in_array($value, ['', null, false, []], true)) !== [];
                }));
                if ($field['max'] !== null) {
                    $rows = array_slice($rows, 0, max(0, $field['max']));
                }
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $texts[] = $this->fields($field['fields'], $row, FieldSchema::resolve($field['fields'], $row) + $context, $lang, $custom, $ids);
                    }
                }
                continue;
            }
            if ($field['type'] === 'relation') {
                // Only the ids are kept here; the titles are looked up per search, so they stay current.
                $ids = array_merge($ids ?? [], RelationFields::idList(FieldSchema::clean($field, $values[$key])));
                continue;
            }
            // Block selects describe layout/source/style; content-type selects describe public facts.
            if (!in_array($field['type'], ['text', 'textarea', 'markdown'], true)
                && !($custom && in_array($field['type'], ['select', 'number', 'decimal', 'date'], true))) {
                continue;
            }
            $value = FieldSchema::clean($field, $values[$key]);
            if (($field['translatable'] ?? false) === true && is_array($value)) {
                $value = $value[$lang] ?? $value[$this->defaultLang] ?? $value['default'] ?? '';
            }
            if (!is_scalar($value) || (string)$value === '') {
                continue;
            }
            $text = match ($field['type']) {
                'markdown' => ($this->markdownToHtml)((string)$value),
                'select' => $field['options'][(string)$value] ?? '',
                default => (string)$value,
            };
            $texts[] = self::plain((string)$text);
        }
        return self::join($texts);
    }

    /** @param array<string, string[]> $conditions @param array<string, mixed> $context */
    private function whenMatches(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $wanted) {
            $raw = $context[$key] ?? '';
            $actual = is_bool($raw) ? ($raw ? 'true' : 'false') : self::scalar($raw);
            $matched = false;
            foreach ($wanted as $value) {
                $matched = $matched || (str_starts_with($value, '!') ? $actual !== substr($value, 1) : $actual === $value);
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    /** Human-readable HTML/field text, never executable markup or link destinations. */
    private static function plain(string $html): string
    {
        $html = preg_replace('#<(script|style|template|iframe)\b[^>]*>.*?(?:</\1\s*>|$)#is', ' ', $html) ?? $html;
        $html = preg_replace('#</(?:p|h[1-6]|li|div|tr|td|th|blockquote|figcaption|dd|dt)>|<br\s*/?>#i', '$0 ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~(?:https?://|www\.|mailto:|tel:)[^\s<>]+~iu', ' ', $text) ?? $text;
        return trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text) ?? $text);
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /** @param string[] $parts */
    private static function join(array $parts): string
    {
        return implode(' ', array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
    }
}
