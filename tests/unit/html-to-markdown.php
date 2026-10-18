<?php
/*
 * HTML from another site turned into Markdown: what is kept, what is dropped, and what could not be carried over.
 *   php tests/unit/html-to-markdown.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\HtmlToMarkdown;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$md = static fn(string $html, ?Closure $rewrite = null): string => (new HtmlToMarkdown($rewrite))->convert($html);

// ---- the everyday text
check('a paragraph', $md('<p>Hello <em>there</em>, <strong>world</strong>.</p>'), "Hello *there*, **world**.\n");
check('two paragraphs', $md('<p>One</p><p>Two</p>'), "One\n\nTwo\n");
check('text without a tag is a paragraph', $md('Just text'), "Just text\n");
check('nothing gives nothing', [$md(''), $md('   '), $md('<p>&nbsp;</p><p> </p>')], ['', '', '']);
check('headings keep their level', $md('<h2>Title</h2><h4>Small</h4>'), "## Title\n\n#### Small\n");
check('bold inside a heading is not repeated', $md('<h3><strong>Bold</strong></h3>'), "### Bold\n");
check('a rule', $md('<p>a</p><hr><p>b</p>'), "a\n\n---\n\nb\n");
check('Greek text is left alone', $md('<p>Ο Ευκλείδης στο <em>Gettysburg</em></p>'), "Ο Ευκλείδης στο *Gettysburg*\n");
check('entities become characters', $md('<p>&ldquo;Quoted&rdquo; &amp; more&hellip;</p>'), "“Quoted” & more…\n");

// ---- emphasis around spaces, and empty emphasis
check('spaces stay outside the marks', $md('<p>a<strong> bold </strong>b</p>'), "a **bold** b\n");
check('empty emphasis disappears', $md('<p>a<strong> </strong>b<em></em>c</p>'), "a bc\n");
check('the same emphasis twice is once', $md('<p><strong><strong>x</strong></strong></p>'), "**x**\n");
check('a stray <strong> that is closed in the next paragraph still reads', $md('<p><strong></p><p>Alfred Hoos</strong></p>'), "Alfred Hoos\n");

// ---- line breaks
check('a line break stays a line break', $md('<p>Line one<br />Line two</p>'), "Line one\nLine two\n");
check('breaks at the ends of a paragraph mean nothing', $md('<p><br />Text<br /></p>'), "Text\n");
check('spaces after a break are dropped', $md('<p>One<br />   Two</p>'), "One\nTwo\n");

// ---- characters that would turn into Markdown
check('stars and backticks are text', $md('<p>2 * 3 and `x`</p>'), "2 \\* 3 and \\`x\\`\n");
check('a lone underscore between words is text, snake_case is untouched', $md('<p>snake_case and _lead</p>'), "snake_case and \\_lead\n");
check('square brackets are plain text', $md('<p>[push glasses up]</p>'), "[push glasses up]\n");
check('but not when they would make a link', $md('<p>[a](b) and [a][b]</p>'), "[a\\](b) and [a\\][b]\n");
check('a paragraph that begins like a heading', $md('<p># not a heading</p>'), "\\# not a heading\n");
check('a paragraph that begins like a number list', $md('<p>1. Friday</p>'), "1\\. Friday\n");
check('a paragraph that begins like a quote', $md('<p>&gt; quoted?</p>'), "\\> quoted?\n");
check('an angle bracket is text', $md('<p>a &lt; b</p>'), "a &lt; b\n");
check('an entity written out as text stays text', $md('<p>&amp;copy;</p>'), "\\&copy;\n");

// ---- links and images
check('a link', $md('<p><a href="https://example.com/x">site</a></p>'), "[site](https://example.com/x)\n");
check('a link with a title', $md('<p><a href="/a" title="More">go</a></p>'), "[go](/a \"More\")\n");
check('a link without an address is its text', $md('<p><a name="top">Top</a> <a href="#x">jump</a></p>'), "Top jump\n");
check('a script address is dropped', $md('<p><a href="javascript:alert(1)">x</a></p>'), "x\n");
check('an empty link is nothing', $md('<p>a<a href="/x"></a>b</p>'), "ab\n");
check('an address with spaces is wrapped', $md('<p><a href="/a b/c">x</a></p>'), "[x](</a b/c>)\n");
check('an image', $md('<p><img src="/a.jpg" alt="A cat" width="10" style="border:0"></p>'), "![A cat](/a.jpg)\n");
check('a file name as a description is dropped', $md('<p><img src="/a.jpg" alt="hoos_l.gif"></p>'), "![](/a.jpg)\n");
check('an inline picture data address is dropped', $md('<p><img src="data:image/gif;base64,AAAA"></p>'), '');
check('the closure rewrites links and images and is told which', $md('<p><a href="http://old/p">p</a> <img src="http://old/i.png"></p>', fn(string $a, string $k): string => "/$k" . parse_url($a, PHP_URL_PATH)), "[p](/link/p) ![](/image/i.png)\n");

// ---- lists
check('a bullet list', $md('<ul><li>One</li><li>Two <em>x</em></li></ul>'), "- One\n- Two *x*\n");
check('a numbered list starts where it says', $md('<ol start="3"><li>c</li><li>d</li></ol>'), "3. c\n4. d\n");
check('a nested list is indented under its item', $md('<ul><li>A<ul><li>A1</li><li>A2</li></ul></li><li>B</li></ul>'), "- A\n  - A1\n  - A2\n- B\n");
check('a list item with paragraphs', $md('<ul><li><p>First</p><p>More</p></li></ul>'), "- First\n  More\n");

// ---- quotes, code, definition lists
check('a quote', $md('<blockquote><p>Quote</p><p>Second</p></blockquote>'), "> Quote\n>\n> Second\n");
check('code', $md('<pre><code>a &lt; b' . "\n" . 'c</code></pre>'), "```\na < b\nc\n```\n");
check('inline code', $md('<p>Use <code>foo()</code>.</p>'), "Use `foo()`.\n");
check('a definition list', $md('<dl><dt>Term</dt><dd>Meaning</dd></dl>'), "**Term**\n\nMeaning\n");

// ---- tables
check('a table of plain cells', $md('<table><tr><th>Year</th><th>Event</th></tr><tr><td>1916</td><td>Born</td></tr></table>'), "| Year | Event |\n| --- | --- |\n| 1916 | Born |\n");
check('a bar inside a cell is escaped', $md('<table><tr><td>a|b</td><td>c</td></tr><tr><td>d</td><td>e</td></tr></table>'), "| a\\|b | c |\n| --- | --- |\n| d | e |\n");
check('a table that only places a picture and a caption is taken apart', $md('<table><tr><td><img src="/p.jpg"></td></tr><tr><td>Caption</td></tr></table>'), "![](/p.jpg)\n\nCaption\n");
check('cells holding paragraphs are taken apart', $md('<table align="left"><tr><td><p><img src="/p.jpg"></p><p>BACKWARD GLANCE</p></td></tr></table>'), "![](/p.jpg)\n\nBACKWARD GLANCE\n");
check('a table with a merged cell is taken apart', $md('<table><tr><td colspan="2">Wide</td></tr><tr><td>a</td><td>b</td></tr></table>'), "Wide\n\na\n\nb\n");
$tables = new HtmlToMarkdown();
$tables->convert('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>');
check('a table without a header is reported', $tables->notes(), ['A table had no header row; its first row is used as the header.']);

// ---- embeds
check('a video embed is kept as a small piece of HTML', $md('<p><iframe src="https://www.youtube.com/embed/abc?feature=oembed" width="500" height="281" title="A talk" frameborder="0" allow="autoplay"></iframe></p>'), "<iframe src=\"https://www.youtube.com/embed/abc?feature=oembed\" title=\"A talk\" width=\"500\" height=\"281\" loading=\"lazy\" allowfullscreen></iframe>\n");
check('a protocol-relative embed address gets https', str_contains($md('<iframe src="//player.vimeo.com/video/1"></iframe>'), 'src="https://player.vimeo.com/video/1"'), true);
$embeds = new HtmlToMarkdown();
check('a frame from an unknown site is left out and reported', [$embeds->convert('<p>a</p><iframe src="https://ads.example.net/x"></iframe>'), $embeds->notes()], ["a\n", ['An embedded frame from ads.example.net was left out.']]);
check('a frame over plain http is left out', $md('<iframe src="http://www.youtube.com/embed/x"></iframe>'), '');

// ---- what is left over by other editors
check('Word and style leftovers are dropped', $md('<p class="MsoNormal" style="text-align:justify"><span style="font-size:12pt"><o:p></o:p>Plain <font face="Arial">words</font></span></p>'), "Plain words\n");
check('scripts, styles and forms are dropped', $md('<p>a</p><script>alert(1)</script><style>p{}</style><form><input name=x><button>Go</button></form><p>b</p>'), "a\n\nb\n");
check('unknown tags keep their text', $md('<p>Merged with <org idsrc="NYSE" value="BGP">Borders Group</org> in 2008</p>'), "Merged with Borders Group in 2008\n");
check('superscript stays', $md('<p>10<sup>3</sup></p>'), "10<sup>3</sup>\n");
check('a div around text is no wrapper', $md('<div><div>One</div><div>Two</div></div>'), "One\n\nTwo\n");
check('a paragraph inside a link is the link text', $md('<a href="/x"><p>Big</p></a>'), "[Big](/x)\n");
check('broken markup (an unclosed span) still reads', $md('<p style="text-align:center"><span class="content"></p><p>Text</span></p>'), "Text\n");

// ---- notes start over
$twice = new HtmlToMarkdown();
$twice->convert('<iframe src="https://ads.example.net/x"></iframe>');
$twice->convert('<p>fine</p>');
check('the notes belong to the last conversion', $twice->notes(), []);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
