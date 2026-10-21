<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The custom post types of a WordPress site that its REST API does show (`/wp-json/wp/v2/<endpoint>`), with their ACF fields
 * and their taxonomies: what a profile says about them, and what one record of them becomes here.
 *
 * Profile keys (see docs/wordpress-import.md, "Custom types in the REST API"):
 *
 *   rest_types:
 *     <name>:                         any name; the endpoint is the same unless `endpoint` says otherwise
 *       endpoint: route               the REST base of the type
 *       content_type: routes          the content type here
 *       fields:                       ACF key => where its value goes
 *         title_en: title@en            title, excerpt and body are the entry's own; @<lang> says which language's entry
 *         υπότιτλος_el: subtitle@el     any other name is a field of the type (custom_fields); without @ it goes to every language
 *         μήκος: { to: distance_km, type: number }
 *       coordinates: { lat: latitude, lng: longitude, to: location }
 *       media:                        ACF keys that hold files of the library (an id, an address, or a list of them)
 *         banner_image: main_image      the main image
 *         photo_gallery: gallery        a list of library paths in the field
 *         map_kml: { to: track, length: distance_km }   a route file, and the field that takes its length when it is empty
 *         youtube_video: video          a Video block in the entry
 *       taxonomies: { activity: activity, categories: categories }   REST base of the old taxonomy => taxonomy here
 *       ignore: [map_img_url]         ACF keys that are left out without being reported
 *       front: { hero_layout: minimal }   front matter added to every entry
 *   term_map: { <REST base>: { <source id>: <source id> } }   a term merged into another
 *   skip_empty_terms: true            terms with no entries and no twin are left out
 *   merge_same_names: true            terms under one parent whose names differ only in case, accents or ς/σ are one
 *   language_prefixes: { en: /en }    where the old site keeps another language (its term names and its old addresses)
 *
 * Nothing in a record is required: an empty value is no value, and a record without any of them still becomes an entry.
 */
final class WordPressRestTypes
{
    /** Targets that are the entry's own, not a field of its type. */
    public const OWN = ['title', 'excerpt', 'body'];

    /** Media targets that are not a field: the main image and a Video block. */
    public const MEDIA_OWN = ['main_image', 'video'];

    /**
     * What the profile says, checked. Mistakes are listed, never guessed around; the import does not start with any.
     *
     * @param array<string, mixed> $profile
     * @return array{types: array<string, array<string, mixed>>, taxonomies: array<string, string>, term_map: array<string, array<int, int>>, skip_empty_terms: bool, merge_same_names: bool, language_prefixes: array<string, string>, errors: string[]}
     */
    public static function parse(array $profile): array
    {
        $errors = [];
        $types = [];
        $taxonomies = [];
        $rest = $profile['rest_types'] ?? [];
        if (!is_array($rest)) {
            $errors[] = 'rest_types: a map of types is expected';
            $rest = [];
        }
        foreach ($rest as $name => $rules) {
            $name = (string)$name;
            $at = 'rest_types.' . $name;
            if (!is_array($rules)) {
                $errors[] = "$at: a map is expected";
                continue;
            }
            $endpoint = trim((string)($rules['endpoint'] ?? $name), '/');
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $endpoint)) {
                $errors[] = "$at.endpoint: '$endpoint' is not a REST base (letters, digits, - and _)";
            }
            $contentType = (string)($rules['content_type'] ?? '');
            if ($contentType === '' || Slug::plain($contentType) !== $contentType) {
                $errors[] = "$at.content_type: the content type here is needed (lower case, like routes)";
            }

            $fields = [];
            foreach (self::mapOf($rules['fields'] ?? [], "$at.fields", $errors) as $acf => $target) {
                $parsed = self::target($target);
                if ($parsed === null) {
                    $errors[] = "$at.fields.$acf: '" . self::show($target) . "' is not a target (field, field@lang, or {to, lang, type})";
                    continue;
                }
                $fields[(string)$acf] = $parsed;
            }

            $coordinates = null;
            if (isset($rules['coordinates'])) {
                $c = $rules['coordinates'];
                if (!is_array($c) || trim((string)($c['lat'] ?? '')) === '' || trim((string)($c['lng'] ?? '')) === '') {
                    $errors[] = "$at.coordinates: needs lat and lng (the ACF keys of the two numbers)";
                } else {
                    $to = (string)($c['to'] ?? 'location');
                    if (!self::isFieldKey($to)) {
                        $errors[] = "$at.coordinates.to: '$to' is not a field key";
                    }
                    $coordinates = ['lat' => (string)$c['lat'], 'lng' => (string)$c['lng'], 'to' => $to];
                }
            }

            $media = [];
            foreach (self::mapOf($rules['media'] ?? [], "$at.media", $errors) as $acf => $target) {
                $to = is_array($target) ? (string)($target['to'] ?? '') : (string)$target;
                $length = is_array($target) ? (string)($target['length'] ?? '') : '';
                if (!in_array($to, self::MEDIA_OWN, true) && !self::isFieldKey($to)) {
                    $errors[] = "$at.media.$acf: '" . self::show($target) . "' is not main_image, video or a field key";
                    continue;
                }
                if ($length !== '' && !self::isFieldKey($length)) {
                    $errors[] = "$at.media.$acf.length: '$length' is not a field key";
                    $length = '';
                }
                $media[(string)$acf] = ['to' => $to, 'length' => $length];
            }

            $typeTaxonomies = [];
            foreach (self::mapOf($rules['taxonomies'] ?? [], "$at.taxonomies", $errors) as $base => $here) {
                $base = (string)$base;
                $here = (string)$here;
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $base) || $here === '' || Slug::plain($here) !== $here) {
                    $errors[] = "$at.taxonomies.$base: '$here' is not a taxonomy name (lower case, - and _)";
                    continue;
                }
                if (isset($taxonomies[$base]) && $taxonomies[$base] !== $here) {
                    $errors[] = "$at.taxonomies.$base: goes to '$here' here but another type sends it to '{$taxonomies[$base]}'";
                    continue;
                }
                $taxonomies[$base] = $here;
                $typeTaxonomies[$base] = $here;
            }

            $ignore = array_values(array_map('strval', is_array($rules['ignore'] ?? null) ? $rules['ignore'] : []));
            $front = is_array($rules['front'] ?? null) ? $rules['front'] : [];
            $types[$name] = [
                'name' => $name,
                'endpoint' => $endpoint,
                'content_type' => $contentType,
                'fields' => $fields,
                'coordinates' => $coordinates,
                'media' => $media,
                'taxonomies' => $typeTaxonomies,
                'ignore' => $ignore,
                'front' => $front,
            ];
        }

        $termMap = [];
        foreach (self::mapOf($profile['term_map'] ?? [], 'term_map', $errors) as $base => $lines) {
            $base = (string)$base;
            foreach (self::mapOf($lines, "term_map.$base", $errors) as $from => $into) {
                if (!self::isId($from) || !self::isId($into)) {
                    $errors[] = "term_map.$base.$from: a line is <source id>: <source id>, '" . self::show($into) . "' is not";
                    continue;
                }
                $termMap[$base][(int)$from] = (int)$into;
            }
        }

        $prefixes = [];
        foreach (self::mapOf($profile['language_prefixes'] ?? [], 'language_prefixes', $errors) as $lang => $prefix) {
            $lang = (string)$lang;
            $prefix = '/' . trim((string)$prefix, '/');
            if (!self::isLang($lang) || !preg_match('#^/[A-Za-z0-9_/-]+$#', $prefix)) {
                $errors[] = "language_prefixes.$lang: '$prefix' is not a language and a path (en: /en)";
                continue;
            }
            $prefixes[$lang] = $prefix;
        }

        return [
            'types' => $types,
            'taxonomies' => $taxonomies,
            'term_map' => $termMap,
            'skip_empty_terms' => FieldSchema::isTruthy($profile['skip_empty_terms'] ?? false),
            'merge_same_names' => FieldSchema::isTruthy($profile['merge_same_names'] ?? false),
            'language_prefixes' => $prefixes,
            'errors' => $errors,
        ];
    }

    /**
     * The languages a profile writes besides the main one: every @<lang> of its fields and every language prefix.
     *
     * @param array{types: array<string, array<string, mixed>>, language_prefixes: array<string, string>} $parsed
     * @return string[]
     */
    public static function languages(array $parsed, string $main): array
    {
        $langs = [$main];
        foreach ($parsed['types'] as $type) {
            foreach ($type['fields'] as $target) {
                if ($target['lang'] !== '') {
                    $langs[] = $target['lang'];
                }
            }
        }
        foreach (array_keys($parsed['language_prefixes']) as $lang) {
            $langs[] = (string)$lang;
        }
        return array_values(array_unique($langs));
    }

    /**
     * What one record becomes: the texts of each language (an entry for each), the fields every language shares, the place,
     * the files, the video, and the terms by taxonomy here. Plain values only; files are still ids or addresses of the
     * old site, which the importer brings into the library.
     *
     * @param array<string, mixed> $record a row of the REST API (id, slug, link, title, date, content, excerpt, acf, taxonomies)
     * @param array<string, mixed> $type one type of parse()
     * @return array{
     *   texts: array<string, array{title: string, excerpt: string, body: string, fields: array<string, mixed>}>,
     *   fields: array<string, mixed>,
     *   location: string,
     *   coordinates: string,
     *   media: array<string, array{to: string, length: string, refs: array<int, int|string>, list: bool}>,
     *   video: string,
     *   terms: array<string, int[]>,
     *   unmapped: string[],
     *   notes: string[]
     * }
     */
    public static function map(array $record, array $type, string $mainLang): array
    {
        $acf = is_array($record['acf'] ?? null) ? $record['acf'] : [];
        $texts = [$mainLang => [
            'title' => WordPressReader::text((string)($record['title']['rendered'] ?? (is_string($record['title'] ?? null) ? $record['title'] : ''))),
            'excerpt' => (string)($record['excerpt']['rendered'] ?? ''),
            'body' => (string)($record['content']['rendered'] ?? ''),
            'fields' => [],
        ]];
        $shared = [];
        $notes = [];
        $known = array_fill_keys($type['ignore'], true);

        foreach ($type['fields'] as $key => $target) {
            $known[$key] = true;
            if (!array_key_exists($key, $acf)) {
                continue;
            }
            $raw = $acf[$key];
            if (self::isEmpty($raw)) {
                continue;
            }
            $lang = $target['lang'] !== '' ? $target['lang'] : '';
            if (in_array($target['to'], self::OWN, true)) {
                $value = $target['to'] === 'title' ? WordPressReader::text(self::scalar($raw)) : self::scalar($raw);
                if (trim(WordPressReader::text($value)) === '' && $target['to'] !== 'body') {
                    continue;
                }
                foreach ($lang !== '' ? [$lang] : [$mainLang] as $l) {
                    $texts[$l] ??= ['title' => '', 'excerpt' => '', 'body' => '', 'fields' => []];
                    // The first key that has a text wins: an ACF field over WordPress's own, in the order of the profile.
                    if (!isset($texts[$l]['set'][$target['to']])) {
                        $texts[$l][$target['to']] = $value;
                        $texts[$l]['set'][$target['to']] = true;
                    }
                }
                continue;
            }
            $value = $target['type'] === 'number' ? self::number($raw) : self::value($raw);
            if ($value === null) {
                $notes[] = $target['type'] === 'number' ? "$key: '" . self::show($raw) . "' is not a number, left empty" : "$key: a value that is not text, left out";
                continue;
            }
            if ($lang === '') {
                $shared[$target['to']] ??= $value;
            } else {
                $texts[$lang] ??= ['title' => '', 'excerpt' => '', 'body' => '', 'fields' => []];
                $texts[$lang]['fields'][$target['to']] ??= $value;
            }
        }
        foreach ($texts as $l => $text) {
            unset($texts[$l]['set']);
        }

        // The place: both numbers, or none.
        $location = '';
        $coordinates = 'none';
        if ($type['coordinates'] !== null) {
            $c = $type['coordinates'];
            $known[$c['lat']] = $known[$c['lng']] = true;
            [$location, $coordinates] = self::position($acf[$c['lat']] ?? null, $acf[$c['lng']] ?? null);
        }

        $media = [];
        $video = '';
        foreach ($type['media'] as $key => $rule) {
            $known[$key] = true;
            if ($rule['to'] === 'video') {
                $video = self::videoUrl($acf[$key] ?? null);
                continue;
            }
            $refs = self::refs($acf[$key] ?? null);
            if ($refs !== []) {
                // A gallery stays a list even with one picture in it; a single file is one path.
                $list = is_array($acf[$key]) && array_is_list($acf[$key]);
                $media[$key] = $rule + ['refs' => $refs, 'list' => $list];
            }
        }

        $terms = [];
        foreach ($type['taxonomies'] as $base => $here) {
            $ids = array_values(array_filter(array_map('intval', is_array($record[$base] ?? null) ? $record[$base] : []), static fn(int $id): bool => $id > 0));
            if ($ids !== []) {
                $terms[$here] = array_values(array_unique(array_merge($terms[$here] ?? [], $ids)));
            }
        }

        $unmapped = [];
        foreach ($acf as $key => $value) {
            if (!isset($known[(string)$key]) && !self::isEmpty($value)) {
                $unmapped[] = (string)$key;
            }
        }
        return ['texts' => $texts, 'fields' => $shared, 'location' => $location, 'coordinates' => $coordinates, 'media' => $media, 'video' => $video, 'terms' => $terms, 'unmapped' => $unmapped, 'notes' => $notes];
    }

    /**
     * A position from two values that may be numbers, numeric strings with a comma, or empty: "lat, lng" when both are real,
     * nothing when one is missing or the pair is not a place.
     *
     * @return array{0: string, 1: string} the position, and both, one or none
     */
    public static function position(mixed $lat, mixed $lng): array
    {
        $read = static function (mixed $value): ?float {
            if (is_int($value) || is_float($value)) {
                return (float)$value;
            }
            if (!is_string($value)) {
                return null;
            }
            $value = str_replace(',', '.', trim($value));
            return preg_match('/^-?\d{1,3}(\.\d+)?$/', $value) ? (float)$value : null;
        };
        $a = $read($lat);
        $b = $read($lng);
        if ($a === null && $b === null) {
            return ['', 'none'];
        }
        if ($a === null || $b === null) {
            return ['', 'one'];
        }
        $place = Geo::parse(Geo::format($a, $b));
        return $place === null ? ['', 'none'] : [Geo::format($place['lat'], $place['lng']), 'both'];
    }

    /** A YouTube or Vimeo address from what an ACF field holds (an address, or the embed code an oEmbed field gives). */
    public static function videoUrl(mixed $value): string
    {
        $text = trim(is_array($value) ? (string)($value['url'] ?? '') : (is_string($value) ? $value : ''));
        if ($text === '') {
            return '';
        }
        if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $text, $m)) {
            $text = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (str_starts_with($text, '//')) {
            $text = 'https:' . $text;
        }
        if (preg_match('#^(?:https?://)?(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $text, $m)) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }
        if (preg_match('#^(?:https?://)?(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d+)#', $text, $m)) {
            return 'https://vimeo.com/' . $m[1];
        }
        return preg_match('#^https://#i', $text) ? $text : '';
    }

    /** @return array<int, int|string> the files a media field points at: ids of the library, or addresses */
    private static function refs(mixed $value): array
    {
        if (self::isEmpty($value)) {
            return [];
        }
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return (int)$value > 0 ? [(int)$value] : [];
        }
        if (is_string($value)) {
            $value = trim($value);
            return preg_match('#^(https?:)?//|^/#i', $value) ? [$value] : [];
        }
        if (!is_array($value)) {
            return [];
        }
        // An ACF image or file field that returns an array.
        if (isset($value['ID']) || isset($value['id']) || isset($value['url'])) {
            $id = (int)($value['ID'] ?? $value['id'] ?? 0);
            return $id > 0 ? [$id] : self::refs((string)($value['url'] ?? ''));
        }
        $out = [];
        foreach ($value as $item) {
            foreach (self::refs($item) as $ref) {
                if (!in_array($ref, $out, true)) {
                    $out[] = $ref;
                }
            }
        }
        return $out;
    }

    /** @return array{to: string, lang: string, type: string}|null */
    private static function target(mixed $target): ?array
    {
        $type = 'text';
        if (is_array($target)) {
            $to = (string)($target['to'] ?? '');
            $lang = (string)($target['lang'] ?? '');
            $type = (string)($target['type'] ?? 'text');
            if (str_contains($to, '@')) {
                [$to, $lang] = explode('@', $to, 2);
            }
        } elseif (is_string($target)) {
            [$to, $lang] = array_pad(explode('@', trim($target), 2), 2, '');
        } else {
            return null;
        }
        if (!self::isFieldKey($to) || ($lang !== '' && !self::isLang($lang)) || !in_array($type, ['text', 'number'], true)) {
            return null;
        }
        return ['to' => $to, 'lang' => $lang, 'type' => $type];
    }

    /** @return array<string|int, mixed> */
    private static function mapOf(mixed $value, string $at, array &$errors): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            $errors[] = "$at: a map is expected";
            return [];
        }
        return $value;
    }

    private static function isFieldKey(string $key): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9_]*$/', $key);
    }

    private static function isLang(string $lang): bool
    {
        return (bool)preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $lang);
    }

    private static function isId(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int)$value > 0);
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === false || $value === [] || (is_string($value) && trim($value) === '');
    }

    private static function show(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** A text from a value that may be a list (a field that takes several): its parts with commas. */
    private static function scalar(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(static fn($v): string => is_scalar($v) ? trim((string)$v) : '', $value), static fn(string $v): bool => $v !== ''));
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * A value for a field of the type: text with its line breaks kept and no markup, true for a ticked box, the address of a
     * link field, the parts of a list with commas. Null when it is none of these (a repeater, a group).
     */
    private static function value(mixed $value): string|bool|null
    {
        if (is_bool($value)) {
            return $value ?: null;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            if (isset($value['url']) && is_string($value['url'])) {
                return trim($value['url']) !== '' ? trim($value['url']) : null;
            }
            if (array_is_list($value) && array_filter($value, static fn($v): bool => !is_scalar($v)) === []) {
                $text = self::scalar($value);
                return $text !== '' ? $text : null;
            }
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $text = preg_replace('#<br\s*/?>|</p>\s*|</li>\s*#i', "\n", $value) ?? $value;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = trim(preg_replace("/ *\n */u", "\n", preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?? $text);
        return $text !== '' ? $text : null;
    }

    /** A number from a value like 12, "12.5", "12,5" or "12,5 χλμ."; null when there is none. */
    private static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/-?\d+(?:[.,]\d+)?/', $value, $m)) {
            return null;
        }
        return ctype_digit(ltrim($m[0], '-')) ? (int)$m[0] : (float)str_replace(',', '.', $m[0]);
    }
}
