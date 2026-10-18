<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Matching text and display text stay separate: snippets keep the spelling the author wrote. */
final class SearchText
{
    private static ?array $folds = null;

    /** Bound public input, discard malformed UTF-8 and controls, and keep ordinary punctuation for display. */
    public static function query(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = mb_convert_encoding(substr($value, 0, 2000), 'UTF-8', 'UTF-8');
        $value = preg_replace('/[\p{Z}\s\p{C}]+/u', ' ', $value) ?? '';
        return trim(mb_substr($value, 0, 200, 'UTF-8'));
    }

    /** Greek (including polytonic), final sigma and common Latin accents work without the optional intl extension. */
    public static function normalize(string $text): string
    {
        if (self::$folds === null) {
            self::$folds = ['ς' => 'σ'];
            foreach ([
                'α' => 'άἀἁἂἃἄἅἆἇὰάᾀᾁᾂᾃᾄᾅᾆᾇᾰᾱᾲᾳᾴᾶᾷ',
                'ε' => 'έἐἑἒἓἔἕὲέ',
                'η' => 'ήἠἡἢἣἤἥἦἧὴήᾐᾑᾒᾓᾔᾕᾖᾗῂῃῄῆῇ',
                'ι' => 'ίϊΐἰἱἲἳἴἵἶἷὶίῐῑῒΐῖῗ',
                'ο' => 'όὀὁὂὃὄὅὸό',
                'υ' => 'ύϋΰὐὑὒὓὔὕὖὗὺύῠῡῢΰῦῧ',
                'ω' => 'ώὠὡὢὣὤὥὦὧὼώᾠᾡᾢᾣᾤᾥᾦᾧῲῳῴῶῷ',
                'ρ' => 'ῤῥ',
                'a' => 'àáâãäåāăą', 'e' => 'èéêëēĕėęě', 'i' => 'ìíîïĩīĭįı',
                'o' => 'òóôõöøōŏő', 'u' => 'ùúûüũūŭůűų', 'y' => 'ýÿŷ',
                'c' => 'çćĉċč', 'n' => 'ñńņň', 's' => 'śŝşš', 'z' => 'źżž',
                'l' => 'ĺļľł', 'r' => 'ŕŗř', 'd' => 'ďđ', 't' => 'ţť', 'g' => 'ĝğġģ',
            ] as $base => $letters) {
                foreach (mb_str_split($letters, 1, 'UTF-8') as $letter) {
                    self::$folds[$letter] = $base;
                }
            }
        }
        return preg_replace('/\p{M}+/u', '', strtr(mb_strtolower($text, 'UTF-8'), self::$folds)) ?? '';
    }

    /** All words must match; their order does not matter and punctuation is a word separator. @return string[] */
    public static function terms(string $query): array
    {
        return array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u', self::normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** @param string[] $terms @return array<int, array{text: string, match: bool}> Plain text only; Twig escapes every part. */
    public static function parts(string $text, array $terms): array
    {
        if ($text === '' || $terms === []) {
            return $text === '' ? [] : [['text' => $text, 'match' => false]];
        }
        $normal = self::normalize($text);
        $ranges = [];
        foreach ($terms as $term) {
            $offset = 0;
            while ($term !== '' && ($at = strpos($normal, $term, $offset)) !== false) {
                $ranges[] = [$at, $at + strlen($term)];
                $offset = $at + strlen($term);
            }
        }
        if ($ranges === []) {
            return [['text' => $text, 'match' => false]];
        }
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($end, $merged[$last][1]);
            } else {
                $merged[] = [$start, $end];
            }
        }
        $original = self::originalRanges($text, $merged);
        $parts = [];
        $cursor = 0;
        foreach ($original as [$start, $end]) {
            if ($start > $cursor) {
                $parts[] = ['text' => substr($text, $cursor, $start - $cursor), 'match' => false];
            }
            $parts[] = ['text' => substr($text, $start, $end - $start), 'match' => true];
            $cursor = $end;
        }
        if ($cursor < strlen($text)) {
            $parts[] = ['text' => substr($text, $cursor), 'match' => false];
        }
        return $parts;
    }

    /** @param string[] $terms */
    public static function snippet(string $text, array $terms, int $length = 220): string
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        $normal = self::normalize($text);
        $first = null;
        foreach ($terms as $term) {
            $at = strpos($normal, $term);
            if ($at !== false && ($first === null || $at < $first)) {
                $first = $at;
            }
        }
        $offset = $first === null ? 0 : self::originalRanges($text, [[$first, $first + 1]])[0][0];
        $start = max(0, mb_strlen(substr($text, 0, $offset), 'UTF-8') - 55);
        $start = min($start, mb_strlen($text, 'UTF-8') - $length);
        // Start at a word when possible, while keeping the match comfortably inside the excerpt.
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start, 'UTF-8');
            if ($space !== false && $space < $start + 35) {
                $start = $space + 1;
            }
        }
        $snippet = mb_substr($text, $start, $length, 'UTF-8');
        $more = $start + $length < mb_strlen($text, 'UTF-8');
        if ($more) {
            $space = mb_strrpos($snippet, ' ', 0, 'UTF-8');
            if ($space !== false && $space > $length - 35) {
                $snippet = mb_substr($snippet, 0, $space, 'UTF-8');
            }
        }
        return ($start > 0 ? '… ' : '') . trim($snippet) . ($more ? ' …' : '');
    }

    /** Map normalized byte positions back to whole original graphemes, keeping accents attached to highlighted letters. */
    private static function originalRanges(string $text, array $ranges): array
    {
        preg_match_all('/\X/u', $text, $clusters, PREG_OFFSET_CAPTURE);
        $normalOffset = 0;
        $i = 0;
        $original = [];
        $start = null;
        foreach ($clusters[0] as [$cluster, $offset]) {
            $next = $normalOffset + strlen(self::normalize($cluster));
            if ($start === null && $next > $ranges[$i][0]) {
                $start = $offset;
            }
            if ($start !== null && $next >= $ranges[$i][1]) {
                $original[] = [$start, $offset + strlen($cluster)];
                $start = null;
                $i++;
                if ($i >= count($ranges)) {
                    break;
                }
            }
            $normalOffset = $next;
        }
        return $original;
    }
}
