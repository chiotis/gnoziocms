<?php
/*
 * Theme > Custom CSS: reading and writing custom/assets/css/custom.css.
 *   php tests/unit/custom-css.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\CustomCss;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }

$dir = sys_get_temp_dir() . '/faros-css-' . bin2hex(random_bytes(4));
mkdir($dir);
$css = new CustomCss($dir);
$file = $dir . '/assets/css/custom.css';

check('a site with no file reads as empty', $css->read(), '');
check('saving nothing creates nothing', [$css->save("  \n"), is_file($file)], [['ok' => true], false]);
check('the folders are made on the first save', [$css->save(".a { color: red; }"), is_file($file)], [['ok' => true], true]);
check('the file ends with one newline', $css->read(), ".a { color: red; }\n");
check('line endings become \\n and trailing space goes', [$css->save("a{}\r\nb{}\r\n\r\n\r\n"), $css->read()], [['ok' => true], "a{}\nb{}\n"]);
$time = 1000000000;
touch($file, $time);
clearstatcache();
$css->save("a{}\nb{}");
clearstatcache();
check('the same text is not written again', filemtime($file), $time);
check('no temporary file is left', glob($dir . '/assets/css/*.tmp'), []);
check('an empty text empties the file', [$css->save(''), $css->read(), is_file($file)], [['ok' => true], '', true]);
$big = $css->save(str_repeat('a', CustomCss::MAX_BYTES + 1));
check('too large is refused and the file stays', [$big['ok'], $css->read()], [false, '']);

foreach (glob($dir . '/assets/css/*') as $f) unlink($f);
rmdir($dir . '/assets/css'); rmdir($dir . '/assets'); rmdir($dir);
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
