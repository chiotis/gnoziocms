<?php
/*
 * Clears one person's second factor (docs/security.md, *Second factor*), for someone with access to the server when nobody who can
 * sign in can do it in the admin: the super admin lost their phone and their recovery codes. The person signs in with the password
 * only, and is asked to set it up again if their role requires one. It is written to the activity log.
 *
 *   php scripts/clear-second-factor.php <username>
 */
require __DIR__ . '/../vendor/autoload.php';

use GnozioCMS\{ActivityLogRepository, SecondFactor, SecretBox, SystemDatabase, SystemMetaRepository, UserRepository};

$root = dirname(__DIR__);
$username = (string)($argv[1] ?? '');
if ($username === '') {
    fwrite(STDERR, "Usage: php scripts/clear-second-factor.php <username>\n");
    exit(2);
}
$database = new SystemDatabase($root . '/storage');
$database->initialize();
if (!$database->isAvailable()) {
    fwrite(STDERR, "The system database (storage/db) is not available.\n");
    exit(1);
}
$users = new UserRepository($database, $root . '/content/users/users.yaml');
$user = $users->findByUsername($username);
if (!$user) {
    fwrite(STDERR, "No account is called $username.\n");
    exit(1);
}
$factor = new SecondFactor($database, new SecretBox($root . '/storage/key'), new SystemMetaRepository($database));
if (!$factor->enabled((int)$user['id'])) {
    echo "$username has no second factor.\n";
    exit(0);
}
$factor->disable((int)$user['id']);
try {
    (new ActivityLogRepository($database))->record(['action' => 'users.second_factor_cleared', 'level' => 'warning', 'actor_username' => 'command line', 'subject_type' => 'user', 'subject_id' => (string)$user['id'], 'message' => 'Second factor cleared from the command line.', 'context' => ['username' => $username]]);
} catch (\Throwable) {
    // The log is for the record; the clearing is done.
}
echo "The second factor of $username is cleared. They sign in with the password only until they set it up again.\n";
