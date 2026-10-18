<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Where content lives on disk and on the site: file names and public paths, from the language and home page settings. */
final class ContentPaths
{
    /**
     * The content types whose entries live at the root of the site (/about, /my-post), so they share one set of addresses and
     * none of them can take a word the site already uses. Every other type has its address under its name (/projects/my-project).
     */
    public const ROOT_TYPES = ['pages', 'posts'];

    /** Whether the entries of a type live at the root of the site. */
    public static function isRoot(string $type): bool
    {
        return in_array($type, self::ROOT_TYPES, true);
    }

    public function __construct(private array $settings)
    {
    }

    public function defaultLang(): string
    {
        return (string)($this->settings['languages']['default'] ?? 'en');
    }

    public function homeSlug(): string
    {
        $home = (string)($this->settings['home_page'] ?? '');
        return $home !== '' ? $home : 'index';
    }

    /** "about.md" in the default language, "about.en.md" in another. */
    public function filename(string $slug, string $lang): string
    {
        if ($lang === $this->defaultLang() || $lang === '') {
            return $slug . '.md';
        }
        return $slug . '.' . $lang . '.md';
    }

    /** The public path (no leading slash) of an item: "about", "en/my-post", "projects/mine", "" for the home page. */
    public function publicPath(string $type, string $slug, string $lang): string
    {
        return self::build($type, $slug, $lang, $this->homeSlug(), $this->defaultLang());
    }

    public static function build(string $type, string $slug, string $lang, string $homeSlug, string $defaultLang): string
    {
        $prefix = $lang === $defaultLang ? '' : $lang . '/';

        if ($type === 'pages' && $slug === $homeSlug) {
            return rtrim($prefix, '/');
        }
        if (self::isRoot($type)) {
            return $prefix . $slug;
        }

        return $prefix . $type . '/' . $slug;
    }

    /** The public path (no leading slash) of the list of a content type: "posts", "en/posts". */
    public static function archive(string $type, string $lang, string $defaultLang): string
    {
        return ($lang === $defaultLang ? '' : $lang . '/') . $type;
    }
}
