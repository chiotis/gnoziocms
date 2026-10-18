<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The look of the search page (Admin > Theme > Archive Layouts, the Search card): the title area above the search box, as
 * a list has (a layout, a picture, parallax) and its own title and subtitle, which may differ for each language. Kept in the
 * theme settings as `search_page`; only what differs from the defaults is stored. Without any of it the page is the plain
 * title band with the theme's words ("Search", the subtitle), as before.
 */
final class SearchPage
{
    private static ?array $schema = null;

    /** @return array<string, array<string, mixed>> */
    public static function schema(): array
    {
        return self::$schema ??= FieldSchema::normalize([
            'title_layout' => ['type' => 'select', 'label' => 'Title area', 'default' => 'default', 'options' => ContentTypes::TITLE_LAYOUTS],
            'title_image' => ['type' => 'image', 'label' => 'Picture of the title area', 'default' => ''],
            'title_parallax' => ['type' => 'toggle', 'label' => 'Parallax', 'default' => false],
            'title' => ['type' => 'text', 'label' => 'Title', 'default' => '', 'translatable' => true],
            'subtitle' => ['type' => 'text', 'label' => 'Subtitle', 'default' => '', 'translatable' => true],
        ]);
    }

    /**
     * What the page has now: every value, the defaults where nothing is stored.
     *
     * @param array<string, mixed> $themeSettings
     * @return array<string, mixed>
     */
    public static function values(array $themeSettings): array
    {
        $stored = is_array($themeSettings['search_page'] ?? null) ? $themeSettings['search_page'] : [];
        return FieldSchema::resolve(self::schema(), array_intersect_key($stored, self::schema()));
    }

    /**
     * What to store after the card was submitted: the values that differ from the defaults (empty when none does).
     *
     * @param array<string, mixed> $input the submitted `archive_search[...]`
     * @param array<string, mixed> $themeSettings the settings now
     * @return array<string, mixed>
     */
    public static function fromInput(array $input, array $themeSettings): array
    {
        $values = FieldSchema::fromInput(self::schema(), $input, self::values($themeSettings));
        $keep = [];
        foreach (self::schema() as $key => $field) {
            if (($values[$key] ?? null) !== $field['default']) {
                $keep[$key] = $values[$key];
            }
        }
        return $keep;
    }
}
