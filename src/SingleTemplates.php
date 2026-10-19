<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The templates built with blocks for the page of one entry (Admin > Theme > Single Layouts > Edit template). They are stored in
 * the theme settings as `single_templates.<type>`: `use` says whether the type draws its pages with the template, and `blocks` holds
 * the blocks in the shape of the Footer Blocks (a plain list, or a map of `default` and a set for each language, FooterBlocks).
 * Turning the template off (Reset) leaves the blocks where they are, so it can be switched on again. Nothing here knows a content
 * type: a type the site adds later is stored under its own name the same way.
 */
final class SingleTemplates
{
    /** @param array<string, mixed> $themeSettings @return array{use: bool, blocks: mixed} */
    public static function entry(array $themeSettings, string $type): array
    {
        $stored = is_array($themeSettings['single_templates'] ?? null) ? ($themeSettings['single_templates'][$type] ?? null) : null;
        $stored = is_array($stored) ? $stored : [];
        return ['use' => ($stored['use'] ?? false) === true, 'blocks' => $stored['blocks'] ?? []];
    }

    /** The blocks the entries of a type show in a language (the site's own set when the language has none). @param array<string, mixed> $themeSettings @return array<int, mixed> */
    public static function blocks(array $themeSettings, string $type, string $lang, string $defaultLang): array
    {
        return FooterBlocks::forLanguage(self::entry($themeSettings, $type)['blocks'], $lang, $defaultLang);
    }

    /** Whether the pages of a type are drawn from its template in this language: the template is on and has blocks to draw. @param array<string, mixed> $themeSettings */
    public static function applies(array $themeSettings, string $type, string $lang, string $defaultLang): bool
    {
        return self::entry($themeSettings, $type)['use'] && self::blocks($themeSettings, $type, $lang, $defaultLang) !== [];
    }

    /**
     * The `single_templates` map to store after a change to one type: `$use` switches the template on or off (null leaves it), `$sets`
     * replaces its blocks (null leaves them; an empty set list takes them away). A type left with nothing to keep is dropped.
     *
     * @param array<string, mixed> $themeSettings the settings now
     * @param array<string, array<int, mixed>>|null $sets the sets to keep, by key (FooterBlocks::toStore)
     * @return array<string, array<string, mixed>>
     */
    public static function with(array $themeSettings, string $type, ?bool $use, ?array $sets): array
    {
        $all = is_array($themeSettings['single_templates'] ?? null) ? $themeSettings['single_templates'] : [];
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $type) !== 1) {
            return $all;
        }
        $entry = self::entry($themeSettings, $type);
        $use ??= $entry['use'];
        $blocks = $sets === null ? $entry['blocks'] : FooterBlocks::toStore($sets);
        $blocks = is_array($blocks) ? $blocks : [];
        if ($blocks === [] && !$use) {
            unset($all[$type]);
            return $all;
        }
        $all[$type] = ['use' => $use, 'blocks' => $blocks];
        return $all;
    }

    /**
     * The theme settings after the Single Layouts cards were sent: for each type of the form that exists (a form has no template) its
     * choice, `built` or anything else for the theme's. Types the form did not send are left as they are.
     *
     * @param array<string, mixed> $data the theme settings being stored
     * @param array<string, mixed> $choices `single_template[<type>]` as sent
     * @param string[] $known the content types the site has
     * @param (\Closure(string): array<string, array<int, mixed>>)|null $starting the sets a type starts from (StartingTemplates::sets()): a type
     *        that is switched to `built` while it has no blocks gets them, so it does not start from an empty list
     * @return array<string, mixed>
     */
    public static function withChoices(array $data, array $choices, array $known, ?\Closure $starting = null): array
    {
        foreach ($choices as $type => $choice) {
            if (in_array((string)$type, $known, true) && (string)$type !== 'forms' && is_string($choice)) {
                $entry = self::entry($data, (string)$type);
                $sets = $choice === 'built' && !$entry['use'] && $entry['blocks'] === [] && $starting !== null ? $starting((string)$type) : [];
                $data['single_templates'] = self::with($data, (string)$type, $choice === 'built', $sets === [] ? null : $sets);
            }
        }
        if (($data['single_templates'] ?? []) === []) {
            unset($data['single_templates']);
        }
        return $data;
    }
}
