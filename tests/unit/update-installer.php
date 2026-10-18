<?php
/*
 * Installing a new version: only code is replaced (a site's content, uploads, custom/ and database are not on the
 * list and stay byte for byte as they were), a package that is wrong in any way changes nothing, a new version that
 * does not start is taken out again (the database too if it had changed), and the old code can be put back later.
 *   php tests/unit/update-installer.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{MaintenanceMode, SystemDatabase, SystemMetaRepository, UpdateInstaller, UpdateNetwork, UpdateService};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- which paths a package may write
$allowed = ['src/App.php', 'src/Sub/Deep.php', 'admin/templates/x.twig', 'vendor/autoload.php', 'themes/default/theme.yaml', 'themes/mine/blocks/a/block.yaml', 'starter/content/pages/a.md', 'public/assets/css/app.css', 'public/index.php', 'public/.htaccess', 'public/uploads/.htaccess', 'custom/README.md', 'VERSION', 'CHANGELOG.md', 'composer.json'];
$refused = ['content/pages/a.md', 'custom/custom.css', 'custom/lang/el.yaml', 'storage/db/app.sqlite', 'public/uploads/media/a.jpg', 'public/other.php', 'themes/x', 'tests/a.php', '.env', '../evil.php', 'src/../../evil.php', '/etc/passwd', 'src\\evil.php', 'C:/evil.php', 'src//a.php', 'src/./a.php', "src/a\0.php", '', 'srcx/a.php', 'public/uploads/.htaccess/x'];
check('every path of the code is allowed', array_map([UpdateInstaller::class, 'isAllowedPath'], $allowed), array_fill(0, count($allowed), true));
check('and nothing of a site, nothing outside, nothing that climbs out', array_map([UpdateInstaller::class, 'isAllowedPath'], $refused), array_fill(0, count($refused), false));

// ---- maintenance mode
$base = sys_get_temp_dir() . '/upd' . getmypid();
$site = $base . '/site';
mkdir("$site/storage", 0775, true);
$mode = new MaintenanceMode($site);
check('no flag, nothing is blocked', [$mode->state(), $mode->blocks(''), $mode->blocks('anything')], [null, false, false]);
$token = $mode->enable('1.0.0');
check('with the flag visitors are blocked, and the version is noted', [$mode->blocks(''), $mode->blocks('wrong'), $mode->state()['version']], [true, true, '1.0.0']);
check('the token of the flag gets in', $mode->blocks($token), false);
file_put_contents($mode->path(), json_encode(['since' => time() - MaintenanceMode::MAX_AGE - 5, 'version' => '1', 'token' => 'x']));
check('a flag older than a quarter of an hour is ignored, so a failed update cannot close the site', [$mode->state(), $mode->blocks('')], [null, false]);
file_put_contents($mode->path(), 'rubbish');
check('so is one that cannot be read', $mode->blocks(''), false);
$mode->enable('1');
$mode->disable();
check('and it is removed when the update ends', [is_file($mode->path()), $mode->blocks('')], [false, false]);
check('the page visitors see says it is temporary', [str_contains(MaintenanceMode::page(), 'Back in a moment'), str_contains(MaintenanceMode::page(), 'noindex')], [true, true]);
exec('rm -rf ' . escapeshellarg($base));

// ---- the manifest of a release
$good = ['version' => '1.2.3', 'min_php' => '8.1', 'package_url' => 'https://github.com/a/b/releases/download/v1.2.3/faroscms-1.2.3.zip', 'sha256' => str_repeat('a', 64), 'size' => 1000, 'requires_backup' => true];
check('a good manifest is accepted as it is', UpdateService::validateManifest($good), $good);
check('with a backup required unless it says it is not', [UpdateService::validateManifest(['requires_backup' => false] + $good)['requires_backup'], UpdateService::validateManifest(array_diff_key($good, ['requires_backup' => 1]))['requires_backup']], [false, true]);
check('a package on this machine over http is fine for tests', UpdateService::validateManifest(['package_url' => 'http://127.0.0.1:8080/p.zip'] + $good)['package_url'], 'http://127.0.0.1:8080/p.zip');
$bad = [
    'nothing' => null, 'a string' => 'x', 'no version' => ['version' => ''] + $good, 'a version that is not x.y.z' => ['version' => '1.2'] + $good, 'a version with a suffix' => ['version' => '1.2.3-beta'] + $good,
    'a bad min php' => ['min_php' => '8.x'] + $good, 'a short checksum' => ['sha256' => 'abc'] + $good, 'a checksum that is not hex' => ['sha256' => str_repeat('z', 64)] + $good,
    'no size' => ['size' => 0] + $good, 'a size past the limit' => ['size' => UpdateService::MAX_PACKAGE_BYTES + 1] + $good,
    'plain http on another host' => ['package_url' => 'http://example.com/p.zip'] + $good, 'a file address' => ['package_url' => 'file:///etc/passwd'] + $good, 'no host' => ['package_url' => 'https:///p.zip'] + $good, 'no address' => ['package_url' => ''] + $good,
];
foreach ($bad as $label => $manifest) { check("a manifest is refused: $label", UpdateService::validateManifest($manifest), null); }
check('and the checksum is compared in lower case', UpdateService::validateManifest(['sha256' => strtoupper(str_repeat('a', 64))] + $good)['sha256'], str_repeat('a', 64));

// ---- a site, and packages
$base = sys_get_temp_dir() . '/upd' . getmypid();
$site = $base . '/site';
$put = function (string $root, string $path, string $text) { @mkdir(dirname("$root/$path"), 0775, true); file_put_contents("$root/$path", $text); };
// A connection left open to a file that is then deleted and made again can be mistaken for the new file (the same inode
// is often reused), so the ones this test opened are closed before a site is made again.
$opened = [];
$makeSite = function (string $version) use ($site, $put, &$opened): void {
    foreach ($opened as $open) { $open->close(); }
    $opened = [];
    gc_collect_cycles();
    // The old site is moved out of the way, not deleted: a database file deleted and made again at the same path can reuse
    // the inode, and SQLite may then refuse to open it while the process still remembers the old one.
    static $made = 0;
    if (is_dir($site)) { rename($site, $site . '-old' . (++$made)); }
    foreach (['src/App.php' => '<?php // old code', 'src/Old.php' => 'only in the old version', 'admin/templates/a.twig' => 'old admin', 'vendor/autoload.php' => '<?php // old vendor', 'themes/default/theme.yaml' => 'name: default # old', 'themes/mine/theme.yaml' => 'name: mine', 'starter/content/pages/a.md' => 'old starter',
        'public/index.php' => '<?php // old index', 'public/.htaccess' => '# old htaccess', 'public/assets/css/app.css' => '/* old */', 'public/uploads/.htaccess' => '# old uploads rules', 'custom/README.md' => 'old readme',
        'VERSION' => $version . "\n", 'CHANGELOG.md' => '# old', 'README.md' => 'old', 'LICENSE' => 'old', 'update.md' => 'old', 'composer.json' => '{}',
        'content/pages/mine.md' => "---\ntitle: Mine\n---\n\nMy page\n", 'content/posts/p.md' => "post", 'custom/assets/css/custom.css' => 'body{color:red}', 'public/uploads/media/pic.jpg' => 'JPEGDATA', 'content/media/pic.yaml' => 'name: pic'] as $path => $text) {
        $put($site, $path, $text);
    }
    mkdir("$site/storage/backups", 0775, true);
    $db = new SystemDatabase("$site/storage");
    $db->initialize();
    (new SystemMetaRepository($db))->set('site_settings', "title: My own site\n");
    $db->close();
};
/** A package for a version: every path of the new code, plus whatever else a test adds or changes. */
$makePackage = function (string $version, array $extra = [], array $without = []) use ($base): array {
    $files = ['src/App.php' => '<?php // NEW code', 'src/New.php' => 'only in the new version', 'admin/templates/a.twig' => 'new admin', 'vendor/autoload.php' => '<?php // new vendor', 'themes/default/theme.yaml' => 'name: default # new', 'themes/extra/theme.yaml' => 'name: extra', 'starter/content/pages/a.md' => 'new starter',
        'public/index.php' => '<?php // NEW index', 'public/.htaccess' => '# new htaccess', 'public/assets/css/app.css' => '/* new */', 'public/uploads/.htaccess' => '# new uploads rules', 'custom/README.md' => 'new readme',
        'VERSION' => $version . "\n", 'CHANGELOG.md' => '# new', 'README.md' => 'new', 'LICENSE' => 'new', 'update.md' => 'new', 'composer.json' => '{"new":1}'];
    $files = array_diff_key($extra + $files, array_flip($without));
    @mkdir($base, 0775, true);
    $path = $base . '/pkg-' . $version . '-' . mt_rand() . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($files as $name => $text) {
        if ($text === null) { $zip->addEmptyDir($name); continue; }
        $zip->addFromString($name, $text);
    }
    $zip->close();
    return ['path' => $path, 'manifest' => ['version' => $version, 'min_php' => '8.1', 'package_url' => 'https://example.test/p.zip', 'sha256' => hash_file('sha256', $path), 'size' => filesize($path), 'requires_backup' => true]];
};
/** The site's own data, as one text, to tell whether anything changed. */
$data = function () use ($site): string {
    $out = [];
    foreach (['content', 'custom/assets', 'public/uploads/media', 'storage/db'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$site/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile() && !str_ends_with($f->getFilename(), '-wal') && !str_ends_with($f->getFilename(), '-shm')) { $out[] = substr($f->getPathname(), strlen($site)) . ':' . md5_file($f->getPathname()); } }
    }
    sort($out);
    return implode("\n", $out);
};
$settingsText = function () use ($site): string { $db = new SystemDatabase("$site/storage"); $db->initialize(); $v = (new SystemMetaRepository($db))->get('site_settings'); $db->close(); return (string)$v; };
$log = [];
$healthCalls = [];
$health = ['reached' => true, 'ok' => true, 'message' => 'the site answered'];
$onHealth = null;
$packagePath = '';
$downloadError = null;
$beforeDownload = null;
$build = function () use ($site, &$log, &$healthCalls, &$health, &$onHealth, &$packagePath, &$downloadError, &$beforeDownload, &$opened): UpdateInstaller {
    $db = new SystemDatabase("$site/storage");
    $db->initialize();
    $opened[] = $db;
    return new UpdateInstaller($site, $db, new MaintenanceMode($site),
        function (string $url, string $dest, int $max) use (&$packagePath, &$downloadError, &$beforeDownload): ?string { if ($beforeDownload) { $beforeDownload(); } if ($downloadError !== null) { return $downloadError; } copy($packagePath, $dest); return null; },
        function (string $token, string $version) use (&$healthCalls, &$health, &$onHealth, $site): array { $healthCalls[] = ['flag' => (new MaintenanceMode($site))->blocks(''), 'gets_in' => !(new MaintenanceMode($site))->blocks($token), 'code' => (string)@file_get_contents("$site/src/App.php"), 'version' => $version]; if ($onHealth) { $onHealth(); } return $health; },
        function (string $level, string $message, array $ctx) use (&$log): void { $log[] = [$level, $message]; });
};
$code = fn(string $p) => (string)@file_get_contents("$site/$p");
$leftovers = fn() => array_map('basename', glob("$site/storage/updates/*", GLOB_ONLYDIR) ?: []);
$state = fn() => [$code('VERSION'), $code('src/App.php'), is_file("$site/src/Old.php"), is_file("$site/src/New.php"), (new MaintenanceMode($site))->state() !== null];
$ok = fn(array $r) => [$r['status'], $r['step']];
$OLD = ["0.1.0\n", '<?php // old code', true, false, false];

// ---- a good install
$makeSite('0.1.0');
$pkg = $makePackage('0.2.0');
$packagePath = $pkg['path'];
$installer = $build();
$before = $data();
$r = $installer->install($pkg['manifest'], '0.1.0', true);
check('a good package installs', [$ok($r), $r['from'], $r['to']], [['installed', 'done'], '0.1.0', '0.2.0']);
check('the code is the new code, and the old is gone', [$code('VERSION'), $code('src/App.php'), is_file("$site/src/Old.php"), is_file("$site/src/New.php"), $code('vendor/autoload.php'), $code('admin/templates/a.twig'), $code('public/index.php'), $code('themes/default/theme.yaml')], ["0.2.0\n", '<?php // NEW code', false, true, '<?php // new vendor', 'new admin', '<?php // NEW index', 'name: default # new']);
check('the files that live inside the site\'s own folders are renewed too, and the rest of those folders are not touched', [$code('public/uploads/.htaccess'), $code('custom/README.md'), $code('custom/assets/css/custom.css'), $code('public/uploads/media/pic.jpg')], ['# new uploads rules', 'new readme', 'body{color:red}', 'JPEGDATA']);
check('a theme the package brings is added, and one the site has of its own stays', [$code('themes/extra/theme.yaml'), $code('themes/mine/theme.yaml')], ['name: extra', 'name: mine']);
check('the content, uploads, custom files and the database are byte for byte what they were', [$data() === $before, $settingsText()], [true, "title: My own site\n"]);
check('the public site was closed while the code changed, the check got in, and the new code was what it saw', [$healthCalls[0]['flag'], $healthCalls[0]['gets_in'], $healthCalls[0]['code'], $healthCalls[0]['version']], [true, true, '<?php // NEW code', '0.2.0']);
check('the site is open again, the working files are gone, the old code is kept to roll back to', [(new MaintenanceMode($site))->state(), is_dir("$site/storage/updates/0.2.0/staged"), is_file("$site/storage/updates/0.2.0/package.zip"), $code('storage/updates/0.2.0/previous/src/App.php'), $code('storage/updates/0.2.0/previous/VERSION')], [null, false, false, '<?php // old code', "0.1.0\n"]);
check('a copy of the database is kept beside it', is_file("$site/storage/updates/0.2.0/database/app.sqlite"), true);
check('and it can be rolled back to', $installer->rollbackTarget('0.2.0'), '0.1.0');
check('the steps were logged', $log[0], ['info', 'Downloading the package.']);

// ---- rolling back later
$beforeRollback = $data();
$r = $installer->rollback('0.2.0', '0.2.0');
check('the old code can be put back later', [$ok($r), $state()], [['rolled_back', 'rollback'], $OLD]);
check('and what it kept is cleared', [$installer->rollbackTarget('0.2.0'), $leftovers()], [null, []]);
check('with the site\'s data untouched, and the database not turned back (it has been used since)', [$data() === $beforeRollback, $code('themes/extra/theme.yaml') === ''], [true, true]);
check('a second roll back has nothing to use', $installer->rollback('0.2.0', '0.1.0')['status'], 'failed');

// ---- a package that is wrong changes nothing
$wrong = [
    'its checksum is not the published one' => [fn() => ['manifest' => ['sha256' => str_repeat('b', 64)] + $pkg['manifest'], 'path' => $pkg['path']], 'verify'],
    'its size is not the published one' => [fn() => ['manifest' => ['size' => $pkg['manifest']['size'] + 1] + $pkg['manifest'], 'path' => $pkg['path']], 'verify'],
];
foreach ($wrong as $label => [$make, $step]) {
    $makeSite('0.1.0');
    $before = $data();
    $p = $make();
    $packagePath = $p['path'];
    $r = $build()->install($p['manifest'], '0.1.0', true);
    check("an update whose package is wrong ($label) is refused at the $step step, nothing changes", [$ok($r), $state(), $data() === $before, $leftovers(), count($healthCalls) === 1], [['failed', $step], $OLD, true, [], true]);
}
$healthCalls = [];
$badPackages = [
    'a site\'s content' => ['content/pages/evil.md' => 'x'],
    'the database' => ['storage/db/app.sqlite' => 'x'],
    'an upload' => ['public/uploads/media/evil.php' => '<?php'],
    'a custom file' => ['custom/custom.css' => 'x'],
    'a path that climbs out' => ['src/../../evil.php' => 'x'],
    'a file at the top' => ['evil.php' => 'x'],
];
foreach ($badPackages as $label => $extra) {
    $makeSite('0.1.0');
    $before = $data();
    $p = $makePackage('0.2.0', $extra);
    $packagePath = $p['path'];
    $r = $build()->install($p['manifest'], '0.1.0', true);
    check("a package with $label in it is refused whole, nothing changes", [$ok($r), $state(), $data() === $before, $leftovers(), is_file("$base/evil.php") || is_file("$site/evil.php")], [['failed', 'unpack'], $OLD, true, [], false]);
}
$makeSite('0.1.0');
$p = $makePackage('0.2.0', ['VERSION' => "0.9.9\n"]);
$packagePath = $p['path'];
check('a package that is another version than it says is refused', [$ok($build()->install($p['manifest'], '0.1.0', true)), $state()], [['failed', 'unpack'], $OLD]);
foreach (['src/App.php', 'public/index.php', 'vendor/autoload.php', 'themes/default/theme.yaml'] as $missing) {
    $p = $makePackage('0.2.0', [], [$missing]);
    $packagePath = $p['path'];
    check("a package without $missing is refused as incomplete", [$ok($build()->install($p['manifest'], '0.1.0', true)), $state()], [['failed', 'unpack'], $OLD]);
}
$notZip = $base . '/not.zip';
file_put_contents($notZip, 'this is not a zip');
$packagePath = $notZip;
check('a file that is not a ZIP is refused', [$ok($build()->install(['sha256' => hash_file('sha256', $notZip), 'size' => filesize($notZip)] + $pkg['manifest'], '0.1.0', true)), $state()], [['failed', 'unpack'], $OLD]);
$zip = new ZipArchive();
$linkZip = $base . '/link.zip';
$zip->open($linkZip, ZipArchive::CREATE);
foreach (['src/App.php' => '<?php', 'public/index.php' => '<?php', 'vendor/autoload.php' => '<?php', 'themes/default/theme.yaml' => 'x', 'VERSION' => "0.2.0\n"] as $n => $t) { $zip->addFromString($n, $t); }
$zip->addFromString('src/link.php', '../../content/pages/mine.md');
$zip->setExternalAttributesName('src/link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
$zip->close();
$packagePath = $linkZip;
check('a package that holds a link is refused', [$ok($build()->install(['sha256' => hash_file('sha256', $linkZip), 'size' => filesize($linkZip)] + $pkg['manifest'], '0.1.0', true)), $state()], [['failed', 'unpack'], $OLD]);

// ---- the conditions
$packagePath = $pkg['path'];
$makeSite('0.1.0');
check('without a verified backup nothing is done', [$ok($build()->install($pkg['manifest'], '0.1.0', false)), $state()], [['failed', 'backup'], $OLD]);
check('unless the release does not require one', $ok($build()->install(['requires_backup' => false] + $pkg['manifest'], '0.1.0', false)), ['installed', 'done']);
$makeSite('0.2.0');
check('the same version is not installed again', [$ok($build()->install($pkg['manifest'], '0.2.0', true)), $code('src/App.php')], [['failed', 'preflight'], '<?php // old code']);
$makeSite('0.3.0');
check('nor an older one', $ok($build()->install($pkg['manifest'], '0.3.0', true)), ['failed', 'preflight']);
$makeSite('0.1.0');
check('nor one that needs a newer PHP', $ok($build()->install(['min_php' => '99.0'] + $pkg['manifest'], '0.1.0', true)), ['failed', 'preflight']);
$downloadError = 'The server answered 404.';
$r = $build()->install($pkg['manifest'], '0.1.0', true);
check('a package that cannot be downloaded changes nothing, and says why', [$ok($r), str_contains($r['message'], 'The server answered 404.'), $state(), $leftovers()], [['failed', 'download'], true, $OLD, []]);
$downloadError = null;
$lockHandle = fopen("$site/storage/updates/install.lock", 'c');
flock($lockHandle, LOCK_EX);
$checks = $build()->preflight($pkg['manifest'], '0.1.0');
check('an update in progress is seen, and a second one does not start', [array_values(array_filter($checks, fn($c) => !$c['ok']))[0]['label'], $ok($build()->install($pkg['manifest'], '0.1.0', true))], ['No update running', ['failed', 'preflight']]);
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
check('every other check is fine on this machine', array_column(array_filter($build()->preflight($pkg['manifest'], '0.1.0'), fn($c) => !$c['ok']), 'label'), []);

// ---- a new version that does not start
$makeSite('0.1.0');
$before = $data();
$health = ['reached' => true, 'ok' => false, 'message' => '/admin/login answered 500'];
$r = $build()->install($pkg['manifest'], '0.1.0', true);
check('the new version is taken out and the old one put back, and it says why', [$ok($r), str_contains($r['message'], '/admin/login answered 500'), $state(), $data() === $before, $leftovers()], [['rolled_back', 'health'], true, $OLD, true, []]);
check('every folder is as it was', [$code('themes/default/theme.yaml'), $code('vendor/autoload.php'), $code('public/uploads/.htaccess'), is_file("$site/themes/extra/theme.yaml"), $code('admin/templates/a.twig')], ['name: default # old', '<?php // old vendor', '# old uploads rules', false, 'old admin']);
$makeSite('0.1.0');
$onHealth = function () use ($site) { $db = new SystemDatabase("$site/storage"); $db->initialize(); $db->connection()->exec("INSERT INTO schema_migrations (version, applied_at) VALUES ('999_new', 'now')"); $db->connection()->exec("CREATE TABLE new_feature (id INTEGER)"); $db->close(); };
$tablesBefore = function () use ($site): array { $db = new SystemDatabase("$site/storage"); $db->initialize(); $r = [(int)$db->connection()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), (int)$db->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'new_feature'")->fetchColumn()]; $db->close(); return $r; };
$was = $tablesBefore();
$r = $build()->install($pkg['manifest'], '0.1.0', true);
check('a new version that had changed the database has it put back as it was', [$ok($r), $tablesBefore() === $was, $settingsText()], [['rolled_back', 'health'], true, "title: My own site\n"]);
$onHealth = null;
$makeSite('0.1.0');
$health = ['reached' => false, 'ok' => false, 'message' => 'no answer from https://site.test'];
$r = $build()->install($pkg['manifest'], '0.1.0', true);
check('when the site cannot be asked, the new version stays, the site is opened, and it says to look (' . $r['message'] . ')', [$ok($r), str_contains($r['message'], 'no answer from https://site.test'), $code('VERSION'), (new MaintenanceMode($site))->state(), $build()->rollbackTarget('0.2.0')], [['unverified', 'health'], true, "0.2.0\n", null, '0.1.0']);
$build()->rollback('0.2.0', '0.2.0');
$health = ['reached' => true, 'ok' => true, 'complete' => false, 'message' => '/about did not confirm version 0.2.0'];
$r = $build()->install($pkg['manifest'], '0.1.0', true);
check('a partial check is explicitly unverified, with a way back', [$ok($r), str_contains($r['message'], '/about'), $code('VERSION'), $build()->rollbackTarget('0.2.0')], [['unverified', 'health'], true, "0.2.0\n", '0.1.0']);
$health = ['reached' => true, 'ok' => true, 'message' => 'the site answered'];

// ---- a swap that fails half way is undone
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    echo "note a swap that fails half way is not tried here: this user can write anywhere\n";
} else {
    $makeSite('0.1.0');
    $before = $data();
    $beforeDownload = function () use ($site) { chmod("$site/public", 0555); };
    $r = $build()->install($pkg['manifest'], '0.1.0', true);
    chmod("$site/public", 0775);
    $beforeDownload = null;
    check('a swap that fails on the last folders puts back the ones before it', [$ok($r), $state(), $code('vendor/autoload.php'), $code('admin/templates/a.twig'), $code('starter/content/pages/a.md'), $code('themes/default/theme.yaml'), $data() === $before, $leftovers()], [['failed', 'swap'], $OLD, '<?php // old vendor', 'old admin', 'old starter', 'name: default # old', true, []]);
    check('and the site is open', (new MaintenanceMode($site))->state(), null);
}

// ---- old runs are not kept for ever
$makeSite('0.1.0');
foreach (['0.2.0', '0.3.0', '0.4.0'] as $i => $v) {
    $p = $makePackage($v);
    $packagePath = $p['path'];
    $installer = $build();
    $installer->install($p['manifest'], $i === 0 ? '0.1.0' : ['0.2.0', '0.3.0'][$i - 1], true);
}
check('only what the last two updates left is kept', $leftovers(), ['0.3.0', '0.4.0']);

// ---- the network: downloading, and asking the site
$server = $base . '/server';
mkdir($server . '/docroot', 0775, true);
file_put_contents($server . '/state', 'ok');
file_put_contents($server . '/router.php', '<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$state = trim((string)file_get_contents(__DIR__ . "/state"));
$seen = $_SERVER["HTTP_X_FAROS_UPDATE"] ?? "";
file_put_contents(__DIR__ . "/seen", $path . " " . $seen . "\n", FILE_APPEND);
$nonce = $_GET["_faros_check"] ?? "";
if ($state !== "cached" && $seen === "tok123" && preg_match("/^[a-f0-9]{32}$/", $nonce)) {
    header("X-Faros-Update-Check: " . hash_hmac("sha256", "0.2.0|" . $nonce, $seen));
}
if ($state === "truncated") { header("Content-Length: 100000"); echo "<urlset"; return; }
if ($state === "redirect" && $path === "/") { header("Location: /about", true, 302); return; }
if ($path === "/release.json") { header("Content-Type: application/json"); echo file_get_contents(__DIR__ . "/release.json"); return; }
if ($path === "/package.zip") { header("Content-Type: application/zip"); readfile(__DIR__ . "/package.zip"); return; }
if ($path === "/go") { header("Location: /package.zip", true, 302); return; }
if ($path === "/missing") { http_response_code(404); echo "no"; return; }
if ($path === "/admin/login") { http_response_code($state === "login500" ? 500 : 200); echo $state === "loginerror" ? "<br />\n<b>Fatal error</b>: Uncaught Error: boom in /x.php" : "<html>login</html>"; return; }
if ($path === "/") { http_response_code($state === "home500" ? 500 : ($state === "home404" ? 404 : 200)); echo "home"; return; }
if ($path === "/about") { http_response_code($state === "entry500" ? 500 : ($state === "entry404" ? 404 : 200)); echo $state === "empty" ? "" : "about"; return; }
if ($path === "/sitemap.xml") { http_response_code($state === "sitemap-off" ? 404 : 200); echo $state === "badxml" ? "<html>not a sitemap</html>" : "<urlset></urlset>"; return; }
http_response_code(404);');
file_put_contents($server . '/package.zip', str_repeat('Z', 5000));
$port = (function (): int { $s = stream_socket_server('tcp://127.0.0.1:0', $e, $m); $n = stream_socket_get_name($s, false); fclose($s); return (int)substr($n, strrpos($n, ':') + 1); })();
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $server . '/docroot', $server . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
$url = "http://127.0.0.1:$port";
check('only https, or http on this machine, may be fetched', array_map([UpdateNetwork::class, 'isAllowedUrl'], ['https://github.com/x', 'http://127.0.0.1:1/x', 'http://localhost/x', 'http://example.com/x', 'ftp://x/y', 'file:///etc/passwd', '']), [true, true, true, false, false, false, false]);
$dest = $base . '/dl.zip';
check('a file is downloaded, through a redirect too', [UpdateNetwork::download("$url/package.zip", $dest, 10000), filesize($dest), UpdateNetwork::download("$url/go", $dest, 10000), filesize($dest)], [null, 5000, null, 5000]);
check('a file larger than the limit is stopped and not kept', [UpdateNetwork::download("$url/package.zip", $dest, 1000) !== null, is_file($dest)], [true, false]);
check('an address that is not there is an error and nothing is kept', [UpdateNetwork::download("$url/missing", $dest, 10000) !== null, is_file($dest)], [true, false]);
check('an address that is not allowed is refused without a request', [UpdateNetwork::download('http://example.com/p.zip', $dest, 10000), is_file($dest)], ['The address is not allowed (it must be https).', false]);
check('nobody listening is an error', UpdateNetwork::download('http://127.0.0.1:1/p.zip', $dest, 10000) !== null, true);
$ask = fn(string $state) => (function () use ($server, $url, $state): array { file_put_contents($server . '/state', $state); return UpdateNetwork::checkSite($url, 'tok123'); })();
check('a site that answers is fine', $ask('ok'), ['reached' => true, 'ok' => true, 'complete' => true, 'message' => 'Checked /admin/login, /, /sitemap.xml']);
check('the token goes with the request, so the maintenance page lets it in', str_contains((string)file_get_contents($server . '/seen'), '/admin/login tok123'), true);
check('a home page that does not exist yet is fine, as on a new site', $ask('home404')['ok'], true);
check('a sign-in page that fails is not', [$ask('login500')['ok'], $ask('login500')['message']], [false, '/admin/login answered 500']);
check('a home page that fails is not', [$ask('home500')['ok'], $ask('home500')['message']], [false, '/ answered 500']);
check('a PHP error on a page that says 200 is not fine either', [$ask('loginerror')['ok'], $ask('loginerror')['message']], [false, '/admin/login shows a PHP error']);
check('a site that cannot be reached is not known to be bad', UpdateNetwork::checkSite('http://127.0.0.1:1', 'tok'), ['reached' => false, 'ok' => false, 'complete' => false, 'message' => 'no answer from http://127.0.0.1:1']);
$probe = function (string $state, string $version = '0.2.0', bool $sitemap = true) use ($server, $url): array {
    file_put_contents($server . '/state', $state);
    return UpdateNetwork::checkSite($url, 'tok123', $version, '/about', $sitemap);
};
check('all four requests prove the expected version answered freshly', $probe('ok'), ['reached' => true, 'ok' => true, 'complete' => true, 'message' => 'Checked /admin/login, /, /about, /sitemap.xml']);
check('an entry failure is caught even when login and home work', $probe('entry500')['message'], '/about answered 500');
check('a known published entry becoming a 404 fails the check', $probe('entry404')['ok'], false);
check('an empty page with status 200 fails the check', $probe('empty')['message'], '/about returned an empty page');
check('HTML returned instead of the sitemap fails the check', $probe('badxml')['message'], '/sitemap.xml did not return a sitemap');
check('a deliberately disabled sitemap is allowed', $probe('sitemap-off', '0.2.0', false)['complete'], true);
check('a missing enabled sitemap is a failure', $probe('sitemap-off')['ok'], false);
$cached = $probe('cached');
check('cached or restricted responses do not falsely verify or roll back an install', [$cached['reached'], $cached['ok'], $cached['complete'], str_contains($cached['message'], 'did not confirm version')], [true, true, false, true]);
check('a different version cannot satisfy the fresh-version proof', $probe('ok', '0.3.0')['complete'], false);
check('an existing homepage redirect is unverified rather than an automatic rollback', array_intersect_key($probe('redirect'), array_flip(['reached', 'ok', 'complete'])), ['reached' => true, 'ok' => true, 'complete' => false]);
check('a truncated HTTP response cannot verify an install', $probe('truncated')['complete'], false);

// ---- the release manifest, read from where it is published
file_put_contents($server . '/state', 'ok');
$db = new SystemDatabase($site . '/storage');
$db->initialize();
$meta = new SystemMetaRepository($db);
$manifest = ['version' => '9.9.9', 'min_php' => '8.1', 'package_url' => "$url/package.zip", 'sha256' => hash('sha256', str_repeat('Z', 5000)), 'size' => 5000, 'requires_backup' => true];
file_put_contents($server . '/release.json', json_encode($manifest));
$service = new UpdateService($site, ['repository' => 'acme/site', 'release_url' => "$url/release.json"], $meta);
check('the manifest is looked for at release.json of the latest release unless told elsewhere', [(new UpdateService($site, ['repository' => 'acme/site'], $meta))->releaseManifestUrl(), $service->releaseManifestUrl()], ['https://github.com/acme/site/releases/latest/download/release.json', "$url/release.json"]);
check('it is read and checked', $service->releaseManifest(true), $manifest);
file_put_contents($server . '/release.json', json_encode(['version' => '10.0.0'] + $manifest));
check('and kept, so the screen does not ask every time', $service->releaseManifest()['version'], '9.9.9');
check('until it is asked to look again', $service->releaseManifest(true)['version'], '10.0.0');
file_put_contents($server . '/release.json', '{"version": "11.0.0"');
check('a manifest that is not valid is nothing', $service->releaseManifest(true), null);
$other = new UpdateService($site, ['repository' => 'acme/site', 'release_url' => "$url/missing"], $meta);
check('so is one that is not there', $other->releaseManifest(true), null);
proc_terminate($proc);
proc_close($proc);

exec('rm -rf ' . escapeshellarg($base));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
