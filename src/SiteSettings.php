<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * The site settings: the YAML document kept in `system_meta` (with the old `content/settings/site.yaml` read once
 * for installs that predate it), its defaults, the values the settings form shows, and how a submitted form is
 * checked and written back. Secrets are never sent to the browser and a blank secret input keeps the stored one.
 */
final class SiteSettings
{
    /** The places backups can be sent to. */
    public const REMOTE_PROVIDERS = ['custom', 'aws_s3', 'backblaze_b2', 'cloudflare_r2', 'wasabi', 'digitalocean_spaces', 'minio'];

    public function __construct(private SystemMetaRepository $meta, private string $contentDir)
    {
    }

    public function load(): array
    {
        $defaults = $this->defaults();
        $raw = $this->raw('site_settings', $defaults);
        if ($raw === '') {
            return $defaults;
        }

        try {
            $data = Yaml::parse($raw);
        } catch (\Throwable) {
            return $defaults;
        }
        if (!is_array($data)) {
            return $defaults;
        }

        return self::withDefaults($defaults, $data);
    }

    /**
     * The stored settings over the defaults. Groups are merged by name, but a list is the stored list as it is: merging lists
     * place by place would bring back what was taken out (a site with only `el` got `en` again from the default `[el, en]`). An
     * empty stored list leaves the default, as before.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function withDefaults(array $defaults, array $stored): array
    {
        foreach ($stored as $key => $value) {
            $default = $defaults[$key] ?? null;
            if (is_array($value) && is_array($default) && !array_is_list($value) && !array_is_list($default)) {
                $defaults[$key] = self::withDefaults($default, $value);
            } elseif (is_array($value) && $value === [] && is_array($default)) {
                continue;
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }

    public function defaults(): array
    {
        return [
            'title' => 'GnozioCMS',
            'tagline' => 'A flat-file CMS powered by Markdown and Twig.',
            'base_url' => '',
            'theme' => 'default',
            'home_page' => 'index',
            'date_format' => 'd/m/Y',
            // What the site may use: set by the super admin only (Settings > Limits). 0 means no limit.
            'limits' => [
                'storage_mb' => 1024,
            ],
            'languages' => [
                'default' => 'el',
                'available' => ['el', 'en'],
            ],
            'content_types' => ['pages', 'posts', 'projects', 'forms'],
            // Types from the theme's catalogue the site switched off (Admin > Content types).
            'content_types_off' => [],
            'forms' => [
                'store_submissions' => true,
                'notifications' => [
                    'driver' => 'smtp',
                    'from' => '',
                    'from_name' => '',
                    'smtp' => [
                        'host' => '',
                        'port' => '',
                        'username' => '',
                        'password' => '',
                        'encryption' => '',
                    ],
                    'ses' => [
                        'key' => '',
                        'secret' => '',
                        'region' => '',
                    ],
                ],
                'antispam' => [
                    'honeypot' => 'website',
                    'rate_limit_seconds' => 20,
                ],
            ],
            'menu_locations' => [
                'header' => 'main',
                'footer' => 'footer',
            ],
            'backup' => [
                'auto' => [
                    'enabled' => false,
                    'schedule' => 'daily',
                    'last_run' => '',
                ],
                'local' => [
                    'keep' => 20,
                ],
                'remote' => [
                    'enabled' => false,
                    'provider' => 'custom',
                    'endpoint' => '',
                    'region' => '',
                    'bucket' => '',
                    'access_key' => '',
                    'secret_key' => '',
                    'prefix' => '',
                    'keep' => 20,
                    'path_style' => false,
                    'keep_local' => false,
                ],
            ],
            // Keys of services the site talks to (Admin > Settings > APIs).
            'apis' => [
                'youtube' => ['key' => '', 'cache_hours' => 6],
                // The tiles of the maps ('' means OpenStreetMap's), their credit, and when a map loads: 'click' (the visitor asks) or 'auto'.
                'maps' => ['tiles_url' => '', 'attribution' => '', 'load' => 'click'],
            ],
            'updates' => [
                'channel' => 'stable',
                'repository' => 'chiotis/faroscms',
                'branch' => 'main',
                'version_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/main/VERSION',
                'changelog_url' => 'https://raw.githubusercontent.com/chiotis/faroscms/main/CHANGELOG.md',
                'package_url' => 'https://github.com/chiotis/faroscms/archive/refs/heads/main.zip',
                'release_url' => '',
                'github_token' => '',
                'latest_version' => '',
            ],
        ];
    }

    public function raw(string $key, array $defaults): string
    {
        $legacyPath = $this->legacyPath($key);
        $legacyRaw = $legacyPath !== '' && is_file($legacyPath) ? (string)file_get_contents($legacyPath) : '';

        if ($this->meta->isAvailable()) {
            $raw = $this->meta->get($key);
            if ($raw !== null) {
                return $raw;
            }

            // Installs upgraded from the YAML settings era keep their values on first load.
            $raw = trim($legacyRaw) !== '' ? $legacyRaw : Yaml::dump($defaults, 4, 2);
            $this->meta->set($key, $raw);
            return $raw;
        }

        return trim($legacyRaw) !== '' ? $legacyRaw : Yaml::dump($defaults, 4, 2);
    }

    /** @return array<string, mixed> */
    public function parse(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = Yaml::parse($raw);
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    private function legacyPath(string $key): string
    {
        return match ($key) {
            'site_settings' => $this->contentDir . '/settings/site.yaml',
            'theme_settings' => $this->contentDir . '/settings/theme.yaml',
            default => '',
        };
    }

    public function formValues(array $parsed): array
    {
        $defaults = $this->load();
        $merged = self::withDefaults($defaults, $parsed);
        $backupSchedule = (string)($merged['backup']['auto']['schedule'] ?? 'daily');
        if (!in_array($backupSchedule, ['daily', 'weekly', 'monthly'], true)) {
            $backupSchedule = 'daily';
        }
        $backupKeep = (int)($merged['backup']['local']['keep'] ?? 20);
        if ($backupKeep < 1) {
            $backupKeep = 1;
        }
        $remoteProvider = (string)($merged['backup']['remote']['provider'] ?? 'custom');
        if (!in_array($remoteProvider, self::REMOTE_PROVIDERS, true)) {
            $remoteProvider = 'custom';
        }
        $remoteKeep = (int)($merged['backup']['remote']['keep'] ?? 20);
        if ($remoteKeep < 1) {
            $remoteKeep = 1;
        }

        return [
            'title' => (string)($merged['title'] ?? ''),
            'tagline' => (string)($merged['tagline'] ?? ''),
            'base_url' => (string)($merged['base_url'] ?? ''),
            'theme' => (string)($merged['theme'] ?? ''),
            'home_page' => (string)($merged['home_page'] ?? ''),
            'date_format' => (string)($merged['date_format'] ?? ''),
            'storage_limit_mb' => max(0, (int)($merged['limits']['storage_mb'] ?? 1024)),
            'robots_disallow' => implode("\n", RobotsTxt::rules(is_array($merged['seo']['robots_disallow'] ?? null) ? $merged['seo']['robots_disallow'] : [])),
            'upload_limit_mb' => max(0, (int)($merged['limits']['upload_mb'] ?? $merged['media']['max_upload_mb'] ?? 20)),
            'upload_types' => is_array($merged['limits']['upload_types'] ?? null) ? array_values(array_intersect(array_keys(MediaLibrary::UPLOAD_GROUPS), array_map('strval', $merged['limits']['upload_types']))) : array_keys(MediaLibrary::UPLOAD_GROUPS),
            'languages_default' => (string)($merged['languages']['default'] ?? 'el'),
            'languages_available' => implode(', ', $merged['languages']['available'] ?? []),
            'mail_driver' => (string)($merged['forms']['notifications']['driver'] ?? 'smtp'),
            'mail_from' => (string)($merged['forms']['notifications']['from'] ?? ''),
            'mail_from_name' => (string)($merged['forms']['notifications']['from_name'] ?? ''),
            'smtp_host' => (string)($merged['forms']['notifications']['smtp']['host'] ?? ''),
            'smtp_port' => (string)($merged['forms']['notifications']['smtp']['port'] ?? ''),
            'smtp_user' => (string)($merged['forms']['notifications']['smtp']['username'] ?? ''),
            'smtp_encryption' => (string)($merged['forms']['notifications']['smtp']['encryption'] ?? ''),
            'ses_key' => (string)($merged['forms']['notifications']['ses']['key'] ?? ''),
            'ses_region' => (string)($merged['forms']['notifications']['ses']['region'] ?? ''),
            'backup_auto_enabled' => Format::isTruthy($merged['backup']['auto']['enabled'] ?? false),
            'backup_schedule' => $backupSchedule,
            'backup_last_run' => (string)($merged['backup']['auto']['last_run'] ?? ''),
            'backup_keep_local' => (string)$backupKeep,
            'backup_remote_enabled' => Format::isTruthy($merged['backup']['remote']['enabled'] ?? false),
            'backup_remote_provider' => $remoteProvider,
            'backup_remote_endpoint' => (string)($merged['backup']['remote']['endpoint'] ?? ''),
            'backup_remote_region' => (string)($merged['backup']['remote']['region'] ?? ''),
            'backup_remote_bucket' => (string)($merged['backup']['remote']['bucket'] ?? ''),
            'backup_remote_access_key' => (string)($merged['backup']['remote']['access_key'] ?? ''),
            'backup_remote_prefix' => (string)($merged['backup']['remote']['prefix'] ?? ''),
            'backup_remote_keep' => (string)$remoteKeep,
            'backup_remote_path_style' => Format::isTruthy($merged['backup']['remote']['path_style'] ?? false),
            'backup_remote_keep_local' => Format::isTruthy($merged['backup']['remote']['keep_local'] ?? false),
            'google_enabled' => Format::isTruthy($merged['auth']['google']['enabled'] ?? false),
            'google_client_id' => (string)($merged['auth']['google']['client_id'] ?? ''),
            'google_allowed_domain' => (string)($merged['auth']['google']['allowed_domain'] ?? ''),
            'youtube_cache_hours' => (string)self::cacheHours($merged['apis']['youtube']['cache_hours'] ?? 6),
            'maps_tiles_url' => (string)($merged['apis']['maps']['tiles_url'] ?? ''),
            'maps_attribution' => (string)($merged['apis']['maps']['attribution'] ?? ''),
            'maps_load' => ($merged['apis']['maps']['load'] ?? 'click') === 'auto' ? 'auto' : 'click',
            'update_repository' => (string)($merged['updates']['repository'] ?? 'chiotis/faroscms'),
            'update_branch' => (string)($merged['updates']['branch'] ?? 'main'),
            'update_version_url' => (string)($merged['updates']['version_url'] ?? ''),
            'update_changelog_url' => (string)($merged['updates']['changelog_url'] ?? ''),
            'update_package_url' => (string)($merged['updates']['package_url'] ?? ''),
            'update_release_url' => (string)($merged['updates']['release_url'] ?? ''),
        ] + $this->maskedSecrets($merged);
    }

    /**
     * The form as the server reads it, from what the browser sent. A box that is ticked is sent, one that is not is
     * not, so those become '1' or '0'; the robots box is null when it was not on the form that was sent, so a form
     * without it does not clear the rules. The storage limit and the upload settings are left out: they need
     * permission, so the caller adds them.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function formFromPost(array $post): array
    {
        return [
            'title' => (string)($post['title'] ?? ''),
            'tagline' => (string)($post['tagline'] ?? ''),
            'base_url' => (string)($post['base_url'] ?? ''),
            'theme' => (string)($post['theme'] ?? ''),
            'home_page' => (string)($post['home_page'] ?? ''),
            'date_format' => (string)($post['date_format'] ?? ''),
            // Only when the box was on the form that was sent, so a form without it does not clear the rules.
            'robots_disallow' => isset($post['robots_disallow']) ? implode("\n", RobotsTxt::rules((string)$post['robots_disallow'])) : null,
            'languages_default' => (string)($post['languages_default'] ?? ''),
            'languages_available' => (string)($post['languages_available'] ?? ''),
            'mail_driver' => (string)($post['mail_driver'] ?? ''),
            'mail_from' => (string)($post['mail_from'] ?? ''),
            'mail_from_name' => (string)($post['mail_from_name'] ?? ''),
            'smtp_host' => (string)($post['smtp_host'] ?? ''),
            'smtp_port' => (string)($post['smtp_port'] ?? ''),
            'smtp_user' => (string)($post['smtp_user'] ?? ''),
            'smtp_pass' => (string)($post['smtp_pass'] ?? ''),
            'smtp_encryption' => (string)($post['smtp_encryption'] ?? ''),
            'ses_key' => (string)($post['ses_key'] ?? ''),
            'ses_secret' => (string)($post['ses_secret'] ?? ''),
            'ses_region' => (string)($post['ses_region'] ?? ''),
            'backup_auto_enabled' => isset($post['backup_auto_enabled']) ? '1' : '0',
            'backup_schedule' => (string)($post['backup_schedule'] ?? ''),
            'backup_keep_local' => (string)($post['backup_keep_local'] ?? ''),
            'backup_remote_enabled' => isset($post['backup_remote_enabled']) ? '1' : '0',
            'backup_remote_provider' => (string)($post['backup_remote_provider'] ?? ''),
            'backup_remote_endpoint' => (string)($post['backup_remote_endpoint'] ?? ''),
            'backup_remote_region' => (string)($post['backup_remote_region'] ?? ''),
            'backup_remote_bucket' => (string)($post['backup_remote_bucket'] ?? ''),
            'backup_remote_access_key' => (string)($post['backup_remote_access_key'] ?? ''),
            'backup_remote_secret_key' => (string)($post['backup_remote_secret_key'] ?? ''),
            'backup_remote_prefix' => (string)($post['backup_remote_prefix'] ?? ''),
            'backup_remote_keep' => (string)($post['backup_remote_keep'] ?? ''),
            'backup_remote_path_style' => isset($post['backup_remote_path_style']) ? '1' : '0',
            'backup_remote_keep_local' => isset($post['backup_remote_keep_local']) ? '1' : '0',
            'google_enabled' => isset($post['google_enabled']) ? '1' : '0',
            'google_client_id' => (string)($post['google_client_id'] ?? ''),
            'google_client_secret' => (string)($post['google_client_secret'] ?? ''),
            'google_allowed_domain' => (string)($post['google_allowed_domain'] ?? ''),
            'update_repository' => (string)($post['update_repository'] ?? ''),
            'update_branch' => (string)($post['update_branch'] ?? ''),
            'update_version_url' => (string)($post['update_version_url'] ?? ''),
            'update_changelog_url' => (string)($post['update_changelog_url'] ?? ''),
            'update_package_url' => (string)($post['update_package_url'] ?? ''),
            'update_release_url' => (string)($post['update_release_url'] ?? ''),
            'update_github_token' => (string)($post['update_github_token'] ?? ''),
            'youtube_key' => (string)($post['youtube_key'] ?? ''),
            // Null when the field was not on the form that was sent, so such a form leaves the choice as it is.
            'youtube_cache_hours' => isset($post['youtube_cache_hours']) ? (string)$post['youtube_cache_hours'] : null,
            'maps_tiles_url' => isset($post['maps_tiles_url']) ? (string)$post['maps_tiles_url'] : null,
            'maps_attribution' => isset($post['maps_attribution']) ? (string)$post['maps_attribution'] : null,
            'maps_load' => isset($post['maps_load']) ? (string)$post['maps_load'] : null,
            'clear_secrets' => is_array($post['clear_secret'] ?? null) ? array_map('strval', $post['clear_secret']) : [],
        ];
    }

    /**
     * Secrets never travel back to the browser; the form only learns whether one is stored.
     *
     * @return array<string, string|bool>
     */
    private function maskedSecrets(array $settings): array
    {
        $form = [];
        foreach (self::secretPaths() as $field => $path) {
            $form[$field] = '';
            $form[$field . '_set'] = trim((string)ArrayPath::get($settings, $path)) !== '';
        }
        return $form;
    }

    /** @return array<string, array<int, string>> form field => settings path */
    public static function secretPaths(): array
    {
        return [
            'smtp_pass' => ['forms', 'notifications', 'smtp', 'password'],
            'ses_secret' => ['forms', 'notifications', 'ses', 'secret'],
            'google_client_secret' => ['auth', 'google', 'client_secret'],
            'backup_remote_secret_key' => ['backup', 'remote', 'secret_key'],
            'update_github_token' => ['updates', 'github_token'],
            'youtube_key' => ['apis', 'youtube', 'key'],
        ];
    }

    /** How many hours a fetched playlist is kept: one of 1, 6, 24 or 72. */
    public static function cacheHours(mixed $value): int
    {
        $hours = (int)$value;
        return in_array($hours, [1, 6, 24, 72], true) ? $hours : 6;
    }

    /**
     * Switches a content type from the theme's catalogue on or off. Switching on lists the type and takes it off the list of
     * those switched off; switching off adds it to that list (the files of the type are not touched). Pages and forms cannot
     * be switched off. Returns false when the settings cannot be written.
     */
    public function setContentType(string $type, bool $on): bool
    {
        if (!$this->meta->isAvailable() || !preg_match('/^[a-z][a-z0-9_-]*$/', $type) || (!$on && in_array($type, ['pages', 'forms'], true))) {
            return false;
        }
        $data = $this->parse($this->raw('site_settings', $this->defaults()));
        $listed = array_values(array_filter(array_map('strval', is_array($data['content_types'] ?? null) ? $data['content_types'] : $this->defaults()['content_types'])));
        $off = array_values(array_filter(array_map('strval', is_array($data['content_types_off'] ?? null) ? $data['content_types_off'] : [])));
        if ($on) {
            if (!in_array($type, $listed, true)) {
                $listed[] = $type;
            }
            $off = array_values(array_diff($off, [$type]));
        } elseif (!in_array($type, $off, true)) {
            $off[] = $type;
        }
        $data['content_types'] = $listed;
        if ($off === []) {
            unset($data['content_types_off']);
        } else {
            $data['content_types_off'] = $off;
        }
        $this->meta->set('site_settings', Yaml::dump($data, 4, 2));
        return true;
    }

    /**
     * Stores one section of the site settings (`seo`, `analytics`: the screens that own them) and leaves the rest as it is. An
     * empty section is removed. Returns false when the settings cannot be written.
     *
     * @param array<string, mixed> $values
     */
    public function setSection(string $key, array $values): bool
    {
        if (!$this->meta->isAvailable() || !preg_match('/^[a-z_]+$/', $key)) {
            return false;
        }
        $data = $this->parse($this->raw('site_settings', $this->defaults()));
        if ($values === []) {
            unset($data[$key]);
        } else {
            $data[$key] = $values;
        }
        $this->meta->set('site_settings', Yaml::dump($data, 5, 2));
        return true;
    }

    /** @param array<string, mixed> $seo */
    public function setSeo(array $seo): bool
    {
        return $this->setSection('seo', $seo);
    }

    /** Shows a menu in a place of the theme ("header", "footer"). Returns false when the settings cannot be written. */
    public function setMenuLocation(string $location, string $menu): bool
    {
        $location = Slug::plain($location);
        $menu = Slug::plain($menu);
        if (!$this->meta->isAvailable() || $location === '' || $menu === '') {
            return false;
        }
        $data = $this->parse($this->raw('site_settings', $this->defaults()));
        $places = is_array($data['menu_locations'] ?? null) ? $data['menu_locations'] : [];
        $places[$location] = $menu;
        $data['menu_locations'] = $places;
        $this->meta->set('site_settings', Yaml::dump($data, 4, 2));
        return true;
    }

    public function save(string $raw, array $form): bool
    {
        if (!$this->meta->isAvailable()) {
            return false;
        }

        // Kept apart: a box left out of the form (null) is not the same as a box that was emptied.
        $robotsSubmitted = array_key_exists('robots_disallow', $form) && $form['robots_disallow'] !== null;
        $robotsText = (string)($form['robots_disallow'] ?? '');
        $hoursSubmitted = array_key_exists('youtube_cache_hours', $form) && $form['youtube_cache_hours'] !== null;
        $mapsSubmitted = array_key_exists('maps_load', $form) && $form['maps_load'] !== null;
        foreach ($form as $key => $value) {
            if (is_array($value)) {
                $form[$key] = array_map(fn($item) => trim((string)$item), $value);
            } else {
                $form[$key] = trim((string)$value);
            }
        }
        $data = $this->parse($raw);
        $existingSecrets = [];
        foreach (self::secretPaths() as $field => $path) {
            $existingSecrets[$field] = (string)(ArrayPath::get($data, $path) ?? '');
        }

        $data['title'] = $form['title'] !== '' ? $form['title'] : ($data['title'] ?? 'GnozioCMS');
        $data['tagline'] = $form['tagline'] !== '' ? $form['tagline'] : ($data['tagline'] ?? '');
        $data['base_url'] = $form['base_url'];
        $data['theme'] = $form['theme'] !== '' ? $form['theme'] : ($data['theme'] ?? 'default');
        $data['home_page'] = $form['home_page'] !== '' ? $form['home_page'] : ($data['home_page'] ?? 'index');
        $data['date_format'] = $form['date_format'] !== '' ? $form['date_format'] : ($data['date_format'] ?? 'd/m/Y');
        if ($robotsSubmitted) {
            $rules = RobotsTxt::rules($robotsText);
            if ($rules === []) {
                unset($data['seo']['robots_disallow']);
                if (($data['seo'] ?? []) === []) {
                    unset($data['seo']);
                }
            } else {
                $data['seo']['robots_disallow'] = $rules;
            }
        }
        // Every form value was turned into text above: an empty one means the field was not submitted.
        if (is_numeric($form['storage_limit_mb'] ?? null)) {
            $data['limits']['storage_mb'] = (int)$form['storage_limit_mb'];
        }
        if (is_numeric($form['upload_limit_mb'] ?? null)) {
            $data['limits']['upload_mb'] = (int)$form['upload_limit_mb'];
        }
        if (is_string($form['upload_types'] ?? null) && $form['upload_types'] !== '') {
            $data['limits']['upload_types'] = explode(',', $form['upload_types']);
        }

        $available = $this->languageList($form['languages_available']);
        if (empty($available) && isset($data['languages']['available']) && is_array($data['languages']['available'])) {
            $available = array_values(array_filter(array_map('strval', $data['languages']['available'])));
        }
        if (empty($available)) {
            $available = ['el', 'en'];
        }

        $defaultLang = Slug::plain($form['languages_default']);
        if ($defaultLang === '' && isset($data['languages']['default'])) {
            $defaultLang = (string)$data['languages']['default'];
        }
        if ($defaultLang === '') {
            $defaultLang = $available[0] ?? 'el';
        }
        if (!in_array($defaultLang, $available, true)) {
            array_unshift($available, $defaultLang);
            $available = array_values(array_unique($available));
        }

        $data['languages'] = [
            'default' => $defaultLang,
            'available' => $available,
        ];

        $data['forms']['notifications'] = $data['forms']['notifications'] ?? [];
        $driver = $form['mail_driver'] !== '' ? $form['mail_driver'] : ($data['forms']['notifications']['driver'] ?? 'smtp');
        if (!in_array($driver, ['smtp', 'ses'], true)) {
            $driver = 'smtp';
        }
        $data['forms']['notifications']['driver'] = $driver;
        $data['forms']['notifications']['from'] = $form['mail_from'];
        $data['forms']['notifications']['from_name'] = $form['mail_from_name'];
        $data['forms']['notifications']['smtp'] = [
            'host' => $form['smtp_host'],
            'port' => $form['smtp_port'] !== '' ? (int)$form['smtp_port'] : '',
            'username' => $form['smtp_user'],
            'password' => $form['smtp_pass'],
            'encryption' => $form['smtp_encryption'],
        ];
        $data['forms']['notifications']['ses'] = [
            'key' => $form['ses_key'],
            'secret' => $form['ses_secret'],
            'region' => $form['ses_region'],
        ];

        // Where menus appear is set in the menu editor (setMenuLocation), so the settings form leaves it as it is.
        unset($data['menu']);

        $backupSchedule = strtolower((string)($form['backup_schedule'] ?? 'daily'));
        if (!in_array($backupSchedule, ['daily', 'weekly', 'monthly'], true)) {
            $backupSchedule = 'daily';
        }
        $backupAutoEnabled = Format::isTruthy($form['backup_auto_enabled'] ?? false);
        $backupKeep = (int)($form['backup_keep_local'] ?? 20);
        if ($backupKeep < 1) {
            $backupKeep = 1;
        }
        $backupLastRun = (string)($data['backup']['auto']['last_run'] ?? '');
        $remoteProvider = strtolower((string)($form['backup_remote_provider'] ?? 'custom'));
        if (!in_array($remoteProvider, self::REMOTE_PROVIDERS, true)) {
            $remoteProvider = 'custom';
        }
        $remoteKeep = (int)($form['backup_remote_keep'] ?? 20);
        if ($remoteKeep < 1) {
            $remoteKeep = 1;
        }

        $data['backup'] = [
            'auto' => [
                'enabled' => $backupAutoEnabled,
                'schedule' => $backupSchedule,
                'last_run' => $backupLastRun,
            ],
            'local' => [
                'keep' => $backupKeep,
            ],
            'remote' => [
                'enabled' => Format::isTruthy($form['backup_remote_enabled'] ?? false),
                'provider' => $remoteProvider,
                'endpoint' => (string)($form['backup_remote_endpoint'] ?? ''),
                'region' => (string)($form['backup_remote_region'] ?? ''),
                'bucket' => (string)($form['backup_remote_bucket'] ?? ''),
                'access_key' => (string)($form['backup_remote_access_key'] ?? ''),
                'secret_key' => (string)($form['backup_remote_secret_key'] ?? ''),
                'prefix' => trim((string)($form['backup_remote_prefix'] ?? ''), '/'),
                'keep' => $remoteKeep,
                'path_style' => Format::isTruthy($form['backup_remote_path_style'] ?? false),
                'keep_local' => Format::isTruthy($form['backup_remote_keep_local'] ?? false),
            ],
        ];

        $data['auth']['google'] = [
            'enabled' => Format::isTruthy($form['google_enabled'] ?? false),
            'client_id' => (string)($form['google_client_id'] ?? ''),
            'client_secret' => (string)($form['google_client_secret'] ?? ''),
            'allowed_domain' => strtolower(trim((string)($form['google_allowed_domain'] ?? ''))),
        ];

        $existingUpdates = is_array($data['updates'] ?? null) ? $data['updates'] : [];
        $repository = trim((string)($form['update_repository'] ?? ''));
        if ($repository === '') {
            $repository = 'chiotis/faroscms';
        }
        $branch = trim((string)($form['update_branch'] ?? ''));
        if ($branch === '') {
            $branch = 'main';
        }
        $data['updates'] = [
            'channel' => (string)($existingUpdates['channel'] ?? 'stable'),
            'repository' => $repository,
            'branch' => $branch,
            'version_url' => (string)($form['update_version_url'] ?? ''),
            'changelog_url' => (string)($form['update_changelog_url'] ?? ''),
            'package_url' => (string)($form['update_package_url'] ?? ''),
            'release_url' => trim((string)($form['update_release_url'] ?? '')),
            'github_token' => '',
            'latest_version' => (string)($existingUpdates['latest_version'] ?? ''),
        ];

        // Blank secret inputs keep the stored value; only an explicit "remove" clears it.
        $clear = is_array($form['clear_secrets'] ?? null) ? $form['clear_secrets'] : [];
        foreach (self::secretPaths() as $field => $path) {
            $submitted = (string)($form[$field] ?? '');
            if (in_array($field, $clear, true)) {
                $value = '';
            } elseif ($submitted !== '') {
                $value = $submitted;
            } else {
                $value = $existingSecrets[$field] ?? '';
            }
            ArrayPath::set($data, $path, $value);
        }

        // The key was set by the loop above; how long a playlist is kept is left as it is when the form did not carry it.
        $data['apis']['youtube']['cache_hours'] = $hoursSubmitted
            ? self::cacheHours($form['youtube_cache_hours'])
            : self::cacheHours($data['apis']['youtube']['cache_hours'] ?? 6);

        // The maps: only what the form carried. Tiles must be an https address with {z}, {x} and {y} in it, or the default is used.
        if ($mapsSubmitted) {
            $tiles = (string)($form['maps_tiles_url'] ?? '');
            $valid = preg_match('#^https://[^\s"\'<>]+\{z\}[^\s"\'<>]*\{x\}[^\s"\'<>]*\{y\}[^\s"\'<>]*$#', $tiles) === 1;
            $data['apis']['maps'] = [
                'tiles_url' => $valid ? $tiles : '',
                'attribution' => $valid ? GeoView::cleanAttribution((string)($form['maps_attribution'] ?? '')) : '',
                'load' => ($form['maps_load'] ?? '') === 'auto' ? 'auto' : 'click',
            ];
        }

        $this->meta->set('site_settings', Yaml::dump($data, 4, 2));
        return true;
    }

    private function languageList(string $value): array
    {
        $items = Format::commaList($value);
        $items = array_map(fn($item) => Slug::plain((string)$item), $items);
        $items = array_values(array_filter($items, fn($item) => $item !== ''));
        return array_values(array_unique($items));
    }
}
