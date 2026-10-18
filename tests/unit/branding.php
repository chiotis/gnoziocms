<?php
/*
 * Theme > Branding: what the choices make of a page. The CSS (colours with the shades worked out from them, type, spacing,
 * corners, buttons, logo sizes), the tags of the head (icons, browser colour, font file), the attributes of the page, and the
 * two kinds of field the screen needs (a number that may be empty, a colour that must be hex).
 *   php tests/unit/branding.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{Branding, FieldSchema, Format, Theme};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }
function has(string $haystack, string $needle): bool { return str_contains($haystack, $needle); }

// ---- the fields: a number that may be empty, a colour that must be hex
$fields = FieldSchema::normalize([
    'size' => ['type' => 'number', 'blank' => true, 'min' => 10, 'max' => 50],
    'plain' => ['type' => 'number', 'min' => 3, 'max' => 9],
    'colour' => ['type' => 'color', 'hex' => true],
    'any' => ['type' => 'color'],
]);
check('a blank number starts empty, a plain one at its minimum', [$fields['size']['default'], $fields['plain']['default']], ['', 3]);
check('a blank number can be emptied; a plain one cannot', [FieldSchema::fromInput($fields, ['size' => ''], ['size' => 20])['size'], FieldSchema::fromInput($fields, ['plain' => ''], ['plain' => 5])['plain']], ['', 5]);
check('and is kept within its limits', [FieldSchema::fromInput($fields, ['size' => '99'], [])['size'], FieldSchema::fromInput($fields, ['size' => '2'], [])['size'], FieldSchema::fromInput($fields, ['size' => 'abc'], ['size' => 12])['size']], [50, 10, 12]);
$hex = static fn($v) => FieldSchema::fromInput($fields, ['colour' => $v], ['colour' => '#112233'])['colour'];
check('a hex colour is written the same way: #rrggbb in lower case', [$hex('#ABC'), $hex('c026d3'), $hex(' #1D4ED8 '), $hex('')], ['#aabbcc', '#c026d3', '#1d4ed8', '']);
check('a colour that is not hex keeps the current one', [$hex('red'), $hex('rgb(1,2,3)'), $hex('#12'), $hex('url(x)')], ['#112233', '#112233', '#112233', '#112233']);
check('a colour without the hex rule still takes names', FieldSchema::fromInput($fields, ['any' => 'rebeccapurple'], [])['any'], 'rebeccapurple');

// ---- the theme declares them
$theme = new Theme($repo, 'default');
$schema = $theme->settingsSchema();
check('the default theme has the design section and the new brand fields', [isset($schema['design']), isset($schema['brand']['fields']['logo_dark']), isset($schema['brand']['fields']['favicon']), $schema['design']['fields']['container']['blank']], [true, true, true, true]);
$defaults = $theme->defaultSettings();
check('and every one of them starts empty or on "theme", so a site that sets none looks as it did', [
    $defaults['design']['light_accent'], $defaults['design']['base_size'], $defaults['design']['line_height'], $defaults['design']['heading_font'], $defaults['design']['scale'], $defaults['design']['button_case'], $defaults['brand']['logo_height'], $defaults['brand']['show_name'],
], ['', '', '', 'pairing', 'auto', 'auto', '', false]);
check('so they add nothing to the page', [Branding::css($defaults), Branding::head($defaults)], ['', '']);
check('neither does a site with no settings at all', [Branding::css([]), Branding::head([])], ['', '']);

// ---- colours
$css = Branding::css(['design' => ['light_accent' => '#1d4ed8', 'dark_accent' => '#93c5fd']]);
check('an accent brings its hover and soft shades and a readable text colour, for light and dark', [
    has($css, '--accent-l:#1d4ed8;'), has($css, '--accent-l-hover:#1840b1;'), has($css, '--accent-l-soft:#e8edfb;'), has($css, '--accent-contrast-l:#ffffff;'),
    has($css, '--accent-d:#93c5fd;'), has($css, '--accent-d-hover:#b3d6fe;'), has($css, '--accent-contrast-d:#0b1220;'),
], [true, true, true, true, true, true, true]);
check('the rule beats the palette, font, shape and mode rules of the style sheet', str_starts_with($css, ':root[data-theme][data-mode]{'), true);
$css = Branding::css(['design' => ['light_accent' => '#fde047']]);
check('a light accent gets dark text', has($css, '--accent-contrast-l:#0b1220;'), true);
check('the accent band uses that same chosen background, including Slate', has($css, '--accent-band-bg:#fde047;'), true);
check('choosing only a dark accent keeps the default band background', has(Branding::css(['design' => ['dark_accent' => '#fde047']]), '--accent-band-bg:'), false);
$css = Branding::css(['design' => ['light_background' => '#fffdf8', 'light_surface' => '#f8f1e4', 'light_text' => '#261b14', 'light_muted' => '#5b4a3d', 'light_border' => '#eadfca', 'ink' => '#323130']]);
check('background, surface, text, muted and border set the neutrals; surface and border bring a second shade', [
    has($css, '--n-bg:#fffdf8;'), has($css, '--n-surface:#f8f1e4;'), has($css, '--n-surface-2:#'), has($css, '--n-text:#261b14;'), has($css, '--n-muted:#5b4a3d;'), has($css, '--n-border:#eadfca;'), has($css, '--n-border-strong:#'),
], [true, true, true, true, true, true, true]);
check('the dark panels get their colour, its channels and a lighter shade', [has($css, '--ink:#323130;'), has($css, '--ink-rgb:50 49 48;'), has($css, '--ink-2:#')], [true, true, true]);
$css = Branding::css(['design' => ['dark_background' => '#101010', 'dark_surface' => '#1a1a1a', 'dark_text' => '#f0f0f0', 'dark_muted' => '#aaaaaa', 'dark_border' => '#333333']]);
check('dark colours set the dark neutrals', [has($css, '--d-bg:#101010;'), has($css, '--d-surface:#1a1a1a;'), has($css, '--d-surface-2:#'), has($css, '--d-text:#f0f0f0;'), has($css, '--d-muted:#aaaaaa;'), has($css, '--d-border:#333333;'), has($css, '--d-border-strong:#')], [true, true, true, true, true, true, true]);
check('a colour that is not hex is ignored, never put in the style sheet', Branding::css(['design' => ['light_accent' => 'red; } body { display:none', 'light_text' => 'url(x)']]), '');

// ---- type
$css = Branding::css(['design' => ['heading_font' => 'geometric', 'body_font' => 'old_style']]);
check('a font choice sets the family of headings and of text', [has($css, '--font-heading:Avenir, Montserrat'), has($css, '--font-body:"Iowan Old Style"')], [true, true]);
check('an explicit heading font also reaches component titles with a body-font default', has($css, '--heading-font-custom:var(--font-heading);'), true);
check('a body font on its own does not change component heading defaults', has(Branding::css(['design' => ['body_font' => 'old_style']]), '--heading-font-custom:'), false);
check('"custom" without a font file is not used', Branding::css(['design' => ['heading_font' => 'custom']]), '');
$css = Branding::css(['design' => ['heading_font' => 'custom', 'font_file' => 'fonts/brand.woff2']], '/site');
check('with a font file it is loaded: the file of custom/assets, under the folder the site lives in', [
    has($css, '@font-face{font-family:"Brand Font"'), has($css, 'src:url("/site/_custom/fonts/brand.woff2") format("woff2")'), has($css, '--font-heading:"Brand Font", var(--font-system);'), has($css, '--font-body:'),
], [true, true, true, false]);
check('a full address is used as it is', has(Branding::css(['design' => ['body_font' => 'custom', 'font_file' => 'https://cdn.example.test/f.woff']]), 'url("https://cdn.example.test/f.woff") format("woff")'), true);
check('only font files are accepted: nothing else, nothing that climbs out of the folder', [
    Branding::css(['design' => ['body_font' => 'custom', 'font_file' => 'fonts/x.php']]), Branding::css(['design' => ['body_font' => 'custom', 'font_file' => '../secret.woff2']]), Branding::css(['design' => ['body_font' => 'custom', 'font_file' => 'a.woff2") } body {x:y']]),
], ['', '', '']);
$css = Branding::css(['design' => ['base_size' => 18]]);
check('a text size scales the type: 18 is 1.125 times the theme\'s 16', has($css, '--type-scale:1.125;'), true);
check('16 is the theme\'s own size: nothing', Branding::css(['design' => ['base_size' => 16]]), '');
$css = Branding::css(['design' => ['scale' => '1.25']]);
check('a heading scale works out five steps that grow with the window', preg_match_all('/--step-[1-5]:clamp\(/', $css), 5);
check('the biggest is the text size times the ratio five times: 17 x 1.25^5 = 51.9 pixels', has($css, '3.2425rem)'), true);
check('the custom scale reaches minimal and phone hero titles too', has($css, '--hero-title-size:var(--step-5);'), true);
check('"theme" is no scale', Branding::css(['design' => ['scale' => 'auto']]), '');
$css = Branding::css(['design' => ['heading_weight' => '800', 'heading_tracking' => 'wide', 'heading_leading' => 'relaxed', 'heading_case' => 'uppercase', 'line_height' => 1.8]]);
check('heading weight, letter spacing, line height and case, and the line height of the text', [
    has($css, '--heading-weight:800;'), has($css, '--heading-tracking:0.025em;'), has($css, 'h1,h2,h3,h4,h5,h6{line-height:1.35;text-transform:uppercase}'), has($css, 'body.theme-body{line-height:1.8}'),
], [true, true, true, true]);
check('component titles and prose get the selected type values without losing their own defaults when unset', [
    has($css, '--heading-weight-custom:800;'), has($css, '--heading-tracking-custom:0.025em;'), has($css, '--heading-leading:1.35;'), has($css, '--body-leading:1.8;'),
], [true, true, true, true]);
check('capitals without a letter spacing of their own get a little', has(Branding::css(['design' => ['heading_case' => 'uppercase']]), '--heading-tracking:0.02em;'), true);
check('that automatic spacing also reaches component titles', has(Branding::css(['design' => ['heading_case' => 'uppercase']]), '--heading-tracking-custom:0.02em;'), true);
check('restoring the theme type removes every optional component override', Branding::css(['design' => ['heading_font' => 'pairing', 'heading_weight' => 'auto', 'heading_tracking' => 'auto', 'heading_leading' => 'auto', 'heading_case' => 'auto', 'scale' => 'auto', 'line_height' => '']]), '');
$css = Branding::css(['design' => ['heading_color' => 'accent']]);
check('headings in the palette colour follow the accent of the mode', [has($css, '--heading-color:var(--accent);'), has($css, ':root.dark')], [true, false]);
$css = Branding::css(['design' => ['heading_color' => 'custom', 'heading_color_light' => '#b00020', 'heading_color_dark' => '#ff8a80']]);
check('own heading colours: one for light, one for the dark mode (chosen or system)', [has($css, '--heading-color:#b00020;'), has($css, ':root.dark[data-theme][data-mode]{--heading-color:#ff8a80}'), has($css, '@media(prefers-color-scheme:dark){:root[data-mode="system"]:not(.light)[data-theme]{--heading-color:#ff8a80}}')], [true, true, true]);
$css = Branding::css(['design' => ['heading_color' => 'custom', 'heading_color_light' => '#b00020']]);
check('a dark mode left empty keeps the text colour', has($css, ':root.dark[data-theme][data-mode]{--heading-color:initial}'), true);
$css = Branding::css(['design' => ['heading_color' => 'custom', 'heading_color_dark' => '#ff8a80']]);
check('and a light mode left empty keeps it too', [has($css, ':root[data-theme][data-mode]{'), has($css, '{--heading-color:#ff8a80}')], [false, true]);
check('own colours without any colour, and the default, add nothing', [Branding::css(['design' => ['heading_color' => 'custom']]), Branding::css(['design' => ['heading_color' => 'text', 'heading_color_light' => '#b00020']])], ['', '']);
check('a value that is not a colour is not written', has(Branding::css(['design' => ['heading_color' => 'custom', 'heading_color_light' => 'red;}body{display:none']]), 'display'), false);
check('a weight or word that is not on the list is ignored', Branding::css(['design' => ['heading_weight' => '999', 'heading_tracking' => 'x', 'heading_leading' => 'y', 'button_weight' => '100']]), '');

// ---- layout, spacing, corners, buttons, logo
$css = Branding::css(['design' => ['container' => 1400, 'reading_width' => 800, 'gutter' => 64, 'section_space' => 56, 'header_height' => 80, 'spacing' => 'airy']]);
check('widths and the header\'s height in pixels become rem; a margin and the space between sections follow the window', [
    has($css, '--container:87.5rem;'), has($css, '--container-narrow:50rem;'), has($css, '--gutter:clamp(1.25rem, calc('), has($css, '--section-scale:0.5;'), has($css, '--header-min:5rem;'), has($css, '--space-scale:1.2;'),
], [true, true, true, true, true, true]);
check('a side margin under 20 pixels does not grow', has(Branding::css(['design' => ['gutter' => 16]]), '--gutter:1rem;'), true);
$css = Branding::css(['design' => ['radius' => 16, 'button_radius' => 'square', 'shadows' => 'none']]);
check('a corner radius sets the four sizes from one number; buttons and shadows have their own choice', [
    has($css, '--radius-m:1rem;'), has($css, '--radius-s:0.57rem;'), has($css, '--radius-xl:2rem;'), has($css, '--radius-button:0px;'), has($css, '--shadow-s:none;--shadow-m:none;'),
], [true, true, true, true, true]);
check('pill and rounded buttons', [has(Branding::css(['design' => ['button_radius' => 'pill']]), '--radius-button:999px;'), has(Branding::css(['design' => ['button_radius' => 'rounded']]), '--radius-button:var(--radius-s);')], [true, true]);
check('zero is a radius: square corners', has(Branding::css(['design' => ['radius' => 0]]), '--radius-m:0px;'), true);
$css = Branding::css(['design' => ['button_height' => 52, 'button_pad' => 28, 'button_size' => 16, 'button_weight' => '700', 'button_case' => 'uppercase']]);
check('buttons: height, padding, text size and weight, capitals with a little letter spacing', [
    has($css, '--btn-height:3.25rem;'), has($css, '--btn-pad-x:1.75rem;'), has($css, '--btn-size:1rem;'), has($css, '--btn-weight:700;'), has($css, '--btn-case:uppercase;'), has($css, '--btn-tracking:0.06em;'),
], [true, true, true, true, true, true]);
$css = Branding::css(['brand' => ['logo_height' => 48, 'logo_height_mobile' => 32, 'footer_logo_height' => 40, 'name_size' => 24]]);
check('the logo sizes and the size of the name', [has($css, '--logo-height:3rem;'), has($css, '--logo-height-mobile:2rem;'), has($css, '--footer-logo-height:2.5rem;'), has($css, '--brand-name-size:1.5rem;')], [true, true, true, true]);
check('a number given as text counts; an empty one does not', [has(Branding::css(['design' => ['radius' => '12']]), '--radius-m:0.75rem;'), Branding::css(['design' => ['radius' => '', 'container' => 'wide']])], [true, '']);

// ---- the head
$head = Branding::head(['brand' => ['favicon' => '/uploads/media/f.svg', 'touch_icon' => '/uploads/media/a.png', 'theme_color' => '#1E293B']], '/site');
check('icons, the home screen icon and the colour of the browser', [
    has($head, '<link rel="icon" href="/site/uploads/media/f.svg" type="image/svg+xml">'), has($head, '<link rel="apple-touch-icon" href="/site/uploads/media/a.png">'), has($head, '<meta name="theme-color" content="#1e293b">'),
], [true, true, true]);
check('a PNG and an ICO favicon say their type', [
    has(Branding::head(['brand' => ['favicon' => '/a.png']]), 'type="image/png"'), has(Branding::head(['brand' => ['favicon' => '/a.ico']]), 'type="image/x-icon"'),
], [true, true]);
check('an address with a quote or a bracket is not written', Branding::head(['brand' => ['favicon' => '/a.png" onerror="x', 'touch_icon' => 'javascript:alert(1)']]), '');
check('a font file in use is fetched early', has(Branding::head(['design' => ['body_font' => 'custom', 'font_file' => 'fonts/b.woff2']]), '<link rel="preload" href="/_custom/fonts/b.woff2" as="font" type="font/woff2" crossorigin>'), true);
check('and not when no font uses it', Branding::head(['design' => ['font_file' => 'fonts/b.woff2']]), '');

// ---- the attributes
check('the page gets the palette, mode, font, shape and decoration', Branding::attributes(['appearance' => ['palette' => 'garnet', 'mode' => 'dark', 'font' => 'serif', 'shape' => 'sharp', 'glow' => 'solid']]), [
    'data-theme' => 'garnet', 'data-mode' => 'dark', 'data-font' => 'serif', 'data-shape' => 'sharp', 'data-glow' => 'solid',
]);
check('and the usual ones without settings', Branding::attributes([]), ['data-theme' => 'slate', 'data-mode' => 'system', 'data-font' => 'sans', 'data-shape' => 'soft', 'data-glow' => 'soft']);

// ---- nothing a person typed reaches the style sheet as anything but a number, a hex colour or a word of the lists
$evil = ['design' => [
    'light_accent' => '#fff;}</style><script>x</script>', 'heading_font' => 'x;}body{background:red', 'scale' => '1.25;}', 'radius' => '5;}body{x:y', 'font_file' => 'a.woff2</style>',
    'button_case' => 'uppercase;}', 'spacing' => 'airy;}', 'shadows' => 'none;}',
], 'brand' => ['logo_height' => '9;}', 'favicon' => '/x"><script>']];
$out = Branding::css($evil) . Branding::head($evil);
check('so nothing of it survives', [has($out, '<script'), has($out, '</style'), has($out, 'body{')], [false, false, false]);

// ---- a text with a version for each language
$tr = FieldSchema::normalize(['t' => ['type' => 'text', 'translatable' => true], 'area' => ['type' => 'textarea', 'translatable' => true], 'plain' => ['type' => 'text'], 'n' => ['type' => 'number', 'translatable' => true]]);
check('only text and textarea fields can be translatable', [$tr['t']['translatable'] ?? false, $tr['area']['translatable'] ?? false, isset($tr['plain']['translatable']), isset($tr['n']['translatable'])], [true, true, false, false]);
$set = static fn($v, $current = []) => FieldSchema::fromInput($tr, ['t' => $v], ['t' => $current])['t'];
check('a plain text stays a plain text', $set('Hello'), 'Hello');
check('a map keeps the default and the languages, and drops empty and odd ones', $set(['default' => ' Γεια ', 'en' => 'Hello', 'de' => '', 'x y' => 'no', '<b>' => 'no']), ['default' => 'Γεια', 'en' => 'Hello']);
check('a map with only the default text is the plain text', $set(['default' => 'Γεια', 'en' => '']), 'Γεια');
check('a map with nothing is empty', $set(['default' => '', 'en' => '']), '');
check('only a translation, no default, is kept as it is', $set(['en' => 'Hello']), ['en' => 'Hello']);
check('a plain text sent over a map changes only the default text', $set('New', ['default' => 'Old', 'en' => 'Hello']), ['default' => 'New', 'en' => 'Hello']);
check('a map sent to a text that is not translatable is not a text', FieldSchema::fromInput($tr, ['plain' => ['a' => 'b']], ['plain' => 'keep'])['plain'], 'keep');
check('each text is cleaned like any other (no line breaks in a one-line text)', $set(['default' => "a\nb", 'en' => "c\r\nd"]), ['default' => 'a b', 'en' => 'c d']);
check('a text in a repeater row can be translatable too', FieldSchema::fromInput(FieldSchema::normalize(['rows' => ['type' => 'repeater', 'fields' => ['label' => ['type' => 'text', 'translatable' => true]]]]), ['rows' => [['label' => ['default' => 'A', 'en' => 'B']]]], [])['rows'], [['label' => ['default' => 'A', 'en' => 'B']]]);
check('the text for a language: its own, else the default, else the first', [Format::localized(['default' => 'Γεια', 'en' => 'Hello'], 'en'), Format::localized(['default' => 'Γεια', 'en' => 'Hello'], 'fr'), Format::localized(['en' => 'Hello'], 'el'), Format::localized(['default' => 'Γεια', 'en' => ' '], 'en'), Format::localized('Plain', 'en'), Format::localized(null, 'en'), Format::localized([], 'en')], ['Hello', 'Γεια', 'Hello', 'Γεια', 'Plain', '', '']);

// ---- release notes: a line of Markdown as safe HTML
check('release notes: code, bold and italic', Format::inlineMarkdown('**New tab** in `Theme` is *shown* now'), '<strong>New tab</strong> in <code>Theme</code> is <em>shown</em> now');
check('and a link, with nothing inside code touched', Format::inlineMarkdown('See [the docs](https://x.example/a) and `**not bold** [a](https://b.example)`'), 'See <a href="https://x.example/a" target="_blank" rel="noopener">the docs</a> and <code>**not bold** [a](https://b.example)</code>');
check('HTML and unsafe links stay as text', [Format::inlineMarkdown('<b>x</b> & [y](javascript:alert(1))'), Format::inlineMarkdown('a_b_c and 2 * 3 * 4')], ['&lt;b&gt;x&lt;/b&gt; &amp; [y](javascript:alert(1))', 'a_b_c and 2 * 3 * 4']);
check('a lone star or a name with underscores is not formatting', Format::inlineMarkdown('footer_blocks and *'), 'footer_blocks and *');

// ---- the footer's credits line: links written as [text](address), everything else escaped
check('a credits line: a web link opens in a new tab', Format::inlineLinks('Designed by [Unicorg](https://unicorg.example/a?b=1&c=2)'), 'Designed by <a href="https://unicorg.example/a?b=1&amp;c=2" target="_blank" rel="noopener">Unicorg</a>');
check('a site address and a mail address are links too', [Format::inlineLinks('[Home](/en/about)'), Format::inlineLinks('[Mail](mailto:a@b.gr)')], ['<a href="/en/about">Home</a>', '<a href="mailto:a@b.gr">Mail</a>']);
check('other schemes and tags stay as plain text', [Format::inlineLinks('[x](javascript:alert(1))'), Format::inlineLinks('[x](//evil.example)'), Format::inlineLinks('<script>a</script> & "b"')], ['[x](javascript:alert(1))', '[x](//evil.example)', '&lt;script&gt;a&lt;/script&gt; &amp; &quot;b&quot;']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
