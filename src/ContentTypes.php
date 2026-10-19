<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Definitions of content types: the fields an editor fills in and how the type is listed.
 *
 * A type is still a folder in content/. A definition adds to it and is optional:
 *   themes/<theme>/content-types/<type>.yaml   ships with the theme
 *   custom/content-types/<type>.yaml           belongs to the site, kept across updates
 * A site file for a type the theme also defines is merged in: fields are added or changed one by
 * one (mark a theme field `hidden: true` to retire it), and archive settings replace the theme's.
 *
 *   label / singular / description   text, or a map per language (el: …, en: …)
 *   fields:                          key => definition, see FieldSchema; a repeater (a list of rows) may hold one repeater
 *                                    level of its own, and is stored as a YAML list of maps
 *     filterable: true               select and relation fields: offered as a filter in the archive
 *     card: true                     shown on the item's card (a repeater as a short list of its rows)
 *     show: false                    not shown on the item's own page
 *   single:                          how the type's own page can be set in Theme > Single Layouts
 *     sidebar: true                  its template draws a sidebar when the card asks for one (None, Right, Left)
 *     header: true                   its template opens with a section the header can sit over
 *     options:                       options of this page, key => definition (select, toggle, number, text, ...), drawn on
 *                                    the card and stored with the theme settings; templates read single_layout(type).options
 *   archive_options:                 the same, for the type's list (Theme > Archive Layouts); the values are `archive: {options: {...}}`
 *                                    and templates read archive.settings.options
 *   archive:
 *     title, subtitle                text or a map per language
 *     layout, columns, per_page, order (newest, oldest, title, random, or field:<key>:asc|desc), show_image,
 *     show_excerpt, show_date, show_meta
 *     taxonomies: [categories]       taxonomies offered as filters
 *
 * Values are stored in the item's front matter under `custom_fields`, and are always checked
 * against the definition before they reach a template.
 */
final class ContentTypes
{
    public const LAYOUTS = [
        'cards' => 'Cards',
        'list' => 'Minimal list',
        'compact' => 'Compact with thumbnails',
        'overlay' => 'Image tiles with text on top',
        'featured' => 'Featured story and a list',
        'magazine' => 'Magazine',
        'editorial' => 'Editorial',
        'map' => 'Map with a list',
    ];

    /** The title areas a list can open with: the ones an entry's page has (Hero block layouts). */
    public const TITLE_LAYOUTS = ['default' => 'Default', 'centered' => 'Centered', 'split' => 'Split', 'cover' => 'Cover', 'minimal' => 'Minimal'];

    /** How tall the map of the map layout is, and where its list sits. */
    public const MAP_HEIGHTS = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'tall' => 'Tall'];
    public const MAP_LISTS = ['right' => 'Right', 'left' => 'Left', 'below' => 'Below', 'over_right' => 'Over the map, right', 'over_left' => 'Over the map, left', 'none' => 'No list'];

    public const ORDERS = [
        'date_desc' => 'Newest first',
        'date_asc' => 'Oldest first',
        'title_asc' => 'Title A–Z',
        'title_desc' => 'Title Z–A',
        'random' => 'Random (a new order for each visit)',
    ];

    /** Field types an archive can be ordered by. */
    public const SORTABLE_TYPES = ['date', 'number', 'decimal', 'text'];

    public const FIELD_TYPES = ['text', 'textarea', 'markdown', 'email', 'url', 'link', 'image', 'file', 'location', 'color', 'number', 'decimal', 'date', 'select', 'toggle', 'repeater', 'relation'];

    /** The kinds of field Admin > Content types can create: a repeater needs fields of its own, so it is written in the type's YAML file. */
    public const FORM_FIELD_TYPES = ['text', 'textarea', 'markdown', 'email', 'url', 'link', 'image', 'file', 'location', 'color', 'number', 'decimal', 'date', 'select', 'toggle'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $raw = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $themeRaw = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $customRaw = null;
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $archiveSchema = null;

    /** @var (\Closure(array<int, string>, array<int, string>, string): array<int, array<string, mixed>>)|null ids, content types allowed, language => entries to link */
    private ?\Closure $relations = null;

    /** Lets the fields that point at other entries (relation) show them: the closure turns ids into the entries a visitor may see. */
    public function showRelationsWith(\Closure $resolve): void
    {
        $this->relations = $resolve;
    }

    /** @var (\Closure(string, string, string, string): array<int, array<string, mixed>>)|null id, type, field path, language => entries that point here */
    private ?\Closure $references = null;

    /** Lets a fact sheet list the entries that point at an entry through a relation that has a `reverse_label`. */
    public function showReferencesWith(\Closure $find): void
    {
        $this->references = $find;
    }

    public function __construct(private Theme $theme)
    {
    }

    /** Types that have a definition. @return string[] */
    public function defined(): array
    {
        return array_keys($this->rawAll());
    }

    /**
     * The types the theme ships a definition for: the catalogue a site switches on and off (Admin > Content types).
     * Types of the site's own are not in it; they are always on.
     *
     * @return string[]
     */
    public function catalogue(): array
    {
        return array_keys($this->loadDir($this->theme->path() . '/content-types', 'theme'));
    }

    public function has(string $type): bool
    {
        return isset($this->rawAll()[$type]);
    }

    /** 'theme', 'custom', 'theme+custom', or '' for a type without a definition. */
    public function origin(string $type): string
    {
        return (string)($this->rawAll()[$type]['_origin'] ?? '');
    }

    /**
     * The definition of a type with texts chosen for a language. Types without a file still get
     * a usable definition (no fields, default archive), so callers need no special cases.
     *
     * @return array{type: string, origin: string, label: string, singular: string, description: string, fields: array<string, array<string, mixed>>, archive: array<string, mixed>, archive_options: array<string, array<string, mixed>>, single: array{sidebar: bool, header: bool, options: array<string, array<string, mixed>>}}
     */
    public function definition(string $type, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $key = $type . '|' . $lang . '|' . $defaultLang;
        return $this->cache[$key] ??= $this->build($type, $this->rawAll()[$type] ?? [], $lang, $defaultLang);
    }

    /**
     * The definition as the theme alone ships it, without the site's file. The admin compares
     * against it so only real differences are written to custom/.
     *
     * @return array{type: string, origin: string, label: string, singular: string, description: string, fields: array<string, array<string, mixed>>, archive: array<string, mixed>, archive_options: array<string, array<string, mixed>>, single: array{sidebar: bool, header: bool, options: array<string, array<string, mixed>>}}
     */
    public function themeDefinition(string $type, string $lang = 'en', string $defaultLang = 'en'): array
    {
        return $this->build($type, $this->loadDir($this->theme->path() . '/content-types', 'theme')[$type] ?? [], $lang, $defaultLang);
    }

    /** What the site's own file for a type holds (empty when there is none). @return array<string, mixed> */
    public function customRaw(string $type): array
    {
        $raw = $this->loadDir($this->theme->customPath() . '/content-types', 'custom')[$type] ?? [];
        unset($raw['_origin']);
        return $raw;
    }

    /** Raw definition as merged from theme and site, before texts are chosen. @return array<string, mixed> */
    public function raw(string $type): array
    {
        return $this->rawAll()[$type] ?? [];
    }

    /**
     * Writes the site's definition of a type to custom/content-types/<type>.yaml, or removes the
     * file when there is nothing left to say. Returns false when the file cannot be written.
     *
     * @param array<string, mixed> $definition
     */
    public function saveCustom(string $type, array $definition): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $type)) {
            return false;
        }
        $dir = $this->theme->customPath() . '/content-types';
        $file = $dir . '/' . $type . '.yaml';
        $definition = array_filter($definition, static fn(mixed $value): bool => $value !== [] && $value !== '' && $value !== null);
        $this->raw = $this->customRaw = null;
        $this->cache = [];
        if ($definition === []) {
            return !is_file($file) || @unlink($file);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $yaml = "# Written by Admin > Content types. Kept across updates; theme definitions are merged underneath.\n"
            . Yaml::dump($definition, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        return @file_put_contents($file, $yaml, LOCK_EX) !== false;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{type: string, origin: string, label: string, singular: string, description: string, fields: array<string, array<string, mixed>>, archive: array<string, mixed>, archive_options: array<string, array<string, mixed>>, single: array{sidebar: bool, header: bool, options: array<string, array<string, mixed>>}}
     */
    private function build(string $type, array $raw, string $lang, string $defaultLang): array
    {
        $pick = static fn(mixed $value): mixed => self::pick($value, $lang, $defaultLang);

        $label = trim((string)$pick($raw['label'] ?? ''));
        $label = $label !== '' ? $label : ucfirst($type);
        $singular = trim((string)$pick($raw['singular'] ?? ''));

        $fields = [];
        foreach (is_array($raw['fields'] ?? null) ? $raw['fields'] : [] as $fieldKey => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $definition = self::pickTexts($definition, $pick);
            $normalized = FieldSchema::normalize([$fieldKey => $definition])[(string)$fieldKey] ?? null;
            if ($normalized === null || !in_array($normalized['type'], self::FIELD_TYPES, true)) {
                continue;
            }
            $normalized['filterable'] = ($definition['filterable'] ?? false) === true && in_array($normalized['type'], ['select', 'relation'], true);
            $normalized['card'] = ($definition['card'] ?? false) === true;
            $normalized['show'] = ($definition['show'] ?? true) !== false;
            $fields[(string)$fieldKey] = $normalized;
        }

        $archive = is_array($raw['archive'] ?? null) ? $raw['archive'] : [];
        $archiveValues = self::resolveArchive($archive, $pick);
        $archiveOptions = self::declareOptions($raw['archive_options'] ?? [], $pick);
        $archiveValues['options'] = $archiveOptions === [] ? [] : FieldSchema::resolve($archiveOptions, array_intersect_key(is_array($archive['options'] ?? null) ? $archive['options'] : [], $archiveOptions));
        $single = is_array($raw['single'] ?? null) ? $raw['single'] : [];

        // Ordering by a declared field: "field:<key>:asc" or "field:<key>:desc".
        $order = (string)($archive['order'] ?? '');
        if (preg_match('/^field:([a-z][a-z0-9_]*):(asc|desc)$/', $order, $m)
            && isset($fields[$m[1]]) && !$fields[$m[1]]['hidden']
            && in_array($fields[$m[1]]['type'], self::SORTABLE_TYPES, true)) {
            $archiveValues['order'] = $order;
        }

        return [
            'type' => $type,
            'origin' => (string)($raw['_origin'] ?? ''),
            'label' => $label,
            'singular' => $singular !== '' ? $singular : $label,
            'description' => trim((string)$pick($raw['description'] ?? '')),
            'fields' => $fields,
            'archive' => $archiveValues,
            'archive_options' => $archiveOptions,
            'single' => [
                'sidebar' => ($single['sidebar'] ?? false) === true,
                'header' => ($single['header'] ?? false) === true,
                'options' => self::declareOptions($single['options'] ?? [], $pick),
            ],
        ];
    }

    /**
     * A raw field definition with its texts (label, help, placeholder, a repeater's item_label, option labels) chosen for the
     * language, also in the fields of a repeater's rows.
     *
     * @param array<string, mixed> $definition
     * @param callable(mixed): mixed $pick
     * @return array<string, mixed>
     */
    private static function pickTexts(array $definition, callable $pick): array
    {
        foreach (['label', 'help', 'placeholder', 'item_label'] as $text) {
            if (isset($definition[$text])) {
                $definition[$text] = (string)$pick($definition[$text]);
            }
        }
        if (is_array($definition['options'] ?? null)) {
            foreach ($definition['options'] as $value => $optionLabel) {
                $definition['options'][$value] = is_array($optionLabel) ? (string)$pick($optionLabel) : $optionLabel;
            }
        }
        if (is_array($definition['fields'] ?? null)) {
            foreach ($definition['fields'] as $key => $sub) {
                if (is_array($sub)) {
                    $definition['fields'][$key] = self::pickTexts($sub, $pick);
                }
            }
        }
        return $definition;
    }

    /**
     * The options a type declares for its page or its list: each is a field definition with texts chosen for the language.
     * Only the kinds of field a card can draw (and the schema checks) are kept; every one is optional to fill in.
     *
     * @param callable(mixed): mixed $pick chooses the text for a language
     * @return array<string, array<string, mixed>>
     */
    public static function declareOptions(mixed $raw, callable $pick): array
    {
        $options = [];
        foreach (is_array($raw) ? $raw : [] as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $definition = self::pickTexts($definition, $pick);
            $normalized = FieldSchema::normalize([$key => $definition])[(string)$key] ?? null;
            if ($normalized !== null && in_array($normalized['type'], ['text', 'select', 'toggle', 'number', 'decimal', 'color'], true) && !$normalized['hidden']) {
                $options[(string)$key] = $normalized;
            }
        }
        return $options;
    }

    /**
     * Fields an editor can fill in (retired fields excluded).
     *
     * @return array<string, array<string, mixed>>
     */
    public function editableFields(string $type, string $lang = 'en', string $defaultLang = 'en'): array
    {
        return array_filter(
            $this->definition($type, $lang, $defaultLang)['fields'],
            static fn(array $field): bool => !$field['hidden']
        );
    }

    /**
     * Stored values of the declared fields, checked against the definition. Fields without a
     * value are left out.
     *
     * @param array<string, mixed> $meta front matter of the item
     * @return array<string, mixed>
     */
    public function values(string $type, array $meta, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $stored = is_array($meta['custom_fields'] ?? null) ? $meta['custom_fields'] : [];
        $values = [];
        foreach ($this->editableFields($type, $lang, $defaultLang) as $key => $field) {
            if ($field['type'] === 'repeater' && ($field['legacy'] ?? []) !== []) {
                $stored = self::withLegacyRow((string)$key, $field, $stored);
            }
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $raw = $stored[$key];
            if ($field['type'] === 'date' && is_int($raw)) {
                // An unquoted YAML date is read as a timestamp.
                $raw = gmdate('Y-m-d', $raw);
            }
            $value = FieldSchema::clean($field, $raw);
            if (self::isEmpty($field, $value)) {
                continue;
            }
            $values[$key] = $value;
        }
        return $values;
    }

    /**
     * Fields of an item ready to print: label, checked value, and a text to show. Empty values
     * are skipped, and so are fields not meant for the context.
     *
     * @param array<string, mixed> $meta
     * @param 'page'|'card' $context
     * @param \Closure(string): string $formatDate
     * @return array<int, array{key: string, label: string, type: string, value: mixed, display: string}>
     */
    public function display(string $type, array $meta, string $context, \Closure $formatDate, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $values = $this->values($type, $meta, $lang, $defaultLang);
        $fields = array_filter(
            $this->editableFields($type, $lang, $defaultLang),
            static fn(array $field): bool => $context === 'card' ? $field['card'] : $field['show']
        );
        $entries = $this->displayEntries($fields, $values, $formatDate, $lang, $defaultLang);
        return $context === 'card' ? $entries : array_merge($entries, $this->referencedEntries($type, $meta, $lang, $defaultLang));
    }

    /**
     * What points at an entry through a relation whose field says `reverse_label` (in the type that holds the field): one entry of the
     * fact sheet for each such field, listing the entries that point here, so the page of an author names its books without a template.
     *
     * @param array<string, mixed> $meta
     * @return array<int, array<string, mixed>>
     */
    private function referencedEntries(string $type, array $meta, string $lang, string $defaultLang): array
    {
        $id = trim((string)($meta['translation_id'] ?? ''));
        if ($this->references === null || $id === '') {
            return [];
        }
        $entries = [];
        foreach ($this->defined() as $from) {
            foreach (RelationFields::paths($this->definition($from, $lang, $defaultLang)['fields']) as $found) {
                $label = (string)($found['field']['reverse_label'] ?? '');
                $targets = $found['field']['types'] ?? [];
                if ($label === '' || ($targets !== [] && !in_array($type, $targets, true))) {
                    continue;
                }
                $items = ($this->references)($id, $from, $found['path'], $lang);
                if ($items !== []) {
                    $entries[] = ['key' => 'referenced_by.' . $from . '.' . $found['path'], 'label' => $label, 'type' => 'relation', 'value' => array_column($items, 'id'), 'items' => $items, 'display' => implode(', ', array_column($items, 'title'))];
                }
            }
        }
        return $entries;
    }

    /**
     * The fields of an item named by key, in that order, ready to print like display() (whatever their `show` setting says; a key
     * the type does not declare, or has retired, is skipped). A fact sheet in a template built with blocks chooses its fields this way.
     *
     * @param array<string, mixed> $meta
     * @param string[] $keys
     * @param \Closure(string): string $formatDate
     * @return array<int, array<string, mixed>>
     */
    public function displayFields(string $type, array $meta, array $keys, \Closure $formatDate, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $editable = $this->editableFields($type, $lang, $defaultLang);
        $chosen = [];
        foreach ($keys as $key) {
            if (isset($editable[$key])) {
                $chosen[$key] = $editable[$key];
            }
        }
        return $this->displayEntries($chosen, $this->values($type, $meta, $lang, $defaultLang), $formatDate, $lang, $defaultLang);
    }

    /**
     * Entries ready to print for checked values. A repeater's entry has `rows`, each a list of entries of its own (an inner
     * repeater the same way, one level down), and a `display` that names its rows in one line, for a card.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values checked values
     * @param \Closure(string): string $formatDate
     * @return array<int, array<string, mixed>>
     */
    private function displayEntries(array $fields, array $values, \Closure $formatDate, string $lang, string $defaultLang): array
    {
        $entries = [];
        foreach ($fields as $key => $field) {
            if ($field['hidden'] || !array_key_exists($key, $values) || self::isEmpty($field, $values[$key])) {
                continue;
            }
            $value = $values[$key];
            if (($field['translatable'] ?? false) === true && is_array($value)) {
                // A text with a version per language: the one of the page's language, else the site's own.
                $value = (string)(($lang !== $defaultLang ? ($value[$lang] ?? null) : null) ?? $value['default'] ?? '');
                if ($value === '') {
                    continue;
                }
            }
            $entry = ['key' => (string)$key, 'label' => $field['label'], 'type' => $field['type'], 'value' => $value];
            if ($field['type'] === 'repeater') {
                $rows = [];
                foreach (is_array($value) ? $value : [] as $row) {
                    $inner = $this->displayEntries($field['fields'], is_array($row) ? $row : [], $formatDate, $lang, $defaultLang);
                    if ($inner !== []) {
                        $rows[] = $inner;
                    }
                }
                if ($rows === []) {
                    continue;
                }
                $entry['rows'] = $rows;
                $entry['display'] = implode(', ', array_filter(array_map(
                    static fn(array $row): string => (string)(array_values(array_filter($row, static fn(array $e): bool => $e['type'] !== 'repeater' && $e['type'] !== 'image' && $e['display'] !== ''))[0]['display'] ?? ''),
                    $rows
                ), static fn(string $text): bool => $text !== ''));
                $entries[] = $entry;
                continue;
            }
            if ($field['type'] === 'relation') {
                // The entries a visitor may see (published, in this language or the site's own); a field whose entries are all
                // hidden or gone shows nothing.
                $items = $this->relations !== null ? ($this->relations)(RelationFields::idList($value), $field['types'] ?? [], $lang) : [];
                if ($items === []) {
                    continue;
                }
                $entry['items'] = $items;
                $entry['display'] = implode(', ', array_column($items, 'title'));
                $entries[] = $entry;
                continue;
            }
            $entry['display'] = match ($field['type']) {
                'select' => (string)($field['options'][(string)$value] ?? $value),
                'toggle' => $value === true ? 'yes' : '',
                'date' => $formatDate((string)$value),
                'url' => (string)(preg_replace('#^www\.#i', '', (string)parse_url((string)$value, PHP_URL_HOST)) ?: $value),
                'link' => (string)preg_replace('#^(mailto:|tel:|https?://(www\.)?)#i', '', (string)$value),
                default => is_scalar($value) ? (string)$value : '',
            };
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Values to store for the declared fields from a submitted form. Empty values are omitted so
     * front matter stays tidy; retired fields keep whatever they had.
     *
     * @param array<string, mixed> $input submitted values by field key
     * @param array<string, mixed> $existing the item's current custom_fields
     * @return array<string, mixed>
     */
    public function fromInput(string $type, array $input, array $existing, string $lang = 'en', string $defaultLang = 'en'): array
    {
        $store = [];
        $fields = $this->definition($type, $lang, $defaultLang)['fields'];
        // Flat keys a list replaces are not kept once the form is saved: by then their value is the list's first row.
        $replaced = [];
        foreach ($fields as $field) {
            if ($field['type'] === 'repeater' && !$field['hidden']) {
                $replaced = array_merge($replaced, array_values($field['legacy'] ?? []));
            }
        }
        foreach ($fields as $key => $field) {
            if ($field['hidden']) {
                if (array_key_exists($key, $existing) && !in_array((string)$key, $replaced, true)) {
                    $store[$key] = $existing[$key];
                }
                continue;
            }
            if ($field['type'] === 'toggle') {
                if (FieldSchema::isTruthy($input[$key] ?? false)) {
                    $store[$key] = true;
                }
                continue;
            }
            $raw = $input[$key] ?? '';
            // A list (a repeater's rows) or a text per language comes as a map; anything else is one value.
            $structured = is_array($raw) && ($field['type'] === 'repeater' || $field['type'] === 'relation' || ($field['translatable'] ?? false) === true);
            if (!$structured && (!is_scalar($raw) || trim((string)$raw) === '')) {
                continue;
            }
            if (in_array($field['type'], ['number', 'decimal'], true) && !is_numeric($raw)) {
                continue;
            }
            $value = FieldSchema::clean($field, $raw);
            if ($field['type'] === 'repeater') {
                $value = self::tidyRows($field, $value);
            }
            if (!self::isEmpty($field, $value)) {
                $store[$key] = $value;
            }
        }
        return $store;
    }

    /**
     * Gives a list without rows one row made of the flat keys it replaces (`legacy`), when they hold something: content saved
     * before the list took over keeps showing, and the editor offers that row first. Rows that exist always win.
     *
     * @param array<string, mixed> $field a repeater
     * @param array<string, mixed> $stored the item's custom_fields
     * @return array<string, mixed>
     */
    private static function withLegacyRow(string $key, array $field, array $stored): array
    {
        if (is_array($stored[$key] ?? null) && $stored[$key] !== []) {
            return $stored;
        }
        $row = [];
        foreach ($field['legacy'] as $rowKey => $oldKey) {
            if (is_scalar($stored[$oldKey] ?? null) && trim((string)$stored[$oldKey]) !== '') {
                $row[$rowKey] = $stored[$oldKey];
            }
        }
        if ($row !== []) {
            $stored[$key] = [$row];
        }
        return $stored;
    }

    /**
     * Checked rows as they are stored: empty values left out (also in an inner list), as for the type's own fields; reading them
     * back fills them in again.
     *
     * @param array<string, mixed> $field
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function tidyRows(array $field, array $rows): array
    {
        foreach ($rows as $i => $row) {
            foreach ($field['fields'] as $subKey => $sub) {
                if ($sub['type'] === 'repeater' && is_array($row[$subKey] ?? null)) {
                    $row[$subKey] = self::tidyRows($sub, $row[$subKey]);
                }
                if (array_key_exists($subKey, $row) && self::isEmpty($sub, $row[$subKey])) {
                    unset($row[$subKey]);
                }
            }
            $rows[$i] = $row;
        }
        return $rows;
    }

    /** Keys of every declared field, including retired ones. @return string[] */
    public function declaredKeys(string $type): array
    {
        return array_map('strval', array_keys($this->definition($type)['fields']));
    }

    /**
     * Orders an archive can use: the fixed ones plus one pair per sortable declared field.
     *
     * @param array<string, array<string, mixed>> $fields normalized fields of the type
     * @return array<string, string> value => label
     */
    public static function orderOptions(array $fields): array
    {
        $options = self::ORDERS;
        foreach ($fields as $key => $field) {
            if ($field['hidden'] || !in_array($field['type'], self::SORTABLE_TYPES, true)) {
                continue;
            }
            $ascending = $field['type'] === 'date' ? 'earliest first' : ($field['type'] === 'text' ? 'A–Z' : 'lowest first');
            $descending = $field['type'] === 'date' ? 'latest first' : ($field['type'] === 'text' ? 'Z–A' : 'highest first');
            $options['field:' . $key . ':asc'] = $field['label'] . ': ' . $ascending;
            $options['field:' . $key . ':desc'] = $field['label'] . ': ' . $descending;
        }
        return $options;
    }

    /** @return array<string, array<string, mixed>> */
    public static function archiveSchema(): array
    {
        return self::$archiveSchema ??= FieldSchema::normalize([
            'layout' => ['type' => 'select', 'label' => 'Layout', 'default' => 'cards', 'options' => self::LAYOUTS],
            'columns' => ['type' => 'select', 'label' => 'Columns', 'default' => '3', 'options' => ['2' => '2', '3' => '3', '4' => '4']],
            'per_page' => ['type' => 'number', 'label' => 'Items per page', 'default' => 12, 'min' => 0, 'max' => 60],
            'order' => ['type' => 'select', 'label' => 'Order', 'default' => 'date_desc', 'options' => self::ORDERS],
            'show_image' => ['type' => 'toggle', 'label' => 'Show images', 'default' => true],
            'show_excerpt' => ['type' => 'toggle', 'label' => 'Show the excerpt', 'default' => true],
            'show_date' => ['type' => 'toggle', 'label' => 'Show the date', 'default' => true],
            'show_meta' => ['type' => 'toggle', 'label' => 'Show category and card fields', 'default' => true],
            'map_height' => ['type' => 'select', 'label' => 'Map height', 'default' => 'large', 'options' => self::MAP_HEIGHTS],
            'map_list' => ['type' => 'select', 'label' => 'List beside the map', 'default' => 'right', 'options' => self::MAP_LISTS],
            // The title area above the list: one of the layouts of an entry's title area, with a picture it may use and parallax for it.
            'title_layout' => ['type' => 'select', 'label' => 'Title area', 'default' => 'default', 'options' => self::TITLE_LAYOUTS],
            'title_image' => ['type' => 'image', 'label' => 'Picture of the title area', 'default' => ''],
            'title_parallax' => ['type' => 'toggle', 'label' => 'Parallax', 'default' => false],
            // The trail above the title of the list: Home, then the list itself.
            'breadcrumbs' => ['type' => 'toggle', 'label' => 'Breadcrumbs', 'default' => false],
        ]);
    }

    /**
     * The archive settings of a type or taxonomy after checking every value against the schema: how it is laid out,
     * ordered, and paged, which parts of an entry show, and the texts above the list. Anything missing gets its default.
     *
     * @param array<string, mixed> $archive raw settings as written in a definition
     * @param callable(mixed): mixed $pick chooses the text for a language
     * @return array<string, mixed>
     */
    public static function resolveArchive(array $archive, callable $pick): array
    {
        $values = FieldSchema::resolve(self::archiveSchema(), array_intersect_key($archive, self::archiveSchema()));
        $values['title'] = trim((string)$pick($archive['title'] ?? ''));
        $values['subtitle'] = trim((string)$pick($archive['subtitle'] ?? ''));
        $values['taxonomies'] = array_values(array_filter(
            array_map('strval', is_array($archive['taxonomies'] ?? null) ? $archive['taxonomies'] : []),
            static fn(string $name): bool => (bool)preg_match('/^[a-z][a-z0-9_-]*$/', $name)
        ));
        return $values;
    }

    /**
     * What to store after the archive part of an admin form was submitted: only the values that differ from the
     * defaults, on top of whatever the file already holds (so keys the form does not know are kept).
     *
     * @param array<string, mixed> $input the submitted `archive[...]` values
     * @param array<string, mixed> $current what the site's file holds now
     * @param array<string, mixed> $defaults the resolved settings the site would have without its own file
     * @param string[] $taxonomyNames taxonomies that exist, the only ones that can be offered as filters
     * @param bool $fieldOrders whether an order by a declared field ("field:<key>:asc") is accepted
     * @param array<string, array<string, mixed>> $declared the options the type declares for its list (`archive_options`), whose values are `options`
     * @return array<string, mixed>
     */
    public static function archiveFromInput(array $input, array $current, array $defaults, array $taxonomyNames, bool $fieldOrders, array $declared = []): array
    {
        $schema = self::archiveSchema();
        $submitted = [];
        foreach (['layout', 'columns', 'map_height', 'map_list', 'title_layout'] as $key) {
            $submitted[$key] = FieldSchema::clean($schema[$key], $input[$key] ?? null);
        }
        // An invalid picture address changes nothing; an empty one takes the picture away.
        $image = trim((string)($input['title_image'] ?? ''));
        $submitted['title_image'] = $image === '' ? '' : (string)FieldSchema::clean($schema['title_image'], $image);
        $order = (string)($input['order'] ?? '');
        $submitted['order'] = isset(self::ORDERS[$order]) || ($fieldOrders && preg_match('/^field:[a-z][a-z0-9_]*:(asc|desc)$/', $order))
            ? $order
            : $defaults['order'];
        $submitted['per_page'] = FieldSchema::clean($schema['per_page'], $input['per_page'] ?? null);
        foreach (['show_image', 'show_excerpt', 'show_date', 'show_meta', 'title_parallax', 'breadcrumbs'] as $key) {
            $submitted[$key] = FieldSchema::isTruthy($input[$key] ?? false);
        }
        $chosen = array_map('strval', is_array($input['taxonomies'] ?? null) ? $input['taxonomies'] : []);
        $submitted['taxonomies'] = array_values(array_intersect($taxonomyNames, $chosen));
        foreach (['title', 'subtitle'] as $key) {
            $submitted[$key] = trim((string)preg_replace('/\s+/', ' ', (string)($input[$key] ?? '')));
        }
        foreach ($submitted as $key => $value) {
            if ($value === ($defaults[$key] ?? ($schema[$key]['default'] ?? null))) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }
        if ($declared !== []) {
            // The options of the type: what the form says for each declared one; only those that differ from the theme's are kept.
            $held = is_array($current['options'] ?? null) ? array_intersect_key($current['options'], $declared) : [];
            $now = FieldSchema::fromInput($declared, is_array($input['options'] ?? null) ? $input['options'] : [], FieldSchema::resolve($declared, $held));
            $base = is_array($defaults['options'] ?? null) ? $defaults['options'] : [];
            $differing = [];
            foreach ($declared as $key => $field) {
                if (($now[$key] ?? null) !== ($base[$key] ?? FieldSchema::clean($field, null))) {
                    $differing[$key] = $now[$key];
                }
            }
            if ($differing === []) {
                unset($current['options']);
            } else {
                $current['options'] = $differing;
            }
        }
        return $current;
    }

    /**
     * Picks the text for a language from a plain value or a map per language.
     */
    public static function pick(mixed $value, string $lang, string $defaultLang): mixed
    {
        if (!is_array($value) || array_is_list($value)) {
            return $value;
        }
        return $value[$lang] ?? $value[$defaultLang] ?? $value['en'] ?? reset($value);
    }

    /** @param array<string, mixed> $field */
    private static function isEmpty(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'toggle' => $value !== true,
            'number', 'decimal' => !is_int($value) && !is_float($value),
            default => $value === '' || $value === null || $value === [],
        };
    }

    /**
     * Theme and site definitions merged by type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rawAll(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }
        $this->themeRaw ??= $this->loadDir($this->theme->path() . '/content-types', 'theme');
        $this->customRaw ??= $this->loadDir($this->theme->customPath() . '/content-types', 'custom');
        $merged = $this->themeRaw;
        foreach ($this->customRaw as $type => $definition) {
            $definition['_origin'] = isset($merged[$type]) ? ($merged[$type]['_origin'] . '+custom') : 'custom';
            $merged[$type] = isset($merged[$type]) ? self::merge($merged[$type], $definition) : $definition;
        }
        ksort($merged);
        return $this->raw = $merged;
    }

    /**
     * Definitions found in one folder, by type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadDir(string $dir, string $origin): array
    {
        $found = [];
        $files = glob($dir . '/*.yaml') ?: [];
        sort($files);
        foreach ($files as $file) {
            $type = basename($file, '.yaml');
            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $type)) {
                continue;
            }
            try {
                $definition = Yaml::parseFile($file);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($definition)) {
                $definition['_origin'] = $origin;
                $found[$type] = $definition;
            }
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if ($key === 'fields' && is_array($value)) {
                $fields = is_array($base['fields'] ?? null) ? $base['fields'] : [];
                foreach ($value as $fieldKey => $definition) {
                    $fields[$fieldKey] = is_array($definition) && is_array($fields[$fieldKey] ?? null)
                        ? array_replace($fields[$fieldKey], $definition)
                        : $definition;
                }
                $base['fields'] = $fields;
            } elseif (($key === 'archive_options' || ($key === 'single' && isset($value['options']))) && is_array($value)) {
                // Declared options are merged one by one, like fields.
                $declared = $key === 'single' ? ($value['options'] ?? []) : $value;
                $held = $key === 'single' ? ($base['single']['options'] ?? []) : ($base['archive_options'] ?? []);
                $held = is_array($held) ? $held : [];
                foreach (is_array($declared) ? $declared : [] as $optionKey => $definition) {
                    $held[$optionKey] = is_array($definition) && is_array($held[$optionKey] ?? null) ? array_replace($held[$optionKey], $definition) : $definition;
                }
                if ($key === 'single') {
                    $base['single'] = array_replace(is_array($base['single'] ?? null) ? $base['single'] : [], $value, ['options' => $held]);
                } else {
                    $base['archive_options'] = $held;
                }
            } elseif ($key === 'archive' && is_array($value)) {
                $base['archive'] = array_replace(is_array($base['archive'] ?? null) ? $base['archive'] : [], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
