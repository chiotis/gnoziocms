<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Searchable words from an entry, using the same field definitions as its editor. No templates or
 * dynamic block providers run here: URLs, layout settings and undeclared/private fields are omitted.
 */
final class SearchDocument
{
    private const CACHE_BYTES = 16 * 1024 * 1024;
    private const CACHE_ENTRIES = 5000;
    private const TEXT_KEYS = ['title', 'terms', 'fields', 'excerpt', 'body'];
    private ?string $cacheDir = null;
    private string $cacheSalt = '';
    private ?string $blockFingerprint = null;
    /** @var array<string, array<string, mixed>> Buckets are loaded once and keep only entries visited in the current scan. */
    private array $cache = [];

    public function __construct(
        private BlockRegistry $blocks,
        private ContentTypes $types,
        private \Closure $markdownToHtml,
        private string $defaultLang = 'en',
    ) {
    }

    /** Optional disposable cache of extracted words; visibility and search results are always evaluated afresh. */
    public function cacheIn(string $dir, string $salt): void
    {
        $this->cacheDir = rtrim($dir, '/');
        $this->cacheSalt = $salt;
        $this->cache = [];
    }

    /** Call after a complete search scan, so removed or no-longer-visible entries leave each visited bucket. */
    public function flush(): void
    {
        if ($this->cacheDir === null) {
            return;
        }
        foreach ($this->cache as $key => &$bucket) {
            $entries = array_intersect_key($bucket['entries'], $bucket['seen']);
            $bucket['dirty'] = $bucket['dirty'] || count($entries) !== count($bucket['entries']);
            $bucket['entries'] = $entries;
            $bucket['sizes'] = array_intersect_key($bucket['sizes'], $bucket['seen']);
            $bucket['bytes'] = array_sum($bucket['sizes']);
            $bucket['seen'] = [];
            if (!$bucket['dirty']) {
                continue;
            }
            if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
                continue;
            }
            $data = serialize(['schema' => $bucket['schema'], 'entries' => $entries]);
            if (strlen($data) > self::CACHE_BYTES) {
                continue;
            }
            $file = $this->cacheDir . '/' . $key . '.ser';
            $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temp, $data) === false || !@rename($temp, $file)) {
                @unlink($temp);
            } else {
                $bucket['dirty'] = false;
            }
        }
        unset($bucket);
    }

    /** @return array{title: string, terms: string, fields: string, excerpt: string, body: string} */
    public function extract(ContentItem $item): array
    {
        if ($this->cacheDir === null) {
            return $this->build($item);
        }
        $key = hash('sha256', $item->type . '|' . $item->lang);
        if (!isset($this->cache[$key])) {
            $this->loadCache($key, $item);
        }
        $bucket = &$this->cache[$key];
        $bucket['seen'][$item->slug] = true;
        // Actual content, not timestamps: saves and draft previews cannot reuse an older document of the same size.
        $fingerprint = hash('sha256', serialize([$item->slug, $item->meta, $item->html]));
        $entry = $bucket['entries'][$item->slug] ?? null;
        if ($entry !== null && $entry[0] === $fingerprint) {
            return $entry[1];
        }
        $document = $this->build($item);
        $entry = [$fingerprint, $document];
        $size = strlen(serialize($entry));
        $bucket['bytes'] -= $bucket['sizes'][$item->slug] ?? 0;
        unset($bucket['entries'][$item->slug], $bucket['sizes'][$item->slug]);
        // Bound memory and disk usage; an oversized entry is still searched normally.
        if (count($bucket['entries']) < self::CACHE_ENTRIES && $bucket['bytes'] + $size < self::CACHE_BYTES - 65536) {
            $bucket['entries'][$item->slug] = $entry;
            $bucket['sizes'][$item->slug] = $size;
            $bucket['bytes'] += $size;
        }
        $bucket['dirty'] = true;
        return $document;
    }

    private function loadCache(string $key, ContentItem $item): void
    {
        if ($this->blockFingerprint === null) {
            $definitions = [];
            foreach ($this->blocks->all() as $type => $definition) {
                $definitions[$type] = array_intersect_key($definition, array_flip(['fields', 'common', 'design_fields', 'variant_migrations', 'width_moved_to']));
            }
            $this->blockFingerprint = hash('sha256', serialize($definitions));
        }
        $schema = hash('sha256', serialize([
            $this->cacheSalt, $this->defaultLang, $item->type, $item->lang, $this->blockFingerprint,
            $this->types->definition($item->type, $item->lang, $this->defaultLang)['fields'],
        ]));
        $bucket = ['schema' => $schema, 'entries' => [], 'seen' => [], 'sizes' => [], 'bytes' => 0, 'dirty' => false];
        $raw = @file_get_contents($this->cacheDir . '/' . $key . '.ser', false, null, 0, self::CACHE_BYTES + 1);
        $data = null;
        if (is_string($raw) && strlen($raw) <= self::CACHE_BYTES) {
            try {
                $data = @unserialize($raw, ['allowed_classes' => false]);
            } catch (\Throwable) {
                // A corrupt cache only costs speed.
            }
        }
        if (is_array($data) && ($data['schema'] ?? null) === $schema && is_array($data['entries'] ?? null)
            && count($data['entries']) <= self::CACHE_ENTRIES) {
            foreach ($data['entries'] as $slug => $entry) {
                if (!is_array($entry) || !is_string($entry[0] ?? null) || !is_array($entry[1] ?? null)
                    || array_keys($entry[1]) !== self::TEXT_KEYS || count(array_filter($entry[1], 'is_string')) !== count(self::TEXT_KEYS)) {
                    continue;
                }
                $bucket['entries'][$slug] = $entry;
                $bucket['sizes'][$slug] = strlen(serialize($entry));
                $bucket['bytes'] += $bucket['sizes'][$slug];
            }
        }
        $this->cache[$key] = $bucket;
    }

    /** @return array{title: string, terms: string, fields: string, excerpt: string, body: string} */
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
        return [
            'title' => self::plain(self::scalar($item->get('title', $item->slug))),
            'terms' => self::join($terms),
            'fields' => $this->fields($definitions, $values, $values, $item->lang, true),
            'excerpt' => self::plain(self::scalar($item->get('excerpt', ''))),
            'body' => self::join([
                self::plain($item->html),
                $this->blockText(is_array($rawBlocks) ? $rawBlocks : [], $item->lang),
            ]),
        ];
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
    private function fields(array $definitions, array $values, array $context, string $lang, bool $custom = false): string
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
                        $texts[] = $this->fields($field['fields'], $row, FieldSchema::resolve($field['fields'], $row) + $context, $lang, $custom);
                    }
                }
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
