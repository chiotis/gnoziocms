<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Block definitions from themes/<theme>/blocks/<type>/block.yaml, plus new block types a site
 * adds in custom/blocks/<type>/block.yaml. A theme block's definition cannot be replaced from
 * custom/ (its template and CSS can be overridden or extended by path instead).
 *
 * block.yaml keys: label, description, variants (value: label), tone, spacing, fields.
 * Field extras understood here: `type: icon` (select from the icon set) and
 * `options_from: <source>` (select options supplied by the application, e.g. content types).
 */
final class BlockRegistry
{
    public const TONES = ['default' => 'Default', 'muted' => 'Muted', 'contrast' => 'Contrast', 'accent' => 'Accent'];
    /** Share of the row a block takes (percent); 100 is today's full-width section and is never written to a page. */
    public const WIDTHS = ['100' => '100%', '75' => '75%', '66' => '66%', '50' => '50%', '33' => '33%', '25' => '25%'];
    public const SPACINGS = ['default' => 'Default', 'compact' => 'Compact', 'spacious' => 'Spacious', 'none' => 'None'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitions = null;

    /**
     * @param array<string, \Closure(): array<string, string>> $optionSources
     */
    public function __construct(private Theme $theme, private array $optionSources = [])
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $definitions = [];
        foreach ($this->theme->blockRoots() as $origin => $root) {
            foreach (glob($root . '/*/block.yaml') ?: [] as $file) {
                $type = basename(dirname($file));
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $type) || isset($definitions[$type]) || $type === BlockContainer::TYPE) {
                    continue;
                }
                $definition = $this->load($type, $file, $origin);
                if ($definition !== null) {
                    $definitions[$type] = $definition;
                }
            }
        }
        ksort($definitions);
        return $this->definitions = $definitions;
    }

    /** @return array<string, mixed>|null */
    public function get(string $type): ?array
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * Fields as the editor reads them: select options become ordered [value, label] pairs, so JSON keeps their order and string values.
     *
     * @param array<string, array<string, mixed>> $fields normalized definitions
     * @return array<int, array<string, mixed>>
     */
    public static function fieldsForEditor(array $fields): array
    {
        $list = [];
        foreach ($fields as $field) {
            if (isset($field['options'])) {
                $pairs = [];
                foreach ($field['options'] as $value => $label) {
                    $pairs[] = [(string)$value, (string)$label];
                }
                $field['options'] = $pairs;
            }
            if (isset($field['fields'])) {
                $field['fields'] = self::fieldsForEditor($field['fields']);
            }
            $list[] = $field;
        }
        return $list;
    }

    /**
     * Definitions for the admin block editor: fields with defaults, and select options as ordered
     * [value, label] pairs so JSON keeps their order and string values. The blocks that only a template built with blocks offers
     * (`scope: template`) are left out unless the editor is one; a text field that names a field of the entry (`source: entry_field`)
     * is a choice among `$entryFields` there (the paths and labels EntryBindings::sources() gives for the template's content type).
     *
     * The blocks of `scope: archive` (they draw a part of a list of entries) are offered only by the editor of an archive template
     * (`$scope` 'archive'); `$scope` true or 'template' is a template for the page of an entry. The editor of a card offers neither.
     * A select field that names `source: sort_order` is a choice among `$sortOrders` there (the orders the list knows).
     *
     * @param bool|string $scope false: the blocks of any page; true or 'template': also those of an entry's template; 'archive': also those of a list's template
     * @param list<array{path: string, label: string}> $entryFields
     * @param array<string, string> $sortOrders
     * @return array<int, array<string, mixed>>
     */
    public function editorDefinitions(bool|string $scope = false, array $entryFields = [], array $sortOrders = []): array
    {
        $scope = $scope === true ? 'template' : ($scope === false ? '' : $scope);
        $prepare = static fn(array $fields): array => self::fieldsForEditor(self::withEntryChoices($fields, $entryFields, $sortOrders));
        $definitions = [];
        foreach ($this->all() as $definition) {
            if ($definition['scope'] !== '' && $definition['scope'] !== $scope) {
                continue;
            }
            $definitions[] = [
                'type' => $definition['type'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'category' => $definition['category'] ?? '',
                'origin' => $definition['origin'],
                'preview' => $definition['preview'] ?? '',
                'variant_when' => $definition['variant_when'] === [] ? new \stdClass() : $definition['variant_when'],
                'variant_previews' => ($definition['variant_previews'] ?? []) === [] ? new \stdClass() : $definition['variant_previews'],
                'design_fields' => $definition['design_fields'] ?? [],
                'common' => $prepare($definition['common']),
                'fields' => $prepare($definition['fields']),
            ];
        }
        return $definitions;
    }

    /**
     * Fields that name a field of the entry as choices (see editorDefinitions), also inside the rows of a repeater.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param list<array{path: string, label: string}> $entryFields
     * @return array<string, array<string, mixed>>
     */
    private static function withEntryChoices(array $fields, array $entryFields, array $sortOrders = []): array
    {
        foreach ($fields as $key => $field) {
            if (($field['source'] ?? '') === 'sort_order') {
                // The orders the list knows; a template without a list's orders offers the common ones.
                $fields[$key]['type'] = 'select';
                $fields[$key]['options'] = $sortOrders !== [] ? $sortOrders : ContentTypes::ORDERS;
            } elseif (($field['source'] ?? '') === 'entry_field') {
                $options = ['' => '—'];
                foreach ($entryFields as $source) {
                    $options[$source['path']] = $source['label'];
                }
                $fields[$key]['type'] = 'select';
                $fields[$key]['options'] = $options;
            } elseif ($field['type'] === 'repeater') {
                $fields[$key]['fields'] = self::withEntryChoices($field['fields'], $entryFields, $sortOrders);
            }
        }
        return $fields;
    }

    /**
     * A block as its definition means it now: one saved in a layout that has since become another (the hero's text alignment of
     * 0.1.86, now two layouts) is moved to it, a Slider block (gone in 0.1.110) becomes a Hero slider, and the two fixed buttons
     * of a Hero slider's slide (until 0.1.156) become its list of buttons. Anything else is returned as it is.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function migrate(array $raw): array
    {
        if (($raw['type'] ?? null) === 'slider' && $this->get('slider') === null && $this->get('hero-slider') !== null) {
            $raw = self::slideToHeroSlider($raw);
        }
        if (($raw['type'] ?? null) === 'hero-slider' && is_array($raw['items'] ?? null)) {
            $raw['items'] = array_map(static fn(mixed $slide): mixed => is_array($slide) ? self::slideButtons($slide) : $slide, $raw['items']);
        }
        $definition = is_string($raw['type'] ?? null) ? $this->get($raw['type']) : null;
        // A block that used `width` for something of its own before every block got a Width (the divider's line) keeps that choice in
        // the field named by `width_moved_to`; only a value that is not one of the shared Width's is moved.
        $moved = $definition['width_moved_to'] ?? '';
        if ($moved !== '' && array_key_exists('width', $raw) && !array_key_exists((string)(is_scalar($raw['width']) ? $raw['width'] : ''), self::WIDTHS)) {
            if (!array_key_exists($moved, $raw)) {
                $raw[$moved] = $raw['width'];
            }
            unset($raw['width']);
        }
        // The FAQ's "Open the first answer" toggle (`open_first`, until 0.1.135) is the "When the page opens" choice now.
        if (($raw['type'] ?? null) === 'faq' && array_key_exists('open_first', $raw)) {
            if (!array_key_exists('initial', $raw) && in_array($raw['open_first'], [true, 1, '1', 'true', 'yes', 'on'], true)) {
                $raw['initial'] = 'first';
            }
            unset($raw['open_first']);
        }
        foreach ($definition['variant_migrations'] ?? [] as $migration) {
            $matches = true;
            foreach ($migration['when'] as $key => $wanted) {
                $matches = $matches && is_scalar($raw[$key] ?? null) && (string)$raw[$key] === $wanted;
            }
            if ($matches && $migration['when'] !== []) {
                $raw['variant'] = $migration['to'];
                foreach ($migration['drop'] as $key) {
                    unset($raw[$key]);
                }
                break;
            }
        }
        return $raw;
    }

    /**
     * The blocks of a page as the editor shows them: each migrated, also the blocks inside the columns of a container.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, mixed>
     */
    public function forEditor(array $blocks): array
    {
        return array_map(function (mixed $block): mixed {
            if (!is_array($block)) {
                return $block;
            }
            if (($block['type'] ?? null) === BlockContainer::TYPE) {
                foreach (is_array($block['columns'] ?? null) ? array_values($block['columns']) : [] as $c => $column) {
                    if (is_array($column) && is_array($column['blocks'] ?? null)) {
                        $block['columns'][$c]['blocks'] = $this->forEditor(array_values($column['blocks']));
                    }
                }
                return $block;
            }
            return $this->migrate($block);
        }, $blocks);
    }

    /**
     * A slide of the Hero slider with its buttons as a list: the two fixed buttons a slide had until 0.1.156 (`label`, `url`,
     * `style` and `label_2`, `url_2`, `style_2`) become the rows of `buttons`, in order, a button with neither a text nor a
     * link left out. Each keeps the style it had, also the one it had by default (the first primary, the second
     * secondary). A slide that has `buttons` already keeps them; the old keys are dropped either way.
     *
     * @param array<string, mixed> $slide
     * @return array<string, mixed>
     */
    private static function slideButtons(array $slide): array
    {
        $pairs = [['label', 'url', 'style', 'primary'], ['label_2', 'url_2', 'style_2', 'secondary']];
        $old = array_merge(...array_map(static fn(array $pair): array => array_slice($pair, 0, 3), $pairs));
        if (array_intersect_key($slide, array_flip($old)) === []) {
            return $slide;
        }
        if (!array_key_exists('buttons', $slide)) {
            $buttons = [];
            $hasText = static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== [];
            foreach ($pairs as [$label, $url, $style, $default]) {
                if (!$hasText($slide[$label] ?? null) && !$hasText($slide[$url] ?? null)) {
                    continue;
                }
                $button = [];
                foreach (['label' => $label, 'url' => $url] as $to => $from) {
                    if (array_key_exists($from, $slide)) {
                        $button[$to] = $slide[$from];
                    }
                }
                $button['style'] = is_string($slide[$style] ?? null) && $slide[$style] !== '' ? $slide[$style] : $default;
                $buttons[] = $button;
            }
            if ($buttons !== []) {
                $slide['buttons'] = $buttons;
            }
        }
        foreach ($old as $key) {
            unset($slide[$key]);
        }
        return $slide;
    }

    /**
     * The Slider block of before 0.1.110 as the Hero slider it became: its layouts are slide layouts (the large slide and the
     * banner are a text over a picture, the cards a picture beside the text), a slide's first two links are its buttons, and the
     * arrows, the automatic movement and what every block has (background, spacing, anchor, hidden) stay. The third link of a
     * slide, the heading and introduction above the slider, and its width have no place in a Hero slider and are left out.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function slideToHeroSlider(array $raw): array
    {
        $layout = ($raw['variant'] ?? 'full') === 'multi' ? 'split' : 'cover';
        $block = ['type' => 'hero-slider'];
        foreach (['tone', 'spacing', 'anchor', 'hidden', 'navigation', 'autoplay'] as $key) {
            if (array_key_exists($key, $raw)) {
                $block[$key] = $raw[$key];
            }
        }
        $items = [];
        foreach (is_array($raw['items'] ?? null) ? $raw['items'] : [] as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $item = ['layout' => $layout];
            foreach (['image', 'eyebrow', 'text', 'url', 'url_2'] as $key) {
                if (array_key_exists($key, $slide)) {
                    $item[$key] = $slide[$key];
                }
            }
            foreach (['title' => 'heading', 'link_label' => 'label', 'link_label_2' => 'label_2'] as $from => $to) {
                if (array_key_exists($from, $slide)) {
                    $item[$to] = $slide[$from];
                }
            }
            $items[] = $item;
        }
        $block['items'] = $items;
        return $block;
    }

    /**
     * Blocks as they should be written to front matter: known blocks keep only defined fields,
     * checked, with values that equal the field default left out; unknown block types (for
     * example from a removed custom block) are kept unchanged so no content is lost.
     *
     * @param array<int, mixed> $rawBlocks
     * @return array<int, array<string, mixed>>
     */
    public function sanitizeForStorage(array $rawBlocks): array
    {
        $blocks = [];
        foreach ($rawBlocks as $raw) {
            if (!is_array($raw) || !is_string($raw['type'] ?? null) || !preg_match('/^[a-z][a-z0-9-]*$/', $raw['type'])) {
                continue;
            }
            if ($raw['type'] === BlockContainer::TYPE) {
                $blocks[] = BlockContainer::sanitize($raw, fn(array $inside): array => $this->sanitizeForStorage($inside));
                continue;
            }
            $raw = $this->migrate($raw);
            $definition = $this->get($raw['type']);
            if ($definition === null) {
                $blocks[] = $raw;
                continue;
            }
            $block = ['type' => $raw['type']];
            $raw = FieldSchema::settleUnits($definition['fields'], $raw);
            $bound = false;
            foreach ([$definition['common'], $definition['fields']] as $fields) {
                foreach ($fields as $key => $field) {
                    if (!array_key_exists($key, $raw)) {
                        continue;
                    }
                    // A field bound to a field of the entry (a template built with blocks) keeps its binding, not a value.
                    if (EntryBindings::isBinding($raw[$key]) && isset($definition['fields'][$key]) && EntryBindings::bindable($field['type'])) {
                        // A list keeps the mapping of its columns beside the binding (EntryBindings::cleanList()).
                        $block[$key] = $field['type'] === 'repeater' ? EntryBindings::cleanList($raw[$key]) : ['bind' => $raw[$key]['bind']];
                        $bound = true;
                        continue;
                    }
                    $value = FieldSchema::clean($field, $raw[$key]);
                    if ($field['type'] === 'repeater') {
                        $value = self::rowsWithoutDefaults($field, $value);
                    }
                    if ($value === $field['default'] || $value === []) {
                        continue;
                    }
                    // A width is written as the number it is (width: 50), like a person would type it.
                    $block[$key] = $key === 'width' && is_numeric($value) ? (int)$value : $value;
                }
            }
            if ($bound && ($raw[EntryBindings::HIDE_KEY] ?? true) === false) {
                $block[EntryBindings::HIDE_KEY] = false;
            }
            $blocks[] = $block;
        }
        return $blocks;
    }

    /** @return array<string, mixed>|null */
    /**
     * The rows of a checked repeater as they are stored: values equal to the default left out (also in an inner list), and rows
     * left with nothing dropped.
     *
     * @param array<string, mixed> $field
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function rowsWithoutDefaults(array $field, array $rows): array
    {
        $kept = [];
        foreach ($rows as $row) {
            foreach ($field['fields'] as $subKey => $subField) {
                if ($subField['type'] === 'repeater' && is_array($row[$subKey] ?? null)) {
                    $row[$subKey] = self::rowsWithoutDefaults($subField, $row[$subKey]);
                }
                if (($row[$subKey] ?? null) === $subField['default']) {
                    unset($row[$subKey]);
                }
            }
            if ($row !== []) {
                $kept[] = $row;
            }
        }
        return $kept;
    }

    private function load(string $type, string $file, string $origin): ?array
    {
        try {
            $raw = Yaml::parseFile($file);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $variants = [];
        foreach (is_array($raw['variants'] ?? null) ? $raw['variants'] : [] as $value => $label) {
            if (preg_match('/^[a-z0-9-]+$/', (string)$value)) {
                $variants[(string)$value] = (string)$label;
            }
        }
        if ($variants === []) {
            $variants = ['default' => 'Default'];
        }
        // A layout that only makes sense while another field has a value (`variant_when: {split: {source: manual}}`), read like a field's `when`.
        $variantWhen = [];
        foreach (is_array($raw['variant_when'] ?? null) ? $raw['variant_when'] : [] as $variant => $when) {
            $checked = isset($variants[(string)$variant]) && is_array($when) ? (FieldSchema::normalize(['x' => ['type' => 'text', 'when' => $when]])['x']['when'] ?? null) : null;
            if ($checked !== null) {
                $variantWhen[(string)$variant] = $checked;
            }
        }
        // A layout that is a variant of another (a centred cover): its section also carries the other's class, so the styles of that one apply.
        $variantExtends = [];
        foreach (is_array($raw['variant_extends'] ?? null) ? $raw['variant_extends'] : [] as $variant => $base) {
            if (isset($variants[(string)$variant], $variants[(string)$base]) && (string)$variant !== (string)$base) {
                $variantExtends[(string)$variant] = (string)$base;
            }
        }
        // A layout that used to be a layout plus a choice that is gone: `when` says which blocks, `to` the layout they are now, `drop` what to remove.
        $variantMigrations = [];
        foreach (is_array($raw['variant_migrations'] ?? null) ? $raw['variant_migrations'] : [] as $migration) {
            if (is_array($migration) && is_array($migration['when'] ?? null) && isset($variants[(string)($migration['to'] ?? '')])) {
                $variantMigrations[] = [
                    'when' => array_map('strval', array_filter($migration['when'], 'is_scalar')),
                    'to' => (string)$migration['to'],
                    'drop' => array_values(array_map('strval', is_array($migration['drop'] ?? null) ? $migration['drop'] : [])),
                ];
            }
        }
        $tone = (string)($raw['tone'] ?? 'default');
        $spacing = (string)($raw['spacing'] ?? 'default');
        $fields = FieldSchema::withIcons(FieldSchema::normalize($this->expandFields(is_array($raw['fields'] ?? null) ? $raw['fields'] : [])), $this->theme->iconNames());
        $fields = $this->withDrawings($fields, $type, dirname($file));
        // Fields of the block that the editor shows beside its layout (in the Design tab) because they change how the layout looks.
        $designFields = array_values(array_filter(
            array_map('strval', is_array($raw['design_fields'] ?? null) ? $raw['design_fields'] : []),
            static fn(string $key): bool => isset($fields[$key]) && $fields[$key]['type'] !== 'repeater'
        ));

        return [
            'type' => $type,
            'origin' => $origin,
            'label' => (string)($raw['label'] ?? ucfirst(str_replace('-', ' ', $type))),
            'description' => (string)($raw['description'] ?? ''),
            'category' => trim((string)($raw['category'] ?? '')),
            // `scope: template` marks a block that only a template built with blocks offers (it draws a part of the entry the template is for).
            'scope' => in_array($raw['scope'] ?? '', ['template', 'archive'], true) ? (string)$raw['scope'] : '',
            'preview' => self::previewMarkup((string)@file_get_contents(dirname($file) . '/preview.svg')),
            'variants' => $variants,
            'variant_when' => $variantWhen,
            'variant_extends' => $variantExtends,
            'variant_migrations' => $variantMigrations,
            // The field a block's own old `width` setting moved to (block.yaml `width_moved_to`), so `width` is the shared Width everywhere.
            'width_moved_to' => preg_match('/^[a-z][a-z0-9_]*$/', (string)($raw['width_moved_to'] ?? '')) && isset($fields[(string)$raw['width_moved_to']]) ? (string)$raw['width_moved_to'] : '',
            'variant_previews' => self::variantPreviews(dirname($file) . '/variants', array_keys($variants), $designFields, $fields),
            'design_fields' => $designFields,
            'fields' => $fields,
            // Every block shares these presentation fields.
            'common' => FieldSchema::normalize([
                'variant' => ['type' => 'select', 'label' => 'Layout', 'options' => $variants, 'default' => (string)array_key_first($variants)],
                'tone' => ['type' => 'select', 'label' => 'Background', 'options' => self::TONES, 'default' => isset(self::TONES[$tone]) ? $tone : 'default'],
                'spacing' => ['type' => 'select', 'label' => 'Spacing', 'options' => self::SPACINGS, 'default' => isset(self::SPACINGS[$spacing]) ? $spacing : 'default'],
                'width' => ['type' => 'select', 'label' => 'Width', 'options' => self::WIDTHS, 'default' => '100', 'help' => 'Under 100% a block sits beside the next ones in a row.'],
                'anchor' => ['type' => 'text', 'label' => 'Anchor id', 'help' => 'Optional id for in-page links, e.g. services.'],
                'hidden' => ['type' => 'toggle', 'label' => 'Hide this block'],
            ]),
        ];
    }

    /**
     * The small drawing of each layout of a block (`variants/<layout>.svg` next to its block.yaml, in the format of preview.svg), for
     * the layout choice of the editor. A layout that depends on a design field has one more drawing for each value of that field,
     * named `<layout>-<value>.svg` (the hero's text over a picture, centred), used while the field has that value.
     *
     * @param string[] $variants the layouts
     * @param string[] $designFields
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, string> drawing name => markup
     */
    private static function variantPreviews(string $dir, array $variants, array $designFields, array $fields): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = $variants;
        foreach ($variants as $variant) {
            foreach ($designFields as $key) {
                foreach (array_keys($fields[$key]['options'] ?? []) as $value) {
                    $names[] = $variant . '-' . $value;
                }
            }
        }
        $previews = [];
        foreach ($names as $name) {
            if (!preg_match('/^[a-z0-9-]+$/', (string)$name)) {
                continue;
            }
            $markup = self::previewMarkup((string)@file_get_contents($dir . '/' . $name . '.svg'));
            if ($markup !== '') {
                $previews[(string)$name] = $markup;
            }
        }
        return $previews;
    }

    /**
     * Gives every choice that is drawn (`drawn` in block.yaml, also inside repeaters) the small pictures of its options: the files
     * variants/<option>.svg of the block (`drawn: true`) or of another block (`drawn: hero`), read like the layout previews.
     *
     * @param array<string, array<string, mixed>> $fields normalized definitions
     * @return array<string, array<string, mixed>>
     */
    private function withDrawings(array $fields, string $type, string $dir): array
    {
        foreach ($fields as $key => $field) {
            if ($field['type'] === 'repeater') {
                $fields[$key]['fields'] = $this->withDrawings($field['fields'], $type, $dir);
            } elseif (($field['drawn'] ?? '') !== '') {
                $dirs = [$dir . '/variants'];
                if ($field['drawn'] !== '*' && $field['drawn'] !== $type) {
                    $dirs = array_map(static fn(string $root): string => $root . '/' . $field['drawn'] . '/variants', array_values($this->theme->blockRoots()));
                }
                $drawings = [];
                foreach (array_keys($field['options'] ?? []) as $value) {
                    foreach ($dirs as $variants) {
                        $markup = preg_match('/^[a-z0-9-]+$/', (string)$value) ? self::previewMarkup((string)@file_get_contents($variants . '/' . $value . '.svg')) : '';
                        if ($markup !== '') {
                            $drawings[(string)$value] = $markup;
                            break;
                        }
                    }
                }
                $fields[$key]['drawings'] = $drawings;
            }
        }
        return $fields;
    }

    private const PREVIEW_TAGS = ['g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon'];
    private const PREVIEW_ATTRIBUTES = ['d', 'x', 'y', 'width', 'height', 'rx', 'ry', 'cx', 'cy', 'r', 'x1', 'y1', 'x2', 'y2', 'points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'fill-opacity', 'stroke-opacity', 'opacity', 'transform'];

    /**
     * The small wireframe a block shows in the editor's picker (`preview.svg` next to its block.yaml): drawn in one colour
     * (`currentColor`) on a 64 by 48 grid. Only plain shapes and a few presentation attributes are kept, so a file can never
     * bring script, links, images or styles into the admin. Returns the markup inside the <svg> element, or '' when the file
     * is missing, too large, or not usable.
     */
    public static function previewMarkup(string $svg): string
    {
        if ($svg === '' || strlen($svg) > 6000) {
            return '';
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $document->documentElement : null;
        if ($root === null || strtolower($root->localName) !== 'svg') {
            return '';
        }
        $clean = static function (\DOMNode $node) use (&$clean): string {
            $out = '';
            foreach ($node->childNodes as $child) {
                if (!$child instanceof \DOMElement || !in_array(strtolower($child->localName), self::PREVIEW_TAGS, true)) {
                    continue;
                }
                $attributes = '';
                foreach ($child->attributes as $attribute) {
                    $name = strtolower($attribute->name);
                    $value = (string)$attribute->value;
                    if (!in_array($name, self::PREVIEW_ATTRIBUTES, true) || preg_match('/[<>"\']|url\(|javascript:/i', $value)) {
                        continue;
                    }
                    if (in_array($name, ['fill', 'stroke'], true) && !in_array($value, ['none', 'currentColor'], true)) {
                        continue;
                    }
                    $attributes .= ' ' . $name . '="' . $value . '"';
                }
                $tag = strtolower($child->localName);
                $inner = $tag === 'g' ? $clean($child) : '';
                $out .= '<' . $tag . $attributes . ($inner === '' ? '/>' : '>' . $inner . '</' . $tag . '>');
            }
            return $out;
        };
        return $clean($root);
    }

    /** @param array<string, mixed> $fields */
    private function expandFields(array $fields): array
    {
        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $source = (string)($field['options_from'] ?? '');
            if ($source !== '' && isset($this->optionSources[$source])) {
                $options = ($this->optionSources[$source])();
                if (is_array($field['options'] ?? null)) {
                    $options = $field['options'] + $options;
                }
                $field['type'] = 'select';
                $field['options'] = $options;
            }
            if (($field['type'] ?? '') === 'repeater' && is_array($field['fields'] ?? null)) {
                $field['fields'] = $this->expandFields($field['fields']);
            }
            $fields[$key] = $field;
        }
        return $fields;
    }
}
