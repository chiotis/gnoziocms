<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Turns the choices of Theme > Branding into what a page needs: the CSS that changes the default theme's design tokens
 * (colours, type, spacing, corners, buttons, logo sizes), the icons and colour of the browser, and the attributes that
 * select a palette, a colour mode, a font pairing, a shape and the decoration.
 *
 * Every number and colour is optional and checked by the settings schema before it gets here. A choice that is empty,
 * or says "theme", adds nothing, so a site that sets none of them is styled by the theme's own style sheet alone. The
 * CSS is made of numbers, hex colours and words from the lists below, never of text a person typed, so nothing a
 * person types can reach a style sheet as code.
 */
final class Branding
{
    /** The font stacks of the font choices. System fonts, so there is nothing to download; "custom" is the person's own file. */
    public const FONT_STACKS = [
        'inter' => '"Inter", var(--font-system)',
        'system' => 'var(--font-system)',
        'humanist' => 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", "Source Sans Pro", sans-serif',
        'geometric' => 'Avenir, Montserrat, Corbel, "URW Gothic", "Source Sans Pro", sans-serif',
        'neo_grotesque' => 'Roboto, "Helvetica Neue", "Arial Nova", "Nimbus Sans", Arial, sans-serif',
        'rounded' => 'ui-rounded, "Hiragino Maru Gothic ProN", Quicksand, Comfortaa, Manjari, "Arial Rounded MT", "Arial Rounded MT Bold", Calibri, sans-serif',
        'industrial' => 'Bahnschrift, "DIN Alternate", "Franklin Gothic Medium", "Nimbus Sans Narrow", sans-serif-condensed, sans-serif',
        'classical' => 'Optima, Candara, "Noto Sans", "Source Sans Pro", sans-serif',
        'transitional' => 'Charter, "Bitstream Charter", "Sitka Text", Cambria, serif',
        'old_style' => '"Iowan Old Style", "Palatino Linotype", "URW Palladio L", P052, serif',
        'slab' => 'Rockwell, "Rockwell Nova", "Roboto Slab", "DejaVu Serif", "Sitka Small", serif',
        'didone' => 'Didot, "Bodoni MT", "Noto Serif Display", "URW Bookman", Georgia, serif',
        'mono' => 'var(--font-mono)',
        'custom' => '"Brand Font", var(--font-system)',
    ];

    private const TRACKING = ['tighter' => '-0.045em', 'tight' => '-0.028em', 'normal' => '0', 'wide' => '0.025em'];
    private const LEADING = ['tight' => '1.05', 'normal' => '1.2', 'relaxed' => '1.35'];
    private const SPACING = ['tight' => 0.8, 'airy' => 1.2, 'roomy' => 1.4];

    /** The selectors that win over the palette, font, shape and mode rules of the style sheet (which sit on :root[data-…]). */
    private const ROOT = ':root[data-theme][data-mode]';

    /** The dark surface the soft accent of dark mode is mixed into when the person has not chosen one (the theme's own). */
    private const DARK_BACKGROUND = '#0b1220';

    /**
     * The CSS to add after the theme's style sheet, or '' when nothing is set.
     *
     * @param array<string, mixed> $settings the resolved theme settings
     * @param string $baseUrl where the site lives ('' for the root), for the address of a font file
     */
    public static function css(array $settings, string $baseUrl = ''): string
    {
        $design = SitePalettes::apply(is_array($settings['design'] ?? null) ? $settings['design'] : []);
        $brand = is_array($settings['brand'] ?? null) ? $settings['brand'] : [];
        $vars = [];
        $rules = [];
        $fontFace = '';

        // ---- colours: light, dark, and the near-black of the dark panels
        $light = self::colours($design, 'light');
        $dark = self::colours($design, 'dark');
        if (isset($light['accent'])) {
            $a = $light['accent'];
            $vars['--accent-l'] = $a;
            $vars['--accent-l-hover'] = self::mix($a, '#000000', 0.18);
            $vars['--accent-l-soft'] = self::mix($a, '#ffffff', 0.9);
            $vars['--accent-contrast-l'] = self::contrast($a);
            // Slate has its own default band background; an explicit accent must replace that background too.
            $vars['--accent-band-bg'] = $a;
        }
        if (isset($light['background'])) {
            $vars['--n-bg'] = $light['background'];
        }
        $lightInk = $light['text'] ?? '#0e1726';
        if (isset($light['surface'])) {
            $vars['--n-surface'] = $light['surface'];
            $vars['--n-surface-2'] = self::mix($light['surface'], $lightInk, 0.05);
        }
        if (isset($light['text'])) {
            $vars['--n-text'] = $light['text'];
        }
        if (isset($light['muted'])) {
            $vars['--n-muted'] = $light['muted'];
        }
        if (isset($light['border'])) {
            $vars['--n-border'] = $light['border'];
            $vars['--n-border-strong'] = self::mix($light['border'], $lightInk, 0.18);
        }

        $darkBase = $dark['background'] ?? self::DARK_BACKGROUND;
        if (isset($dark['accent'])) {
            $a = $dark['accent'];
            $vars['--accent-d'] = $a;
            $vars['--accent-d-hover'] = self::mix($a, '#ffffff', 0.3);
            $vars['--accent-d-soft'] = self::mix($a, $darkBase, 0.86);
            $vars['--accent-contrast-d'] = self::contrast($a);
        }
        if (isset($dark['background'])) {
            $vars['--d-bg'] = $dark['background'];
        }
        $darkText = $dark['text'] ?? '#e8edf4';
        if (isset($dark['surface'])) {
            $vars['--d-surface'] = $dark['surface'];
            $vars['--d-surface-2'] = self::mix($dark['surface'], $darkText, 0.06);
        }
        if (isset($dark['text'])) {
            $vars['--d-text'] = $dark['text'];
        }
        if (isset($dark['muted'])) {
            $vars['--d-muted'] = $dark['muted'];
        }
        if (isset($dark['border'])) {
            $vars['--d-border'] = $dark['border'];
            $vars['--d-border-strong'] = self::mix($dark['border'], $darkText, 0.2);
        }
        $ink = self::hex($design['ink'] ?? '');
        if ($ink !== null) {
            [$r, $g, $b] = self::rgb($ink);
            $vars['--ink'] = $ink;
            $vars['--ink-rgb'] = $r . ' ' . $g . ' ' . $b;
            $vars['--ink-2'] = self::mix($ink, '#ffffff', 0.08);
        }

        SecondaryColour::add($design, $darkBase, $vars, $rules);

        // ---- type: families, size, scale, headings
        $customFont = self::fontFile((string)($design['font_file'] ?? ''), $baseUrl);
        // A role that uses "Your own font" takes the family chosen for it (BrandingFonts); without one it takes the single file.
        $stacks = BrandingFonts::stacks($design);
        $legacyUsed = false;
        foreach (['heading_font' => ['--font-heading', 'heading'], 'body_font' => ['--font-body', 'body']] as $key => [$var, $role]) {
            $choice = (string)($design[$key] ?? 'pairing');
            if ($choice === 'custom' && isset($stacks[$role])) {
                $vars[$var] = $stacks[$role];
                if ($role === 'heading') {
                    $vars['--heading-font-custom'] = 'var(--font-heading)';
                }
            } elseif (isset(self::FONT_STACKS[$choice]) && ($choice !== 'custom' || $customFont !== null)) {
                $vars[$var] = self::FONT_STACKS[$choice];
                $legacyUsed = $legacyUsed || $choice === 'custom';
                if ($role === 'heading') {
                    $vars['--heading-font-custom'] = 'var(--font-heading)';
                }
            }
        }
        $fontFace = BrandingFonts::fontFaces($design, $baseUrl);
        if ($customFont !== null && $legacyUsed) {
            $fontFace .= '@font-face{font-family:"Brand Font";font-style:normal;font-weight:100 900;font-display:swap;src:url("'
                . $customFont['url'] . '") format("' . $customFont['format'] . '")}';
        }
        $base = self::number($design['base_size'] ?? '');
        if ($base !== null && abs($base - 16.0) > 0.01) {
            $vars['--type-scale'] = self::fmt($base / 16);
        }
        $ratio = self::number($design['scale'] ?? '');
        if ($ratio !== null && $ratio > 1.0) {
            $size = $base ?? 16.0;
            // Steps 1 to 5 of the theme; the small steps and the text size keep the theme's. A phone uses a gentler ratio.
            $phone = 1 + ($ratio - 1) * 0.62;
            for ($step = 1; $step <= 5; $step++) {
                $vars['--step-' . $step] = self::fluid($size * $phone ** $step, $size * 1.0625 * $ratio ** $step);
            }
            $vars['--hero-title-size'] = 'var(--step-5)';
        }
        $weight = (string)($design['heading_weight'] ?? '');
        if (in_array($weight, ['400', '500', '600', '700', '800'], true)) {
            $vars['--heading-weight'] = $weight;
            $vars['--heading-weight-custom'] = $weight;
        }
        $tracking = (string)($design['heading_tracking'] ?? '');
        if (isset(self::TRACKING[$tracking])) {
            $vars['--heading-tracking'] = self::TRACKING[$tracking];
            $vars['--heading-tracking-custom'] = self::TRACKING[$tracking];
        }
        $uppercase = ($design['heading_case'] ?? '') === 'uppercase';
        if ($uppercase && !isset(self::TRACKING[$tracking])) {
            $vars['--heading-tracking'] = '0.02em';
            $vars['--heading-tracking-custom'] = '0.02em';
        }
        // The colour of headings: the accent follows the palette and the mode; own colours are one for each mode (a mode left empty keeps the text).
        $headingColor = (string)($design['heading_color'] ?? 'text');
        $headingDark = null;
        if ($headingColor === 'accent') {
            $vars['--heading-color'] = 'var(--accent)';
        } elseif ($headingColor === 'custom') {
            $lightHeading = self::hex($design['heading_color_light'] ?? '');
            $darkHeading = self::hex($design['heading_color_dark'] ?? '');
            if ($lightHeading !== null) {
                $vars['--heading-color'] = $lightHeading;
            }
            if ($lightHeading !== null || $darkHeading !== null) {
                $headingDark = $darkHeading ?? 'initial';
            }
        }
        $headings = [];
        $leading = (string)($design['heading_leading'] ?? '');
        if (isset(self::LEADING[$leading])) {
            $vars['--heading-leading'] = self::LEADING[$leading];
            $headings[] = 'line-height:' . self::LEADING[$leading];
        }
        if ($uppercase) {
            $headings[] = 'text-transform:uppercase';
        }
        if ($headings !== []) {
            $rules[] = 'h1,h2,h3,h4,h5,h6{' . implode(';', $headings) . '}';
        }
        $lineHeight = self::number($design['line_height'] ?? '');
        if ($lineHeight !== null) {
            $vars['--body-leading'] = self::fmt($lineHeight, 2);
            $rules[] = 'body.theme-body{line-height:' . self::fmt($lineHeight, 2) . '}';
        }

        // ---- layout and spacing
        $container = self::number($design['container'] ?? '');
        if ($container !== null) {
            $vars['--container'] = self::rem($container);
        }
        $reading = self::number($design['reading_width'] ?? '');
        if ($reading !== null) {
            $vars['--container-narrow'] = self::rem($reading);
        }
        $gutter = self::number($design['gutter'] ?? '');
        if ($gutter !== null) {
            $vars['--gutter'] = self::fluid(min(20.0, $gutter), $gutter);
        }
        $header = self::number($design['header_height'] ?? '');
        if ($header !== null) {
            $vars['--header-min'] = self::rem($header);
        }
        $section = self::number($design['section_space'] ?? '');
        if ($section !== null) {
            $vars['--section-scale'] = self::fmt($section / 112);
        }
        $spacing = (string)($design['spacing'] ?? '');
        if (isset(self::SPACING[$spacing])) {
            $vars['--space-scale'] = self::fmt(self::SPACING[$spacing]);
        }

        // ---- corners, shadows
        $radius = self::number($design['radius'] ?? '');
        if ($radius !== null) {
            $vars['--radius-s'] = self::rem($radius * 0.57);
            $vars['--radius-m'] = self::rem($radius);
            $vars['--radius-l'] = self::rem($radius * 1.43);
            $vars['--radius-xl'] = self::rem($radius * 2);
        }
        $buttonRadius = (string)($design['button_radius'] ?? '');
        if ($buttonRadius === 'pill') {
            $vars['--radius-button'] = '999px';
        } elseif ($buttonRadius === 'rounded') {
            $vars['--radius-button'] = 'var(--radius-s)';
        } elseif ($buttonRadius === 'square') {
            $vars['--radius-button'] = '0px';
        }
        $shadow = BrandingShape::shadow((string)($design['shadows'] ?? ''));
        if ($shadow !== null) {
            $vars['--shadow-s'] = $shadow[0];
            $vars['--shadow-m'] = $shadow[1];
        }

        // ---- buttons
        foreach (['button_height' => '--btn-height', 'button_pad' => '--btn-pad-x', 'button_size' => '--btn-size'] as $key => $var) {
            $value = self::number($design[$key] ?? '');
            if ($value !== null) {
                $vars[$var] = self::rem($value);
            }
        }
        $buttonWeight = (string)($design['button_weight'] ?? '');
        if (in_array($buttonWeight, ['500', '600', '700', '800'], true)) {
            $vars['--btn-weight'] = $buttonWeight;
        }
        if (($design['button_case'] ?? '') === 'uppercase') {
            $vars['--btn-case'] = 'uppercase';
            $vars['--btn-tracking'] = '0.06em';
        }

        // ---- the logo and the name of the site
        foreach (['logo_height' => '--logo-height', 'logo_height_mobile' => '--logo-height-mobile', 'footer_logo_height' => '--footer-logo-height', 'name_size' => '--brand-name-size'] as $key => $var) {
            $value = self::number($brand[$key] ?? '');
            if ($value !== null) {
                $vars[$var] = self::rem($value);
            }
        }

        $css = $fontFace;
        if ($vars !== []) {
            $declarations = '';
            foreach ($vars as $name => $value) {
                $declarations .= $name . ':' . $value . ';';
            }
            $css .= self::ROOT . '{' . $declarations . '}';
        }
        if ($headingDark !== null) {
            $rules[] = ':root.dark[data-theme][data-mode]{--heading-color:' . $headingDark . '}';
            $rules[] = '@media(prefers-color-scheme:dark){:root[data-mode="system"]:not(.light)[data-theme]{--heading-color:' . $headingDark . '}}';
        }
        // Links and the three button styles (BrandingButtons): nothing when none is chosen.
        $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];
        // The per-element type, the forms and the corner, border and phone presets are made by their own classes.
        return $css . implode('', $rules) . BrandingTypography::css($design) . BrandingForms::css($design) . BrandingShape::css($design)
            . BrandingButtons::css($design, (string)($appearance['palette'] ?? 'slate'));
    }

    /**
     * The tags for the head of a page: the icon of the tab, the icon of a phone's home screen, the colour of the browser's
     * address bar and, for a font file of the site's own, a hint to fetch it early.
     *
     * @param array<string, mixed> $settings the resolved theme settings
     * @param string $language the language of the page, to preload the script of a font that it needs
     */
    public static function head(array $settings, string $baseUrl = '', string $language = ''): string
    {
        $brand = is_array($settings['brand'] ?? null) ? $settings['brand'] : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        $html = '';
        $favicon = trim((string)($brand['favicon'] ?? ''));
        if ($favicon !== '' && FieldSchema::isSafeUrl($favicon)) {
            $types = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $type = $types[strtolower(pathinfo((string)parse_url($favicon, PHP_URL_PATH), PATHINFO_EXTENSION))] ?? '';
            $html .= '<link rel="icon" href="' . self::attr(self::address($favicon, $baseUrl)) . '"' . ($type !== '' ? ' type="' . $type . '"' : '') . ">\n";
        }
        $touch = trim((string)($brand['touch_icon'] ?? ''));
        if ($touch !== '' && FieldSchema::isSafeUrl($touch)) {
            $html .= '<link rel="apple-touch-icon" href="' . self::attr(self::address($touch, $baseUrl)) . "\">\n";
        }
        $colour = self::hex($brand['theme_color'] ?? '');
        if ($colour !== null) {
            $html .= '<meta name="theme-color" content="' . $colour . "\">\n";
        }
        $font = self::fontFile((string)($design['font_file'] ?? ''), $baseUrl);
        $stacks = BrandingFonts::stacks($design);
        $uses = [];
        foreach (['heading', 'body'] as $role) {
            if (!isset($stacks[$role])) {
                $uses[] = (string)($design[$role . '_font'] ?? '');
            }
        }
        if ($font !== null && $font['format'] === 'woff2' && in_array('custom', $uses, true)) {
            $html .= '<link rel="preload" href="' . self::attr($font['url']) . "\" as=\"font\" type=\"font/woff2\" crossorigin>\n";
        }
        $html .= BrandingFonts::preloads($design, $baseUrl, $language);
        return $html;
    }

    /**
     * The choices that select the theme's own looks, as the attributes of the page's html element.
     *
     * @param array<string, mixed> $settings the resolved theme settings
     * @return array<string, string> data attribute => value
     */
    public static function attributes(array $settings): array
    {
        $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];
        return [
            'data-theme' => (string)($appearance['palette'] ?? 'slate') ?: 'slate',
            'data-mode' => (string)($appearance['mode'] ?? 'system') ?: 'system',
            'data-font' => (string)($appearance['font'] ?? 'sans') ?: 'sans',
            'data-shape' => (string)($appearance['shape'] ?? 'soft') ?: 'soft',
            'data-glow' => ($appearance['glow'] ?? 'soft') === 'solid' ? 'solid' : 'soft',
        ];
    }

    /**
     * The colours of one mode, each as #rrggbb, those that are set.
     *
     * @param array<string, mixed> $design
     * @return array<string, string>
     */
    private static function colours(array $design, string $mode): array
    {
        $colours = [];
        foreach (['accent', 'background', 'surface', 'text', 'muted', 'border'] as $role) {
            $hex = self::hex($design[$mode . '_' . $role] ?? '');
            if ($hex !== null) {
                $colours[$role] = $hex;
            }
        }
        return $colours;
    }

    /** @return array{url: string, format: string}|null a font file the theme can load, or null */
    private static function fontFile(string $value, string $baseUrl): ?array
    {
        $value = trim($value);
        if ($value === '' || !FieldSchema::isSafeUrl($value)) {
            return null;
        }
        $extension = strtolower(pathinfo((string)parse_url($value, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($extension, ['woff2', 'woff'], true) || str_contains($value, '..')) {
            return null;
        }
        // A bare path is a file of custom/assets, served under /_custom/.
        if (!preg_match('#^(https?://|/)#i', $value)) {
            $value = '/_custom/' . ltrim($value, '/');
        }
        return ['url' => self::address($value, $baseUrl), 'format' => $extension];
    }

    /** The address of a path on this site (with the folder the site lives in), or a full address as it is. */
    private static function address(string $value, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($value, '/');
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? FieldSchema::normalizeHex($value) : null;
    }

    /** A number that was set (the schema keeps '' for "not set"), or null. */
    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && $value !== '' && is_numeric($value)) ? (float)$value : null;
    }

    private static function fmt(float $value, int $decimals = 4): string
    {
        $text = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
        return $text === '' || $text === '-0' ? '0' : $text;
    }

    private static function rem(float $px): string
    {
        return $px == 0.0 ? '0px' : self::fmt($px / 16) . 'rem';
    }

    /** A size that grows with the width of the window: $min pixels at 320 wide, $max pixels at 1280 wide (and not beyond either). */
    private static function fluid(float $min, float $max): string
    {
        if (abs($max - $min) < 0.05) {
            return self::rem($max);
        }
        $slope = ($max - $min) / 960;
        $intercept = $min - $slope * 320;
        return 'clamp(' . self::rem($min) . ', calc(' . self::fmt($intercept / 16) . 'rem + ' . self::fmt($slope * 100) . 'vw), ' . self::rem($max) . ')';
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** $a moved $amount (0 to 1) of the way to $b. */
    private static function mix(string $a, string $b, float $amount): string
    {
        $from = self::rgb($a);
        $to = self::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int)round($from[$i] + ($to[$i] - $from[$i]) * $amount)), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** White or the theme's near-black, whichever reads better on $background. */
    private static function contrast(string $background): string
    {
        $dark = '#0b1220';
        $l = self::luminance($background);
        $withWhite = 1.05 / ($l + 0.05);
        $withDark = ($l + 0.05) / (self::luminance($dark) + 0.05);
        return $withWhite >= $withDark ? '#ffffff' : $dark;
    }
}
