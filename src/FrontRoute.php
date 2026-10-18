<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Which kind of public page an address asks for: the sitemap, robots.txt, a feed, an old /pages/... address (sent to the
 * address it became), a list's page of the old WordPress kind (/category/news/page/2, sent to ?page=2), a category or tag
 * (also a child term: /category/books/english),
 * the search, the list of a content type, one entry of a type, or a page. The
 * language is read from the first part of the address. This only decides; finding the entry and drawing the page is
 * the caller's.
 */
final class FrontRoute
{
    /** The part of an address that names a language: "" for the default language, "en/" for another. */
    public static function langPrefix(string $lang, string $defaultLang): string
    {
        return $lang === $defaultLang ? '' : $lang . '/';
    }

    /**
     * @param array<string, mixed> $settings
     * @param string[] $types the content types of the site
     * @param string[] $taxonomies the taxonomies a site added: each has pages of its own at /<name>/<term>
     * @return array{kind: string, lang: string, segments: string[], lang_prefix: string, path_no_lang: string, home_slug: string, slug: string, type: string, location: string}
     *   kind is one of sitemap, robots, feed, legacy_page, taxonomy, search, archive, entry, page, not_found;
     *   a feed has the address it is the feed of in `segments` (none for the site's, ["posts"], ["category", "news"]).
     *   @param bool $feeds whether an address ending in /feed is a feed: false for an entry or page that has that very name
     */
    public static function resolve(string $path, array $settings, array $types, array $taxonomies = [], bool $feeds = true): array
    {
        $default = (string)($settings['languages']['default'] ?? 'en');
        $available = $settings['languages']['available'] ?? [];
        $homeSlug = (string)($settings['home_page'] ?? 'index');
        $segments = $path === '' ? [] : explode('/', $path);
        $lang = $default;
        if (isset($segments[0]) && in_array($segments[0], $available, true)) {
            $lang = array_shift($segments);
        }
        $prefix = self::langPrefix($lang, $default);
        $pathNoLang = implode('/', $segments);
        $route = [
            'kind' => 'page',
            'lang' => $lang,
            'segments' => $segments,
            'lang_prefix' => $prefix,
            'path_no_lang' => $pathNoLang === $homeSlug ? '' : $pathNoLang,
            'home_slug' => $homeSlug,
            'slug' => '',
            'type' => '',
            'location' => '',
        ];
        $first = $segments[0] ?? '';

        if ($path === 'sitemap.xml' || $path === 'robots.txt') {
            return ['kind' => $path === 'sitemap.xml' ? 'sitemap' : 'robots'] + $route;
        }
        // /feed, /posts/feed, /category/news/feed: the newest entries of the site, of a type, of a term.
        if ($feeds && end($segments) === 'feed') {
            $base = array_slice($segments, 0, -1);
            $of = $base === [] ? null : self::resolve(($lang === $default ? '' : $lang . '/') . implode('/', $base), $settings, $types, $taxonomies, false);
            if ($base === [] || ($of['kind'] === 'archive') || ($of['kind'] === 'taxonomy' && count($base) >= 2)) {
                return ['kind' => 'feed', 'segments' => $base] + $route;
            }
        }
        // WordPress lists a page of a list as /category/news/page/2; here it is ?page=2 (the first page is the list itself).
        $last = count($segments);
        if ($last >= 3 && $segments[$last - 2] === 'page' && preg_match('/^[1-9]\d{0,5}$/', $segments[$last - 1]) === 1) {
            $base = array_slice($segments, 0, -2);
            $of = self::resolve(($lang === $default ? '' : $lang . '/') . implode('/', $base), $settings, $types, $taxonomies, $feeds);
            if (in_array($of['kind'], ['archive', 'taxonomy'], true)) {
                $number = (int)$segments[$last - 1];
                return ['kind' => 'legacy_page', 'location' => '/' . $prefix . implode('/', $base) . ($number > 1 ? '?page=' . $number : '')] + $route;
            }
        }
        // An address names one thing and nothing after it: a page, a list, an entry (type/slug), a term (taxonomy/term), the
        // search. A longer one (/about/anything) is not that thing, so it is a not found rather than a second address for it.
        $count = count($segments);
        $notFound = ['kind' => 'not_found'] + $route;
        if ($first === 'pages') {
            if ($count > 2) {
                return $notFound;
            }
            $slug = $segments[1] ?? '';
            return ['kind' => 'legacy_page', 'location' => '/' . $prefix . ($slug !== '' && $slug !== $homeSlug ? $slug : '')] + $route;
        }
        if (in_array($first, ['tag', 'tags', 'category', 'categories'], true) || ($count > 1 && in_array($first, $taxonomies, true) && !in_array($first, $types, true))) {
            // A term of a taxonomy with a tree is at /category/parent/child, as deep as the tree goes (the page finds out whether it is there).
            return ['kind' => 'taxonomy'] + $route;
        }
        if ($first === 'search') {
            return $count > 1 ? $notFound : ['kind' => 'search'] + $route;
        }
        if ($first !== '' && in_array($first, $types, true)) {
            if ($count > 2) {
                return $notFound;
            }
            $slug = $segments[1] ?? '';
            return ['kind' => $slug === '' ? 'archive' : 'entry', 'type' => $first, 'slug' => $slug] + $route;
        }
        if ($count > 1) {
            return $notFound;
        }
        return ['slug' => $first !== '' ? $first : $homeSlug] + $route;
    }
}
