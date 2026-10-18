<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The blocks of Theme > Footer Blocks, which show above the footer. They are stored in the theme settings as `footer_blocks`:
 * a plain list (one set for every language) or a map with a set for each language, the way a translatable text is kept: `default`
 * is the set of the site's own language and a language code has the set of that language. A language without a set of its own
 * shows the default one. This is only the shape; the blocks themselves are checked by the block registry.
 */
final class FooterBlocks
{
    /**
     * The blocks the pages of a language show.
     *
     * @return array<int, mixed>
     */
    public static function forLanguage(mixed $stored, string $lang, string $defaultLang): array
    {
        if (!is_array($stored)) {
            return [];
        }
        if (array_is_list($stored)) {
            return $stored;
        }
        $own = $lang !== $defaultLang ? ($stored[$lang] ?? null) : null;
        if (is_array($own) && $own !== []) {
            return array_values($own);
        }
        return is_array($stored['default'] ?? null) ? array_values($stored['default']) : [];
    }

    /**
     * A set for each key the editor shows (`default`, then a code for each other language), empty when there is nothing.
     *
     * @param string[] $keys
     * @return array<string, array<int, mixed>>
     */
    public static function sets(mixed $stored, array $keys): array
    {
        $sets = [];
        foreach ($keys as $key) {
            if (is_array($stored) && array_is_list($stored)) {
                $set = $key === 'default' ? $stored : [];
            } else {
                $set = is_array($stored) && is_array($stored[$key] ?? null) ? $stored[$key] : [];
            }
            $sets[$key] = array_values($set);
        }
        return $sets;
    }

    /**
     * What to keep for the sets that were sent: empty sets are dropped, and only the site's own set is a plain list.
     *
     * @param array<string, array<int, mixed>> $sets
     * @return array<int|string, mixed> empty for no blocks at all
     */
    public static function toStore(array $sets): array
    {
        $sets = array_filter($sets, static fn(array $set): bool => $set !== []);
        if ($sets === []) {
            return [];
        }
        return array_keys($sets) === ['default'] ? array_values($sets['default']) : $sets;
    }

    /**
     * The sets of a request: a map of key to blocks (what the editor sends when the site has several languages) or a list (one
     * language: the default set). Sets of languages the editor did not send are the ones stored now, so a site that went down to
     * one language and back does not lose them.
     *
     * @param array<int|string, mixed> $posted
     * @return array<string, array<int, mixed>>
     */
    public static function fromPost(array $posted, mixed $stored): array
    {
        $sets = array_is_list($posted) ? ['default' => $posted] : $posted;
        $kept = is_array($stored) && !array_is_list($stored) ? $stored : [];
        foreach ($kept as $key => $set) {
            if (!array_key_exists((string)$key, $sets) && is_array($set)) {
                $sets[(string)$key] = $set;
            }
        }
        $clean = [];
        foreach ($sets as $key => $set) {
            $key = (string)$key;
            if (($key === 'default' || preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $key)) && is_array($set)) {
                $clean[$key] = array_values($set);
            }
        }
        return $clean;
    }
}
