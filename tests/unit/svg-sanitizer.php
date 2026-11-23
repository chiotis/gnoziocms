<?php
/*
 * Uploaded SVG drawings written again with only what is safe (audit S17): SvgSanitizer.
 *   php tests/unit/svg-sanitizer.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\SvgSanitizer;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }
function svg(string $inner, string $attrs = ''): string { return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10"' . $attrs . '>' . $inner . '</svg>'; }
function clean(string $inner, string $attrs = ''): string { return (string)SvgSanitizer::clean(svg($inner, $attrs)); }

$drawing = svg('<title>Logo</title><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs><g fill="url(#g)" transform="translate(1 1)"><path d="M0 0h10v10z"/><circle cx="5" cy="5" r="2" style="fill: #c00; stroke-width: 2"/><use href="#g" xlink:href="#g"/><text x="1" y="9" font-family="Arial">Hi</text></g>');
$kept = (string)SvgSanitizer::clean($drawing);
check('a plain drawing keeps its shapes, gradient, styles, local links and text', [str_contains($kept, '<path d="M0 0h10v10z"/>'), str_contains($kept, 'fill="url(#g)"'), str_contains($kept, 'style="fill: #c00; stroke-width: 2"'), str_contains($kept, 'href="#g"'), str_contains($kept, 'xlink:href="#g"'), str_contains($kept, '>Hi</text>'), str_contains($kept, '<title>Logo</title>')], [true, true, true, true, true, true, true]);

check('a script goes, with what it holds', clean('<script>alert(1)</script><rect width="1" height="1"/>'), svg('<rect width="1" height="1"/>') . "\n");
check('a script in another case or namespace prefix goes too', str_contains(clean('<svg:script xmlns:svg="http://www.w3.org/2000/svg">alert(1)</svg:script><rect/>'), 'alert'), false);
check('event attributes go', clean('<rect onclick="alert(1)" onload="x()" width="1"/>'), svg('<rect width="1"/>') . "\n");
check('an entity-encoded javascript: link is seen decoded and goes', str_contains(clean('<a href="&#106;avascript:alert(1)"><rect/></a>'), 'href'), false);
check('a link to another address goes; to a part of the drawing it stays', [str_contains(clean('<a xlink:href="https://evil.example/"><rect/></a>'), 'href'), str_contains(clean('<a href="#part"><rect/></a>'), 'href="#part"')], [false, true]);
check('<set> that sets an event attribute goes', str_contains(clean('<rect><set attributeName="onclick" to="alert(1)"/></rect>'), 'set'), false);
check('<animate> of href goes; of a colour it stays', [str_contains(clean('<a href="#x"><animate attributeName="href" values="javascript:alert(1)"/></a>'), 'animate'), str_contains(clean('<rect><animate attributeName="fill" values="red;blue" dur="2s"/></rect>'), '<animate attributeName="fill"')], [false, true]);
check('a style that imports goes', str_contains(clean('<style>@import url(https://evil.example/x.css); rect{fill:red}</style><rect/>'), '@import'), false);
check('a style with a local url stays, a remote one goes', [str_contains(clean('<style>rect{fill:url(#g)}</style><rect/>'), 'url(#g)'), str_contains(clean('<rect style="background:url(https://evil.example/t.png)"/>'), 'style'), str_contains(clean('<rect fill="url(https://evil.example/#g)"/>'), 'fill')], [true, false, false]);
check('CSS escapes, which could hide an import, are not kept', str_contains(clean('<style>@\\69mport url(x)</style><rect/>'), 'mport'), false);
check('foreignObject, iframe and HTML go', str_contains(clean('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="x"/></body></foreignObject><rect/>'), 'foreign'), false);
check('a picture inlined as a raster data: address stays; an SVG or remote one goes', [str_contains(clean('<image href="data:image/png;base64,iVBORw0KGgo=" width="1"/>'), 'data:image/png'), str_contains(clean('<image href="data:image/svg+xml;base64,PHN2Zz4=" width="1"/>'), 'href'), str_contains(clean('<image href="https://evil.example/a.png"/>'), 'href')], [true, false, false]);
check('editor metadata (Inkscape, Illustrator) is dropped, the drawing kept', clean('<sodipodi:namedview xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"/><rect xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" inkscape:label="r" width="2"/>') !== '' && !str_contains(clean('<sodipodi:namedview xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"/><rect/>'), 'namedview'), true);
check('comments and processing instructions go', SvgSanitizer::clean('<?xml version="1.0"?><?xml-stylesheet href="https://evil.example/x.css"?><!-- hi -->' . svg('<!-- c --><rect/>')), svg('<rect/>') . "\n");
check('a DOCTYPE (entities) is refused', SvgSanitizer::clean('<!DOCTYPE svg [<!ENTITY x "javascript:alert(1)">]>' . svg('<a href="&x;"><rect/></a>')), null);
check('what does not parse is refused', SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>'), null);
check('a root that is not an SVG is refused', [SvgSanitizer::clean('<html><body/></html>'), SvgSanitizer::clean('<svg><rect/></svg>'), SvgSanitizer::clean('')], [null, null, null]);
check('an unclosed url( to elsewhere goes', str_contains(clean('<rect fill="url(https://evil.example/"/>'), 'fill'), false);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
