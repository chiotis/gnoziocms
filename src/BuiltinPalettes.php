<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The colours of the seven ready-made palettes of the default theme (the rules of `:root[data-theme="…"]` in its style sheet),
 * so Theme > Branding can show them read-only and copy one into a palette of the site. Only the roles a palette sets are here;
 * the others are the theme's neutrals. A test keeps this list equal to the style sheet.
 */
final class BuiltinPalettes
{
    private const NEUTRALS = [
        'light' => ['background' => '#ffffff', 'surface' => '#f5f6f8', 'text' => '#0e1726', 'muted' => '#4a5568', 'border' => '#e2e6ec'],
        'dark' => ['background' => '#0b1220', 'surface' => '#111a2c', 'text' => '#e8edf4', 'muted' => '#a6b3c5', 'border' => '#223049'],
        'ink' => '#0b1220',
    ];

    /** id => [name, light accent, dark accent] */
    private const ACCENTS = [
        'slate' => ['Slate', '#1e293b', '#e2e8f0'],
        'indigo' => ['Indigo', '#4338ca', '#a5b4fc'],
        'emerald' => ['Emerald', '#047857', '#6ee7b7'],
        'teal' => ['Teal', '#0f766e', '#5eead4'],
        'rose' => ['Rose', '#be123c', '#fda4af'],
        'amber' => ['Amber', '#b45309', '#fcd34d'],
        'garnet' => ['Garnet', '#8f1d1d', '#f4a8a0'],
    ];

    /** Garnet's own neutrals (warm paper, warm charcoal). */
    private const GARNET = [
        'light' => ['background' => '#fffdf8', 'surface' => '#f8f1e4', 'text' => '#261b14', 'muted' => '#5b4a3d', 'border' => '#eadfca'],
        'dark' => ['background' => '#232120', 'surface' => '#2c2a28', 'text' => '#f4efea', 'muted' => '#c6bdb5', 'border' => '#433f3b'],
        'ink' => '#323130',
    ];

    /** @return array{id: string, name: string, light: array<string, string>, dark: array<string, string>, ink: string}|null */
    public static function get(string $id): ?array
    {
        if (!isset(self::ACCENTS[$id])) {
            return null;
        }
        [$name, $light, $dark] = self::ACCENTS[$id];
        $base = $id === 'garnet' ? self::GARNET : self::NEUTRALS;
        return [
            'id' => $id,
            'name' => $name,
            'light' => ['accent' => $light] + $base['light'],
            'dark' => ['accent' => $dark] + $base['dark'],
            'ink' => $base['ink'],
        ];
    }

    /** The display name of a ready-made palette ("Teal"), or the id with a capital for a palette of another theme. */
    public static function name(string $id): string
    {
        return self::ACCENTS[$id][0] ?? ucfirst($id);
    }

    /** @return array<string, array<string, mixed>> every ready-made palette by id */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::ACCENTS) as $id) {
            $out[$id] = self::get($id);
        }
        return $out;
    }
}
