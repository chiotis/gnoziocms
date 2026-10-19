<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Field bindings of a template built with blocks (docs/theming.md, "Templates built with blocks"): a block field that shows a field
 * of the entry being drawn instead of a value typed in the template. A binding is stored in the block where the value would be, as
 * `{bind: "custom_fields.author"}`: a dotted path from the entry (`title`, `excerpt`, `main_image`, `date`, `author`, `categories`,
 * `tags`, or `custom_fields.<key>` for any field the content type declares). Everything about a content type comes from its
 * definition when the page is drawn, so a type added later with fields of its own needs no code here.
 *
 * A bound value is put where the typed value would be and goes through the normal checks of the block's field after that (the
 * block renderer calls apply() before FieldSchema::resolve), so it is cleaned exactly like typed text: no markup or style gets in
 * through a field of an entry.
 *
 * What may be bound to what is one table, ACCEPTS: it names, for the type of the block's field, the kinds of source it takes. The
 * kind of a declared field is its FieldSchema type; the common fields of an entry have kinds of their own (SOURCES). A new
 * field type becomes bindable by adding it to that one table.
 */
final class EntryBindings
{
    /** The kind of source each block field type takes: block field type => source kinds. */
    public const ACCEPTS = [
        'text' => ['text', 'select', 'number', 'decimal', 'date', 'terms', 'email'],
        'textarea' => ['textarea', 'markdown'],
        'markdown' => ['textarea', 'markdown'],
        'image' => ['image'],
        'link' => ['link', 'url'],
        'url' => ['link', 'url'],
    ];

    /** The common fields of every entry: path => [label, kind]. Their kinds follow the table above (`excerpt` is a text of several lines). */
    public const SOURCES = [
        'title' => ['Title', 'text'],
        'excerpt' => ['Excerpt', 'textarea'],
        'main_image' => ['Main image', 'image'],
        'date' => ['Date', 'date'],
        'author' => ['Author', 'text'],
        'categories' => ['Categories', 'terms'],
        'tags' => ['Tags', 'terms'],
    ];

    /** The block field that holds the choice "hide this block while everything bound in it is empty" (only a `false` is stored). */
    public const HIDE_KEY = 'bind_hide';

    /**
     * @param \Closure(string): string $formatDate a date (Y-m-d) as the site shows dates
     * @param \Closure(string, string): string $termLabel a term of the taxonomy `categories` or `tags`: taxonomy, term id
     */
    public function __construct(
        private ContentItem $item,
        private ContentTypes $types,
        private string $lang,
        private string $defaultLang,
        private \Closure $formatDate,
        private \Closure $termLabel
    ) {
    }

    /** Whether a block field of this type can be bound to something at all. */
    public static function bindable(string $fieldType): bool
    {
        return isset(self::ACCEPTS[$fieldType]);
    }

    /** Whether a stored value is a binding: `{bind: "path"}` with a path written the way paths are (a list binding of delivery 1B adds a `map` beside it). */
    public static function isBinding(mixed $value): bool
    {
        return is_array($value) && is_string($value['bind'] ?? null) && preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $value['bind']) === 1;
    }

    /**
     * What a content type offers to bind, for the picker of the template editor: the common fields, then the declared ones (a retired
     * field is not offered), each with the kind that decides which block fields it fits.
     *
     * @return list<array{path: string, key: string, label: string, kind: string, group: string}>
     */
    public static function sources(ContentTypes $types, string $type, string $lang, string $defaultLang): array
    {
        $out = [];
        foreach (self::SOURCES as $path => [$label, $kind]) {
            $out[] = ['path' => $path, 'key' => $path, 'label' => $label, 'kind' => $kind, 'group' => 'Entry'];
        }
        foreach ($types->editableFields($type, $lang, $defaultLang) as $key => $field) {
            // A list of rows is bound in a list (delivery 1B); a toggle or a location has no block field to show it in.
            if ($field['type'] === 'repeater') {
                continue;
            }
            $out[] = ['path' => 'custom_fields.' . $key, 'key' => (string)$key, 'label' => $field['label'], 'kind' => $field['type'], 'group' => $types->definition($type, $lang, $defaultLang)['label']];
        }
        return $out;
    }

    /** The sources of a content type that a block field of this type takes. @param list<array<string, string>> $sources @return list<array<string, string>> */
    public static function compatible(array $sources, string $fieldType): array
    {
        $kinds = self::ACCEPTS[$fieldType] ?? [];
        return array_values(array_filter($sources, static fn(array $source): bool => in_array($source['kind'], $kinds, true)));
    }

    /**
     * A block with its bindings replaced by the entry's values. The result is a block as it would be typed, ready for the normal
     * checks; the binding of a field that cannot be bound, or whose source is gone or no longer fits, leaves the field empty.
     *
     * @param array<string, array<string, mixed>> $fields the block type's fields (normalized)
     * @param array<string, mixed> $raw the stored block
     * @return array{raw: array<string, mixed>, hidden: bool, missing: list<string>}
     */
    public function apply(array $fields, array $raw): array
    {
        $bound = 0;
        $filled = 0;
        $missing = [];
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $raw) || !self::isBinding($raw[$key])) {
                continue;
            }
            $bound++;
            $path = $raw[$key]['bind'];
            $value = self::bindable($field['type']) ? $this->value($path, $field['type']) : null;
            if ($value === null) {
                $missing[] = $path;
                unset($raw[$key]);
                continue;
            }
            $raw[$key] = $value;
            if ($value !== '') {
                $filled++;
            }
        }
        // A binding in a field the block does not know is dropped like any value the block does not know.
        $hide = $bound > 0 && ($raw[self::HIDE_KEY] ?? true) !== false && $filled === 0;
        unset($raw[self::HIDE_KEY]);
        return ['raw' => $raw, 'hidden' => $hide, 'missing' => $missing];
    }

    /**
     * The value of a path for a block field of a type: text for the field to check. An empty string is a source that exists and
     * has nothing for this entry; null is a source that does not exist or does not fit the field (renamed, removed, another type).
     */
    public function value(string $path, string $fieldType): ?string
    {
        $kinds = self::ACCEPTS[$fieldType] ?? [];
        if (isset(self::SOURCES[$path])) {
            return in_array(self::SOURCES[$path][1], $kinds, true) ? $this->common($path) : null;
        }
        if (!str_starts_with($path, 'custom_fields.') || substr_count($path, '.') !== 1) {
            return null;
        }
        $key = substr($path, strlen('custom_fields.'));
        $field = $this->types->editableFields($this->item->type, $this->lang, $this->defaultLang)[$key] ?? null;
        if ($field === null || !in_array($field['type'], $kinds, true)) {
            return null;
        }
        $values = $this->types->values($this->item->type, $this->item->meta, $this->lang, $this->defaultLang);
        if (!array_key_exists($key, $values)) {
            return '';
        }
        $value = $values[$key];
        if (($field['translatable'] ?? false) === true && is_array($value)) {
            // A text with a version for each language: the one of the page's language, else the site's own.
            $value = (string)(($this->lang !== $this->defaultLang ? ($value[$this->lang] ?? null) : null) ?? $value['default'] ?? '');
        }
        return match ($field['type']) {
            'select' => (string)($field['options'][(string)$value] ?? ''),
            'date' => is_scalar($value) && (string)$value !== '' ? ($this->formatDate)((string)$value) : '',
            'number', 'decimal' => is_int($value) || is_float($value) ? (string)$value : '',
            default => is_scalar($value) ? (string)$value : '',
        };
    }

    /**
     * The fields of a fact sheet, ready to print: the fields the paths name, in that order (`custom_fields.<key>`; one the type no
     * longer declares is skipped), or the fields the entry's page shows when no path is given.
     *
     * @param string[] $paths
     * @return array<int, array<string, mixed>>
     */
    public function factSheet(array $paths): array
    {
        $keys = [];
        foreach ($paths as $path) {
            if (str_starts_with($path, 'custom_fields.')) {
                $keys[] = substr($path, strlen('custom_fields.'));
            }
        }
        $meta = $this->item->meta;
        return array_values($keys === []
            ? $this->types->display($this->item->type, $meta, 'page', $this->formatDate, $this->lang, $this->defaultLang)
            : $this->types->displayFields($this->item->type, $meta, $keys, $this->formatDate, $this->lang, $this->defaultLang));
    }

    /** The value of a common field of the entry. */
    private function common(string $path): string
    {
        $meta = $this->item->meta;
        return match ($path) {
            'title' => $this->text($meta['title'] ?? ''),
            'excerpt' => $this->text($meta['excerpt'] ?? ''),
            'main_image' => $this->text($meta['main_image'] ?? ''),
            'author' => $this->text($meta['author'] ?? ''),
            'date' => $this->date($meta['date'] ?? ''),
            'categories', 'tags' => $this->terms($path, $meta[$path] ?? []),
            default => '',
        };
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function date(mixed $value): string
    {
        if (is_int($value)) {
            // An unquoted YAML date is read as a timestamp.
            $value = gmdate('Y-m-d', $value);
        }
        $value = $this->text($value);
        return $value !== '' ? ($this->formatDate)(substr($value, 0, 10)) : '';
    }

    /** The names of the terms an entry is filed under, in one line. */
    private function terms(string $taxonomy, mixed $ids): string
    {
        $labels = [];
        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            $id = $this->text($id);
            if ($id !== '') {
                $labels[] = ($this->termLabel)($taxonomy, $id);
            }
        }
        return implode(', ', array_filter($labels, static fn(string $label): bool => $label !== ''));
    }
}
