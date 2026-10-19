<?php
/*
 * The package a site installs: only code, never a site's own data, the same bytes every time, and a manifest that
 * matches it.
 *   php tests/unit/release-package.php
 */
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = dirname(__DIR__, 2);
$out = sys_get_temp_dir() . '/relpkg' . getmypid();
$version = trim((string)file_get_contents($root . '/VERSION'));
$run = function (string $dir, string $extra = '') use ($root): string { return (string)shell_exec('php ' . escapeshellarg($root . '/scripts/build-release.php') . ' ' . escapeshellarg($dir) . ' ' . $extra . ' 2>&1'); };

$said = $run($out . '/a', '--repo=acme/site');
check('it says what it built', str_starts_with($said, "Built gnoziocms-$version.zip: "), true);
$manifest = json_decode((string)file_get_contents($out . '/a/release.json'), true);
$zipPath = $out . "/a/gnoziocms-$version.zip";
check('the manifest names the version, the package and where it is', [$manifest['version'], $manifest['package'], $manifest['package_url']], [$version, "gnoziocms-$version.zip", "https://github.com/acme/site/releases/download/v$version/gnoziocms-$version.zip"]);
check('and the lowest PHP, from composer.json', $manifest['min_php'], preg_match('/\\d+(?:\\.\\d+)*/', (string)json_decode((string)file_get_contents($root . '/composer.json'), true)['require']['php'], $minimumPhp) ? $minimumPhp[0] : null);
check('and the checksum and size of the package', [$manifest['sha256'], $manifest['size']], [hash_file('sha256', $zipPath), filesize($zipPath)]);
check('a backup is required before installing it', $manifest['requires_backup'], true);

$zip = new ZipArchive();
$zip->open($zipPath);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
check('the manifest counts its files', $manifest['files'], count($names));
check('it holds the code the site runs', [in_array('src/App.php', $names, true), in_array('public/index.php', $names, true), in_array('vendor/autoload.php', $names, true), in_array('themes/default/theme.yaml', $names, true), in_array('VERSION', $names, true), in_array('scripts/use-starter.php', $names, true)], [true, true, true, true, true, true]);
check('and the VERSION inside is the version of the manifest', trim((string)$zip->getFromName('VERSION')), $version);
check('and a demo site to start from', in_array('starter/content/pages/index.md', $names, true) || count(array_filter($names, fn($n) => str_starts_with($n, 'starter/content/'))) > 0, true);
$leaks = array_values(array_filter($names, fn($n) => (bool)preg_match('#^(content|storage|tests|node_modules|\.git|\.github|\.claude|build|docs)/#', $n) || (str_starts_with($n, 'custom/') && $n !== 'custom/README.md') || (str_starts_with($n, 'public/uploads/') && $n !== 'public/uploads/.htaccess')));
check('and nothing of a site: no content, uploads, custom/, storage, tests, or settings', $leaks, []);
check('uploads keep the file that stops code running in them', in_array('public/uploads/.htaccess', $names, true), true);
require_once $root . '/vendor/autoload.php';
check('every file of the package is one an update is allowed to write (the two lists have not drifted apart)', array_values(array_filter($names, fn($n) => !GnozioCMS\UpdateInstaller::isAllowedPath($n))), []);
// An update is installed by the installer of the site being updated, which is older than the package. It refuses any file
// it does not know, so a new single file (outside the folders below) would stop every older site from updating. This is the
// list of 0.1.23; add to it only when the oldest site that must still update is past the release that taught the installer
// the file (the importer, scripts/import-wordpress.php, is not shipped for this reason: it runs from a copy of the repository).
$known = ['public/index.php', 'public/.htaccess', 'public/uploads/.htaccess', 'custom/README.md', 'scripts/use-starter.php', 'CHANGELOG.md', 'README.md', 'LICENSE', 'update.md', 'composer.json', 'composer.lock', 'VERSION'];
$folders = '#^(src|admin|vendor|themes|starter|public/assets)/#';
check('and no single file an installer of 0.1.23 does not know, which would stop older sites from updating', array_values(array_filter($names, fn($n) => !str_ends_with($n, '/') && !preg_match($folders, $n) && !in_array($n, $known, true))), []);
$bad = array_values(array_filter($names, fn($n) => str_starts_with($n, '/') || str_contains($n, '..') || str_contains($n, '\\') || basename($n) === '.DS_Store'));
check('every name is a safe relative path', $bad, []);
$zip->close();

// A site's own files lying in the folder it is built from never get in.
$work = $out . '/work';
mkdir($work, 0775, true);
exec('cp -R ' . escapeshellarg($root . '/src') . ' ' . escapeshellarg($work . '/src'));
foreach (['admin', 'themes', 'starter', 'public/assets', 'vendor'] as $d) { @mkdir($work . '/' . dirname($d), 0775, true); }
foreach (['VERSION', 'composer.json'] as $f) { copy($root . '/' . $f, $work . '/' . $f); }
mkdir($work . '/scripts', 0775, true);
copy($root . '/scripts/build-release.php', $work . '/scripts/build-release.php');
mkdir($work . '/content/pages', 0775, true);
file_put_contents($work . '/content/pages/secret.md', 'private');
mkdir($work . '/public/uploads/media', 0775, true);
file_put_contents($work . '/public/uploads/media/photo.jpg', 'x');
mkdir($work . '/custom', 0775, true);
file_put_contents($work . '/custom/custom.css', 'x');
mkdir($work . '/storage/db', 0775, true);
file_put_contents($work . '/storage/db/app.sqlite', 'x');
shell_exec('php ' . escapeshellarg($work . '/scripts/build-release.php') . ' ' . escapeshellarg($out . '/b') . ' 2>&1');
$zip->open($out . "/b/gnoziocms-$version.zip");
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
$zip->close();
check('a folder with a site in it gives a package without the site', array_values(array_filter($names, fn($n) => !str_starts_with($n, 'src/') && $n !== 'VERSION' && $n !== 'composer.json')), []);

// The same version builds to the same bytes.
$run($out . '/c', '--repo=acme/site');
check('the same version builds to the same package', hash_file('sha256', $out . "/c/gnoziocms-$version.zip"), $manifest['sha256']);

// A version that is not one is refused.
$bogus = $out . '/bogus';
mkdir($bogus . '/scripts', 0775, true);
copy($root . '/scripts/build-release.php', $bogus . '/scripts/build-release.php');
file_put_contents($bogus . '/VERSION', "not-a-version\n");
file_put_contents($bogus . '/composer.json', '{}');
check('a VERSION that is not x.y.z builds nothing', [str_contains((string)shell_exec('php ' . escapeshellarg($bogus . '/scripts/build-release.php') . ' ' . escapeshellarg($bogus . '/out') . ' 2>&1'), 'VERSION must be like'), is_dir($bogus . '/out')], [true, false]);

// ---- starting a new site from the demo
$fresh = $out . '/fresh';
mkdir($fresh . '/scripts', 0775, true);
mkdir($fresh . '/starter/content/pages', 0775, true);
mkdir($fresh . '/starter/uploads/media', 0775, true);
copy($root . '/scripts/use-starter.php', $fresh . '/scripts/use-starter.php');
file_put_contents($fresh . '/starter/content/pages/index.md', "---\ntitle: Home\n---\n\nDemo\n");
file_put_contents($fresh . '/starter/uploads/media/a.jpg', 'jpg');
$use = fn() => (string)shell_exec('php ' . escapeshellarg($fresh . '/scripts/use-starter.php') . ' 2>&1');
check('the demo is copied into a new site', [str_starts_with($use(), 'This site already') , is_file($fresh . '/content/pages/index.md'), is_file($fresh . '/public/uploads/media/a.jpg')], [false, true, true]);
file_put_contents($fresh . '/content/pages/index.md', 'my own words');
check('a site that has pages is never overwritten', [str_starts_with($use(), 'This site already has pages'), file_get_contents($fresh . '/content/pages/index.md')], [true, 'my own words']);

exec('rm -rf ' . escapeshellarg($out));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
