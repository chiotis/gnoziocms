<?php
/*
 * The admin's second factor (audit S9b): the codes (RFC 6238 and RFC 4648 test values), the QR code, setting it up, signing in
 * with a right and a wrong code, a recovery code used once, the requirement by role (Google sign-in left as it is), the limits on
 * wrong codes, and the super admin clearing someone else's. With a moved clock; nothing is drawn or sent.
 *   php tests/unit/second-factor.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{Auth, LoginController, LoginThrottle, NotificationRepository, QrCode, SecondFactor, SecondFactorAdmin, SecretBox, SignIn, SystemDatabase, SystemMetaRepository, Totp, UserRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- the codes
$rfc = Totp::base32Encode('12345678901234567890');
check('base32 of the RFC 6238 key', $rfc, 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
check('RFC 4648 test values', [Totp::base32Encode('f'), Totp::base32Encode('fo'), Totp::base32Encode('foobar'), Totp::base32Decode('MZXW6YTBOI======'), Totp::base32Decode('mzxw 6ytb oi'), Totp::base32Decode('0189')], ['MY', 'MZXQ', 'MZXW6YTBOI', 'foobar', 'foobar', null]);
check('RFC 6238 codes (SHA-1, 8 digits)', [Totp::code($rfc, Totp::step(59), 8), Totp::code($rfc, Totp::step(1111111109), 8), Totp::code($rfc, Totp::step(1234567890), 8), Totp::code($rfc, Totp::step(20000000000), 8)], ['94287082', '07081804', '89005924', '65353130']);
check('and 6 digits, as the apps show', Totp::code($rfc, Totp::step(59)), '287082');
$t = 1_800_000_000;
$code = Totp::code($rfc, Totp::step($t));
check('a code is taken in its step, the one before and the one after, typed with a space', [Totp::verify($rfc, $code, $t), Totp::verify($rfc, $code, $t + 30), Totp::verify($rfc, $code, $t - 30), Totp::verify($rfc, substr($code, 0, 3) . ' ' . substr($code, 3), $t)], [Totp::step($t), Totp::step($t), Totp::step($t), Totp::step($t)]);
check('not two steps away, not once used, not other text', [Totp::verify($rfc, $code, $t + 61), Totp::verify($rfc, $code, $t, Totp::step($t)), Totp::verify($rfc, 'abcdef', $t), Totp::verify($rfc, '', $t)], [null, null, null, null]);
check('a new secret is 20 bytes', [strlen((string)Totp::base32Decode(Totp::newSecret())), Totp::newSecret() !== Totp::newSecret()], [20, true]);
check('the address for the app', Totp::uri('ABC', 'ada', 'My: Site'), 'otpauth://totp/My%20Site:ada?secret=ABC&issuer=My%20Site&algorithm=SHA1&digits=6&period=30');

// ---- the QR code
check('Reed-Solomon of the textbook example (HELLO WORLD, 1-M)', QrCode::reedSolomon([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10), [196, 35, 39, 119, 235, 215, 231, 226, 93, 23]);
$qr = QrCode::encode(Totp::uri(Totp::newSecret(), 'someone.with.a.long.name', 'A site with a long title'));
$finder = static fn(QrCode $q, int $x0, int $y0): bool => $q->dark($x0, $y0) && $q->dark($x0 + 6, $y0 + 6) && !$q->dark($x0 + 1, $y0 + 1) && $q->dark($x0 + 3, $y0 + 3);
check('an otpauth address fits, with its three finder patterns and the dark module', [$qr->version() <= 10, $qr->size() === $qr->version() * 4 + 17, $finder($qr, 0, 0), $finder($qr, $qr->size() - 7, 0), $finder($qr, 0, $qr->size() - 7), $qr->dark(8, $qr->size() - 8)], [true, true, true, true, true, true]);
check('the app shows the domain, not the title', [SecondFactorAdmin::issuerFor('https://www.Example.gr/site', 'other.test'), SecondFactorAdmin::issuerFor('', 'ελληνικά.test:8080'), SecondFactorAdmin::issuerFor('', 'shop.test:8080'), SecondFactorAdmin::issuerFor('', '')], ['example.gr', 'ελληνικά.test', 'shop.test', 'GnozioCMS']);
check('a long Greek title no longer matters, a domain always fits', QrCode::encode(Totp::uri(Totp::newSecret(), 'someone.with.a.long.name', SecondFactorAdmin::issuerFor('https://www.a-rather-long-domain-name.gr', '')))->version() <= 10, true);
check('as one SVG with a white ground', [str_starts_with($qr->svg(), '<svg'), substr_count($qr->svg(), '<path'), str_contains($qr->svg(), 'fill="#fff"')], [true, 1, true]);
$threw = false;
try { QrCode::encode(str_repeat('x', 300)); } catch (\LengthException) { $threw = true; }
check('a text too long is refused', $threw, true);

// ---- a site
$dir = sys_get_temp_dir() . '/secondfactor' . getmypid();
mkdir("$dir/storage/db", 0775, true);
mkdir("$dir/content/users", 0775, true);
file_put_contents("$dir/content/users/users.yaml", '');
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$users = new UserRepository($db, "$dir/content/users/users.yaml");
$auth = new Auth("$dir/content/users/users.yaml", $users);
$box = new SecretBox("$dir/storage/key");
$factor = new SecondFactor($db, $box, $meta);
$now = $t;
$factor->setClock(function () use (&$now): int { return $now; });
$ada = $users->create(['username' => 'ada', 'email' => 'ada@example.org', 'password_hash' => $users->passwordHash('Correct-horse-1'), 'role' => 'admin']);
$boss = $users->create(['username' => 'boss', 'email' => 'boss@example.org', 'password_hash' => $users->passwordHash('Correct-horse-1'), 'role' => 'superadmin']);
$ed = $users->create(['username' => 'ed', 'email' => 'ed@example.org', 'password_hash' => $users->passwordHash('Correct-horse-1'), 'role' => 'editor']);
$log = [];
$logger = function (string $action, string $level, ?string $type, ?string $id, ?string $message = '', array $context = [], ?array $actor = null) use (&$log): void { $log[] = [$action, $id]; };
$site = (object)['drawn' => [], 'went' => [], 'status' => []];
$notifications = new NotificationRepository($db);
$login = new LoginController(
    $auth, new LoginThrottle($db), $users, $notifications,
    fn(): array => [], fn(string $p): string => 'https://s.test' . $p, $logger,
    function (string $template, array $data) use ($site): void { $site->drawn[] = [$template, $data]; },
    function (string $path) use ($site): void { $site->went[] = $path; },
    function (int $status, array $headers) use ($site): void { $site->status[] = [$status, $headers]; },
    null, $factor
);
$admin = new SecondFactorAdmin($factor, $users, $logger, 'Test site');
$codeAt = function (string $secret, int $at): string { return Totp::code($secret, Totp::step($at)); };

// ---- setting it up (from the profile)
$_SESSION = [];
$auth->loginUser($users->find($ada));
$screen = $admin->screen($auth->user(), [], false);
$secret = (string)$_SESSION[SecondFactorAdmin::SETUP];
check('the screen offers a QR code and the key, in groups of four', [$screen['status']['enabled'], str_starts_with($screen['setup']['qr'], '<svg'), str_replace(' ', '', $screen['setup']['key']) === $secret, str_contains($screen['setup']['uri'], 'Test%20site:ada')], [false, true, true, true]);
check('the same key while it is being set up', $admin->screen($auth->user(), [], false)['setup']['key'], $screen['setup']['key']);
check('a wrong code does not turn it on', [$admin->apply($auth->user(), ['action' => 'enable', 'code' => '000000']), $factor->enabled($ada)], ['/admin/second-factor?error=code', false]);
check('the right one does, and is logged', [$admin->apply($auth->user(), ['action' => 'enable', 'code' => $codeAt($secret, $now)]), $factor->enabled($ada), end($log)], ['/admin/second-factor?on=1', true, ['users.second_factor_on', (string)$ada]]);
$shown = $admin->screen($auth->user(), ['on' => '1'], false);
$codes = $shown['recovery_codes'];
check('ten recovery codes are shown once', [count($codes), preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes[0]), $admin->screen($auth->user(), [], false)['recovery_codes'], $shown['status']['recovery_left']], [10, 1, [], 10]);
$stored = $db->connection()->query('SELECT secret, recovery FROM user_second_factors WHERE user_id = ' . $ada)->fetch();
check('kept sealed, and the recovery codes only as hashes', [SecretBox::isSealed($stored['secret']), str_contains($stored['recovery'], $codes[0]), str_contains($stored['recovery'], str_replace('-', '', $codes[0]))], [true, false, false]);
$auth->logout();

// ---- signing in
$_SESSION = [];
$site->drawn = [];
$login->handle('login', 'POST', ['username' => 'ada', 'password' => 'Correct-horse-1'], [], '10.0.0.1');
check('a right password asks for the code and signs nobody in yet', [$auth->check(), end($site->drawn)[1]['step'] ?? '', $site->went, end($log)], [false, 'code', [], ['auth.second_factor_asked', 'ada']]);
$login->handle('login', 'GET', [], [], '10.0.0.1');
check('the login page keeps asking for it', end($site->drawn)[1]['step'] ?? '', 'code');
$now += 30;
$login->handle('login', 'POST', ['step' => 'code', 'code' => '123456'], [], '10.0.0.1');
check('a wrong code: asked again, with a message, logged', [$auth->check(), end($site->drawn)[1]['step'] ?? '', str_contains(end($site->drawn)[1]['error'], 'not right'), end($log)], [false, 'code', true, ['auth.second_factor_failure', 'ada']]);
$good = $codeAt($secret, $now);
$login->handle('login', 'POST', ['step' => 'code', 'code' => $good], [], '10.0.0.1');
check('the right code signs in, by password, and goes to the admin', [$auth->user()['username'] ?? '', $auth->method(), $site->went, isset($_SESSION[SignIn::PENDING]), end($log)], ['ada', 'password', ['/admin'], false, ['auth.login_success', 'ada']]);
$auth->logout();
$login->handle('login', 'POST', ['username' => 'ada', 'password' => 'Correct-horse-1'], [], '10.0.0.1');
$login->handle('login', 'POST', ['step' => 'code', 'code' => $good], [], '10.0.0.1');
check('the same code is not taken a second time', $auth->check(), false);

$login->handle('login', 'POST', ['step' => 'code', 'code' => strtoupper($codes[0])], [], '10.0.0.1');
$note = $notifications->recent()[0] ?? [];
check('a recovery code signs in (upper case is fine), and the person is told how many are left', [$auth->check(), $factor->status($ada)['recovery_left'], $note['type'] ?? '', str_contains((string)($note['body'] ?? ''), '9 left')], [true, 9, 'security.recovery_code_used', true]);
$auth->logout();
$login->handle('login', 'POST', ['username' => 'ada', 'password' => 'Correct-horse-1'], [], '10.0.0.1');
$login->handle('login', 'POST', ['step' => 'code', 'code' => $codes[0]], [], '10.0.0.1');
check('a used recovery code works no more', $auth->check(), false);

$now += SignIn::PENDING_SECONDS + 1;
$site->drawn = [];
$login->handle('login', 'POST', ['step' => 'code', 'code' => $codeAt($secret, $now)], [], '10.0.0.1');
check('after five minutes the password has to be given again', [$auth->check(), $site->drawn[0][1]['step'] ?? '', $site->drawn[0][1]['error']], [false, '', 'That took too long. Sign in again.']);
$login->handle('login', 'POST', ['username' => 'ada', 'password' => 'Correct-horse-1'], [], '10.0.0.1');
$site->drawn = [];
$login->handle('login', 'GET', [], ['restart' => '1'], '10.0.0.1');
check('"start again" goes back to the password', [$site->drawn[0][1]['step'] ?? '', isset($_SESSION[SignIn::PENDING])], ['', false]);

// ---- the limits: wrong codes count like wrong passwords, and a right password does not wipe them
$_SESSION = [];
$site->status = [];
for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ADDRESS; $i++) {
    $login->handle('login', 'POST', ['username' => 'ada', 'password' => 'Correct-horse-1'], [], '10.0.0.5');
    $login->handle('login', 'POST', ['step' => 'code', 'code' => '000000'], [], '10.0.0.5');
}
$site->drawn = [];
$login->handle('login', 'POST', ['username' => 'ada', 'password' => 'Correct-horse-1'], [], '10.0.0.5');
check('after eight wrong codes even the right password is blocked: 429', [$site->status[0][0] ?? 0, str_starts_with($site->drawn[0][1]['error'], 'Too many failed sign-in attempts'), $auth->check()], [429, true, false]);
$now += LoginThrottle::WINDOW_SECONDS + 1;

// ---- turning it off, new codes, a key this server cannot open
$_SESSION = [];
$auth->loginUser($users->find($ada));
$now += 60;
check('new recovery codes need a code', [$admin->apply($auth->user(), ['action' => 'renew', 'code' => '000000']), $admin->apply($auth->user(), ['action' => 'renew', 'code' => $codeAt($secret, $now)])], ['/admin/second-factor?error=code', '/admin/second-factor?renewed=1']);
$renewed = $admin->screen($auth->user(), [], false)['recovery_codes'];
check('ten new ones, the old ones gone', [count($renewed), $factor->status($ada)['recovery_left'], $factor->verify($ada, $codes[1])], [10, 10, '']);
copy("$dir/storage/key", "$dir/key.kept");
unlink("$dir/storage/key");
$lostFactor = new SecondFactor($db, new SecretBox("$dir/storage/key"), $meta);
$lostFactor->setClock(function () use (&$now): int { return $now; });
$now += 60;
check('on a server without the key: it says so, the app code fails, a recovery code still works', [$lostFactor->status($ada)['lost'], $lostFactor->verify($ada, $codeAt($secret, $now)), $lostFactor->verify($ada, $renewed[0])], [true, '', 'recovery']);
rename("$dir/key.kept", "$dir/storage/key");
$now += 60;
check('turning it off needs a code too', [$admin->apply($auth->user(), ['action' => 'disable', 'code' => '000000']), $admin->apply($auth->user(), ['action' => 'disable', 'code' => $codeAt($secret, $now)]), $factor->enabled($ada), end($log)], ['/admin/second-factor?error=code', '/admin/second-factor?off=1', false, ['users.second_factor_off', (string)$ada]]);
$auth->logout();

// ---- the requirement
$factor->setRequiredRoles(['admin', 'superadmin']);
check('required roles are kept', [$factor->requiredRoles(), $factor->requiredFor($users->find($ada)), $factor->requiredFor($users->find($ed))], [['admin', 'superadmin'], true, false]);
check('a required person without one, by password, must set it up; by Google not; another role not', [$factor->mustSetUp($users->find($ada), 'password'), $factor->mustSetUp($users->find($ada), ''), $factor->mustSetUp($users->find($ada), 'google'), $factor->mustSetUp($users->find($ed), 'password')], [true, true, false, false]);
$_SESSION = [];
$auth->loginUser($users->find($ada));
$screen = $admin->screen($auth->user(), [], true);
$secret = (string)$_SESSION[SecondFactorAdmin::SETUP];
check('the screen says why', [$screen['must_set_up'], $screen['required']], [true, true]);
$now += 60;
$admin->apply($auth->user(), ['action' => 'enable', 'code' => $codeAt($secret, $now)]);
check('once set up, nothing more is asked', $factor->mustSetUp($users->find($ada), 'password'), false);
$now += 60;
check('and a required one cannot be turned off', [$admin->apply($auth->user(), ['action' => 'disable', 'code' => $codeAt($secret, $now)]), $factor->enabled($ada)], ['/admin/second-factor?error=required', true]);

// ---- clearing someone else's
check('an admin cannot clear another person\'s', [$admin->apply($users->publicUser($users->find($ada)), ['action' => 'clear', 'id' => (string)$boss]), $admin->apply($auth->user(), ['action' => 'clear', 'id' => (string)$ada])], ['/admin/second-factor?error=forbidden', '/admin/second-factor?error=forbidden']);
$log = [];
check('the super admin can, and it is logged with who', [$admin->apply($users->publicUser($users->find($boss)), ['action' => 'clear', 'id' => (string)$ada]), $factor->enabled($ada), $log], ['/admin/users-edit?id=' . $ada . '&second_factor_cleared=1', false, [['users.second_factor_cleared', (string)$ada]]]);
check('but not their own that way', $admin->apply($users->publicUser($users->find($boss)), ['action' => 'clear', 'id' => (string)$boss]), '/admin/second-factor?error=forbidden');

// ---- someone without a second factor signs in as before
$_SESSION = [];
$site->went = [];
$login->handle('login', 'POST', ['username' => 'ed', 'password' => 'Correct-horse-1'], [], '10.0.0.8');
check('an account without one is signed in by the password, as before', [$auth->user()['username'] ?? '', $auth->method(), $site->went], ['ed', 'password', ['/admin']]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
