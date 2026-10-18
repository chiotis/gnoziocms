<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * The active frontend theme plus the site's update-safe `custom/` layer.
 *
 * Lookup order is always custom/ first, then themes/<name>/, using the same relative paths
 * (templates/…, components/…, layouts/…). Theme files are replaced by updates; custom/ is not.
 * See docs/theming.md.
 */
final class Theme
{
    public const DEFAULT_NAME = 'default';
    /** The name, among the block types of a page, of the styles for blocks that share a row (assets/css/block-row.css). */
    public const ROW_STYLES = '_row';
    /** The name, among the block types of a page, of the styles of containers (assets/css/block-container.css). */
    public const CONTAINER_STYLES = '_container';

    private string $name;
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $settingsSchema = null;

    public function __construct(private string $basePath, string $name = self::DEFAULT_NAME)
    {
        $name = preg_replace('/[^a-z0-9_-]/i', '', $name) ?: self::DEFAULT_NAME;
        if (!is_dir($basePath . '/themes/' . $name)) {
            $name = self::DEFAULT_NAME;
        }
        $this->name = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->basePath . '/themes/' . $this->name;
    }

    public function customPath(): string
    {
        return $this->basePath . '/custom';
    }

    public function label(): string
    {
        return (string)($this->manifest()['label'] ?? $this->name);
    }

    public function version(): string
    {
        return (string)($this->manifest()['version'] ?? '');
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $this->manifest = [];
        $path = $this->path() . '/theme.yaml';
        if (is_file($path)) {
            try {
                $data = Yaml::parseFile($path);
                $this->manifest = is_array($data) ? $data : [];
            } catch (\Throwable) {
                $this->manifest = [];
            }
        }
        return $this->manifest;
    }

    /**
     * Settings sections declared by the theme, with normalized field definitions.
     *
     * @return array<string, array{key: string, label: string, description: string, columns: int, fields: array<string, array<string, mixed>>}>
     */
    public function settingsSchema(): array
    {
        if ($this->settingsSchema !== null) {
            return $this->settingsSchema;
        }
        $sections = [];
        $raw = $this->manifest()['settings'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $key => $section) {
            $key = (string)$key;
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $key) || !is_array($section)) {
                continue;
            }
            $sections[$key] = [
                'key' => $key,
                'label' => (string)($section['label'] ?? ucfirst(str_replace('_', ' ', $key))),
                'description' => (string)($section['description'] ?? ''),
                'columns' => max(1, min(5, (int)($section['columns'] ?? 2))),
                'fields' => FieldSchema::withIcons(FieldSchema::normalize(is_array($section['fields'] ?? null) ? $section['fields'] : []), $this->iconNames()),
            ];
        }
        return $this->settingsSchema = $sections;
    }

    /**
     * The choices of a select in the theme's settings (value => label), or [] when the theme has no such field.
     * The content editor uses this to offer the same choices per entry.
     *
     * @return array<string, string>
     */
    public function settingOptions(string $section, string $field): array
    {
        $options = $this->settingsSchema()[$section]['fields'][$field]['options'] ?? [];
        return is_array($options) ? $options : [];
    }

    /** @return array<string, array<string, mixed>> */
    public function defaultSettings(): array
    {
        $values = [];
        foreach ($this->settingsSchema() as $key => $section) {
            $values[$key] = FieldSchema::defaults($section['fields']);
        }
        return $values;
    }

    /**
     * Stored settings checked against the schema. Declared fields always hold a valid value;
     * keys the schema does not know are kept for templates that read them directly.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function resolveSettings(array $stored): array
    {
        foreach ($this->settingsSchema() as $key => $section) {
            $current = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $stored[$key] = FieldSchema::resolve($section['fields'], $current);
        }
        return $stored;
    }

    /**
     * @param array<string, mixed> $input submitted `theme[section][field]` values
     * @param array<string, mixed> $current resolved current settings
     * @return array<string, mixed>
     */
    public function settingsFromInput(array $input, array $current): array
    {
        foreach ($this->settingsSchema() as $key => $section) {
            $sectionInput = is_array($input[$key] ?? null) ? $input[$key] : [];
            $sectionCurrent = is_array($current[$key] ?? null) ? $current[$key] : [];
            $current[$key] = FieldSchema::fromInput($section['fields'], $sectionInput, $sectionCurrent);
        }
        return $current;
    }

    /**
     * Page templates editors can choose (manifest `page_templates`). "default" is always present and
     * means the normal hierarchy; other keys map to templates/<key>.twig and are listed only when that
     * template exists (theme or custom/).
     *
     * @return array<string, array{label: string, description: string}>
     */
    public function pageTemplates(): array
    {
        $templates = ['default' => ['label' => 'Standard', 'description' => '']];
        $raw = $this->manifest()['page_templates'] ?? [];
        $raw = is_array($raw) ? $raw : [];
        // A site lists its own templates in custom/page-templates.yaml (same shape as the manifest's list).
        $customFile = $this->customPath() . '/page-templates.yaml';
        if (is_file($customFile)) {
            try {
                $custom = Yaml::parseFile($customFile);
            } catch (\Throwable) {
                $custom = null;
            }
            if (is_array($custom)) {
                $raw = array_replace($raw, $custom);
            }
        }
        foreach ($raw as $key => $template) {
            $key = (string)$key;
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $key) || !is_array($template)) {
                continue;
            }
            if ($key !== 'default' && !$this->hasTemplate('templates/' . $key . '.twig')) {
                continue;
            }
            $templates[$key] = [
                'label' => (string)($template['label'] ?? ucfirst($key)),
                'description' => (string)($template['description'] ?? ''),
            ];
        }
        return $templates;
    }

    /** @return array<string, array{label: string, default: string}> */
    public function menuLocations(): array
    {
        $locations = [];
        $raw = $this->manifest()['menu_locations'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $key => $location) {
            $key = (string)$key;
            if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
                continue;
            }
            $location = is_array($location) ? $location : [];
            $locations[$key] = [
                'label' => (string)($location['label'] ?? ucfirst($key)),
                'default' => (string)($location['default'] ?? $key),
            ];
        }
        return $locations;
    }

    /** Twig search paths, highest priority first. @return string[] */
    public function templateRoots(): array
    {
        $roots = [];
        if (is_dir($this->customPath())) {
            $roots[] = $this->customPath();
        }
        $roots[] = $this->path();
        return $roots;
    }

    /** First candidate (relative path such as `templates/single.twig`) that exists, or null. */
    public function findTemplate(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = (string)$candidate;
            if ($this->hasTemplate($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The template a content type's page uses when neither the entry nor the type's single layout chooses one: the first of
     * single-<singular>, single, <type> (and page or post) that exists.
     */
    public function defaultSingleTemplate(string $type, string $singular): string
    {
        $candidates = [
            'templates/single-' . $singular . '.twig',
            'templates/single.twig',
            'templates/' . $type . '.twig',
        ];
        if ($type === 'pages') {
            $candidates[] = 'templates/page.twig';
        } elseif ($type === 'posts') {
            $candidates[] = 'templates/post.twig';
        }
        return $this->findTemplate($candidates) ?? 'templates/single.twig';
    }

    /**
     * Whether a template draws the standard title area (so the title style, image, excerpt and header choices of a single
     * layout apply to it) rather than a page of its own, such as the book page. Read from the template's source.
     */
    public function usesTitleArea(string $relative): bool
    {
        return str_contains($this->templateSource($relative), 'components/page-header.twig');
    }

    /** The source of a template of the site or the theme (the site's wins), or '' when there is none. */
    public function templateSource(string $relative): string
    {
        if (!self::isSafeRelativePath($relative)) {
            return '';
        }
        foreach ($this->templateRoots() as $root) {
            if (is_file($root . '/' . $relative)) {
                return (string)file_get_contents($root . '/' . $relative);
            }
        }
        return '';
    }

    public function hasTemplate(string $relative): bool
    {
        if (!self::isSafeRelativePath($relative) || !str_ends_with($relative, '.twig')) {
            return false;
        }
        foreach ($this->templateRoots() as $root) {
            if (is_file($root . '/' . $relative)) {
                return true;
            }
        }
        return false;
    }

    public function assetUrl(string $baseUrl, string $path): string
    {
        $file = self::assetFile($this->path() . '/assets', $path);
        $url = $baseUrl . '/_themes/' . $this->name . '/' . ltrim($path, '/');
        return $file !== null ? $url . '?v=' . self::fileVersion($file) : $url;
    }

    /**
     * One stylesheet URL for the block types on a page: each type's theme block.css followed by
     * the site's additive custom/blocks/<type>/block.css, served together by ThemeAssets.
     * Empty when none of the types has styles.
     *
     * @param string[] $types
     */
    public function blockStylesheetUrl(string $baseUrl, array $types): string
    {
        $files = self::blockStyleFiles($this->path(), $this->customPath(), $types);
        if ($files === []) {
            return '';
        }
        $stamp = '';
        foreach ($files as $file) {
            $stamp .= $file . ':' . self::fileVersion($file) . ';';
        }
        $names = array_values(array_unique(array_filter($types, static fn(string $type): bool => in_array($type, [self::ROW_STYLES, self::CONTAINER_STYLES], true) || (bool)preg_match('/^[a-z][a-z0-9-]*$/', $type))));
        return $baseUrl . '/_themes/' . $this->name . '/_blocks.css?b=' . implode(',', $names) . '&v=' . substr(sha1($stamp), 0, 10);
    }

    /** One script URL for the block types on a page that ship a block.js, or ''. @param string[] $types */
    public function blockScriptUrl(string $baseUrl, array $types): string
    {
        $files = self::blockAssetFiles($this->path(), $this->customPath(), $types, 'block.js');
        if ($files === []) {
            return '';
        }
        $stamp = '';
        $names = [];
        foreach ($files as $file) {
            $stamp .= $file . ':' . self::fileVersion($file) . ';';
            $names[basename(dirname($file))] = true;
        }
        return $baseUrl . '/_themes/' . $this->name . '/_blocks.js?b=' . implode(',', array_keys($names)) . '&v=' . substr(sha1($stamp), 0, 10);
    }

    /**
     * Existing block stylesheets for the given types, in order (theme file, then custom file).
     *
     * @param string[] $types
     * @return string[]
     */
    public static function blockStyleFiles(string $themePath, string $customPath, array $types): array
    {
        return self::blockAssetFiles($themePath, $customPath, $types, 'block.css');
    }

    /**
     * @param string[] $types
     * @return string[]
     */
    public static function blockAssetFiles(string $themePath, string $customPath, array $types, string $filename): array
    {
        $files = [];
        foreach (array_slice(array_values(array_unique($types)), 0, 40) as $type) {
            if (($type === self::ROW_STYLES || $type === self::CONTAINER_STYLES) && $filename === 'block.css') {
                // The styles of a row of blocks and of containers live with the theme's assets and ride along only where a page has one.
                $file = self::assetFile($themePath . '/assets', $type === self::ROW_STYLES ? 'css/block-row.css' : 'css/block-container.css');
                if ($file !== null) {
                    $files[] = $file;
                }
                continue;
            }
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9-]*$/', $type)) {
                continue;
            }
            foreach ([$themePath . '/blocks', $customPath . '/blocks'] as $root) {
                $file = self::assetFile($root, $type . '/' . $filename);
                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }
        return $files;
    }

    /** Block definition folders, theme first; custom/ may add new block types. @return array<string, string> */
    public function blockRoots(): array
    {
        return ['theme' => $this->path() . '/blocks', 'custom' => $this->customPath() . '/blocks'];
    }

    /** @return string[] icon names from the theme and custom/icons */
    public function iconNames(): array
    {
        $names = [];
        foreach ([$this->path() . '/icons', $this->customPath() . '/icons'] as $dir) {
            foreach (glob($dir . '/*.svg') ?: [] as $file) {
                $name = basename($file, '.svg');
                if (preg_match('/^[a-z0-9-]+$/', $name)) {
                    $names[$name] = true;
                }
            }
        }
        $names = array_keys($names);
        sort($names);
        return $names;
    }

    /** Inline SVG for an icon (custom/icons wins), decorative by default, or '' when unknown. */
    public function icon(string $name, string $class = ''): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            return '';
        }
        static $cache = [];
        $key = $this->name . ':' . $name;
        if (!array_key_exists($key, $cache)) {
            $svg = '';
            foreach ([$this->customPath() . '/icons', $this->path() . '/icons'] as $dir) {
                if (is_file($dir . '/' . $name . '.svg')) {
                    $svg = trim((string)file_get_contents($dir . '/' . $name . '.svg'));
                    break;
                }
            }
            $cache[$key] = str_starts_with($svg, '<svg') ? $svg : '';
        }
        if ($cache[$key] === '') {
            return '';
        }
        $attributes = ' aria-hidden="true" focusable="false"';
        if ($class !== '') {
            $attributes .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
        }
        return '<svg' . $attributes . substr($cache[$key], 4);
    }

    /** URL of a file in custom/assets, or '' when the site has no such file. */
    public function customAssetUrl(string $baseUrl, string $path): string
    {
        $file = self::assetFile($this->customPath() . '/assets', $path);
        if ($file === null) {
            return '';
        }
        return $baseUrl . '/_custom/' . ltrim($path, '/') . '?v=' . self::fileVersion($file);
    }

    /**
     * Frontend strings: the theme's default-language file, the site's overrides for it, then the
     * same pair for the requested language. A string the theme translates wins over a default-
     * language override, so overriding Greek never forces Greek onto the English site.
     *
     * @return array<string, string>
     */
    public function translations(string $lang, string $defaultLang): array
    {
        $strings = array_replace($this->themeTranslations($defaultLang), $this->customTranslations($defaultLang));
        if ($lang !== $defaultLang) {
            $strings = array_replace($strings, $this->themeTranslations($lang), $this->customTranslations($lang));
        }
        return $strings;
    }

    /** Strings a language inherits when the site has not overridden them. @return array<string, string> */
    public function inheritedTranslations(string $lang, string $defaultLang): array
    {
        $strings = $this->themeTranslations($defaultLang);
        if ($lang !== $defaultLang) {
            $strings = array_replace($strings, $this->customTranslations($defaultLang), $this->themeTranslations($lang));
        }
        return $strings;
    }

    /** @return array<string, string> */
    public function themeTranslations(string $lang): array
    {
        $lang = self::safeLang($lang);
        $path = $this->path() . '/lang/' . $lang . '.php';
        if ($lang === '' || !is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? self::stringMap($data) : [];
    }

    /** @return array<string, string> */
    public function customTranslations(string $lang): array
    {
        $path = $this->customTranslationPath($lang);
        if ($path === '' || !is_file($path)) {
            return [];
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? self::stringMap($data) : [];
    }

    /** @param array<string, string> $strings */
    public function writeCustomTranslations(string $lang, array $strings): bool
    {
        $path = $this->customTranslationPath($lang);
        if ($path === '') {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }
        if ($strings === []) {
            return !is_file($path) || @unlink($path);
        }
        ksort($strings);
        $payload = "# Site overrides for theme strings (Admin > Translations). Kept across updates.\n"
            . Yaml::dump($strings, 1, 2);
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $payload) === false) {
            return false;
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            return false;
        }
        return true;
    }

    public function customTranslationPath(string $lang): string
    {
        $lang = self::safeLang($lang);
        return $lang === '' ? '' : $this->customPath() . '/lang/' . $lang . '.yaml';
    }

    /** Resolves a requested asset inside $root, or null when it is missing or not allowed. */
    public static function assetFile(string $root, string $path): ?string
    {
        $path = ltrim($path, '/');
        if (!self::isSafeRelativePath($path) || ThemeAssets::contentType($path) === null) {
            return null;
        }
        $file = $root . '/' . $path;
        if (!is_file($file)) {
            return null;
        }
        $realRoot = realpath($root);
        $realFile = realpath($file);
        if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $realFile;
    }

    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return false;
            }
        }
        return true;
    }

    /** A short stamp that changes when a file does (its date and size), for the end of an asset address. */
    public static function fileVersion(string $file): string
    {
        // PHP remembers a file's date and size for the rest of the request; a file written in this request must be read afresh.
        clearstatcache(true, $file);
        return base_convert((string)((int)@filemtime($file)), 10, 36) . base_convert((string)((int)@filesize($file)), 10, 36);
    }

    private static function safeLang(string $lang): string
    {
        return preg_match('/^[a-z]{2,3}([_-][a-z0-9]{2,8})?$/i', $lang) ? $lang : '';
    }

    /** @return array<string, string> */
    private static function stringMap(array $data): array
    {
        $strings = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $strings[(string)$key] = (string)$value;
            }
        }
        return $strings;
    }
}
