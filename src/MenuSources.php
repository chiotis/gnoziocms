<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What a menu can link to, as the menu editor offers it: the pages and entries of every content type, the lists of the
 * types, the categories and tags, and the home page. Each is a choice with the address a menu item would take (the
 * address without a language, as menus keep it) and its title in every language it exists in, so adding it also fills the
 * item's labels.
 */
final class MenuSources
{
    /** The most entries offered for one type; the editor has a search for the rest, and a link can be typed. */
    public const PER_GROUP = 400;

    /**
     * @param callable(): Taxonomies $taxonomies
     * @param callable(): array<string, mixed> $settings the site settings, read each time
     */
    public function __construct(private ContentRepository $content, private $taxonomies, private $settings)
    {
    }

    /**
     * @return array<int, array{id: string, label: string, items: array<int, array{title: string, url: string, labels: array<string, string>, detail: string, draft: bool}>, total: int}>
     */
    public function groups(): array
    {
        $settings = ($this->settings)();
        $defaultLang = (string)($settings['languages']['default'] ?? 'en');
        $languages = array_map('strval', $settings['languages']['available'] ?? [$defaultLang]);
        $homeSlug = (string)(($settings['home_page'] ?? '') !== '' ? $settings['home_page'] : 'index');
        $groups = [];
        $types = array_values(array_filter($this->content->getTypes(), static fn(string $type): bool => $type !== 'forms'));

        foreach ($types as $type) {
            $entries = $this->entries($type, $defaultLang, $languages, $homeSlug);
            $groups[] = ['id' => $type, 'label' => Slug::title($type), 'items' => array_slice($entries, 0, self::PER_GROUP), 'total' => count($entries)];
        }

        $lists = [['title' => 'Home', 'url' => '', 'labels' => [], 'detail' => 'Home page', 'draft' => false]];
        foreach ($types as $type) {
            if ($type !== 'pages') {
                $lists[] = ['title' => Slug::title($type), 'url' => $type, 'labels' => [], 'detail' => 'List of ' . strtolower(Slug::title($type)), 'draft' => false];
            }
        }
        $lists[] = ['title' => 'Search', 'url' => 'search', 'labels' => [], 'detail' => 'Search page', 'draft' => false];
        $groups[] = ['id' => 'lists', 'label' => 'Lists and special pages', 'items' => $lists, 'total' => count($lists)];

        $taxonomies = ($this->taxonomies)();
        foreach ($taxonomies->names() as $name) {
            $taxonomy = $taxonomies->load($name);
            $kind = Taxonomies::kind($name);
            $terms = [];
            foreach ($taxonomy['terms'] as $term) {
                $labels = [];
                foreach ($languages as $language) {
                    $labels[$language] = trim((string)($term['labels'][$language] ?? ''));
                }
                $slug = $taxonomies->slug($name, $term['id']);
                $terms[] = [
                    'title' => $taxonomies->label($name, $term['id'], $defaultLang),
                    'url' => $kind . '/' . $slug,
                    'labels' => $labels,
                    'detail' => match ($name) { 'categories' => 'Category', 'tags' => 'Tag', default => $taxonomy['title'] },
                    'draft' => false,
                ];
            }
            if ($terms !== []) {
                $groups[] = ['id' => 'taxonomy-' . $name, 'label' => $taxonomy['title'], 'items' => array_slice($terms, 0, self::PER_GROUP), 'total' => count($terms)];
            }
        }

        return $groups;
    }

    /**
     * The entries of a type, one for each entry whatever the languages it is written in.
     *
     * @param string[] $languages
     * @return array<int, array{title: string, url: string, labels: array<string, string>, detail: string, draft: bool}>
     */
    private function entries(string $type, string $defaultLang, array $languages, string $homeSlug): array
    {
        $byKey = [];
        foreach ($this->content->getItems($type, null, true) as $item) {
            $id = (string)($item->meta['translation_id'] ?? '');
            $key = $id !== '' ? 'id:' . $id : 'slug:' . $item->slug;
            $byKey[$key][$item->lang] = $item;
        }

        $entries = [];
        foreach ($byKey as $versions) {
            $main = $versions[$defaultLang] ?? reset($versions);
            if ($main === false) {
                continue;
            }
            $title = trim((string)($main->meta['title'] ?? '')) ?: $main->slug;
            $labels = [];
            foreach ($languages as $language) {
                $labels[$language] = isset($versions[$language]) ? trim((string)($versions[$language]->meta['title'] ?? '')) : '';
            }
            $status = strtolower((string)($main->meta['status'] ?? 'published'));
            $draft = $status !== 'published' || ($main->meta['visible'] ?? true) === false;
            $url = ContentPaths::build($type, $main->slug, $defaultLang, $homeSlug, $defaultLang);
            if ($url === '') {
                continue; // the home page is offered with the lists
            }
            $entries[] = [
                'title' => $title,
                'url' => $url,
                'labels' => $labels,
                'detail' => $draft ? ucfirst($status === 'published' ? 'hidden' : $status) : '',
                'draft' => $draft,
            ];
        }

        if ($type === 'pages') {
            usort($entries, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        }
        return $entries;
    }
}
