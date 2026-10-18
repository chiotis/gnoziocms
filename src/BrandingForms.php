<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The form styling of Theme > Branding: field background, border colour and width, label size and weight, and the look of focus
 * and errors. It reaches the public forms (PublicForms, the form block) and the search field.
 *
 * Every choice is a preset made of the theme's own colour roles, so the contrast follows the palette: the focus presets always
 * draw a ring of at least 2px (the default ring, an accent outline, or an outline in the text colour), and errors never rely on
 * colour alone (the strong preset thickens the border, the tinted one adds a bar and a heavier message).
 */
final class BrandingForms
{
    private const ROOT = ':root[data-theme][data-mode]';

    /** The fields of the public forms and the search form. */
    private const FIELD = '.form-grid input:not([type="checkbox"],[type="radio"]),.form-grid textarea,.form-grid select,.search-form input,.header-search input[type="search"]';

    private const BACKGROUND = ['background' => 'var(--color-bg)', 'surface' => 'var(--color-surface)', 'surface_2' => 'var(--color-surface-2)'];
    private const BORDER = ['strong' => 'var(--color-border-strong)', 'muted' => 'var(--color-muted)', 'text' => 'var(--color-text)', 'accent' => 'var(--accent)'];
    private const WIDTH = ['thin' => '1px', 'medium' => '2px'];
    private const LABEL_SIZE = ['small' => '0.85rem', 'normal' => '0.95rem', 'large' => '1.05rem'];

    /**
     * @param array<string, mixed> $design the resolved `design` settings
     * @return string the rules to add after the theme's style sheet, or ''
     */
    public static function css(array $design): string
    {
        $css = '';
        $field = [];
        $background = (string)($design['input_bg'] ?? '');
        if (isset(self::BACKGROUND[$background])) {
            $field[] = 'background:' . self::BACKGROUND[$background];
        }
        $border = (string)($design['input_border'] ?? '');
        if (isset(self::BORDER[$border])) {
            $field[] = 'border-color:' . self::BORDER[$border];
        }
        $width = (string)($design['input_border_width'] ?? '');
        if (isset(self::WIDTH[$width])) {
            $field[] = 'border-width:' . self::WIDTH[$width];
        }
        $radius = $design['radius_input'] ?? '';
        if (is_numeric($radius) && $radius !== '') {
            $field[] = 'border-radius:' . BrandingShape::rem((float)$radius);
        }
        if ($field !== []) {
            $css .= self::scope(self::FIELD) . '{' . implode(';', $field) . '}';
        }

        $label = [];
        $size = (string)($design['label_size'] ?? '');
        if (isset(self::LABEL_SIZE[$size])) {
            $label[] = 'font-size:' . self::LABEL_SIZE[$size];
        }
        $weight = (string)($design['label_weight'] ?? '');
        if (in_array($weight, ['400', '500', '600', '700'], true)) {
            $label[] = 'font-weight:' . $weight;
        }
        if ($label !== []) {
            $css .= self::ROOT . ' .form-grid label>span:first-child,' . self::ROOT . ' .form-grid legend{' . implode(';', $label) . '}';
        }

        // Focus: the default is the theme's ring. An outline is a second, unmistakable ring that does not depend on a soft shadow.
        $focus = (string)($design['input_ring'] ?? '');
        if ($focus === 'outline' || $focus === 'high') {
            $colour = $focus === 'high' ? 'var(--color-text)' : 'var(--accent)';
            $css .= self::scope(self::FIELD, ':focus')
                . '{border-color:' . $colour . ';outline:' . ($focus === 'high' ? '3px' : '2px') . ' solid ' . $colour . ';outline-offset:2px;box-shadow:none}';
        }

        $error = (string)($design['error_style'] ?? '');
        if ($error === 'strong') {
            $css .= self::ROOT . ' .form-grid [aria-invalid="true"]{border-width:2px}'
                . self::ROOT . ' .form-grid .form-error,' . self::ROOT . ' small.form-error{font-weight:700}';
        } elseif ($error === 'tinted') {
            $css .= self::ROOT . ' .form-grid [aria-invalid="true"]{background:color-mix(in srgb,var(--color-danger) 8%,var(--color-bg))}'
                . self::ROOT . ' .form-grid .form-error,' . self::ROOT . ' small.form-error{font-weight:600;padding-left:0.5rem;border-left:3px solid var(--color-danger)}';
        }
        return $css;
    }

    /** The selectors of a list, each under the root rule, with an optional state after each. */
    private static function scope(string $list, string $state = ''): string
    {
        $parts = [];
        foreach (self::split($list) as $selector) {
            $parts[] = self::ROOT . ' ' . $selector . $state;
        }
        return implode(',', $parts);
    }

    /** @return string[] the selectors of a list (commas inside brackets or parentheses do not split) */
    private static function split(string $list): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($list) as $char) {
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;
        return $parts;
    }
}
