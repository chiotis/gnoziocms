<?php
/*
 * Builds the package a site installs: the code of one version and nothing of any site.
 *
 *   php scripts/build-release.php [output-dir] [--repo=owner/name]
 *
 * Writes gnoziocms-<version>.zip and release.json (what the Updates screen reads: the version, the lowest PHP, where the
 * package is, its SHA-256 and size) into the output directory (build/ by default). The package is built from a list of
 * what to include, so a site's own content, uploads, custom/, storage or settings can never end up in it, whatever is
 * lying around in the folder it is built from. The same version builds to the same bytes.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$out = $root . '/build';
$repo = getenv('GITHUB_REPOSITORY') ?: 'chiotis/gnoziocms';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);
    } else {
        $out = $arg;
    }
}

/** What the package holds: folders (everything inside) and single files, relative to the root. */
const DIRECTORIES = ['src', 'admin', 'vendor', 'themes', 'starter', 'public/assets'];
const FILES = ['public/index.php', 'public/.htaccess', 'public/uploads/.htaccess', 'custom/README.md', 'scripts/use-starter.php', 'VERSION', 'CHANGELOG.md', 'README.md', 'LICENSE', 'update.md', 'composer.json', 'composer.lock'];

$version = trim((string)@file_get_contents($root . '/VERSION'));
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "VERSION must be like 0.1.20 (it is '$version').\n");
    exit(1);
}
$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
preg_match('/\d+(\.\d+)?/', (string)($composer['require']['php'] ?? '8.5'), $php);
$minPhp = $php[0] ?? '8.5';

$files = [];
foreach (FILES as $file) {
    if (is_file($root . '/' . $file)) {
        $files[] = $file;
    }
}
foreach (DIRECTORIES as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isLink() || !$item->isFile() || $item->getFilename() === '.DS_Store') {
            continue;
        }
        $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
    }
}
sort($files, SORT_STRING);
$files = array_values(array_unique($files));

if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "Cannot create $out.\n");
    exit(1);
}
$name = "gnoziocms-$version.zip";
$zipPath = $out . '/' . $name;
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot write $zipPath.\n");
    exit(1);
}
// A fixed time and permissions, so the same version always builds to the same package.
$time = (int)(getenv('SOURCE_DATE_EPOCH') ?: 1767225600);
foreach ($files as $file) {
    $zip->addFile($root . '/' . $file, $file);
    $zip->setMtimeName($file, $time);
    $zip->setExternalAttributesName($file, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
$zip->close();

$sha = hash_file('sha256', $zipPath);
$manifest = [
    'version' => $version,
    'min_php' => $minPhp,
    'package' => $name,
    'package_url' => "https://github.com/$repo/releases/download/v$version/$name",
    'sha256' => $sha,
    'size' => filesize($zipPath),
    'files' => count($files),
    'requires_backup' => true,
];
file_put_contents($out . '/release.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Built $name: " . count($files) . " files, " . round($manifest['size'] / 1048576, 1) . " MB, sha256 $sha\n";
