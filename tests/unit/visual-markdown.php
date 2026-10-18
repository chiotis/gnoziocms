<?php
/*
 * The Markdown of the content editor as the visual editor draws it: one block at a time with the lines it came from, raw HTML as
 * a block that is only shown (never run) and kept as it is, definitions of links kept apart, and the endpoint's reading of it.
 *   php tests/unit/visual-markdown.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\VisualMarkdown;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$vm = new VisualMarkdown(function (): Environment {
    $e = new Environment(['renderer' => ['soft_break' => "<br />\n"], 'allow_unsafe_links' => false, 'attributes' => ['allow' => ['target', 'align']]]);
    $e->addExtension(new CommonMarkCoreExtension());
    $e->addExtension(new TableExtension());
    $e->addExtension(new StrikethroughExtension());
    $e->addExtension(new AttributesExtension());
    return $e;
});

$r = $vm->render("# Title\n\nHello *world*.\nSecond line\n\n- a\n- b\n  - c\n\n```php\necho 1;\n```\n\n> quote\n");
check('each block has its html and the lines it came from', array_map(fn($b) => $b['source'], $r['blocks']), ['# Title', "Hello *world*.\nSecond line", "- a\n- b\n  - c", "```php\necho 1;\n```", '> quote']);
check('drawn the way the site draws it (a new line is a line break)', [$r['blocks'][0]['html'], $r['blocks'][1]['html']], ['<h1>Title</h1>', "<p>Hello <em>world</em>.<br />\nSecond line</p>"]);
check('the lines account for the text', [$r['verbatim'], $r['tail']], [true, '']);

$r = $vm->render("Setext\n======\n\nSub\n---\n\ntext");
check('a heading underlined with signs is one block, with both lines', [$r['blocks'][0]['source'], $r['blocks'][1]['source'], $r['verbatim']], ["Setext\n======", "Sub\n---", true]);

$r = $vm->render("One\n\n\n\n\nTwo\r\nthree\r\n");
check('blank lines between blocks, and Windows line ends, are not part of a block', [array_column($r['blocks'], 'source'), $r['verbatim']], [['One', "Two\nthree"], true]);

$r = $vm->render("Text [ref][1] and [two][2]\n\n[1]: https://one.test\n[2]: https://two.test \"Two\"\n");
check('the definitions links refer to are kept apart, to be written back at the end', [$r['tail'], $r['verbatim'], count($r['blocks'])], ["[1]: https://one.test\n[2]: https://two.test \"Two\"", true, 1]);
check('and the link still points where the definition says', str_contains($r['blocks'][0]['html'], 'href="https://one.test"'), true);

$r = $vm->render("{align=center}\nCentered *text*\n\nPlain\n\n{align=right}\n## Right heading\n\n> {align=center}\n> In a quote\n\n{a=b}\n{align=justify}\nTwo lines of attributes");
check('the line of attributes before a block is part of the block, and written back with it', [array_column($r['blocks'], 'source'), $r['verbatim'], $r['tail']], [["{align=center}\nCentered *text*", 'Plain', "{align=right}\n## Right heading", "> {align=center}\n> In a quote", "{a=b}\n{align=justify}\nTwo lines of attributes"], true, '']);
check('alignment is drawn as the align attribute, on paragraphs and headings', [$r['blocks'][0]['html'], $r['blocks'][2]['html']], ['<p align="center">Centered <em>text</em></p>', '<h2 align="right">Right heading</h2>']);
check('and no other attribute is accepted', str_contains($r['blocks'][4]['html'], 'align="justify"') && !str_contains($r['blocks'][4]['html'], ' a="'), true);
$r = $vm->render("Text\n\\{align=center}\nmore");
check('a line of braces that is escaped is text, and stays in the paragraph', [count($r['blocks']), $r['blocks'][0]['html']], [1, "<p>Text<br />\n{align=center}<br />\nmore</p>"]);

$r = $vm->render("See [a] and [b].\n\n[a]:\n/one\n\n[b]:\n  <https://two.test/x y>\n  \"Two\"\n");
check('a definition with its address on the next line is a definition too, kept whole at the end', [$r['tail'], $r['verbatim'], count($r['blocks'])], ["[a]:\n/one\n[b]:\n  <https://two.test/x y>\n  \"Two\"", true, 1]);
check('and the links still point where it says', [str_contains($r['blocks'][0]['html'], 'href="/one"'), str_contains($r['blocks'][0]['html'], 'title="Two"')], [true, true]);

$r = $vm->render("One\n\n[a]:\n\nTwo");
check('a label with nothing after it is not a definition, only a paragraph', [$r['verbatim'], count($r['blocks'])], [true, 3]);

// ---- raw HTML is shown, never run
$r = $vm->render("<iframe src=\"https://x.test\" width=\"100%\"></iframe>\n\n<script>alert(1)</script>\n\nAfter");
$html = $r['blocks'][0]['html'];
check('a block of HTML is a box that cannot be edited, with its text as data', [str_contains($html, 'class="md-raw"'), str_contains($html, 'contenteditable="false"'), str_contains($html, 'data-raw="&lt;iframe src=&quot;https://x.test&quot; width=&quot;100%&quot;&gt;&lt;/iframe&gt;"')], [true, true, true]);
check('the box shows the code as text, so nothing runs', [str_contains($html, '<iframe'), str_contains($r['blocks'][1]['html'], '<script'), str_contains($r['blocks'][1]['html'], '&lt;script&gt;')], [false, false, true]);
check('and the lines of the block are kept', [$r['blocks'][0]['source'], $r['blocks'][1]['source']], ['<iframe src="https://x.test" width="100%"></iframe>', '<script>alert(1)</script>']);

$r = $vm->render('Under <u>line</u>, a <span class="x">span</span>, a<br>break and <a class="btn" href="#">Button</a>.');
$html = $r['blocks'][0]['html'];
check('underline is drawn as underline', str_contains($html, '<u>line</u>'), true);
check('a line break in HTML is a line break', str_contains($html, 'a<br>break'), true);
check('other inline HTML is a small box with its text as data', [substr_count($html, 'class="md-raw-inline"'), str_contains($html, 'data-raw="&lt;span class=&quot;x&quot;&gt;"'), str_contains($html, '<span class="x">')], [4, true, false]);

// ---- the rest of what the site draws
$r = $vm->render("| A | B |\n|:--|--:|\n| 1 | 2 |\n\n~~gone~~ and `code`\n\n[form slug=\"contact\"]");
check('a table is drawn, with its alignment', [str_contains($r['blocks'][0]['html'], '<table'), str_contains($r['blocks'][0]['html'], 'align="right"')], [true, true]);
check('strikethrough is drawn', str_contains($r['blocks'][1]['html'], '<del>gone</del>'), true);
check('a shortcode is text', $r['blocks'][2]['html'], '<p>[form slug=&quot;contact&quot;]</p>');
check('a link that would run script has lost its address', str_contains($vm->render('[x](javascript:alert(1))')['blocks'][0]['html'], 'javascript'), false);

$r = $vm->render('A [new tab](https://x.test){target=_blank} and [same](/y), {name} and {.cls}.');
check('a link marked {target=_blank} opens in a new tab, safely', [str_contains($r['blocks'][0]['html'], '<a target="_blank" href="https://x.test" rel="noopener noreferrer">new tab</a>'), str_contains($r['blocks'][0]['html'], '<a href="/y">same</a>')], [true, true]);
check('no other attribute is accepted, and plain braces stay as text', [str_contains($r['blocks'][0]['html'], '{name}'), str_contains($vm->render('[a](/x){.big #id onclick=x}')['blocks'][0]['html'], 'onclick')], [true, false]);

$r = $vm->render('');
check('nothing is nothing', [$r['blocks'], $r['tail'], $r['verbatim']], [[], '', true]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
