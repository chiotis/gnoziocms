<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The container row of the block builder: a section with columns (1 to 6), and blocks inside each column. It is an entry of the
 * `blocks:` list of a page like a block, with its own shape:
 *
 *   - type: container
 *     settings: {gap: l, justify: center}          only what differs from the default
 *     columns:
 *       - settings: {width: 1/3}                   width: fill, auto, a fraction (1/3), a percentage (37%) or a size (20rem)
 *         blocks: [ ...normal blocks... ]
 *
 * One level only: a container inside a column is dropped. The theme draws it (components/block-container.twig) with classes and
 * CSS custom properties from assets/css/block-container.css, which is loaded only on a page that has one. This class is the
 * shape: the fields, their checking for storage and for drawing, and the data the editor starts from.
 */
final class BlockContainer
{
    public const TYPE = 'container';
    public const MAX_COLUMNS = 6;
    /** Spacing steps of the theme (--space-*), the only values a gap or a padding can take. */
    public const SPACES = ['none' => 'None', 'xs' => 'Extra small', 's' => 'Small', 'm' => 'Medium', 'l' => 'Large', 'xl' => 'Extra large'];
    public const STACKS = ['row' => 'Stay in a row', 'two' => 'Wrap to 2 columns', 'one' => 'Stack to 1 column'];
    public const ALIGNS = ['stretch' => 'Stretch', 'start' => 'Top', 'center' => 'Middle', 'end' => 'Bottom', 'baseline' => 'Baseline'];
    public const JUSTIFY = ['start' => 'Start', 'center' => 'Center', 'end' => 'End', 'between' => 'Space between', 'around' => 'Space around', 'evenly' => 'Space evenly'];
    public const MIN_HEIGHTS = ['none' => 'Auto', 's' => 'Small', 'm' => 'Medium', 'l' => 'Large', 'screen' => 'Screen'];
    /** Ready-made column layouts: id => [label, column widths]. */
    public const PRESETS = [
        '50-50' => ['50 / 50', ['1/2', '1/2']],
        '33-67' => ['33 / 67', ['1/3', '2/3']],
        '67-33' => ['67 / 33', ['2/3', '1/3']],
        '3x33' => ['3 × 33', ['1/3', '1/3', '1/3']],
        '25-50-25' => ['25 / 50 / 25', ['1/4', '1/2', '1/4']],
        '4x25' => ['4 × 25', ['1/4', '1/4', '1/4', '1/4']],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $settings = null;
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $columns = null;

    /** The fields of the container itself. @return array<string, array<string, mixed>> */
    public static function settingFields(): array
    {
        return self::$settings ??= FieldSchema::normalize([
            'direction' => ['type' => 'select', 'label' => 'Direction', 'options' => ['row' => 'Row', 'row-reverse' => 'Row, reversed'], 'default' => 'row'],
            'justify' => ['type' => 'select', 'label' => 'Justify content', 'options' => self::JUSTIFY, 'default' => 'start', 'help' => 'How the columns share a line that is not full (justify-content).'],
            'align' => ['type' => 'select', 'label' => 'Align items', 'options' => self::ALIGNS, 'default' => 'stretch', 'help' => 'Where columns of different heights sit on their line (align-items).'],
            'align_content' => ['type' => 'select', 'label' => 'Align content', 'options' => ['stretch' => 'Stretch'] + array_diff_key(self::JUSTIFY, ['stretch' => 1]), 'default' => 'stretch', 'help' => 'How wrapped lines share the height of the container (align-content).'],
            'wrap' => ['type' => 'select', 'label' => 'Wrap', 'options' => ['wrap' => 'Wrap', 'nowrap' => 'No wrap', 'wrap-reverse' => 'Wrap, reversed'], 'default' => 'wrap'],
            'gap' => ['type' => 'select', 'label' => 'Column gap', 'options' => self::SPACES, 'default' => 'm'],
            'row_gap' => ['type' => 'select', 'label' => 'Row gap', 'options' => self::SPACES, 'default' => 'm'],
            'stack_desktop' => ['type' => 'select', 'label' => 'Desktop', 'options' => self::STACKS, 'default' => 'row'],
            'stack_tablet' => ['type' => 'select', 'label' => 'Tablet', 'options' => ['auto' => 'Automatic'] + self::STACKS, 'default' => 'auto', 'help' => 'Automatic: two columns stay in a row, more wrap to 2.'],
            'stack_phone' => ['type' => 'select', 'label' => 'Phone', 'options' => self::STACKS, 'default' => 'one'],
            'reverse_phone' => ['type' => 'toggle', 'label' => 'Reverse the order when stacked on a phone'],
            'tone' => ['type' => 'select', 'label' => 'Background', 'options' => BlockRegistry::TONES, 'default' => 'default'],
            'image' => ['type' => 'image', 'label' => 'Background picture'],
            'overlay' => ['type' => 'select', 'label' => 'Overlay', 'options' => ['none' => 'None', 'light' => 'Light', 'dark' => 'Dark'], 'default' => 'none', 'help' => 'Over the picture, so the text stays readable. Dark also makes the text light.'],
            'inner' => ['type' => 'select', 'label' => 'Inner width', 'options' => ['container' => 'Container', 'narrow' => 'Narrow', 'full' => 'Full bleed'], 'default' => 'container'],
            'spacing' => ['type' => 'select', 'label' => 'Spacing', 'options' => BlockRegistry::SPACINGS, 'default' => 'default'],
            'min_height' => ['type' => 'select', 'label' => 'Minimum height', 'options' => self::MIN_HEIGHTS, 'default' => 'none'],
            'anchor' => ['type' => 'text', 'label' => 'Anchor id', 'help' => 'Optional id for in-page links, e.g. services.'],
            'class' => ['type' => 'text', 'label' => 'CSS class', 'help' => 'Optional class names (letters, digits, - and _) for the site\'s own stylesheet.'],
            'hidden' => ['type' => 'toggle', 'label' => 'Hide this container'],
        ]);
    }

    /** The fields of one column. @return array<string, array<string, mixed>> */
    public static function columnFields(): array
    {
        return self::$columns ??= FieldSchema::normalize([
            'width' => ['type' => 'text', 'label' => 'Width', 'default' => 'fill', 'help' => 'fill, auto, a fraction (1/3), a percentage (37%) or a size (20rem, 320px).'],
            'min_width' => ['type' => 'text', 'label' => 'Minimum width', 'help' => 'A size (12rem) or a percentage.'],
            'max_width' => ['type' => 'text', 'label' => 'Maximum width', 'help' => 'A size (40rem) or a percentage.'],
            'grow' => ['type' => 'number', 'label' => 'Grow', 'min' => 0, 'max' => 12, 'blank' => true, 'default' => ''],
            'shrink' => ['type' => 'number', 'label' => 'Shrink', 'min' => 0, 'max' => 12, 'blank' => true, 'default' => ''],
            'basis' => ['type' => 'text', 'label' => 'Basis', 'help' => 'auto, a size or a percentage. Replaces the width when set, together with grow and shrink.'],
            'align_self' => ['type' => 'select', 'label' => 'Align self', 'options' => ['auto' => 'Auto'] + self::ALIGNS, 'default' => 'auto'],
            'valign' => ['type' => 'select', 'label' => 'Content', 'options' => ['top' => 'Top', 'center' => 'Middle', 'bottom' => 'Bottom'], 'default' => 'top', 'help' => 'Where the blocks sit in a column that is taller than they are.'],
            'order' => ['type' => 'number', 'label' => 'Order', 'min' => -6, 'max' => 6, 'default' => 0, 'help' => 'Lower numbers come first; 0 keeps the column where it is.'],
            'padding' => ['type' => 'select', 'label' => 'Padding', 'options' => self::SPACES, 'default' => 'none'],
            'tone' => ['type' => 'select', 'label' => 'Background', 'options' => BlockRegistry::TONES, 'default' => 'default'],
            'radius' => ['type' => 'select', 'label' => 'Corners', 'options' => ['none' => 'Square', 's' => 'Small', 'm' => 'Medium', 'l' => 'Large'], 'default' => 'none'],
        ]);
    }

    /**
     * A size the theme can use: whole or one-decimal number with px, rem, em, ch or %; empty when it is not one.
     * `$keywords` are the words also allowed (auto, fill).
     *
     * @param string[] $keywords
     */
    public static function length(mixed $value, array $keywords = []): string
    {
        $value = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        if ($value === '') {
            return '';
        }
        if (in_array($value, $keywords, true)) {
            return $value;
        }
        if (preg_match('/^(\d{1,4}(?:\.\d{1,2})?)(px|rem|em|ch|%)$/', $value, $m) && (float)$m[1] > 0 && ($m[2] !== '%' || (float)$m[1] <= 100)) {
            return $m[1] . $m[2];
        }
        return '';
    }

    /** A column width as the value stored: fill (the default), auto, a fraction, a percentage or a size. */
    public static function width(mixed $value): string
    {
        $text = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        if (preg_match('/^([1-9])\s*\/\s*([2-9])$/', $text, $m) && (int)$m[1] < (int)$m[2]) {
            return $m[1] . '/' . $m[2];
        }
        $length = self::length($text, ['fill', 'auto']);
        return $length === '' ? 'fill' : $length;
    }

    /** The share of the row a column asks for (0 to 1), or null for auto and fixed sizes. */
    public static function share(string $width, int $columns): ?float
    {
        if ($width === 'fill') {
            return 1 / max(1, $columns);
        }
        if (preg_match('#^(\d)/(\d)$#', $width, $m)) {
            return (int)$m[1] / (int)$m[2];
        }
        if (str_ends_with($width, '%')) {
            return (float)$width / 100;
        }
        return null;
    }

    /** The class of a block in a column that stands for its room (block--w-25/33/50, none for a wide column): the Width of the block itself is not used there. */
    public static function blockWidth(string $width, int $columns): int
    {
        $share = self::share($width, $columns);
        if ($share === null) {
            if ($width === 'auto') {
                return 50;
            }
            $px = (float)$width * (str_ends_with($width, 'px') ? 1 : 16);
            return $px <= 360 ? 25 : ($px <= 520 ? 33 : ($px <= 720 ? 50 : 100));
        }
        return $share <= 0.3 ? 25 : ($share <= 0.4 ? 33 : ($share <= 0.6 ? 50 : 100));
    }

    /**
     * A container as it should be written to front matter: only settings that differ from the default, one to six columns, and the
     * blocks of each checked by `$blocks` (the registry's own check). A container in a column is dropped.
     *
     * @param array<string, mixed> $raw
     * @param \Closure(array<int, mixed>): array<int, mixed> $blocks
     * @return array<string, mixed>
     */
    public static function sanitize(array $raw, \Closure $blocks): array
    {
        $settings = self::cleanSettings(self::settingFields(), is_array($raw['settings'] ?? null) ? $raw['settings'] : []);
        $columns = [];
        $given = is_array($raw['columns'] ?? null) ? array_values($raw['columns']) : [];
        foreach (array_slice($given ?: [[]], 0, self::MAX_COLUMNS) as $column) {
            $column = is_array($column) ? $column : [];
            $entry = [];
            $own = self::cleanSettings(self::columnFields(), is_array($column['settings'] ?? null) ? $column['settings'] : []);
            if ($own !== []) {
                $entry['settings'] = $own;
            }
            $inside = array_values(array_filter(
                is_array($column['blocks'] ?? null) ? $column['blocks'] : [],
                static fn(mixed $block): bool => is_array($block) && ($block['type'] ?? null) !== self::TYPE
            ));
            $inside = $blocks($inside);
            if ($inside !== []) {
                $entry['blocks'] = $inside;
            }
            $columns[] = $entry;
        }
        $out = ['type' => self::TYPE];
        if ($settings !== []) {
            $out['settings'] = $settings;
        }
        $out['columns'] = $columns;
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed> only what differs from the defaults
     */
    private static function cleanSettings(array $fields, array $values): array
    {
        $clean = self::values($fields, $values);
        $out = [];
        foreach ($fields as $key => $field) {
            if ($clean[$key] !== $field['default'] && $clean[$key] !== '') {
                $out[$key] = $clean[$key];
            }
        }
        return $out;
    }

    /**
     * Every field of the container or a column with a valid value (the default where nothing valid is stored).
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function values(array $fields, array $values): array
    {
        $values = FieldSchema::resolve($fields, array_intersect_key($values, $fields));
        foreach (['width'] as $key) {
            if (isset($fields[$key])) {
                $values[$key] = self::width($values[$key]);
            }
        }
        foreach (['min_width', 'max_width'] as $key) {
            if (isset($fields[$key])) {
                $values[$key] = self::length($values[$key]);
            }
        }
        if (isset($fields['basis'])) {
            $values['basis'] = self::length($values['basis'], ['auto']);
        }
        if (isset($fields['anchor'])) {
            $values['anchor'] = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)$values['anchor'])), '-');
        }
        if (isset($fields['class'])) {
            $values['class'] = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^A-Za-z0-9_\- ]+/', '', (string)$values['class'])));
        }
        return $values;
    }

    /**
     * A container as the theme draws it: its settings with defaults, and the columns (one to six) with theirs and their raw blocks.
     *
     * @param array<string, mixed> $raw
     * @return array{settings: array<string, mixed>, columns: array<int, array{settings: array<string, mixed>, blocks: array<int, mixed>}>}
     */
    public static function resolve(array $raw): array
    {
        $settings = self::values(self::settingFields(), is_array($raw['settings'] ?? null) ? $raw['settings'] : []);
        $given = is_array($raw['columns'] ?? null) ? array_values($raw['columns']) : [];
        $columns = [];
        foreach (array_slice($given ?: [[]], 0, self::MAX_COLUMNS) as $column) {
            $column = is_array($column) ? $column : [];
            $columns[] = [
                'settings' => self::values(self::columnFields(), is_array($column['settings'] ?? null) ? $column['settings'] : []),
                'blocks' => array_values(array_filter(is_array($column['blocks'] ?? null) ? $column['blocks'] : [], static fn(mixed $block): bool => is_array($block) && ($block['type'] ?? null) !== self::TYPE)),
            ];
        }
        return ['settings' => $settings, 'columns' => $columns];
    }

    /**
     * The classes and custom properties that draw one column (see block-container.css). Nothing is inline but the variables.
     *
     * @param array<string, mixed> $column resolved column settings
     * @return array{class: string, style: string}
     */
    public static function columnMarkup(array $column): array
    {
        $classes = ['bkc-col'];
        $vars = [];
        $width = (string)$column['width'];
        if (preg_match('#^(\d)/(\d)$#', $width, $m)) {
            $classes[] = 'bkc-col--part';
            $vars[] = '--bkc-f:' . self::number((int)$m[1] / (int)$m[2]);
        } elseif (str_ends_with($width, '%')) {
            $classes[] = 'bkc-col--part';
            $vars[] = '--bkc-f:' . self::number((float)$width / 100);
        } elseif ($width === 'auto') {
            $classes[] = 'bkc-col--auto';
        } elseif ($width === 'fill') {
            $classes[] = 'bkc-col--fill';
        } else {
            $classes[] = 'bkc-col--fixed';
            $vars[] = '--bkc-w:' . $width;
        }
        if ($column['min_width'] !== '') {
            $vars[] = '--bkc-min:' . $column['min_width'];
        }
        if ($column['max_width'] !== '') {
            $vars[] = '--bkc-max:' . $column['max_width'];
        }
        if ($column['grow'] !== '' || $column['shrink'] !== '' || $column['basis'] !== '') {
            $classes[] = 'bkc-col--flex';
            foreach (['grow', 'shrink', 'basis'] as $key) {
                if ($column[$key] !== '') {
                    $vars[] = '--bkc-' . $key . ':' . $column[$key];
                }
            }
        }
        if ($column['align_self'] !== 'auto') {
            $classes[] = 'bkc-col--self-' . $column['align_self'];
        }
        if ($column['valign'] !== 'top') {
            $classes[] = 'bkc-col--v-' . $column['valign'];
        }
        if ((int)$column['order'] !== 0) {
            $classes[] = 'bkc-col--ordered';
            $vars[] = '--bkc-order:' . (int)$column['order'];
        }
        if ($column['padding'] !== 'none') {
            $classes[] = 'bkc-col--pad-' . $column['padding'];
        }
        if ($column['tone'] !== 'default') {
            $classes[] = 'bkc-col--toned tone-' . $column['tone'];
        }
        if ($column['radius'] !== 'none') {
            $classes[] = 'bkc-col--r-' . $column['radius'];
        }
        return ['class' => implode(' ', $classes), 'style' => implode(';', $vars)];
    }

    /** The classes of the row of columns (direction, alignment, gaps, how it stacks on each screen). @param array<string, mixed> $settings resolved settings */
    public static function rowClasses(array $settings, int $columns): string
    {
        $tablet = (string)$settings['stack_tablet'];
        if ($tablet === 'auto') {
            $tablet = $columns <= 2 ? 'row' : 'two';
        }
        return implode(' ', [
            'bkc-row',
            'bkc-dir-' . $settings['direction'],
            'bkc-wrap-' . $settings['wrap'],
            'bkc-j-' . $settings['justify'],
            'bkc-a-' . $settings['align'],
            'bkc-ac-' . $settings['align_content'],
            'bkc-gap-' . $settings['gap'],
            'bkc-rgap-' . $settings['row_gap'],
            'bkc-d-' . $settings['stack_desktop'],
            'bkc-t-' . $tablet,
            'bkc-p-' . $settings['stack_phone'],
        ] + ($settings['reverse_phone'] === true ? [99 => 'bkc-rev'] : []));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }

    /**
     * Every block inside the columns of a container, in order (for what reads the blocks of a page: stylesheets, media, checks).
     *
     * @param array<string, mixed> $container
     * @return array<int, array<string, mixed>>
     */
    public static function nested(array $container): array
    {
        $blocks = [];
        foreach (is_array($container['columns'] ?? null) ? $container['columns'] : [] as $column) {
            foreach (is_array($column) && is_array($column['blocks'] ?? null) ? $column['blocks'] : [] as $block) {
                if (is_array($block)) {
                    $blocks[] = $block;
                }
            }
        }
        return $blocks;
    }

    /**
     * The blocks of a list with the ones inside containers after their container, flattened.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $blocks): array
    {
        $flat = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $flat[] = $block;
            if (($block['type'] ?? null) === self::TYPE) {
                array_push($flat, ...self::nested($block));
            }
        }
        return $flat;
    }

    /**
     * What the block builder needs to draw a container: its fields (as the builder reads fields), the presets and the limits.
     *
     * @return array<string, mixed>
     */
    public static function editorData(): array
    {
        $presets = [];
        foreach (self::PRESETS as $id => [$label, $widths]) {
            $presets[] = ['id' => $id, 'label' => $label, 'widths' => $widths];
        }
        return [
            'type' => self::TYPE,
            'label' => 'Container',
            'description' => 'A row with columns. Blocks go inside the columns.',
            'max_columns' => self::MAX_COLUMNS,
            'settings' => BlockRegistry::fieldsForEditor(self::settingFields()),
            'columns' => BlockRegistry::fieldsForEditor(self::columnFields()),
            'presets' => $presets,
        ];
    }
}
