<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Carries the whole theme of a site to another (Theme > Export and import), and puts it back. Branding alone has its own export
 * (BrandingTransfer); this one takes everything the Theme screen sets:
 *
 *  - every section of the theme's settings (Branding, Header, Footer, Sidebar card, Brand, Social profiles, …), with the font files
 *    Branding uses;
 *  - Single Layouts and the single templates built with blocks, for each content type;
 *  - Archive Layouts (kept in the site's definition of each content type and in each taxonomy's file) with the search page, and the
 *    archive templates built with blocks;
 *  - Footer Blocks, and the site's Custom CSS.
 *
 * Export writes a zip with `theme.json` and the font files under `assets/`. Import reads it, shows what each part would change, and
 * applies only the parts ticked. A section of settings, Footer Blocks, the search page and Custom CSS are replaced by the file's; the
 * layouts and templates are replaced for each content type or taxonomy the file has and this site has too, and left alone for the
 * others. Every value goes through the same checks as the Theme screen (the settings schema, the archive schema, the block registry
 * and the HTML guard), so a file can hold nothing the screen could not have saved. Template files, blocks and other code in custom/
 * never travel: they are code and are copied by hand. What was there before is kept as one backup, and undo puts it back.
 *
 * It holds no session; the settings are read and written through the callables it is given.
 */
final class ThemeTransfer
{
    public const FORMAT = 'gnoziocms-theme';
    public const BACKUP_KEY = 'theme_backup';

    /** The sections of the settings that make Branding: one part. */
    public const BRANDING = ['appearance', 'design'];
    /** Sections that say who the site is (logo, profiles): offered, not ticked, since another site has its own. */
    public const IDENTITY = ['brand', 'social'];

    private const MAX_JSON = 8388608;
    private const MAX_FILE = 5242880;
    private const MAX_FILES = 24;

    private const LAYOUTS = ['single_layouts' => 'Single Layouts', 'single_templates' => 'Single templates', 'archive_layouts' => 'Archive Layouts', 'archive_templates' => 'Archive templates', 'footer_blocks' => 'Footer Blocks', 'custom_css' => 'Custom CSS'];

    /**
     * @param callable(): array<string, mixed> $load the resolved theme settings now
     * @param callable(array<string, mixed>): void $save stores theme settings
     * @param callable(): string[] $typeNames the content types the site has
     * @param callable(array<int|string, mixed>, mixed): array<string, array<int, mixed>> $cleanBlocks checks sets of blocks (BlockSets::clean)
     */
    public function __construct(
        private Theme $theme,
        private $load,
        private $save,
        private ?SystemMetaRepository $meta,
        private SingleLayouts $single,
        private ContentTypes $types,
        private Taxonomies $taxonomies,
        private $typeNames,
        private $cleanBlocks,
        private CustomCss $css,
        private string $publicDir,
        private string $version = '',
    ) {
    }

    /**
     * The parts an import can take, in the order of the Theme screen.
     *
     * @return array<string, array{label: string, ticked: bool}>
     */
    public function parts(): array
    {
        $schema = $this->theme->settingsSchema();
        $parts = [];
        if (array_intersect(self::BRANDING, array_keys($schema)) !== []) {
            $parts['branding'] = ['label' => 'Branding', 'ticked' => true];
        }
        foreach ($schema as $key => $section) {
            if (!in_array($key, self::BRANDING, true)) {
                $parts['section:' . $key] = ['label' => (string)($section['label'] ?? $key), 'ticked' => !in_array($key, self::IDENTITY, true)];
            }
        }
        foreach (self::LAYOUTS as $key => $label) {
            if ($key !== 'single_layouts' || $this->single->declared()) {
                $parts[$key] = ['label' => $label, 'ticked' => true];
            }
        }
        return $parts;
    }

    // ---- export ---------------------------------------------------------------------------------------------------

    /**
     * Writes the zip of the whole theme to $destination.
     *
     * @return string[] the files that went into it besides theme.json
     */
    public function export(string $destination): array
    {
        $settings = ($this->load)();
        $branding = $this->branding();
        $files = [];
        foreach ($branding->fontFiles(array_intersect_key($settings, array_flip(self::BRANDING))) as $relative => $path) {
            $files['assets/' . $relative] = $path;
        }
        $archives = ['types' => [], 'taxonomies' => []];
        foreach ($this->listTypes() as $type) {
            $raw = $this->types->customRaw($type);
            $archives['types'][$type] = is_array($raw['archive'] ?? null) ? $raw['archive'] : [];
        }
        foreach ($this->taxonomies->names() as $name) {
            $archives['taxonomies'][$name] = $this->taxonomies->load($name)['archive'];
        }
        $document = [
            'format' => self::FORMAT,
            'gnoziocms' => $this->version,
            'theme' => $this->theme->name(),
            'exported' => gmdate('c'),
            'settings' => $settings,
            'archives' => $archives,
            'custom_css' => $this->css->read(),
            'files' => array_keys($files),
        ];
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The export file could not be written.');
        }
        $zip->addFromString('theme.json', (string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();
        return array_keys($files);
    }

    // ---- import ---------------------------------------------------------------------------------------------------

    /**
     * Reads an uploaded file (a zip made by export, or its JSON alone).
     *
     * @return array{ok: bool, error?: string, document?: array<string, mixed>, files?: array<string, string>}
     */
    public function read(string $bytes): array
    {
        $files = [];
        if (str_starts_with($bytes, 'PK')) {
            $temp = tempnam(sys_get_temp_dir(), 'themezip');
            if ($temp === false || file_put_contents($temp, $bytes) === false) {
                return ['ok' => false, 'error' => 'The file could not be read.'];
            }
            $zip = new \ZipArchive();
            if ($zip->open($temp) !== true) {
                @unlink($temp);
                return ['ok' => false, 'error' => 'This is not a theme export (the zip cannot be opened).'];
            }
            try {
                $json = $zip->getFromName('theme.json');
                if (!is_string($json)) {
                    return ['ok' => false, 'error' => is_string($zip->getFromName('branding.json'))
                        ? 'This is a Branding export: import it in Theme > Branding > Export, import and reset.'
                        : 'This zip has no theme.json.'];
                }
                $branding = $this->branding();
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string)$zip->getNameIndex($i);
                    if (!str_starts_with($name, 'assets/')) {
                        continue;
                    }
                    $relative = substr($name, 7);
                    $stat = $zip->statIndex($i);
                    if (!$branding->isFontFile($relative) || !is_array($stat) || $stat['size'] > self::MAX_FILE || count($files) >= self::MAX_FILES) {
                        continue;
                    }
                    $content = $zip->getFromIndex($i);
                    if (is_string($content) && $content !== '') {
                        $files[$relative] = $content;
                    }
                }
            } finally {
                $zip->close();
                @unlink($temp);
            }
        } else {
            $json = $bytes;
        }
        if (strlen($json) > self::MAX_JSON) {
            return ['ok' => false, 'error' => 'The theme file is too large.'];
        }
        $document = json_decode($json, true);
        if (is_array($document) && ($document['format'] ?? '') === BrandingTransfer::FORMAT) {
            return ['ok' => false, 'error' => 'This is a Branding export: import it in Theme > Branding > Export, import and reset.'];
        }
        if (!is_array($document) || ($document['format'] ?? '') !== self::FORMAT || !is_array($document['settings'] ?? null)) {
            return ['ok' => false, 'error' => 'This is not a GnozioCMS theme export.'];
        }
        return ['ok' => true, 'document' => $document, 'files' => $files];
    }

    /**
     * What importing would change, part by part.
     *
     * @param array{document: array<string, mixed>, files: array<string, string>} $read
     * @return array{parts: list<array<string, mixed>>, warnings: string[], skipped: string[], files: list<array{path: string, status: string}>, theme: string, from: string}
     */
    public function preview(array $read): array
    {
        $current = $this->now();
        $next = $this->merged($current, $read['document'], array_keys($this->parts()));
        $parts = [];
        foreach ($this->parts() as $key => $part) {
            $changes = $this->changes($key, $current, $next);
            $parts[] = ['key' => $key, 'label' => $part['label'], 'ticked' => $part['ticked'], 'changes' => $changes, 'in_file' => $this->inFile($key, $read['document'])];
        }
        $files = [];
        foreach ($read['files'] as $relative => $content) {
            $path = $this->theme->customPath() . '/assets/' . $relative;
            $files[] = ['path' => $relative, 'status' => !is_file($path) ? 'new' : (hash_file('sha256', $path) === hash('sha256', $content) ? 'same' : 'replaced')];
        }
        $warnings = $next['warnings'];
        $missing = $this->missingImages($read['document']);
        if ($missing !== []) {
            $warnings[] = 'Pictures this site does not have (upload them with the same address, or choose others): ' . implode(', ', array_slice($missing, 0, 12)) . (count($missing) > 12 ? ' and ' . (count($missing) - 12) . ' more' : '') . '.';
        }
        $theme = (string)($read['document']['theme'] ?? '');
        if ($theme !== '' && $theme !== $this->theme->name()) {
            $warnings[] = 'The file was made with the theme "' . $theme . '"; this site uses "' . $this->theme->name() . '". Only the settings this theme knows are taken.';
        }
        return ['parts' => $parts, 'warnings' => $warnings, 'skipped' => $next['skipped'], 'files' => $files, 'theme' => $theme, 'from' => (string)($read['document']['gnoziocms'] ?? '')];
    }

    /**
     * Applies the parts chosen. What was there before is kept as the backup.
     *
     * @param array{document: array<string, mixed>, files: array<string, string>} $read
     * @param string[] $parts the keys of parts() to take
     * @return array{ok: bool, error?: string, changed: int, files: int}
     */
    public function apply(array $read, array $parts): array
    {
        $parts = array_values(array_intersect(array_keys($this->parts()), $parts));
        if ($parts === []) {
            return ['ok' => false, 'error' => 'Tick at least one part to import.', 'changed' => 0, 'files' => 0];
        }
        $current = $this->now();
        $next = $this->merged($current, $read['document'], $parts);
        $changed = 0;
        foreach ($parts as $key) {
            $changed += count($this->changes($key, $current, $next));
        }
        $written = 0;
        if (in_array('branding', $parts, true)) {
            $branding = $this->branding();
            foreach ($read['files'] as $relative => $content) {
                $path = $this->theme->customPath() . '/assets/' . $relative;
                if (!$branding->isFontFile($relative) || (is_file($path) && hash_file('sha256', $path) === hash('sha256', $content))) {
                    continue;
                }
                if ((!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) || file_put_contents($path, $content) === false) {
                    return ['ok' => false, 'error' => 'The font files could not be written. Check that the custom/ folder is writable.', 'changed' => 0, 'files' => $written];
                }
                $written++;
            }
        }
        if ($changed === 0) {
            return ['ok' => true, 'changed' => 0, 'files' => $written];
        }
        $this->meta?->setJson(self::BACKUP_KEY, ['at' => gmdate('c'), 'reason' => 'import', 'state' => $current]);
        $error = $this->write($current, $next);
        return $error === null ? ['ok' => true, 'changed' => $changed, 'files' => $written] : ['ok' => false, 'error' => $error, 'changed' => 0, 'files' => $written];
    }

    // ---- backup and undo ------------------------------------------------------------------------------------------

    /** @return array{at: string}|null when the theme from before the last import was kept */
    public function backup(): ?array
    {
        $backup = $this->meta?->getJson(self::BACKUP_KEY);
        return is_array($backup) && is_array($backup['state'] ?? null) ? ['at' => (string)($backup['at'] ?? '')] : null;
    }

    /** Puts the theme back as it was before the last import (imported font files stay). */
    public function undo(): bool
    {
        $backup = $this->meta?->getJson(self::BACKUP_KEY);
        $state = is_array($backup) ? ($backup['state'] ?? null) : null;
        if (!is_array($state) || !is_array($state['settings'] ?? null)) {
            return false;
        }
        $before = [
            'settings' => $this->theme->resolveSettings($state['settings']),
            'types' => is_array($state['types'] ?? null) ? $state['types'] : [],
            'taxonomies' => is_array($state['taxonomies'] ?? null) ? $state['taxonomies'] : [],
            'css' => is_string($state['css'] ?? null) ? $state['css'] : null,
        ];
        if ($this->write($this->now(), $before) !== null) {
            return false;
        }
        $this->meta?->set(self::BACKUP_KEY, '');
        return true;
    }

    // ---- inside ---------------------------------------------------------------------------------------------------

    /** @return array{settings: array<string, mixed>, types: array<string, array<string, mixed>>, taxonomies: array<string, array<string, mixed>>, css: string} */
    private function now(): array
    {
        $types = [];
        foreach ($this->listTypes() as $type) {
            $raw = $this->types->customRaw($type);
            $types[$type] = is_array($raw['archive'] ?? null) ? $raw['archive'] : [];
        }
        $taxonomies = [];
        foreach ($this->taxonomies->names() as $name) {
            $taxonomies[$name] = $this->taxonomies->load($name)['archive'];
        }
        return ['settings' => ($this->load)(), 'types' => $types, 'taxonomies' => $taxonomies, 'css' => $this->css->read()];
    }

    /**
     * The state after taking $parts of a file: each part checked as the Theme screen checks it.
     *
     * @param array<string, mixed> $current now()
     * @param array<string, mixed> $document the file's theme.json
     * @param string[] $parts
     * @return array{settings: array<string, mixed>, types: array<string, array<string, mixed>>, taxonomies: array<string, array<string, mixed>>, css: string, warnings: string[], skipped: string[]}
     */
    private function merged(array $current, array $document, array $parts): array
    {
        $incoming = is_array($document['settings'] ?? null) ? $document['settings'] : [];
        $schema = $this->theme->settingsSchema();
        $defaults = $this->theme->defaultSettings();
        $settings = $current['settings'];
        $knownTypes = $this->listTypes(true);
        $warnings = [];
        $skipped = [];
        $take = static fn(string $part): bool => in_array($part, $parts, true);

        // The sections of the settings: the file's values over the defaults, only the fields this theme declares.
        foreach ($schema as $key => $section) {
            // A section the file does not have (a file made by hand, or with another theme) is left as it is; an export always has them all.
            if (!$take(in_array($key, self::BRANDING, true) ? 'branding' : 'section:' . $key) || !is_array($incoming[$key] ?? null)) {
                continue;
            }
            $values = $defaults[$key] ?? [];
            foreach (is_array($incoming[$key] ?? null) ? $incoming[$key] : [] as $field => $value) {
                if (isset($section['fields'][$field]) && !($section['fields'][$field]['hidden'] ?? false)) {
                    $values[$field] = $value;
                } else {
                    $skipped[] = $key . '.' . $field;
                }
            }
            $settings[$key] = FieldSchema::resolve($section['fields'], $values);
        }
        if ($take('branding')) {
            $settings = SitePalettes::followSource(SitePalettes::fold($settings));
        }

        $absent = [];
        if ($take('single_layouts') && $this->single->declared() && is_array($incoming['single_layouts'] ?? null)) {
            $chosen = [];
            foreach ($incoming['single_layouts'] as $type => $card) {
                if (in_array((string)$type, $knownTypes, true) && is_array($card)) {
                    $chosen[(string)$type] = $card;
                } else {
                    $absent[] = (string)$type;
                }
            }
            $kept = is_array($settings['single_layouts'] ?? null) ? $settings['single_layouts'] : [];
            $settings['single_layouts'] = array_replace($kept, $this->single->fromInput($chosen, array_keys($chosen), $settings));
        }
        if ($take('single_templates') && is_array($incoming['single_templates'] ?? null)) {
            foreach ($incoming['single_templates'] as $type => $entry) {
                $type = (string)$type;
                if (!in_array($type, $knownTypes, true) || !is_array($entry)) {
                    $absent[] = $type;
                    continue;
                }
                $stored = SingleTemplates::entry($settings, $type)['blocks'];
                $sets = is_array($entry['blocks'] ?? null) ? $this->sets($entry['blocks'], $stored) : [];
                $settings['single_templates'] = SingleTemplates::with($settings, $type, ($entry['use'] ?? false) === true, $sets);
            }
        }
        $listKeys = array_merge(
            array_values(array_diff($knownTypes, ['pages', 'forms'])),
            array_map([ArchiveTemplates::class, 'forTaxonomy'], $this->taxonomies->names())
        );
        if ($take('archive_templates') && is_array($incoming[ArchiveTemplates::KEY] ?? null)) {
            foreach ($incoming[ArchiveTemplates::KEY] as $key => $entry) {
                $key = (string)$key;
                if (!in_array($key, $listKeys, true) || !is_array($entry)) {
                    $absent[] = $key;
                    continue;
                }
                $stored = ArchiveTemplates::entry($settings, $key);
                $page = is_array($entry['blocks'] ?? null) ? $this->sets($entry['blocks'], $stored['blocks']) : [];
                $card = is_array($entry['card'] ?? null) ? $this->sets($entry['card'], $stored['card']) : [];
                // The file's entry replaces the site's whole: its cards for each type too.
                unset($settings[ArchiveTemplates::KEY][$key]);
                $settings[ArchiveTemplates::KEY] = ArchiveTemplates::with($settings, $key, ($entry['use'] ?? false) === true, $page, $card);
                foreach (is_array($entry['card_types'] ?? null) ? $entry['card_types'] : [] as $type => $blocks) {
                    if (is_array($blocks) && in_array((string)$type, $knownTypes, true)) {
                        $settings[ArchiveTemplates::KEY] = ArchiveTemplates::withTypeCard($settings, $key, (string)$type, $this->sets($blocks, $stored['card_types'][$type] ?? []));
                    }
                }
            }
            if (($settings[ArchiveTemplates::KEY] ?? null) === []) {
                unset($settings[ArchiveTemplates::KEY]);
            }
        }
        if ($take('footer_blocks')) {
            $sets = is_array($incoming['footer_blocks'] ?? null) ? $this->sets($incoming['footer_blocks'], $settings['footer_blocks'] ?? null) : [];
            $kept = FooterBlocks::toStore($sets);
            if ($kept === []) {
                unset($settings['footer_blocks']);
            } else {
                $settings['footer_blocks'] = $kept;
            }
        }

        $types = $current['types'];
        $taxonomies = $current['taxonomies'];
        if ($take('archive_layouts')) {
            $keep = $this->searchPage(is_array($incoming['search_page'] ?? null) ? $incoming['search_page'] : []);
            if ($keep === []) {
                unset($settings['search_page']);
            } else {
                $settings['search_page'] = $keep;
            }
            $archives = is_array($document['archives'] ?? null) ? $document['archives'] : [];
            $taxonomyNames = $this->taxonomies->names();
            foreach (is_array($archives['types'] ?? null) ? $archives['types'] : [] as $type => $archive) {
                if (isset($types[(string)$type]) && is_array($archive)) {
                    $types[(string)$type] = $this->archive($archive, $taxonomyNames, true);
                } elseif ($archive !== []) {
                    $absent[] = (string)$type;
                }
            }
            foreach (is_array($archives['taxonomies'] ?? null) ? $archives['taxonomies'] : [] as $name => $archive) {
                if (isset($taxonomies[(string)$name]) && is_array($archive)) {
                    $taxonomies[(string)$name] = $this->archive($archive, array_values(array_diff($taxonomyNames, [(string)$name])), false);
                } elseif ($archive !== []) {
                    $absent[] = 'taxonomy ' . $name;
                }
            }
        }
        $css = $current['css'];
        if ($take('custom_css')) {
            $css = is_string($document['custom_css'] ?? null) ? $document['custom_css'] : '';
            if (strlen($css) > CustomCss::MAX_BYTES) {
                $warnings[] = 'The Custom CSS of the file is larger than ' . (int)(CustomCss::MAX_BYTES / 1024) . ' KB and is not taken.';
                $css = $current['css'];
            }
        }
        $absent = array_values(array_unique(array_filter($absent, static fn(string $name): bool => $name !== '')));
        if ($absent !== []) {
            $warnings[] = 'Layouts and templates for lists this site does not have are left out: ' . implode(', ', $absent) . '. Make the content type or taxonomy first, then import again.';
        }
        return ['settings' => $settings, 'types' => $types, 'taxonomies' => $taxonomies, 'css' => $css, 'warnings' => $warnings, 'skipped' => $skipped];
    }

    /**
     * The sets of blocks of a file, checked like the Theme screen checks them; only the file's languages (the site's other sets are
     * not kept, as the editor would keep them).
     *
     * @param array<int|string, mixed> $posted
     * @return array<string, array<int, mixed>>
     */
    private function sets(array $posted, mixed $stored): array
    {
        $keys = array_is_list($posted) ? ['default'] : array_map('strval', array_keys($posted));
        return array_intersect_key(($this->cleanBlocks)($posted, $stored), array_flip($keys));
    }

    /** The search page's values that differ from the defaults. @param array<string, mixed> $stored @return array<string, mixed> */
    private function searchPage(array $stored): array
    {
        $values = SearchPage::values(['search_page' => $stored]);
        $keep = [];
        foreach (SearchPage::schema() as $key => $field) {
            if (($values[$key] ?? null) !== $field['default']) {
                $keep[$key] = $values[$key];
            }
        }
        return $keep;
    }

    /**
     * The archive settings of a type or taxonomy from a file, as they are stored (only what differs from the theme), each value checked.
     *
     * @param array<string, mixed> $archive
     * @param string[] $taxonomyNames the taxonomies a list may filter by
     * @return array<string, mixed>
     */
    private function archive(array $archive, array $taxonomyNames, bool $fieldOrders): array
    {
        $schema = ContentTypes::archiveSchema();
        $out = [];
        foreach ($schema as $key => $field) {
            if (array_key_exists($key, $archive)) {
                $out[$key] = $field['type'] === 'toggle' ? FieldSchema::isTruthy($archive[$key]) : FieldSchema::clean($field, $archive[$key]);
            }
        }
        $order = (string)($archive['order'] ?? '');
        if (isset(ContentTypes::ORDERS[$order]) || ($fieldOrders && preg_match('/^field:[a-z][a-z0-9_]*:(asc|desc)$/', $order) === 1)) {
            $out['order'] = $order;
        } else {
            unset($out['order']);
        }
        foreach (['title', 'subtitle'] as $key) {
            $text = $this->text($archive[$key] ?? null);
            if ($text !== null) {
                $out[$key] = $text;
            }
        }
        $names = static fn(mixed $list): array => array_values(array_intersect($taxonomyNames, array_map('strval', is_array($list) ? $list : [])));
        if (is_array($archive['taxonomies'] ?? null)) {
            $out['taxonomies'] = $names($archive['taxonomies']);
        }
        if (array_key_exists('map_filters', $archive)) {
            $out['map_filters'] = $archive['map_filters'] === 'auto' ? 'auto' : (is_array($archive['map_filters']) ? $names($archive['map_filters']) : null);
            if ($out['map_filters'] === null) {
                unset($out['map_filters']);
            }
        }
        if (!$fieldOrders && is_array($archive['types'] ?? null)) {
            $types = array_values(array_intersect($this->listTypes(), array_map('strval', $archive['types'])));
            if ($types !== []) {
                $out['types'] = $types;
            }
        }
        foreach (['include_children', 'sub_term_chips'] as $key) {
            if (($archive[$key] ?? null) === false) {
                $out[$key] = false;
            }
        }
        if (is_array($archive['options'] ?? null)) {
            $options = array_filter($archive['options'], static fn(mixed $value, mixed $key): bool => is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1 && (is_scalar($value) || $value === null), ARRAY_FILTER_USE_BOTH);
            if ($options !== []) {
                $out['options'] = $options;
            }
        }
        return $out;
    }

    /** A text of a list (plain, or one for each language), on one line; null when it is not one. */
    private function text(mixed $value): string|array|null
    {
        $clean = static fn(mixed $text): string => trim((string)preg_replace('/\s+/', ' ', is_scalar($text) ? (string)$text : ''));
        if (is_scalar($value)) {
            return $clean($value);
        }
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $lang => $text) {
            if (is_string($lang) && preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $lang) === 1 && is_scalar($text)) {
                $out[$lang] = $clean($text);
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * Stores a state: the settings, the archive settings of the types and taxonomies that changed, the stylesheet.
     *
     * @return string|null what went wrong
     */
    private function write(array $before, array $after): ?string
    {
        if (!$this->same($before['settings'], $after['settings'])) {
            ($this->save)($after['settings']);
        }
        $failed = [];
        foreach ($after['types'] as $type => $archive) {
            if (!isset($before['types'][$type]) || $this->same($before['types'][$type], $archive)) {
                continue;
            }
            $custom = $this->types->customRaw((string)$type);
            if ($archive === []) {
                unset($custom['archive']);
            } else {
                $custom['archive'] = $archive;
            }
            if (!$this->types->saveCustom((string)$type, $custom)) {
                $failed[] = (string)$type;
            }
        }
        foreach ($after['taxonomies'] as $name => $archive) {
            if (!isset($before['taxonomies'][$name]) || $this->same($before['taxonomies'][$name], $archive)) {
                continue;
            }
            $loaded = $this->taxonomies->load((string)$name);
            $this->taxonomies->save((string)$name, $loaded['title'], $loaded['terms'], $archive);
        }
        if (is_string($after['css'] ?? null) && $after['css'] !== $before['css']) {
            $saved = $this->css->save($after['css']);
            if (($saved['ok'] ?? false) !== true) {
                return (string)($saved['message'] ?? 'The Custom CSS could not be written.');
            }
        }
        return $failed === [] ? null : 'Could not write the archive layout of ' . implode(', ', $failed) . '. Check that the custom/ folder is writable.';
    }

    /**
     * What a part would change, as rows of the preview.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    private function changes(string $part, array $current, array $next): array
    {
        $rows = [];
        $schema = $this->theme->settingsSchema();
        $sections = $part === 'branding' ? self::BRANDING : (str_starts_with($part, 'section:') ? [substr($part, 8)] : []);
        foreach ($sections as $key) {
            foreach ($schema[$key]['fields'] ?? [] as $field => $definition) {
                $from = $current['settings'][$key][$field] ?? null;
                $to = $next['settings'][$key][$field] ?? null;
                if (!$this->same($from, $to)) {
                    $rows[] = ['label' => (string)($definition['label'] ?? $field), 'from' => $this->show($from), 'to' => $this->show($to)];
                }
            }
        }
        $maps = match ($part) {
            'single_layouts' => [[$current['settings']['single_layouts'] ?? [], $next['settings']['single_layouts'] ?? [], '']],
            'single_templates' => [[$current['settings']['single_templates'] ?? [], $next['settings']['single_templates'] ?? [], '']],
            'archive_templates' => [[$current['settings'][ArchiveTemplates::KEY] ?? [], $next['settings'][ArchiveTemplates::KEY] ?? [], '']],
            'archive_layouts' => [[$current['types'], $next['types'], ''], [$current['taxonomies'], $next['taxonomies'], 'taxonomy '], [['search page' => $current['settings']['search_page'] ?? []], ['search page' => $next['settings']['search_page'] ?? []], '']],
            default => [],
        };
        foreach ($maps as [$from, $to, $prefix]) {
            foreach (array_unique(array_merge(array_keys(is_array($from) ? $from : []), array_keys(is_array($to) ? $to : []))) as $key) {
                $a = $from[$key] ?? null;
                $b = $to[$key] ?? null;
                if (!$this->same($a, $b)) {
                    $rows[] = ['label' => $prefix . $key, 'from' => $this->summary($part, $a), 'to' => $this->summary($part, $b)];
                }
            }
        }
        if ($part === 'footer_blocks' && !$this->same($current['settings']['footer_blocks'] ?? null, $next['settings']['footer_blocks'] ?? null)) {
            $rows[] = ['label' => 'Footer Blocks', 'from' => $this->blockCount($current['settings']['footer_blocks'] ?? null), 'to' => $this->blockCount($next['settings']['footer_blocks'] ?? null)];
        }
        if ($part === 'custom_css' && $current['css'] !== $next['css']) {
            $lines = static fn(string $css): string => $css === '' ? '' : (substr_count(rtrim($css, "\n"), "\n") + 1) . ' lines';
            $rows[] = ['label' => 'Custom CSS', 'from' => $lines($current['css']), 'to' => $lines($next['css'])];
        }
        return $rows;
    }

    /** One line about an entry of a layout part. */
    private function summary(string $part, mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }
        if (is_array($value) && in_array($part, ['single_templates', 'archive_templates'], true)) {
            $text = (($value['use'] ?? false) === true ? 'on' : 'off') . ', ' . $this->blockCount($value['blocks'] ?? null);
            if ($part === 'archive_templates' && ($value['card'] ?? []) !== []) {
                $text .= ', card of ' . $this->blockCount($value['card']);
            }
            return $text;
        }
        if (is_array($value) && !array_is_list($value)) {
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = $key . ': ' . (is_bool($item) ? ($item ? 'on' : 'off') : (is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            }
            return $this->cut(implode(', ', $pairs));
        }
        return $this->show($value);
    }

    private function blockCount(mixed $stored): string
    {
        if (!is_array($stored) || $stored === []) {
            return '';
        }
        $sets = array_is_list($stored) ? [$stored] : array_filter($stored, 'is_array');
        $count = array_sum(array_map('count', $sets));
        return $count . ' block' . ($count === 1 ? '' : 's') . (count($sets) > 1 ? ' in ' . count($sets) . ' languages' : '');
    }

    /** Whether the file has anything for a part. */
    private function inFile(string $part, array $document): bool
    {
        $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];
        return match (true) {
            $part === 'branding' => array_intersect_key($settings, array_flip(self::BRANDING)) !== [],
            str_starts_with($part, 'section:') => is_array($settings[substr($part, 8)] ?? null),
            $part === 'archive_layouts' => is_array($document['archives'] ?? null) || isset($settings['search_page']),
            $part === 'custom_css' => is_string($document['custom_css'] ?? null) && $document['custom_css'] !== '',
            default => ($settings[$part] ?? []) !== [],
        };
    }

    /**
     * The addresses of uploaded pictures the file names that this site has no file for.
     *
     * @return string[]
     */
    private function missingImages(array $document): array
    {
        $text = (string)json_encode([$document['settings'] ?? [], $document['archives'] ?? []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        preg_match_all('#/uploads/[A-Za-z0-9._~%/-]+\.[A-Za-z0-9]{2,5}#', $text, $found);
        $missing = [];
        foreach (array_unique($found[0]) as $address) {
            $path = rawurldecode($address);
            if (!str_contains($path, '..') && !is_file($this->publicDir . $path)) {
                $missing[] = $address;
            }
        }
        return $missing;
    }

    /** @return string[] the site's content types; without `pages` and `forms` unless $all (they have no list) */
    private function listTypes(bool $all = false): array
    {
        $types = array_values(array_map('strval', ($this->typeNames)()));
        return $all ? $types : array_values(array_diff($types, ['pages', 'forms']));
    }

    private function branding(): BrandingTransfer
    {
        return new BrandingTransfer($this->theme, $this->load, $this->save, null);
    }

    private function same(mixed $a, mixed $b): bool
    {
        return json_encode($a ?? '') === json_encode($b ?? '');
    }

    private function show(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }
        return $this->cut(is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function cut(string $text): string
    {
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text;
    }
}
