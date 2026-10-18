<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The words the theme prints ("Read more", form messages, and so on) as the Translations screen edits them. The theme
 * ships them and updates replace them, so an edit is stored as an override in `custom/lang/<lang>.yaml`, which updates
 * never touch; an override that says what the theme says is dropped. Menu labels (`nav.*`) are not edited here.
 */
final class ThemeStrings
{
    public function __construct(private Theme $theme)
    {
    }

    /** Keys kept out of the screen: menu labels live in the menu editor. */
    public static function isHidden(string $key): bool
    {
        return str_starts_with($key, 'nav.');
    }

    /**
     * @param array<string|int, mixed> $translations
     * @return array<string, string> the strings that can be edited on the screen
     */
    public static function visible(array $translations): array
    {
        $rows = [];
        foreach ($translations as $key => $value) {
            $key = (string)$key;
            if (self::isHidden($key)) {
                continue;
            }
            $rows[$key] = (string)$value;
        }
        return $rows;
    }

    /**
     * What the screen shows for a language.
     *
     * @return array{translations: array<string, string>, defaults: array<string, string>, customized: string[], custom_file: string}
     */
    public function screen(string $lang, string $defaultLang): array
    {
        $visible = self::visible(array_replace($this->theme->inheritedTranslations($lang, $defaultLang), $this->theme->customTranslations($lang)));
        ksort($visible);
        return [
            'translations' => $visible,
            'defaults' => self::visible($this->theme->themeTranslations($defaultLang)),
            'customized' => array_keys(self::visible($this->theme->customTranslations($lang))),
            'custom_file' => 'custom/lang/' . $lang . '.yaml',
        ];
    }

    /**
     * What the Translations screen draws: every string with the words it is shown with (the key, the source, the value, its state and
     * the area of the site it belongs to), the areas, the counts for the filters, and how far each language of the site is translated.
     * A string is `missing` with no text, `source` when it still says what the source language says, and `translated` otherwise; it is
     * `custom` when the site overrides the theme's text, and `own` when the theme has no such string (the site added it).
     *
     * @param string[] $languages every language of the site
     * @return array{rows: array<int, array{key: string, group: string, source: string, value: string, status: string, custom: bool, own: bool, long: bool}>, groups: array<string, array{label: string, count: int}>, stats: array{total: int, translated: int, missing: int, source: int, custom: int}, progress: array<string, int>, custom_file: string}
     */
    public function overview(string $lang, string $defaultLang, array $languages): array
    {
        $rows = $this->rows($lang, $defaultLang);
        $stats = ['total' => count($rows), 'translated' => 0, 'missing' => 0, 'source' => 0, 'custom' => 0];
        $groups = [];
        foreach ($rows as $row) {
            $stats[$row['status']]++;
            $stats['custom'] += $row['custom'] ? 1 : 0;
            $groups[$row['group']] ??= ['label' => self::groupLabel($row['group']), 'count' => 0];
            $groups[$row['group']]['count']++;
        }
        uksort($groups, static fn(string $a, string $b): int => $a === 'general' ? -1 : ($b === 'general' ? 1 : strcmp($a, $b)));
        $progress = [];
        foreach (array_unique(array_merge($languages, [$lang])) as $code) {
            $all = $code === $lang ? $rows : $this->rows($code, $defaultLang);
            $done = count(array_filter($all, static fn(array $r): bool => $r['status'] === 'translated'));
            $progress[$code] = $all === [] ? 100 : (int)floor($done / count($all) * 100);
        }
        return ['rows' => $rows, 'groups' => $groups, 'stats' => $stats, 'progress' => $progress, 'custom_file' => 'custom/lang/' . $lang . '.yaml'];
    }

    /** The area of the site a key belongs to: the word before its first dot ("form.error.required" is "form"). */
    public static function group(string $key): string
    {
        return str_contains($key, '.') ? (string)strstr($key, '.', true) : 'general';
    }

    public static function groupLabel(string $group): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $group));
    }

    /** @return array<int, array{key: string, group: string, source: string, value: string, status: string, custom: bool, own: bool, long: bool}> */
    private function rows(string $lang, string $defaultLang): array
    {
        $screen = $this->screen($lang, $defaultLang);
        $rows = [];
        foreach ($screen['translations'] as $key => $value) {
            $source = $screen['defaults'][$key] ?? '';
            $status = $value === '' ? 'missing' : ($lang !== $defaultLang && $value === $source ? 'source' : 'translated');
            $rows[] = [
                'key' => (string)$key,
                'group' => self::group((string)$key),
                'source' => $source,
                'value' => $value,
                'status' => $status,
                'custom' => in_array($key, $screen['customized'], true),
                'own' => !array_key_exists($key, $screen['defaults']),
                'long' => mb_strlen($value) > 80 || mb_strlen($source) > 80 || str_contains($value, "\n") || str_contains($source, "\n"),
            ];
        }
        // By area, then by key: the strings of one part of the site are together.
        usort($rows, static fn(array $a, array $b): int => [$a['group'] === 'general' ? '' : $a['group'], $a['key']] <=> [$b['group'] === 'general' ? '' : $b['group'], $b['key']]);
        return $rows;
    }

    /**
     * Saves a submitted form for a language. A string set back to what the theme says, or ticked for reset, stops
     * being an override; a key the form does not mention keeps its override.
     *
     * @param array<string, mixed> $post keys[], values[] (same order), reset[] (keys to give back to the theme)
     * @return array{ok: bool, overrides: int}
     */
    public function save(string $lang, string $defaultLang, array $post): array
    {
        $inherited = $this->theme->inheritedTranslations($lang, $defaultLang);
        $next = $this->theme->customTranslations($lang);
        $keys = is_array($post['keys'] ?? null) ? $post['keys'] : [];
        $values = is_array($post['values'] ?? null) ? $post['values'] : [];
        $reset = array_map('strval', is_array($post['reset'] ?? null) ? $post['reset'] : []);

        foreach ($keys as $index => $key) {
            $key = trim((string)$key);
            if ($key === '' || self::isHidden($key)) {
                continue;
            }
            $value = (string)($values[$index] ?? '');
            if (in_array($key, $reset, true) || (array_key_exists($key, $inherited) && $inherited[$key] === $value)) {
                unset($next[$key]);
                continue;
            }
            $next[$key] = $value;
        }

        return ['ok' => $this->theme->writeCustomTranslations($lang, $next), 'overrides' => count($next)];
    }
}
