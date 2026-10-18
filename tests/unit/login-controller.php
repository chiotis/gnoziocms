<?php
/*
 * The addresses of the admin that need no sign-in (LoginController): the login screen, a sign-in with a password (wrong, right, with
 * the shipped password, blocked), signing out, signing in with Google at each step it can stop at, and the first visit to a site
 * with no account. Nothing is drawn or sent: what the controller asks of the site is recorded.
 *   php tests/unit/login-controller.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{Auth, LoginController, LoginThrottle, NotificationRepository, SystemDatabase, UserRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

/** A site with its own folder: the accounts, and a controller whose requests to the site are recorded in $site. */
function site(bool $withUsers, array &$settings, array &$google): array
{
    static $n = 0;
    $_SESSION = [];
    $dir = sys_get_temp_dir() . '/logincontroller' . getmypid() . '-' . (++$n);
    mkdir("$dir/storage/db", 0775, true);
    mkdir("$dir/content/users", 0775, true);
    file_put_contents("$dir/content/users/users.yaml", $withUsers ? "- username: shipped\n  password: 'plain:1234'\n  role: admin\n" : '');
    $db = new SystemDatabase("$dir/storage");
    $db->initialize();
    $users = new UserRepository($db, "$dir/content/users/users.yaml");
    $auth = new Auth("$dir/content/users/users.yaml", $users);
    $notifications = new NotificationRepository($db);
    $site = (object)['drawn' => [], 'went' => [], 'status' => [], 'log' => []];
    $controller = new LoginController(
        $auth,
        new LoginThrottle($db),
        $users,
        $notifications,
        function () use (&$settings): array { return $settings; },
        fn(string $path): string => 'https://s.test' . $path,
        function (string $action, string $level, ?string $type, ?string $id, ?string $message, array $context, ?array $actor) use ($site): void { $site->log[] = [$action, $id]; },
        function (string $template, array $data) use ($site): void { $site->drawn[] = [$template, $data]; },
        function (string $path) use ($site): void { $site->went[] = $path; },
        function (int $status, array $headers) use ($site): void { $site->status[] = [$status, $headers]; },
        function (string $url, array $options, string $kind) use (&$google): array { $google['calls'][] = $kind; return $google[$kind]; }
    );
    return [$controller, $site, $users, $auth, $notifications, $dir];
}
$settings = ['base_url' => 'https://s.test'];
$google = ['calls' => [], 'token' => ['body' => '{"access_token":"tok"}', 'status' => 200, 'error' => ''], 'profile' => ['body' => '{"email":"ada@example.org","sub":"g1","email_verified":true}', 'status' => 200, 'error' => '']];

[$login, $site, $users, $auth, $notifications] = site(true, $settings, $google);
$users->create(['username' => 'ada', 'email' => 'ada@example.org', 'password_hash' => $users->passwordHash('Correct-horse-1'), 'role' => 'admin']);
$users->create(['username' => 'off', 'email' => 'off@example.org', 'password_hash' => $users->passwordHash('Correct-horse-1'), 'role' => 'editor', 'status' => 'inactive']);

// ---- what is not its business
check('an address that needs a sign-in is not answered', [$login->handle('content', 'GET', [], [], '10.0.0.1'), $site->drawn, $site->went], [false, [], []]);

// ---- the login screen
check('the login screen is answered, with nothing to say and Google not ready', [$login->handle('login', 'GET', [], [], '10.0.0.1'), $site->drawn[0][0], $site->drawn[0][1]['error'], $site->drawn[0][1]['google_auth']['ready']], [true, '@admin/login.twig', '', false]);

// ---- a password
$login->handle('login', 'POST', ['username' => ' ada ', 'password' => 'wrong'], [], '10.0.0.1');
check('a wrong password draws the screen again, with the message, and goes nowhere', [end($site->drawn)[1]['error'], $site->went, isset($_SESSION['user'])], ['Invalid credentials.', [], false]);
$login->handle('login', 'POST', ['username' => ' ada ', 'password' => 'Correct-horse-1'], [], '10.0.0.1');
check('the right one signs in (the name trimmed) and goes to the admin, with no warning', [$site->went, $auth->user()['username'], isset($_SESSION['security_default_password']), $notifications->unreadCount()], [['/admin'], 'ada', false, 0]);
check('a signed-in person who asks for the login screen is sent to the admin', [$login->handle('login', 'GET', [], [], '10.0.0.1'), end($site->went)], [true, '/admin']);
$auth->logout();
$login->handle('login', 'POST', ['username' => 'shipped', 'password' => '1234'], [], '10.0.0.3');
$notes = $notifications->recent();
check('the password that ships is flagged, and the person is told to change it', [isset($_SESSION['security_default_password']), count($notes), $notes[0]['type'], $notes[0]['severity'], str_contains($notes[0]['body'], 'shipped still signs in')], [true, 1, 'security.default_password', 'error', true]);
$auth->logout();
$site->drawn = [];
for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ADDRESS; $i++) { $login->handle('login', 'POST', ['username' => 'nobody' . $i, 'password' => 'x'], [], '10.0.0.9'); }
$site->drawn = [];
$site->status = [];
$login->handle('login', 'POST', ['username' => 'ada', 'password' => 'Correct-horse-1'], [], '10.0.0.9');
check('too many failures from one place block it: status 429, how long to wait, and a message', [$site->status[0][0], preg_match('/^Retry-After: \d+$/', $site->status[0][1][0]) === 1, str_starts_with($site->drawn[0][1]['error'], 'Too many failed sign-in attempts'), isset($_SESSION['user'])], [429, true, true, false]);

// ---- signing out
$site->went = [];
$login->handle('logout', 'GET', [], [], '10.0.0.1');
check('signing out by a plain visit goes to the login screen and does nothing else', [$site->went, $auth->check()], [['/admin/login'], false]);
$auth->loginUser($users->findByUsername('ada'));
$login->handle('logout', 'GET', [], [], '10.0.0.1');
check('a signed-in person who only visits the address is not signed out', [end($site->went), $auth->check()], ['/admin', true]);
$login->handle('logout', 'POST', [], [], '10.0.0.1');
check('by the form they are, and it is logged with who', [$auth->check(), end($site->went), end($site->log)], [false, '/admin/login', ['auth.logout', 'ada']]);

// ---- Google
$site->drawn = [];
$login->handle('google-login', 'GET', [], [], '10.0.0.1');
check('Google that is not set up says so', [$site->drawn[0][1]['error'], isset($_SESSION['google_oauth_state'])], ['Google Sign-In is not configured yet.', false]);
$settings['auth']['google'] = ['enabled' => true, 'client_id' => 'cid', 'client_secret' => 'sec', 'allowed_domain' => 'example.org'];
$site->went = [];
$login->handle('google-login', 'GET', [], [], '10.0.0.1');
$state = $_SESSION['google_oauth_state'];
parse_str((string)parse_url($site->went[0], PHP_URL_QUERY), $q);
check('Google that is set up sends the person there, with a state that is kept for the way back', [str_starts_with($site->went[0], 'https://accounts.google.com/'), $q['state'] === $state, strlen($state)], [true, true, 32]);
check('and the login screen offers it', $login->handle('login', 'GET', [], [], '10.0.0.1') && end($site->drawn)[1]['google_auth']['ready'], true);

$back = function (array $get, ?string $keep = 'STATE') use ($login, $site): string {
    $_SESSION['google_oauth_state'] = $keep;
    $site->drawn = [];
    $login->handle('google-callback', 'GET', [], $get, '10.0.0.1');
    return (string)($site->drawn[0][1]['error'] ?? '');
};
check('a way back with the wrong state is refused, and the state is forgotten', [$back(['state' => 'OTHER', 'code' => 'c']), isset($_SESSION['google_oauth_state'])], ['Google Sign-In state could not be verified.', false]);
check('so is one with none', $back(['code' => 'c']), 'Google Sign-In state could not be verified.');
check('one with no code', $back(['state' => 'STATE']), 'Google did not return an authorization code.');
$google['token'] = ['body' => false, 'status' => 0, 'error' => 'timeout'];
check('a token Google does not give is reported', $back(['state' => 'STATE', 'code' => 'c']), 'Google token exchange failed. timeout');
$google['token'] = ['body' => '{"access_token":"tok"}', 'status' => 200, 'error' => ''];
$google['profile'] = ['body' => '', 'status' => 500, 'error' => ''];
check('a profile it does not give', $back(['state' => 'STATE', 'code' => 'c']), 'Google profile lookup failed.');
$google['profile'] = ['body' => '{"email":"ada@other.org","sub":"g1","email_verified":true}', 'status' => 200, 'error' => ''];
check('an account of another domain', $back(['state' => 'STATE', 'code' => 'c']), 'This Google account is not allowed for this GnozioCMS installation.');
$google['profile'] = ['body' => '{"email":"nobody@example.org","sub":"g9","email_verified":true}', 'status' => 200, 'error' => ''];
check('an account no user has', $back(['state' => 'STATE', 'code' => 'c']), 'No active GnozioCMS user matches this Google account.');
$google['profile'] = ['body' => '{"email":"off@example.org","sub":"g8","email_verified":true}', 'status' => 200, 'error' => ''];
check('an account whose user is switched off', [$back(['state' => 'STATE', 'code' => 'c']), $auth->check()], ['No active GnozioCMS user matches this Google account.', false]);
$google['profile'] = ['body' => '{"email":"Ada@Example.org","sub":"g1","email_verified":true}', 'status' => 200, 'error' => ''];
$site->went = [];
$back(['state' => 'STATE', 'code' => 'c']);
$ada = $users->findByUsername('ada');
check('a user the Google account matches is signed in, linked to it, logged, and sent to the admin', [$auth->user()['username'], $ada['google_sub'], $ada['google_email'], end($site->log), $site->went, isset($_SESSION['google_oauth_state'])], ['ada', 'g1', 'ada@example.org', ['auth.login_success', 'ada'], ['/admin'], false]);

// ---- the first visit
$settings2 = [];
[$first, $site2, $users2, $auth2] = site(false, $settings2, $google);
$first->handle('login', 'GET', [], [], '10.0.0.1');
check('a site with no account shows the form for the first one, empty', [$site2->drawn[0][0], $site2->drawn[0][1]['payload'], $site2->drawn[0][1]['error']], ['@admin/setup.twig', ['username' => '', 'display_name' => '', 'email' => ''], '']);
$first->handle('login', 'POST', ['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.org', 'password' => 'short', 'password_confirm' => 'short'], [], '10.0.0.1');
check('a form that is not good draws it again with the reason and what was typed', [end($site2->drawn)[0], end($site2->drawn)[1]['error'] !== '', end($site2->drawn)[1]['payload']['username'], $site2->went, $users2->count()], ['@admin/setup.twig', true, 'ada', [], 0]);
$first->handle('login', 'POST', ['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.org', 'password' => 'Sturdy-pass-99', 'password_confirm' => 'Sturdy-pass-99'], [], '10.0.0.1');
check('one that is good makes the first administrator, signs them in and goes to the admin', [$users2->count(), $site2->went, $auth2->check()], [1, ['/admin'], true]);
$auth2->logout();
$site2->drawn = [];
$first->handle('login', 'GET', [], [], '10.0.0.1');
check('after that the address is the login screen again', $site2->drawn[0][0], '@admin/login.twig');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
