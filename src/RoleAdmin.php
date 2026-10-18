<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Roles screen does: the permission table of the built-in and custom roles (saved, or one built-in role set
 * back to its defaults), making, renaming, describing, and deleting custom roles (never while someone holds the role),
 * a log entry for every change of permissions, and the data of the screen.
 */
final class RoleAdmin
{
    private const STARTING_POINTS = ['admin', 'editor', 'user'];

    /** @param callable(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context */
    public function __construct(private SystemMetaRepository $meta, private UserRepository $users, private $log)
    {
    }

    /**
     * Carries out a submitted action against the roles as they are now.
     *
     * @param array<string, mixed> $post
     * @return string where to send the browser
     */
    public function apply(PermissionService $permissions, array $post): string
    {
        if (!$this->meta->isAvailable()) {
            return '/admin/roles?error=store';
        }
        $custom = $permissions->customRoles();
        $action = (string)($post['action'] ?? 'save');
        $key = strtolower(trim((string)($post['role'] ?? '')));

        if ($action === 'create_role') {
            return $this->create($permissions, $custom, $post);
        }
        if ($action === 'update_role' || $action === 'delete_role') {
            if (!isset($custom[$key])) {
                return '/admin/roles?error=unknown';
            }
            if ($action === 'delete_role') {
                // People still holding the role would lose all access, so it has to be free first.
                if ($this->users->countByRole($key, false) > 0) {
                    return '/admin/roles?error=in_use&role=' . urlencode($key);
                }
                unset($custom[$key]);
                $this->meta->setJson('custom_roles', $custom);
                $this->log('roles.delete', 'warning', 'role', $key, 'Role deleted.', []);
                return '/admin/roles?deleted=1';
            }
            $custom[$key]['label'] = trim((string)($post['label'] ?? '')) ?: $custom[$key]['label'];
            $custom[$key]['description'] = trim((string)($post['description'] ?? ''));
            $this->meta->setJson('custom_roles', $custom);
            $this->log('roles.update', 'info', 'role', $key, 'Role renamed or described.', []);
            return '/admin/roles?saved=1';
        }

        $this->savePermissions($permissions, $custom, $post, $action, $key);
        return '/admin/roles?saved=1';
    }

    /**
     * The columns and rows of the permission table, and the custom roles, for the screen.
     *
     * @return array{roles: array<string, array<string, mixed>>, columns: array<string, array<string, mixed>>, groups: array<string, array<string, array<string, mixed>>>, custom_roles: array<int, array<string, mixed>>, can_add_role: bool, super_caps: string[]}
     */
    public function screen(PermissionService $permissions): array
    {
        $custom = $permissions->customRoles();
        $all = $permissions->allRoles();
        $columns = [];
        foreach (array_merge(PermissionService::CUSTOMIZABLE_ROLES, array_keys($custom)) as $role) {
            $isCustom = isset($custom[$role]);
            $columns[$role] = [
                'label' => $all[$role]['label'],
                'custom' => $isCustom,
                'customized' => !$isCustom && $permissions->isCustomized($role),
                'caps' => $permissions->capabilitiesForRole($role),
                'defaults' => $isCustom ? [] : $permissions->defaultsForRole($role),
                'users' => $this->users->countByRole($role),
            ];
        }
        $groups = [];
        foreach (PermissionService::catalogue() as $key => $capability) {
            $groups[$capability['group']][$key] = $capability;
        }
        $mine = [];
        foreach ($custom as $key => $definition) {
            $mine[] = $definition + ['key' => $key, 'users_total' => $this->users->countByRole($key, false)];
        }
        return [
            'roles' => $all,
            'columns' => $columns,
            'groups' => $groups,
            'custom_roles' => $mine,
            'can_add_role' => count($custom) < PermissionService::MAX_CUSTOM_ROLES,
            'super_caps' => $permissions->capabilitiesForRole('superadmin'),
        ];
    }

    /** @param array<string, array<string, mixed>> $custom @param array<string, mixed> $post */
    private function create(PermissionService $permissions, array $custom, array $post): string
    {
        $label = trim((string)preg_replace('/\s+/', ' ', (string)($post['label'] ?? '')));
        if ($label === '') {
            return '/admin/roles?error=label';
        }
        if (count($custom) >= PermissionService::MAX_CUSTOM_ROLES) {
            return '/admin/roles?error=limit';
        }
        $from = (string)($post['from'] ?? 'blank');
        $copied = in_array($from, self::STARTING_POINTS, true);
        $newKey = PermissionService::newCustomKey($label, array_merge(array_keys($custom), array_keys(PermissionService::roles())));
        $custom[$newKey] = [
            'label' => $label,
            'description' => trim((string)($post['description'] ?? '')),
            'capabilities' => $permissions->normalizeCapabilities($copied ? $permissions->capabilitiesForRole($from) : []),
        ];
        $this->meta->setJson('custom_roles', $custom);
        $made = new PermissionService(null, $custom);
        $this->log('roles.create', 'warning', 'role', $newKey, 'Role created.', [
            'label' => $made->customRoles()[$newKey]['label'],
            'copied_from' => $copied ? $from : '',
            'capabilities' => $made->customRoles()[$newKey]['capabilities'],
        ]);
        return '/admin/roles?created=' . urlencode($newKey);
    }

    /** The permission table, and the "back to the built-in set" of one built-in role. @param array<string, array<string, mixed>> $custom @param array<string, mixed> $post */
    private function savePermissions(PermissionService $permissions, array $custom, array $post, string $action, string $key): void
    {
        $before = [];
        foreach (array_merge(PermissionService::CUSTOMIZABLE_ROLES, array_keys($custom)) as $role) {
            $before[$role] = $permissions->capabilitiesForRole($role);
        }
        $inForm = is_array($post['in_form'] ?? null) ? array_map('strval', $post['in_form']) : array_keys($before);

        $selected = [];
        $newCustom = $custom;
        if ($action === 'reset') {
            $selected = $before;
            if (in_array($key, PermissionService::CUSTOMIZABLE_ROLES, true)) {
                $selected[$key] = $permissions->defaultsForRole($key);
            }
        } else {
            foreach ($before as $role => $caps) {
                // A role that was not on the form (made in another window meanwhile) keeps what it has.
                $posted = $post['caps'][$role] ?? [];
                $selected[$role] = in_array($role, $inForm, true)
                    ? (is_array($posted) ? array_keys(array_filter($posted, static fn($v): bool => (string)$v === '1')) : [])
                    : $caps;
            }
        }
        foreach ($custom as $role => $definition) {
            $newCustom[$role]['capabilities'] = $permissions->normalizeCapabilities($selected[$role] ?? $before[$role]);
        }

        $document = $permissions->saveable($selected);
        $this->meta->setJson('role_permissions', $document);
        if ($newCustom !== $custom) {
            $this->meta->setJson('custom_roles', $newCustom);
        }

        $after = new PermissionService($document, $newCustom);
        foreach ($before as $role => $was) {
            $now = $after->capabilitiesForRole($role);
            $added = array_values(array_diff($now, $was));
            $removed = array_values(array_diff($was, $now));
            if ($added !== [] || $removed !== []) {
                $this->log('roles.update', 'warning', 'role', $role, 'Role permissions changed.', ['added' => $added, 'removed' => $removed]);
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, string $level, ?string $type, ?string $id, string $message, array $context): void
    {
        ($this->log)($action, $level, $type, $id, $message, $context);
    }
}
