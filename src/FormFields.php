<?php

declare(strict_types=1);

namespace GnozioCMS;

/** The form editor's field definitions: which types exist, how submitted field rows are cleaned before they are stored, and how stored ones are read back for the form on the site and for the editor. */
final class FormFields
{
    /** Parts of a form that show something and take no answer: they have no value, are not checked, and are not in the emails. */
    public const DISPLAY = ['heading', 'paragraph'];
    /** How wide a field is in the row of the form; a field with none takes the whole row. */
    public const WIDTHS = ['half', 'third', 'two-thirds'];

    /** The texts of a file field besides its label and help: the button, and what is said when something is wrong. All can be translated. */
    public const FILE_TEXTS = ['button', 'error_size', 'error_type', 'error_max', 'error_min'];

    /** The settings of a file field as they are stored: single or several, how many, how big, which kinds, and the pattern for the file names. @return array<string, mixed> */
    public static function storeUpload(mixed $raw): array
    {
        $settings = FormUploads::settings(['upload' => is_array($raw) ? $raw : []]);
        $clean = [
            'multiple' => $settings['multiple'],
            'max_size_mb' => $settings['max_size_mb'],
            'extensions' => $settings['extensions'],
        ];
        if ($settings['multiple']) {
            $clean['min'] = $settings['min'];
            $clean['max'] = $settings['max'];
        }
        if ($settings['total_mb'] > 0) {
            $clean['total_mb'] = $settings['total_mb'];
        }
        if ($settings['rename'] !== '') {
            $clean['rename'] = mb_substr($settings['rename'], 0, 200);
        }
        return $clean;
    }

    public static function isDisplay(string $type): bool
    {
        return in_array($type, self::DISPLAY, true);
    }

    /** @return string[] */
    public static function types(): array
    {
        return [
            'text',
            'email',
            'textarea',
            'number',
            'tel',
            'url',
            'date',
            'time',
            'datetime-local',
            'select',
            'radio',
            'checkbox',
            'checkboxes',
            'hidden',
            'color',
            'range',
            'file',
            'heading',
            'paragraph',
        ];
    }

    public static function sanitizeName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace(' ', '-', $value);
        return Slug::plain($value);
    }

    /** @return string[] */
    public static function parseOptions(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $parts = preg_split('/\R|,/', $value) ?: [];
        $options = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $options[] = $part;
        }
        return $options;
    }

    /**
     * What the editor sends, cleaned for storing. A name that is used twice gets a number so no two fields share an
     * answer, a part that only shows something (a heading, a paragraph) is named after its kind when it has no name, and
     * options come as a list of choices (value and label) or as text, one per line.
     *
     * @param string[] $languages the languages a field may be translated into (any when empty)
     * @return array<int, array<string, mixed>>
     */
    public static function parseInput(mixed $input, array $languages = []): array
    {
        if (!is_array($input)) {
            return [];
        }
        $fields = [];
        $used = [];
        $counts = [];
        $allowedTypes = self::types();
        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = self::sanitizeName((string)($row['name'] ?? ''));
            if ($name === '' && self::isDisplay($type)) {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                $name = $type . '-' . $counts[$type];
            }
            if ($name === '' || str_starts_with($name, 'form_')) {
                continue;
            }
            for ($n = 2; isset($used[$name]); $n++) {
                $name = preg_replace('/-\d+$/', '', $name) . '-' . $n;
            }
            $used[$name] = true;
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                $label = Slug::title($name);
            }
            $field = [
                'type' => $type,
                'name' => $name,
                'label' => $label,
            ];
            $width = strtolower(trim((string)($row['width'] ?? '')));
            if (in_array($width, self::WIDTHS, true) && $type !== 'hidden') {
                $field['width'] = $width;
            }
            if (self::isDisplay($type)) {
                $translations = FormTranslations::cleanField($row['translations'] ?? [], [], $languages);
                if ($translations !== []) {
                    $field['translations'] = $translations;
                }
                $fields[] = $field;
                continue;
            }
            if (Format::isTruthy($row['required'] ?? false)) {
                $field['required'] = true;
            }
            if ($type === 'file') {
                $help = trim((string)($row['help'] ?? ''));
                if ($help !== '') {
                    $field['help'] = $help;
                }
                $field['upload'] = self::storeUpload($row['upload'] ?? []);
                foreach (self::FILE_TEXTS as $key) {
                    $text = trim(str_replace(["\r", "\n"], ' ', (string)($row[$key] ?? '')));
                    if ($text !== '') {
                        $field[$key] = $text;
                    }
                }
                $translations = FormTranslations::cleanField($row['translations'] ?? [], [], $languages);
                if ($translations !== []) {
                    $field['translations'] = $translations;
                }
                $fields[] = $field;
                continue;
            }
            $placeholder = trim((string)($row['placeholder'] ?? ''));
            if ($placeholder !== '') {
                $field['placeholder'] = $placeholder;
            }
            $options = self::storeOptions($row['options'] ?? '');
            if (!empty($options) && in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                $field['options'] = $options;
            }
            $default = (string)($row['default'] ?? '');
            if ($default !== '') {
                $field['default'] = $default;
            }
            $help = trim((string)($row['help'] ?? ''));
            if ($help !== '') {
                $field['help'] = $help;
            }
            $rows = (int)($row['rows'] ?? 4);
            if ($type === 'textarea' && $rows > 0) {
                $field['rows'] = $rows;
            }
            $min = trim((string)($row['min'] ?? ''));
            if ($min !== '') {
                $field['min'] = $min;
            }
            $max = trim((string)($row['max'] ?? ''));
            if ($max !== '') {
                $field['max'] = $max;
            }
            $step = trim((string)($row['step'] ?? ''));
            if ($step !== '') {
                $field['step'] = $step;
            }
            $translations = FormTranslations::cleanField($row['translations'] ?? [], array_column(self::normalizeOptions($field['options'] ?? []), 'value'), $languages);
            if ($translations !== []) {
                $field['translations'] = $translations;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * The choices of a field as they are stored: "value|label", or just the text when value and label are the same. A list
     * (what the form builder sends) keeps a comma inside a label; text is split on lines and commas, as it always was.
     *
     * @return string[]
     */
    public static function storeOptions(mixed $value): array
    {
        if (!is_array($value)) {
            return self::parseOptions((string)$value);
        }
        $stored = [];
        foreach ($value as $option) {
            if (is_array($option)) {
                $label = trim((string)($option['label'] ?? ''));
                $optionValue = trim((string)($option['value'] ?? ''));
            } else {
                $label = trim((string)$option);
                $optionValue = '';
            }
            if ($optionValue === '') {
                $optionValue = $label;
            }
            $optionValue = str_replace('|', '/', $optionValue);
            if ($label === '') {
                $label = $optionValue;
            }
            if ($optionValue === '') {
                continue;
            }
            $stored[] = $label === $optionValue ? $optionValue : $optionValue . '|' . str_replace(["\r", "\n"], ' ', $label);
        }
        return $stored;
    }

    /** @return array<int, array<string, mixed>> */
    public static function normalize(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $normalized = [];
        $allowedTypes = self::types();
        $counts = [];
        foreach ($fields as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $name = self::sanitizeName((string)($row['name'] ?? ''));
            if ($name === '' && self::isDisplay($type)) {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                $name = $type . '-' . $counts[$type];
            }
            if ($name === '') {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                $label = Slug::title($name);
            }
            $width = strtolower(trim((string)($row['width'] ?? '')));
            $field = [
                'type' => $type,
                'name' => $name,
                'label' => $label,
                'width' => in_array($width, self::WIDTHS, true) ? $width : '',
                'required' => Format::isTruthy($row['required'] ?? false),
                'placeholder' => (string)($row['placeholder'] ?? ''),
                'help' => (string)($row['help'] ?? ''),
                'default' => $row['default'] ?? '',
                'options' => self::normalizeOptions($row['options'] ?? []),
                'rows' => (int)($row['rows'] ?? 4),
                'min' => (string)($row['min'] ?? ''),
                'max' => (string)($row['max'] ?? ''),
                'step' => (string)($row['step'] ?? ''),
                'translations' => FormTranslations::cleanField($row['translations'] ?? []),
            ];
            if ($type === 'file') {
                $field['upload'] = FormUploads::settings($row);
                foreach (self::FILE_TEXTS as $key) {
                    $field[$key] = trim((string)($row[$key] ?? ''));
                }
            }
            $normalized[] = $field;
        }
        return $normalized;
    }

    /**
     * The fields as the form builder starts with them: one row each, with the choices as a list of value and label, and
     * whether the field was stored already (its name is then what stored submissions are filed under).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forBuilder(mixed $fields, bool $stored = true): array
    {
        $rows = [];
        foreach (self::normalize($fields) as $field) {
            $field['default'] = is_array($field['default']) ? implode(', ', array_map('strval', $field['default'])) : (string)$field['default'];
            $field['rows'] = (int)$field['rows'] > 0 ? (int)$field['rows'] : 4;
            $field['saved'] = $stored;
            $rows[] = $field;
        }
        return $rows;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function normalizeOptions(mixed $value): array
    {
        $raw = [];
        if (is_array($value)) {
            $raw = $value;
        } elseif (is_string($value)) {
            $raw = preg_split('/\R|,/', $value) ?: [];
        }
        $options = [];
        foreach ($raw as $option) {
            $option = trim((string)$option);
            if ($option === '') {
                continue;
            }
            $value = $option;
            $label = $option;
            if (str_contains($option, '|')) {
                [$value, $label] = array_map('trim', explode('|', $option, 2));
            } elseif (str_contains($option, ':')) {
                [$value, $label] = array_map('trim', explode(':', $option, 2));
            }
            if ($label === '') {
                $label = $value;
            }
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }
        return $options;
    }
}
