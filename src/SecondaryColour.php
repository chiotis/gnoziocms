<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The optional secondary brand colour (`design.light_secondary` and `design.dark_secondary`) as CSS variables. Nothing is
 * produced until one of them is set. The variables a theme or a block uses follow the colour mode, like the accent's:
 *
 *   --color-secondary          --color-secondary-hover       --color-secondary-soft       --color-secondary-contrast
 *
 * and the fixed ones for each mode: --secondary-l, --secondary-l-hover, --secondary-l-soft, --secondary-contrast-l and the -d set.
 * A mode left empty uses the other mode's colour.
 */
final class SecondaryColour
{
    /**
     * @param array<string, mixed> $design
     * @param array<string, string> $vars the variables of the :root rule, added to
     * @param string[] $rules the rules after it, added to
     */
    public static function add(array $design, string $darkBase, array &$vars, array &$rules): void
    {
        $light = self::hex($design['light_secondary'] ?? null);
        $dark = self::hex($design['dark_secondary'] ?? null);
        if ($light === null && $dark === null) {
            return;
        }
        $light ??= $dark;
        $dark ??= $light;
        $set = [
            'l' => [$light, Contrast::mix($light, '#000000', 0.18), Contrast::mix($light, '#ffffff', 0.9)],
            'd' => [$dark, Contrast::mix($dark, '#ffffff', 0.3), Contrast::mix($dark, $darkBase, 0.86)],
        ];
        foreach ($set as $mode => [$colour, $hover, $soft]) {
            $vars['--secondary-' . $mode] = $colour;
            $vars['--secondary-' . $mode . '-hover'] = $hover;
            $vars['--secondary-' . $mode . '-soft'] = $soft;
            $vars['--secondary-contrast-' . $mode] = Contrast::textOn($colour);
        }
        $follow = static function (string $mode): string {
            $out = '';
            foreach (['' => '', '-hover' => '-hover', '-soft' => '-soft'] as $name => $suffix) {
                $out .= '--color-secondary' . $name . ':var(--secondary-' . $mode . $suffix . ');';
            }
            return $out . '--color-secondary-contrast:var(--secondary-contrast-' . $mode . ');';
        };
        $vars['--color-secondary'] = 'var(--secondary-l)';
        $vars['--color-secondary-hover'] = 'var(--secondary-l-hover)';
        $vars['--color-secondary-soft'] = 'var(--secondary-l-soft)';
        $vars['--color-secondary-contrast'] = 'var(--secondary-contrast-l)';
        $rules[] = ':root.dark[data-theme][data-mode]{' . $follow('d') . '}';
        $rules[] = '@media(prefers-color-scheme:dark){:root[data-mode="system"]:not(.light)[data-theme]{' . $follow('d') . '}}';
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? FieldSchema::normalizeHex($value) : null;
    }
}
