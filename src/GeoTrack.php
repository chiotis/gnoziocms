<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Reads a route file: GPX, KML or GeoJSON. The result is the lines of the route (each a list of [lat, lng, height]; the
 * height is null when the file has none), the places the file marks (name, description, position), and the facts a visitor
 * wants (length, climb, highest and lowest point, a profile of the height along the way).
 *
 * GPX and KML are read as a stream, so a large recording does not have to fit in memory as a document; nothing is fetched
 * (no network, no entities), and a file with a DOCTYPE is refused. Anything unreadable gives null, never an exception. A file with markup a browser could run is refused.
 */
final class GeoTrack
{
    /** Largest file read, in bytes. */
    public const MAX_BYTES = 15_000_000;
    private const MAX_POINTS = 400_000;
    private const MAX_WAYPOINTS = 300;
    /** A change of height smaller than this (metres) is noise from the receiver, not a climb. */
    private const CLIMB_THRESHOLD = 4.0;

    public const EXTENSIONS = ['gpx', 'kml', 'geojson'];

    /**
     * @return array{lines: array<int, array<int, array{0: float, 1: float, 2: float|null}>>, waypoints: array<int, array{lat: float, lng: float, name: string, text: string}>}|null
     */
    public static function parse(string $contents, string $extension): ?array
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            return null;
        }
        $extension = strtolower($extension);
        $result = match ($extension) {
            'gpx' => self::parseGpx($contents),
            'kml' => self::parseKml($contents),
            'geojson', 'json' => self::parseGeoJson($contents),
            default => null,
        };
        if ($result === null || ($result['lines'] === [] && $result['waypoints'] === [])) {
            return null;
        }
        return $result;
    }

    /**
     * The facts of a route.
     *
     * @param array<int, array<int, array{0: float, 1: float, 2: float|null}>> $lines
     * @return array{distance_km: float, ascent_m: int, descent_m: int, min_m: int|null, max_m: int|null, has_height: bool, loop: bool, start: array{0: float, 1: float}|null, end: array{0: float, 1: float}|null, profile: array<int, array{0: float, 1: int}>}
     */
    public static function stats(array $lines): array
    {
        $distance = 0.0;
        $ascent = $descent = 0.0;
        $min = $max = null;
        $samples = [];
        $reference = null;
        foreach ($lines as $line) {
            $previous = null;
            foreach ($line as $point) {
                if ($previous !== null) {
                    $distance += Geo::distanceKm($previous[0], $previous[1], $point[0], $point[1]);
                }
                $previous = $point;
                if ($point[2] === null) {
                    continue;
                }
                $height = (float)$point[2];
                $min = $min === null ? $height : min($min, $height);
                $max = $max === null ? $height : max($max, $height);
                if ($reference === null) {
                    $reference = $height;
                } elseif (abs($height - $reference) >= self::CLIMB_THRESHOLD) {
                    $height > $reference ? $ascent += $height - $reference : $descent += $reference - $height;
                    $reference = $height;
                }
                $samples[] = [$distance, $height];
            }
        }
        $first = $lines[0][0] ?? null;
        $lastLine = $lines === [] ? [] : $lines[count($lines) - 1];
        $last = $lastLine === [] ? null : $lastLine[count($lastLine) - 1];
        $profile = [];
        if (count($samples) > 1) {
            $step = max(1, (int)ceil(count($samples) / 120));
            for ($i = 0; $i < count($samples); $i += $step) {
                $profile[] = [round($samples[$i][0], 3), (int)round($samples[$i][1])];
            }
            $end = $samples[count($samples) - 1];
            if ($profile[count($profile) - 1][0] !== round($end[0], 3)) {
                $profile[] = [round($end[0], 3), (int)round($end[1])];
            }
        }
        return [
            'distance_km' => round($distance, 2),
            'ascent_m' => (int)round($ascent),
            'descent_m' => (int)round($descent),
            'min_m' => $min === null ? null : (int)round($min),
            'max_m' => $max === null ? null : (int)round($max),
            'has_height' => $min !== null,
            'loop' => $first !== null && $last !== null && $distance > 1.0 && Geo::distanceKm($first[0], $first[1], $last[0], $last[1]) < max(0.2, $distance * 0.03),
            'start' => $first === null ? null : [$first[0], $first[1]],
            'end' => $last === null ? null : [$last[0], $last[1]],
            'profile' => $profile,
        ];
    }

    // ---- GPX ----------------------------------------------------------------------------------------------------

    /** @return array{lines: array, waypoints: array}|null */
    private static function parseGpx(string $xml): ?array
    {
        $reader = self::reader($xml);
        if ($reader === null) {
            return null;
        }
        $tracks = [];
        $routes = [];
        $waypoints = [];
        $line = null;
        $kind = '';
        $points = 0;
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->localName === 'trkseg' || $reader->localName === 'rte') {
                    if ($line !== null && count($line) > 1) {
                        $kind === 'rte' ? $routes[] = $line : $tracks[] = $line;
                    }
                    $line = null;
                }
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            switch ($reader->localName) {
                case 'trkseg':
                case 'rte':
                    $line = [];
                    $kind = $reader->localName;
                    break;
                case 'trkpt':
                case 'rtept':
                    $point = self::gpxPoint($reader);
                    if ($point !== null && $line !== null && ++$points <= self::MAX_POINTS) {
                        $line[] = [$point['lat'], $point['lng'], $point['ele']];
                    }
                    break;
                case 'wpt':
                    $point = self::gpxPoint($reader);
                    if ($point !== null && count($waypoints) < self::MAX_WAYPOINTS) {
                        $waypoints[] = ['lat' => $point['lat'], 'lng' => $point['lng'], 'name' => $point['name'], 'text' => $point['text']];
                    }
                    break;
            }
        }
        $reader->close();
        // A recording is the tracks; a planned route (rte) is used only when there is no track.
        $lines = $tracks !== [] ? $tracks : $routes;
        return ['lines' => $lines, 'waypoints' => $waypoints];
    }

    /** Reads one <trkpt>, <rtept> or <wpt> (its attributes and the children that matter), leaving the reader at its end. @return array{lat: float, lng: float, ele: float|null, name: string, text: string}|null */
    private static function gpxPoint(\XMLReader $reader): ?array
    {
        $lat = $reader->getAttribute('lat');
        $lng = $reader->getAttribute('lon');
        $name = $reader->localName;
        $depth = $reader->depth;
        $empty = $reader->isEmptyElement;
        $ele = null;
        $label = '';
        $text = '';
        if (!$empty) {
            while (@$reader->read()) {
                if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === $name) {
                    break;
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                    continue;
                }
                switch ($reader->localName) {
                    case 'ele':
                        $value = trim($reader->readString());
                        $ele = is_numeric($value) ? (float)$value : null;
                        break;
                    case 'name':
                        $label = trim($reader->readString());
                        break;
                    case 'desc':
                    case 'cmt':
                        $text = $text !== '' ? $text : trim($reader->readString());
                        break;
                }
            }
        }
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
            return null;
        }
        return ['lat' => round((float)$lat, 6), 'lng' => round((float)$lng, 6), 'ele' => $ele, 'name' => mb_substr($label, 0, 120), 'text' => mb_substr($text, 0, 400)];
    }

    // ---- KML ----------------------------------------------------------------------------------------------------

    /** @return array{lines: array, waypoints: array}|null */
    private static function parseKml(string $xml): ?array
    {
        $reader = self::reader($xml);
        if ($reader === null) {
            return null;
        }
        $lines = [];
        $waypoints = [];
        $names = [];
        $texts = [];
        $inPoint = false;
        $current = [];
        $points = 0;
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->localName === 'Placemark') {
                    array_pop($names);
                    array_pop($texts);
                } elseif ($reader->localName === 'Point') {
                    $inPoint = false;
                } elseif ($reader->localName === 'Track' && $current !== []) {
                    count($current) > 1 ? $lines[] = $current : null;
                    $current = [];
                }
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            switch ($reader->localName) {
                case 'Placemark':
                    $names[] = '';
                    $texts[] = '';
                    break;
                case 'name':
                    if ($names !== []) {
                        $names[count($names) - 1] = trim($reader->readString());
                    }
                    break;
                case 'description':
                    if ($texts !== []) {
                        $texts[count($texts) - 1] = trim(strip_tags(html_entity_decode($reader->readString(), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    }
                    break;
                case 'Point':
                    $inPoint = true;
                    break;
                case 'Track':
                    $current = [];
                    break;
                case 'coord':
                    // gx:Track: "lon lat height"
                    $parts = preg_split('/\s+/', trim($reader->readString())) ?: [];
                    if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && ++$points <= self::MAX_POINTS) {
                        $current[] = [round((float)$parts[1], 6), round((float)$parts[0], 6), isset($parts[2]) && is_numeric($parts[2]) ? (float)$parts[2] : null];
                    }
                    break;
                case 'coordinates':
                    $coordinates = self::kmlCoordinates($reader->readString(), self::MAX_POINTS - $points);
                    $points += count($coordinates);
                    if ($inPoint) {
                        if ($coordinates !== [] && count($waypoints) < self::MAX_WAYPOINTS) {
                            $waypoints[] = ['lat' => $coordinates[0][0], 'lng' => $coordinates[0][1], 'name' => mb_substr((string)end($names), 0, 120), 'text' => mb_substr((string)end($texts), 0, 400)];
                        }
                    } elseif (count($coordinates) > 1) {
                        $lines[] = $coordinates;
                    }
                    break;
            }
        }
        $reader->close();
        return ['lines' => $lines, 'waypoints' => $waypoints];
    }

    /** @return array<int, array{0: float, 1: float, 2: float|null}> */
    private static function kmlCoordinates(string $text, int $room): array
    {
        $points = [];
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $token) {
            if ($token === '' || count($points) >= $room) {
                continue;
            }
            $parts = explode(',', $token);
            if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && abs((float)$parts[1]) <= 90 && abs((float)$parts[0]) <= 180) {
                $points[] = [round((float)$parts[1], 6), round((float)$parts[0], 6), isset($parts[2]) && is_numeric($parts[2]) && (float)$parts[2] != 0.0 ? (float)$parts[2] : null];
            }
        }
        return $points;
    }

    // ---- GeoJSON ------------------------------------------------------------------------------------------------

    /** @return array{lines: array, waypoints: array}|null */
    private static function parseGeoJson(string $json): ?array
    {
        $data = json_decode($json, true, 64);
        if (!is_array($data)) {
            return null;
        }
        $lines = [];
        $waypoints = [];
        self::collectGeometry($data, '', '', $lines, $waypoints);
        return ['lines' => $lines, 'waypoints' => $waypoints];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed> $lines
     * @param array<int, mixed> $waypoints
     */
    private static function collectGeometry(array $node, string $name, string $text, array &$lines, array &$waypoints): void
    {
        $type = (string)($node['type'] ?? '');
        if ($type === 'FeatureCollection') {
            foreach (is_array($node['features'] ?? null) ? $node['features'] : [] as $feature) {
                if (is_array($feature)) {
                    self::collectGeometry($feature, '', '', $lines, $waypoints);
                }
            }
            return;
        }
        if ($type === 'Feature') {
            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $label = is_string($properties['name'] ?? null) ? $properties['name'] : (is_string($properties['title'] ?? null) ? $properties['title'] : '');
            $note = is_string($properties['description'] ?? null) ? $properties['description'] : '';
            if (is_array($node['geometry'] ?? null)) {
                self::collectGeometry($node['geometry'], $label, $note, $lines, $waypoints);
            }
            return;
        }
        $position = static function (mixed $p): ?array {
            if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1]) || abs((float)$p[1]) > 90 || abs((float)$p[0]) > 180) {
                return null;
            }
            return [round((float)$p[1], 6), round((float)$p[0], 6), isset($p[2]) && is_numeric($p[2]) && (float)$p[2] != 0.0 ? (float)$p[2] : null];
        };
        $toLine = static function (mixed $coordinates) use ($position): array {
            $line = [];
            foreach (is_array($coordinates) ? $coordinates : [] as $p) {
                $point = $position($p);
                if ($point !== null && count($line) < self::MAX_POINTS) {
                    $line[] = $point;
                }
            }
            return $line;
        };
        switch ($type) {
            case 'LineString':
                $line = $toLine($node['coordinates'] ?? null);
                if (count($line) > 1) {
                    $lines[] = $line;
                }
                break;
            case 'MultiLineString':
                foreach (is_array($node['coordinates'] ?? null) ? $node['coordinates'] : [] as $part) {
                    $line = $toLine($part);
                    if (count($line) > 1) {
                        $lines[] = $line;
                    }
                }
                break;
            case 'Point':
                $point = $position($node['coordinates'] ?? null);
                if ($point !== null && count($waypoints) < self::MAX_WAYPOINTS) {
                    $waypoints[] = ['lat' => $point[0], 'lng' => $point[1], 'name' => mb_substr($name, 0, 120), 'text' => mb_substr(trim(strip_tags($text)), 0, 400)];
                }
                break;
            case 'MultiPoint':
                foreach (is_array($node['coordinates'] ?? null) ? $node['coordinates'] : [] as $p) {
                    $point = $position($p);
                    if ($point !== null && count($waypoints) < self::MAX_WAYPOINTS) {
                        $waypoints[] = ['lat' => $point[0], 'lng' => $point[1], 'name' => mb_substr($name, 0, 120), 'text' => ''];
                    }
                }
                break;
            case 'GeometryCollection':
                foreach (is_array($node['geometries'] ?? null) ? $node['geometries'] : [] as $geometry) {
                    if (is_array($geometry)) {
                        self::collectGeometry($geometry, $name, $text, $lines, $waypoints);
                    }
                }
                break;
        }
    }

    /** An XML reader that fetches nothing and refuses a file with a DOCTYPE, or null when the text is not XML. */
    private static function reader(string $xml): ?\XMLReader
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', substr($xml, 0, 4096))) {
            return null;
        }
        // A route file is served as it was uploaded, as XML; markup that a browser could run when it opens the file (a page, a drawing,
        // a script, a style sheet) is not a route and is refused.
        if (stripos($xml, 'www.w3.org/1999/xhtml') !== false || stripos($xml, 'www.w3.org/2000/svg') !== false || stripos($xml, '<script') !== false || stripos($xml, 'xml-stylesheet') !== false) {
            return null;
        }
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        $opened = $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $opened ? $reader : null;
    }
}
