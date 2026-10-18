<?php
/*
 * The Image block's picture width: the fields and their ranges, what is stored (nothing for the default), and the markup the template
 * draws for a percentage, for pixels, for the alignment and for "full width on a phone". The template is drawn with a stand-in image().
 *   php tests/unit/image-width.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\{Theme, BlockRegistry, FieldSchema};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$registry = new BlockRegistry(new Theme($root, 'default'), ['content_types' => fn() => [], 'forms' => fn() => ['' => '—']]);
$definition = $registry->get('image');
$fields = $definition['fields'];

$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($root . '/themes/default/blocks/image'), ['autoescape' => false]);
$twig->addFunction(new \Twig\TwigFunction('image', fn($src, $o = []) => '<img src="' . $src . '" data-sizes="' . ($o['sizes'] ?? '') . '" data-max="' . ($o['max_width'] ?? '') . '">'));
$twig->addFunction(new \Twig\TwigFunction('link_url', fn($l, $p = '') => (string)$l));
$twig->addFunction(new \Twig\TwigFunction('t', fn($k, $d = '') => $d));
$draw = function (array $values) use ($registry, $definition, $twig): string {
    $block = FieldSchema::resolve($definition['fields'], $values + ['image' => '/a.jpg', 'caption' => 'Cap']);
    $block += FieldSchema::resolve($definition['common'], $values);
    return $twig->render('block.twig', ['block' => $block]);
};
$figure = fn(string $html): string => preg_match('/<figure[^>]*>/', $html, $m) ? $m[0] : '';
$sizes = fn(string $html): string => preg_match('/data-sizes="([^"]*)"/', $html, $m) ? $m[1] : '';

// ---- the fields
check('the width is empty (the whole column), in percent, centered, full width on a phone', [$fields['image_width']['default'], $fields['image_width_unit']['default'], $fields['image_align']['default'], $fields['image_full_mobile']['default']], ['', '%', 'center', true]);
check('they are in the Design tab', $definition['design_fields'], ['image_width', 'image_width_unit', 'image_align', 'image_full_mobile']);
check('the units and their ranges', [array_keys($fields['image_width_unit']['options']), $fields['image_width']['units'], $fields['image_width']['ranges']], [['%', 'px'], 'image_width_unit', ['%' => [10, 100], 'px' => [40, 2000]]]);
check('the editor offers these percentages', $fields['image_width']['presets'], [25, 33, 50, 66, 75, 100]);
check('alignment and phone width matter only for a narrower picture', [$fields['image_align']['needs_size'], $fields['image_full_mobile']['needs_size']], ['image_width', 'image_width']);

// ---- ranges
$settle = fn(array $values) => FieldSchema::resolve($fields, $values);
$pair = fn(array $v) => [$settle($v)['image_width'], $settle($v)['image_width_unit']];
check('50 percent is kept', $pair(['image_width' => 50]), [50, '%']);
check('a number as text is kept', $pair(['image_width' => '33']), [33, '%']);
check('under 10 percent becomes 10', $pair(['image_width' => 3]), [10, '%']);
check('100 percent or more is the whole column', $pair(['image_width' => 250]), ['', '%']);
check('320 pixels are kept', $pair(['image_width' => 320, 'image_width_unit' => 'px']), [320, 'px']);
check('under 40 pixels becomes 40', $pair(['image_width' => 5, 'image_width_unit' => 'px']), [40, 'px']);
check('over 2000 pixels becomes 2000', $pair(['image_width' => 99999, 'image_width_unit' => 'px']), [2000, 'px']);
check('100 pixels is 100 pixels, not the whole column', $pair(['image_width' => 100, 'image_width_unit' => 'px']), [100, 'px']);
check('text, a negative number and an unknown unit are the default', [$pair(['image_width' => 'wide']), $pair(['image_width' => -5]), $pair(['image_width' => 40, 'image_width_unit' => 'em'])], [['', '%'], [10, '%'], [40, '%']]);

// ---- what is stored
$store = fn(array $v) => $registry->sanitizeForStorage([['type' => 'image', 'image' => '/a.jpg'] + $v])[0];
check('nothing is written for the default', $store([]), ['type' => 'image', 'image' => '/a.jpg']);
check('nothing is written for 100 percent, centered, full width on a phone', $store(['image_width' => 100, 'image_width_unit' => '%', 'image_align' => 'center', 'image_full_mobile' => true]), ['type' => 'image', 'image' => '/a.jpg']);
check('50 percent on the left, kept off the phone, is written', $store(['image_width' => '50', 'image_align' => 'left', 'image_full_mobile' => false]), ['type' => 'image', 'image' => '/a.jpg', 'image_width' => 50, 'image_align' => 'left', 'image_full_mobile' => false]);
check('pixels are written with their unit', $store(['image_width' => 320, 'image_width_unit' => 'px']), ['type' => 'image', 'image' => '/a.jpg', 'image_width' => 320, 'image_width_unit' => 'px']);
check('100 pixels is written', $store(['image_width' => 100, 'image_width_unit' => 'px'])['image_width'], 100);
check('a range that is wrong is corrected when written', [$store(['image_width' => 1])['image_width'], $store(['image_width' => 9000, 'image_width_unit' => 'px'])['image_width']], [10, 2000]);
check('an alignment that is not one of the three is dropped', isset($store(['image_align' => 'sideways'])['image_align']), false);
$round = $store(['image_width' => 320, 'image_width_unit' => 'px', 'image_align' => 'right']);
check('written twice it does not change', $registry->sanitizeForStorage([$round])[0], $round);

// ---- the markup
$plain = $draw([]);
check('the default draws no class and no variable of the width', [str_contains($plain, 'is-sized'), str_contains($plain, 'style='), str_contains($plain, '--image-width'), $figure($plain)], [false, false, false, '<figure class="image-block ratio-original is-rounded">']);
check('the default keeps the usual sizes and max width', [$sizes($plain), str_contains($plain, 'data-max="1800"')], ['(min-width: 1200px) 1200px, 94vw', true]);
check('100 percent draws the same as the default', $draw(['image_width' => 100, 'image_align' => 'left']), $plain);
$half = $draw(['image_width' => 50]);
check('50 percent: classes and one variable', $figure($half), '<figure class="image-block ratio-original is-rounded is-sized is-align-center is-full-mobile" style="--image-width: 50%">');
check('50 percent: the picture asks for half the size', [$sizes($half), str_contains($half, 'data-max="1800"')], ['(max-width: 640px) 94vw, (min-width: 1200px) 600px, 47vw', true]);
$px = $draw(['image_width' => 320, 'image_width_unit' => 'px', 'image_align' => 'left']);
check('320 pixels on the left', $figure($px), '<figure class="image-block ratio-original is-rounded is-sized is-align-left is-full-mobile" style="--image-width: 320px">');
check('320 pixels: the picture asks for 320 pixels, a phone for the page', $sizes($px), '(max-width: 640px) 94vw, 320px');
$right = $draw(['image_width' => 40, 'image_align' => 'right', 'image_full_mobile' => false]);
check('right, not full width on a phone', [$figure($right), $sizes($right)], ['<figure class="image-block ratio-original is-rounded is-sized is-align-right" style="--image-width: 40%">', '(min-width: 1200px) 480px, 38vw']);
check('an unknown alignment is the middle', str_contains($draw(['image_width' => 50, 'image_align' => 'x']), 'is-align-center'), true);
check('the caption stays inside the figure, so it follows the width and the position', (bool)preg_match('#<figure[^>]*is-sized.*<figcaption[^>]*>Cap</figcaption>\s*</figure>#s', $half), true);
check('narrow and full layouts scale their sizes too', [$sizes($draw(['variant' => 'narrow', 'image_width' => 50])), $sizes($draw(['variant' => 'full', 'image_width' => 50]))], ['(max-width: 640px) 94vw, (min-width: 800px) 368px, 47vw', '(max-width: 640px) 94vw, 50vw']);
$linked = $draw(['image_width' => 50, 'link' => '/go', 'ratio' => 'square', 'rounded' => false]);
check('a link, a shape and square corners work with the width', [str_contains($linked, 'class="image-block-link"'), str_contains($linked, 'ratio-square is-sized'), str_contains($linked, 'is-rounded')], [true, true, false]);
$css = (string)file_get_contents($root . '/themes/default/blocks/image/block.css');
check('the stylesheet limits the width to the column and widens it on a phone', [str_contains($css, 'width: var(--image-width)'), str_contains($css, 'max-width: 100%'), (bool)preg_match('/max-width: 640px\)[^}]*\.is-full-mobile/s', $css)], [true, true, true]);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
