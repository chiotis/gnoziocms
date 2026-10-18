<?php
/*
 * The rules that go into robots.txt: what is kept, what is dropped, and that nothing typed can add a line of its own.
 *   php tests/unit/robots.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\RobotsTxt;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

check('one path per line', RobotsTxt::rules("/private/\n/thank-you\n"), ['/private/', '/thank-you']);
check('Windows line ends and blank lines', RobotsTxt::rules("/a\r\n\r\n/b\r\n"), ['/a', '/b']);
check('spaces around a path are trimmed', RobotsTxt::rules("  /a  \n\t/b\t"), ['/a', '/b']);
check('a path must start with a slash', RobotsTxt::rules("private\nhttps://x.test/p\n/ok"), ['/ok']);
check('crawler wildcards are kept', RobotsTxt::rules("/*.pdf\$\n/search?q=*\n/a*b"), ['/*.pdf$', '/search?q=*', '/a*b']);
check('a line typed as a whole rule is accepted', RobotsTxt::rules("Disallow: /x\ndisallow:/y\nDISALLOW :   /z"), ['/x', '/y', '/z']);
check('a path appears once, in the order first typed', RobotsTxt::rules("/b\n/a\n/b\n/a"), ['/b', '/a']);
check('a comment or a second directive is dropped', RobotsTxt::rules("# note\n/a # inline\nAllow: /b\nSitemap: /c\nUser-agent: x"), []);
check('spaces inside a path are dropped', RobotsTxt::rules("/a b\n/a\tb"), []);
check('a path with quotes or angle brackets is dropped', RobotsTxt::rules("/a\"b\n/<script>\n/a'b"), ["/a'b"]);
check('control characters cannot start a new line', RobotsTxt::rules(["/a\nUser-agent: evil", "/b\rSitemap: x", "/c"]), ['/c']);
check('too long a path is dropped', RobotsTxt::rules('/' . str_repeat('a', 200)), []);
check('a path of the longest allowed length is kept', count(RobotsTxt::rules('/' . str_repeat('a', 199))), 1);
check('only 100 rules are kept', count(RobotsTxt::rules(implode("\n", array_map(fn($i) => "/p$i", range(1, 150))))), 100);
check('a list works as well as text', RobotsTxt::rules(['/a', ' /b ', '', '/a']), ['/a', '/b']);
check('percent-encoded and Unicode-free paths are fine', RobotsTxt::rules("/caf%C3%A9/\n/καλημέρα"), ['/caf%C3%A9/']);
check('nothing typed gives no rules', RobotsTxt::rules(''), []);

check('with no rules the file is what it always was', RobotsTxt::render('https://x.test/sitemap.xml'), "User-agent: *\nAllow: /\nSitemap: https://x.test/sitemap.xml\n");
check('rules go between Allow and Sitemap', RobotsTxt::render('https://x.test/sitemap.xml', ['/a', '/b/']), "User-agent: *\nAllow: /\nDisallow: /a\nDisallow: /b/\nSitemap: https://x.test/sitemap.xml\n");
check('rules are checked again when written', RobotsTxt::render('https://x.test/s.xml', ["/ok", "bad\nUser-agent: evil"]), "User-agent: *\nAllow: /\nDisallow: /ok\nSitemap: https://x.test/s.xml\n");
check('the sitemap address cannot carry a line break', RobotsTxt::render("https://x.test/s.xml\nDisallow: /"), "User-agent: *\nAllow: /\nSitemap: https://x.test/s.xmlDisallow: /\n");

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
