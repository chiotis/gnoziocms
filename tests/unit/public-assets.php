<?php
/*
 * Theme and custom/ files copied into public/ (ThemeAssets::publish), so a web server that answers 404 for a missing .css itself
 * (CloudPanel's nginx) needs no rule: what is copied and what is not, a change and a deletion carried over, the block bundles as
 * files, a public/ that cannot be written, and image variants named by an address with no extension until they exist.
 *   php tests/unit/public-assets.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\{Images, Theme, ThemeAssets};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }
function put(string $file, string $content, ?int $mtime = null): void { @mkdir(dirname($file), 0775, true); file_put_contents($file, $content); if ($mtime !== null) touch($file, $mtime); }
function wipe(string $dir): void { if (!is_dir($dir)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $i) { $i->isDir() ? rmdir($i->getPathname()) : unlink($i->getPathname()); } rmdir($dir); }

/** A site of its own, one per part, since publish() remembers its work for the rest of the request. */
function site(string $name): string
{
    $base = sys_get_temp_dir() . '/gnozio-public-assets-' . getmypid() . '-' . $name;
    wipe($base);
    put($base . '/themes/plain/theme.yaml', "name: Plain\n");
    put($base . '/themes/plain/assets/css/site.css', 'body{color:red}', 1_700_000_000);
    put($base . '/themes/plain/assets/fonts/a.woff2', 'font');
    put($base . '/themes/plain/assets/notes.md', 'not served');
    put($base . '/themes/plain/blocks/hero/block.css', '.hero{}');
    put($base . '/themes/plain/blocks/hero/block.js', 'hero()');
    put($base . '/themes/plain/blocks/hero/block.twig', '<div></div>');
    put($base . '/themes/plain/blocks/hero/preview.svg', '<svg/>');
    put($base . '/custom/assets/css/custom.css', '.c{}');
    put($base . '/custom/assets/logo.svg', '<svg/>');
    @mkdir($base . '/public', 0775, true);
    return $base;
}

// ---- what is copied
$base = site('copy');
$theme = new Theme($base, 'plain');
$url = $theme->assetUrl('https://s.test', 'css/site.css');
$pub = $base . '/public';
check('the address stays the one of the theme', str_starts_with($url, 'https://s.test/_themes/plain/css/site.css?v='), true);
check('the theme\'s files are copied to that address under public/, with their date', [is_file($pub . '/_themes/plain/css/site.css'), (string)@file_get_contents($pub . '/_themes/plain/css/site.css'), @filemtime($pub . '/_themes/plain/css/site.css')], [true, 'body{color:red}', 1_700_000_000]);
check('fonts and the block files a browser asks for too', [is_file($pub . '/_themes/plain/fonts/a.woff2'), is_file($pub . '/_themes/plain/blocks/hero/block.css'), is_file($pub . '/_themes/plain/blocks/hero/preview.svg')], [true, true, true]);
check('templates and other files that are not served are not', [is_file($pub . '/_themes/plain/notes.md'), is_file($pub . '/_themes/plain/blocks/hero/block.twig')], [false, false]);
check('custom/ files go to /_custom/, but not its SVG files (index.php gives them a CSP)', [is_file($pub . '/_custom/css/custom.css'), is_file($pub . '/_custom/logo.svg')], [true, false]);

// ---- a change, an addition and a deletion are carried over by the next request's publish()
$base = site('change');
check('a first publish', ThemeAssets::publish($base, 'plain'), true);
put($base . '/themes/plain/assets/css/site.css', 'body{color:blue}', 1_700_000_100);
put($base . '/themes/plain/assets/js/site.js', 'go()');
unlink($base . '/themes/plain/assets/fonts/a.woff2');
put($base . '/public/_themes/plain/_blocks/old.css', 'old bundle');
// publish() works once per request and site; a second site folder with the same files stands for the next request.
rename($base, $base . '-next');
$base .= '-next';
check('the next publish', ThemeAssets::publish($base, 'plain'), true);
$pub = $base . '/public/_themes/plain';
check('a changed file is copied again, a new one added, a deleted one removed', [(string)@file_get_contents($pub . '/css/site.css'), is_file($pub . '/js/site.js'), is_file($pub . '/fonts/a.woff2'), is_dir($pub . '/fonts')], ['body{color:blue}', true, false, false]);
check('and the old block bundles go with the change', is_file($pub . '/_blocks/old.css'), false);

// ---- block bundles are files
$base = site('bundles');
$theme = new Theme($base, 'plain');
$css = $theme->blockStylesheetUrl('', ['hero']);
$js = $theme->blockScriptUrl('', ['hero']);
check('the block stylesheet of a page is a file with a hash in its name', (bool)preg_match('#^/_themes/plain/_blocks/[0-9a-f]{16}\.css\?b=hero$#', $css), true);
check('with the blocks\' styles in it', (string)@file_get_contents($base . '/public' . strtok($css, '?')), "/* hero */\n.hero{}\n");
check('and the script too', [(bool)preg_match('#^/_themes/plain/_blocks/[0-9a-f]{16}\.js\?b=hero$#', $js), (string)@file_get_contents($base . '/public' . strtok($js, '?'))], [true, "/* hero */\nhero()\n;\n"]);

// ---- a public/ that cannot be written: index.php answers the same addresses
$base = site('readonly');
chmod($base . '/public', 0555);
if (is_writable($base . '/public')) {
    echo "skip a public/ that cannot be written (this user writes anyway)\n";
} else {
    $theme = new Theme($base, 'plain');
    $url = $theme->assetUrl('', 'css/site.css');
    check('nothing is copied, the address is the same', [is_dir($base . '/public/_themes'), str_starts_with($url, '/_themes/plain/css/site.css?v=')], [false, true]);
    check('and the bundles keep their address of index.php', str_starts_with($theme->blockStylesheetUrl('', ['hero']), '/_themes/plain/_blocks.css?b=hero&v='), true);
}
chmod($base . '/public', 0775);

// ---- image variants: an address with no extension until the file exists
$base = site('images');
$public = $base . '/public';
$image = imagecreatetruecolor(800, 400);
@mkdir($public . '/uploads/2026', 0775, true);
imagejpeg($image, $public . '/uploads/2026/photo one.jpg');
$images = new Images($public);
$info = $images->info('/uploads/2026/photo%20one.jpg');
$version = base_convert((string)filemtime($public . '/uploads/2026/photo one.jpg'), 10, 36);
check('a variant not yet written is named by an address index.php answers', $images->variantUrl($info, 360), '/_image/2026/photo%20one.jpg/360-' . $version . '-w72');
if (Images::webpSupported()) {
    $_SERVER['REQUEST_METHOD'] = 'HEAD';
    ob_start();
    $handled = @Images::handle($public, '/_image/2026/photo%20one.jpg/360-' . $version . '-w72');
    ob_end_clean();
    check('which writes the file', [$handled, is_file($public . '/uploads/_v/2026/photo one.jpg/360-' . $version . '-w72.webp')], [true, true]);
    check('and from then on the page names the file itself', $images->variantUrl($info, 360), '/uploads/_v/2026/photo%20one.jpg/360-' . $version . '-w72.webp');
} else {
    echo "skip writing a variant (no WebP in this PHP)\n";
}

foreach (['copy', 'change-next', 'bundles', 'readonly', 'images'] as $name) {
    wipe(sys_get_temp_dir() . '/gnozio-public-assets-' . getmypid() . '-' . $name);
}
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
