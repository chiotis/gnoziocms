<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Theme > Branding: the colours and shape of links and of the three button styles (Primary, Secondary, Text).
 *
 * Every colour is a "tone": a palette role (accent, text, surface, …) that follows the palette and the colour mode, or a custom
 * #rrggbb that is used only while it passes AA (4.5:1) against its partner in light and in dark. A setting that is empty adds
 * nothing, so a site that sets none of them is styled by the theme's own style sheet alone. Hover is worked out from the normal
 * state unless it is set. Focus and disabled are not settings: the theme gives them safe looks.
 *
 * The CSS is made of the words of the lists below, numbers and validated hex colours, never of text a person typed.
 */
final class BrandingButtons
{
    /** The three styles and where each one is drawn. The second list is what the form buttons and the search button follow (Primary). */
    public const STYLES = [
        'primary' => ['.btn-primary', '.form-grid button', '.search-form button'],
        'secondary' => ['.btn:not(.btn-primary):not(.btn-text)'],
        'text' => ['.btn-text'],
    ];

    /** The look the theme gives each style: [background, text, border] as roles. */
    public const STYLE_DEFAULTS = [
        'primary' => ['accent', 'on_accent', 'accent'],
        'secondary' => ['none', 'text', 'border'],
        'text' => ['none', 'accent', 'none'],
    ];

    /** Roles and the CSS each one is (the variables follow the section the control sits in, and the colour mode). */
    public const ROLES = [
        'accent' => 'var(--accent)',
        'on_accent' => 'var(--accent-contrast)',
        'secondary' => 'var(--color-secondary, var(--accent))',
        'text' => 'var(--color-text)',
        'muted' => 'var(--color-muted)',
        'background' => 'var(--color-bg)',
        'surface' => 'var(--color-surface)',
        'border' => 'var(--color-border-strong)',
        'ink' => 'var(--ink)',
        'white' => '#ffffff',
        'none' => 'transparent',
    ];

    public const RADII = ['pill' => '999px', 'rounded' => 'var(--radius-s)', 'square' => '0px'];
    public const WIDTHS = ['0', '1', '2', '3'];
    public const THICKNESS = ['thin' => '0.05em', 'medium' => '0.12em', 'thick' => '0.2em'];
    public const OFFSET = ['near' => '0.08em', 'far' => '0.32em'];

    /** What the palettes of the theme are, in hex, so a custom colour can be checked: [accent, accent hover] light and dark, and the neutrals. */
    private const BASE = [
        'light' => ['background' => '#ffffff', 'surface' => '#f5f6f8', 'text' => '#0e1726', 'muted' => '#4a5568', 'border' => '#e2e6ec', 'ink' => '#0b1220'],
        'dark' => ['background' => '#0b1220', 'surface' => '#111a2c', 'text' => '#e8edf4', 'muted' => '#a6b3c5', 'border' => '#223049', 'ink' => '#0b1220'],
    ];
    private const PALETTES = [
        'slate' => ['light' => ['#1e293b', '#0f172a'], 'dark' => ['#e2e8f0', '#f8fafc']],
        'indigo' => ['light' => ['#4338ca', '#3730a3'], 'dark' => ['#a5b4fc', '#c7d2fe']],
        'emerald' => ['light' => ['#047857', '#065f46'], 'dark' => ['#6ee7b7', '#a7f3d0']],
        'teal' => ['light' => ['#0f766e', '#115e59'], 'dark' => ['#5eead4', '#99f6e4']],
        'rose' => ['light' => ['#be123c', '#9f1239'], 'dark' => ['#fda4af', '#fecdd3']],
        'amber' => ['light' => ['#b45309', '#92400e'], 'dark' => ['#fcd34d', '#fde68a']],
        'garnet' => [
            'light' => ['#8f1d1d', '#701414', 'background' => '#fffdf8', 'surface' => '#f8f1e4', 'text' => '#261b14', 'muted' => '#5b4a3d', 'border' => '#eadfca', 'ink' => '#323130'],
            'dark' => ['#f4a8a0', '#f9c9c3', 'background' => '#232120', 'surface' => '#2c2a28', 'text' => '#f4efea', 'muted' => '#c6bdb5', 'border' => '#433f3b', 'ink' => '#323130'],
        ],
    ];

    private const ROOT = ':root[data-theme][data-mode]';
    private const AA = 4.5;

    /** The properties of a style that are tones, in the order of the screen: the normal state, then hover. */
    public const TONES = ['bg', 'text', 'border', 'hover_bg', 'hover_text', 'hover_border'];

    /** The keys of this feature in the design section, for the settings schema, the export and the tests. @return string[] */
    public static function keys(): array
    {
        $keys = ['link_color', 'link_hover', 'link_color_dark', 'link_hover_dark', 'link_underline', 'link_thickness', 'link_offset'];
        foreach (array_keys(self::STYLES) as $style) {
            foreach (self::TONES as $tone) {
                $keys[] = 'btn_' . $style . '_' . $tone;
            }
            $keys[] = 'btn_' . $style . '_border_width';
            $keys[] = 'btn_' . $style . '_radius';
        }
        return $keys;
    }

    /**
     * The CSS for the choices, or '' when none is set.
     *
     * @param array<string, mixed> $design the resolved design section
     */
    public static function css(array $design, string $palette = 'slate'): string
    {
        $context = ['design' => $design, 'palette' => isset(self::PALETTES[$palette]) ? $palette : 'slate'];
        return self::links($context) . self::buttons($context);
    }

    /** Parses one tone: ['role' => name] or ['hex' => #rrggbb], or null when it is not set or not valid. @return array{role?: string, hex?: string}|null */
    public static function tone(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (isset(self::ROLES[$value])) {
            return ['role' => $value];
        }
        $hex = FieldSchema::normalizeHex($value);
        return $hex !== null ? ['hex' => $hex] : null;
    }

    /** The WCAG contrast ratio of two #rrggbb colours (1 to 21). */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * The hex a tone has in a colour mode, or null for a tone that has none of its own (transparent): the caller then takes the page.
     *
     * @param array{design: array<string, mixed>, palette: string} $context
     */
    public static function hex(array $tone, string $mode, array $context): ?string
    {
        if (isset($tone['hex'])) {
            return $tone['hex'];
        }
        $role = $tone['role'] ?? 'none';
        $design = $context['design'];
        $own = self::PALETTES[$context['palette']][$mode];
        $neutral = static fn(string $name): string => (string)(self::hexSetting($design[$mode . '_' . $name] ?? null) ?? ($own[$name] ?? self::BASE[$mode][$name]));
        $accent = self::hexSetting($design[$mode . '_accent'] ?? null) ?? $own[0];
        switch ($role) {
            case 'accent':
                return $accent;
            case 'on_accent':
                return self::contrastOn($accent);
            case 'secondary':
                return self::hexSetting($design[$mode . '_secondary'] ?? null) ?? $accent;
            case 'text':
            case 'muted':
            case 'background':
            case 'surface':
                return $neutral($role);
            case 'border':
                return self::mix($neutral('border'), $neutral('text'), $mode === 'light' ? 0.18 : 0.2);
            case 'ink':
                return self::hexSetting($design['ink'] ?? null) ?? ($own['ink'] ?? self::BASE[$mode]['ink']);
            case 'white':
                return '#ffffff';
        }
        return null;
    }

    /** The CSS of a tone: its variable for a role, the colour for a custom one. */
    public static function value(array $tone): string
    {
        return isset($tone['hex']) ? $tone['hex'] : self::ROLES[$tone['role']];
    }

    // ---- links

    /** @param array{design: array<string, mixed>, palette: string} $context */
    private static function links(array $context): string
    {
        $d = $context['design'];
        $light = self::tone($d['link_color'] ?? '');
        $lightHover = self::tone($d['link_hover'] ?? '');
        $dark = self::tone($d['link_color_dark'] ?? '');
        $darkHover = self::tone($d['link_hover_dark'] ?? '');
        $underline = (string)($d['link_underline'] ?? '');
        $thickness = self::THICKNESS[(string)($d['link_thickness'] ?? '')] ?? null;
        $offset = self::OFFSET[(string)($d['link_offset'] ?? '')] ?? null;
        $css = '';

        // The colours. A custom colour is kept for a mode only while it reads on that mode's page.
        $pass = static fn(?array $tone, string $mode): bool => $tone === null || !isset($tone['hex'])
            || self::ratio($tone['hex'], (string)self::hex(['role' => 'background'], $mode, $context)) >= self::AA;
        $effective = [
            'light' => $pass($light, 'light') ? $light : null,
            'dark' => $dark !== null ? ($pass($dark, 'dark') ? $dark : null) : ($pass($light, 'dark') ? $light : null),
        ];
        $hovers = [
            'light' => $pass($lightHover, 'light') ? $lightHover : null,
            'dark' => $darkHover !== null ? ($pass($darkHover, 'dark') ? $darkHover : null) : ($pass($lightHover, 'dark') ? $lightHover : null),
        ];
        // Colours stay out of the panels that bring their own (a dark band, the accent band): their links keep the theme's.
        $links = 'a:where(:not(.tone-contrast a, .tone-accent a';
        $declarations = ['light' => [], 'dark' => []];
        foreach (['light', 'dark'] as $mode) {
            $tone = $effective[$mode];
            $hover = $hovers[$mode];
            // What the person chose for this mode (a dark mode left empty follows the light one).
            $chosen = $mode === 'light' ? $light : ($dark ?? $light);
            $chosenHover = $mode === 'light' ? $lightHover : ($darkHover ?? $lightHover);
            $reset = $tone === null && $chosen !== null && $mode === 'dark' && $effective['light'] !== null;
            if ($tone !== null) {
                $declarations[$mode]['color'] = self::value($tone);
            } elseif ($reset) {
                // A custom colour that does not read on the dark page: dark mode keeps the theme's link colour.
                $declarations[$mode]['color'] = 'var(--accent)';
            }
            if ($hover !== null) {
                $declarations[$mode]['hover'] = self::value($hover);
            } elseif ($tone !== null && $chosenHover === null) {
                $declarations[$mode]['hover'] = ($tone['role'] ?? '') === 'accent' ? 'var(--accent-hover)' : 'color-mix(in srgb,' . self::value($tone) . ' 75%,var(--color-text))';
            } elseif ($reset) {
                $declarations[$mode]['hover'] = 'var(--accent-hover)';
            }
        }
        $emit = static function (string $scope, array $values, string $exclude) use ($links): string {
            $selector = ':where(' . $scope . ') ' . $links . $exclude . '))';
            $out = '';
            if (isset($values['color'])) {
                $out .= $selector . '{color:' . $values['color'] . '}';
            }
            if (isset($values['hover'])) {
                $out .= $selector . ':hover{color:' . $values['hover'] . '}';
            }
            return $out;
        };
        $css .= $emit(self::ROOT, $declarations['light'], '');
        if ($declarations['dark'] !== []) {
            // The dark rules also serve the specimen's dark panel (.mode-dark); its light panel (.mode-light) keeps the light ones in a dark page.
            $css .= $emit(':root.dark[data-theme][data-mode], .mode-dark', $declarations['dark'], ', .mode-light a');
            $css .= '@media(prefers-color-scheme:dark){' . $emit(':root[data-mode="system"]:not(.light)[data-theme]', $declarations['dark'], ', .mode-light a') . '}';
        }

        // The underline, its thickness and its distance from the text.
        $shape = '';
        if ($thickness !== null) {
            $shape .= 'text-decoration-thickness:' . $thickness . ';';
        }
        if ($offset !== null) {
            $shape .= 'text-underline-offset:' . $offset . ';';
        }
        if ($shape !== '') {
            $css .= ':where(' . self::ROOT . ') a{' . rtrim($shape, ';') . '}';
        }
        $content = ':where(' . self::ROOT . ') :is(.prose,.prose-block) a:where(:not(.btn,.text-link))';
        if ($underline === 'always') {
            $css .= $content . '{text-decoration-line:underline}';
        } elseif ($underline === 'hover') {
            $css .= $content . '{text-decoration-line:none}' . $content . ':is(:hover,:focus-visible){text-decoration-line:underline}';
        } elseif ($underline === 'never') {
            $css .= $content . '{text-decoration-line:none}' . $content . ':focus-visible{text-decoration-line:underline}';
        }
        return $css;
    }

    // ---- buttons

    /** @param array{design: array<string, mixed>, palette: string} $context */
    private static function buttons(array $context): string
    {
        $css = '';
        foreach (self::STYLES as $style => $selectors) {
            $css .= self::style($style, $selectors, $context);
        }
        return $css;
    }

    /**
     * One style: the normal and the hover rule. What a tone of the style would make unreadable (a custom colour under 4.5:1 with its
     * partner in light or dark) is left out, so the theme's own colour stays.
     *
     * @param string[] $selectors
     * @param array{design: array<string, mixed>, palette: string} $context
     */
    private static function style(string $style, array $selectors, array $context): string
    {
        $d = $context['design'];
        $own = [];
        foreach (self::TONES as $name) {
            $own[$name] = self::tone($d['btn_' . $style . '_' . $name] ?? '');
        }
        $defaults = self::STYLE_DEFAULTS[$style];

        // A custom colour has to read on its partner, in both modes: normal text on its background, and again on hover.
        $normal = self::settle($own['bg'], $own['text'], ['role' => $defaults[0]], ['role' => $defaults[1]], $context);
        [$bg, $text] = $normal;
        $border = $own['border'];
        $hoverBg = $own['hover_bg'];
        $hoverText = $own['hover_text'];
        // The partner of the hover colours is the normal state's (or the theme's) other colour.
        $hoverPair = self::settle($hoverBg, $hoverText, $bg ?? ['role' => $defaults[0]], $text ?? ['role' => $defaults[1]], $context);
        [$hoverBg, $hoverText] = $hoverPair;
        $hoverBorder = $own['hover_border'];

        // A background of the person's own without a text colour: the text that reads best on it in each mode (the accent keeps its own).
        $auto = null;
        if ($bg !== null && $text === null && ($bg['role'] ?? '') !== 'accent') {
            $auto = [];
            foreach (['light', 'dark'] as $mode) {
                $back = self::hex($bg, $mode, $context);
                $auto[$mode] = $back === null ? null : self::contrastOn($back);
            }
            if ($auto['light'] === null) {
                $auto = null;
                $text = ['role' => 'text'];
            }
        }
        $decl = '';
        if ($bg !== null) {
            $decl .= '--btn-bg:' . self::value($bg) . ';';
        }
        if ($auto !== null) {
            $decl .= '--btn-text:' . $auto['light'] . ';';
        } elseif ($text !== null) {
            $decl .= '--btn-text:' . self::value($text) . ';';
        }
        // The Primary button's border follows its background unless it has a colour of its own.
        if ($border !== null) {
            $decl .= '--btn-border:' . self::value($border) . ';';
        } elseif ($bg !== null && $style === 'primary') {
            $decl .= '--btn-border:' . self::value($bg) . ';';
        }
        $width = (string)($d['btn_' . $style . '_border_width'] ?? '');
        if (in_array($width, self::WIDTHS, true)) {
            $decl .= 'border-width:' . $width . 'px;';
        }
        $radius = self::RADII[(string)($d['btn_' . $style . '_radius'] ?? '')] ?? null;
        if ($radius !== null) {
            $decl .= 'border-radius:' . $radius . ';';
        }

        $hover = '';
        $hoverBgValue = null;
        if ($hoverBg !== null) {
            $hoverBgValue = self::value($hoverBg);
        } elseif ($bg !== null) {
            // Worked out from the normal background: toward the text colour (darker in light mode, lighter in dark mode).
            $hoverBgValue = ($bg['role'] ?? '') === 'accent' && $style === 'primary'
                ? 'var(--accent-hover)'
                : (($bg['role'] ?? '') === 'none' ? 'color-mix(in srgb,var(--color-text) 8%,transparent)' : 'color-mix(in srgb,' . self::value($bg) . ' 86%,var(--color-text))');
        }
        if ($hoverBgValue !== null) {
            $hover .= '--btn-bg:' . $hoverBgValue . ';';
        }
        if ($hoverText !== null) {
            $hover .= '--btn-text:' . self::value($hoverText) . ';';
        }
        if ($hoverBorder !== null) {
            $hover .= '--btn-border:' . self::value($hoverBorder) . ';';
        } elseif ($border !== null) {
            $hover .= '--btn-border:' . self::value($border) . ';';
        } elseif ($style === 'primary' && $hoverBgValue !== null) {
            $hover .= '--btn-border:' . $hoverBgValue . ';';
        }

        $css = '';
        if ($decl !== '') {
            $css .= self::rule($selectors, '', $decl);
        }
        if ($auto !== null && $auto['dark'] !== $auto['light']) {
            // The same background reads better with the other text colour in dark mode; the specimen's panels follow their own mode.
            $dark = '--btn-text:' . $auto['dark'] . ';';
            $light = '--btn-text:' . $auto['light'] . ';';
            $css .= self::modeRule($selectors, ':root.dark[data-theme][data-mode]', $dark, '.mode-dark');
            $css .= '@media(prefers-color-scheme:dark){' . self::modeRule($selectors, ':root[data-mode="system"]:not(.light)[data-theme]', $dark, '.mode-dark') . '}';
            $css .= self::modeRule($selectors, self::ROOT, $light, '.mode-light', true);
        }
        if ($hover !== '') {
            $css .= self::rule($selectors, ':hover', $hover, '[data-state="hover"]');
        }
        return $css;
    }

    /**
     * Keeps the custom colours of a bg/text pair only while the pair reads at 4.5:1 in light and dark. The partner of a colour that is
     * not set is the theme's colour of the style. Returns [bg, text], a dropped or unset colour as null.
     *
     * @param array{role?: string, hex?: string}|null $bg
     * @param array{role?: string, hex?: string}|null $text
     * @param array{role?: string, hex?: string} $partnerBg
     * @param array{role?: string, hex?: string} $partnerText
     * @param array{design: array<string, mixed>, palette: string} $context
     * @return array{0: array<string, string>|null, 1: array<string, string>|null}
     */
    private static function settle(?array $bg, ?array $text, array $partnerBg, array $partnerText, array $context): array
    {
        if (($bg === null || !isset($bg['hex'])) && ($text === null || !isset($text['hex']))) {
            return [$bg, $text];
        }
        // A background without a text colour gets the white or near-black that reads best on it (see style()), so the pair always passes.
        if ($text === null && $bg !== null) {
            return [$bg, $text];
        }
        $b = $bg ?? $partnerBg;
        $t = $text ?? $partnerText;
        foreach (['light', 'dark'] as $mode) {
            $page = (string)self::hex(['role' => 'background'], $mode, $context);
            $back = self::hex($b, $mode, $context) ?? $page;
            $front = self::hex($t, $mode, $context) ?? $page;
            if (self::ratio($back, $front) < self::AA) {
                return [isset($bg['hex']) ? null : $bg, isset($text['hex']) ? null : $text];
            }
        }
        return [$bg, $text];
    }

    /**
     * The rule for every selector of a style, with the state ($state: ':hover') and its forced twin for the specimen ($forced).
     *
     * @param string[] $selectors
     */
    private static function rule(array $selectors, string $state, string $declarations, string $forced = ''): string
    {
        $list = [];
        foreach ($selectors as $selector) {
            $list[] = self::ROOT . ' ' . $selector . $state;
            if ($forced !== '') {
                $list[] = self::ROOT . ' ' . $selector . $forced;
            }
        }
        return implode(',', $list) . '{' . $declarations . '}';
    }

    /**
     * A rule for the selectors of a style that holds in one colour mode: under $root, and (for the specimen) inside $panel. The light
     * panel's rule is written after the dark ones with the panel's class first, so a light panel in a dark page keeps its colours.
     *
     * @param string[] $selectors
     */
    private static function modeRule(array $selectors, string $root, string $declarations, string $panel, bool $panelOnly = false): string
    {
        $list = [];
        foreach ($selectors as $selector) {
            if (!$panelOnly) {
                $list[] = $root . ' ' . $selector;
            }
            $list[] = self::ROOT . ' ' . $panel . ' ' . $selector;
        }
        return implode(',', $list) . '{' . $declarations . '}';
    }

    // ---- colour arithmetic

    private static function hexSetting(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? FieldSchema::normalizeHex($value) : null;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [(int)hexdec(substr($hex, 1, 2)), (int)hexdec(substr($hex, 3, 2)), (int)hexdec(substr($hex, 5, 2))];
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

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

    /** White or the theme's near-black, whichever reads better on $background (as Branding picks the text on an accent). */
    private static function contrastOn(string $background): string
    {
        return self::ratio($background, '#ffffff') >= self::ratio($background, '#0b1220') ? '#ffffff' : '#0b1220';
    }
}
