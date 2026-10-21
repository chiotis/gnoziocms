<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The terms of one taxonomy of a WordPress site with their duplicates merged, before they are brought over.
 *
 * Old sites collect the same term twice: a spelling variant ("Παρατήρηση πουλιών" and "Παρατήρηση Πτηνών"), a name typed in another
 * case or without its accents, an empty term left beside the one people use. Three rules decide what comes over:
 *
 *   1. The lines of the profile's `term_map` (source id => source id) merge one term into another, always and first. A chain
 *      (a into b, b into c) ends at its last term; a line naming a term the site does not have is reported, never guessed.
 *   2. Two terms under the same parent whose names differ only in case, accents or final sigma (ς/σ) are one; the one with
 *      more entries stays (the lower id when they have as many). A term under another parent is another term ("Logicomix"
 *      under News and under Reviews), so those need a `term_map` line.
 *   3. With `skip_empty_terms`, a term with no entries (count 0) that nothing was merged into, and that has no term with
 *      entries below it, is left out.
 *
 * Everything is worked out from the source ids, the names and the counts, so the same site and the same profile give the
 * same terms on every run. Each merge is returned with its reason so the importer can print it; a merge made by rule 2 can
 * be pinned by copying it into `term_map`.
 */
final class WordPressTermMerge
{
    /**
     * @param array<int, array{id: int, slug: string, name: string, link: string, parent: int, count: int, description: string}> $terms as WordPressReader::terms() gives them
     * @param array<int, int> $map the `term_map` lines of this taxonomy, source id => the source id it goes into
     * @return array{
     *   terms: array<int, array{id: int, slug: string, name: string, link: string, parent: int, count: int, description: string, aliases: int[], alias_links: string[]}>,
     *   merges: array<int, array{from: int, from_name: string, into: int, into_name: string, why: string}>,
     *   skipped: array<int, array{id: int, name: string}>,
     *   unknown: array<int, array{from: int, into: int}>
     * }
     */
    public static function apply(array $terms, array $map, bool $skipEmpty): array
    {
        $byId = [];
        foreach ($terms as $term) {
            $byId[(int)$term['id']] = $term;
        }
        ksort($byId);

        // 1. The profile's lines.
        $into = [];
        $why = [];
        $unknown = [];
        ksort($map);
        foreach ($map as $from => $to) {
            $from = (int)$from;
            $to = (int)$to;
            if (!isset($byId[$from]) || !isset($byId[$to])) {
                $unknown[] = ['from' => $from, 'into' => $to];
                continue;
            }
            if ($from !== $to) {
                $into[$from] = $to;
                $why[$from] = 'term_map';
            }
        }
        // The lines that send terms round in a circle are dropped, every line of the circle.
        $circle = [];
        foreach (array_keys($into) as $from) {
            $path = [$from => true];
            for ($at = $into[$from]; isset($into[$at]); $at = $into[$at]) {
                if (isset($path[$at])) {
                    $circle[$at] = true;
                    for ($c = $into[$at]; $c !== $at; $c = $into[$c]) {
                        $circle[$c] = true;
                    }
                    break;
                }
                $path[$at] = true;
            }
        }
        ksort($circle);
        foreach (array_keys($circle) as $from) {
            $unknown[] = ['from' => $from, 'into' => $into[$from]];
        }
        foreach (array_keys($circle) as $from) {
            unset($into[$from], $why[$from]);
        }
        $final = static function (int $id) use (&$into): int {
            for ($guard = 0; isset($into[$id]) && $guard < 100; $guard++) {
                $id = $into[$id];
            }
            return $id;
        };

        // 2. The same name under the same parent.
        $groups = [];
        foreach ($byId as $id => $term) {
            if (isset($into[$id])) {
                continue;
            }
            $parent = (int)$term['parent'] > 0 && isset($byId[(int)$term['parent']]) ? $final((int)$term['parent']) : 0;
            $groups[$parent . "\0" . self::normalize((string)$term['name'])][] = $id;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            usort($ids, static fn(int $a, int $b): int => [(int)$byId[$b]['count'], $a] <=> [(int)$byId[$a]['count'], $b]);
            $keep = array_shift($ids);
            foreach ($ids as $id) {
                $into[$id] = $keep;
                $why[$id] = 'same name';
            }
        }

        // Who stays, and what each one took in.
        $aliases = [];
        $counts = [];
        foreach ($byId as $id => $term) {
            $to = $final($id);
            $counts[$to] = ($counts[$to] ?? 0) + max(0, (int)$term['count']);
            if ($to !== $id) {
                $aliases[$to][] = $id;
            }
        }

        // 3. Empty terms with no twin and nothing used below them.
        $skipped = [];
        $skip = [];
        if ($skipEmpty) {
            $parentOf = static function (int $id) use (&$byId, $final): int {
                $parent = (int)$byId[$id]['parent'];
                return $parent > 0 && $parent !== $id && isset($byId[$parent]) ? $final($parent) : 0;
            };
            $usedBelow = [];
            foreach ($byId as $id => $term) {
                if ($final($id) !== $id || ($counts[$id] ?? 0) === 0) {
                    continue;
                }
                $seen = [$id => true];
                for ($at = $parentOf($id); $at > 0 && !isset($seen[$at]); $at = $parentOf($at)) {
                    $usedBelow[$at] = true;
                    $seen[$at] = true;
                }
            }
            foreach ($byId as $id => $term) {
                if ($final($id) === $id && ($counts[$id] ?? 0) === 0 && empty($aliases[$id]) && empty($usedBelow[$id])) {
                    $skip[$id] = true;
                    $skipped[] = ['id' => $id, 'name' => (string)$term['name']];
                }
            }
        }

        $kept = [];
        foreach ($byId as $id => $term) {
            if ($final($id) !== $id || isset($skip[$id])) {
                continue;
            }
            $parent = (int)$term['parent'];
            $parent = $parent > 0 && $parent !== $id && isset($byId[$parent]) ? $final($parent) : 0;
            $list = $aliases[$id] ?? [];
            sort($list);
            $term['parent'] = $parent === $id || isset($skip[$parent]) ? 0 : $parent;
            $term['count'] = $counts[$id] ?? 0;
            $term['aliases'] = $list;
            $term['alias_links'] = array_values(array_filter(array_map(static fn(int $a): string => (string)$byId[$a]['link'], $list)));
            $kept[] = $term;
        }

        $merges = [];
        foreach ($byId as $id => $term) {
            $to = $final($id);
            if ($to !== $id) {
                $merges[] = ['from' => $id, 'from_name' => (string)$term['name'], 'into' => $to, 'into_name' => (string)$byId[$to]['name'], 'why' => $why[$id] ?? 'term_map'];
            }
        }
        return ['terms' => $kept, 'merges' => $merges, 'skipped' => $skipped, 'unknown' => $unknown];
    }

    /**
     * A name as it is compared: lower case, no accents or diaeresis, final sigma as σ, one space between words. "Παρατήρηση
     * Πτηνών" and "παρατηρηση πτηνων" are the same name; "Μινωική Βίλλα" and "Μινωική βίλα" are not (that takes a line).
     */
    public static function normalize(string $name): string
    {
        $text = mb_strtolower(trim($name), 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }
        $text = strtr($text, [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
