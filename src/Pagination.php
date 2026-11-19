<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Which page numbers a list of pages shows. Up to eight pages are all shown. With more, the first and the last page, the
 * current one and two on each side of it; what lies between is a gap the theme draws as an ellipsis. A gap that would hide
 * a single page shows that page instead. Used by archives, the Entry list block and search.
 */
final class Pagination
{
    /** Up to this many pages, every number is shown. */
    public const SHOW_ALL = 8;
    /** Pages shown on each side of the current one. */
    public const AROUND = 2;

    /**
     * @return array<int, array{number: int, current: bool, gap_before: bool}> in order; `gap_before` marks a hidden run before it
     */
    public static function numbers(int $page, int $pages): array
    {
        if ($pages < 2) {
            return [];
        }
        $page = max(1, min($pages, $page));
        if ($pages <= self::SHOW_ALL) {
            $numbers = range(1, $pages);
        } else {
            $numbers = array_values(array_unique(array_merge(
                [1],
                range(max(1, $page - self::AROUND), min($pages, $page + self::AROUND)),
                [$pages]
            )));
            sort($numbers);
        }
        $out = [];
        $previous = 0;
        foreach ($numbers as $n) {
            if ($previous > 0 && $n - $previous === 2) {
                $out[] = ['number' => $previous + 1, 'current' => $previous + 1 === $page, 'gap_before' => false];
            }
            $out[] = ['number' => $n, 'current' => $n === $page, 'gap_before' => $previous > 0 && $n - $previous > 2];
            $previous = $n;
        }
        return $out;
    }
}
