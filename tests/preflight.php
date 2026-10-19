<?php
// The environment check of tests/run.sh: prints one line per problem with the PHP that runs the tests and exits 1 when
// there is any. It runs on any PHP version, so it can say "too old" instead of crashing. Usage: php tests/preflight.php <root>
$root = $argv[1] ?? dirname(__DIR__);
$problems = [];

$composer = json_decode((string)@file_get_contents($root . '/composer.json'), true);
if (!is_array($composer)) {
    echo "composer.json cannot be read in $root, so the PHP the app needs is unknown\n";
    exit(1);
}
$require = $composer['require'] ?? [];

// The lowest PHP is the first version number of require.php (">=8.5", "^8.5.1" ...).
$minimum = preg_match('/\d+(?:\.\d+)*/', (string)($require['php'] ?? ''), $m) ? $m[0] : null;
if ($minimum === null) {
    $problems[] = 'composer.json names no PHP version in require.php';
} elseif (version_compare(PHP_VERSION, $minimum, '<')) {
    $problems[] = "PHP $minimum or newer is needed; this is " . PHP_VERSION . ' (' . PHP_BINARY . ', see php -v)';
}

// Extensions: composer's ext-* entries plus the ones the installer treats as required (its EXTENSIONS list).
$needed = [];
foreach (array_keys($require) as $name) {
    if (str_starts_with($name, 'ext-')) {
        $needed[substr($name, 4)] = 'composer.json';
    }
}
if (preg_match('/const EXTENSIONS = \[([^\]]*)\]/', (string)@file_get_contents($root . '/installer.php'), $m)
    && preg_match_all("/'([a-z0-9_]+)' => true/", $m[1], $all)) {
    foreach ($all[1] as $name) {
        $needed[$name] = $needed[$name] ?? 'installer.php';
    }
} else {
    $problems[] = 'installer.php lists no required PHP extensions (the preflight cannot read its EXTENSIONS)';
}
foreach ($needed as $name => $from) {
    if (!extension_loaded($name)) {
        $problems[] = "the PHP extension $name is missing (required in $from; check php -m)";
    }
}

foreach ($problems as $line) {
    echo $line, "\n";
}
exit($problems ? 1 : 0);
