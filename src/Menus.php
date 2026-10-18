<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Menus: the YAML files in content/menus (one per menu, labels in every language), how they are read, checked, and
 * written, which menu sits in which place of the theme, and which items are active for the page being shown.
 */
final class Menus
{
    /** @var array<string, array{title: string, items: array<int, array<string, mixed>>}> */
    private array $menusCache = [];

    /**
     * @param callable(): array<string, mixed> $settings the site settings, read each time (they can change during a request)
     * @param callable(string, ?string): string $translate a theme string for the language being shown
     * @param callable(): array<string, array<string, mixed>> $menuLocations the places the theme offers menus in
     */
    public function __construct(
        private string $contentDir,
        private $settings,
        private $translate,
        private $menuLocations
    ) {
    }

    /** Drops what was read, after the files changed. */
    public function forget(): void
    {
        $this->menusCache = [];
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ($this->settings)();
    }

    public function ensureDefaults(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $defaultLang = (string)($this->settings()['languages']['default'] ?? 'el');
        $mainPath = $this->path('main');
        $footerPath = $this->path('footer');

        if (!is_file($mainPath)) {
            $legacyHeader = $this->settings()['menu']['header'] ?? [];
            $mainItems = [];
            if (is_array($legacyHeader) && !empty($legacyHeader)) {
                foreach ($legacyHeader as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $url = trim((string)($row['url'] ?? ''));
                    $label = trim((string)($row['label'] ?? ''));
                    $labelKey = trim((string)($row['label_key'] ?? ''));
                    $class = trim((string)($row['class'] ?? ''));
                    $target = trim((string)($row['target'] ?? ''));
                    if ($url === '' && $label === '' && $labelKey === '') {
                        continue;
                    }
                    $item = ['url' => $url];
                    if ($label !== '') {
                        $item['label'] = $label;
                    }
                    if ($labelKey !== '') {
                        $item['label_key'] = $labelKey;
                    }
                    if ($class !== '') {
                        $item['class'] = $class;
                    }
                    if ($target !== '') {
                        $item['target'] = $target;
                    }
                    $mainItems[] = $item;
                }
            }
            if (empty($mainItems)) {
                $mainItems = [
                    ['label_key' => 'nav.main.home', 'url' => ''],
                    ['label_key' => 'nav.main.about', 'url' => 'about'],
                    [
                        'label_key' => 'nav.main.services',
                        'url' => 'services',
                        'children' => [
                            ['label_key' => 'nav.main.services.workplace_strategy', 'url' => 'workplace-strategy'],
                            ['label_key' => 'nav.main.services.design_build', 'url' => 'design-build'],
                            ['label_key' => 'nav.main.services.project_management', 'url' => 'project-management'],
                        ],
                    ],
                    ['label_key' => 'nav.main.projects', 'url' => 'projects'],
                    ['label_key' => 'nav.main.news', 'url' => 'category/news'],
                    ['label_key' => 'nav.main.contact', 'url' => 'contact', 'class' => 'nav-cta'],
                ];
            }
            $this->write('main', [
                'title' => 'Main Menu',
                'items' => $mainItems,
            ]);
        }

        if (!is_file($footerPath)) {
            $footerItems = [
                ['label_key' => 'nav.footer.about', 'url' => 'about'],
                ['label_key' => 'nav.footer.careers', 'url' => 'careers'],
                ['label_key' => 'nav.footer.faq', 'url' => 'faq'],
                ['label_key' => 'nav.footer.privacy', 'url' => 'privacy-policy'],
                ['label_key' => 'nav.footer.terms', 'url' => 'terms'],
                ['label_key' => 'nav.footer.cookies', 'url' => 'cookies'],
                ['label_key' => 'nav.footer.contact', 'url' => 'contact'],
            ];
            $this->write('footer', [
                'title' => 'Footer Menu',
                'items' => $footerItems,
            ]);
        }
    }

    private function dir(): string
    {
        return $this->contentDir . '/menus';
    }

    public function path(string $key): string
    {
        $key = Slug::plain($key);
        if ($key === '') {
            $key = 'menu';
        }
        return $this->dir() . '/' . $key . '.yaml';
    }

    /** @return string[] */
    public function keys(): array
    {
        $keys = ['main', 'footer'];
        foreach (glob($this->dir() . '/*.yaml') ?: [] as $path) {
            $filename = basename($path, '.yaml');
            if ($filename === '' || preg_match('/\.[a-z]{2}$/', $filename) === 1) {
                continue;
            }
            $key = Slug::plain($filename);
            if ($key === '' || in_array($key, $keys, true)) {
                continue;
            }
            $keys[] = $key;
        }
        sort($keys);
        return $keys;
    }

    /** @return array<int, array{key: string, title: string, updated: string, items: int, places: string[]}> */
    public function listForAdmin(): array
    {
        $places = $this->locations();
        $rows = [];
        foreach ($this->keys() as $key) {
            $path = $this->path($key);
            $menu = $this->load($key);
            $mtime = is_file($path) ? (int)filemtime($path) : 0;
            $rows[] = [
                'key' => $key,
                'title' => (string)($menu['title'] ?? Slug::title($key)),
                'updated' => $mtime > 0 ? date('Y-m-d H:i', $mtime) : '',
                'items' => count($this->flatten($menu['items'] ?? [], [])),
                'places' => array_values(array_map(static fn(array $p): string => $p['label'], array_filter($places, static fn(array $p): bool => $p['menu'] === $key))),
            ];
        }
        return $rows;
    }

    /** @return array{title: string, items: array<int, array<string, mixed>>} */
    public function load(string $key): array
    {
        $cacheKey = $key . '|single';
        if (isset($this->menusCache[$cacheKey])) {
            return $this->menusCache[$cacheKey];
        }

        $menu = [
            'title' => Slug::title($key),
            'items' => [],
        ];

        $path = $this->path($key);
        if (is_file($path)) {
            $data = Yaml::parseFile($path);
            if (is_array($data)) {
                $title = trim((string)($data['title'] ?? ''));
                if ($title !== '') {
                    $menu['title'] = $title;
                }
                $menu['items'] = $this->normalize($data['items'] ?? []);
            }
        }

        $this->menusCache[$cacheKey] = $menu;
        return $menu;
    }

    public function write(string $key, array $menu): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $this->path($key);
        $title = trim((string)($menu['title'] ?? ''));
        if ($title === '') {
            $title = Slug::title($key);
        }
        $payload = [
            'title' => $title,
            'items' => $this->lean($this->normalize($menu['items'] ?? [])),
        ];
        file_put_contents($path, Yaml::dump($payload, 4, 2));
        $this->menusCache = [];
    }

    /**
     * The items as the file keeps them: only what is set (an item with no class has no `class:` line).
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function lean(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $row = [];
            foreach (['label', 'label_key'] as $field) {
                if (($item[$field] ?? '') !== '') {
                    $row[$field] = $item[$field];
                }
            }
            $labels = array_filter(is_array($item['labels'] ?? null) ? $item['labels'] : [], static fn($text): bool => $text !== '');
            if ($labels !== []) {
                $row['labels'] = $labels;
            }
            $row['url'] = (string)($item['url'] ?? '');
            foreach (['class', 'target'] as $field) {
                if (($item[$field] ?? '') !== '') {
                    $row[$field] = $item[$field];
                }
            }
            if (!empty($item['hidden'])) {
                $row['hidden'] = true;
            }
            if (!empty($item['children']) && is_array($item['children'])) {
                $row['children'] = $this->lean($item['children']);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function normalize(mixed $items, int $depth = 1): array
    {
        if (!is_array($items)) {
            return [];
        }
        $depth = max(1, min(3, $depth));
        $defaultLang = (string)($this->settings()['languages']['default'] ?? 'el');
        $languages = $this->settings()['languages']['available'] ?? [$defaultLang];
        $rows = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $labelKey = trim((string)($row['label_key'] ?? ''));
            $sourceLabels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
            $labels = [];
            foreach ($languages as $language) {
                $language = (string)$language;
                $labels[$language] = trim((string)($sourceLabels[$language] ?? ''));
            }
            if ($label !== '' && ($labels[$defaultLang] ?? '') === '') {
                $labels[$defaultLang] = $label;
            }
            $url = trim((string)($row['url'] ?? ''));
            $class = trim((string)($row['class'] ?? ''));
            $target = trim((string)($row['target'] ?? ''));
            $children = [];
            if ($depth < 3) {
                $children = $this->normalize($row['children'] ?? [], $depth + 1);
            }
            $hasLabels = false;
            foreach ($labels as $value) {
                if ($value !== '') {
                    $hasLabels = true;
                    break;
                }
            }
            if ($label === '' && $labelKey === '' && !$hasLabels && $url === '' && empty($children)) {
                continue;
            }
            $item = [
                'label' => $label,
                'label_key' => $labelKey,
                'labels' => $labels,
                'url' => $url,
                'class' => $class,
                'target' => $target,
            ];
            if (Format::isTruthy($row['hidden'] ?? false)) {
                $item['hidden'] = true;
            }
            if (!empty($children)) {
                $item['children'] = $children;
            }
            $rows[] = $item;
        }
        return $rows;
    }

    /** @return array<int, array{depth: string, label_key: string, labels: array<string, string>, url: string, class: string, target: string, hidden: bool}> */
    public function flatten(mixed $items, array $languages, int $depth = 1): array
    {
        $depth = max(1, min(3, $depth));
        $rows = [];
        foreach ($this->normalize($items, $depth) as $item) {
            $rowLabels = [];
            $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
            foreach ($languages as $language) {
                $language = (string)$language;
                $rowLabels[$language] = trim((string)($labels[$language] ?? ''));
            }
            $rows[] = [
                'depth' => (string)$depth,
                'label_key' => trim((string)($item['label_key'] ?? '')),
                'labels' => $rowLabels,
                'url' => trim((string)($item['url'] ?? '')),
                'class' => trim((string)($item['class'] ?? '')),
                'target' => trim((string)($item['target'] ?? '')),
                'hidden' => !empty($item['hidden']),
            ];
            if ($depth < 3 && isset($item['children']) && is_array($item['children'])) {
                $rows = array_merge($rows, $this->flatten($item['children'], $languages, $depth + 1));
            }
        }
        return $rows;
    }

    /**
     * The items of a menu from the rows the editor sends: a flat list in the order shown, each row with its depth (1 to 3).
     * A row that claims to be deeper than the row above allows is brought up to the level it can have, and an empty row is
     * skipped.
     *
     * @param array<int, mixed> $rows each: depth, label_key, labels (by language), url, class, target, hidden
     * @param string[] $languages
     * @return array<int, array<string, mixed>>
     */
    public function fromRows(mixed $rows, array $languages): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $roots = [];
        $stack = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceLabels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
            $labels = [];
            $hasLabels = false;
            foreach ($languages as $language) {
                $language = (string)$language;
                $value = trim((string)($sourceLabels[$language] ?? ''));
                $labels[$language] = $value;
                $hasLabels = $hasLabels || $value !== '';
            }
            $labelKey = trim((string)($row['label_key'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            if ($labelKey === '' && !$hasLabels && $url === '') {
                continue;
            }

            $depth = max(1, min(3, (int)($row['depth'] ?? 1)));
            while ($depth > 1 && !isset($stack[$depth - 1])) {
                $depth--;
            }

            $item = [
                'label_key' => $labelKey,
                'labels' => $labels,
                'url' => $url,
                'class' => trim((string)($row['class'] ?? '')),
                'target' => trim((string)($row['target'] ?? '')),
                'hidden' => Format::isTruthy($row['hidden'] ?? false),
            ];

            if ($depth === 1) {
                $roots[] = $item;
                $stack = [1 => count($roots) - 1];
                continue;
            }

            $parent = &$this->nodeByStack($roots, $stack, $depth - 1);
            if (!isset($parent['children']) || !is_array($parent['children'])) {
                $parent['children'] = [];
            }
            $parent['children'][] = $item;
            $stack[$depth] = count($parent['children']) - 1;
            for ($d = $depth + 1; $d <= 3; $d++) {
                unset($stack[$d]);
            }
            unset($parent);
        }

        return $this->normalize($roots);
    }

    /** @param array<int, int> $stack */
    private function &nodeByStack(array &$roots, array $stack, int $depth): array
    {
        $ref = &$roots[(int)$stack[1]];
        for ($d = 2; $d <= $depth; $d++) {
            if (!isset($ref['children']) || !is_array($ref['children'])) {
                $ref['children'] = [];
            }
            $ref = &$ref['children'][(int)$stack[$d]];
        }
        return $ref;
    }

    /**
     * The places the site shows a menu in: the theme's, header and footer, and any the settings add. Each says which menu
     * is shown there now and which one the theme starts with.
     *
     * @return array<int, array{key: string, label: string, default: string, menu: string}>
     */
    public function locations(): array
    {
        $assigned = $this->settings()['menu_locations'] ?? [];
        $assigned = is_array($assigned) ? $assigned : [];
        $places = [];
        $defined = array_replace(['header' => ['label' => 'Header', 'default' => 'main'], 'footer' => ['label' => 'Footer', 'default' => 'footer']], ($this->menuLocations)());
        foreach ($defined as $key => $definition) {
            $key = Slug::plain((string)$key);
            if ($key === '') {
                continue;
            }
            $default = Slug::plain((string)($definition['default'] ?? ''));
            $places[$key] = ['key' => $key, 'label' => (string)($definition['label'] ?? Slug::title($key)), 'default' => $default, 'menu' => $default];
        }
        foreach ($assigned as $key => $menu) {
            $key = Slug::plain((string)$key);
            $menu = Slug::plain((string)$menu);
            if ($key === '' || $menu === '') {
                continue;
            }
            $places[$key] ??= ['key' => $key, 'label' => Slug::title($key), 'default' => '', 'menu' => ''];
            $places[$key]['menu'] = $menu;
        }
        return array_values($places);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function forTheme(string $lang, string $currentPath): array
    {
        $locations = [];
        foreach ($this->locations() as $place) {
            $locations[$place['key']] = $place['menu'];
        }

        $resolved = [];
        foreach ($locations as $location => $key) {
            $location = Slug::plain((string)$location);
            $key = Slug::plain((string)$key);
            if ($location === '' || $key === '') {
                continue;
            }
            $menu = $this->load($key);
            $activeItems = $this->applyActiveState($menu['items'] ?? [], $this->normalizePath($currentPath));
            $resolved[$location] = $this->localize($activeItems, $lang);
        }

        if (empty($resolved['header'] ?? [])) {
            $legacyHeader = $this->settings()['menu']['header'] ?? [];
            if (is_array($legacyHeader)) {
                $activeItems = $this->applyActiveState($this->normalize($legacyHeader), $this->normalizePath($currentPath));
                $resolved['header'] = $this->localize($activeItems, $lang);
            }
        }

        return $resolved;
    }

    /** @param array<int, array<string, mixed>> $items
     *  @return array<int, array<string, mixed>>
     */
    private function localize(array $items, string $lang): array
    {
        $defaultLang = (string)($this->settings()['languages']['default'] ?? 'el');
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item) || !empty($item['hidden'])) {
                continue;
            }
            $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
            $label = trim((string)($labels[$lang] ?? ''));
            if ($label === '') {
                $labelKey = trim((string)($item['label_key'] ?? ''));
                if ($labelKey !== '') {
                    $label = ($this->translate)($labelKey, $labelKey);
                }
            }
            if ($label === '' && $defaultLang !== $lang) {
                $label = trim((string)($labels[$defaultLang] ?? ''));
            }
            if ($label === '') {
                $label = trim((string)($item['label'] ?? ''));
            }
            $item['label'] = $label;
            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->localize($item['children'], $lang);
            }
            $rows[] = $item;
        }
        return $rows;
    }

    /** @param array<int, array<string, mixed>> $items
     *  @return array<int, array<string, mixed>>
     */
    private function applyActiveState(array $items, string $currentPath): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $children = [];
            $hasChildActive = false;
            if (isset($item['children']) && is_array($item['children'])) {
                $children = $this->applyActiveState($item['children'], $currentPath);
                foreach ($children as $child) {
                    if (!is_array($child)) {
                        continue;
                    }
                    if (($child['is_active'] ?? false) || ($child['is_trail'] ?? false)) {
                        $hasChildActive = true;
                        break;
                    }
                }
            }

            $isActive = $this->isUrlActive((string)($item['url'] ?? ''), $currentPath);
            $item['is_active'] = $isActive;
            $item['is_trail'] = $hasChildActive;
            if (!empty($children)) {
                $item['children'] = $children;
            } else {
                unset($item['children']);
            }
            $rows[] = $item;
        }
        return $rows;
    }

    private function isUrlActive(string $url, string $currentPath): bool
    {
        $targetPath = $this->targetPath($url);
        if ($targetPath === null) {
            return false;
        }
        if ($targetPath === '') {
            return $currentPath === '';
        }
        if ($currentPath === $targetPath) {
            return true;
        }
        return str_starts_with($currentPath, $targetPath . '/');
    }

    private function targetPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || $url === '/') {
            return '';
        }

        if (str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $url) === 1) {
            $targetHost = (string)(parse_url($url, PHP_URL_HOST) ?? '');
            $baseHost = (string)(parse_url((string)($this->settings()['base_url'] ?? ''), PHP_URL_HOST) ?? '');
            if ($targetHost === '' || $baseHost === '' || strcasecmp($targetHost, $baseHost) !== 0) {
                return null;
            }
            $url = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        }

        return $this->normalizePath($url);
    }

    private function normalizePath(string $path): string
    {
        $raw = trim($path);
        if ($raw === '') {
            return '';
        }

        $parsed = parse_url($raw);
        if (is_array($parsed) && array_key_exists('path', $parsed)) {
            $path = trim((string)$parsed['path']);
        } else {
            $path = $raw;
        }

        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $available = $this->settings()['languages']['available'] ?? [];
        if (isset($segments[0]) && in_array($segments[0], $available, true)) {
            array_shift($segments);
        }

        if (($segments[0] ?? '') === 'pages') {
            array_shift($segments);
        }

        $homeSlug = Slug::plain((string)($this->settings()['home_page'] ?? 'index'));
        $normalized = trim(implode('/', $segments), '/');
        if ($normalized === $homeSlug) {
            return '';
        }

        return $normalized;
    }

    /** Menu links follow a page whose address changed. Links are language-neutral ("about", "posts/my-post"). */
    public function relink(string $oldUrl, string $newUrl): void
    {
        $walk = function (array $items) use (&$walk, $oldUrl, $newUrl, &$changed): array {
            foreach ($items as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['url']) && trim((string)$item['url'], '/') === $oldUrl) {
                    $items[$i]['url'] = $newUrl;
                    $changed = true;
                }
                if (isset($item['children']) && is_array($item['children'])) {
                    $items[$i]['children'] = $walk($item['children']);
                }
            }
            return $items;
        };
        foreach ($this->keys() as $key) {
            $changed = false;
            $menu = $this->load($key);
            $menu['items'] = $walk($menu['items']);
            if ($changed) {
                $this->write($key, $menu);
            }
        }
    }
}
