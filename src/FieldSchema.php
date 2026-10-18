<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Declarative field definitions for theme settings, content blocks and content types.
 *
 * A definition list is a map of key => definition. Values are always checked against the
 * definition: a missing value takes the field default and an invalid one falls back to it, so a
 * stored value can never push unexpected markup or CSS into a template.
 *
 * A repeater is a list of rows with fields of their own. A row of a top-level repeater may hold a
 * repeater too (two levels, MAX_DEPTH); a repeater deeper than that is left out of the definition,
 * and problems() names it so the person who wrote the definition can see why.
 */
final class FieldSchema
{
    public const TYPES = ['text', 'textarea', 'markdown', 'email', 'url', 'link', 'image', 'video', 'file', 'location', 'color', 'tone', 'number', 'decimal', 'date', 'select', 'icon', 'toggle', 'repeater'];

    /** How many repeaters can sit inside one another: a repeater, and a repeater in its rows. */
    public const MAX_DEPTH = 2;

    /**
     * @param array<string, mixed> $definitions raw map of key => definition
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $definitions): array
    {
        return self::normalizeLevel($definitions, 1);
    }

    /**
     * What is wrong with raw definitions in a way normalize() quietly corrects: a repeater nested deeper than MAX_DEPTH, which it
     * leaves out. Each problem is one sentence that names the field by its path (`fields.books.links.shops`).
     *
     * @param array<string, mixed> $definitions raw map of key => definition
     * @return list<string>
     */
    public static function problems(array $definitions, string $path = 'fields', int $depth = 1): array
    {
        $problems = [];
        foreach ($definitions as $key => $definition) {
            if (!is_array($definition) || strtolower((string)($definition['type'] ?? '')) !== 'repeater') {
                continue;
            }
            $where = $path . '.' . $key;
            if ($depth > self::MAX_DEPTH) {
                $problems[] = $where . ': a repeater can sit inside another repeater only one level deep (' . self::MAX_DEPTH . ' levels in all); this field is ignored.';
                continue;
            }
            $problems = array_merge($problems, self::problems(is_array($definition['fields'] ?? null) ? $definition['fields'] : [], $where, $depth + 1));
        }
        return $problems;
    }

    /**
     * @param array<string, mixed> $definitions
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeLevel(array $definitions, int $depth): array
    {
        $fields = [];
        foreach ($definitions as $key => $definition) {
            $key = (string)$key;
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $key) || !is_array($definition)) {
                continue;
            }
            $type = strtolower((string)($definition['type'] ?? 'text'));
            if (!in_array($type, self::TYPES, true)) {
                $type = 'text';
            }
            // Too deep (see problems()): left out rather than turned into a text that would hold a list.
            if ($type === 'repeater' && $depth > self::MAX_DEPTH) {
                continue;
            }
            $field = [
                'key' => $key,
                'type' => $type,
                'label' => (string)($definition['label'] ?? ucfirst(str_replace('_', ' ', $key))),
                'help' => (string)($definition['help'] ?? ''),
                'placeholder' => (string)($definition['placeholder'] ?? ''),
                'span' => in_array($definition['span'] ?? '', ['full', 'third'], true) ? $definition['span'] : '',
                'hidden' => ($definition['hidden'] ?? false) === true,
                'required' => ($definition['required'] ?? false) === true,
            ];
            // Public search can omit an otherwise visible field, or explicitly include text a custom template displays.
            if (is_bool($definition['searchable'] ?? null)) {
                $field['searchable'] = $definition['searchable'];
            }
            // A text that has a version for each language of the site (see sanitizeTranslations).
            if ($type === 'text' || $type === 'textarea') {
                if (($definition['translatable'] ?? false) === true) {
                    $field['translatable'] = true;
                }
            }
            // A field that matters only while another has a value: `when: {background: video}` (or a list of values).
            $when = [];
            foreach (is_array($definition['when'] ?? null) ? $definition['when'] : [] as $other => $wanted) {
                $wanted = array_values(array_filter(array_map('strval', is_array($wanted) ? $wanted : [$wanted]), static fn(string $w): bool => $w !== ''));
                if (preg_match('/^[a-z][a-z0-9_]*$/', (string)$other) && $wanted !== []) {
                    $when[(string)$other] = $wanted;
                }
            }
            if ($when !== []) {
                $field['when'] = $when;
            }
            // An icon is a choice from the theme's icon set; the set itself is filled in where the theme is known.
            if ($type === 'select' || $type === 'icon') {
                $field['options'] = self::normalizeOptions($definition['options'] ?? []);
                // A choice that fits only while another field has a value: `options_when: {below: {variant: '!overlay'}}`, read like `when`.
                $optionsWhen = [];
                foreach (is_array($definition['options_when'] ?? null) ? $definition['options_when'] : [] as $option => $conditions) {
                    $checked = is_array($conditions) && isset($field['options'][(string)$option])
                        ? (self::normalize(['x' => ['type' => 'text', 'when' => $conditions]])['x']['when'] ?? null) : null;
                    if ($checked !== null) {
                        $optionsWhen[(string)$option] = $checked;
                    }
                }
                if ($optionsWhen !== []) {
                    $field['options_when'] = $optionsWhen;
                }
            }
            // A choice the editor draws as small pictures: `drawn: true` takes variants/<value>.svg of the block, `drawn: hero` those of that block.
            if ($type === 'select' && isset($definition['drawn'])) {
                $field['drawn'] = $definition['drawn'] === true ? '*' : (is_string($definition['drawn']) && preg_match('/^[a-z][a-z0-9-]*$/', $definition['drawn']) ? $definition['drawn'] : '');
            }
            if ($type === 'file') {
                // Which files the Library offers for it: all of them, or only route files (GPX, KML, GeoJSON).
                $field['kind'] = ($definition['kind'] ?? '') === 'track' ? 'track' : 'all';
            }
            if ($type === 'decimal') {
                $field['min'] = isset($definition['min']) && is_numeric($definition['min']) ? (float)$definition['min'] : null;
                $field['max'] = isset($definition['max']) && is_numeric($definition['max']) ? (float)$definition['max'] : null;
            }
            if ($type === 'number' || $type === 'repeater') {
                $field['min'] = isset($definition['min']) && is_numeric($definition['min']) ? (int)$definition['min'] : null;
                $field['max'] = isset($definition['max']) && is_numeric($definition['max']) ? (int)$definition['max'] : null;
            }
            // A number that may be left empty ("the theme's own size"), and a colour that must be a hex code (a theme works
            // with it, for example to pick a readable text colour).
            if ($type === 'number') {
                $field['blank'] = ($definition['blank'] ?? false) === true;
                // A size whose range depends on a unit chosen in another field: `units: width_unit`, `ranges: {'%': [10, 100], px: [40, 2000]}`.
                $units = (string)($definition['units'] ?? '');
                $ranges = [];
                foreach (is_array($definition['ranges'] ?? null) ? $definition['ranges'] : [] as $unit => $range) {
                    if (is_array($range) && count($range) === 2 && is_numeric($range[0] ?? null) && is_numeric($range[1] ?? null)) {
                        $ranges[(string)$unit] = [(int)$range[0], (int)$range[1]];
                    }
                }
                if (preg_match('/^[a-z][a-z0-9_]*$/', $units) && $ranges !== []) {
                    $field['units'] = $units;
                    $field['ranges'] = $ranges;
                }
                // The editor offers these as one-click values; and `needs_size` shows a field only while that size is narrower than its column.
                $field['presets'] = array_values(array_filter(array_map('intval', is_array($definition['presets'] ?? null) ? $definition['presets'] : []), static fn(int $n): bool => $n > 0));
            }
            if (preg_match('/^[a-z][a-z0-9_]*$/', (string)($definition['needs_size'] ?? ''))) {
                $field['needs_size'] = (string)$definition['needs_size'];
            }
            if ($type === 'color') {
                $field['hex'] = ($definition['hex'] ?? false) === true;
            }
            if ($type === 'repeater') {
                // The fields of a row; a row of a top-level repeater may hold a repeater of its own (MAX_DEPTH).
                $subDefinitions = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
                $field['fields'] = self::normalizeLevel($subDefinitions, $depth + 1);
                $field['item_label'] = (string)($definition['item_label'] ?? 'Item');
                // A list of one short text (the hero's highlights) is edited as a row of boxes, not as folding cards; never one of lists.
                $only = count($field['fields']) === 1 ? reset($field['fields']) : null;
                $field['inline'] = ($definition['inline'] ?? false) === true && $only !== null && $only['type'] !== 'repeater';
            }
            $field['default'] = self::clean($field, $definition['default'] ?? null, true);
            $fields[$key] = $field;
        }
        return $fields;
    }

    /**
     * Gives every icon field (also inside repeaters) the icons the theme has as its choices, "None" first,
     * and settles its default again now that the choices are known.
     *
     * @param array<string, array<string, mixed>> $fields normalized definitions
     * @param string[] $names icon names
     * @return array<string, array<string, mixed>>
     */
    public static function withIcons(array $fields, array $names): array
    {
        foreach ($fields as $key => $field) {
            if ($field['type'] === 'icon') {
                $options = ['' => 'None'];
                foreach ($names as $name) {
                    $options[$name] = $name;
                }
                $field['options'] = $options;
                $field['default'] = self::sanitize($field, $field['default'], '');
                $fields[$key] = $field;
            } elseif ($field['type'] === 'repeater') {
                $field['fields'] = self::withIcons($field['fields'], $names);
                $fields[$key] = $field;
            }
        }
        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields normalized definitions
     * @return array<string, mixed>
     */
    public static function defaults(array $fields): array
    {
        $values = [];
        foreach ($fields as $key => $field) {
            $values[$key] = $field['default'];
        }
        return $values;
    }

    /**
     * Checks stored values: each defined field gets a valid value, other keys are left alone.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function resolve(array $fields, array $values): array
    {
        foreach ($fields as $key => $field) {
            $values[$key] = self::clean($field, $values[$key] ?? null);
        }
        return self::settleUnits($fields, $values);
    }

    /**
     * A size with a unit (`units` in its definition): kept inside the range of the unit chosen in the other field, and a size that
     * fills the whole room (100 %) is the same as none, so a default is never written. Run after the fields are checked one by one.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function settleUnits(array $fields, array $values): array
    {
        foreach ($fields as $key => $field) {
            if (!isset($field['units'], $fields[$field['units']]) || !array_key_exists($key, $values)) {
                continue;
            }
            $unit = self::clean($fields[$field['units']], $values[$field['units']] ?? null);
            $size = self::clean($field, $values[$key]);
            if ($size === '' || !isset($field['ranges'][$unit])) {
                $values[$key] = '';
                continue;
            }
            [$min, $max] = $field['ranges'][$unit];
            $size = max($min, min($max, (int)$size));
            // Only a percentage can fill the room; the same number of pixels is a size like any other.
            $values[$key] = $unit === '%' && $size >= 100 ? '' : $size;
        }
        return $values;
    }

    /**
     * Reads submitted form values. Fields the form did not render (hidden ones) keep their current
     * value; a visible toggle that was not submitted is off.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function fromInput(array $fields, array $input, array $current): array
    {
        $values = $current;
        foreach ($fields as $key => $field) {
            if ($field['hidden']) {
                $values[$key] = self::clean($field, $current[$key] ?? null);
                continue;
            }
            if ($field['type'] === 'toggle') {
                $values[$key] = self::isTruthy($input[$key] ?? false);
                continue;
            }
            $currentValue = self::clean($field, $current[$key] ?? null);
            // An invalid submission keeps the current valid value instead of resetting to the default.
            $values[$key] = array_key_exists($key, $input)
                ? self::sanitize($field, $input[$key], $currentValue)
                : $currentValue;
        }
        return self::settleUnits($fields, $values);
    }

    /** @param array<string, mixed> $field */
    public static function clean(array $field, mixed $value, bool $isDefault = false): mixed
    {
        if ($isDefault) {
            if ($value === null && in_array($field['type'], ['select', 'icon'], true)) {
                return (string)(array_key_first($field['options']) ?? '');
            }
            $value = self::sanitize($field, $value, self::emptyValue($field));
            // A declared default that is not one of the options means the first option.
            if (in_array($field['type'], ['select', 'icon'], true) && !array_key_exists($value, $field['options']) && !($field['type'] === 'icon' && $field['options'] === [] && $value !== '')) {
                return (string)(array_key_first($field['options']) ?? '');
            }
            return $value;
        }
        return self::sanitize($field, $value, $field['default']);
    }

    /** @param array<string, mixed> $field */
    private static function sanitize(array $field, mixed $value, mixed $fallback): mixed
    {
        if ($value === null) {
            return $fallback;
        }
        if (($field['translatable'] ?? false) === true && (is_array($value) || (is_array($fallback) && is_scalar($value)))) {
            return self::sanitizeTranslations($field, $value, $fallback);
        }

        switch ($field['type']) {
            case 'toggle':
                return is_bool($value) ? $value : self::isTruthy($value);

            case 'number':
                if (($field['blank'] ?? false) && is_string($value) && trim($value) === '') {
                    return '';
                }
                if (!is_numeric($value)) {
                    return $fallback;
                }
                $number = (int)$value;
                if ($field['min'] !== null) {
                    $number = max($field['min'], $number);
                }
                if ($field['max'] !== null) {
                    $number = min($field['max'], $number);
                }
                return $number;

            case 'decimal':
                // Empty means "not set" (e.g. no coordinates yet).
                if ($value === '' || !is_numeric($value)) {
                    return $value === '' ? '' : $fallback;
                }
                $decimal = round((float)$value, 7);
                if ($field['min'] !== null && $decimal < $field['min']) {
                    return $fallback;
                }
                if ($field['max'] !== null && $decimal > $field['max']) {
                    return $fallback;
                }
                return $decimal;

            case 'date':
                // Empty means "not set"; anything else must be a real calendar date (YYYY-MM-DD).
                $value = is_scalar($value) ? trim((string)$value) : '';
                if ($value === '') {
                    return '';
                }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                return $date !== false && $date->format('Y-m-d') === $value ? $value : $fallback;

            case 'icon':
                $value = is_scalar($value) ? (string)$value : '';
                // Until the theme's icon set is filled in (see withIcons) any icon name is accepted, so a declared default survives.
                if (count($field['options']) <= 1 && preg_match('/^[a-z0-9-]{0,40}$/', $value)) {
                    return $value;
                }
                return array_key_exists($value, $field['options']) ? $value : $fallback;

            case 'select':
                $value = is_scalar($value) ? (string)$value : '';
                return array_key_exists($value, $field['options']) ? $value : $fallback;

            case 'color':
                $value = is_scalar($value) ? trim((string)$value) : '';
                if (($field['hex'] ?? false) && $value !== '') {
                    $hex = self::normalizeHex($value);
                    return $hex ?? $fallback;
                }
                return $value === '' || self::isSafeColor($value) ? $value : $fallback;

            case 'tone':
                // A palette role (accent, text, …) or a #rrggbb; empty means the theme's own.
                $value = is_scalar($value) ? trim((string)$value) : '';
                return $value === '' || BrandingButtons::tone($value) !== null ? (isset(BrandingButtons::ROLES[$value]) ? $value : (self::normalizeHex($value) ?? $value)) : $fallback;

            case 'image':
            case 'video':
            case 'file':
            case 'url':
                $value = is_scalar($value) ? trim((string)$value) : '';
                return $value === '' || self::isSafeUrl($value) ? $value : $fallback;

            case 'link':
                $value = is_scalar($value) ? trim((string)$value) : '';
                return $value === '' || self::isSafeLink($value) ? $value : $fallback;

            case 'location':
                // A position, always stored the same way ("35.2012, 26.2744"); anything else is not kept.
                $value = is_scalar($value) ? trim((string)$value) : '';
                $position = Geo::parse($value);
                return $value === '' ? '' : ($position === null ? $fallback : Geo::format($position['lat'], $position['lng']));

            case 'repeater':
                // A form that lists no rows at all sends an empty marker, which means "none".
                if ($value === '') {
                    return [];
                }
                if (!is_array($value)) {
                    return $fallback;
                }
                $items = [];
                foreach (array_values($value) as $item) {
                    if (is_array($item)) {
                        $item = array_intersect_key($item, $field['fields']);
                        // A row the person added and left empty is not kept. An inner list counts by its checked rows (a row whose
                        // inner rows are all empty is empty too), and a text per language by its checked texts.
                        $seen = $item;
                        foreach ($seen as $subKey => $subValue) {
                            $sub = $field['fields'][$subKey];
                            if ($sub['type'] === 'repeater' || (($sub['translatable'] ?? false) === true && is_array($subValue))) {
                                $seen[$subKey] = self::sanitize($sub, $subValue, $sub['type'] === 'repeater' ? [] : '');
                            }
                        }
                        $filled = array_filter($seen, static fn(mixed $v): bool => !($v === '' || $v === null || $v === false || $v === []));
                        if ($filled !== []) {
                            $items[] = self::resolve($field['fields'], $item);
                        }
                    }
                }
                return $field['max'] !== null ? array_slice($items, 0, max(0, $field['max'])) : $items;

            case 'markdown':
            case 'textarea':
                if (!is_scalar($value)) {
                    return $fallback;
                }
                return self::limit(str_replace(["\r\n", "\r"], "\n", trim((string)$value)), 10000);

            default:
                if (!is_scalar($value)) {
                    return $fallback;
                }
                return self::limit(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value) ?? ''), 2000);
        }
    }

    /**
     * A translatable text is a plain string (the text for every language) or a map: `default` is the text of the site's own language
     * (what a plain string is), and a language code has the text for that language. Empty texts are dropped, a map with only the
     * default text is kept as a plain string, and a plain string sent over a map replaces only the default text.
     *
     * @param array<string, mixed> $field
     */
    private static function sanitizeTranslations(array $field, mixed $value, mixed $fallback): mixed
    {
        $plain = $field;
        unset($plain['translatable']);
        if (!is_array($value)) {
            $value = ['default' => $value] + array_filter((array)$fallback, static fn(mixed $v, mixed $k): bool => $k !== 'default', ARRAY_FILTER_USE_BOTH);
        }
        $texts = [];
        foreach ($value as $lang => $text) {
            $lang = (string)$lang;
            if ($lang !== 'default' && !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $lang)) {
                continue;
            }
            $clean = self::sanitize($plain, is_scalar($text) ? $text : '', '');
            if (is_string($clean) && $clean !== '') {
                $texts[$lang] = $clean;
            }
        }
        if ($texts === []) {
            return '';
        }
        return array_keys($texts) === ['default'] ? $texts['default'] : $texts;
    }

    public static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)(is_scalar($value) ? $value : ''))), ['1', 'true', 'yes', 'on'], true);
    }

    /** #rgb or #rrggbb (with or without the #) as lowercase #rrggbb, or null when it is not a hex colour. */
    public static function normalizeHex(string $value): ?string
    {
        $value = ltrim(trim($value), '#');
        if (preg_match('/^[0-9a-f]{3}$/i', $value)) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }
        return preg_match('/^[0-9a-f]{6}$/i', $value) ? '#' . strtolower($value) : null;
    }

    /** Colours end up in inline style attributes, so only plain colour syntax is accepted. */
    public static function isSafeColor(string $value): bool
    {
        return (bool)preg_match(
            '/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/deg]+\)|var\(--[a-z0-9-]+\)|[a-z]{3,30})$/i',
            $value
        );
    }

    /** URLs end up inside CSS url('...') and attributes, so quotes, brackets and spaces are refused. */
    public static function isSafeUrl(string $value): bool
    {
        if (preg_match('/[\s"\'()<>\\\\]/', $value)) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
            return (bool)preg_match('#^https?://#i', $value);
        }
        return true;
    }

    /**
     * Links may also point inside the page (#id), to email, or to a phone number; script and data
     * schemes, quotes, brackets, and whitespace are refused.
     */
    public static function isSafeLink(string $value): bool
    {
        if (preg_match('/[\s"\'<>\\\\]/', $value)) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
            return (bool)preg_match('#^(https?://|mailto:|tel:)#i', $value);
        }
        return true;
    }

    /** @return array<string, string> */
    private static function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }
        $normalized = [];
        // List form [a, b] means value a with label "A"; a map (even with numeric keys) is value => label.
        $isList = array_is_list($options);
        foreach ($options as $value => $label) {
            if ($isList) {
                $value = (string)$label;
                if ($value === '') {
                    continue;
                }
                $label = ucfirst(str_replace(['_', '-'], ' ', $value));
            }
            $normalized[(string)$value] = (string)$label;
        }
        return $normalized;
    }

    /** @param array<string, mixed> $field */
    private static function emptyValue(array $field): mixed
    {
        return match ($field['type']) {
            'toggle' => false,
            'number' => ($field['blank'] ?? false) ? '' : ($field['min'] ?? 0),
            'repeater' => [],
            default => '',
        };
    }

    private static function limit(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}
