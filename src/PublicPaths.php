<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the site's public addresses are: which paths lead to a real page, a list of every published address with its
 * title (for suggestions and pickers), the closest existing address to one that was not found, and how a pasted
 * address of this very site becomes a path.
 */
final class PublicPaths
{
    /**
     * @param callable(): Taxonomies $taxonomies
     * @param callable(): array<string, mixed> $settings the site settings, read each time
     * @param callable(): string $baseUrl the address of the site, as the visitor sees it
     */
    public function __construct(
        private ContentRepository $content,
        private $taxonomies,
        private $settings,
        private $baseUrl
    ) {
    }

    /** @return array<string, string> public path (no leading slash) => title, for published content in every language */
    public function map(): array
    {
        $settings = ($this->settings)();
        $homeSlug = (string)(($settings['home_page'] ?? '') !== '' ? $settings['home_page'] : 'index');
        $defaultLang = (string)($settings['languages']['default'] ?? 'en');
        $map = [];
        foreach ($this->content->getTypes() as $type) {
            if ($type === 'forms') {
                continue;
            }
            foreach ($this->content->getItems($type, null, false, false) as $item) {
                $path = ContentPaths::build($type, $item->slug, $item->lang, $homeSlug, $defaultLang);
                $map[$path] = (string)($item->meta['title'] ?? $item->slug);
            }
        }
        ksort($map);
        return $map;
    }

    /** Whether a visitor asking for this path (normalised, no leading slash) gets a real page. */
    public function exists(string $path): bool
    {
        if ($path === '' || in_array($path, ['sitemap.xml', 'robots.txt'], true)) {
            return true;
        }
        [$lang, $segments] = $this->split($path);
        if ($segments === []) {
            return true;
        }
        $first = $segments[0];
        if (in_array($first, ['search', 'pages'], true)) {
            return true;
        }
        if (in_array($first, ['tag', 'tags', 'category', 'categories'], true)) {
            return isset($segments[1]) && ($this->taxonomies)()->findByPath(in_array($first, ['category', 'categories'], true) ? 'categories' : 'tags', implode('/', array_slice($segments, 1))) !== null;
        }
        if (in_array($first, $this->content->getTypes(), true)) {
            if (count($segments) === 1) {
                return true;
            }
            return count($segments) === 2 && $this->content->find($first, $segments[1], $lang, false, false) !== null;
        }
        if (count($segments) !== 1) {
            return false;
        }
        foreach (ContentPaths::ROOT_TYPES as $type) {
            if (($type === 'pages' || in_array($type, $this->content->getTypes(), true)) && $this->content->find($type, $first, $lang, false, false) !== null) {
                return true;
            }
        }
        return false;
    }

    /** The existing address most like one that was not found, or '' when nothing is close. @param array<string, string> $known */
    public static function suggest(string $missing, array $known): string
    {
        $tail = basename($missing);
        $best = '';
        $bestScore = 0.0;
        foreach ($known as $path => $title) {
            $candidate = basename((string)$path);
            similar_text($tail, $candidate, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = (string)$path;
            }
        }
        return $bestScore >= 65 ? '/' . $best : '';
    }

    /** Turns a pasted address of this very site into a path, so it survives a change of domain. */
    public function localize(string $target): string
    {
        $target = trim($target);
        if (!RedirectRepository::isExternal($target)) {
            return $target;
        }
        $host = strtolower((string)(parse_url($target, PHP_URL_HOST) ?? ''));
        $own = [strtolower((string)(parse_url(($this->baseUrl)(), PHP_URL_HOST) ?? '')), strtolower((string)explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0])];
        if ($host === '' || !in_array($host, array_filter($own), true)) {
            return $target;
        }
        $path = (string)(parse_url($target, PHP_URL_PATH) ?? '/');
        $query = parse_url($target, PHP_URL_QUERY);
        $fragment = parse_url($target, PHP_URL_FRAGMENT);
        return ($path !== '' ? $path : '/') . ($query ? '?' . $query : '') . ($fragment ? '#' . $fragment : '');
    }

    /** @return array{0: string, 1: string[]} the language the path is in, and its segments without the language */
    private function split(string $path): array
    {
        $settings = ($this->settings)();
        $segments = $path === '' ? [] : explode('/', $path);
        $available = $settings['languages']['available'] ?? [];
        $lang = $settings['languages']['default'] ?? 'en';
        if (isset($segments[0]) && in_array($segments[0], $available, true)) {
            $lang = array_shift($segments);
        }
        return [$lang, $segments];
    }
}
