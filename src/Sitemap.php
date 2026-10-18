<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The sitemap search engines read: the address and the last change of every public entry in every language, with the
 * picture of each, and the pages of the terms of the taxonomies that have entries. Left out: drafts and hidden entries,
 * entries (and whole content types) that ask search engines to skip them, the addresses Admin > SEO says to leave out,
 * and everything on a site that asked to stay out of search. The choices are the site's SEO settings.
 */
final class Sitemap
{
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     * @param (\Closure(string): array<int, array{path: string, lastmod: int}>)|null $terms the term pages of a language that have entries
     */
    public function __construct(private ContentRepository $content, private \Closure $settings, private \Closure $absoluteUrl, private ?\Closure $terms = null)
    {
    }

    /** Whether the site has a sitemap: it can be switched off, and a site that asked to stay out of search has none. */
    public function enabled(): bool
    {
        $seo = SeoSettings::from(($this->settings)());
        return $seo['sitemap']['enabled'] && !$seo['discourage'];
    }

    /** @return array<int, array{loc: string, lastmod: string, images: string[]}> */
    public function entries(): array
    {
        $paths = new ContentPaths(($this->settings)());
        $settings = ($this->settings)();
        $seo = SeoSettings::from($settings);
        if (!$this->enabled()) {
            return [];
        }
        $default = $paths->defaultLang();
        $available = $settings['languages']['available'] ?? [$default];

        $entries = [];
        foreach ($this->content->getTypes() as $type) {
            foreach ($available as $lang) {
                foreach ($this->content->getItems($type, $lang, false) as $item) {
                    $key = $lang . '|' . $type . '|' . $item->slug;
                    $path = $paths->publicPath($type, $item->slug, (string)$lang);
                    if (isset($entries[$key]) || !SeoSettings::indexable($seo, $type, $item->meta) || $this->excluded($seo, $path, (string)$lang, (string)$default)) {
                        continue;
                    }
                    $image = trim((string)($item->meta['main_image'] ?? ''));
                    $entries[$key] = [
                        'loc' => ($this->absoluteUrl)($path),
                        'lastmod' => date('c', $item->mtime),
                        'images' => $seo['sitemap']['images'] && $image !== '' ? [preg_match('#^https?://#i', $image) ? $image : ($this->absoluteUrl)($image)] : [],
                    ];
                }
            }
        }
        if ($this->terms !== null && $seo['sitemap']['taxonomies'] && !$seo['noindex_taxonomies']) {
            foreach ($available as $lang) {
                foreach (($this->terms)((string)$lang) as $term) {
                    if (!$this->excluded($seo, $term['path'], (string)$lang, (string)$default)) {
                        $entries['term|' . $term['path']] = ['loc' => ($this->absoluteUrl)($term['path']), 'lastmod' => date('c', $term['lastmod']), 'images' => []];
                    }
                }
            }
        }
        return array_values($entries);
    }

    /** Whether a rule of "leave out" matches the address, with or without the language the address starts with. */
    private function excluded(array $seo, string $path, string $lang, string $default): bool
    {
        if (SeoSettings::excluded($seo, $path)) {
            return true;
        }
        return $lang !== $default && SeoSettings::excluded($seo, (string)preg_replace('#^/?' . preg_quote($lang, '#') . '(?=/|$)#', '', $path));
    }

    public function xml(): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n";
        foreach ($this->entries() as $entry) {
            $xml .= "  <url>\n"
                . '    <loc>' . htmlspecialchars($entry['loc'], ENT_QUOTES) . "</loc>\n"
                . '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_QUOTES) . "</lastmod>\n"
                . implode('', array_map(static fn(string $image): string => '    <image:image><image:loc>' . htmlspecialchars($image, ENT_QUOTES) . "</image:loc></image:image>\n", $entry['images']))
                . "  </url>\n";
        }
        return $xml . "</urlset>\n";
    }
}
