<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Small geography helpers: reading and writing a position ("35.2012, 26.2744"), distances, the box around some points, and a
 * line with fewer points that still looks the same on a map. No dependencies; every method is pure.
 */
final class Geo
{
    private const EARTH_KM = 6371.0088;

    /**
     * A position typed or pasted by a person: "35.2012, 26.2744", "35.2012 26.2744", "35.2012;26.2744", or a "geo:" address.
     * Anything that is not a real position (out of range, not numbers, 0,0 left in by a form) gives null.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function parse(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $text = preg_replace('/^\s*geo:/i', '', trim($value)) ?? '';
        if (!preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)/', $text, $m)) {
            return null;
        }
        $lat = (float)$m[1];
        $lng = (float)$m[2];
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }
        return ['lat' => $lat, 'lng' => $lng];
    }

    /** The one way a position is stored: "35.2012, 26.2744" (up to six decimals, about ten centimetres). */
    public static function format(float $lat, float $lng): string
    {
        $trim = static fn(float $n): string => rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');
        return $trim($lat) . ', ' . $trim($lng);
    }

    /** Distance over the surface of the earth between two positions, in kilometres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 2 * self::EARTH_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * How far a position is from a line (its nearest segment, not only its nearest vertex), in kilometres. Over the few
     * kilometres that matter here the earth is flat enough: the line is laid out around the position in kilometres.
     *
     * @param array<int, array<int, float>> $line [[lat, lng], …]
     */
    public static function distanceToLineKm(float $lat, float $lng, array $line): float
    {
        $count = count($line);
        if ($count === 0) {
            return INF;
        }
        $kmPerLng = 111.320 * cos(deg2rad($lat));
        $project = static fn(array $p): array => [($p[1] - $lng) * $kmPerLng, ($p[0] - $lat) * 110.574];
        $best = INF;
        $previous = $project($line[0]);
        if ($count === 1) {
            return hypot($previous[0], $previous[1]);
        }
        for ($i = 1; $i < $count; $i++) {
            $next = $project($line[$i]);
            $dx = $next[0] - $previous[0];
            $dy = $next[1] - $previous[1];
            $length = $dx * $dx + $dy * $dy;
            $t = $length > 0 ? max(0.0, min(1.0, -($previous[0] * $dx + $previous[1] * $dy) / $length)) : 0.0;
            $best = min($best, hypot($previous[0] + $t * $dx, $previous[1] + $t * $dy));
            $previous = $next;
        }
        return $best;
    }

    /**
     * The box around some positions: [south, west, north, east], or null for none.
     *
     * @param array<int, array<int, float>> $points [[lat, lng], …]
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function bbox(array $points): ?array
    {
        if ($points === []) {
            return null;
        }
        $south = $north = (float)$points[0][0];
        $west = $east = (float)$points[0][1];
        foreach ($points as $p) {
            $south = min($south, (float)$p[0]);
            $north = max($north, (float)$p[0]);
            $west = min($west, (float)$p[1]);
            $east = max($east, (float)$p[1]);
        }
        return [$south, $west, $north, $east];
    }

    /**
     * A line with at most $max points that follows the original (Douglas–Peucker, with the tolerance raised until it fits). The
     * first and the last point always stay. Extra values on a point (the height) are kept.
     *
     * @param array<int, array<int, float|null>> $points
     * @return array<int, array<int, float|null>>
     */
    public static function simplify(array $points, int $max): array
    {
        $count = count($points);
        if ($count <= $max || $max < 2) {
            return $max < 2 ? array_slice($points, 0, $max) : $points;
        }
        $tolerance = 0.000005;
        $tooFine = 0.0;
        for ($round = 0; $round < 24; $round++) {
            $kept = self::douglasPeucker($points, $tolerance);
            if (count($kept) <= $max) {
                // The tolerance was raised in big steps: look between the last one that kept too many and this one for the finest that fits.
                for ($i = 0; $i < 7 && $tooFine > 0.0; $i++) {
                    $middle = ($tooFine + $tolerance) / 2;
                    $try = self::douglasPeucker($points, $middle);
                    if (count($try) <= $max) {
                        $kept = $try;
                        $tolerance = $middle;
                    } else {
                        $tooFine = $middle;
                    }
                }
                return array_map(static fn(int $i): array => $points[$i], $kept);
            }
            $tooFine = $tolerance;
            $tolerance *= 1.8;
        }
        // Nothing fitted (a very dense line): take points at an even step.
        $result = [];
        for ($i = 0; $i < $max; $i++) {
            $result[] = $points[(int)round($i * ($count - 1) / ($max - 1))];
        }
        return $result;
    }

    /**
     * @param array<int, array<int, float|null>> $points
     * @return int[] indexes of the points kept
     */
    private static function douglasPeucker(array $points, float $tolerance): array
    {
        $count = count($points);
        $keep = array_fill(0, $count, false);
        $keep[0] = $keep[$count - 1] = true;
        $stack = [[0, $count - 1]];
        $limit = $tolerance * $tolerance;
        while ($stack !== []) {
            [$first, $last] = array_pop($stack);
            if ($last - $first < 2) {
                continue;
            }
            $ax = (float)$points[$first][1];
            $ay = (float)$points[$first][0];
            $dx = (float)$points[$last][1] - $ax;
            $dy = (float)$points[$last][0] - $ay;
            $length = $dx * $dx + $dy * $dy;
            $far = -1.0;
            $index = -1;
            for ($i = $first + 1; $i < $last; $i++) {
                $px = (float)$points[$i][1] - $ax;
                $py = (float)$points[$i][0] - $ay;
                $t = $length > 0 ? max(0.0, min(1.0, ($px * $dx + $py * $dy) / $length)) : 0.0;
                $distance = ($px - $t * $dx) ** 2 + ($py - $t * $dy) ** 2;
                if ($distance > $far) {
                    $far = $distance;
                    $index = $i;
                }
            }
            if ($far > $limit && $index > 0) {
                $keep[$index] = true;
                $stack[] = [$first, $index];
                $stack[] = [$index, $last];
            }
        }
        return array_keys(array_filter($keep));
    }
}
