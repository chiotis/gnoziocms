<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the blocks of an archive template built with blocks (scope `archive`) work out from the list they are drawn for, apart from the
 * markup: the title area's layout and picture, and what the filter bar, the count and the list show. BlockRenderer asks for these before
 * it draws the block, so the Twig of a block only lays them out.
 */
final class ArchiveBlocks
{
    /**
     * The layout and picture of the Archive title block: its own choice or, "As set for the list", the list's (Archive Layouts).
     *
     * @param array<string, mixed> $values the block's checked values
     * @param array<string, mixed> $settings the archive settings of the list
     * @return array{layout: string, picture: string, behind: bool}
     */
    public static function title(array $values, array $settings): array
    {
        $choice = (string)($values['variant'] ?? 'auto');
        if ($choice === 'auto') {
            $choice = (string)($settings['title_layout'] ?? 'default');
        }
        if (!in_array($choice, ['default', 'centered', 'split', 'cover', 'minimal'], true)) {
            $choice = 'default';
        }
        $picture = (string)($values['image'] ?? '') !== '' ? (string)$values['image'] : (string)($settings['title_image'] ?? '');
        // A cover needs its picture.
        $layout = $choice === 'cover' && $picture === '' ? 'default' : $choice;
        // The picture is the background of the area in the default and cover layouts: the header can sit over it.
        return ['layout' => $layout, 'picture' => $picture, 'behind' => $picture !== '' && in_array($layout, ['default', 'cover'], true)];
    }

    /**
     * What a block of the list draws, from the archive the page is for (ArchiveBuilder's result).
     *
     * @param array<string, mixed> $values the block's checked values
     * @param array<string, mixed> $archive
     * @return array<string, mixed> the values with what the block shows added
     */
    public static function fill(string $type, array $values, array $archive): array
    {
        $settings = is_array($archive['settings'] ?? null) ? $archive['settings'] : [];
        switch ($type) {
            case 'archive-title':
                $title = self::title($values, $settings);
                return $values + ['_layout' => $title['layout'], '_picture' => $title['picture'], '_behind' => $title['behind']];
            case 'filter-bar':
                $values['filters'] = $archive['filters'] ?? [];
                // Without a filter to offer the bar leaves nothing behind.
                $values['_empty'] = $values['filters'] === [];
                return $values;
            case 'result-count':
                $values['total'] = (int)($archive['total'] ?? 0);
                $values['_empty'] = !($values['always'] ?? false) && !($archive['filtered'] ?? false);
                return $values;
        }
        return $values;
    }
}
