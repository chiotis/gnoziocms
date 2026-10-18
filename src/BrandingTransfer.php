<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Carries the look of a site (Theme > Branding) from one site to another, and puts it back.
 *
 *  - export: a zip with `branding.json` (the `appearance` and `design` settings, whole) and the font files they refer to
 *    (`assets/...`, from the site's custom/assets). The tree is exported as it is, with no list of keys, so whatever fields
 *    the theme gains (fonts, site palettes, links, buttons) travel too. A file from before the palettes were the one place for
 *    colours (per-role overrides under `design`) is converted on import into a site palette.
 *  - import: reads a zip or a JSON file, shows what would change (preview), and applies it. Only keys the theme declares are
 *    taken, each checked by the settings schema; the rest is reported as skipped. Settings that were there before are kept as a
 *    backup, and undo puts them back.
 *  - reset: puts the settings of one Branding section back to their defaults, with the same backup.
 *
 * It holds no session and no database: the settings are read and written through the two callables it is given.
 */
final class BrandingTransfer
{
    public const FORMAT = 'faroscms-branding';
    public const BACKUP_KEY = 'branding_backup';
    /** The settings trees that make the look of a site. */
    public const TREES = ['appearance', 'design'];

    private const MAX_JSON = 1048576;
    private const MAX_FILE = 5242880;
    private const MAX_FILES = 24;
    private const FILE_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9_./-]*\.(woff2|woff|ttf|otf)$#i';

    /**
     * The sections of the Branding screen that can be reset: the keys of `design` each owns, as patterns.
     *
     * @var array<string, array{label: string, patterns: string[]}>
     */
    public const SECTIONS = [
        'colour' => ['label' => 'Colour', 'patterns' => ['/^(light|dark)_/', '/^ink$/', '/^heading_color/', '/^secondary/', '/^palette/', '/^site_palette/', '/^duo_/']],
        'type' => ['label' => 'Typography', 'patterns' => ['/^(heading_font|body_font|font_file|fonts?|font_)/', '/^(base_size|line_height|scale)$/', '/^heading_(weight|tracking|leading|case)$/', '/^(h1|h2|h3|lead|small)_/', '/^(body_weight|paragraph_space)$/']],
        'layout' => ['label' => 'Layout and spacing', 'patterns' => ['/^(container|reading_width|gutter|section_space|header_height|spacing)$/', '/^mobile_/']],
        'shape' => ['label' => 'Shape and depth', 'patterns' => ['/^radius(_|$)/', '/^(border_width|shadows)$/']],
        'forms' => ['label' => 'Forms', 'patterns' => ['/^(input_|label_)/', '/^(input_ring|error_style)$/']],
        'buttons' => ['label' => 'Buttons', 'patterns' => ['/^(button_|btn_|link_)/', '/^(buttons?|links?)$/']],
    ];

    /**
     * @param callable(): array<string, mixed> $load the resolved theme settings now
     * @param callable(array<string, mixed>): void $save stores theme settings
     * @param SystemMetaRepository|null $meta where the backup is kept (null: no backup can be kept)
     */
    public function __construct(
        private Theme $theme,
        private $load,
        private $save,
        private ?SystemMetaRepository $meta,
        private string $version = '',
    ) {
    }

    // ---- export ---------------------------------------------------------------------------------------------------

    /**
     * Writes the zip of the current Branding to $destination.
     *
     * @return string[] the files that went into it, as `assets/…`
     */
    public function export(string $destination): array
    {
        $settings = ($this->load)();
        $tree = $this->tree($settings);
        $files = [];
        foreach ($this->referencedFiles($tree) as $relative => $path) {
            $files['assets/' . $relative] = $path;
        }
        $document = [
            'format' => self::FORMAT,
            'faroscms' => $this->version,
            'theme' => $this->theme->name(),
            'exported' => gmdate('c'),
            'settings' => $tree,
            'files' => array_keys($files),
        ];
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The export file could not be written.');
        }
        $zip->addFromString('branding.json', (string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
     * @return array{ok: bool, error?: string, settings?: array<string, array<string, mixed>>, files?: array<string, string>, theme?: string}
     */
    public function read(string $bytes): array
    {
        $files = [];
        if (str_starts_with($bytes, "PK")) {
            $temp = tempnam(sys_get_temp_dir(), 'brandzip');
            if ($temp === false || file_put_contents($temp, $bytes) === false) {
                return ['ok' => false, 'error' => 'The file could not be read.'];
            }
            $zip = new \ZipArchive();
            if ($zip->open($temp) !== true) {
                @unlink($temp);
                return ['ok' => false, 'error' => 'This is not a Branding export (the zip cannot be opened).'];
            }
            try {
                $json = $zip->getFromName('branding.json');
                if (!is_string($json)) {
                    return ['ok' => false, 'error' => 'This zip has no branding.json.'];
                }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string)$zip->getNameIndex($i);
                    if (!str_starts_with($name, 'assets/')) {
                        continue;
                    }
                    $relative = substr($name, 7);
                    $stat = $zip->statIndex($i);
                    if (!$this->safeFile($relative) || !is_array($stat) || $stat['size'] > self::MAX_FILE || count($files) >= self::MAX_FILES) {
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
            return ['ok' => false, 'error' => 'The Branding file is too large.'];
        }
        $document = json_decode($json, true);
        if (!is_array($document) || ($document['format'] ?? '') !== self::FORMAT || !is_array($document['settings'] ?? null)) {
            return ['ok' => false, 'error' => 'This is not a GnozioCMS Branding export.'];
        }
        $settings = [];
        foreach (self::TREES as $tree) {
            $settings[$tree] = is_array($document['settings'][$tree] ?? null) ? $document['settings'][$tree] : [];
        }
        return ['ok' => true, 'settings' => $settings, 'files' => $files, 'theme' => (string)($document['theme'] ?? '')];
    }

    /**
     * What importing would change.
     *
     * @param array{settings: array<string, array<string, mixed>>, files: array<string, string>} $read
     * @return array{changes: list<array{section: string, key: string, label: string, from: string, to: string}>, skipped: string[], files: list<array{path: string, status: string}>}
     */
    public function preview(array $read): array
    {
        $current = ($this->load)();
        [$next, $skipped] = $this->merged($current, $read['settings']);
        $changes = [];
        foreach (self::TREES as $tree) {
            $fields = $this->theme->settingsSchema()[$tree]['fields'] ?? [];
            foreach ($fields as $key => $field) {
                $before = $current[$tree][$key] ?? null;
                $after = $next[$tree][$key] ?? null;
                if ($this->same($before, $after) === false) {
                    $changes[] = ['section' => $tree, 'key' => (string)$key, 'label' => (string)($field['label'] ?? $key), 'from' => $this->show($before), 'to' => $this->show($after)];
                }
            }
        }
        $files = [];
        foreach ($read['files'] as $relative => $content) {
            $path = $this->theme->customPath() . '/assets/' . $relative;
            $files[] = ['path' => $relative, 'status' => !is_file($path) ? 'new' : (hash_file('sha256', $path) === hash('sha256', $content) ? 'same' : 'replaced')];
        }
        return ['changes' => $changes, 'skipped' => $skipped, 'files' => $files];
    }

    /**
     * Applies an import. The settings of before are kept as the backup.
     *
     * @param array{settings: array<string, array<string, mixed>>, files: array<string, string>} $read
     * @return array{ok: bool, error?: string, changed: int, files: int}
     */
    public function apply(array $read): array
    {
        $current = ($this->load)();
        [$next] = $this->merged($current, $read['settings']);
        $written = 0;
        foreach ($read['files'] as $relative => $content) {
            if (!$this->safeFile($relative)) {
                continue;
            }
            $path = $this->theme->customPath() . '/assets/' . $relative;
            if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $content)) {
                continue;
            }
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                return ['ok' => false, 'error' => 'The font files could not be written. Check that the custom/ folder is writable.', 'changed' => 0, 'files' => $written];
            }
            if (file_put_contents($path, $content) === false) {
                return ['ok' => false, 'error' => 'A font file could not be written.', 'changed' => 0, 'files' => $written];
            }
            $written++;
        }
        $changed = $this->store($current, $next, 'import');
        return ['ok' => true, 'changed' => $changed, 'files' => $written];
    }

    // ---- backup, undo, reset --------------------------------------------------------------------------------------

    /** @return array{at: string, reason: string}|null when the previous settings were kept, and why */
    public function backup(): ?array
    {
        $backup = $this->meta?->getJson(self::BACKUP_KEY);
        return is_array($backup) && is_array($backup['settings'] ?? null) ? ['at' => (string)($backup['at'] ?? ''), 'reason' => (string)($backup['reason'] ?? '')] : null;
    }

    /** Puts the settings back as they were before the last import or reset. */
    public function undo(): bool
    {
        $backup = $this->meta?->getJson(self::BACKUP_KEY);
        if (!is_array($backup) || !is_array($backup['settings'] ?? null)) {
            return false;
        }
        $current = ($this->load)();
        $restored = $current;
        foreach (self::TREES as $tree) {
            $restored[$tree] = is_array($backup['settings'][$tree] ?? null) ? $backup['settings'][$tree] : ($current[$tree] ?? []);
        }
        ($this->save)($this->theme->resolveSettings($restored));
        $this->meta?->set(self::BACKUP_KEY, '');
        return true;
    }

    /**
     * Puts the `design` settings of one section back to their defaults.
     *
     * @return int how many settings changed (-1 when the section is not one)
     */
    public function reset(string $section): int
    {
        if (!isset(self::SECTIONS[$section])) {
            return -1;
        }
        $current = ($this->load)();
        $defaults = $this->theme->defaultSettings();
        $next = $current;
        foreach (array_keys($this->theme->settingsSchema()['design']['fields'] ?? []) as $key) {
            if ($this->inSection((string)$key, $section)) {
                $next['design'][$key] = $defaults['design'][$key] ?? '';
            }
        }
        return $this->store($current, $this->theme->resolveSettings($next), 'reset:' . $section);
    }

    /** @return string[] the keys of `design` that a section owns */
    public function sectionKeys(string $section): array
    {
        $keys = [];
        foreach (array_keys($this->theme->settingsSchema()['design']['fields'] ?? []) as $key) {
            if ($this->inSection((string)$key, $section)) {
                $keys[] = (string)$key;
            }
        }
        return $keys;
    }

    // ---- inside ---------------------------------------------------------------------------------------------------

    private function inSection(string $key, string $section): bool
    {
        foreach (self::SECTIONS[$section]['patterns'] as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * The settings after an import: each tree is the file's, limited to the fields the theme declares and checked by their types.
     *
     * @param array<string, mixed> $current
     * @param array<string, array<string, mixed>> $incoming
     * @return array{0: array<string, mixed>, 1: string[]} the settings, and the keys that were skipped
     */
    private function merged(array $current, array $incoming): array
    {
        $schema = $this->theme->settingsSchema();
        $defaults = $this->theme->defaultSettings();
        $next = $current;
        $skipped = [];
        foreach (self::TREES as $tree) {
            if (!isset($schema[$tree])) {
                continue;
            }
            $values = $defaults[$tree] ?? [];
            foreach ($incoming[$tree] ?? [] as $key => $value) {
                if (isset($schema[$tree]['fields'][$key]) && !($schema[$tree]['fields'][$key]['hidden'] ?? false)) {
                    $values[$key] = $value;
                } else {
                    $skipped[] = $tree . '.' . $key;
                }
            }
            $next[$tree] = FieldSchema::resolve($schema[$tree]['fields'], $values);
        }
        // A file from before 0.1.143 carries per-role colour overrides; they become a site palette, as on the site they came from.
        $next = SitePalettes::followSource(SitePalettes::fold($next));
        return [$next, $skipped];
    }

    /** Keeps $before as the backup and stores $after; returns how many values differ. */
    private function store(array $before, array $after, string $reason): int
    {
        $changed = 0;
        $backup = [];
        foreach (self::TREES as $tree) {
            $backup[$tree] = $before[$tree] ?? [];
            foreach (array_unique(array_merge(array_keys($before[$tree] ?? []), array_keys($after[$tree] ?? []))) as $key) {
                if (!$this->same($before[$tree][$key] ?? null, $after[$tree][$key] ?? null)) {
                    $changed++;
                }
            }
        }
        if ($changed === 0) {
            return 0;
        }
        $this->meta?->setJson(self::BACKUP_KEY, ['at' => gmdate('c'), 'reason' => $reason, 'settings' => $backup]);
        ($this->save)($after);
        return $changed;
    }

    /** @return array<string, array<string, mixed>> */
    private function tree(array $settings): array
    {
        $tree = [];
        foreach (self::TREES as $name) {
            $tree[$name] = is_array($settings[$name] ?? null) ? $settings[$name] : [];
        }
        return $tree;
    }

    /**
     * The font files the settings point to that exist in custom/assets.
     *
     * @return array<string, string> path inside custom/assets => path on disk
     */
    private function referencedFiles(array $tree): array
    {
        $found = [];
        $walk = function (mixed $value) use (&$walk, &$found): void {
            if (is_array($value)) {
                array_map($walk, $value);
                return;
            }
            if (!is_string($value) || $value === '' || count($found) >= self::MAX_FILES) {
                return;
            }
            $relative = ltrim(preg_replace('#^/?_custom/#', '', $value) ?? $value, '/');
            if (!$this->safeFile($relative)) {
                return;
            }
            $path = $this->theme->customPath() . '/assets/' . $relative;
            if (is_file($path) && filesize($path) <= self::MAX_FILE) {
                $found[$relative] = $path;
            }
        };
        $walk($tree);
        return $found;
    }

    private function safeFile(string $relative): bool
    {
        return preg_match(self::FILE_PATTERN, $relative) === 1 && !str_contains($relative, '..') && !str_contains($relative, '//');
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
        $text = is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text;
    }
}
