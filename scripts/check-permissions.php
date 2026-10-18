<?php
/*
 * Checks which role may use which admin action. Run it after adding or changing an admin action:
 *
 *   php scripts/check-permissions.php
 *
 * Add the new action to the table below with who should reach it: [superadmin, admin, editor, user].
 * An action that is not mapped in PermissionService is administrator-only, which is what a new
 * action should start as.
 */
require __DIR__ . '/../vendor/autoload.php';
use GnozioCMS\PermissionService;
$p = new PermissionService();
$fail = 0;
function check(string $label, $actual, $expected) { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . var_export($actual, true) . ' expected ' . var_export($expected, true)) . "\n"; }
$u = fn(string $role, int $id = 5) => ['id' => $id, 'role' => $role, 'status' => 'active'];

// Every admin action the router knows, and who may use it: [superadmin, admin, editor, user]
$actions = [
    'dashboard' => [1,1,1,0], 'index' => [1,1,1,0],
    'content' => [1,1,1,0], 'content-bulk' => [1,1,1,0], 'search' => [1,1,1,0], 'edit' => [1,1,1,0], 'revisions' => [1,1,1,0], 'links' => [1,1,1,0], 'save' => [1,1,1,0], 'delete' => [1,1,1,0], 'new' => [1,1,1,0], 'block-presets' => [1,1,1,0], 'markdown-visual' => [1,1,1,0], 'media-picker' => [1,1,1,0],
    'media' => [1,1,1,0], 'files' => [1,1,1,0], 'taxonomies' => [1,1,1,0],
    'menus' => [1,1,0,0], 'menus-new' => [1,1,0,0], 'menus-edit' => [1,1,0,0],
    'forms' => [1,1,0,0], 'forms-new' => [1,1,0,0], 'form-submissions' => [1,1,0,0], 'forms-export' => [1,1,0,0], 'export' => [1,1,0,0], 'import' => [1,1,0,0],
    'settings' => [1,1,0,0], 'theme' => [1,1,0,0], 'system' => [1,1,0,0], 'content-types' => [1,1,0,0], 'translations' => [1,1,0,0],
    'activity-logs' => [1,1,0,0], 'email-logs' => [1,1,0,0], 'backups' => [1,1,0,0], 'updates' => [1,1,0,0],
    'notification-read' => [1,1,0,0], 'notifications-read-all' => [1,1,0,0],
    'users' => [1,0,0,0], 'users-delete' => [1,0,0,0], 'roles' => [1,0,0,0], 'redirects' => [1,1,0,0], 'seo' => [1,1,0,0], 'analytics' => [1,1,0,0],
    'users-edit' => [1,1,1,1],
    'some-future-action' => [1,1,0,0],
];
foreach ($actions as $action => $expected) {
    foreach (['superadmin', 'admin', 'editor', 'user'] as $i => $role) {
        check("$role / $action", $p->canAccessAction($u($role), $action), (bool)$expected[$i]);
    }
}
check('anonymous denied', $p->canAccessAction(null, 'dashboard'), false);
check('inactive editor denied', $p->canAccessAction(['id' => 1, 'role' => 'editor', 'status' => 'inactive'], 'content'), false);
check('unknown role has nothing', $p->capabilitiesForRole('wizard'), []);
check('raw_html: admin roles only', [$p->can($u('superadmin'), 'content.raw_html'), $p->can($u('admin'), 'content.raw_html'), $p->can($u('editor'), 'content.raw_html')], [true, true, false]);
check('forms.manage not for editor', $p->can($u('editor'), 'forms.manage'), false);
check('editor edits only self', [$p->canEditUser($u('editor', 5), 5), $p->canEditUser($u('editor', 5), 6), $p->canCreateUsers($u('editor'))], [true, false, false]);
check('editor cannot change own role', $p->canChangeAccessForUser($u('editor', 5), 5), false);
check('admin cannot manage users', $p->canCreateUsers($u('admin')), false);
check('role list', array_keys(PermissionService::roles()), ['superadmin', 'admin', 'editor', 'user']);

// Permissions the super admin chose (stored in system_meta as role_permissions).
$custom = new PermissionService(['editor' => ['content.manage', 'menus.manage', 'users.manage', 'roles.manage', 'backups.restore', 'nonsense'], 'admin' => ['content.manage'], 'superadmin' => []]);
check('custom: editor gains menus', $custom->canAccessAction($u('editor'), 'menus'), true);
check('custom: editor loses media', $custom->canAccessAction($u('editor'), 'media'), false);
check('custom: users, roles, restore can never be granted', [$custom->can($u('editor'), 'users.manage'), $custom->can($u('editor'), 'roles.manage'), $custom->can($u('editor'), 'backups.restore')], [false, false, false]);
check('custom: sign-in and own profile cannot be removed', [$custom->can($u('editor'), 'admin.access'), $custom->can($u('editor'), 'users.self')], [true, true]);
check('custom: unknown capability dropped', in_array('nonsense', $custom->capabilitiesForRole('editor'), true), false);
check('custom: admin narrowed', [$custom->canAccessAction($u('admin'), 'content'), $custom->canAccessAction($u('admin'), 'settings')], [true, false]);
check('custom: super admin cannot be overridden', $custom->capabilitiesForRole('superadmin'), $p->capabilitiesForRole('superadmin'));
check('custom: role left alone keeps defaults', $custom->capabilitiesForRole('user'), $p->capabilitiesForRole('user'));
check('custom: only the super admin gets roles', [$custom->canAccessAction($u('superadmin'), 'roles'), $custom->canAccessAction($u('admin'), 'roles'), $custom->canAccessAction($u('editor'), 'roles')], [true, false, false]);
check('saveable: unchanged role is not stored', $p->saveable(['editor' => $p->defaultsForRole('editor')]), []);
check('saveable: change is stored without the ungrantable', $p->saveable(['editor' => ['content.manage', 'users.manage']]), ['editor' => ['admin.access', 'users.self', 'content.manage']]);
check('saveable: superadmin never stored', $p->saveable(['superadmin' => ['content.manage']]), []);
check('catalogue covers every capability a role has', array_values(array_diff(array_merge(...array_map([$p, 'capabilitiesForRole'], ['superadmin', 'admin', 'editor', 'user'])), array_keys(PermissionService::catalogue()))), []);
check('junk stored data is ignored', (new PermissionService(['editor' => 'x', 'admin' => null]))->capabilitiesForRole('editor'), $p->defaultsForRole('editor'));

// Roles the super admin made (stored in system_meta as custom_roles).
$mine = new PermissionService(null, [
    'photographer' => ['label' => 'Photographer', 'description' => 'Photos only', 'capabilities' => ['media.manage', 'users.manage', 'roles.manage', 'backups.restore', 'nonsense']],
    'editor' => ['label' => 'Fake editor', 'capabilities' => ['settings.manage']],
    'Bad Key' => ['label' => 'x', 'capabilities' => ['media.manage']],
    'a' => ['label' => 'too short', 'capabilities' => []],
    'translator' => 'not an array',
    'writer' => ['capabilities' => ['content.manage']],
]);
check('custom role: only valid ones are kept', array_keys($mine->customRoles()), ['photographer', 'writer']);
check('custom role: cannot take the name of a built-in role', $mine->isCustomRole('editor'), false);
check('custom role: the built-in role is untouched by a clash', $mine->capabilitiesForRole('editor'), $p->capabilitiesForRole('editor'));
check('custom role: gets what was chosen, plus sign-in and profile', $mine->capabilitiesForRole('photographer'), ['admin.access', 'users.self', 'media.manage']);
check('custom role: never gets what only the super admin has', [$mine->can($u('photographer'), 'users.manage'), $mine->can($u('photographer'), 'roles.manage'), $mine->can($u('photographer'), 'backups.restore')], [false, false, false]);
check('custom role: reaches only its own screens', [$mine->canAccessAction($u('photographer'), 'media'), $mine->canAccessAction($u('photographer'), 'content'), $mine->canAccessAction($u('photographer'), 'settings'), $mine->canAccessAction($u('photographer'), 'roles'), $mine->canAccessAction($u('photographer'), 'users')], [true, false, false, false, false]);
check('custom role: an unnamed role is named from its key', $mine->customRoles()['writer']['label'], 'Writer');
check('custom role: inactive person denied', $mine->can(['id' => 1, 'role' => 'photographer', 'status' => 'inactive'], 'media.manage'), false);
check('custom role: listed after the built-in ones', array_keys($mine->allRoles()), ['superadmin', 'admin', 'editor', 'user', 'photographer', 'writer']);
check('custom role: marked as custom', [$mine->allRoles()['photographer']['custom'], $mine->allRoles()['admin']['custom']], [true, false]);
check('custom role: an unknown role still has nothing', $mine->capabilitiesForRole('wizard'), []);
check('custom role: a built-in role can still be customised alongside', (new PermissionService(['editor' => ['media.manage']], ['photographer' => ['capabilities' => []]]))->capabilitiesForRole('editor'), ['admin.access', 'users.self', 'media.manage']);
check('custom role: more than the limit are ignored', count((new PermissionService(null, array_combine(array_map(fn($n) => "role-$n", range(1, 30)), array_fill(0, 30, ['label' => 'r']))))->customRoles()), PermissionService::MAX_CUSTOM_ROLES);
check('custom role: junk stored data is ignored', (new PermissionService(null, ['x' => 5, 'photographer' => null]))->customRoles(), []);
check('key: valid and invalid', array_map([PermissionService::class, 'validCustomKey'], ['photographer', 'a', 'Photographer', 'has space', 'admin', 'editor', 'ok-2', '9lives', str_repeat('a', 31)]), [true, false, false, false, false, false, true, false, false]);
check('key: from a Greek name', PermissionService::newCustomKey('Φωτογράφος', []), 'fotografos');
check('key: from an English name', PermissionService::newCustomKey('Junior Editor', []), 'junior-editor');
check('key: never a built-in name', PermissionService::newCustomKey('Editor', []), 'editor-2');
check('key: never one that exists', PermissionService::newCustomKey('Writer', ['writer', 'writer-2']), 'writer-3');
check('key: a name that cannot be converted still gets a key', PermissionService::validCustomKey(PermissionService::newCustomKey('日本語', [])), true);
check('key: a name starting with a digit', PermissionService::newCustomKey('24h support', []), 'role-24h-support');
check('key: a very long name is cut', strlen(PermissionService::newCustomKey(str_repeat('abc ', 30), [])) <= 30, true);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
