<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The `relation` fields of a content type, found and read without a database: where they are in the definition (also inside
 * repeaters, two levels), which ids a stored entry points at, and the conversion of what a person or a CSV file types
 * (`books/my-book`) into the stable ids that are stored.
 *
 * A reference is the `translation_id` of the entry pointed at. It does not change when the entry gets another address and it is
 * shared by every language of the entry, so a reference follows the page in the language being shown.
 */
final class RelationFields
{
    /** What an id looks like (ContentEditor::newTranslationId makes 16 hex digits; imported ones may differ). */
    public const ID = '/^[A-Za-z0-9_-]{6,64}$/';

    /**
     * The relation fields of a set of normalized definitions, with the path of each (`related.book` for a field inside a repeater).
     *
     * @param array<string, array<string, mixed>> $fields
     * @return list<array{path: string, field: array<string, mixed>}>
     */
    public static function paths(array $fields, string $prefix = ''): array
    {
        $found = [];
        foreach ($fields as $key => $field) {
            $path = $prefix . $key;
            if (($field['type'] ?? '') === 'relation') {
                $found[] = ['path' => $path, 'field' => $field];
            } elseif (($field['type'] ?? '') === 'repeater') {
                $found = array_merge($found, self::paths($field['fields'] ?? [], $path . '.'));
            }
        }
        return $found;
    }

    /**
     * The references a stored entry holds: field path, the place of its row (`0`, or `1.0` for a row of an inner list; empty for a
     * field of the type itself), the position inside a field that takes several, and the id. Values are checked first, so an id that
     * could not be stored is not listed.
     *
     * @param array<string, array<string, mixed>> $fields normalized definitions of the type
     * @param array<string, mixed> $values the entry's custom_fields
     * @return list<array{path: string, row: string, pos: int, id: string}>
     */
    public static function extract(array $fields, array $values, string $prefix = '', string $row = ''): array
    {
        $refs = [];
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $path = $prefix . $key;
            if (($field['type'] ?? '') === 'relation') {
                $ids = self::idList(FieldSchema::clean($field, $values[$key]));
                foreach ($ids as $pos => $id) {
                    $refs[] = ['path' => $path, 'row' => $row, 'pos' => $pos, 'id' => $id];
                }
            } elseif (($field['type'] ?? '') === 'repeater' && is_array($values[$key])) {
                foreach (array_values($values[$key]) as $index => $item) {
                    if (is_array($item)) {
                        $refs = array_merge($refs, self::extract($field['fields'] ?? [], $item, $path . '.', $row === '' ? (string)$index : $row . '.' . $index));
                    }
                }
            }
        }
        return $refs;
    }

    /** @return list<string> */
    public static function idList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn(string $id): bool => $id !== ''));
        }
        return is_string($value) && $value !== '' ? [$value] : [];
    }

    /**
     * Turns what was typed or posted for a relation field into tokens: ids stay, `type/slug` (also with a leading `@`) is left for
     * the caller to look up. A list may be an array or one text split by `|`, `,` or new lines.
     *
     * @return list<string>
     */
    public static function tokens(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : preg_split('/[|,\r\n]+/', is_scalar($raw) ? (string)$raw : '');
        $tokens = [];
        foreach ($parts ?: [] as $part) {
            $part = is_scalar($part) ? ltrim(trim((string)$part), '@') : '';
            if ($part !== '' && !in_array($part, $tokens, true)) {
                $tokens[] = $part;
            }
        }
        return $tokens;
    }

    /**
     * A copy of `$values` (the raw custom_fields of an entry, or a form's `details`) where each relation field holds ids: every token
     * that is a `type/slug` goes through `$lookup`, which returns the id (or '' for an entry that does not exist, which drops it).
     * Rows of repeaters are walked, so a relation inside a row is converted too.
     *
     * @param array<string, array<string, mixed>> $fields normalized definitions
     * @param array<string, mixed> $values
     * @param \Closure(string, array<int, string>): string $lookup `type/slug` and the types the field allows => the id, or ''
     * @return array<string, mixed>
     */
    public static function convert(array $fields, array $values, \Closure $lookup): array
    {
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            if (($field['type'] ?? '') === 'relation') {
                $ids = [];
                foreach (self::tokens($values[$key]) as $token) {
                    $id = preg_match(self::ID, $token) === 1 && !str_contains($token, '/') ? $token : $lookup($token, $field['types'] ?? []);
                    if ($id !== '' && !in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
                $values[$key] = ($field['multiple'] ?? false) ? $ids : ($ids[0] ?? '');
            } elseif (($field['type'] ?? '') === 'repeater' && is_array($values[$key])) {
                foreach ($values[$key] as $index => $row) {
                    if (is_array($row)) {
                        $values[$key][$index] = self::convert($field['fields'] ?? [], $row, $lookup);
                    }
                }
            }
        }
        return $values;
    }

    /**
     * A copy of `$values` where the value of each relation field (also in rows) is replaced by what `$change` returns for its ids.
     *
     * @param array<string, array<string, mixed>> $fields normalized definitions
     * @param array<string, mixed> $values
     * @param \Closure(array<string, mixed>, list<string>): mixed $change the field and its stored ids => the new value
     * @return array<string, mixed>
     */
    public static function transform(array $fields, array $values, \Closure $change): array
    {
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            if (($field['type'] ?? '') === 'relation') {
                $values[$key] = $change($field, self::idList(FieldSchema::clean($field, $values[$key])));
            } elseif (($field['type'] ?? '') === 'repeater' && is_array($values[$key])) {
                foreach ($values[$key] as $index => $row) {
                    if (is_array($row)) {
                        $values[$key][$index] = self::transform($field['fields'] ?? [], $row, $change);
                    }
                }
            }
        }
        return $values;
    }

    /** A short text that says which relation fields a set of definitions has; it changes when a field, its targets or its limits do. */
    public static function signature(array $fieldsByType): string
    {
        $parts = [];
        ksort($fieldsByType);
        foreach ($fieldsByType as $type => $fields) {
            foreach (self::paths($fields) as $entry) {
                $parts[] = $type . ':' . $entry['path'] . ':' . implode(',', $entry['field']['types'] ?? []) . ':' . (($entry['field']['multiple'] ?? false) ? 'm' . ($entry['field']['max'] ?? '') : 's');
            }
        }
        return sha1(implode('|', $parts));
    }
}
