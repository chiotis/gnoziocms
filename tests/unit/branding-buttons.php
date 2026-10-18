<?php
/*
 * Theme > Branding: links and the three button styles (BrandingButtons). The CSS each style and the links make, that nothing is
 * added until something is chosen, that a custom colour is used only while it passes AA, that the focus ring reads in every palette
 * and both modes, that the buttons of blocks and forms keep their look, and that the editor's values go round (input, stored, CSS).
 *   php tests/unit/branding-buttons.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{Branding, BrandingButtons, FieldSchema, Theme};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }
function has(string $haystack, string $needle): bool { return str_contains($haystack, $needle); }

$theme = new Theme($repo, 'default');
$schema = $theme->settingsSchema()['design']['fields'];
$defaults = $theme->defaultSettings();
$css = static fn(array $design, string $palette = 'slate'): string => BrandingButtons::css($design, $palette);

// ---- defaults: every key is declared, starts empty or on "theme", and adds nothing
$keys = BrandingButtons::keys();
check('every setting of links and buttons is declared by the default theme', array_values(array_filter($keys, static fn($k) => !isset($schema[$k]))), []);
check('each starts empty or on "theme"', array_values(array_filter($keys, static fn($k) => !in_array($defaults['design'][$k], ['', 'auto'], true))), []);
check('so the page gets no CSS from them, and Branding adds nothing', [$css($defaults['design']), Branding::css($defaults), Branding::css([])], ['', '', '']);
check('a value that is not a role or a hex adds nothing either', $css(['link_color' => 'red; } body{display:none', 'btn_primary_bg' => 'url(x)', 'btn_text_radius' => 'huge']), '');

// ---- links
$links = $css(['link_color' => 'text', 'link_hover' => 'accent']);
check('a link colour is a rule of low weight, so the theme\'s own link colours (nav, footer, cards) still win', has($links, ':where(:root[data-theme][data-mode]) a:where(:not(.tone-contrast a, .tone-accent a)){color:var(--color-text)}'), true);
check('and the hover colour is the one chosen', has($links, ':hover{color:var(--accent)}'), true);
check('a hover that is not chosen is worked out: the accent\'s own, else toward the text', [
    has($css(['link_color' => 'accent']), ':hover{color:var(--accent-hover)}'),
    has($css(['link_color' => 'muted']), ':hover{color:color-mix(in srgb,var(--color-muted) 75%,var(--color-text))}'),
], [true, true]);
$dark = $css(['link_color' => 'accent', 'link_color_dark' => '#ffcc00']);
check('dark has its own colour, in the dark class, the system preference and the specimen\'s dark panel', [
    has($dark, ':where(:root.dark[data-theme][data-mode], .mode-dark) a:where(:not(.tone-contrast a, .tone-accent a, .mode-light a)){color:#ffcc00}'),
    has($dark, '@media(prefers-color-scheme:dark){:where(:root[data-mode="system"]:not(.light)[data-theme])'),
], [true, true]);
$underline = static fn(string $mode): string => $css(['link_underline' => $mode]);
check('underline: always, on hover (and focus), never (still shown on focus); the theme\'s own adds nothing', [
    has($underline('always'), '{text-decoration-line:underline}'),
    has($underline('hover'), '{text-decoration-line:none}') && has($underline('hover'), ':is(:hover,:focus-visible){text-decoration-line:underline}'),
    has($underline('never'), '{text-decoration-line:none}') && has($underline('never'), ':focus-visible{text-decoration-line:underline}'),
    $underline('auto'),
], [true, true, true, '']);
check('the underline is only for the links of text, never for buttons or text links', has($underline('hover'), ':is(.prose,.prose-block) a:where(:not(.btn,.text-link))'), true);
$shape = $css(['link_thickness' => 'thick', 'link_offset' => 'far']);
check('thickness and distance have presets', [has($shape, 'text-decoration-thickness:0.2em'), has($shape, 'text-underline-offset:0.32em')], [true, true]);

// ---- link colours must read: a custom one is used only while it passes AA on the page
$readable = $css(['link_color' => '#0b57d0']);
check('a custom link colour that reads in light and dark is used', has($readable, 'color:#0b57d0'), true);
$bad = $css(['link_color' => '#7a7a7a']);
check('one that is too pale on the light page is left out', $bad, '');
$onlyLight = $css(['link_color' => '#1a1a1a']);
check('one that reads on light but not on dark page keeps light, and dark mode keeps the theme\'s colour', [has($onlyLight, 'color:#1a1a1a'), has($onlyLight, '.mode-dark) a:where(:not(.tone-contrast a, .tone-accent a, .mode-light a)){color:var(--accent)}')], [true, true]);
check('a dark colour of its own is checked on the dark page', has($css(['link_color_dark' => '#222222']), '#222222'), false);
check('AA is 4.5:1: the ratio of black and white is 21, and of #767676 on white 4.54', [round(BrandingButtons::ratio('#000000', '#ffffff'), 1), round(BrandingButtons::ratio('#767676', '#ffffff'), 2)], [21.0, 4.54]);
check('the page the colours are checked against follows the colours the site chose', has($css(['link_color' => '#444444', 'light_background' => '#555555']), 'color:#444444'), false);

// ---- button styles
$primary = $css(['btn_primary_bg' => 'ink', 'btn_primary_text' => 'white', 'btn_primary_border' => 'accent', 'btn_primary_border_width' => '2', 'btn_primary_radius' => 'square']);
check('Primary is the Primary button and the buttons of forms and of the search', has($primary, ':root[data-theme][data-mode] .btn-primary,:root[data-theme][data-mode] .form-grid button,:root[data-theme][data-mode] .search-form button{'), true);
check('with its background, text, border, width and corners', has($primary, '--btn-bg:var(--ink);--btn-text:#ffffff;--btn-border:var(--accent);border-width:2px;border-radius:0px;'), true);
check('its hover is worked out from the background (toward the text colour) and keeps the border', has($primary, '{--btn-bg:color-mix(in srgb,var(--ink) 86%,var(--color-text));--btn-border:var(--accent);}'), true);
check('and has a twin for the specimen to show it', has($primary, '.btn-primary[data-state="hover"]'), true);
check('the accent keeps the theme\'s own hover', has($css(['btn_primary_bg' => 'accent']), '--btn-bg:var(--accent-hover)'), true);
$hover = $css(['btn_primary_hover_bg' => 'secondary', 'btn_primary_hover_text' => 'surface', 'btn_primary_hover_border' => 'ink']);
check('a hover that is chosen is used', has($hover, '[data-state="hover"]{--btn-bg:var(--color-secondary, var(--accent));--btn-text:var(--color-surface);--btn-border:var(--ink);}'), true);
$secondary = $css(['btn_secondary_text' => 'accent', 'btn_secondary_border' => 'accent', 'btn_secondary_border_width' => '3', 'btn_secondary_radius' => 'pill']);
check('Secondary is the buttons that are not Primary or Text, so the outline buttons of the header and the pricing plans too', has($secondary, ':root[data-theme][data-mode] .btn:not(.btn-primary):not(.btn-text){--btn-text:var(--accent);--btn-border:var(--accent);border-width:3px;border-radius:999px;}'), true);
$text = $css(['btn_text_text' => 'secondary', 'btn_text_hover_text' => 'ink']);
check('Text is .btn-text', has($text, ':root[data-theme][data-mode] .btn-text{--btn-text:var(--color-secondary, var(--accent));}') && has($text, '.btn-text:hover,:root[data-theme][data-mode] .btn-text[data-state="hover"]{--btn-text:var(--ink);}'), true);
check('a background without a text colour gets the text that reads on it', has($css(['btn_secondary_bg' => '#112233']), '--btn-bg:#112233;--btn-text:#ffffff;'), true);
check('a background that reads better with another text colour in dark mode says so there', has($css(['btn_secondary_bg' => 'surface']), ':root.dark[data-theme][data-mode] .btn:not(.btn-primary):not(.btn-text)'), true);
check('Primary with no background but transparent takes the text colour, not the one made for the accent', has($css(['btn_primary_bg' => 'none']), '--btn-text:var(--color-text);'), true);

// ---- AA for custom colours of a button: in light and in dark, normal and hover
check('custom text that reads on the background in both modes is used', has($css(['btn_primary_text' => '#ffffff', 'btn_primary_bg' => '#1d4ed8']), '--btn-bg:#1d4ed8;--btn-text:#ffffff;'), true);
$pale = $css(['btn_primary_bg' => '#1d4ed8', 'btn_primary_text' => '#8899ff']);
check('custom text that does not reach 4.5:1 is dropped; the background stays and gets the text that reads', has($pale, '#8899ff'), false);
check('custom text over the theme\'s background is checked too (in dark the accent is pale, so dark text on it only)', [
    has($css(['btn_primary_text' => '#ffffff']), '#ffffff'),
    has($css(['btn_primary_text' => '#0b1220']), '#0b1220'),
], [false, false]);
check('a custom hover pair is checked against the same rule', has($css(['btn_primary_hover_bg' => '#ffff00', 'btn_primary_hover_text' => '#ffffff']), '#ffff00'), false);
check('palette roles are the person\'s choice and are not refused', has($css(['btn_primary_bg' => 'white', 'btn_primary_text' => 'white']), '--btn-bg:#ffffff;--btn-text:#ffffff;'), true);
check('the accent a site chose for itself is what a role means to the check', has($css(['btn_primary_text' => '#ffffff', 'light_accent' => '#ffffff', 'dark_accent' => '#000000']), '#ffffff;'), false);

// ---- focus and disabled are not settings
check('there is no setting for focus or disabled', array_values(array_filter(array_keys($schema), static fn($k) => str_contains($k, 'focus') || str_contains($k, 'disabled'))), []);
$site = (string)file_get_contents($repo . '/themes/default/assets/css/site.css');
check('the theme draws them: the focus ring and a dimmed, readable disabled button', [
    has($site, ":focus-visible {\n  outline: 2px solid var(--color-focus);"),
    (bool)preg_match('/\.btn:disabled,[^{]*\{[^}]*opacity: 0\.6;[^}]*cursor: not-allowed/s', $site),
], [true, true]);
// The ring is the accent: it must show against the page in every palette and both modes (3:1 for a part of the interface, WCAG 1.4.11).
preg_match_all('/:root\[data-theme="([a-z]+)"\]\s*\{(.*?)\}/s', $site, $palettes, PREG_SET_ORDER);
$tokens = static function (string $block): array { preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-f]{6})/i', $block, $m, PREG_SET_ORDER); $out = []; foreach ($m as $x) $out[$x[1]] = strtolower($x[2]); return $out; };
$rootBlock = $tokens(substr($site, strpos($site, ':root {'), 4200));
$rings = ['slate light' => BrandingButtons::ratio($rootBlock['accent-l'], $rootBlock['n-bg']) >= 3.0, 'slate dark' => BrandingButtons::ratio($rootBlock['accent-d'], $rootBlock['d-bg']) >= 3.0];
foreach ($palettes as $p) {
    $t = $tokens($p[2]) + $rootBlock;
    foreach (['light' => ['accent-l', 'n-bg'], 'dark' => ['accent-d', 'd-bg']] as $mode => [$accent, $bg]) {
        $rings[$p[1] . ' ' . $mode] = BrandingButtons::ratio($t[$accent], $t[$bg]) >= 3.0;
    }
}
check('the focus ring has 3:1 on the page in every palette, light and dark', array_keys(array_filter($rings, static fn($ok) => !$ok)), []);
check('the palettes the check knows are the ones the style sheet has', array_map(static fn($p) => $p[1], $palettes), ['indigo', 'emerald', 'teal', 'rose', 'amber', 'garnet']);
$fine = true;
foreach ($palettes as $p) {
    $t = $tokens($p[2]);
    $context = ['design' => [], 'palette' => $p[1]];
    $fine = $fine && BrandingButtons::hex(['role' => 'accent'], 'light', $context) === $t['accent-l'] && BrandingButtons::hex(['role' => 'accent'], 'dark', $context) === $t['accent-d'];
}
check('and their accents are the ones the check uses', $fine, true);
// The ring over each section the buttons sit in: the dark and the accent band set their own --color-focus.
check('on a dark band and the accent band the ring follows the section (it is the band\'s accent, or the band\'s text)', [has($site, "  --color-focus: var(--accent-d);\n  --card-bg: var(--d-surface);\n  --section-bg: var(--d-bg);"), has($site, '--color-focus: var(--accent-contrast-l);')], [true, true]);

// ---- the buttons of blocks and forms keep their look
$formButton = substr($site, (int)strpos($site, ".form-grid button,\n.search-form button {"), 900);
check('a form button is the Primary look by default: the accent, its contrast colour and the hover accent', [
    has($formButton, '--btn-bg: var(--accent);') && has($formButton, '--btn-text: var(--accent-contrast);') && has($formButton, '--btn-border: var(--accent);'),
    (bool)preg_match('/\.form-grid button:hover,\s*\.search-form button:hover \{\s*--btn-bg: var\(--accent-hover\);\s*--btn-border: var\(--accent-hover\);/', $site),
], [true, true]);
check('the Text style is in the style sheet', has($site, ".btn-text {\n  --btn-bg: transparent;\n  --btn-text: var(--accent);"), true);
$loader = new \Twig\Loader\FilesystemLoader($repo . '/themes/default/components');
$twig = new \Twig\Environment($loader, ['autoescape' => 'html']);
$twig->addFunction(new \Twig\TwigFunction('link_url', static fn($url, $prefix = '') => (string)$url));
$twig->addFunction(new \Twig\TwigFunction('icon', static fn($name, $class = '') => '<svg class="' . $class . '"></svg>', ['is_safe' => ['html']]));
$render = static fn(array $items): string => $twig->createTemplate("{% import 'ui.twig' as ui %}{{ ui.actions(items, '') }}")->render(['items' => $items]);
$html = $render([['label' => 'A', 'url' => '/a', 'style' => 'primary'], ['label' => 'B', 'url' => '/b', 'style' => 'secondary'], ['label' => 'C', 'url' => '/c']]);
check('blocks: Primary and Secondary (and no style) draw the classes they always did, and the arrow only on Primary', [
    substr_count($html, 'class="btn btn-primary"'), substr_count($html, 'class="btn btn-secondary"'), substr_count($html, 'btn-icon'),
], [2, 1, 2]);
check('Text is new: a link-like button with the arrow', has($render([['label' => 'T', 'url' => '/t', 'style' => 'text']]), 'class="btn btn-text"') && has($render([['label' => 'T', 'url' => '/t', 'style' => 'text']]), 'btn-icon'), true);
$blocks = [];
foreach (['hero', 'cta', 'text', 'text-image', 'hero-slider'] as $block) {
    $yaml = (string)file_get_contents($repo . '/themes/default/blocks/' . $block . '/block.yaml');
    $blocks[$block] = [substr_count($yaml, 'options: { primary: Primary, secondary: Secondary, text: Text }'), has($yaml, 'default: primary') || has($yaml, 'default: secondary')];
}
check('blocks that offer a button style keep their defaults and now offer Text', array_map(static fn($b) => $b[1], $blocks), ['hero' => true, 'cta' => true, 'text' => true, 'text-image' => true, 'hero-slider' => true]);
check('every one of them lists the three styles', array_map(static fn($b) => $b[0] > 0, $blocks), ['hero' => true, 'cta' => true, 'text' => true, 'text-image' => true, 'hero-slider' => true]);

// ---- the editor's round trip: what the form sends, what is stored, what the page gets
$input = ['design' => [
    'btn_primary_bg' => 'ink', 'btn_primary_text' => ' #FFF ', 'btn_secondary_border_width' => '2', 'btn_secondary_radius' => 'square', 'btn_text_hover_text' => 'surface',
    'link_color' => '#0B57D0', 'link_underline' => 'hover', 'link_thickness' => 'thick', 'link_hover' => 'javascript:alert(1)', 'btn_primary_hover_bg' => 'red', 'btn_text_radius' => 'huge',
]];
$stored = $theme->resolveSettings($theme->settingsFromInput($input, $theme->defaultSettings()));
$design = $stored['design'];
check('the form is read: roles stay, a hex is written #rrggbb in lower case, junk is not kept', [
    $design['btn_primary_bg'], $design['btn_primary_text'], $design['btn_secondary_border_width'], $design['btn_secondary_radius'], $design['btn_text_hover_text'],
    $design['link_color'], $design['link_underline'], $design['link_thickness'], $design['link_hover'], $design['btn_primary_hover_bg'], $design['btn_text_radius'],
], ['ink', '#ffffff', '2', 'square', 'surface', '#0b57d0', 'hover', 'thick', '', '', 'auto']);
$again = $theme->resolveSettings($theme->settingsFromInput(['design' => array_intersect_key($design, array_flip($keys))], $stored));
check('saving what was stored changes nothing', $again['design'], $design);
$page = Branding::css($stored);
check('and the page gets the CSS of those choices', [has($page, '--btn-bg:var(--ink)'), has($page, 'border-width:2px'), has($page, 'color:#0b57d0'), has($page, 'text-decoration-thickness:0.2em')], [true, true, true, true]);
check('Branding adds the CSS after its own, never before', strpos($page, ':root[data-theme][data-mode] .btn-primary') > 0, true);
$field = FieldSchema::normalize(['t' => ['type' => 'tone']])['t'];
check('a tone field: a role, a hex, empty; anything else keeps the current value', [
    FieldSchema::fromInput(['t' => $field], ['t' => 'surface'], ['t' => ''])['t'],
    FieldSchema::fromInput(['t' => $field], ['t' => '#ABC'], ['t' => ''])['t'],
    FieldSchema::fromInput(['t' => $field], ['t' => ''], ['t' => 'ink'])['t'],
    FieldSchema::fromInput(['t' => $field], ['t' => 'sparkle'], ['t' => 'ink'])['t'],
    FieldSchema::fromInput(['t' => $field], ['t' => ['ink']], ['t' => 'ink'])['t'],
], ['surface', '#aabbcc', '', 'ink', '']);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
