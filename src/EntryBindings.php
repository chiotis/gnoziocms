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
 *
 * A gallery field (a list of pictures) is offered as a list whose rows have one column, `image` (GALLERY_COLUMN), so the Gallery
 * block's list of images (and any list with a column of the kind image) can show it.
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

    /**
     * What an entry that is pointed at gives to a binding (a relation, `custom_fields.author.title`, or a list of them): path or column key
     * => [label, kind]. Kinds follow the table above. The fields the pointed-at type declares come as `custom_fields.<key>` (one entry)
     * or `field_<key>` (a column of a list).
     */
    public const CARD = [
        'title' => ['Title', 'text'],
        'excerpt' => ['Excerpt', 'textarea'],
        'main_image' => ['Main image', 'image'],
        'date' => ['Date', 'date'],
        'url' => ['Address of its page', 'link'],
    ];

    /** The one column of a gallery field bound as a list: each picture is a row with its address here. */
    public const GALLERY_COLUMN = 'image';

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
        private \Closure $termLabel,
        private ?Relations $relations = null,
        private int $depth = 0
    ) {
    }

    /** Whether a block field of this type can be bound to something at all: a single value (ACCEPTS), or a list of rows (a repeater). */
    public static function bindable(string $fieldType): bool
    {
        return isset(self::ACCEPTS[$fieldType]) || $fieldType === 'repeater';
    }

    /**
     * Whether a stored value is a binding: `{bind: "path"}` with a path written the way paths are. A list binding (a block's repeater
     * bound to a repeater of the entry) adds `map` and `fixed` beside it, see cleanList().
     */
    public static function isBinding(mixed $value): bool
    {
        return is_array($value) && is_string($value['bind'] ?? null) && preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $value['bind']) === 1;
    }

    /**
     * A list binding as it is stored: `{bind, map: {block column: entry column}, fixed: {block column: value}}`. `map` names, for a
     * column of the block's rows, the column of the entry's rows it shows (a column left out is empty); `fixed` gives a column one value
     * for every row, or `{first: value, rest: value}` to give the first row another (a primary button, then secondary ones). Keys that
     * are not written the way field keys are, and values that are not text, are dropped; the values themselves are checked by the block's
     * own column when the page is drawn, as typed ones are.
     *
     * @param array<string, mixed> $value a binding (isBinding())
     * @return array<string, mixed>
     */
    public static function cleanList(array $value): array
    {
        $key = static fn(mixed $k): bool => is_string($k) && preg_match('/^[a-z][a-z0-9_]*$/', $k) === 1;
        $text = static fn(mixed $v): ?string => is_string($v) || is_int($v) || is_float($v) ? mb_substr(trim((string)$v), 0, 2000) : null;
        $out = ['bind' => $value['bind']];
        $map = [];
        foreach (is_array($value['map'] ?? null) ? $value['map'] : [] as $column => $source) {
            if ($key($column) && $key($source)) {
                $map[$column] = $source;
            }
        }
        $fixed = [];
        foreach (is_array($value['fixed'] ?? null) ? $value['fixed'] : [] as $column => $one) {
            if (!$key($column)) {
                continue;
            }
            if (is_array($one)) {
                $first = $text($one['first'] ?? null);
                $rest = $text($one['rest'] ?? null);
                if ($first !== null && $first !== '' && $rest !== null && $rest !== '' && $first !== $rest) {
                    $fixed[$column] = ['first' => $first, 'rest' => $rest];
                } elseif ($first !== null && $first !== '') {
                    $fixed[$column] = $first;
                }
                continue;
            }
            $one = $text($one);
            if ($one !== null && $one !== '') {
                $fixed[$column] = $one;
            }
        }
        if ($map !== []) {
            $out['map'] = $map;
        }
        if ($fixed !== []) {
            $out['fixed'] = $fixed;
        }
        return $out;
    }

    /**
     * What every entry offers to bind whatever its type (title, excerpt, main image, date, author, terms): the sources of a card that
     * lists entries of several content types, as on the page of a category.
     *
     * @return list<array<string, mixed>>
     */
    public static function commonSources(): array
    {
        $out = [];
        foreach (self::SOURCES as $path => [$label, $kind]) {
            $out[] = ['path' => $path, 'key' => $path, 'label' => $label, 'kind' => $kind, 'group' => 'Entry'];
        }
        return $out;
    }

    /**
     * What a content type offers to bind, for the picker of the template editor: the common fields, then the declared ones (a retired
     * field is not offered), each with the kind that decides which block fields it fits.
     *
     * @return list<array<string, mixed>> path, key, label, kind and group; a list (kind `list`) also has its `columns`
     */
    public static function sources(ContentTypes $types, string $type, string $lang, string $defaultLang): array
    {
        $out = [];
        foreach (self::SOURCES as $path => [$label, $kind]) {
            $out[] = ['path' => $path, 'key' => $path, 'label' => $label, 'kind' => $kind, 'group' => 'Entry'];
        }
        foreach ($types->editableFields($type, $lang, $defaultLang) as $key => $field) {
            $source = ['path' => 'custom_fields.' . $key, 'key' => (string)$key, 'label' => $field['label'], 'kind' => $field['type'], 'group' => $types->definition($type, $lang, $defaultLang)['label']];
            if ($field['type'] === 'repeater') {
                // A list of rows is bound to a block's list, with the columns of its rows to map (an inner list has none to offer).
                $source['kind'] = 'list';
                $source['columns'] = [];
                foreach ($field['fields'] as $columnKey => $column) {
                    if ($column['type'] !== 'repeater') {
                        $source['columns'][] = ['key' => (string)$columnKey, 'label' => $column['label'], 'kind' => $column['type']];
                    }
                }
            } elseif ($field['type'] === 'gallery') {
                $source['kind'] = 'list';
                $source['columns'] = [['key' => self::GALLERY_COLUMN, 'label' => 'Image', 'kind' => 'image']];
            }
            $out[] = $source;
            if ($field['type'] === 'relation') {
                array_pop($out);
                array_push($out, ...self::relationSources($types, $source, $field, $lang, $defaultLang));
            }
        }
        // The entries of other types that point at this one, each field a list to bind.
        foreach ($types->defined() as $from) {
            $definition = $types->definition($from, $lang, $defaultLang);
            foreach (RelationFields::paths($definition['fields']) as $found) {
                $targets = $found['field']['types'] ?? [];
                if (($targets !== [] && !in_array($type, $targets, true)) || $found['field']['hidden']) {
                    continue;
                }
                $out[] = ['path' => 'referenced_by.' . $from . '.' . $found['path'], 'key' => $from . '.' . $found['path'], 'label' => $definition['label'] . ' · ' . $found['field']['label'], 'kind' => 'list', 'group' => 'Pointing here', 'columns' => self::cardColumns($types, $from, $lang, $defaultLang)];
            }
        }
        return $out;
    }

    /**
     * What a relation field offers: for one entry the fields of the entry it points at (`custom_fields.author.title`, ...; the ones the
     * type declares when the field names a single type), the titles of all of them for a field that takes several, and for such a field a
     * list whose rows are the entries pointed at.
     *
     * @param array<string, mixed> $source the field as a source
     * @param array<string, mixed> $field the relation field
     * @return list<array<string, mixed>>
     */
    private static function relationSources(ContentTypes $types, array $source, array $field, string $lang, string $defaultLang): array
    {
        $group = $field['label'];
        $target = count($field['types'] ?? []) === 1 ? $field['types'][0] : '';
        $source['relation'] = true;
        if ($field['multiple'] ?? false) {
            $source['kind'] = 'list';
            $source['columns'] = self::cardColumns($types, $target, $lang, $defaultLang);
            return [
                $source,
                ['path' => $source['path'] . '.title', 'key' => $source['key'] . '.title', 'label' => $field['label'] . ' · all titles', 'kind' => 'text', 'group' => $group],
            ];
        }
        $source['kind'] = 'relation';
        $out = [$source];
        foreach (self::CARD as $key => [$label, $kind]) {
            $out[] = ['path' => $source['path'] . '.' . $key, 'key' => $source['key'] . '.' . $key, 'label' => $field['label'] . ' · ' . $label, 'kind' => $kind, 'group' => $group];
        }
        if ($target !== '') {
            foreach ($types->editableFields($target, $lang, $defaultLang) as $key => $own) {
                if (!in_array($own['type'], ['repeater', 'relation'], true)) {
                    $out[] = ['path' => $source['path'] . '.custom_fields.' . $key, 'key' => $source['key'] . '.' . $key, 'label' => $field['label'] . ' · ' . $own['label'], 'kind' => $own['type'], 'group' => $group];
                }
            }
        }
        return $out;
    }

    /**
     * The columns of a list of entries (a relation that takes several, or what points at an entry): the card's, and the fields the
     * type declares when there is one type (`field_<key>`).
     *
     * @return list<array{key: string, label: string, kind: string}>
     */
    private static function cardColumns(ContentTypes $types, string $target, string $lang, string $defaultLang): array
    {
        $columns = [];
        foreach (self::CARD as $key => [$label, $kind]) {
            $columns[] = ['key' => $key, 'label' => $label, 'kind' => $kind];
        }
        if ($target !== '') {
            foreach ($types->editableFields($target, $lang, $defaultLang) as $key => $own) {
                if (!in_array($own['type'], ['repeater', 'relation'], true)) {
                    $columns[] = ['key' => 'field_' . $key, 'label' => $own['label'], 'kind' => $own['type']];
                }
            }
        }
        return $columns;
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
            if ($field['type'] === 'repeater') {
                $rows = $this->rows($raw[$key], $field, $missing);
                if ($rows === null) {
                    $missing[] = $path;
                    unset($raw[$key]);
                    continue;
                }
                $raw[$key] = $rows;
                $filled += $rows === [] ? 0 : 1;
                continue;
            }
            $value = isset(self::ACCEPTS[$field['type']]) ? $this->value($path, $field['type']) : null;
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
        if (!str_starts_with($path, 'custom_fields.')) {
            return null;
        }
        if (substr_count($path, '.') > 1) {
            return $this->relatedValue(explode('.', $path), $fieldType);
        }
        $key = substr($path, strlen('custom_fields.'));
        $field = $this->types->editableFields($this->item->type, $this->lang, $this->defaultLang)[$key] ?? null;
        if ($field === null || !in_array($field['type'], $kinds, true)) {
            return null;
        }
        $values = $this->types->values($this->item->type, $this->item->meta, $this->lang, $this->defaultLang);
        return array_key_exists($key, $values) ? $this->scalar($field, $values[$key]) : '';
    }

    /**
     * A field of the entry a relation points at: `custom_fields.<relation>.<title|excerpt|main_image|date|url>`, or
     * `custom_fields.<relation>.custom_fields.<key>` for a field of the type the relation names. For a relation that takes several only
     * `.title` is offered (all titles in a line). Null when the path leads nowhere or the kind does not fit; '' when there is no entry.
     *
     * @param list<string> $parts the path split at the dots
     */
    private function relatedValue(array $parts, string $fieldType): ?string
    {
        $kinds = self::ACCEPTS[$fieldType] ?? [];
        $field = $this->types->editableFields($this->item->type, $this->lang, $this->defaultLang)[$parts[1]] ?? null;
        if ($field === null || $field['type'] !== 'relation') {
            return null;
        }
        $rest = implode('.', array_slice($parts, 2));
        $multiple = ($field['multiple'] ?? false) === true;
        if ($multiple && $rest !== 'title') {
            return null;
        }
        $target = count($field['types'] ?? []) === 1 ? $field['types'][0] : '';
        if (isset(self::CARD[$rest])) {
            $kind = self::CARD[$rest][1];
        } elseif ($target !== '' && $this->depth < 1 && preg_match('/^custom_fields\.([a-z][a-z0-9_]*)$/', $rest, $m) === 1) {
            $own = $this->types->editableFields($target, $this->lang, $this->defaultLang)[$m[1]] ?? null;
            if ($own === null || in_array($own['type'], ['repeater', 'relation'], true)) {
                return null;
            }
            $kind = $own['type'];
        } else {
            return null;
        }
        if (!in_array($kind, $kinds, true)) {
            return null;
        }
        $cards = $this->relations?->related($this->item, $parts[1], $this->lang) ?? [];
        if ($cards === []) {
            return '';
        }
        if ($multiple) {
            return implode(', ', array_column($cards, 'title'));
        }
        $card = $cards[0];
        if (isset(self::CARD[$rest])) {
            return $this->cardValue($card, $rest);
        }
        $entry = $this->relations?->entry($card);
        return $entry === null ? '' : (new self($entry, $this->types, $this->lang, $this->defaultLang, $this->formatDate, $this->termLabel, $this->relations, $this->depth + 1))->value($rest, $fieldType);
    }

    /** One value of the card of an entry that is pointed at, as the text a block's field takes. @param array<string, mixed> $card */
    private function cardValue(array $card, string $key): string
    {
        return match ($key) {
            'date' => $this->date((string)($card['date'] ?? '')),
            default => $this->text($card[$key] ?? ''),
        };
    }

    /**
     * The rows of a list binding as a block's repeater takes them: one row for each row of the entry's list, with the columns of the
     * map filled from the row and the fixed ones given their value. The rows are not checked yet (the block's own field does that, so
     * `max`, empty rows and unsafe values are handled as for typed rows). Null when the source is not a list of this type any more.
     *
     * @param array<string, mixed> $binding the stored binding
     * @param array<string, mixed> $field the block's repeater
     * @param list<string> $missing gets the columns the entry's rows no longer have
     * @return array<int, array<string, mixed>>|null
     */
    private function rows(array $binding, array $field, array &$missing): ?array
    {
        $path = (string)$binding['bind'];
        if (str_starts_with($path, 'referenced_by.') || (str_starts_with($path, 'custom_fields.') && substr_count($path, '.') === 1)) {
            $kind = $this->types->editableFields($this->item->type, $this->lang, $this->defaultLang)[substr($path, strlen('custom_fields.'))] ?? null;
            if (str_starts_with($path, 'referenced_by.') || ($kind !== null && $kind['type'] === 'relation')) {
                return $this->entryRows($binding, $field, $missing);
            }
        }
        if (!str_starts_with($path, 'custom_fields.') || substr_count($path, '.') !== 1) {
            return null;
        }
        $key = substr($path, strlen('custom_fields.'));
        $source = $this->types->editableFields($this->item->type, $this->lang, $this->defaultLang)[$key] ?? null;
        if ($source !== null && $source['type'] === 'gallery') {
            return $this->galleryRows($binding, $field, $key, $missing);
        }
        if ($source === null || $source['type'] !== 'repeater') {
            return null;
        }
        $binding = self::cleanList($binding);
        // Each column of the block that takes a column of the entry's rows, if that column is still there and still fits.
        $columns = [];
        foreach ($binding['map'] ?? [] as $column => $from) {
            $to = $field['fields'][$column] ?? null;
            $has = $source['fields'][$from] ?? null;
            if ($to === null || $to['type'] === 'repeater' || !isset(self::ACCEPTS[$to['type']])) {
                continue;
            }
            if ($has === null || $has['type'] === 'repeater' || !in_array($has['type'], self::ACCEPTS[$to['type']], true)) {
                $missing[] = $path . '.' . $from;
                continue;
            }
            $columns[$column] = $from;
        }
        $values = $this->types->values($this->item->type, $this->item->meta, $this->lang, $this->defaultLang);
        $rows = [];
        foreach (is_array($values[$key] ?? null) ? array_values($values[$key]) : [] as $r => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out = [];
            $any = false;
            foreach ($columns as $column => $from) {
                $out[$column] = array_key_exists($from, $row) ? $this->scalar($source['fields'][$from], $row[$from]) : '';
                $any = $any || $out[$column] !== '';
            }
            if (!$any) {
                continue;
            }
            // The first row of the list that is drawn gets the first value of a fixed column, the others the rest.
            foreach ($binding['fixed'] ?? [] as $column => $one) {
                if (isset($field['fields'][$column]) && !isset($columns[$column]) && $field['fields'][$column]['type'] !== 'repeater') {
                    $out[$column] = is_array($one) ? ($rows === [] ? $one['first'] : $one['rest']) : $one;
                }
            }
            $rows[] = $out;
        }
        return $rows;
    }

    /**
     * The rows of a list bound to a gallery field: one row for each picture, the block's columns mapped to `image` getting its address
     * (a column mapped to anything else is missing), and the fixed ones their value.
     *
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $field the block's repeater
     * @param list<string> $missing
     * @return array<int, array<string, mixed>>
     */
    private function galleryRows(array $binding, array $field, string $key, array &$missing): array
    {
        $binding = self::cleanList($binding);
        $columns = [];
        foreach ($binding['map'] ?? [] as $column => $from) {
            $to = $field['fields'][$column] ?? null;
            if ($to === null || $to['type'] === 'repeater' || !isset(self::ACCEPTS[$to['type']])) {
                continue;
            }
            if ($from !== self::GALLERY_COLUMN || !in_array('image', self::ACCEPTS[$to['type']], true)) {
                $missing[] = 'custom_fields.' . $key . '.' . $from;
                continue;
            }
            $columns[] = $column;
        }
        if ($columns === []) {
            return [];
        }
        $rows = [];
        foreach ($this->types->gallery($this->item->type, $this->item->meta, $key, $this->lang, $this->defaultLang) as $url) {
            $out = array_fill_keys($columns, $url);
            foreach ($binding['fixed'] ?? [] as $column => $one) {
                if (isset($field['fields'][$column]) && !in_array($column, $columns, true) && $field['fields'][$column]['type'] !== 'repeater') {
                    $out[$column] = is_array($one) ? ($rows === [] ? $one['first'] : $one['rest']) : $one;
                }
            }
            $rows[] = $out;
        }
        return $rows;
    }

    /**
     * The rows of a list bound to entries: the ones a relation field points at (`custom_fields.<key>`), or the ones that point at this
     * entry (`referenced_by.<type>.<field>`). A column of the block shows a column of the card (CARD) or `field_<key>`, a field the type of
     * those entries declares, read from the entry's file only when some column asks for one. Null when the source is not there any more.
     *
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $field the block's repeater
     * @param list<string> $missing
     * @return array<int, array<string, mixed>>|null
     */
    private function entryRows(array $binding, array $field, array &$missing): ?array
    {
        $path = (string)$binding['bind'];
        if ($this->relations === null) {
            return [];
        }
        if (str_starts_with($path, 'referenced_by.')) {
            [$from, $fieldPath] = array_pad(explode('.', substr($path, strlen('referenced_by.')), 2), 2, '');
            $definition = $this->types->definition($from, $this->lang, $this->defaultLang)['fields'];
            $found = array_values(array_filter(RelationFields::paths($definition), static fn(array $f): bool => $f['path'] === $fieldPath))[0] ?? null;
            if ($found === null || !isset($this->types->definition($from, $this->lang, $this->defaultLang)['fields'])) {
                return null;
            }
            $targets = $found['field']['types'] ?? [];
            if ($targets !== [] && !in_array($this->item->type, $targets, true)) {
                return null;
            }
            $type = $from;
            $cards = $this->relations->referencedBy($this->item, $from, $fieldPath, $this->lang);
        } else {
            $relation = $this->types->editableFields($this->item->type, $this->lang, $this->defaultLang)[substr($path, strlen('custom_fields.'))] ?? null;
            if ($relation === null || $relation['type'] !== 'relation') {
                return null;
            }
            $type = count($relation['types'] ?? []) === 1 ? $relation['types'][0] : '';
            $cards = $this->relations->related($this->item, substr($path, strlen('custom_fields.')), $this->lang);
        }
        $own = $type !== '' ? $this->types->editableFields($type, $this->lang, $this->defaultLang) : [];
        $binding = self::cleanList($binding);
        $columns = [];
        foreach ($binding['map'] ?? [] as $column => $from) {
            $to = $field['fields'][$column] ?? null;
            if ($to === null || $to['type'] === 'repeater' || !isset(self::ACCEPTS[$to['type']])) {
                continue;
            }
            $kind = self::CARD[$from][1] ?? (str_starts_with($from, 'field_') && isset($own[substr($from, 6)]) && !in_array($own[substr($from, 6)]['type'], ['repeater', 'relation'], true) ? $own[substr($from, 6)]['type'] : null);
            if ($kind === null || !in_array($kind, self::ACCEPTS[$to['type']], true)) {
                $missing[] = $path . '.' . $from;
                continue;
            }
            $columns[$column] = $from;
        }
        $rows = [];
        foreach ($cards as $card) {
            $item = null;
            $out = [];
            $any = false;
            foreach ($columns as $column => $from) {
                if (isset(self::CARD[$from])) {
                    $out[$column] = $this->cardValue($card, $from);
                } else {
                    $key = substr($from, 6);
                    $item ??= $this->relations->entry($card);
                    $values = $item !== null ? $this->types->values($type, $item->meta, $this->lang, $this->defaultLang) : [];
                    $out[$column] = array_key_exists($key, $values) ? $this->scalar($own[$key], $values[$key]) : '';
                }
                $any = $any || $out[$column] !== '';
            }
            if (!$any) {
                continue;
            }
            foreach ($binding['fixed'] ?? [] as $column => $one) {
                if (isset($field['fields'][$column]) && !isset($columns[$column]) && $field['fields'][$column]['type'] !== 'repeater') {
                    $out[$column] = is_array($one) ? ($rows === [] ? $one['first'] : $one['rest']) : $one;
                }
            }
            $rows[] = $out;
        }
        return $rows;
    }

    /** A value of a field as the text a block's field takes (see value()). @param array<string, mixed> $field */
    private function scalar(array $field, mixed $value): string
    {
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
