<?php
/*
 * The one-file installer's pure parts: the paths it may write are exactly the ones an update may write, the manifest
 * checks, the allowed addresses, both languages hold the same strings, an existing site is recognised, and the build
 * puts installer.php beside the package and not inside it.
 *   php tests/unit/installer.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
define('GNOZIO_INSTALLER_LIB', true);
require dirname(__DIR__, 2) . '/installer.php';
use GnozioCMS\UpdateInstaller;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

check('it never ships with a key', INSTALL_KEY, '');
check('the folders it writes are the updater\'s', GnozioInstaller::DIRECTORIES, UpdateInstaller::DIRECTORIES);
check('and the files', GnozioInstaller::FILES, UpdateInstaller::FILES);
$paths = ['src/App.php', 'themes/default/theme.yaml', 'themes/x/a/b.yaml', 'VERSION', 'public/index.php', 'public/uploads/.htaccess', 'content/pages/a.md', 'custom/a.css', 'storage/db/app.sqlite', 'public/installer.php', 'installer.php', 'public/other.php', '../evil.php', 'src/../../evil.php', '/etc/passwd', 'src\\evil.php', 'C:/evil.php', 'src//a.php', 'src/./a.php', "src/a\0.php", '', 'srcx/a.php', 'themes/x', 'public/uploads/media/a.jpg'];
check('every path is judged as the updater judges it', array_map([GnozioInstaller::class, 'isAllowedPath'], $paths), array_map([UpdateInstaller::class, 'isAllowedPath'], $paths));
check('and the installer itself is not a path of a package', [GnozioInstaller::isAllowedPath('installer.php'), GnozioInstaller::isAllowedPath('public/installer.php')], [false, false]);

$sha = str_repeat('a', 64);
$good = ['version' => '1.2.3', 'min_php' => '8.5', 'package_url' => 'https://github.com/x/y/releases/download/v1.2.3/p.zip', 'sha256' => strtoupper($sha), 'size' => 1000];
check('a good manifest is read', GnozioInstaller::validateManifest($good), ['version' => '1.2.3', 'min_php' => '8.5', 'package_url' => $good['package_url'], 'sha256' => $sha, 'size' => 1000]);
check('a local http address is accepted for tests', GnozioInstaller::validateManifest(['package_url' => 'http://127.0.0.1:9/p.zip'] + $good) !== null, true);
$bad = [['version' => 'v1'], ['min_php' => 'x'], ['package_url' => 'http://example.com/p.zip'], ['package_url' => 'ftp://127.0.0.1/p.zip'], ['package_url' => 'https:///p.zip'], ['sha256' => 'abc'], ['size' => 0], ['size' => 104857601], ['size' => 'many']];
$results = [];
foreach ($bad as $change) { $results[] = GnozioInstaller::validateManifest($change + $good); }
check('and every wrong field is refused', $results, array_fill(0, count($bad), null));
check('and something that is not a manifest', [GnozioInstaller::validateManifest(null), GnozioInstaller::validateManifest('x'), GnozioInstaller::validateManifest([])], [null, null, null]);
check('the manifest address is the latest release of the project, outside the test server', GnozioInstaller::manifestUrl(), 'https://github.com/chiotis/gnoziocms/releases/latest/download/release.json');
putenv('GNOZIO_INSTALLER_RELEASE_URL=http://127.0.0.1:9/release.json');
check('and the environment cannot change it when PHP is not the built-in server', GnozioInstaller::manifestUrl(), 'https://github.com/chiotis/gnoziocms/releases/latest/download/release.json');
putenv('GNOZIO_INSTALLER_RELEASE_URL');

$strings = GnozioInstaller::strings();
$en = array_keys($strings['en']); $el = array_keys($strings['el']);
check('English and Greek have the same strings', [array_diff($en, $el), array_diff($el, $en)], [[], []]);
$empty = array_filter($strings['el'], static fn($v) => trim($v) === '');
check('none is empty', $empty, []);
preg_match_all('/\{(\w+)\}/', implode(' ', $strings['en']), $m1);
$same = true;
foreach ($strings['en'] as $key => $text) {
    preg_match_all('/\{(\w+)\}/', $text, $a); preg_match_all('/\{(\w+)\}/', $strings['el'][$key], $b);
    sort($a[1]); sort($b[1]);
    if ($a[1] !== $b[1]) { $same = false; echo "     placeholders differ in $key\n"; }
}
check('and the same placeholders', $same, true);

$dir = sys_get_temp_dir() . '/gzinst' . getmypid();
@mkdir($dir . '/a/src', 0775, true); @mkdir($dir . '/b/storage/db', 0775, true); @mkdir($dir . '/c', 0775, true);
file_put_contents($dir . '/a/VERSION', "1.0.0\n"); file_put_contents($dir . '/b/storage/db/app.sqlite', '');
check('a folder with code is an existing site', GnozioInstaller::existingInstall($dir . '/a'), 'code');
check('a folder with a database is an existing site', GnozioInstaller::existingInstall($dir . '/b'), 'database');
check('an empty folder is not', GnozioInstaller::existingInstall($dir . '/c'), '');
shell_exec('rm -rf ' . escapeshellarg($dir));

$out = sys_get_temp_dir() . '/gzbuild' . getmypid();
shell_exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/build-release.php') . ' ' . escapeshellarg($out) . ' 2>&1');
check('the build puts installer.php beside the package', is_file($out . '/installer.php') && md5_file($out . '/installer.php') === md5_file(dirname(__DIR__, 2) . '/installer.php'), true);
$zipFile = glob($out . '/gnoziocms-*.zip')[0] ?? '';
$zip = new ZipArchive(); $zip->open($zipFile);
check('and not inside the package', $zip->locateName('installer.php') === false && $zip->locateName('public/installer.php') === false, true);
shell_exec('rm -rf ' . escapeshellarg($out));

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
