<?php
/*
 * The first visit to a site with no accounts: the first administrator is made, only once, with a password that is
 * not the demo's.
 *   php tests/unit/first-admin.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{Auth, FirstAdmin, SystemDatabase, UserRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/firstadm' . getmypid();
mkdir("$dir/storage", 0775, true);
mkdir("$dir/content/users", 0775, true);
file_put_contents("$dir/content/users/users.yaml", "- username: demo\n  password: \"plain:demo-pass\"\n  role: admin\n");
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$users = new UserRepository($db, "$dir/content/users/users.yaml");
$auth = new Auth("$dir/content/users/users.yaml", $users);
$_SESSION = [];
$log = [];
$first = new FirstAdmin($users, $auth, function (string $a, string $l, ?string $t, ?string $i, string $m, array $c) use (&$log) { $log[] = [$a, $c]; });
$form = ['username' => 'ada', 'display_name' => 'Ada L', 'email' => 'Ada@Example.test', 'password' => 'Sturdy-pass-99', 'password_confirm' => 'Sturdy-pass-99'];

check('a site with no account needs one', $first->needed(), true);
$cases = [
    'a username that is too short' => ['username' => 'a!'] + $form,
    'no username' => ['username' => ''] + $form,
    'an email that is not one' => ['email' => 'nope'] + $form,
    'no email' => ['email' => ''] + $form,
    'a short password' => ['password' => 'short', 'password_confirm' => 'short'] + $form,
    'passwords that differ' => ['password_confirm' => 'Other-pass-99'] + $form,
    'the password the demo ships with' => ['username' => 'demo', 'password' => 'demo-pass', 'password_confirm' => 'demo-pass'] + $form,
];
foreach ($cases as $label => $post) {
    $r = $first->create($post);
    check("$label is refused, and nothing is made", [$r['ok'], $r['error'] !== '', $users->count(), $_SESSION], [false, true, 0, []]);
}
$r = $first->create(['username' => 'a d/a', 'display_name' => ' Ada ', 'email' => 'x', 'password' => '12345678', 'password_confirm' => '12345678']);
check('what was typed is given back after a mistake, the username cleaned, no password among it', [$r['payload']['username'], $r['payload']['display_name'], array_keys($r['payload'])], ['ada', 'Ada', ['username', 'display_name', 'email']]);

$r = $first->create($form);
check('the administrator is made', [$r['ok'], $r['error'], $users->count()], [true, '', 1]);
$u = $users->findByUsername('ada');
check('as a super admin, active, with the email in lower case and the name given', [$u['role'], $u['status'], $u['email'], $u['display_name'], $u['source']], ['superadmin', 'active', 'ada@example.test', 'Ada L', 'setup']);
check('the password is stored hashed and works', [str_contains($u['password_hash'], 'Sturdy'), password_verify('Sturdy-pass-99', $u['password_hash'])], [false, true]);
check('and they are signed in', [$_SESSION['user']['username'] ?? '', isset($_SESSION['user']['password_hash'])], ['ada', false]);
check('it is logged', [$log[0][0], $log[0][1]], ['setup.first_admin', ['username' => 'ada']]);
check('and now the first visit is over: nothing more is needed', $first->needed(), false);
$r = $first->create(['username' => 'mallory', 'email' => 'm@x.test', 'password' => 'Another-pass-1', 'password_confirm' => 'Another-pass-1']);
check('a second account cannot be made this way', [$r['ok'], $r['error'], $users->count()], [false, 'This site already has an account. Sign in instead.', 1]);
$noName = new FirstAdmin(new UserRepository(new SystemDatabase("$dir/none"), "$dir/x.yaml"), $auth, fn() => null);
check('a site whose database cannot be used does not offer it', $noName->needed(), false);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
