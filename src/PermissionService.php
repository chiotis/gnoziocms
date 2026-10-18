<?php

declare(strict_types=1);

namespace GnozioCMS;

final class PermissionService
{
    /** Roles whose capabilities the super admin can change. The super admin's own set is fixed so nobody can be locked out. */
    public const CUSTOMIZABLE_ROLES = ['admin', 'editor', 'user'];

    /** On for every role, and not switchable: without them a signed-in person could not reach the admin or their own profile. */
    private const LOCKED_ON = ['admin.access', 'users.self'];

    /** @var array<string, array<int, string>> Chosen by the super admin, only for roles that differ from the built-in set. */
    private array $overrides = [];

    /** How many roles of their own a site can have. */
    public const MAX_CUSTOM_ROLES = 20;

    /** @var array<string, array{label: string, description: string, capabilities: array<int, string>}> Roles the super admin made. */
    private array $customRoles = [];

    /** @var array<string, array<int, string>> The built-in capabilities of each role. */
    private array $roleCapabilities = [
        'superadmin' => [
            'admin.access',
            'dashboard.view',
            'content.manage',
            'content.raw_html',
            'forms.manage',
            'media.manage',
            'menus.manage',
            'redirects.manage',
            'seo.manage',
            'analytics.manage',
            'taxonomies.manage',
            'settings.manage',
            'translations.manage',
            'users.manage',
            'roles.manage',
            'limits.manage',
            'users.self',
            'activity.manage',
            'email_logs.manage',
            'notifications.manage',
            'imports.manage',
            'exports.manage',
            'backups.manage',
            'backups.restore',
            'updates.manage',
            'omni.manage',
        ],
        'admin' => [
            'admin.access',
            'dashboard.view',
            'content.manage',
            'content.raw_html',
            'forms.manage',
            'media.manage',
            'menus.manage',
            'redirects.manage',
            'seo.manage',
            'analytics.manage',
            'taxonomies.manage',
            'settings.manage',
            'translations.manage',
            'users.self',
            'activity.manage',
            'email_logs.manage',
            'notifications.manage',
            'imports.manage',
            'exports.manage',
            'backups.manage',
            'updates.manage',
        ],
        // Writes and publishes content and manages media and taxonomies. No forms (their submissions hold
        // visitors' personal data), menus, settings, users, logs, backups, updates, or import/export, and no
        // raw HTML in content (see content.raw_html), so an editor cannot script the public site.
        'editor' => [
            'admin.access',
            'dashboard.view',
            'content.manage',
            'media.manage',
            'taxonomies.manage',
            'users.self',
        ],
        'user' => [
            'admin.access',
            'users.self',
        ],
    ];

    /**
     * @param array<string, mixed>|null $overrides The saved per-role capability lists (see saveable()), or null for the built-in roles.
     * @param array<string, mixed>|null $customRoles The roles the super admin made: key => label, description, capabilities.
     */
    public function __construct(?array $overrides = null, ?array $customRoles = null)
    {
        $this->overrides = $this->cleanOverrides($overrides ?? []);
        $this->customRoles = $this->cleanCustomRoles($customRoles ?? []);
    }

    /**
     * Roles of the site's own: a name, a description, and exactly the capabilities chosen (always-on ones added, the
     * ones only the super admin can have never). Anything that is not a valid role is dropped.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array{label: string, description: string, capabilities: array<int, string>}>
     */
    private function cleanCustomRoles(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $definition) {
            if (!is_string($key) || !self::validCustomKey($key) || !is_array($definition) || count($out) >= self::MAX_CUSTOM_ROLES) {
                continue;
            }
            $label = trim((string)preg_replace('/\s+/', ' ', (string)($definition['label'] ?? '')));
            $out[$key] = [
                'label' => $label !== '' ? mb_substr($label, 0, 60) : Slug::title($key),
                'description' => mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)($definition['description'] ?? ''))), 0, 200),
                'capabilities' => $this->normalizeList(is_array($definition['capabilities'] ?? null) ? $definition['capabilities'] : []),
            ];
        }
        return $out;
    }

    /** A key a custom role can have: lower case letters, digits, and dashes, and never the name of a built-in role. */
    public static function validCustomKey(string $key): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9-]{1,29}$/', $key) && !isset(self::roles()[$key]);
    }

    /**
     * A new key for a role, made from its name (Greek converted to Latin) and different from every role that exists.
     *
     * @param string[] $taken
     */
    public static function newCustomKey(string $label, array $taken): string
    {
        $base = trim(str_replace('_', '-', Slug::fromText($label)), '-');
        $base = mb_substr($base, 0, 26);
        if (!preg_match('/^[a-z]/', $base)) {
            $base = 'role-' . $base;
        }
        $base = rtrim(mb_substr($base, 0, 26), '-');
        if (strlen($base) < 2) {
            $base = 'role';
        }
        $key = $base;
        for ($n = 2; !self::validCustomKey($key) || in_array($key, $taken, true); $n++) {
            $key = $base . '-' . $n;
        }
        return $key;
    }

    /** @return array<string, array{label: string, description: string, capabilities: array<int, string>}> */
    public function customRoles(): array
    {
        return $this->customRoles;
    }

    public function isCustomRole(string $role): bool
    {
        return isset($this->customRoles[$this->normalizeRole($role)]);
    }

    /** The capabilities that would be stored for a list a form submitted. @param array<int|string, mixed> $list @return array<int, string> */
    public function normalizeCapabilities(array $list): array
    {
        return $this->normalizeList($list);
    }

    /**
     * Every role a person can have, most powerful first, the site's own roles last.
     *
     * @return array<string, array{label: string, description: string, custom: bool}>
     */
    public function allRoles(): array
    {
        $roles = [];
        foreach (self::roles() as $key => $role) {
            $roles[$key] = $role + ['custom' => false];
        }
        foreach ($this->customRoles as $key => $role) {
            $roles[$key] = [
                'label' => $role['label'],
                'description' => $role['description'] !== '' ? $role['description'] : 'A role of this site.',
                'custom' => true,
            ];
        }
        return $roles;
    }

    /**
     * Every capability the roles screen shows, grouped, with how much damage it could do in the wrong hands.
     * `grantable` false means only the super admin ever has it; it never appears as a switch.
     *
     * @return array<string, array{group: string, label: string, description: string, risk: string, grantable: bool}>
     */
    public static function catalogue(): array
    {
        $c = static fn(string $group, string $label, string $description, string $risk = 'normal', bool $grantable = true): array => [
            'group' => $group, 'label' => $label, 'description' => $description, 'risk' => $risk, 'grantable' => $grantable,
        ];
        return [
            'admin.access' => $c('Basics', 'Sign in to the admin', 'Always on for every role.'),
            'users.self' => $c('Basics', 'Edit own profile and password', 'Always on for every role.'),
            'dashboard.view' => $c('Content', 'Dashboard', 'Sees the overview with content figures.'),
            'content.manage' => $c('Content', 'Pages, posts, and projects', 'Writes, edits, publishes, and deletes content of every type except forms.'),
            'content.raw_html' => $c('Content', 'Raw HTML in content', 'Can add HTML to text and blocks. HTML can carry scripts, so this lets the person change what runs on the public site.', 'critical'),
            'media.manage' => $c('Content', 'Media library', 'Uploads, renames, and deletes files.'),
            'taxonomies.manage' => $c('Content', 'Categories and tags', 'Creates, edits, and deletes them.'),
            'menus.manage' => $c('Site', 'Menus', 'Changes the site navigation.'),
            'redirects.manage' => $c('Site', 'Redirects and broken links', 'Sends visitors from one address to another, including to other websites, and sees which addresses are not found.', 'sensitive'),
            'seo.manage' => $c('Site', 'SEO', 'Sets how the site appears in search engines and when links are shared, what crawlers may read, the sitemap and the ownership codes. It can take the whole site out of search.', 'sensitive'),
            'analytics.manage' => $c('Site', 'Analytics', 'Chooses how visits are counted, reads the reports, and (with the raw HTML permission) adds tracking code to every page.', 'sensitive'),
            'translations.manage' => $c('Site', 'Translations', 'Edits the site\'s interface texts.'),
            'forms.manage' => $c('Site', 'Forms and submissions', 'Reads what visitors sent (personal data) and decides where notifications go.', 'sensitive'),
            'settings.manage' => $c('Site', 'Settings, system, content types', 'Includes email sending and who can sign in (Google), so it can be used to take over the site.', 'critical'),
            'imports.manage' => $c('Data', 'Import content', 'Bulk-creates and overwrites content from CSV files.', 'sensitive'),
            'exports.manage' => $c('Data', 'Export content and forms', 'Downloads content and visitors\' form data in bulk.', 'sensitive'),
            'activity.manage' => $c('Data', 'Activity log', 'Sees who did what, including IP addresses.', 'sensitive'),
            'email_logs.manage' => $c('Data', 'Email log', 'Reads the emails the site sent, which can hold personal data.', 'sensitive'),
            'notifications.manage' => $c('Data', 'System notifications', 'Sees and dismisses warnings about the site itself.'),
            'backups.manage' => $c('System', 'Backups', 'Creates and downloads backups, which contain everything including user accounts.', 'sensitive'),
            'updates.manage' => $c('System', 'Updates', 'Installs new versions of the software.', 'critical'),
            'backups.restore' => $c('System', 'Restore a backup', 'Replaces the whole site. Super admin only.', 'critical', false),
            'users.manage' => $c('System', 'Users', 'Creates users and sets their roles. Super admin only.', 'critical', false),
            'roles.manage' => $c('System', 'Roles and permissions', 'Changes what each role can do. Super admin only.', 'critical', false),
            'limits.manage' => $c('System', 'Site limits', 'Sets how much storage the site may use. Super admin only.', 'critical', false),
            'omni.manage' => $c('System', 'Omni', 'Connects the site to an Omni, which then sees its health, storage, backups and updates. Super admin only.', 'critical', false),
        ];
    }

    /** @return array<int, string> Capabilities that can be switched on or off for a role. */
    public static function grantable(): array
    {
        return array_keys(array_filter(self::catalogue(), static fn(array $c): bool => $c['grantable']));
    }

    /** @return array<int, string> The built-in capabilities of a role, ignoring anything the super admin changed. */
    public function defaultsForRole(string $role): array
    {
        return $this->roleCapabilities[$this->normalizeRole($role)] ?? [];
    }

    public function isCustomized(string $role): bool
    {
        return isset($this->overrides[$this->normalizeRole($role)]);
    }

    /**
     * The document to store for a submitted matrix: only roles whose choice differs from the built-in set, so a role
     * left alone keeps following the defaults. Anything not switchable is dropped.
     *
     * @param array<string, mixed> $selected role => list of capability keys
     * @return array<string, array<int, string>>
     */
    public function saveable(array $selected): array
    {
        $out = [];
        foreach (self::CUSTOMIZABLE_ROLES as $role) {
            if (!array_key_exists($role, $selected)) {
                continue;
            }
            $list = $this->normalizeList(is_array($selected[$role]) ? $selected[$role] : []);
            $default = $this->normalizeList($this->roleCapabilities[$role] ?? []);
            if ($list !== $default) {
                $out[$role] = $list;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, array<int, string>>
     */
    private function cleanOverrides(array $raw): array
    {
        $out = [];
        foreach (self::CUSTOMIZABLE_ROLES as $role) {
            if (isset($raw[$role]) && is_array($raw[$role])) {
                $out[$role] = $this->normalizeList($raw[$role]);
            }
        }
        return $out;
    }

    /**
     * Keeps only real, switchable capabilities, adds the always-on ones, and puts them in a stable order.
     *
     * @param array<int|string, mixed> $list
     * @return array<int, string>
     */
    private function normalizeList(array $list): array
    {
        $known = self::grantable();
        $keep = array_merge(self::LOCKED_ON, array_filter($list, static fn($v): bool => is_string($v) && in_array($v, $known, true)));
        return array_values(array_intersect($known, array_unique($keep)));
    }

    /**
     * Roles in order of power, for the users screens.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            'superadmin' => ['label' => 'Super admin', 'description' => 'Everything, including users and roles. Always has every permission; keep at least one.'],
            'admin' => ['label' => 'Admin', 'description' => 'Everything except managing users: content, forms, menus, settings, backups, and updates.'],
            'editor' => ['label' => 'Editor', 'description' => 'Writes, edits, and publishes pages, posts, and projects, and manages media and categories. Cannot change settings, forms, menus, or users, and cannot add raw HTML.'],
            'user' => ['label' => 'Basic user', 'description' => 'Can sign in and edit their own profile only.'],
        ];
    }

    /** @return array<int, string> */
    public function capabilitiesForRole(string $role): array
    {
        $role = $this->normalizeRole($role);
        return $this->overrides[$role] ?? $this->customRoles[$role]['capabilities'] ?? $this->roleCapabilities[$role] ?? [];
    }

    public function can(?array $user, string $capability): bool
    {
        if (!$user || (string)($user['status'] ?? 'active') !== 'active') {
            return false;
        }

        return in_array($capability, $this->capabilitiesForRole((string)($user['role'] ?? 'user')), true);
    }

    public function canAccessAction(?array $user, string $action): bool
    {
        // The image picker is used from the content editor, the media screen, and screens with an image setting.
        if ($action === 'media-picker') {
            return $this->can($user, 'content.manage') || $this->can($user, 'media.manage') || $this->can($user, 'settings.manage');
        }

        $capability = match ($action) {
            'settings', 'theme', 'system', 'content-types' => 'settings.manage',
            'dashboard', 'index' => 'dashboard.view',
            'content' => 'content.manage',
            'menus', 'menus-new', 'menus-edit' => 'menus.manage',
            'media', 'files' => 'media.manage',
            'forms', 'forms-new', 'form-submissions', 'form-file', 'form-files-zip' => 'forms.manage',
            'forms-export', 'export' => 'exports.manage',
            'import' => 'imports.manage',
            'translations' => 'translations.manage',
            'taxonomies' => 'taxonomies.manage',
            'activity-logs' => 'activity.manage',
            'email-logs' => 'email_logs.manage',
            'notification-read', 'notifications-read-all' => 'notifications.manage',
            'backups' => 'backups.manage',
            'updates' => 'updates.manage',
            'omni' => 'omni.manage',
            'users', 'users-delete' => 'users.manage',
            'roles' => 'roles.manage',
            'redirects' => 'redirects.manage',
            'seo' => 'seo.manage',
            'analytics' => 'analytics.manage',
            'users-edit' => null,
            'edit', 'save', 'delete', 'new', 'block-presets', 'markdown-visual', 'content-bulk', 'search', 'revisions', 'links' => 'content.manage',
            // An action nobody mapped is for administrators only, never for a lower role by accident.
            default => 'settings.manage',
        };

        if ($capability === null) {
            return $this->can($user, 'users.manage') || $this->can($user, 'users.self');
        }

        return $this->can($user, $capability);
    }

    public function canEditUser(?array $actor, int $targetId): bool
    {
        if ($this->can($actor, 'users.manage')) {
            return true;
        }

        return $this->can($actor, 'users.self') && $targetId > 0 && (int)($actor['id'] ?? 0) === $targetId;
    }

    public function canCreateUsers(?array $actor): bool
    {
        return $this->can($actor, 'users.manage');
    }

    public function canChangeAccessForUser(?array $actor, int $targetId): bool
    {
        return $this->can($actor, 'users.manage') && (int)($actor['id'] ?? 0) !== $targetId;
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        return $role !== '' ? $role : 'user';
    }
}
