<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Route files of the site, read once and kept: the lines (simplified so a page stays light), the places the file marks, and the
 * facts worked out from the whole recording. A route file is one of the site's uploads (GPX, KML or GeoJSON); what is kept lives
 * in storage/cache/geo, named by the file's path, time and size, so a replaced file is read again and an old copy is never used.
 */
final class GeoLibrary
{
    /** Points a route keeps for a page of its own (the map and the profile). */
    public const PAGE_POINTS = 1500;
    /** Points a route keeps when many are drawn at once (an archive map, a list of routes). */
    public const OVERVIEW_POINTS = 90;
    private const FORMAT = 1;

    /** @var array<string, array<string, mixed>|null> */
    private array $memory = [];

    public function __construct(private Images $images, private string $cacheDir)
    {
    }

    /**
     * @return array{lines: array<int, array<int, array{0: float, 1: float, 2: int|null}>>, waypoints: array<int, array{lat: float, lng: float, name: string, text: string}>, stats: array<string, mixed>, bbox: array<int, float>, ext: string}|null
     */
    public function track(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (array_key_exists($url, $this->memory)) {
            return $this->memory[$url];
        }
        $file = $this->images->uploadFile($url);
        $extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($file === null || !in_array($extension, GeoTrack::EXTENSIONS, true) || (int)filesize($file) > GeoTrack::MAX_BYTES) {
            return $this->memory[$url] = null;
        }
        $key = sha1($file . '|' . filemtime($file) . '|' . filesize($file) . '|' . self::FORMAT);
        $cached = $this->cacheDir . '/geo/' . $key . '.json';
        if (is_file($cached)) {
            $data = json_decode((string)file_get_contents($cached), true);
            if (is_array($data) && isset($data['lines'], $data['stats'])) {
                return $this->memory[$url] = $data;
            }
        }
        $parsed = GeoTrack::parse((string)file_get_contents($file), $extension);
        if ($parsed === null) {
            return $this->memory[$url] = null;
        }
        $stats = GeoTrack::stats($parsed['lines']);
        $lines = [];
        $each = max(2, (int)floor(self::PAGE_POINTS / max(1, count($parsed['lines']))));
        foreach ($parsed['lines'] as $line) {
            $lines[] = array_map(static fn(array $p): array => [$p[0], $p[1], $p[2] === null ? null : (int)round($p[2])], Geo::simplify($line, $each));
        }
        $all = [];
        foreach ($lines as $line) {
            foreach ($line as $p) {
                $all[] = $p;
            }
        }
        foreach ($parsed['waypoints'] as $w) {
            $all[] = [$w['lat'], $w['lng']];
        }
        $data = ['lines' => $lines, 'waypoints' => $parsed['waypoints'], 'stats' => $stats, 'bbox' => Geo::bbox($all) ?? [], 'ext' => $extension];
        if (is_dir($this->cacheDir . '/geo') || @mkdir($this->cacheDir . '/geo', 0775, true)) {
            $temporary = $cached . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($temporary, $cached);
            }
        }
        return $this->memory[$url] = $data;
    }

    /**
     * The lines of a route for an overview map: [[lat, lng], …] with few points each.
     *
     * @param array<string, mixed> $track
     * @return array<int, array<int, array{0: float, 1: float}>>
     */
    public static function overview(array $track, int $points = self::OVERVIEW_POINTS): array
    {
        $each = max(2, (int)floor($points / max(1, count($track['lines']))));
        $lines = [];
        foreach ($track['lines'] as $line) {
            $lines[] = array_map(static fn(array $p): array => [round((float)$p[0], 5), round((float)$p[1], 5)], Geo::simplify($line, $each));
        }
        return $lines;
    }
}
