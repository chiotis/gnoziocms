<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The type overrides of Theme > Branding: a step of the type scale, a weight, a line height and a letter spacing for H1, H2, H3,
 * the lead paragraph and small text, plus the weight of the text and the space between paragraphs.
 *
 * A size is always a STEP of the theme's scale (`var(--step-5)`), never pixels, so the fluid sizing and the Text size and Heading
 * scale choices still apply. H4 to H6 have no override: they follow the scale. A choice that is empty or "auto" adds nothing, so a
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
    ];

    public const STEPS = ['5', '4', '3', '2', '1', '0', '-1', '-2'];
    private const WEIGHTS = ['300', '400', '500', '600', '700', '800'];
    private const LEADING = ['tight' => '1.05', 'normal' => '1.2', 'relaxed' => '1.35', 'loose' => '1.6'];
    private const TRACKING = ['tighter' => '-0.045em', 'tight' => '-0.028em', 'normal' => '0', 'wide' => '0.025em'];
    private const PARAGRAPH = ['tight' => '0.6em', 'normal' => '1em', 'relaxed' => '1.4em', 'loose' => '1.8em'];

    private const ROOT = ':root[data-theme][data-mode]';

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
        return $css;
    }
}
