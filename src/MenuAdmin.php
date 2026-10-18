<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Menus screens do on top of `Menus`: the list of menus, creating one (empty or as a copy of another), and
 * editing or deleting one. The class never reads the request: the caller passes the address's query and the
 * submitted form, and gets back a location to go to or the data of the page.
 */
final class MenuAdmin
{
    /** The most items one menu keeps; the editor sends them all in one field, so a runaway request is cut off. */
    public const MAX_ITEMS = 300;
    private const MAX_JSON = 600000;

    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     * @param ?MenuSources $sources what the editor offers to add (without it, only a typed link)
     * @param ?\Closure(string, string): string $themeText the theme's text for a label key in a language, '' when it has none
     * @param ?\Closure(string, string): bool $setLocation puts a menu in a place of the theme (location, menu key); null when the person may not
     */
    public function __construct(
        private Menus $menus,
        private \Closure $settings,
        private \Closure $log,
        private ?MenuSources $sources = null,
        private ?\Closure $themeText = null,
        private ?\Closure $setLocation = null
    ) {
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function overview(array $get): array
    {
        return [
            'admin_section' => 'menus',
            'rows' => $this->menus->listForAdmin(),
            'deleted' => isset($get['deleted']),
            'saved' => isset($get['saved']),
            'created' => isset($get['created']),
        ];
    }

    /**
     * The page that makes a menu, or making it from the submitted form.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{location: string, view: array<string, mixed>}
     */
    public function create(array $get, array $post, bool $isPost): array
    {
        $menuKeys = $this->menus->keys();
        $newKey = Slug::plain((string)($get['new_key'] ?? ''));
        $sourceKey = Slug::plain((string)($get['source_key'] ?? ''));
        if ($newKey === '' && $sourceKey !== '') {
            $newKey = $sourceKey;
        }
        $newTitle = trim((string)($get['new_title'] ?? ''));

        $error = '';
        if ($isPost) {
            $newKey = Slug::plain((string)($post['new_key'] ?? $newKey));
            $title = trim((string)($post['new_title'] ?? ''));
            $newTitle = $title;
            $sourceKey = Slug::plain((string)($post['source_key'] ?? $sourceKey));
            if ($newKey === '') {
                $error = 'Menu key is required.';
            } elseif (in_array($newKey, $menuKeys, true)) {
                $error = 'Menu key already exists.';
            } else {
                $items = [];
                if ($sourceKey !== '' && in_array($sourceKey, $menuKeys, true)) {
                    $source = $this->menus->load($sourceKey);
                    $items = $this->menus->normalize($source['items'] ?? []);
                    if ($title === '') {
                        $title = (string)($source['title'] ?? '');
                    }
                }
                if ($title === '') {
                    $title = Slug::title($newKey);
                }
                $this->menus->write($newKey, ['title' => $title, 'items' => $items]);
                $this->menus->forget();
                ($this->log)('menus.create', 'info', 'menu', $newKey, 'Menu created.', [
                    'title' => $title,
                    'source_key' => $sourceKey,
                ]);
                return ['location' => '/admin/menus-edit?key=' . urlencode($newKey) . '&created=1', 'view' => []];
            }
        }

        return ['location' => '', 'view' => [
            'admin_section' => 'menus',
            'error' => $error,
            'new_key' => $newKey,
            'new_title' => $newTitle,
            'source_key' => $sourceKey,
            'menu_keys' => $menuKeys,
        ]];
    }

    /**
     * The editor of one menu, saving it, or deleting it.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{location: string, view: array<string, mixed>}
     */
    public function edit(array $get, array $post, bool $isPost): array
    {
        $settings = ($this->settings)();
        $languages = $settings['languages']['available'] ?? [(string)($settings['languages']['default'] ?? 'el')];

        $menuKeys = $this->menus->keys();
        $key = Slug::plain((string)($get['key'] ?? $post['key'] ?? ''));
        if ($key === '') {
            return ['location' => $menuKeys !== [] ? '/admin/menus-edit?key=' . urlencode((string)$menuKeys[0]) : '/admin/menus-new', 'view' => []];
        }
        if (!in_array($key, $menuKeys, true)) {
            return ['location' => '/admin/menus', 'view' => []];
        }

        $error = '';
        if ($isPost) {
            if ((string)($post['menu_action'] ?? 'save') === 'delete') {
                $path = $this->menus->path($key);
                if (is_file($path)) {
                    unlink($path);
                    $this->menus->forget();
                    ($this->log)('menus.delete', 'warning', 'menu', $key, 'Menu deleted.', []);
                }
                return ['location' => '/admin/menus?deleted=1', 'view' => []];
            }
            $title = trim((string)($post['menu_title'] ?? ''));
            if ($title === '') {
                $title = Slug::title($key);
            }
            $rows = $this->decodeRows((string)($post['menu_json'] ?? ''));
            if ($rows === null) {
                $error = 'The menu could not be read, so nothing was saved. Reload the page and try again.';
            } else {
                $items = $this->menus->fromRows($rows, array_map('strval', $languages));
                $this->menus->write($key, ['title' => $title, 'items' => $items]);
                $this->menus->forget();
                $placed = $this->saveLocations($key, $post);
                ($this->log)('menus.update', 'info', 'menu', $key, 'Menu updated.', [
                    'title' => $title,
                    'items' => count($this->menus->flatten($items, $languages)),
                    'locations' => $placed,
                ]);
                return ['location' => '/admin/menus-edit?key=' . urlencode($key) . '&saved=1', 'view' => []];
            }
        }

        $menu = $this->menus->load($key);
        $title = (string)($menu['title'] ?? Slug::title($key));
        if ($isPost && $error !== '') {
            $title = trim((string)($post['menu_title'] ?? '')) ?: $title;
        }

        return ['location' => '', 'view' => [
            'admin_section' => 'menus',
            'menu_key' => $key,
            'menu_title' => $title,
            'languages' => $languages,
            'editor_json' => $this->editorJson($key, $title, $menu['items'] ?? [], $languages),
            'saved' => isset($get['saved']),
            'created' => isset($get['created']),
            'deleted' => isset($get['deleted']),
            'error' => $error,
        ]];
    }

    /** The rows the editor sent, or null when they are not a list of rows (or are far more than a menu holds). */
    private function decodeRows(string $json): ?array
    {
        if ($json === '' || strlen($json) > self::MAX_JSON) {
            return null;
        }
        $rows = json_decode($json, true);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ITEMS) {
            return null;
        }
        return $rows;
    }

    /**
     * Puts the menu in the places that were ticked, and gives back the places it was in to the theme's own menu when they
     * were unticked. Nothing happens when the person may not change settings, or the form had no places in it.
     *
     * @param array<string, mixed> $post
     * @return string[] the places the menu is in afterwards
     */
    private function saveLocations(string $key, array $post): array
    {
        $ticked = array_map('strval', is_array($post['menu_locations'] ?? null) ? $post['menu_locations'] : []);
        $places = $this->menus->locations();
        if ($this->setLocation === null || !isset($post['menu_locations_present'])) {
            return array_values(array_map(static fn(array $p): string => $p['key'], array_filter($places, static fn(array $p): bool => $p['menu'] === $key)));
        }
        $in = [];
        foreach ($places as $place) {
            $mine = $place['menu'] === $key;
            $wanted = in_array($place['key'], $ticked, true);
            if ($wanted && !$mine) {
                ($this->setLocation)($place['key'], $key);
                $mine = true;
            } elseif (!$wanted && $mine && $place['default'] !== '' && $place['default'] !== $key) {
                ($this->setLocation)($place['key'], $place['default']);
                $mine = false;
            }
            if ($mine) {
                $in[] = $place['key'];
            }
        }
        return $in;
    }

    /**
     * Everything the editor starts with, as JSON for the page: the items (one row each, with their depth, and the theme's
     * text where a label is left to the theme), the languages, what can be added, and the places of the theme.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string[] $languages
     */
    private function editorJson(string $key, string $title, array $items, array $languages): string
    {
        $languages = array_map('strval', $languages);
        $settings = ($this->settings)();
        $defaultLang = (string)($settings['languages']['default'] ?? $languages[0] ?? 'en');
        $rows = [];
        foreach ($this->menus->flatten($items, $languages) as $row) {
            $row['depth'] = (int)$row['depth'];
            $hints = [];
            foreach ($languages as $language) {
                $hints[$language] = $row['label_key'] !== '' && $this->themeText !== null ? trim(($this->themeText)($row['label_key'], $language)) : '';
                if ($hints[$language] === $row['label_key']) {
                    $hints[$language] = '';
                }
            }
            $row['hints'] = $hints;
            $rows[] = $row;
        }
        $places = [];
        foreach ($this->menus->locations() as $place) {
            $places[] = [
                'key' => $place['key'],
                'label' => $place['label'],
                'menu' => $place['menu'],
                'locked' => $place['menu'] === $key && ($place['default'] === $key || $place['default'] === ''),
            ];
        }
        $payload = [
            'key' => $key,
            'title' => $title,
            'default_language' => $defaultLang,
            'languages' => array_map(static fn(string $code): array => ['code' => $code, 'name' => self::languageName($code)], $languages),
            'items' => $rows,
            'groups' => $this->sources?->groups() ?? [],
            'locations' => $places,
            'can_place' => $this->setLocation !== null,
            'max_items' => self::MAX_ITEMS,
        ];
        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** What a language is called in the language itself ("Ελληνικά", "English"); the code when the server cannot say. */
    public static function languageName(string $code): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($code, $code);
            if (is_string($name) && $name !== '' && $name !== $code) {
                return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
            }
        }
        return ['el' => 'Ελληνικά', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano'][$code] ?? strtoupper($code);
    }
}
