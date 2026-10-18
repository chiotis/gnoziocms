<?php
/*
 * The GnozioCMS namespace and old FarosCMS integrations share the same classes in either loading order.
 *   php tests/unit/namespace-compatibility.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual) . ' expected ' . json_encode($expected)) . "\n"; }

// No legacy class lookup precedes the callback: a type declaration alone does not invoke an autoloader.
$legacyCallback = static fn(FarosCMS\ContentItem $item): string => $item->slug;
$current = new GnozioCMS\ContentItem('pages', 'example', 'en', [], '', '', '', 0);
check('new instances work with old custom callback type hints', $legacyCallback($current), 'example');
check('both names describe the same new instance', $current instanceof FarosCMS\ContentItem, true);
$legacy = new FarosCMS\ContentItem('pages', 'legacy', 'en', [], '', '', '', 0);
check('old construction keeps the canonical class identity', get_class($legacy), GnozioCMS\ContentItem::class);
check('old instances work with new callback type hints', (static fn(GnozioCMS\ContentItem $item): string => $item->slug)($legacy), 'legacy');

// This class has not been loaded above; request the old name first and make sure a second copy is never declared.
check('the old name can load a class first', FarosCMS\FrontRoute::langPrefix('en', 'el'), 'en/');
check('a class loaded under its old name also works under the new name', GnozioCMS\FrontRoute::langPrefix('en', 'el'), 'en/');
check('reflection resolves the legacy alias to the current class', (new ReflectionClass(FarosCMS\FrontRoute::class))->getName(), GnozioCMS\FrontRoute::class);

// Old serialized custom data can still construct the aliased class, keeping the original values.
$serialized = serialize($legacy);
$serialized = str_replace('O:21:"GnozioCMS\\ContentItem"', 'O:20:"FarosCMS\\ContentItem"', $serialized);
check('the old serialized fixture names the legacy class', str_starts_with($serialized, 'O:20:"FarosCMS\\ContentItem":'), true);
$restored = unserialize($serialized);
check('legacy serialized objects remain readable', [$restored instanceof GnozioCMS\ContentItem, $restored->slug], [true, 'legacy']);
check('missing classes fail normally in both namespaces', [class_exists('FarosCMS\\NoSuchClass'), class_exists('GnozioCMS\\NoSuchClass')], [false, false]);
check('the bundled loader still loads external dependencies', class_exists(Twig\Environment::class), true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
