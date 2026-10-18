<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The shape and depth presets of Theme > Branding: shadows (none, soft, medium, strong), the corners of cards and images (the
 * corners of fields are in BrandingForms), the border of cards, and the margins of a phone.
 *
 * "Theme" for any of them adds nothing. The old shadow choices keep their exact values: "none" and "strong" are the same CSS as
 * before the presets were added, and "soft" is the theme's own shadow written out.
 */
final class BrandingShape
{
    private const ROOT = ':root[data-theme][data-mode]';

    /** Shadow presets: [--shadow-s, --shadow-m]. */
    public const SHADOWS = [
        'none' => ['none', 'none'],
        'soft' => ['0 1px 2px rgb(15 23 42 / 0.06), 0 1px 1px rgb(15 23 42 / 0.04)', '0 22px 44px -28px rgb(15 23 42 / 0.45)'],
        'medium' => ['0 1px 3px rgb(15 23 42 / 0.1), 0 1px 2px rgb(15 23 42 / 0.06)', '0 18px 40px -20px rgb(15 23 42 / 0.5)'],
        'strong' => ['0 2px 6px rgb(15 23 42 / 0.16), 0 1px 2px rgb(15 23 42 / 0.12)', '0 30px 60px -24px rgb(15 23 42 / 0.65)'],
    ];

    /** What counts as a card and what as an image: classes of the theme and its blocks. */
    private const CARDS = [
        '.sidebar-card', '.content-item', '.form-block-card', '.place-card', '.plan-card', '.testimonial', '.route-stats',
        '.block--cards .feature', '.block--cards .stat', '.block--cards .check-item', '.accordion-cards .accordion-item',
        '.block-contact.block--cards .contact-item', '.download-link', '.block-banner.block--callout .banner-box',
        '.block-cta.block--card .cta-inner', '.compare-wrap', '.block--boxed .table-frame', '.geo-item', '.pl-item',
        '.block-latest.block--magazine .latest-item',
    ];
    private const IMAGES = [
        '.prose img', '.prose-block img', '.media-frame', '.single-hero-media', '.tabs-panel-media', '.video-media', '.gallery-frame',
        '.team-photo', '.work-media', '.col-media', '.latest-media', '.image-block.is-rounded .image-block-link', '.quote-photo img',
        '.book-cover', '.type-field dd img', '.slider-track', '.map-frame', '.content-item-media',
    ];

    private const BORDER_WIDTH = ['none' => '0px', 'thin' => '1px', 'medium' => '2px', 'thick' => '3px'];

    /** @return array{0: string, 1: string}|null the two shadow values of a preset, or null for "theme" */
    public static function shadow(string $name): ?array
    {
        return self::SHADOWS[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $design the resolved `design` settings
     * @return string the rules to add after the theme's style sheet, or ''
     */
    public static function css(array $design): string
    {
        $css = '';
        $card = self::px($design['radius_card'] ?? '');
        $width = (string)($design['border_width'] ?? '');
        $declarations = [];
        if ($card !== null) {
            $declarations[] = 'border-radius:' . self::rem($card);
        }
        if (isset(self::BORDER_WIDTH[$width])) {
            $declarations[] = 'border-width:' . self::BORDER_WIDTH[$width];
        }
        if ($declarations !== []) {
            $css .= self::list(self::CARDS) . '{' . implode(';', $declarations) . '}';
        }
        $image = self::px($design['radius_image'] ?? '');
        if ($image !== null) {
            $css .= self::list(self::IMAGES) . '{border-radius:' . self::rem($image) . '}';
        }

        // Phones: a side margin, and the space between sections as the scale the sections already use (the theme's is about 58px on a phone).
        $mobile = [];
        $gutter = self::px($design['mobile_gutter'] ?? '');
        if ($gutter !== null) {
            $mobile[] = '--gutter:' . self::rem($gutter);
        }
        $section = self::px($design['mobile_section_space'] ?? '');
        if ($section !== null) {
            $mobile[] = '--section-scale:' . rtrim(rtrim(number_format($section / 58, 4, '.', ''), '0'), '.');
        }
        if ($mobile !== []) {
            $css .= '@media(max-width:640px){' . self::ROOT . '{' . implode(';', $mobile) . '}}';
        }
        return $css;
    }

    public static function rem(float $px): string
    {
        return $px == 0.0 ? '0px' : rtrim(rtrim(number_format($px / 16, 4, '.', ''), '0'), '.') . 'rem';
    }

    private static function px(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || (is_string($value) && $value !== '' && is_numeric($value))) ? (float)$value : null;
    }

    /** @param string[] $selectors */
    private static function list(array $selectors): string
    {
        return implode(',', array_map(static fn(string $selector): string => self::ROOT . ' ' . $selector, $selectors));
    }
}
