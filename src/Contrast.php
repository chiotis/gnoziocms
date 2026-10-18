<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * WCAG 2 contrast: the relative luminance of a colour, the ratio between two colours, the level a ratio reaches, and the
 * text colour (white or the theme's near-black) that reads best on a background. Colours are #rrggbb.
 */
final class Contrast
{
    public const WHITE = '#ffffff';
    /** The theme's near-black, the dark text colour of an accent (see Branding). */
    public const DARK = '#0b1220';

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = FieldSchema::normalizeHex($hex) ?? '#000000';
        return [(int)hexdec(substr($hex, 1, 2)), (int)hexdec(substr($hex, 3, 2)), (int)hexdec(substr($hex, 5, 2))];
    }

    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** The contrast ratio of two colours, 1 to 21. */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** 'AAA', 'AA', 'AA large' (3 to 4.5: large text and interface parts only) or 'fail'. */
    public static function level(float $ratio): string
    {
        $ratio = round($ratio, 2);
        return $ratio >= 7.0 ? 'AAA' : ($ratio >= 4.5 ? 'AA' : ($ratio >= 3.0 ? 'AA large' : 'fail'));
    }

    /** @return array{ratio: float, level: string} */
    public static function report(string $foreground, string $background): array
    {
        $ratio = round(self::ratio($foreground, $background), 2);
        return ['ratio' => $ratio, 'level' => self::level($ratio)];
    }

    /** White or the theme's near-black, whichever reads better on $background (the choice the accent gets in Branding). */
    public static function textOn(string $background): string
    {
        return self::ratio(self::WHITE, $background) >= self::ratio(self::DARK, $background) ? self::WHITE : self::DARK;
    }

    /** $a moved $amount (0 to 1) of the way to $b, as #rrggbb. */
    public static function mix(string $a, string $b, float $amount): string
    {
        $from = self::rgb($a);
        $to = self::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= str_pad(dechex((int)round($from[$i] + ($to[$i] - $from[$i]) * $amount)), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /**
     * The nearest shade of $colour that reaches $minimum against every background, found along OKLCH lightness with the hue and
     * the chroma kept (a colour that does not fit sRGB at that lightness loses chroma, as everywhere else). The colour itself
     * when it already does; null when no lightness can (a background that is too much in the middle for the minimum).
     *
     * @param string[] $backgrounds
     */
    public static function fix(string $colour, array $backgrounds, float $minimum = 4.5): ?string
    {
        $colour = FieldSchema::normalizeHex($colour);
        $backgrounds = array_values(array_filter(array_map(static fn(string $hex): ?string => FieldSchema::normalizeHex($hex), $backgrounds)));
        if ($colour === null || $backgrounds === []) {
            return null;
        }
        $reaches = static function (string $hex) use ($backgrounds, $minimum): bool {
            foreach ($backgrounds as $background) {
                if (self::ratio($hex, $background) < $minimum + 0.005) {
                    return false;
                }
            }
            return true;
        };
        if ($reaches($colour)) {
            return $colour;
        }
        [$l, $c, $h] = Oklch::fromHex($colour);
        for ($i = 1; $i <= 500; $i++) {
            $step = $i * 0.002;
            // Nearest first: the darker and the lighter shade at the same distance, the one that is closer in contrast first.
            $candidates = [Oklch::toHex($l - $step, $c, $h), Oklch::toHex($l + $step, $c, $h)];
            $passing = array_values(array_filter($candidates, $reaches));
            if ($passing !== []) {
                usort($passing, static fn(string $a, string $b): int => self::ratio($a, $backgrounds[0]) <=> self::ratio($b, $backgrounds[0]));
                return $passing[0];
            }
        }
        return null;
    }
}
