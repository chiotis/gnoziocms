<?php
/*
 * Theme > Footer Blocks: the shape of what is stored (a list for every language, or a set for each) and which blocks a language
 * shows.
 *   php tests/unit/footer-blocks.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\FooterBlocks as F;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }

$a = ['type' => 'logos', 'heading' => 'A'];
$b = ['type' => 'logos', 'heading' => 'B'];

check('a plain list is for every language', [F::forLanguage([$a], 'el', 'el'), F::forLanguage([$a], 'en', 'el')], [[$a], [$a]]);
check('a language shows its own set, else the default', [F::forLanguage(['default' => [$a], 'en' => [$b]], 'en', 'el'), F::forLanguage(['default' => [$a], 'en' => []], 'en', 'el'), F::forLanguage(['default' => [$a]], 'fr', 'el')], [[$b], [$a], [$a]]);
check('the site\'s own language always shows the default set', F::forLanguage(['default' => [$a], 'el' => [$b]], 'el', 'el'), [$a]);
check('a set for another language only: nothing in the site\'s own', F::forLanguage(['en' => [$b]], 'el', 'el'), []);
check('nothing stored, nothing shown', [F::forLanguage(null, 'el', 'el'), F::forLanguage('x', 'el', 'el')], [[], []]);

check('the editor gets a set for each key; a plain list is the default one', [F::sets([$a], ['default', 'en']), F::sets(['default' => [$a], 'en' => [$b], 'fr' => [$b]], ['default', 'en']), F::sets(null, ['default'])], [['default' => [$a], 'en' => []], ['default' => [$a], 'en' => [$b]], ['default' => []]]);

check('empty sets are not kept, and only the default set is a plain list', [F::toStore(['default' => [$a], 'en' => []]), F::toStore(['default' => [$a], 'en' => [$b]]), F::toStore(['default' => [], 'en' => [$b]]), F::toStore(['default' => [], 'en' => []])], [[$a], ['default' => [$a], 'en' => [$b]], ['en' => [$b]], []]);

check('a posted map is the sets; the keys that are not languages are left out', F::fromPost(['default' => [$a], 'en' => [$b], 'x y' => [$a], '<b>' => [$a], 'de' => 'no'], null), ['default' => [$a], 'en' => [$b]]);
check('a posted list is the default set, and the other languages stored now stay', F::fromPost([$a], ['default' => [$b], 'en' => [$b]]), ['default' => [$a], 'en' => [$b]]);
check('a posted map says what each language has, emptied ones included', F::fromPost(['default' => [$a], 'en' => []], ['default' => [$b], 'en' => [$b]]), ['default' => [$a], 'en' => []]);
check('a language that was not posted keeps its stored set', F::fromPost(['default' => [$a]], ['default' => [$b], 'fr' => [$b]]), ['default' => [$a], 'fr' => [$b]]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
