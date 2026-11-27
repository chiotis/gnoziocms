<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What a public page was made from, and what a request changed, so the built-in page cache (PageCache) can drop only the pages a
 * saved entry appears on instead of all of them (Settings > Cache > "Refresh only what changed").
 *
 * The places that read entries say what they read:
 * - `type:<type>` a list of a type (an archive, a term's page, a block listing entries, the sitemap, a feed, a menu built from
 *   pages): any entry of the type that changes may change it;
 * - `entry:<type>/<slug>` one entry, by its address (its own page, a page a route looked for);
 * - `id:<translation id>` one entry, by the id a relation field points at;
 * - `*` something made from the whole of the content (the maps' data, the search index): any change at all.
 *
 * The places that write entries (ContentIndex::upsert() and remove()) say what changed. Both are recorded for the whole request
 * (a few strings), so what a page reads before it starts to be kept counts too.
 */
final class CacheTags
{
    public const ALL = '*';

    /** @var array<string, true> */
    private static array $read = [];
    /** @var array<string, true> */
    private static array $changed = [];
    private static bool $removed = false;

    public static function readType(string $type): void
    {
        if ($type !== '') {
            self::$read['type:' . $type] = true;
        }
    }

    public static function readEntry(string $type, string $slug): void
    {
        if ($type !== '' && $slug !== '') {
            self::$read['entry:' . $type . '/' . $slug] = true;
        }
    }

    public static function readId(string $id): void
    {
        if ($id !== '') {
            self::$read['id:' . $id] = true;
        }
    }

    /** Something made from all of the content was used: any change makes the page old. */
    public static function readAll(): void
    {
        self::$read[self::ALL] = true;
    }

    /**
     * Runs `$read` without what it reads counting, for a caller that says itself, more narrowly, what it used (the links to the
     * translations of an entry read the whole type to find them, and depend only on those).
     *
     * @template T
     * @param \Closure(): T $read
     * @return T
     */
    public static function quietly(\Closure $read): mixed
    {
        $before = self::$read;
        try {
            return $read();
        } finally {
            self::$read = $before;
        }
    }

    /** @return list<string> what the page being kept was made from */
    public static function read(): array
    {
        $tags = array_keys(self::$read);
        sort($tags);
        return $tags;
    }

    /** An entry was written: its address, its type's lists, its id, and whatever depends on everything. */
    public static function changed(string $type, string $slug, string $translationId = ''): void
    {
        self::$changed['type:' . $type] = true;
        self::$changed['entry:' . $type . '/' . $slug] = true;
        if ($translationId !== '') {
            self::$changed['id:' . $translationId] = true;
        }
        self::$changed[self::ALL] = true;
    }

    /** An entry was taken away (deleted, or moved to another address): links and menus may have changed with it. */
    public static function removed(string $type, string $slug): void
    {
        self::changed($type, $slug);
        self::$removed = true;
    }

    /** @return list<string> the tags of what this request changed */
    public static function changes(): array
    {
        return array_keys(self::$changed);
    }

    /** Whether an entry was taken away in this request (then only a full purge is safe). */
    public static function anyRemoved(): bool
    {
        return self::$removed;
    }

    /** For tests: as at the start of a request. */
    public static function reset(): void
    {
        self::$read = [];
        self::$changed = [];
        self::$removed = false;
    }
}
