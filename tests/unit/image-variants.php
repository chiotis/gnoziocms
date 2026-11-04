<?php
/*
 * Picture sizes (Images): the widths offered, AVIF before WebP where GD has it, names that carry the encoding, the WebP of older
 * releases still answered, a deferred picture (the slides of a slider after the first), the sizes a page named written after the
 * response, and the cron job's fill (every size written, files of an older encoding removed once they are old).
 *   php tests/unit/image-variants.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\Images;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

if (!Images::webpSupported()) {
    echo "skip (no WebP in this PHP's GD)\nALL PASSED\n";
    exit(0);
}

$base = sys_get_temp_dir() . '/gnozio-image-variants-' . getmypid();
register_shutdown_function(static function () use ($base): void { @exec('rm -rf ' . escapeshellarg($base)); });
$public = $base . '/public';
@mkdir($public . '/uploads/media', 0775, true);
$source = $public . '/uploads/media/wide.png';
imagepng(imagecreatetruecolor(1000, 500), $source);
$version = base_convert((string)filemtime($source), 10, 36);
$avif = Images::avifSupported();

// ---- widths
check('a phone of 412 px at 1.75x has a step of its own (800)', Images::widthsFor(1000), [360, 540, 720, 800, 960, 1000]);
check('the formats, best first', Images::formats(), $avif ? ['avif', 'webp'] : ['webp']);

// ---- markup
$images = new Images($public);
$html = $images->render('/uploads/media/wide.png', ['alt' => '', 'sizes' => '100vw']);
check('a WebP source, its names carrying the encoding', str_contains($html, '/_image/media/wide.png/800-' . $version . '-w72 800w'), true);
if ($avif) {
    check('an AVIF source comes before the WebP one', strpos($html, 'type="image/avif"') < strpos($html, 'type="image/webp"'), true);
    check('its names carry its own encoding', str_contains($html, '/_image/media/wide.png/800-' . $version . '-a50 800w'), true);
}
check('the sizes it named are waiting to be written', $images->pendingCount(), count(Images::widthsFor(1000)) * count(Images::formats()));

$deferred = (new Images($public))->render('/uploads/media/wide.png', ['alt' => '', 'defer' => true]);
[$live, $noscript] = explode('<noscript>', $deferred, 2);
check('a deferred picture has no srcset the browser would fetch', preg_match('/\ssrcset="/', $live), 0);
check('its addresses wait in data-srcset and data-src', [str_contains($live, 'data-srcset="'), str_contains($live, 'data-src="/uploads/media/wide.png"')], [true, true]);
check('its src is a transparent pixel', str_contains($live, 'src="data:image/gif;base64,'), true);
check('the picture itself is in <noscript>', str_contains($noscript, ' srcset="') && str_contains($noscript, 'src="/uploads/media/wide.png"'), true);
check('a picture that opens the page is never deferred', str_contains((new Images($public))->render('/uploads/media/wide.png', ['defer' => true, 'priority' => true]), 'data-src'), false);

// ---- written after the response
$after = new Images($public);
$after->variantUrl($after->info('/uploads/media/wide.png'), 360);
check('a size the page named is written after the response', [$after->generatePending(microtime(true) + 30), is_file($public . '/uploads/_v/media/wide.png/360-' . $version . '-w72.webp')], [1, true]);
check('and the page names the file from then on', $after->variantUrl($after->info('/uploads/media/wide.png'), 360), '/uploads/_v/media/wide.png/360-' . $version . '-w72.webp');
check('nothing is written once the time is up', (function () use ($public): int { $i = new Images($public); $i->variantUrl($i->info('/uploads/media/wide.png'), 540); return $i->generatePending(microtime(true) - 1); })(), 0);

// ---- the addresses index.php answers
$handle = static function (string $uri) use ($public): array {
    $_SERVER['REQUEST_METHOD'] = 'HEAD';
    ob_start();
    $handled = @Images::handle($public, $uri);
    return [$handled, ob_get_clean() === 'Not found' ? 404 : 200];
};
$handle('/_image/media/wide.png/540-' . $version);
check('the WebP of an older release (no tag) is still written for pages kept from before', is_file($public . '/uploads/_v/media/wide.png/540-' . $version . '.webp'), true);
check('an encoding that is not the current one is not answered', $handle('/_image/media/wide.png/540-' . $version . '-w90'), [true, 404]);
check('a file name whose extension does not match its tag is not answered', $handle('/uploads/_v/media/wide.png/540-' . $version . '-a50.webp'), [true, 404]);
if ($avif) {
        $handle('/_image/media/wide.png/540-' . $version . '-a50');
    check('an AVIF size is written on demand', is_file($public . '/uploads/_v/media/wide.png/540-' . $version . '-a50.avif'), true);
}

// ---- the cron job's fill
$old = $public . '/uploads/_v/media/wide.png/960-' . $version . '.webp';
copy($public . '/uploads/_v/media/wide.png/540-' . $version . '.webp', $old);
touch($old, time() - 30 * 86400);
$fresh = $public . '/uploads/_v/media/wide.png/540-' . $version . '.webp';
$written = (new Images($public))->fill(microtime(true) + 120);
$expected = [];
foreach (Images::widthsFor(1000) as $w) {
    foreach (Images::formats() as $f) {
        $expected[] = $w . '-' . $version . '-' . ($f === 'avif' ? 'a50' : 'w72') . '.' . $f;
    }
}
$names = array_map('basename', glob($public . '/uploads/_v/media/wide.png/*.{webp,avif}', GLOB_BRACE) ?: []);
check('fill writes every size and format that is missing', array_values(array_diff($expected, $names)), []);
check('and says how many', $written, count($expected) - 1 - ($avif ? 1 : 0));
check('a file of an older encoding is removed once it is old', is_file($old), false);
check('a recent one is kept (a page kept from before may still name it)', is_file($fresh), true);
check('a second run has nothing to do', (new Images($public))->fill(microtime(true) + 120), 0);
check('variants are never taken for pictures to fill', is_dir($public . '/uploads/_v/_v'), false);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
