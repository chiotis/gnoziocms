<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * A whole palette from two colours: an accent and a base (the background or the ink). Everything is worked out in OKLCH, so
 * the tints and shades of the neutrals step evenly, and the result is the same for the same input.
 *
 * It returns the six colour roles for light and for dark mode (accent, background, surface, text, muted, border), the dark
 * panel colour (ink) and, when a third colour is given, the secondary role. Text and muted text reach WCAG AA (4.5:1) on the
 * background and the surface. An accent (and a secondary) is moved along lightness, keeping its hue and chroma, only as far as
 * it takes to read as text on the background and the surface and to carry the text colour Branding puts on it.
 */
final class PaletteGenerator
{
    public const ROLES = ['accent', 'background', 'surface', 'text', 'muted', 'border'];
    private const AA = 4.5;
    /** The margin the derived muted text keeps over AA. */
    private const MUTED = 4.6;
    private const STEP = 0.004;

    /**
     * @param string $baseRole 'background' (the base is the page colour of the light mode if it is clearly light, of the dark mode if it is clearly dark; a mid-tone only gives its hue) or 'ink' (the text colour)
     * @return array{light: array<string, string>, dark: array<string, string>, ink: string, secondary: array{light: string, dark: string}|null}|null null when a colour is not hex
     */
    public static function generate(string $accent, string $base, string $baseRole = 'background', ?string $secondary = null): ?array
    {
        $accent = FieldSchema::normalizeHex($accent);
        $base = FieldSchema::normalizeHex($base);
        $second = $secondary === null || trim($secondary) === '' ? null : FieldSchema::normalizeHex($secondary);
        if ($accent === null || $base === null || ($secondary !== null && trim($secondary) !== '' && $second === null)) {
            return null;
        }
        $isInk = $baseRole === 'ink';
        [$baseL, $baseC, $hue] = Oklch::fromHex($base);
        $baseIsLight = $baseL >= 0.6;
        // A background is used as it is only when it is clearly light or clearly dark; a mid-tone gives its hue and both pages are derived.
        $useLight = !$isInk && $baseL >= 0.8;
        $useDark = !$isInk && $baseL <= 0.38;
        // Neutrals keep the hue of the base with a little of its chroma; a grey base gives grey neutrals.
        $tint = $baseC < 0.004 ? 0.0 : min($baseC, 0.03);

        // ---- light mode
        $lightBg = $useLight ? $base : Oklch::toHex(0.985, $tint * 0.6, $hue);
        [$lbL, $lbC, $lbH] = Oklch::fromHex($lightBg);
        $lightSurface = Oklch::toHex(max(0.0, $lbL - 0.035), $lbC * 1.4, $lbH);
        $lightBorder = Oklch::toHex(max(0.0, $lbL - 0.095), $lbC * 1.6, $lbH);
        $lightText = $isInk && !$baseIsLight ? $base : Oklch::toHex(0.22, $tint * 0.8, $hue);
        $lightText = self::reach($lightText, [$lightBg, $lightSurface], -1);

        // ---- dark mode
        $darkBg = $useDark ? $base : Oklch::toHex(0.17, min($tint * 0.8, 0.03), $hue);
        [$dbL, $dbC, $dbH] = Oklch::fromHex($darkBg);
        $darkSurface = Oklch::toHex(min(1.0, max($dbL + 0.04, 0.16)), $dbC * 1.1, $dbH);
        $darkBorder = Oklch::toHex(min(1.0, max($dbL + 0.115, 0.27)), $dbC * 1.2, $dbH);
        $darkText = $isInk && $baseIsLight ? $base : Oklch::toHex(0.94, $tint * 0.4, $hue);
        $darkText = self::reach($darkText, [$darkBg, $darkSurface], 1);

        $light = [
            'accent' => self::readable($accent, [$lightBg, $lightSurface], -1),
            'background' => $lightBg,
            'surface' => $lightSurface,
            'text' => $lightText,
            'muted' => self::softer($lightText, [$lightBg, $lightSurface], 1),
            'border' => $lightBorder,
        ];
        $dark = [
            'accent' => self::readable($accent, [$darkBg, $darkSurface], 1),
            'background' => $darkBg,
            'surface' => $darkSurface,
            'text' => $darkText,
            'muted' => self::softer($darkText, [$darkBg, $darkSurface], -1),
            'border' => $darkBorder,
        ];
        [$inkL, $inkC, $inkH] = Oklch::fromHex($darkBg);
        return [
            'light' => $light,
            'dark' => $dark,
            'ink' => Oklch::toHex(min($inkL, 0.19) - 0.03, $inkC, $inkH),
            'secondary' => $second === null ? null : [
                'light' => self::readable($second, [$lightBg, $lightSurface], -1),
                'dark' => self::readable($second, [$darkBg, $darkSurface], 1),
            ],
        ];
    }

    /** True when every text pair of a palette the generator made reads at AA; the check the tests and the screen rely on. */
    public static function passes(array $palette): bool
    {
        foreach (['light', 'dark'] as $mode) {
            $c = $palette[$mode] ?? [];
            foreach (['text', 'muted', 'accent'] as $role) {
                foreach (['background', 'surface'] as $on) {
                    if (Contrast::ratio($c[$role], $c[$on]) < self::AA - 0.005) {
                        return false;
                    }
                }
            }
            if (Contrast::ratio(Contrast::textOn($c['accent']), $c['accent']) < self::AA - 0.005) {
                return false;
            }
        }
        return true;
    }

    /**
     * Moves $colour along lightness (direction -1 darker, 1 lighter) until it reads at AA on every background.
     * Used for text: nothing changes when it already does.
     *
     * @param string[] $backgrounds
     */
    private static function reach(string $colour, array $backgrounds, int $direction): string
    {
        [$l, $c, $h] = Oklch::fromHex($colour);
        $hex = $colour;
        for ($i = 0; $i < 400 && !self::readsOnAll($hex, $backgrounds, self::AA); $i++) {
            $l += $direction * self::STEP;
            $hex = Oklch::toHex($l, $c, $h);
        }
        return $hex;
    }

    /** An accent or secondary: as it is when it reads, otherwise moved until it does and until the text on it does too. */
    private static function readable(string $colour, array $backgrounds, int $direction): string
    {
        [$l, $c, $h] = Oklch::fromHex($colour);
        $hex = $colour;
        for ($i = 0; $i < 400; $i++) {
            if (self::readsOnAll($hex, $backgrounds, self::AA) && Contrast::ratio(Contrast::textOn($hex), $hex) >= self::AA) {
                break;
            }
            $l += $direction * self::STEP;
            $hex = Oklch::toHex($l, $c, $h);
        }
        return $hex;
    }

    /**
     * The softest colour on the line from $text towards the backgrounds that still reads at the muted margin.
     *
     * @param string[] $backgrounds
     */
    private static function softer(string $text, array $backgrounds, int $direction): string
    {
        [$l, $c, $h] = Oklch::fromHex($text);
        $best = $text;
        for ($i = 1; $i <= 200; $i++) {
            $hex = Oklch::toHex($l + $direction * self::STEP * $i, $c, $h);
            if (!self::readsOnAll($hex, $backgrounds, self::MUTED)) {
                break;
            }
            $best = $hex;
        }
        return $best;
    }

    /** @param string[] $backgrounds */
    private static function readsOnAll(string $hex, array $backgrounds, float $minimum): bool
    {
        foreach ($backgrounds as $background) {
            if (Contrast::ratio($hex, $background) < $minimum) {
                return false;
            }
        }
        return true;
    }
}
