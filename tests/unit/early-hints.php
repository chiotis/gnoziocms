<?php
/*
 * EarlyHints: the Link preload headers of a page: its stylesheet and the picture that opens it (the first source of its
 * <picture>, with the same srcset, sizes and type), nothing for a feed, a missing stylesheet or a page without such a picture,
 * and nothing that could break the header.
 *   php tests/unit/early-hints.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\EarlyHints;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$css = '/_themes/default/css/site.css?v=abc';
$picture = '<picture><source type="image/avif" srcset="/a-360.avif 360w, /a-800.avif 800w" sizes="100vw"><source type="image/webp" srcset="/a-360.webp 360w" sizes="100vw">'
    . '<img src="/uploads/a.png" alt="" width="1600" height="900" loading="eager" decoding="sync" fetchpriority="high"></picture>';
$lazy = '<picture><source type="image/webp" srcset="/b-360.webp 360w" sizes="100vw"><img src="/uploads/b.png" loading="lazy"></picture>';
$page = fn(string $body): string => '<!doctype html><html><head><title>x</title></head><body>' . $body . '</body></html>';

check('the stylesheet and the opening picture, its first source as it is in the page', EarlyHints::links($page($lazy . $picture), $css), [
    '</_themes/default/css/site.css?v=abc>; rel=preload; as=style',
    '</a-360.avif>; rel=preload; as=image; imagesrcset="/a-360.avif 360w, /a-800.avif 800w"; imagesizes="100vw"; type="image/avif"; fetchpriority=high',
]);
check('a page without an opening picture has the stylesheet only', EarlyHints::links($page($lazy), $css), ['</_themes/default/css/site.css?v=abc>; rel=preload; as=style']);
check('a plain <img> that opens the page is preloaded by its address', EarlyHints::links($page('<img src="/uploads/c.jpg" fetchpriority="high">'), ''), ['</uploads/c.jpg>; rel=preload; as=image; fetchpriority=high']);
check('an <img> after a closed <picture> is not taken for its source', EarlyHints::links($page($lazy . '<img src="/uploads/d.jpg" fetchpriority="high">'), ''), ['</uploads/d.jpg>; rel=preload; as=image; fetchpriority=high']);
check('a stylesheet that does not exist (no version) is not preloaded', EarlyHints::links($page(''), '/_themes/x/css/site.css'), []);
check('a feed or a sitemap has none', EarlyHints::links('<?xml version="1.0"?><rss></rss>', $css), []);
check('an address that could break the header is left out', EarlyHints::links($page('<img src="/a&quot;b.jpg" fetchpriority="high">'), "/x.css?v=1\r\nX: y"), []);
check('entities in the markup are the characters in the header', EarlyHints::links($page('<img src="/a.jpg?x=1&amp;y=2" fetchpriority="high">'), ''), ['</a.jpg?x=1&y=2>; rel=preload; as=image; fetchpriority=high']);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
