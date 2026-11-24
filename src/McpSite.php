<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * The site itself through MCP, beyond its entries: the site's name and tagline, its SEO settings, its redirects, its menus, the
 * Theme settings and its forms. Only for connections given site:read (reading) or site:write (changing), which are off until a
 * person ticks them, and only as far as the connection's account may do the same in the admin. Every change goes through the
 * same checks as the admin's screen, and what an assistant could use against the site's visitors stays with a person: redirects
 * to other websites, where a form's emails go and where it sends people after sending it, and the codes that prove who owns
 * the site.
 */
final class McpSite
{
    /** The kinds of Theme setting a connection may change; the rest (lists, links, galleries) are changed in Admin > Theme. */
    public const THEME_TYPES = ['text', 'textarea', 'markdown', 'toggle', 'number', 'decimal', 'select', 'icon', 'color', 'tone', 'image', 'url'];

    /** The parts of the SEO settings a connection may change, by the tab of Admin > SEO that keeps them. */
    public const SEO_KEYS = [
        'separator' => 'search', 'title_format' => 'search', 'home_title' => 'search', 'home_description' => 'search', 'default_description' => 'search',
        'noindex_search' => 'search', 'noindex_taxonomies' => 'search', 'types' => 'search',
        'share_image' => 'social', 'twitter_card' => 'social', 'twitter_site' => 'social', 'facebook_app_id' => 'social', 'preferred_source' => 'social',
        'discourage' => 'crawling', 'block_ai' => 'crawling', 'snippets' => 'crawling', 'robots_disallow' => 'crawling', 'sitemap' => 'crawling', 'feed' => 'crawling',
        'identity' => 'identity', 'schema' => 'identity',
    ];

    public const MAX_REDIRECTS = 500;

    /**
     * @param \Closure(): array<string, mixed> $settings the site settings as they are now
     * @param \Closure(): array<string, mixed> $themeSettings the Theme settings as they are now
     * @param \Closure(array<string, mixed>): array{ok: bool, message?: string} $saveTheme saves Theme settings as Admin > Theme does
     * @param \Closure(array<string, mixed>, string): bool $can whether the connection's account has a permission now
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log as App::logActivity
     * @param \Closure(): void $changed what visitors see changed: the settings are read again and the page cache is cleared
     * @param string[] $languages
     */
    public function __construct(
        private ContentRepository $content,
        private ContentEditor $editor,
        private Menus $menus,
        private Theme $theme,
        private SiteSettings $site,
        private RedirectAdmin $redirectAdmin,
        private RedirectRepository $redirects,
        private McpContent $writer,
        private \Closure $settings,
        private \Closure $themeSettings,
        private \Closure $saveTheme,
        private \Closure $can,
        private \Closure $log,
        private \Closure $changed,
        private string $defaultLang,
        private array $languages,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $read = [McpOAuth::SCOPE_SITE_READ, McpOAuth::SCOPE_SITE_WRITE];
        $write = [McpOAuth::SCOPE_SITE_WRITE];
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
        $rw = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $str = static fn(string $description, ?int $max = null): array => ['type' => 'string', 'description' => $description] + ($max !== null ? ['maxLength' => $max] : []);
        $schema = static fn(array $properties, array $required): array => ['type' => 'object', 'properties' => $properties ?: new \stdClass(), 'required' => $required, 'additionalProperties' => false];
        $none = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $tool = static fn(array $scopes, string $permission, string $title, string $description, array $input, array $annotations): array => [
            'scopes' => $scopes, 'permission' => $permission, 'site' => true, 'title' => $title, 'description' => $description, 'inputSchema' => $input, 'annotations' => $annotations,
        ];
        $item = [
            'type' => 'object',
            'description' => 'A menu item: labels by language ({"el": "...", "en": "..."}), url (an address of the site such as /about, or a whole address), and optionally '
                . 'target ("_blank"), class, hidden (true), languages and roles it is limited to, icon (a name of the theme\'s icons), badges by language, rel, and children (items under it; three levels at most).',
        ];
        return [
            'get_site_settings' => $tool($read, 'settings.manage', 'Read the site\'s name', 'The site\'s name (title) and tagline, its address, languages and time zone.', $none, $ro),
            'update_site_settings' => $tool($write, 'settings.manage', 'Change the site\'s name', 'Changes the site\'s name (title) and/or tagline. What is left out stays as it is.', $schema([
                'title' => $str('The site\'s name, shown in the header, the browser tab and search results.', 120),
                'tagline' => $str('A short line about the site; "" for none.', 200),
            ], []), $rw),
            'get_seo_settings' => $tool($read, 'seo.manage', 'Read the SEO settings', 'The site-wide SEO settings of Admin > SEO: how titles are made, the home page\'s title and description, '
                . 'the share picture, what crawlers may read, the sitemap and feed, and who the site is for structured data. update_seo_settings takes the same shape.', $none, $ro),
            'update_seo_settings' => $tool($write, 'seo.manage', 'Change the SEO settings', 'Changes the parts of the SEO settings given, in the shape get_seo_settings gives them '
                . '(an object such as {"home_title": "...", "identity": {"name": "..."}}); an object inside changes only the keys given, a list is replaced whole. '
                . 'Every value is checked as Admin > SEO checks it, and a value it would not keep is named. The codes that prove who owns the site are changed in the admin only.', $schema([
                'settings' => ['type' => 'object', 'description' => 'The parts to change: ' . implode(', ', array_keys(self::SEO_KEYS)) . '.'],
            ], ['settings']), $rw),
            'list_redirects' => $tool($read, 'redirects.manage', 'List redirects', 'The site\'s redirects (from an old address to another), newest first: id, source, target, code, enabled, hits, where it came from.', $schema([
                'query' => $str('Words in the source or target.', 200),
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_REDIRECTS, 'default' => 100],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ], []), $ro),
            'save_redirect' => $tool($write, 'redirects.manage', 'Add or change a redirect', 'Adds a redirect, or changes the one with id. The target must be an address of this site '
                . '(a redirect to another website is made by a person in Admin > Redirects). A source that is a page of the site, or that would make a loop, is refused.', $schema([
                'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The redirect to change (list_redirects gives ids); leave it out to add one.'],
                'source' => $str('The old address, such as /old-page.', 500),
                'target' => $str('Where visitors go, such as /new-page.', 500),
                'code' => ['type' => 'integer', 'enum' => [301, 302, 307, 308, 410], 'default' => 301, 'description' => '301: moved for good (the usual); 302 or 307: for now; 410: gone, with no target.'],
                'enabled' => ['type' => 'boolean', 'description' => 'Changing one only: false turns it off without removing it.'],
                'note' => $str('Why it is there.', 300),
            ], ['source']), $rw),
            'delete_redirect' => $tool($write, 'redirects.manage', 'Remove a redirect', 'Removes a redirect by its id: visitors of its source find nothing again.', $schema([
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['id']), ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]),
            'list_menus' => $tool($read, 'menus.manage', 'List menus', 'The site\'s menus (key, title, how many items) and the places of the theme where each is shown.', $none, $ro),
            'get_menu' => $tool($read, 'menus.manage', 'Read a menu', 'One menu with its items as a tree. save_menu takes the same items back.', $schema([
                'menu' => $str('Its key (list_menus).'),
            ], ['menu']), $ro),
            'save_menu' => $tool($write, 'menus.manage', 'Change or add a menu', 'Replaces a menu\'s items with the ones given, or adds a menu with a new key. Read it with get_menu first and send '
                . 'all of its items back with your change: an item left out is removed.', $schema([
                'menu' => $str('Its key: a known one to change it, a new one (letters, numbers and dashes) to add one.', 60),
                'title' => $str('Its name in the admin.', 120),
                'items' => ['type' => 'array', 'maxItems' => MenuAdmin::MAX_ITEMS, 'items' => $item],
                'places' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Places of the theme to show it in (list_menus gives them, such as "header"); left out: where it is now. Needs settings.manage.'],
            ], ['menu', 'items']), ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]),
            'get_theme_settings' => $tool($read, 'theme.manage', 'Read the Theme settings', 'The settings of Admin > Theme, by section: each field\'s kind, label, choices, '
                . 'value now, and whether it can be changed here (lists, links and galleries are changed in the admin).', $schema([
                'section' => $str('Only this section.'),
            ], []), $ro),
            'update_theme_settings' => $tool($write, 'theme.manage', 'Change the Theme settings', 'Changes the Theme settings given, by section and field: {"header": {"sticky": true}, "colors": {"accent": "#0055aa"}}. '
                . 'What is left out stays as it is. Every value is checked; nothing is saved while one is wrong.', $schema([
                'settings' => ['type' => 'object', 'description' => 'Section => field => value, as get_theme_settings names them.'],
            ], ['settings']), ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]),
            'list_forms' => $tool($read, 'forms.manage', 'List forms', 'The site\'s forms: slug, title, status, how many fields. What visitors sent is not given.', $none, $ro),
            'get_form' => $tool($read, 'forms.manage', 'Read a form', 'One form: its fields (save_form takes them back), button, messages and texts in other languages, and, to read only, where its emails go.', $schema([
                'form' => $str('Its slug (list_forms).'),
            ], ['form']), $ro),
            'save_form' => $tool($write, 'forms.manage', 'Change or add a form', 'Changes a form\'s fields and texts, or adds a form with a new slug (it sends no emails until a person sets them in the admin). '
                . 'Read it with get_form first: fields replaces the whole list. Keep a field\'s name: answers already sent are filed under it. '
                . 'Where its emails go and where it sends people are changed in the admin only.', $schema([
                'form' => $str('Its slug: a known one to change it, a new one to add one.', 80),
                'title' => $str('Its name.', 200),
                'fields' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object'], 'description' => 'Every field in order, in the shape get_form gives: type, name, label, required, placeholder, help, options ([{"value", "label"}]), width, translations ({"en": {"label": ...}}), and condition ({"field": "<name of an earlier field>", "op": "is|not|filled|empty", "value": "<a choice\'s value>"}) to show it only for that answer. A "step" part begins the next step of a form in several steps (its label is the step\'s title); a "rating" field has max stars (2 to 10, 5 when left out).'],
                'style' => ['type' => 'string', 'enum' => ['', 'compact', 'stacked'], 'description' => 'How the form is drawn: "" as the theme draws it, "compact" with less room, "stacked" one field to a row.'],
                'submit_label' => $str('The button\'s text.', 120),
                'success_message' => $str('What is shown once it is sent.', 1000),
                'translations' => ['type' => 'object', 'description' => 'The form\'s own texts in other languages, as get_form gives them: {"en": {"title": ..., "submit_label": ..., "success_message": ...}}.'],
                'status' => ['type' => 'string', 'enum' => ['published', 'draft'], 'description' => 'A new form is published unless draft is asked for.'],
            ], ['form']), ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]),
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    public function call(string $tool, array $args, array $connection): array
    {
        $known = array_keys((array)(self::definitions()[$tool]['inputSchema']['properties'] ?? []));
        $unknown = array_diff(array_map('strval', array_keys($args)), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException($tool . ' does not take ' . implode(', ', $unknown) . '. It takes: ' . (implode(', ', $known) ?: 'nothing') . '.');
        }
        return match ($tool) {
            'get_site_settings' => $this->siteSettings(),
            'update_site_settings' => $this->updateSiteSettings($args, $connection),
            'get_seo_settings' => $this->seo(),
            'update_seo_settings' => $this->updateSeo($args, $connection),
            'list_redirects' => $this->listRedirects($args),
            'save_redirect' => $this->saveRedirect($args, $connection),
            'delete_redirect' => $this->deleteRedirect($args, $connection),
            'list_menus' => $this->listMenus(),
            'get_menu' => $this->getMenu($args),
            'save_menu' => $this->saveMenu($args, $connection),
            'get_theme_settings' => $this->themeSettingsOut($args),
            'update_theme_settings' => $this->updateTheme($args, $connection),
            'list_forms' => $this->listForms(),
            'get_form' => $this->getForm($args),
            'save_form' => $this->saveForm($args, $connection),
        };
    }

    // ---- the site's name and SEO

    /** @return array<string, mixed> */
    private function siteSettings(): array
    {
        $site = ($this->settings)();
        return [
            'title' => (string)($site['title'] ?? ''),
            'tagline' => (string)($site['tagline'] ?? ''),
            'base_url' => (string)($site['base_url'] ?? ''),
            'languages' => $this->languages,
            'default_language' => $this->defaultLang,
            'timezone' => (string)($site['timezone'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function updateSiteSettings(array $args, array $connection): array
    {
        $title = array_key_exists('title', $args) ? trim((string)$args['title']) : null;
        $tagline = array_key_exists('tagline', $args) ? trim((string)$args['tagline']) : null;
        if ($title === null && $tagline === null) {
            throw new \InvalidArgumentException('Give title and/or tagline.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('title cannot be empty.');
        }
        $before = $this->siteSettings();
        if (!$this->site->setTitle($title, $tagline)) {
            throw new \RuntimeException('The site settings could not be written.');
        }
        $this->done('settings.update', 'settings', 'general', 'Site name changed.', $connection, ['from' => ['title' => $before['title'], 'tagline' => $before['tagline']], 'title' => $title, 'tagline' => $tagline]);
        return $this->siteSettings();
    }

    /** @return array<string, mixed> */
    private function seo(): array
    {
        $seo = SeoSettings::from(($this->settings)());
        return ['settings' => array_intersect_key($seo, self::SEO_KEYS), 'content_types' => $this->types(), 'choices' => [
            'separator' => SeoSettings::SEPARATORS,
            'title_format' => 'Words in braces are replaced: ' . implode(', ', array_map(static fn(string $k): string => '{' . $k . '}', array_keys(SeoSettings::VARIABLES))) . '; "" for the default, ' . SeoSettings::DEFAULT_FORMAT . '.',
            'twitter_card' => array_keys(SeoSettings::TWITTER_CARDS),
            'identity.type' => array_keys(SeoSettings::IDENTITY_TYPES),
            'schema.article_type' => array_keys(SeoSettings::ARTICLE_TYPES),
        ]];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function updateSeo(array $args, array $connection): array
    {
        $patch = $args['settings'] ?? null;
        if (!is_array($patch) || $patch === [] || array_is_list($patch)) {
            throw new \InvalidArgumentException('settings must be an object of the parts to change, such as {"home_title": "..."}.');
        }
        $problems = [];
        foreach (array_keys($patch) as $key) {
            if (!isset(self::SEO_KEYS[$key])) {
                $problems[] = $key === 'verification'
                    ? 'verification: the codes that prove who owns the site are changed in Admin > SEO only.'
                    : 'There is no SEO setting "' . $key . '" (there are: ' . implode(', ', array_keys(self::SEO_KEYS)) . ').';
            }
        }
        $patch = array_intersect_key($patch, self::SEO_KEYS);
        $site = ($this->settings)();
        $current = SeoSettings::from($site);
        $wanted = self::merge($current, $patch);
        $types = $this->types();
        $stored = is_array($site['seo'] ?? null) ? $site['seo'] : [];
        foreach (array_unique(array_intersect_key(self::SEO_KEYS, $patch)) as $tab) {
            $stored = SeoSettings::apply($stored, $tab, SeoSettings::toPost($wanted, $tab, $types), $types);
        }
        $after = SeoSettings::from(['seo' => $stored]);
        foreach (self::differences(array_intersect_key($wanted, $patch), array_intersect_key($after, $patch)) as $path => [$asked, $kept]) {
            $problems[] = $path . ': ' . json_encode($asked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' is not a value this setting takes'
                . ' (it would be ' . json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '). get_seo_settings lists the choices.';
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        if (!$this->site->setSeo($stored)) {
            throw new \RuntimeException('The SEO settings could not be written.');
        }
        $this->done('seo.update', 'settings', 'seo', 'SEO settings saved.', $connection, ['changed' => array_keys($patch)]);
        $warnings = [];
        if (($after['discourage'] ?? false) === true && ($current['discourage'] ?? false) !== true) {
            $warnings[] = 'Search engines are now asked not to list the site at all (discourage).';
        }
        return ['settings' => array_intersect_key($after, self::SEO_KEYS), 'warnings' => $warnings];
    }

    // ---- redirects

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listRedirects(array $args): array
    {
        $this->redirectsAvailable();
        $filters = trim((string)($args['query'] ?? '')) !== '' ? ['q' => trim((string)$args['query'])] : [];
        $limit = max(1, min(self::MAX_REDIRECTS, (int)($args['limit'] ?? 100)));
        $offset = max(0, (int)($args['offset'] ?? 0));
        return [
            'total' => $this->redirects->count($filters),
            'redirects' => array_map(self::redirectRow(...), $this->redirects->all($filters, $limit, $offset)),
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function saveRedirect(array $args, array $connection): array
    {
        $this->redirectsAvailable();
        $id = (int)($args['id'] ?? 0);
        $before = $id > 0 ? $this->redirects->find($id) : null;
        if ($id > 0 && $before === null) {
            throw new \InvalidArgumentException('There is no redirect ' . $id . '. list_redirects gives their ids.');
        }
        $code = (int)($args['code'] ?? ($before['status_code'] ?? 301));
        $target = trim((string)($args['target'] ?? ($before['target'] ?? '')));
        if ($code !== 410 && RedirectRepository::isExternal($target)) {
            throw new \InvalidArgumentException('target must be an address of this site, such as /new-page. A redirect to another website is made by a person in Admin > Redirects.');
        }
        $post = [
            'do' => $id > 0 ? 'update' : 'add',
            'id' => $id,
            'source' => (string)$args['source'],
            'target' => $code === 410 ? '' : $target,
            'code' => $code,
            'note' => array_key_exists('note', $args) ? trim((string)$args['note']) : (string)($before['note'] ?? ''),
            'enabled' => array_key_exists('enabled', $args) ? ($args['enabled'] === true ? '1' : '') : (($before['enabled'] ?? 1) ? '1' : ''),
        ];
        $result = $this->redirectAdmin->apply($post, self::actor($connection));
        if ($result['error'] !== '') {
            throw new \InvalidArgumentException($result['error']);
        }
        $saved = $id > 0 ? $this->redirects->find($id) : $this->redirects->findBySource(RedirectRepository::normalizePath((string)$args['source']), false);
        ($this->changed)();
        return ['redirect' => $saved !== null ? self::redirectRow($saved) : null, 'added' => $id === 0];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function deleteRedirect(array $args, array $connection): array
    {
        $this->redirectsAvailable();
        $row = $this->redirects->find((int)($args['id'] ?? 0));
        if ($row === null) {
            throw new \InvalidArgumentException('There is no redirect ' . (int)($args['id'] ?? 0) . '. list_redirects gives their ids.');
        }
        $this->redirectAdmin->apply(['do' => 'delete', 'id' => (int)$row['id']], self::actor($connection));
        ($this->changed)();
        return ['removed' => self::redirectRow($row)];
    }

    private function redirectsAvailable(): void
    {
        if (!$this->redirects->isAvailable()) {
            throw new \InvalidArgumentException('The redirects cannot be reached on this site (its system database is not available).');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function redirectRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'source' => (string)$row['source'],
            'target' => (string)$row['target'],
            'code' => (int)$row['status_code'],
            'enabled' => (bool)(int)$row['enabled'],
            'note' => (string)($row['note'] ?? ''),
            'origin' => (string)($row['origin'] ?? ''),
            'hits' => (int)($row['hits'] ?? 0),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    // ---- menus

    /** @return array<string, mixed> */
    private function listMenus(): array
    {
        $places = [];
        foreach ($this->menus->locations() as $place) {
            $places[] = ['place' => (string)$place['key'], 'label' => (string)$place['label'], 'menu' => (string)$place['menu']];
        }
        return [
            'menus' => array_map(static fn(array $row): array => ['menu' => $row['key'], 'title' => $row['title'], 'items' => $row['items'], 'places' => $row['places']], $this->menus->listForAdmin()),
            'places' => $places,
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function getMenu(array $args): array
    {
        $key = $this->menuKey($args['menu'] ?? '', true);
        $menu = $this->menus->load($key);
        return ['menu' => $key, 'title' => $menu['title'], 'items' => self::leanItems($menu['items']), 'places' => $this->placesOf($key)];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function saveMenu(array $args, array $connection): array
    {
        $given = Slug::plain((string)($args['menu'] ?? ''));
        if ($given === '' || $given !== (string)$args['menu']) {
            throw new \InvalidArgumentException('menu must be a key of small letters, numbers and dashes, such as "footer-links".');
        }
        $new = !in_array($given, $this->menus->keys(), true);
        $items = $args['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('items must be a list of menu items.');
        }
        $problems = [];
        $this->checkItems($items, 1, 'items', $problems);
        $count = self::countItems($items);
        if ($count > MenuAdmin::MAX_ITEMS) {
            $problems[] = 'A menu holds at most ' . MenuAdmin::MAX_ITEMS . ' items; this has ' . $count . '.';
        }
        $places = null;
        if (array_key_exists('places', $args)) {
            $places = array_values(array_unique(array_map('strval', is_array($args['places']) ? $args['places'] : [])));
            $known = array_column($this->menus->locations(), 'key');
            foreach (array_diff($places, $known) as $place) {
                $problems[] = 'places: the theme has no place "' . $place . '" (it has: ' . (implode(', ', $known) ?: 'none') . ').';
            }
            if (!($this->can)($connection, 'settings.manage')) {
                $problems[] = 'places: this connection\'s account may not change where menus are shown (settings.manage). Leave places out.';
            }
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $title = array_key_exists('title', $args) ? trim((string)$args['title']) : ($new ? '' : $this->menus->load($given)['title']);
        $this->menus->write($given, ['title' => $title, 'items' => $items]);
        $this->menus->forget();
        $placed = [];
        if ($places !== null) {
            foreach ($this->menus->locations() as $info) {
                $here = in_array((string)$info['key'], $places, true);
                if ($here && $info['menu'] !== $given) {
                    $this->site->setMenuLocation((string)$info['key'], $given);
                } elseif (!$here && $info['menu'] === $given && $info['default'] !== '' && $info['default'] !== $given) {
                    $this->site->setMenuLocation((string)$info['key'], (string)$info['default']);
                }
            }
            $this->menus->forget();
            $placed = $places;
        }
        $this->done($new ? 'menus.create' : 'menus.update', 'menu', $given, $new ? 'Menu added.' : 'Menu updated.', $connection, ['items' => $count, 'places' => $placed]);
        return $this->getMenu(['menu' => $given]) + ['added' => $new];
    }

    /** @param list<mixed> $items @param list<string> $problems */
    private function checkItems(array $items, int $depth, string $where, array &$problems): void
    {
        $allowed = ['labels', 'label', 'url', 'target', 'class', 'hidden', 'languages', 'roles', 'icon', 'badges', 'rel', 'children'];
        foreach ($items as $i => $item) {
            $at = $where . '[' . $i . ']';
            if (!is_array($item) || array_is_list($item)) {
                $problems[] = $at . ' must be an object.';
                continue;
            }
            foreach (array_diff(array_map('strval', array_keys($item)), $allowed) as $key) {
                $problems[] = $at . ': an item has no "' . $key . '" (it takes: ' . implode(', ', $allowed) . ').';
            }
            $labels = $item['labels'] ?? [];
            if (!is_array($labels) || ($labels !== [] && array_is_list($labels))) {
                $problems[] = $at . '.labels must be an object by language, such as {"' . $this->defaultLang . '": "..."}.';
            } else {
                foreach (array_keys($labels) as $lang) {
                    if (!in_array((string)$lang, $this->languages, true)) {
                        $problems[] = $at . '.labels: the site has no language "' . $lang . '" (it has: ' . implode(', ', $this->languages) . ').';
                    }
                }
                if (trim((string)($labels[$this->defaultLang] ?? $item['label'] ?? '')) === '') {
                    $problems[] = $at . ' needs a label in the default language, ' . $this->defaultLang . '.';
                }
            }
            $url = trim((string)($item['url'] ?? ''));
            if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m) === 1 && !in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) {
                $problems[] = $at . '.url: an address of the site (/about or about), https://, mailto: or tel:, not ' . $m[1] . ':.';
            }
            if (isset($item['target']) && !in_array((string)$item['target'], ['', '_blank', '_self'], true)) {
                $problems[] = $at . '.target must be "_blank" or "".';
            }
            $children = $item['children'] ?? [];
            if (!is_array($children) || ($children !== [] && !array_is_list($children))) {
                $problems[] = $at . '.children must be a list of items.';
            } elseif ($children !== []) {
                if ($depth >= 3) {
                    $problems[] = $at . ': a menu has three levels at most.';
                } else {
                    $this->checkItems($children, $depth + 1, $at . '.children', $problems);
                }
            }
        }
    }

    /** @param list<mixed> $items */
    private static function countItems(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            $count += 1 + (is_array($item) && is_array($item['children'] ?? null) ? self::countItems($item['children']) : 0);
        }
        return $count;
    }

    /** Items as get_menu gives them: only what is set. @param array<int, array<string, mixed>> $items @return list<array<string, mixed>> */
    private static function leanItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $row = ['labels' => array_filter((array)($item['labels'] ?? []), static fn($t): bool => $t !== ''), 'url' => (string)($item['url'] ?? '')];
            foreach (['target', 'class', 'icon'] as $key) {
                if (($item[$key] ?? '') !== '') {
                    $row[$key] = $item[$key];
                }
            }
            foreach (['hidden', 'languages', 'roles', 'badges', 'rel'] as $key) {
                if (!empty($item[$key])) {
                    $row[$key] = $item[$key];
                }
            }
            if (!empty($item['children'])) {
                $row['children'] = self::leanItems($item['children']);
            }
            $out[] = $row;
        }
        return $out;
    }

    private function menuKey(mixed $value, bool $mustExist): string
    {
        $key = Slug::plain((string)$value);
        if ($mustExist && !in_array($key, $this->menus->keys(), true)) {
            throw new \InvalidArgumentException('There is no menu "' . $value . '". The site has: ' . (implode(', ', $this->menus->keys()) ?: 'none') . '.');
        }
        return $key;
    }

    /** @return list<string> */
    private function placesOf(string $key): array
    {
        $out = [];
        foreach ($this->menus->locations() as $info) {
            if ($info['menu'] === $key) {
                $out[] = (string)$info['key'];
            }
        }
        return $out;
    }

    // ---- Theme settings

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function themeSettingsOut(array $args): array
    {
        $values = ($this->themeSettings)();
        $only = trim((string)($args['section'] ?? ''));
        $schema = $this->theme->settingsSchema();
        if ($only !== '' && !isset($schema[$only])) {
            throw new \InvalidArgumentException('The theme has no section "' . $only . '" (it has: ' . implode(', ', array_keys($schema)) . ').');
        }
        $sections = [];
        foreach ($schema as $key => $section) {
            if ($only !== '' && $key !== $only) {
                continue;
            }
            $fields = [];
            foreach ($section['fields'] as $name => $field) {
                if ($field['hidden'] ?? false) {
                    continue;
                }
                $out = array_intersect_key($field, array_flip(['type', 'label', 'help', 'options', 'min', 'max', 'step', 'translatable']));
                $out['value'] = $values[$key][$name] ?? null;
                $out['writable'] = in_array((string)$field['type'], self::THEME_TYPES, true);
                $fields[$name] = $out;
            }
            $sections[$key] = ['label' => $section['label'], 'description' => $section['description'], 'fields' => $fields];
        }
        return ['theme' => $this->theme->label(), 'sections' => $sections];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function updateTheme(array $args, array $connection): array
    {
        $given = $args['settings'] ?? null;
        if (!is_array($given) || $given === [] || array_is_list($given)) {
            throw new \InvalidArgumentException('settings must be an object by section, such as {"header": {"sticky": true}}.');
        }
        $schema = $this->theme->settingsSchema();
        $current = ($this->themeSettings)();
        $problems = [];
        $input = [];
        foreach ($schema as $key => $section) {
            // Every field is sent as it is now, so what is left out (a switch above all) stays as it is.
            $input[$key] = is_array($current[$key] ?? null) ? $current[$key] : [];
        }
        $changed = [];
        foreach ($given as $sectionKey => $fields) {
            $sectionKey = (string)$sectionKey;
            if (!isset($schema[$sectionKey])) {
                $problems[] = 'The theme has no section "' . $sectionKey . '" (it has: ' . implode(', ', array_keys($schema)) . ').';
                continue;
            }
            if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
                $problems[] = $sectionKey . ' must be an object of field => value.';
                continue;
            }
            foreach ($fields as $name => $value) {
                $name = (string)$name;
                $field = $schema[$sectionKey]['fields'][$name] ?? null;
                $at = $sectionKey . '.' . $name;
                if ($field === null || ($field['hidden'] ?? false)) {
                    $problems[] = $at . ': there is no such field (get_theme_settings lists them).';
                    continue;
                }
                if (!in_array((string)$field['type'], self::THEME_TYPES, true)) {
                    $problems[] = $at . ' is a ' . $field['type'] . ', which is changed in Admin > Theme.';
                    continue;
                }
                $problem = $this->themeValueProblem($field, $value);
                if ($problem !== null) {
                    $problems[] = $at . ': ' . $problem;
                    continue;
                }
                $input[$sectionKey][$name] = $value;
                $changed[] = $at;
            }
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $saved = ($this->saveTheme)($input);
        if (($saved['ok'] ?? false) !== true) {
            throw new \RuntimeException((string)($saved['message'] ?? 'The Theme settings could not be saved.'));
        }
        $this->done('theme.update', 'settings', $this->theme->name(), 'Theme settings updated.', $connection, ['changed' => $changed]);
        $after = ($this->themeSettings)();
        $values = [];
        foreach ($changed as $path) {
            [$s, $f] = explode('.', $path, 2);
            $values[$path] = $after[$s][$f] ?? null;
        }
        return ['changed' => $values];
    }

    /** Why a value does not fit a Theme setting, or null when it does. @param array<string, mixed> $field */
    private function themeValueProblem(array $field, mixed $value): ?string
    {
        $type = (string)$field['type'];
        if (($field['translatable'] ?? false) === true && is_array($value)) {
            foreach ($value as $lang => $text) {
                if (!in_array((string)$lang, $this->languages, true) || !is_scalar($text)) {
                    return 'by language, it takes text for ' . implode(', ', $this->languages) . '.';
                }
            }
            return null;
        }
        return match (true) {
            $type === 'toggle' => is_bool($value) ? null : 'it takes true or false.',
            in_array($type, ['select', 'icon'], true) => is_scalar($value) && (array_key_exists((string)$value, (array)$field['options']) || ($type === 'icon' && $field['options'] === [] && preg_match('/^[a-z0-9-]{1,40}$/', (string)$value) === 1))
                ? null : 'it takes one of: ' . implode(', ', array_map('strval', array_keys((array)$field['options']))) . '.',
            in_array($type, ['number', 'decimal'], true) => is_numeric($value)
                && (!is_numeric($field['min'] ?? null) || (float)$value >= (float)$field['min'])
                && (!is_numeric($field['max'] ?? null) || (float)$value <= (float)$field['max'])
                ? null : 'it takes a number' . (is_numeric($field['min'] ?? null) || is_numeric($field['max'] ?? null) ? ' from ' . ($field['min'] ?? '…') . ' to ' . ($field['max'] ?? '…') : '') . '.',
            in_array($type, ['color', 'tone'], true) => is_string($value) && ($value === '' || FieldSchema::normalizeHex($value) !== null || ($type === 'tone' && preg_match('/^[a-z0-9_-]{1,40}$/', $value) === 1))
                ? null : 'it takes a colour as #rrggbb, or "" for none.',
            $type === 'image' => is_string($value) && ($value === '' || $this->writer->mediaPath($value) !== null) ? null : 'it takes a picture of the media library (the path add_image_from_url gave), or "".',
            $type === 'url' => is_string($value) && (preg_match('#^([a-z][a-z0-9+.-]*):#i', $value, $m) !== 1 || in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) ? null : 'it takes an address (/page, https://, mailto: or tel:), or "".',
            default => is_scalar($value) ? null : 'it takes text.',
        };
    }

    // ---- forms

    /** @return array<string, mixed> */
    private function listForms(): array
    {
        $forms = [];
        foreach ($this->content->getItems('forms', $this->defaultLang, true, false) as $item) {
            $forms[] = ['form' => $item->slug, 'title' => (string)($item->meta['title'] ?? ''), 'status' => (string)($item->meta['status'] ?? 'published'), 'fields' => count(FormFields::normalize($item->meta['fields'] ?? []))];
        }
        return ['forms' => $forms];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function getForm(array $args): array
    {
        $item = $this->form((string)($args['form'] ?? ''));
        if ($item === null) {
            throw new \InvalidArgumentException('There is no form "' . ($args['form'] ?? '') . '". list_forms lists them.');
        }
        $meta = $item->meta;
        $notifications = is_array($meta['notifications'] ?? null) ? $meta['notifications'] : [];
        return [
            'form' => $item->slug,
            'title' => (string)($item->meta['title'] ?? ''),
            'status' => (string)($meta['status'] ?? 'published'),
            'fields' => array_map(static fn(array $f): array => array_diff_key($f, ['saved' => 1]), FormFields::forBuilder($meta['fields'] ?? [])),
            'style' => (string)($meta['style'] ?? ''),
            'submit_label' => (string)($meta['submit_label'] ?? ''),
            'success_message' => (string)($meta['success_message'] ?? ''),
            'translations' => is_array($meta['translations'] ?? null) ? $meta['translations'] : new \stdClass(),
            'read_only' => [
                'emails' => ['enabled' => (bool)($notifications['enabled'] ?? false), 'to' => (string)($notifications['to'] ?? ''), 'auto_reply' => (bool)($notifications['auto_reply'] ?? false)],
                'redirect_url' => (string)($meta['redirect_url'] ?? ''),
                'store_submissions' => (bool)($meta['store_submissions'] ?? true),
            ],
        ];
    }

    /** @param array<string, mixed> $args @param array<string, mixed> $connection @return array<string, mixed> */
    private function saveForm(array $args, array $connection): array
    {
        $slug = (string)($args['form'] ?? '');
        $item = $this->form($slug);
        $new = $item === null;
        if ($new && (Slug::plain($slug) === '' || Slug::plain($slug) !== $slug)) {
            throw new \InvalidArgumentException('form must be a slug of small letters, numbers and dashes, such as "event-signup".');
        }
        $problems = [];
        $meta = $item?->meta ?? [];
        $title = array_key_exists('title', $args) ? trim((string)$args['title']) : (string)($item?->meta['title'] ?? '');
        if ($title === '') {
            $problems[] = 'title: a form needs a name.';
        }
        $fields = $meta['fields'] ?? [];
        if (array_key_exists('fields', $args)) {
            if (!is_array($args['fields']) || !array_is_list($args['fields'])) {
                $problems[] = 'fields must be a list of fields.';
            } else {
                foreach ($args['fields'] as $i => $field) {
                    $type = is_array($field) ? strtolower(trim((string)($field['type'] ?? 'text'))) : '';
                    if (!is_array($field)) {
                        $problems[] = 'fields[' . $i . '] must be an object.';
                    } elseif (!in_array($type, FormFields::types(), true)) {
                        $problems[] = 'fields[' . $i . '].type: there is no kind "' . $type . '" (there are: ' . implode(', ', FormFields::types()) . ').';
                    } elseif (FormFields::sanitizeName((string)($field['name'] ?? '')) === '' && !FormFields::isDisplay($type)) {
                        $problems[] = 'fields[' . $i . '] needs a name (small letters, numbers, - and _).';
                    } elseif (in_array($type, ['select', 'radio'], true) && FormFields::storeOptions($field['options'] ?? []) === []) {
                        $problems[] = 'fields[' . $i . '] (' . $type . ') needs options.';
                    }
                }
                $fields = $args['fields'];
            }
        } elseif ($new) {
            $problems[] = 'fields: a new form needs its fields.';
        }
        $translations = is_array($meta['translations'] ?? null) ? $meta['translations'] : [];
        if (array_key_exists('translations', $args)) {
            if (!is_array($args['translations']) || ($args['translations'] !== [] && array_is_list($args['translations']))) {
                $problems[] = 'translations must be an object by language.';
            } else {
                foreach ($args['translations'] as $lang => $texts) {
                    if (!in_array((string)$lang, $this->languages, true) || (string)$lang === $this->defaultLang) {
                        $problems[] = 'translations: "' . $lang . '" is not another language of the site.';
                    } elseif (is_array($texts)) {
                        $translations[(string)$lang] = $texts + (is_array($translations[(string)$lang] ?? null) ? $translations[(string)$lang] : []);
                    }
                }
            }
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $front = $item !== null ? $this->frontOf($item->filePath) : [];
        $notifications = is_array($meta['notifications'] ?? null) ? $meta['notifications'] : [];
        $antispam = is_array($meta['antispam'] ?? null) ? $meta['antispam'] : [];
        $status = (string)($args['status'] ?? ($meta['status'] ?? 'published'));
        $post = [
            'title' => $title,
            'slug' => $new ? $slug : $item->slug,
            'lang' => $this->defaultLang,
            'status' => $status,
            'visible' => ($meta['visible'] ?? true) === false ? '0' : '1',
            'date' => (string)($meta['date'] ?? ''),
            'body' => $item?->markdown ?? '',
            'frontmatter' => Yaml::dump($front, 10, 2),
            'translation_id' => (string)($meta['translation_id'] ?? ''),
            'form_fields_json' => (string)json_encode(array_values($fields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'form_translations_json' => (string)json_encode($translations === [] ? new \stdClass() : $translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'form_notifications' => $notifications,
            'form_store_submissions' => ($meta['store_submissions'] ?? true) === false ? '0' : '1',
            'form_submit_label' => array_key_exists('submit_label', $args) ? trim((string)$args['submit_label']) : (string)($meta['submit_label'] ?? ''),
            'form_success_message' => array_key_exists('success_message', $args) ? trim((string)$args['success_message']) : (string)($meta['success_message'] ?? ''),
            'form_redirect_url' => (string)($meta['redirect_url'] ?? ''),
            'form_style' => array_key_exists('style', $args) ? (string)$args['style'] : (string)($meta['style'] ?? ''),
            'form_honeypot' => (string)($antispam['honeypot'] ?? ($new ? 'website' : '')),
            'form_rate_limit_seconds' => (string)($antispam['rate_limit_seconds'] ?? ($new ? '20' : '')),
        ];
        if (!$new) {
            $post['original_slug'] = $item->slug;
            $post['original_lang'] = $item->lang;
        }
        $saved = $this->editor->save('forms', $post, null, false, self::actor($connection));
        $this->content->forget();
        $this->done($new ? 'forms.create' : 'forms.update', 'form', (string)$saved['slug'], $new ? 'Form added.' : 'Form updated.', $connection, []);
        return $this->getForm(['form' => (string)$saved['slug']]) + ['added' => $new];
    }

    private function form(string $slug): ?ContentItem
    {
        $slug = Slug::plain($slug);
        return $slug === '' ? null : $this->content->find('forms', $slug, $this->defaultLang, true, false);
    }

    /** @return array<string, mixed> */
    private function frontOf(string $path): array
    {
        [$front] = FrontMatter::split((string)@file_get_contents($path));
        try {
            $parsed = $front !== '' ? Yaml::parse($front) : [];
        } catch (\Throwable) {
            throw new \InvalidArgumentException('The front matter of this form cannot be read; a person has to fix it in the admin first.');
        }
        return is_array($parsed) ? $parsed : [];
    }

    // ---- helpers

    /** @return string[] */
    private function types(): array
    {
        return array_values(array_diff($this->content->getTypes(), ['forms']));
    }

    /** @param array<string, mixed> $connection @param array<string, mixed> $context */
    private function done(string $action, string $type, string $id, string $message, array $connection, array $context): void
    {
        ($this->changed)();
        ($this->log)($action, 'info', $type, $id, $message . ' (MCP: ' . $connection['name'] . ')', $context + ['connection' => (int)$connection['id']]);
    }

    /** @param array<string, mixed> $connection */
    private static function actor(array $connection): string
    {
        return (string)$connection['username'] . ' (MCP: ' . $connection['name'] . ')';
    }

    /**
     * `$base` with `$patch` laid over it: an object inside changes only the keys given, a list or a value is replaced.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null) && !array_is_list($base[$key]) && $base[$key] !== []
                ? self::merge($base[$key], $value)
                : $value;
        }
        return $base;
    }

    /**
     * Where what was asked for and what would be kept differ (a bool and its text, or a number and its text, count as the same).
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function differences(mixed $asked, mixed $kept, string $path = ''): array
    {
        if (is_array($asked) && !array_is_list($asked) && is_array($kept)) {
            $out = [];
            foreach ($asked as $key => $value) {
                $out += self::differences($value, $kept[$key] ?? null, ltrim($path . '.' . $key, '.'));
            }
            return $out;
        }
        $same = match (true) {
            is_bool($kept) => $asked === $kept,
            // Only the case changed (a country code written small) is the same value.
            is_scalar($kept) && is_scalar($asked) => mb_strtolower(trim((string)$asked)) === mb_strtolower(trim((string)$kept)),
            is_array($asked) && is_array($kept) => array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : $v, $asked) == array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : $v, $kept),
            default => $asked === $kept,
        };
        return $same ? [] : [$path => [$asked, $kept]];
    }
}
