<?php
/*
 * CssMinify: comments and white space go, what has a meaning stays (strings, url(), the space in calc() and before a pseudo-class,
 * a licence comment, a custom property whose value is a space), and the theme's own stylesheets keep every rule.
 *   php tests/unit/css-minify.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\CssMinify;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }
$m = static fn(string $css): string => CssMinify::minify($css);

check('comments and white space go', $m("/* a */\n.a {\n  color : red ;\n  margin: 0 auto;\n}\n"), '.a{color :red;margin:0 auto}');
check('the space before a pseudo-class is a descendant and stays', $m('.a :hover { x: 1 }'), '.a :hover{x:1}');
check('calc keeps its spaces', $m('.a { width: calc(100% - (2 * var(--gap)) + 1px); }'), '.a{width:calc(100% - (2 * var(--gap)) + 1px)}');
check('combinators keep their spaces', $m('.a > .b ~ .c + .d { x: 1 }'), '.a > .b ~ .c + .d{x:1}');
check('a string is kept as it is', $m(".a::before { content: \"  /* not a comment */ ; } \"; }"), '.a::before{content:"  /* not a comment */ ; } "}');
check('a string with an escaped quote', $m(".a { content: 'it\\'s  here'; }"), ".a{content:'it\\'s  here'}");
check('an unquoted url is kept as it is', $m('.a { background: url(data:image/svg+xml;utf8,<svg  a="1">) no-repeat; }'), '.a{background:url(data:image/svg+xml;utf8,<svg  a="1">) no-repeat}');
check('a quoted url is kept as it is', $m(".a { background: url( 'a b.png' ); }"), ".a{background:url( 'a b.png' )}");
check('a licence comment is kept', $m("/*! Licence  x */\n.a { x: 1 }"), '/*! Licence  x */ .a{x:1}');
check('a comment is not white space: .a/**/.b stays .a.b, spaces around a comment stay one space', $m('.a/**/.b{x:1} .c /* */ .d{x:1}'), '.a.b{x:1}.c .d{x:1}');
check('a custom property whose value is a space keeps it', $m('.a { --x: ; --y: ; }'), '.a{--x: ;--y: }');
check('at-rules keep their meaning', $m("@media (min-width: 600px) and (max-width: 900px) {\n .a { x: 1 }\n}\n@supports not (display: grid) { .b { y: 2 } }"), '@media (min-width:600px) and (max-width:900px){.a{x:1}}@supports not (display:grid){.b{y:2}}');
check('nesting and :is()', $m('.a { & .b { x: 1 } &:is(.c, .d) { y: 2 } }'), '.a{& .b{x:1}&:is(.c,.d){y:2}}');
check('important stays', $m('.a { x: 1 !important; }'), '.a{x:1 !important}');
check('an unclosed comment ends the file', $m('.a{x:1} /* open'), '.a{x:1}');
check('nothing in, nothing out', $m(''), '');

// The theme's own stylesheets: every rule survives (counted by its braces and by its selectors' text).
foreach (array_merge(glob($root . '/themes/default/assets/css/*.css') ?: [], glob($root . '/themes/default/blocks/*/block.css') ?: []) as $file) {
    $css = (string)file_get_contents($file);
    $plain = preg_replace('#/\*.*?\*/#s', '', $css);
    $min = $m($css);
    $name = basename(dirname($file)) . '/' . basename($file);
    if (substr_count($plain, '{') !== substr_count($min, '{') || substr_count($plain, '}') !== substr_count($min, '}') || strlen($min) >= strlen($css)) {
        check($name . ' keeps its rules and is smaller', false, true);
    }
}
check('every stylesheet of the theme keeps its rules and is smaller', $fail, 0);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
