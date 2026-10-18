<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The two requests the palette studio of Theme > Branding makes (as JSON): a palette made from two colours, and the list of
 * Duo colour pairings. Neither stores anything; the palette is saved with the rest of the Theme form.
 */
final class PaletteAdmin
{
    /** The theme settings' fields for the Duo picker: on by default; an empty address means Duo's own. */
    public static function duoEnabled(array $settings): bool
    {
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        return ($design['duo_picker'] ?? true) !== false;
    }

    /**
     * @param array<string, mixed> $input the request's own values (accent, base, base_role, secondary)
     * @return array<string, mixed>
     */
    public static function generate(array $input): array
    {
        $role = ($input['base_role'] ?? '') === 'ink' ? 'ink' : 'background';
        $palette = PaletteGenerator::generate((string)($input['accent'] ?? ''), (string)($input['base'] ?? ''), $role, (string)($input['secondary'] ?? ''));
        if ($palette === null) {
            return ['ok' => false, 'message' => 'Both colours must be hex codes such as #1d4ed8.'];
        }
        $palette['ok'] = true;
        $palette['passes'] = PaletteGenerator::passes($palette);
        return $palette;
    }

    /**
     * A copy of a ready-made palette as a palette of the site ("Teal (custom)"). Nothing is stored: the palette is saved with the
     * rest of the Theme form.
     *
     * @param array<string, mixed> $input the request's own values (builtin: the id of the ready-made palette; taken: ids already used)
     * @return array<string, mixed>
     */
    public static function copy(array $input): array
    {
        $taken = array_map(static fn($id): array => ['id' => (string)$id], is_array($input['taken'] ?? null) ? $input['taken'] : []);
        $palette = SitePalettes::copyOfBuiltin(strtolower(trim((string)($input['builtin'] ?? ''))), $taken);
        if ($palette === null) {
            return ['ok' => false, 'message' => 'That ready-made palette is not known.'];
        }
        return ['ok' => true, 'palette' => $palette];
    }

    /**
     * The nearest shade of a colour that reads at AA (4.5:1, or the minimum given: 3 for large text and interface parts) on one or
     * more backgrounds: same hue and chroma, only the OKLCH lightness moves.
     *
     * @param array<string, mixed> $input colour, against (hex codes, a comma separated list or an array), min
     * @return array<string, mixed>
     */
    public static function fix(array $input): array
    {
        $against = $input['against'] ?? [];
        $against = is_array($against) ? $against : explode(',', (string)$against);
        $minimum = (float)($input['min'] ?? 4.5);
        $minimum = $minimum >= 3.0 && $minimum <= 7.0 ? $minimum : 4.5;
        $colour = FieldSchema::normalizeHex((string)($input['colour'] ?? ''));
        $backgrounds = array_values(array_filter(array_map(static fn($hex): ?string => FieldSchema::normalizeHex(trim((string)$hex)), $against)));
        if ($colour === null || $backgrounds === []) {
            return ['ok' => false, 'message' => 'Give a colour and the colour it is read on.'];
        }
        $fixed = Contrast::fix($colour, $backgrounds, $minimum);
        if ($fixed === null) {
            return ['ok' => false, 'message' => 'No shade of this colour reaches ' . $minimum . ':1 on that background.'];
        }
        $worst = static function (string $hex) use ($backgrounds): float {
            return round(min(array_map(static fn(string $background): float => Contrast::ratio($hex, $background), $backgrounds)), 2);
        };
        return ['ok' => true, 'before' => $colour, 'after' => $fixed, 'ratio_before' => $worst($colour), 'ratio_after' => $worst($fixed), 'level' => Contrast::level($worst($fixed))];
    }

    /** @return array<string, mixed> */
    public static function duo(array $settings, DuoPairings $duo): array
    {
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        if (!self::duoEnabled($settings)) {
            return ['ok' => false, 'off' => true, 'message' => ''];
        }
        $pairs = $duo->pairs((string)($design['duo_url'] ?? ''));
        if ($pairs === null) {
            return ['ok' => false, 'message' => 'Colour pairings are not available right now.'];
        }
        return ['ok' => true, 'pairs' => $pairs, 'credit' => DuoPairings::CREDIT, 'credit_url' => DuoPairings::SITE_URL];
    }
}
