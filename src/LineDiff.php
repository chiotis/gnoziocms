<?php

declare(strict_types=1);

namespace GnozioCMS;

/** A line-by-line comparison of two texts, for showing what changed between two versions of a file. */
final class LineDiff
{
    /** Above this many lines each, the middle part that differs is shown as one removed and one added block. */
    private const MAX_LINES = 1500;

    /**
     * @return array<int, array{op: string, text: string}> op is "=" (same), "-" (only in $old) or "+" (only in $new)
     */
    public static function compare(string $old, string $new): array
    {
        $a = self::lines($old);
        $b = self::lines($new);

        // Most edits touch the middle of a file, so the shared start and end are peeled off first.
        $start = 0;
        $countA = count($a);
        $countB = count($b);
        while ($start < $countA && $start < $countB && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = $countA;
        $endB = $countB;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $out = [];
        for ($i = 0; $i < $start; $i++) {
            $out[] = ['op' => '=', 'text' => $a[$i]];
        }
        foreach (self::middle(array_slice($a, $start, $endA - $start), array_slice($b, $start, $endB - $start)) as $row) {
            $out[] = $row;
        }
        for ($i = $endA; $i < $countA; $i++) {
            $out[] = ['op' => '=', 'text' => $a[$i]];
        }
        return $out;
    }

    /** @return array{added: int, removed: int} */
    public static function summary(array $diff): array
    {
        $added = 0;
        $removed = 0;
        foreach ($diff as $row) {
            if ($row['op'] === '+') {
                $added++;
            } elseif ($row['op'] === '-') {
                $removed++;
            }
        }
        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Only the changed lines with a few lines of context, and "…" gaps between distant changes.
     *
     * @param array<int, array{op: string, text: string}> $diff
     * @return array<int, array{op: string, text: string}>
     */
    public static function withContext(array $diff, int $context = 3): array
    {
        $keep = array_fill(0, count($diff), false);
        foreach ($diff as $i => $row) {
            if ($row['op'] === '=') {
                continue;
            }
            for ($j = max(0, $i - $context); $j <= min(count($diff) - 1, $i + $context); $j++) {
                $keep[$j] = true;
            }
        }
        $out = [];
        $gap = false;
        foreach ($diff as $i => $row) {
            if ($keep[$i]) {
                if ($gap && $out !== []) {
                    $out[] = ['op' => '~', 'text' => ''];
                }
                $gap = false;
                $out[] = $row;
            } else {
                $gap = true;
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return $text === '' ? [] : explode("\n", $text);
    }

    /**
     * @param string[] $a
     * @param string[] $b
     * @return array<int, array{op: string, text: string}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0 || $n > self::MAX_LINES || $m > self::MAX_LINES) {
            $out = [];
            foreach ($a as $line) {
                $out[] = ['op' => '-', 'text' => $line];
            }
            foreach ($b as $line) {
                $out[] = ['op' => '+', 'text' => $line];
            }
            return $out;
        }

        // Longest common subsequence, filled from the end so the walk below goes forwards.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => '=', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['op' => '-', 'text' => $a[$i++]];
            } else {
                $out[] = ['op' => '+', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['op' => '-', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['op' => '+', 'text' => $b[$j++]];
        }
        return $out;
    }
}
