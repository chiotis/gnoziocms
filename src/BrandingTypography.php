<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The type overrides of Theme > Typography: a step of the type scale, a weight, a line height, a letter spacing and a case for H1 to
 * H6, the lead paragraph, small text, the eyebrow, meta, quotes, captions and card titles, plus the weight of the text and the space
 * between paragraphs.
 *
 * A size is always a STEP of the theme's scale (`var(--step-5)`), never pixels, so the fluid sizing and the Text size and Heading
 * scale choices still apply. A choice that is empty or "auto" adds nothing, so a
 * site that sets none of them gets no CSS from here.
 */
final class BrandingTypography
{
    /** What each override styles. The selectors start with the root rule so they win over the classes of blocks and the prose. */
    public const TARGETS = [
        'h1' => ['h1', '.hero-title', '.place-title'],
        'h2' => ['h2', '.section-title', '.cta-title', '.book-title'],
        'h3' => ['h3'],
        'lead' => ['.lead', '.single-hero-subtitle', '.prose.is-lead', '.book-lead', '.place-lead'],
        'small' => ['small', 'figcaption'],
        'h4' => ['h4'],
        'h5' => ['h5'],
        'h6' => ['h6'],
        'eyebrow' => ['.eyebrow'],
        'meta' => ['.content-meta', '.single-hero-meta', '.latest-meta'],
        'quote' => ['.quote-text p', '.prose blockquote', '.prose-block blockquote'],
        // After small, so a caption size wins over small text where both are set.
        'caption' => ['figcaption', '.image-block-caption', '.video-caption', '.ba-caption'],
        // The titles of cards in lists, not the large lead story of Featured and Magazine.
        'card_title' => ['.content-item-title', '.latest-item:not(.is-lead) .latest-title', '.place-card-title'],
    ];

    public const STEPS = ['5', '4', '3', '2', '1', '0', '-1', '-2'];
    private const WEIGHTS = ['300', '400', '500', '600', '700', '800'];
    private const LEADING = ['tight' => '1.05', 'normal' => '1.2', 'relaxed' => '1.35', 'loose' => '1.6'];
    private const TRACKING = ['tighter' => '-0.045em', 'tight' => '-0.028em', 'normal' => '0', 'wide' => '0.025em'];
    private const CASE = ['none' => 'text-transform:none;font-variant-caps:normal', 'uppercase' => 'text-transform:uppercase', 'capitalize' => 'text-transform:capitalize', 'small_caps' => 'text-transform:none;font-variant-caps:all-small-caps'];
    private const PARAGRAPH = ['tight' => '0.6em', 'normal' => '1em', 'relaxed' => '1.4em', 'loose' => '1.8em'];

    private const ROOT = ':root[data-theme][data-mode]';

    /**
     * The text of menus and other navigation (Theme > Typography > Navigation). Each is written as variables the theme reads with
     * its own value as the fallback (`--type-nav-size` and so on), so a theme that does not use one simply ignores it. The size is
     * in pixels, written as rem so the visitor's zoom still applies. The site name takes its size from `brand.name_size`.
     */
    public const NAV = [
        'nav' => 'Header menu',
        'dropdown' => 'Dropdown',
        'drawer' => 'Mobile menu',
        'topbar' => 'Top bar',
        'footer_nav' => 'Footer menu',
        'footer_title' => 'Footer headings',
        'crumbs' => 'Breadcrumbs',
        'name' => 'Site name',
    ];
    private const NAV_SIZE = [10, 32];
    private const NAV_WEIGHTS = ['300', '400', '500', '600', '700', '800'];
    private const NAV_CASE = ['none' => 'none', 'uppercase' => 'uppercase', 'capitalize' => 'capitalize'];
    private const NAV_TRACKING = ['tight' => '-0.02em', 'normal' => '0', 'wide' => '0.04em', 'wider' => '0.08em'];

    /**
     * @param array<string, mixed> $design the resolved `design` settings
     * @return string the rules to add after the theme's style sheet, or ''
     */
    public static function css(array $design): string
    {
        $css = '';
        foreach (self::TARGETS as $key => $selectors) {
            $declarations = [];
            $step = (string)($design[$key . '_step'] ?? '');
            if ($step !== '' && in_array($step, self::STEPS, true)) {
                $declarations[] = 'font-size:var(--step-' . $step . ')';
            }
            $weight = (string)($design[$key . '_weight'] ?? '');
            if (in_array($weight, self::WEIGHTS, true)) {
                $declarations[] = 'font-weight:' . $weight;
            }
            $leading = (string)($design[$key . '_leading'] ?? '');
            if (isset(self::LEADING[$leading])) {
                $declarations[] = 'line-height:' . self::LEADING[$leading];
            }
            $tracking = (string)($design[$key . '_tracking'] ?? '');
            if (isset(self::TRACKING[$tracking])) {
                $declarations[] = 'letter-spacing:' . self::TRACKING[$tracking];
            }
            $case = (string)($design[$key . '_case'] ?? '');
            if (isset(self::CASE[$case])) {
                $declarations[] = self::CASE[$case];
            }
            if ($declarations !== []) {
                $css .= implode(',', array_map(static fn(string $selector): string => self::ROOT . ' ' . $selector, $selectors)) . '{' . implode(';', $declarations) . '}';
            }
        }
        $body = (string)($design['body_weight'] ?? '');
        if (in_array($body, ['300', '400', '500'], true)) {
            $css .= 'body.theme-body{font-weight:' . $body . '}';
        }
        $space = (string)($design['paragraph_space'] ?? '');
        if (isset(self::PARAGRAPH[$space])) {
            $css .= self::ROOT . ' :is(.prose,.prose-block) p:not(:last-child){margin-bottom:' . self::PARAGRAPH[$space] . '}';
        }
        return $css . self::navigation($design);
    }

    /** The variables of the Navigation rows, on the root rule; '' when none is set. */
    private static function navigation(array $design): string
    {
        $vars = [];
        foreach (array_keys(self::NAV) as $key) {
            $slug = '--type-' . str_replace('_', '-', $key);
            $size = $design[$key . '_size'] ?? '';
            if ($key !== 'name' && is_numeric($size) && (float)$size >= self::NAV_SIZE[0] && (float)$size <= self::NAV_SIZE[1]) {
                $vars[] = $slug . '-size:' . rtrim(rtrim(number_format((float)$size / 16, 4, '.', ''), '0'), '.') . 'rem';
            }
            $weight = (string)($design[$key . '_weight'] ?? '');
            if (in_array($weight, self::NAV_WEIGHTS, true)) {
                $vars[] = $slug . '-weight:' . $weight;
            }
            $case = (string)($design[$key . '_case'] ?? '');
            if (isset(self::NAV_CASE[$case])) {
                $vars[] = $slug . '-case:' . self::NAV_CASE[$case];
            }
            $tracking = (string)($design[$key . '_tracking'] ?? '');
            if (isset(self::NAV_TRACKING[$tracking])) {
                $vars[] = $slug . '-tracking:' . self::NAV_TRACKING[$tracking];
            }
        }
        return $vars === [] ? '' : self::ROOT . '{' . implode(';', $vars) . '}';
    }
}
