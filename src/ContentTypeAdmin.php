<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Content types screen does: making a type of the site's own (a name, a folder), saving a submitted
 * definition (only what differs from the theme is written, and keys the form does not know are kept), and the rows the
 * screens show for the fields of a type.
 */
final class ContentTypeAdmin
{
    /** Names a content type cannot take: they are addresses or folders the site already uses. */
    public const RESERVED_NAMES = ['pages', 'settings', 'users', 'media', 'menus', 'taxonomies', 'forms', 'forms-submissions', 'search', 'tag', 'tags', 'category', 'categories', 'admin', 'uploads', 'assets', 'robots', 'sitemap', 'custom'];

    /**
     * @param callable(): string[] $taxonomyNames the taxonomies entries can be filtered by
     * @param callable(string): bool $isReservedKey whether a front matter key is one the CMS uses itself
     * @param callable(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     * @param (callable(string, bool): bool)|null $setEnabled switches a type of the catalogue on or off in the site settings
     */
    public function __construct(
        private ContentTypes $types,
        private string $contentDir,
        private $taxonomyNames,
        private $isReservedKey,
        private $log,
        private $setEnabled = null
    ) {
    }

    /**
     * Every content type in one list: the ones in use first (in the order the admin menu has them), then the ready-made ones the
     * theme ships that are off. `switchable` types are the theme's; pages and the site's own types are always on.
     *
     * @param string[] $manageable the types that are on and can be edited here (not forms)
     * @return array<int, array{type: string, label: string, description: string, fields: int, items: int, layout: string, origin: string, enabled: bool, switchable: bool}>
     */
    public function typeRows(array $manageable, string $default): array
    {
        $catalogue = array_values(array_diff($this->types->catalogue(), ['pages', 'forms']));
        $row = function (string $type, bool $enabled) use ($catalogue, $default): array {
            $definition = $enabled ? $this->types->definition($type, $default, $default) : $this->types->themeDefinition($type, $default, $default);
            return [
                'type' => $type,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'fields' => count(array_filter($definition['fields'], static fn(array $f): bool => !$f['hidden'])),
                'items' => count(glob($this->contentDir . '/' . $type . '/*.md') ?: []),
                'layout' => $definition['archive']['layout'],
                'origin' => $definition['origin'],
                'enabled' => $enabled,
                'switchable' => in_array($type, $catalogue, true),
            ];
        };
        $rows = [];
        foreach ($manageable as $type) {
            $rows[] = $row($type, true);
        }
        foreach ($catalogue as $type) {
            if (!in_array($type, $manageable, true)) {
                $rows[] = $row($type, false);
            }
        }
        return $rows;
    }

    /**
     * Switches a type of the catalogue on or off. The files of a type that is switched off stay where they are.
     *
     * @param array<string, mixed> $post type, enabled (1 to switch on)
     * @return string where to send the browser
     */
    public function toggle(array $post): string
    {
        $type = Slug::plain((string)($post['type'] ?? ''));
        $on = (string)($post['enabled'] ?? '') === '1';
        if (!in_array($type, $this->types->catalogue(), true) || in_array($type, ['pages', 'forms'], true) || $this->setEnabled === null) {
            return '/admin/content-types?error=type';
        }
        if (!($this->setEnabled)($type, $on)) {
            return '/admin/content-types?error=settings';
        }
        ($this->log)($on ? 'content_types.enable' : 'content_types.disable', 'info', 'content_type', $type, $on ? 'Content type switched on.' : 'Content type switched off.', []);
        return '/admin/content-types?toggled=' . ($on ? 'on' : 'off') . '&type_name=' . urlencode($type);
    }

    /**
     * Makes a content type of the site's own.
     *
     * @param array<string, mixed> $post name, label
     * @param string[] $existing the types that exist
     * @return string where to send the browser
     */
    public function create(array $post, array $existing): string
    {
        $name = Slug::plain((string)($post['name'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $name) || in_array($name, self::RESERVED_NAMES, true) || in_array($name, $existing, true) || in_array($name, ($this->taxonomyNames)(), true)) {
            return '/admin/content-types?error=name';
        }
        $label = trim((string)($post['label'] ?? ''));
        $dir = $this->contentDir . '/' . $name;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '/admin/content-types?error=write';
        }
        if (!$this->types->saveCustom($name, ['label' => $label !== '' ? $label : Slug::title($name)])) {
            return '/admin/content-types?error=write';
        }
        ($this->log)('content_types.create', 'info', 'content_type', $name, 'Content type created.', []);
        return '/admin/content-types?type=' . urlencode($name) . '&saved=1';
    }

    /**
     * Saves a submitted definition.
     *
     * @param array<string, mixed> $post
     * @param string[] $manageable the types that can be changed
     * @return string where to send the browser
     */
    public function update(array $post, array $manageable, string $default): string
    {
        $type = Slug::plain((string)($post['type'] ?? ''));
        if (!in_array($type, $manageable, true)) {
            return '/admin/content-types';
        }
        $definition = $this->definitionFromInput($type, $post, $default);
        if (!$this->types->saveCustom($type, $definition)) {
            return '/admin/content-types?type=' . urlencode($type) . '&error=write';
        }
        ($this->log)('content_types.update', 'info', 'content_type', $type, 'Content type updated.', ['fields' => count($definition['fields'] ?? [])]);
        return '/admin/content-types?type=' . urlencode($type) . '&saved=1';
    }

    /**
     * Saves the archive settings of a type, as submitted by Theme > Archive Layouts. The file is only written when something
     * changed. Returns false when it could not be written.
     *
     * @param array<string, mixed> $input the submitted `archive` values
     */
    public function updateArchive(string $type, array $input, string $default): bool
    {
        $before = $this->types->customRaw($type);
        $after = $this->withArchive($type, $before, $input, $default);
        if ($after == $before) {
            return true;
        }
        if (!$this->types->saveCustom($type, $after)) {
            return false;
        }
        ($this->log)('content_types.update', 'info', 'content_type', $type, 'Archive layout updated.', ['archive' => $after['archive'] ?? []]);
        return true;
    }

    /**
     * The site's definition of a type with the submitted archive settings in it: only what differs from the theme.
     *
     * @param array<string, mixed> $custom the site's definition now
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function withArchive(string $type, array $custom, array $input, string $default): array
    {
        $theme = $this->types->themeDefinition($type, $default, $default);
        $archive = ContentTypes::archiveFromInput(
            $input,
            is_array($custom['archive'] ?? null) ? $custom['archive'] : [],
            $theme['archive'],
            ($this->taxonomyNames)(),
            true,
            $this->types->definition($type, $default, $default)['archive_options']
        );
        if ($archive === []) {
            unset($custom['archive']);
        } else {
            $custom['archive'] = $archive;
        }
        return $custom;
    }

    /**
     * The fields of a type as the editor's rows: what they are called, what kind they are, and how they are used.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $themeDefinition
     * @return array<int, array<string, mixed>>
     */
    public function fieldRows(array $definition, array $themeDefinition): array
    {
        $rows = [];
        foreach ($definition['fields'] as $key => $field) {
            $options = [];
            foreach ($field['type'] === 'select' ? $field['options'] : [] as $value => $label) {
                if ((string)$value !== '') {
                    $options[] = $value . '|' . $label;
                }
            }
            $rows[] = [
                'key' => (string)$key,
                'label' => $field['label'],
                'help' => $field['help'],
                'type' => $field['type'],
                'options' => implode("\n", $options),
                'filterable' => $field['filterable'],
                'card' => $field['card'],
                'show' => $field['show'],
                'retired' => $field['hidden'],
                'from_theme' => isset($themeDefinition['fields'][$key]),
                'sub_fields' => $field['type'] === 'repeater' ? self::subFieldSummary($field['fields']) : ($field['type'] === 'relation' ? ($field['types'] !== [] ? implode(', ', $field['types']) : 'any type') . (($field['multiple'] ?? false) ? ' (several)' : '') : ''),
            ];
        }
        return $rows;
    }

    /** The fields of a repeater's rows in one line: "Label (text), Links (repeater: Label, Address)". @param array<string, array<string, mixed>> $fields */
    private static function subFieldSummary(array $fields): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = $field['label'] . ' (' . ($field['type'] === 'repeater' ? 'list: ' . self::subFieldSummary($field['fields']) : $field['type']) . ')';
        }
        return implode(', ', $parts);
    }

    /**
     * One row for each type, for the list.
     *
     * @param string[] $manageable
     * @return array<int, array{type: string, label: string, fields: int, origin: string, layout: string}>
     */
    public function overview(array $manageable, string $default): array
    {
        $rows = [];
        foreach ($manageable as $type) {
            $definition = $this->types->definition($type, $default, $default);
            $rows[] = [
                'type' => $type,
                'label' => $definition['label'],
                'fields' => count(array_filter($definition['fields'], static fn(array $f): bool => !$f['hidden'])),
                'origin' => $definition['origin'],
                'layout' => $definition['archive']['layout'],
            ];
        }
        return $rows;
    }

    /**
     * The site's definition file for a type after applying a submitted form. Starts from what the file
     * holds now (so keys the form does not know are kept) and writes only differences from the theme.
     *
     * @return array<string, mixed>
     */
    public function definitionFromInput(string $type, array $post, string $default): array
    {
        $theme = $this->types->themeDefinition($type, $default, $default);
        $out = $this->types->customRaw($type);
        $text = static fn(string $key): string => trim((string)preg_replace('/\s+/', ' ', (string)($post[$key] ?? '')));

        foreach (['label', 'singular', 'description'] as $key) {
            $submitted = $text($key);
            if ($submitted === '' || $submitted === $theme[$key]) {
                unset($out[$key]);
            } else {
                $out[$key] = $submitted;
            }
        }

        // Archive: only what differs from the theme. It is edited in Theme > Archive Layouts; a form without it leaves it alone.
        if (is_array($post['archive'] ?? null)) {
            $out = $this->withArchive($type, $out, $post['archive'], $default);
        }

        // Fields.
        $fields = is_array($out['fields'] ?? null) ? $out['fields'] : [];
        $themeKeys = array_keys($theme['fields']);
        $seen = [];
        foreach (is_array($post['fields'] ?? null) ? $post['fields'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = strtolower(trim((string)($row['key'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) || isset($seen[$key]) || ($this->isReservedKey)($key)) {
                continue;
            }
            $seen[$key] = true;
            $retired = FieldSchema::isTruthy($row['retired'] ?? false);
            $card = FieldSchema::isTruthy($row['card'] ?? false);
            $show = FieldSchema::isTruthy($row['show'] ?? false);
            $filterable = FieldSchema::isTruthy($row['filterable'] ?? false);

            if (in_array($key, $themeKeys, true)) {
                // A theme field: only how it is used can change.
                $base = $theme['fields'][$key];
                $entry = is_array($fields[$key] ?? null) ? $fields[$key] : [];
                $flags = ['card' => $card, 'show' => $show, 'hidden' => $retired];
                if ($base['type'] === 'select') {
                    $flags['filterable'] = $filterable;
                }
                foreach ($flags as $flag => $value) {
                    if ($value === $base[$flag]) {
                        unset($entry[$flag]);
                    } else {
                        $entry[$flag] = $value;
                    }
                }
                if ($entry === []) {
                    unset($fields[$key]);
                } else {
                    $fields[$key] = $entry;
                }
                continue;
            }

            // A site field: everything can change. Details the form does not offer are kept.
            $entry = is_array($fields[$key] ?? null) ? $fields[$key] : [];
            // A repeater (and a relation, with the types it points at) keeps its kind and its own fields: they are written in the YAML file, the form shows them only.
            $type_ = in_array($entry['type'] ?? '', ['repeater', 'relation'], true) ? (string)$entry['type']
                : (in_array((string)($row['type'] ?? ''), ContentTypes::FORM_FIELD_TYPES, true) ? (string)$row['type'] : 'text');
            $entry['type'] = $type_;
            $label = trim((string)preg_replace('/\s+/', ' ', (string)($row['label'] ?? '')));
            $currentLabel = trim((string)ContentTypes::pick($entry['label'] ?? '', $default, $default));
            if ($label === '') {
                $label = ucfirst(str_replace('_', ' ', $key));
            }
            if ($label !== $currentLabel) {
                $entry['label'] = $label;
            }
            $help = trim((string)preg_replace('/\s+/', ' ', (string)($row['help'] ?? '')));
            if ($help !== trim((string)ContentTypes::pick($entry['help'] ?? '', $default, $default))) {
                if ($help === '') {
                    unset($entry['help']);
                } else {
                    $entry['help'] = $help;
                }
            }
            if ($type_ === 'select') {
                $options = ['' => '—'];
                foreach (preg_split('/\R/', (string)($row['options'] ?? '')) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    [$value, $optionLabel] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : [Slug::plain($line), $line];
                    $value = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($value)) ?? '';
                    $value = trim($value, '-');
                    if ($value !== '' && $optionLabel !== '') {
                        $options[$value] = $optionLabel;
                    }
                }
                $existing = is_array($entry['options'] ?? null) ? $entry['options'] : [];
                $existingText = [];
                foreach ($existing as $value => $optionLabel) {
                    if ((string)$value !== '') {
                        $existingText[(string)$value] = (string)ContentTypes::pick($optionLabel, $default, $default);
                    }
                }
                $newText = $options;
                unset($newText['']);
                if ($existingText !== $newText) {
                    $entry['options'] = $options;
                }
                if ($filterable) {
                    $entry['filterable'] = true;
                } else {
                    unset($entry['filterable']);
                }
            } else {
                unset($entry['options'], $entry['filterable']);
            }
            foreach (['card' => $card, 'hidden' => $retired] as $flag => $value) {
                if ($value) {
                    $entry[$flag] = true;
                } else {
                    unset($entry[$flag]);
                }
            }
            if ($show) {
                unset($entry['show']);
            } else {
                $entry['show'] = false;
            }
            $fields[$key] = $entry;
        }
        // Site fields whose row was removed leave the definition; their stored values stay in the content.
        foreach (array_keys($fields) as $key) {
            if (!isset($seen[$key]) && !in_array((string)$key, $themeKeys, true)) {
                unset($fields[$key]);
            }
        }
        if ($fields === []) {
            unset($out['fields']);
        } else {
            $out['fields'] = $fields;
        }
        return $out;
    }
}
