<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The public page of one term of a taxonomy (a category or a tag): the entries filed under it, from the content
 * types its archive settings allow, newest first, laid out as the taxonomy's archive says (Admin > Taxonomies), with
 * its title, subtitle and description. The class works out what the page shows; the caller renders it.
 */
final class TaxonomyPage
{
    /**
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(): ArchiveBuilder $archive
     * @param \Closure(): LanguageAlternates $alternates
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string, ?string): string $translate a theme string, with what to show when the theme has none
     */
    public function __construct(
        private ContentRepository $content,
        private Theme $theme,
        private \Closure $taxonomies,
        private \Closure $archive,
        private \Closure $alternates,
        private \Closure $settings,
        private \Closure $translate
    ) {
    }

    /**
     * @param string[] $segments the address after the language: ["category", "news"]
     * @param array<string, mixed> $viewDefaults what every public page gets (language, canonical address, menus)
     * @param array<string, mixed> $query the address's query (page, search, sort)
     * @return array{kind: string, slug: string, data: array<string, mixed>}|null null when there is no such term
     */
    public function build(array $segments, string $lang, array $viewDefaults, array $query): ?array
    {
        // The address after the word: one slug, or a child's with its parents' ("books/english").
        $slug = implode('/', array_slice($segments, 1));
        if ($slug === '') {
            return null;
        }
        $taxonomies = ($this->taxonomies)();
        $key = $taxonomies->fromWord((string)($segments[0] ?? ''));
        if ($key === null) {
            return null;
        }
        $kind = Taxonomies::kind($key);
        $term = $taxonomies->findByPath($key, $slug);
        if ($term === null) {
            return null;
        }
        $settings = ($this->settings)();
        $defaultLang = (string)($settings['languages']['default'] ?? 'en');
        $termId = $term['id'];
        $label = $taxonomies->label($key, $termId, $lang);

        // How this taxonomy lists its entries is its own choice (Admin > Taxonomies), the same options a content type has.
        $archive = $taxonomies->archive($key, $lang, $defaultLang);
        $archive['taxonomies'] = array_values(array_diff($archive['taxonomies'], [$key]));
        $includeTypes = $archive['types'];

        // The page of a parent also lists what is filed under its sub-terms, unless the taxonomy's archive says not to.
        $termIds = [$termId];
        if ($archive['include_children']) {
            $termIds = array_merge($termIds, $taxonomies->descendantIds($key, $termId));
        }
        $types = array_values(array_filter($this->content->getTypes(), static fn(string $type): bool => !in_array($type, ['pages', 'forms'], true) && ($includeTypes === [] || in_array($type, $includeTypes, true))));
        // The content index answers when it can (only the entries on the page are read); otherwise every entry is looked at.
        $page = ($this->archive)()->buildIndexed(['types' => $types, 'term' => ['taxonomy' => $key, 'ids' => $termIds]], false, $archive, [], null, $lang, $query);
        if ($page === null) {
            $items = [];
            foreach ($types as $type) {
                foreach ($this->content->getItems($type, $lang, false, false) as $item) {
                    if (array_intersect($termIds, Format::list($item->meta[$key] ?? null)) !== []) {
                        $items[] = $item;
                    }
                }
            }
            // Entries of several types come newest first, as within a single type.
            $when = static fn(ContentItem $i): int => strtotime((string)($i->meta['date'] ?? '')) ?: $i->mtime;
            usort($items, static fn(ContentItem $a, ContentItem $b): int => $when($b) <=> $when($a));
            $page = ($this->archive)()->build($archive, [], null, $lang, $items, $query);
        }
        if ($page['page'] > 1 && !$page['filtered']) {
            $viewDefaults['canonical_url'] = ($viewDefaults['canonical_url'] ?? '') . '?page=' . $page['page'];
        }

        $fill = static fn(string $text): string => str_replace('{term}', $label, $text);
        $titlePrefix = match ($key) {
            'categories' => ($this->translate)('taxonomy.category', 'Category'),
            'tags' => ($this->translate)('taxonomy.tag', 'Tag'),
            default => $taxonomies->load($key)['title'],
        };
        $description = $taxonomies->description($key, $termId, $lang, $defaultLang);
        $alternates = ($this->alternates)()->forTaxonomy($key, $taxonomies->slug($key, $termId));

        // The trail follows the tree: each parent above the term, with the address of its page.
        $prefix = FrontRoute::langPrefix($lang, $defaultLang);
        $trail = [];
        foreach ($taxonomies->ancestors($key, $termId) as $above) {
            $trail[] = ['label' => $taxonomies->label($key, $above['id'], $lang), 'url' => '/' . $prefix . $kind . '/' . $taxonomies->slug($key, $above['id'])];
        }

        return ['kind' => $kind, 'slug' => $term['slug'], 'data' => [
            'items' => $page['items'],
            'archive' => $page,
            'type' => $key,
            'archive_title' => $archive['title'] !== '' ? $fill($archive['title']) : $titlePrefix . ': ' . $label,
            'archive_subtitle' => $archive['subtitle'] !== '' ? $fill($archive['subtitle']) : $description,
            'term_description' => $description,
            'term_trail' => $trail,
            'block_styles' => [$this->theme->blockStylesheetUrl(rtrim((string)($settings['base_url'] ?? ''), '/'), ['latest'])],
            'alternate_urls' => $alternates['urls'],
            'alternate_default' => $alternates['default'],
            'noindex_page' => $page['filtered'],
            'seo_kind' => 'taxonomy',
        ] + $viewDefaults];
    }
}
