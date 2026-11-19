<?php
/*
 * Encrypted backups (audit S19): with a passphrase every entry of a new archive is AES-256 encrypted (files, the database, the
 * manifest), a plain info entry tells the list what it is, verify and restore open it with the passphrase in Settings or one given,
 * and without the right one nothing is read; without a passphrase archives stay plain, as before.
 *   php tests/unit/backup-encryption.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{BackupService, SiteSettings, SystemDatabase};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

if (!BackupService::canEncrypt()) {
    echo "skip this server's zip library cannot write AES-256 entries\n\nALL PASSED\n";
    exit(0);
}
$dir = sys_get_temp_dir() . '/backupenc' . getmypid();
mkdir("$dir/storage", 0775, true);
mkdir("$dir/content/pages", 0775, true);
register_shutdown_function(function () use ($dir) { @exec('rm -rf ' . escapeshellarg($dir)); });
file_put_contents("$dir/content/pages/a.md", "---\ntitle: A\n---\nA-VERY-PRIVATE-SENTENCE\n");
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$db->connection()->exec("INSERT INTO system_meta (key, value, updated_at) VALUES ('probe', 'CUSTOMER-ADDRESS-IN-DB', datetime('now'))");

// ---- plain, as before
$plain = new BackupService($dir, $db);
$made = $plain->createDataSnapshot('site', ['reason' => 'manual', 'version' => '1.2.3']);
check('without a passphrase an archive is plain, as before', [$made['ok'], str_contains((string)file_get_contents($made['path']), 'A-VERY-PRIVATE-SENTENCE'), $plain->list()[0]['encrypted'], $plain->verify($made['filename'])['encrypted']], [true, true, false, false]);

// ---- encrypted
$service = new BackupService($dir, $db);
$service->setPassphrase('correct horse battery');
$made = $service->createDataSnapshot('site', ['reason' => 'manual', 'version' => '1.2.3']);
$bytes = (string)file_get_contents($made['path']);
check('with one, nothing of the content or the database can be read in the file', [$made['ok'], str_contains($bytes, 'A-VERY-PRIVATE-SENTENCE'), str_contains($bytes, 'title: A'), str_contains($bytes, '"sha256"')], [true, false, false, false]);
$zip = new ZipArchive();
$zip->open($made['path']);
$methods = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $stat = $zip->statIndex($i); $methods[$stat['name']] = $stat['encryption_method']; }
$zip->close();
check('every entry is AES-256 but the plain info', [$methods['content/pages/a.md'], $methods['storage/db/app.sqlite'], $methods[BackupService::MANIFEST_NAME], $methods[BackupService::INFO_NAME]], [ZipArchive::EM_AES_256, ZipArchive::EM_AES_256, ZipArchive::EM_AES_256, ZipArchive::EM_NONE]);
$listed = null;
foreach ($service->list() as $item) { if ($item['filename'] === $made['filename']) { $listed = $item; } }
check('the list knows it, its type, reason and version', [$listed['encrypted'], $listed['type'], $listed['reason'], $listed['version'], $listed['has_manifest']], [true, 'data', 'manual', '1.2.3', true]);
$v = $service->verify($made['filename']);
check('verified with the passphrase in Settings: every checksum', [$v['ok'], $v['encrypted'], $v['locked'], $v['checked'] >= 2, str_contains($v['message'], 'encrypted')], [true, true, false, true, true]);

// ---- another server, or another passphrase
$elsewhere = new BackupService($dir, $db);
$elsewhere->setPassphrase('something else');
$v = $elsewhere->verify($made['filename']);
check('another passphrase opens nothing, but the areas are known', [$v['ok'], $v['locked'], $v['areas']['content'] > 0, $v['areas']['database'] > 0], [false, true, true, true]);
$nothing = new BackupService($dir, $db);
check('no passphrase at all: the same', $nothing->verify($made['filename'])['locked'], true);
check('the list still says what it is', array_values(array_filter($nothing->list(), fn($i) => $i['filename'] === $made['filename']))[0]['type'] ?? '', 'data');
check('the one given on the restore screen opens it', $elsewhere->verify($made['filename'], 'correct horse battery')['ok'], true);
$r = $elsewhere->restore($made['filename'], ['content'], 'wrong');
check('restore with the wrong one does nothing', [$r['ok'], str_contains($r['message'], 'encrypted')], [false, true]);
file_put_contents("$dir/content/pages/a.md", "changed\n");
$r = $elsewhere->restore($made['filename'], ['content'], 'correct horse battery');
check('with the right one it restores', [$r['ok'], (string)file_get_contents("$dir/content/pages/a.md")], [true, "---\ntitle: A\n---\nA-VERY-PRIVATE-SENTENCE\n"]);

// ---- the setting
check('the passphrase is a secret of Settings (stored sealed, never shown)', SiteSettings::secretPaths()['backup_passphrase'] ?? null, ['backup', 'passphrase']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
