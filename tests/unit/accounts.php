<?php
/*
 * Signing in (password and Google), adding and changing users, and the roles screen's actions.
 *   php tests/unit/accounts.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{Auth, GoogleSignIn, LoginThrottle, PermissionService, RoleAdmin, SignIn, SystemDatabase, SystemMetaRepository, UserAdmin, UserRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$_SESSION = [];
$dir = sys_get_temp_dir() . '/accounts' . getmypid();
mkdir("$dir/storage/db", 0775, true);
mkdir("$dir/content/users", 0775, true);
file_put_contents("$dir/content/users/users.yaml", "- username: shipped\n  password: 'plain:1234'\n  role: admin\n");
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$log = [];
$logger = function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx, ?array $actor = null) use (&$log) { $log[] = [$action, $id, $actor]; };

// ---- Google
$settings = ['base_url' => 'https://s.test', 'auth' => ['google' => ['enabled' => true, 'client_id' => ' cid ', 'client_secret' => 'sec', 'allowed_domain' => ' EXAMPLE.org ']]];
$calls = [];
$reply = ['token' => ['body' => '{"access_token":"tok123"}', 'status' => 200, 'error' => ''], 'profile' => ['body' => '{"email":"Ada@Example.org","sub":"g1","email_verified":true}', 'status' => 200, 'error' => '']];
$g = new GoogleSignIn(function () use (&$settings) { return $settings; }, fn(string $p) => 'https://s.test' . $p, function (string $url, array $opts, string $kind) use (&$calls, &$reply) { $calls[] = [$kind, $url, $opts]; return $reply[$kind]; });
$cfg = $g->config();
check('the settings are read and cleaned', [$cfg['client_id'], $cfg['allowed_domain'], $cfg['redirect_uri'], $cfg['ready']], ['cid', 'example.org', 'https://s.test/admin/google-callback', true]);
$settings['auth']['google']['client_secret'] = '';
check('without a secret it is not ready', $g->config()['ready'], false);
$settings['auth']['google']['client_secret'] = 'sec';
$settings['auth']['google']['enabled'] = false;
check('switched off it is not ready', $g->config()['ready'], false);
$settings['auth']['google']['enabled'] = true;
$settings = ['base_url' => 'https://s.test'] ;
check('no settings at all is not ready', $g->config()['ready'], false);
$settings = ['auth' => ['google' => ['enabled' => '1', 'client_id' => 'cid', 'client_secret' => 'sec']]];
check('a setting written as text still counts', [$g->config()['ready'], $g->config()['allowed_domain']], [true, '']);
$cfg = $g->config();
$url = $g->authorizationUrl($cfg, 'STATE');
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
check('the address sends the person to Google with the state', [str_starts_with($url, 'https://accounts.google.com/o/oauth2/v2/auth?'), $q['client_id'], $q['state'], $q['scope'], $q['redirect_uri'], $q['response_type'], $q['prompt']], [true, 'cid', 'STATE', 'openid email profile', 'https://s.test/admin/google-callback', 'code', 'select_account']);
$t = $g->exchange('CODE', $cfg);
check('the code is exchanged for a token', [$t, $calls[0][0], $calls[0][1]], [['ok' => true, 'access_token' => 'tok123'], 'token', 'https://oauth2.googleapis.com/token']);
parse_str($calls[0][2][CURLOPT_POSTFIELDS], $post);
check('with the client and the code sent', [$post['code'], $post['client_id'], $post['client_secret'], $post['grant_type']], ['CODE', 'cid', 'sec', 'authorization_code']);
$reply['token'] = ['body' => false, 'status' => 0, 'error' => 'timeout'];
check('a failed call says why', $g->exchange('C', $cfg), ['ok' => false, 'message' => 'Google token exchange failed. timeout']);
$reply['token'] = ['body' => '{"error":"x"}', 'status' => 400, 'error' => ''];
check('a refusal is a failure', $g->exchange('C', $cfg)['ok'], false);
$reply['token'] = ['body' => '{"nothing":1}', 'status' => 200, 'error' => ''];
check('an answer without a token is invalid', $g->exchange('C', $cfg), ['ok' => false, 'message' => 'Google token response was invalid.']);
$p = $g->profile('tok123');
check('the profile is fetched with the token', [$p['ok'], $p['sub'], $calls[array_key_last($calls)][2][CURLOPT_HTTPHEADER]], [true, 'g1', ['Authorization: Bearer tok123']]);
$reply['profile'] = ['body' => 'nope', 'status' => 200, 'error' => ''];
check('a profile that is not JSON is invalid', $g->profile('t'), ['ok' => false, 'message' => 'Google profile response was invalid.']);
$reply['profile'] = ['body' => '', 'status' => 500, 'error' => ''];
check('a failed lookup is reported', $g->profile('t'), ['ok' => false, 'message' => 'Google profile lookup failed.']);
$withDomain = $cfg;
$withDomain['allowed_domain'] = 'example.org';
check('a verified email from the domain is accepted, in lower case', $g->accept(['email' => ' Ada@Example.org ', 'email_verified' => true, 'sub' => ' g1 '], $withDomain), ['ok' => true, 'message' => '', 'email' => 'ada@example.org', 'sub' => 'g1']);
check('an unverified email is refused', $g->accept(['email' => 'a@example.org', 'email_verified' => false], $withDomain)['message'], 'Google account email is not verified.');
check('so is a missing one', $g->accept([], $withDomain)['ok'], false);
check('another domain is refused', $g->accept(['email' => 'a@other.org', 'email_verified' => true], $withDomain)['message'], 'This Google account is not allowed for this GnozioCMS installation.');
check('a look-alike domain is refused', $g->accept(['email' => 'a@notexample.org', 'email_verified' => true], $withDomain)['ok'], false);
check('without a domain rule any verified account is accepted', $g->accept(['email' => 'a@other.org', 'email_verified' => true], $cfg)['ok'], true);

// ---- passwords
$users = new UserRepository($db, "$dir/content/users/users.yaml");
$users->create(['username' => 'ada', 'email' => 'ada@x.test', 'password_hash' => $users->passwordHash('Correct-horse-1'), 'role' => 'admin']);
$auth = new Auth("$dir/content/users/users.yaml", $users);
$throttle = new LoginThrottle($db);
$in = new SignIn($auth, $throttle, $logger);
$r = $in->password('ada', 'wrong', '10.0.0.1');
check('a wrong password fails with the stock message, and is logged with who tried', [$r['status'], $r['message'], $log[0][0], $log[0][1], $log[0][2]], ['failed', 'Invalid credentials.', 'auth.login_failure', 'ada', ['username' => 'ada']]);
$r = $in->password('ada', 'Correct-horse-1', '10.0.0.1');
check('the right one signs in, is logged, and is not flagged', [$r['status'], $r['username'], $r['default_password'], end($log)[0], isset($_SESSION['user'])], ['ok', 'ada', false, 'auth.login_success', true]);
check('a user that does not exist fails the same way', $in->password('ghost', 'x', '10.0.0.2')['message'], 'Invalid credentials.');
$r = $in->password('shipped', '1234', '10.0.0.3');
check('the shipped password works but is flagged', [$r['status'], $r['default_password']], ['ok', true]);
for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ADDRESS; $i++) { $throttle->recordFailure('10.0.0.9', 'nobody' . $i); }
$r = $in->password('ada', 'Correct-horse-1', '10.0.0.9');
check('after too many failures from one place even the right password is blocked', [$r['status'], str_starts_with($r['message'], 'Too many failed sign-in attempts. Try again in '), $r['retry_after'] > 0, end($log)[0]], ['blocked', true, true, 'auth.login_throttled']);

// ---- users
$perm = new PermissionService(null, null);
$admin = $users->find($users->findByUsername('ada')['id']);
$actorAdmin = ['id' => 999, 'role' => 'superadmin'];
$ua = new UserAdmin($users, $auth, $perm, $logger);
check('a blank user starts as an active editor', [UserAdmin::blank()['role'], UserAdmin::blank()['status'], UserAdmin::blank()['id']], ['editor', 'active', 0]);
$new = ['username' => ' bob ', 'email' => 'bob@x.test', 'display_name' => 'Bob', 'role' => 'editor', 'status' => 'active', 'password' => 'Sturdy-pass-99', 'password_confirm' => 'Sturdy-pass-99'];
$r = $ua->save(0, null, $actorAdmin, $new);
check('a new user is created', [$r['ok'], $r['id'] > 0, $users->findByUsername('bob')['email'], $users->findByUsername('bob')['role'], end($log)[0]], [true, true, 'bob@x.test', 'editor', 'users.create']);
check('with a password that works', password_verify('Sturdy-pass-99', $users->findByUsername('bob')['password_hash']), true);
foreach ([
    'no name' => [['username' => ''] + $new, 'Username is required.'],
    'no password for a new user' => [['password' => '', 'password_confirm' => ''] + $new, 'Password is required for new users.'],
    'a password that does not match' => [['password_confirm' => 'other'] + $new, 'Password confirmation does not match.'],
    'a short password' => [['password' => 'short', 'password_confirm' => 'short'] + $new, 'Passwords must be at least 8 characters long.'],
    'the shipped password' => [['username' => 'shipped', 'password' => '1234', 'password_confirm' => '1234'] + $new, 'Passwords must be at least 8 characters long.'],
    'a duplicate name' => [['username' => 'ada'] + $new, 'User could not be saved. Check for duplicate usernames or invalid values.'],
] as $label => [$form, $message]) {
    $r = $ua->save(0, null, $actorAdmin, $form);
    check($label . ' is refused with its own message', [$r['ok'], $r['error']], [false, $message]);
}
$longShipped = ['username' => 'shipped', 'password' => '12345678', 'password_confirm' => '12345678'] + $new;
file_put_contents("$dir/content/users/users.yaml", "- username: shipped\n  password: 'plain:12345678'\n  role: admin\n");
check('the password shipped with the CMS is refused even when long enough', $ua->save(0, null, $actorAdmin, $longShipped)['error'], 'Choose a password other than the one shipped with GnozioCMS.');
check('what was typed comes back to show again', $ua->save(0, null, $actorAdmin, ['username' => 'bob'] + $new)['payload']['email'], 'bob@x.test');
$bobId = (int)$users->findByUsername('bob')['id'];
$bob = $users->find($bobId);
$r = $ua->save($bobId, $bob, $actorAdmin, ['display_name' => 'Robert', 'role' => 'admin', 'status' => 'inactive', 'password' => '', 'password_confirm' => ''] + $new);
check('changing a user without a password keeps the password', [$r['ok'], $r['password_changed'], $users->find($bobId)['display_name'], $users->find($bobId)['role'], $users->find($bobId)['status'], password_verify('Sturdy-pass-99', $users->find($bobId)['password_hash'])], [true, false, 'Robert', 'admin', 'inactive', true]);
$r = $ua->save($bobId, $users->find($bobId), $actorAdmin, ['password' => 'Another-pass-1', 'password_confirm' => 'Another-pass-1'] + $new);
check('a new password is stored and reported', [$r['password_changed'], password_verify('Another-pass-1', $users->find($bobId)['password_hash'])], [true, true]);
$self = ['id' => $bobId, 'role' => 'editor'];
check('a person without the right to manage users may edit themself', $perm->canEditUser($self, $bobId), $perm->can($self, 'users.self'));
$r = $ua->save($bobId, $users->find($bobId), ['id' => $bobId, 'role' => 'admin'], ['role' => 'superadmin', 'status' => 'inactive', 'google_sub' => 'hack', 'google_email' => 'h@x.test'] + $new);
check('nobody changes their own role or status', [$users->find($bobId)['role'], $users->find($bobId)['status']], ['editor', 'active']);
$limited = ['id' => $bobId, 'role' => 'user'];
$ua->save($bobId, $users->find($bobId), $limited, ['role' => 'superadmin', 'google_sub' => 'hack', 'google_email' => 'h@x.test', 'password' => '', 'password_confirm' => ''] + $new);
check('a person who may not manage users cannot change access or the Google link', [$users->find($bobId)['role'], $users->find($bobId)['google_sub'] ?? ''], ['editor', '']);
$ua->save($bobId, $users->find($bobId), $actorAdmin, ['google_sub' => 'gsub', 'google_email' => 'g@x.test', 'password' => '', 'password_confirm' => ''] + $new);
check('one who may can link a Google account', $users->find($bobId)['google_sub'], 'gsub');

// ---- roles
$meta = new SystemMetaRepository($db);
$roles = new RoleAdmin($meta, $users, $logger);
$root = ['id' => 1, 'role' => 'superadmin'];
check('the store must be there', (new RoleAdmin(new SystemMetaRepository(new SystemDatabase('/nonexistent/' . getmypid())), $users, $logger))->apply($perm, ['action' => 'save']), '/admin/roles?error=store');
check('a role needs a name', $roles->apply($perm, ['action' => 'create_role', 'label' => '   ']), '/admin/roles?error=label');
$where = $roles->apply($perm, ['action' => 'create_role', 'label' => ' Shop  Manager ', 'description' => ' Sells ', 'from' => 'editor']);
check('a role is created and named in the address', str_starts_with($where, '/admin/roles?created='), true);
$key = urldecode(substr($where, strlen('/admin/roles?created=')));
$perm = new PermissionService($meta->getJson('role_permissions'), $meta->getJson('custom_roles'));
$custom = $perm->customRoles();
check('with its label tidied, its text, and the editor\'s permissions to start with', [$custom[$key]['label'], $custom[$key]['description'], $custom[$key]['capabilities'] === $perm->normalizeCapabilities($perm->capabilitiesForRole('editor'))], ['Shop Manager', 'Sells', true]);
check('the creation is logged with where it was copied from', [end($log)[0], end($log)[1]], ['roles.create', $key]);
check('renaming keeps the old name when the new one is empty', [$roles->apply($perm, ['action' => 'update_role', 'role' => $key, 'label' => '', 'description' => 'New text']), $meta->getJson('custom_roles')[$key]['label'], $meta->getJson('custom_roles')[$key]['description']], ['/admin/roles?saved=1', 'Shop Manager', 'New text']);
check('an unknown role cannot be changed or deleted', [$roles->apply($perm, ['action' => 'update_role', 'role' => 'nothing']), $roles->apply($perm, ['action' => 'delete_role', 'role' => 'admin'])], ['/admin/roles?error=unknown', '/admin/roles?error=unknown']);
$users->allowRoles([$key]);
$users->update($bobId, ['role' => $key]);
check('a role someone holds cannot be deleted', $roles->apply($perm, ['action' => 'delete_role', 'role' => $key]), '/admin/roles?error=in_use&role=' . urlencode($key));
$users->update($bobId, ['role' => 'user']);
check('one nobody holds can', [$roles->apply($perm, ['action' => 'delete_role', 'role' => $key]), $meta->getJson('custom_roles')], ['/admin/roles?deleted=1', []]);
for ($i = 0; $i < PermissionService::MAX_CUSTOM_ROLES; $i++) { $roles->apply(new PermissionService($meta->getJson('role_permissions'), $meta->getJson('custom_roles')), ['action' => 'create_role', 'label' => 'Role ' . $i, 'from' => 'blank']); }
check('there is a limit on custom roles', $roles->apply(new PermissionService($meta->getJson('role_permissions'), $meta->getJson('custom_roles')), ['action' => 'create_role', 'label' => 'One too many']), '/admin/roles?error=limit');
$meta->setJson('custom_roles', []);

// permissions
$perm = new PermissionService(null, null);
$before = $perm->capabilitiesForRole('editor');
$cap = array_values(array_diff(PermissionService::grantable(), $before))[0] ?? null;
$form = ['in_form' => ['admin', 'editor', 'user'], 'caps' => []];
foreach (['admin', 'editor', 'user'] as $role) { $form['caps'][$role] = array_fill_keys($perm->capabilitiesForRole($role), '1'); }
$form['caps']['editor'][$cap] = '1';
check('a change to the table is saved', [$roles->apply($perm, $form), in_array($cap, (new PermissionService($meta->getJson('role_permissions'), null))->capabilitiesForRole('editor'), true)], ['/admin/roles?saved=1', true]);
$changes = array_values(array_filter($log, fn($l) => $l[0] === 'roles.update' && $l[1] === 'editor'));
check('and logged for the role it changed', count($changes) >= 1, true);
$perm = new PermissionService($meta->getJson('role_permissions'), null);
$roles->apply($perm, ['action' => 'reset', 'role' => 'editor']);
check('resetting a built-in role gives back its defaults', (new PermissionService($meta->getJson('role_permissions'), null))->capabilitiesForRole('editor'), $before);
$formOnlyAdmin = ['in_form' => ['admin'], 'caps' => ['admin' => array_fill_keys($perm->capabilitiesForRole('admin'), '1')]];
$roles->apply(new PermissionService($meta->getJson('role_permissions'), null), $formOnlyAdmin);
check('a role not on the form keeps what it has', (new PermissionService($meta->getJson('role_permissions'), null))->capabilitiesForRole('editor'), $before);
$screen = $roles->screen(new PermissionService($meta->getJson('role_permissions'), $meta->getJson('custom_roles')));
check('the screen has a column per built-in role with its people', [array_keys($screen['columns']), $screen['columns']['admin']['users'], $screen['can_add_role'], $screen['custom_roles']], [['admin', 'editor', 'user'], 1, true, []]);
check('permissions are grouped, and the super admin has all', [count($screen['groups']) > 1, count($screen['super_caps']) > 10], [true, true]);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
