<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Colours in OKLCH (lightness, chroma, hue), where equal steps look equal. Used to derive tints and shades that stay
 * perceptually even; the results are always #rrggbb inside the sRGB gamut (too much chroma is reduced, never clipped by channel).
 */
final class Oklch
{
    /** @return array{0: float, 1: float, 2: float} lightness 0 to 1, chroma about 0 to 0.4, hue in degrees */
    public static function fromHex(string $hex): array
    {
        [$r, $g, $b] = array_map(static function (int $v): float {
            $c = $v / 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, Contrast::rgb($hex));
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);
        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
        $chroma = sqrt($a * $a + $bb * $bb);
        $hue = $chroma < 1.0e-4 ? 0.0 : fmod(rad2deg(atan2($bb, $a)) + 360.0, 360.0);
        return [$lightness, $chroma < 1.0e-4 ? 0.0 : $chroma, $hue];
    }

    /** The colour as #rrggbb; when it is outside sRGB its chroma is lowered until it fits. */
    public static function toHex(float $lightness, float $chroma, float $hue): string
    {
        $lightness = max(0.0, min(1.0, $lightness));
        $chroma = max(0.0, $chroma);
        $rgb = self::linear($lightness, $chroma, $hue);
        if (!self::inGamut($rgb)) {
            $low = 0.0;
            $high = $chroma;
            for ($i = 0; $i < 24; $i++) {
                $mid = ($low + $high) / 2;
                if (self::inGamut(self::linear($lightness, $mid, $hue))) {
                    $low = $mid;
                } else {
                    $high = $mid;
                }
            }
            $rgb = self::linear($lightness, $low, $hue);
        }
        $hex = '#';
        foreach ($rgb as $c) {
            $c = max(0.0, min(1.0, $c));
            $v = $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
            $hex .= str_pad(dechex((int)round(max(0.0, min(1.0, $v)) * 255)), 2, '0', STR_PAD_LEFT);
        }
        return $hex;
    }

    /** @return array{0: float, 1: float, 2: float} linear sRGB */
    private static function linear(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $c) {
            if ($c < -0.0005 || $c > 1.0005) {
                return false;
            }
        }
        return true;
    }
}
