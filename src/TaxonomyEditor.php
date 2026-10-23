<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Taxonomies screen does with a submitted form: the terms and the page layout are checked and saved, a term
 * whose address changed leaves the old one behind as a redirect (in every language) and its links in menus are
 * updated, and the entries filed under each term are counted.
 */
final class TaxonomyEditor
{
    /** Types whose entries are never filed under a term. */
    private const UNFILED_TYPES = ['pages', 'forms'];

    /**
     * @param callable(): Menus $menus
     * @param callable(string, string, ?string, ?string, string, array<string, mixed>): void|null $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(
        private Taxonomies $store,
        private RedirectRepository $redirects,
        private ContentRepository $content,
        private $menus,
        private $log = null
    ) {
    }

    /** The taxonomy asked for, or the first one when it is not a taxonomy of the site. */
    public function selected(string $requested): string
    {
        $names = $this->store->names();
        $name = Slug::plain($requested);
        return in_array($name, $names, true) ? $name : ($names[0] ?? 'tags');
    }

    /**
     * Saves the submitted form and says where to go next.
     *
     * @param array<string, mixed> $post
     * @param string[] $languages
     */
    public function save(string $taxonomy, array $post, array $languages, string $defaultLang, string $by): string
    {
        $result = $this->apply($taxonomy, $post, $languages, $defaultLang, $by);
        if ($this->log !== null) {
            ($this->log)('taxonomies.update', 'info', 'taxonomy', $taxonomy, 'Taxonomy updated.', [
                'title' => $result['title'],
                'terms' => $result['terms'],
                'added' => $result['added'],
                'renamed' => $result['moved'],
                'removed' => $result['removed'],
                'refused' => $result['refused'],
            ]);
        }
        return '/admin/taxonomies?taxonomy=' . urlencode($taxonomy) . '&saved=1'
            . ($result['moved'] > 0 ? '&moved=' . $result['moved'] : '')
            . ($result['refused'] > 0 ? '&refused=' . $result['refused'] : '')
            . ($result['removed'] > 0 ? '&removed=' . $result['removed'] . '&orphaned=' . $result['orphaned'] : '');
    }

    /**
     * What the screen shows for one taxonomy: its terms with how many entries are filed under each, the tabs to
     * switch taxonomy, the page layout of its archive and the content types that can be filed.
     *
     * @param array<string, mixed> $get the address's query (what just happened)
     * @param string[] $languages
     * @param \Closure(string): string $typeLabel the name of a content type
     * @param array<string, string> $titleLayouts the styles of title area the theme offers (none: the term has no such choice)
     * @return array<string, mixed>
     */
    public function screen(string $taxonomy, array $get, array $languages, string $defaultLang, bool $canRedirects, \Closure $typeLabel, array $titleLayouts = []): array
    {
        $names = $this->store->names();
        $current = $this->store->load($taxonomy);
        $listable = $this->listableTypes();
        $usage = $this->usage($names, true);
        $terms = [];
        // A taxonomy with a tree is listed as a tree: each term followed by its children.
        foreach ($current['hierarchical'] ? $this->store->tree($taxonomy) : $current['terms'] as $term) {
            $byType = $usage[$taxonomy][$term['id']] ?? [];
            $term['used'] = array_sum($byType);
            $term['used_by_type'] = $byType;
            $term['depth'] ??= 0;
            $term['path'] = $this->store->slug($taxonomy, $term['id']);
            $terms[] = $term;
        }
        $tabs = [];
        foreach ($names as $name) {
            $loaded = $this->store->load($name);
            $tabs[] = [
                'name' => $name,
                'title' => $loaded['title'],
                'terms' => count($loaded['terms']),
                'filed' => array_sum(array_map('array_sum', $usage[$name] ?? [])),
            ];
        }
        $types = $this->content->getTypes();
        return [
            'types' => $types,
            'admin_section' => 'taxonomies',
            'taxonomy_names' => $names,
            'taxonomy_tabs' => $tabs,
            'taxonomy' => $taxonomy,
            'taxonomy_title' => $current['title'],
            'taxonomy_terms' => $terms,
            'taxonomy_kind' => Taxonomies::kind($taxonomy),
            'hierarchical' => $current['hierarchical'],
            'taxonomy_assign' => $current['assign'],
            'languages' => $languages,
            'default_language' => $defaultLang,
            'archive' => $this->store->archive($taxonomy, $defaultLang, $defaultLang),
            'layouts' => ContentTypes::LAYOUTS,
            'orders' => ContentTypes::ORDERS,
            'other_taxonomies' => array_values(array_diff($names, [$taxonomy])),
            'listable_types' => $listable,
            'type_labels' => array_combine($listable, array_map($typeLabel, $listable)),
            'type_names' => array_combine($types, array_map($typeLabel, $types)),
            'saved' => isset($get['saved']),
            'created' => isset($get['created']),
            'deleted' => trim((string)($get['deleted'] ?? '')),
            'is_custom' => Taxonomies::isCustom($taxonomy),
            'new_error' => trim((string)($get['new_error'] ?? '')),
            'moved' => (int)($get['moved'] ?? 0),
            'removed' => (int)($get['removed'] ?? 0),
            'orphaned' => (int)($get['orphaned'] ?? 0),
            'refused' => (int)($get['refused'] ?? 0),
            'can_redirects' => $canRedirects,
            'title_layouts' => $titleLayouts,
            'error' => '',
        ];
    }

    /**
     * How many entries (any type, any language, drafts too) are filed under each term, for each taxonomy, counted in
     * one pass.
     *
     * @param string[] $taxonomies
     * @return ($byType is true ? array<string, array<string, array<string, int>>> : array<string, array<string, int>>) taxonomy => term id => entries (or entries per content type)
     */
    public function usage(array $taxonomies, bool $byType = false): array
    {
        $counts = array_fill_keys($taxonomies, []);
        foreach ($this->content->getTypes() as $type) {
            if ($type === 'forms') {
                continue;
            }
            foreach ($this->content->getItems($type, null, true, false) as $item) {
                foreach ($taxonomies as $taxonomy) {
                    foreach (Format::list($item->meta[$taxonomy] ?? null) as $termId) {
                        $counts[$taxonomy][$termId][$type] = ($counts[$taxonomy][$termId][$type] ?? 0) + 1;
                    }
                }
            }
        }
        if ($byType) {
            return $counts;
        }
        return array_map(static fn(array $terms): array => array_map('array_sum', $terms), $counts);
    }

    /**
     * The archive settings to store for a taxonomy after a submitted form: only what differs from the defaults is written.
     *
     * @param array<string, mixed> $submitted the submitted `archive` values
     * @return array<string, mixed>
     */
    public function archiveFromInput(string $taxonomy, array $submitted): array
    {
        $current = $this->store->load($taxonomy);
        $listable = $this->listableTypes();
        $types = array_values(array_intersect($listable, Taxonomies::typeList($submitted['types'] ?? null)));
        $all = $types === [] || count($types) === count($listable);
        $archive = ContentTypes::archiveFromInput(
            $submitted,
            $current['archive'],
            // A taxonomy of places (with the types it lists after this save) opens as a map unless the site chose otherwise, so
            // choosing the Map stores nothing and Cards is kept.
            ContentTypes::resolveArchive($this->store->archiveDefaults($taxonomy, $all ? [] : $types), static fn(mixed $v): mixed => $v),
            array_values(array_diff($this->store->names(), [$taxonomy])),
            false
        );
        if ($all) {
            unset($archive['types']);
        } else {
            $archive['types'] = $types;
        }
        // Whether a term's page also lists its sub-terms' entries: on unless unchecked. Only a form that drew the choice (`children_shown`) decides.
        if (isset($submitted['children_shown'])) {
            if (FieldSchema::isTruthy($submitted['include_children'] ?? false)) {
                unset($archive['include_children']);
            } else {
                $archive['include_children'] = false;
            }
            // The chips of the sub-terms under the title of a term's page: on unless unchecked.
            if (FieldSchema::isTruthy($submitted['sub_term_chips'] ?? false)) {
                unset($archive['sub_term_chips']);
            } else {
                $archive['sub_term_chips'] = false;
            }
        }
        return $archive;
    }

    /** Saves the archive settings of a taxonomy, as submitted by Theme > Archive Layouts. The file is only written when something changed. */
    public function updateArchive(string $taxonomy, array $submitted): void
    {
        $current = $this->store->load($taxonomy);
        $archive = $this->archiveFromInput($taxonomy, $submitted);
        if ($archive == $current['archive']) {
            return;
        }
        $this->store->save($taxonomy, $current['title'], $current['terms'], $archive);
    }

    /**
     * Makes a new taxonomy. Its pages are at /<name>/<term>, so the name must not be an address the site already uses: a
     * reserved word, a language, a content type, another taxonomy, the home page or a page.
     *
     * @param string[] $types the content types its pages list (none: all of them)
     * @param string[] $languages
     * @param bool $hierarchical whether its terms can have a parent
     * @return array{name: string, error: string} the name made, or what is wrong with the one asked for
     */
    public function create(string $title, string $requestedName, array $types, array $languages, string $homeSlug, string $by, bool $hierarchical = false): array
    {
        $title = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $title) ?? '');
        if ($title === '') {
            return ['name' => '', 'error' => 'Give the taxonomy a name.'];
        }
        $requestedName = trim($requestedName);
        $name = Slug::plain($requestedName !== '' ? $requestedName : $title);
        if (!preg_match('/^[a-z][a-z0-9-]{1,39}$/', $name)) {
            return ['name' => '', 'error' => $requestedName !== ''
                ? 'The address needs 2 to 40 letters, numbers or hyphens, and starts with a letter.'
                : 'An address could not be made from that name. Type one with Latin letters, numbers or hyphens.'];
        }
        $taken = array_merge(ContentTypeAdmin::RESERVED_NAMES, $languages, $this->content->getTypes(), $this->store->names(), ['index', 'sitemap.xml', 'robots.txt', $homeSlug]);
        if (in_array($name, $taken, true)) {
            return ['name' => '', 'error' => 'The address /' . $name . ' is already used by the site. Choose another.'];
        }
        foreach (ContentPaths::ROOT_TYPES as $rootType) {
            if ($rootType !== 'pages' && !in_array($rootType, $this->content->getTypes(), true)) {
                continue;
            }
            foreach ($this->content->getItems($rootType, null, true) as $entry) {
                if ($entry->slug === $name) {
                    return ['name' => '', 'error' => 'There is ' . ($rootType === 'pages' ? 'a page' : 'a post') . ' at /' . $name . '. Choose another address, or rename it first.'];
                }
            }
        }
        $types = array_values(array_intersect($this->listableTypes(), array_map('strval', $types)));
        $this->store->save($name, $title, [], $types !== [] ? ['types' => $types] : null, $hierarchical);
        if ($this->log !== null) {
            ($this->log)('taxonomies.create', 'info', 'taxonomy', $name, 'Taxonomy created.', ['title' => $title, 'types' => $types, 'hierarchical' => $hierarchical]);
        }
        return ['name' => $name, 'error' => ''];
    }

    /**
     * Deletes a taxonomy the site added. Its terms and pages go; the entries keep the terms in their own files (they are
     * no longer shown), so nothing of the content is lost.
     *
     * @return array{ok: bool, title: string, filed: int} filed: how many entries had terms of it
     */
    public function delete(string $taxonomy, string $by): array
    {
        if (!Taxonomies::isCustom($taxonomy) || !in_array($taxonomy, $this->store->names(), true)) {
            return ['ok' => false, 'title' => '', 'filed' => 0];
        }
        $title = $this->store->load($taxonomy)['title'];
        $filed = array_sum($this->usage([$taxonomy])[$taxonomy] ?? []);
        $ok = $this->store->delete($taxonomy);
        if ($ok && $this->log !== null) {
            ($this->log)('taxonomies.delete', 'warning', 'taxonomy', $taxonomy, 'Taxonomy deleted.', ['title' => $title, 'filed' => $filed]);
        }
        return ['ok' => $ok, 'title' => $title, 'filed' => $filed];
    }

    /** The content types that can be listed on a taxonomy page. @return string[] */
    public function listableTypes(): array
    {
        return array_values(array_filter($this->content->getTypes(), static fn(string $type): bool => !in_array($type, self::UNFILED_TYPES, true)));
    }

    /**
     * The terms of a submitted form, in the order they were sent. A description box (or a title area choice, a picture, the
     * text for search engines) that was not sent at all leaves those of the terms alone (null); one that was sent empty
     * clears them.
     *
     * @param array<string, mixed> $post
     * @param string[] $languages
     * @return array<int, array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>|null, hero_layout: string|null, parent: string|null, image: string|null, image_alt: array<string, string>|null, seo_title: array<string, string>|null, seo_description: array<string, string>|null}>
     */
    public function rowsFromPost(array $post, array $languages): array
    {
        $list = static fn(string $key): array => is_array($post[$key] ?? null) ? $post[$key] : [];
        $ids = $list('term_id');
        $slugs = $list('term_slug');
        $labels = $list('term_label');
        $texts = $list('term_description');
        $heroes = $list('term_hero_layout');
        $parents = $list('term_parent');
        $images = $list('term_image');
        $imageAlts = $list('term_image_alt');
        $seoTitles = $list('term_seo_title');
        $seoDescriptions = $list('term_seo_description');
        $rows = [];
        for ($i = 0, $count = max(count($ids), count($slugs)); $i < $count; $i++) {
            $row = [
                'id' => trim((string)($ids[$i] ?? '')), 'slug' => (string)($slugs[$i] ?? ''), 'labels' => [],
                'descriptions' => $texts === [] ? null : [], 'hero_layout' => $heroes === [] ? null : trim((string)($heroes[$i] ?? '')),
                'parent' => $parents === [] ? null : trim((string)($parents[$i] ?? '')),
                'image' => $images === [] ? null : trim((string)($images[$i] ?? '')),
                'image_alt' => $imageAlts === [] ? null : [], 'seo_title' => $seoTitles === [] ? null : [], 'seo_description' => $seoDescriptions === [] ? null : [],
            ];
            foreach ($languages as $lang) {
                $row['labels'][$lang] = trim((string)($labels[$lang][$i] ?? ''));
                if ($texts !== []) {
                    $row['descriptions'][$lang] = trim((string)($texts[$lang][$i] ?? ''));
                }
                if ($imageAlts !== []) {
                    $row['image_alt'][$lang] = trim((string)($imageAlts[$lang][$i] ?? ''));
                }
                if ($seoTitles !== []) {
                    $row['seo_title'][$lang] = trim((string)($seoTitles[$lang][$i] ?? ''));
                }
                if ($seoDescriptions !== []) {
                    $row['seo_description'][$lang] = trim((string)($seoDescriptions[$lang][$i] ?? ''));
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Saves a submitted form for one taxonomy.
     *
     * @param array<string, mixed> $post
     * @param string[] $languages
     * @return array{title: string, terms: int, moved: int, removed: int, added: int, orphaned: int, refused: int}
     */
    public function apply(string $taxonomy, array $post, array $languages, string $defaultLang, string $by): array
    {
        $current = $this->store->load($taxonomy);
        $names = $this->store->names();
        $listable = $this->listableTypes();
        $title = trim((string)($post['taxonomy_title'] ?? $current['title']));
        // Whether terms can have a parent: a form that carries the choice decides, one without it leaves it as it is.
        $hierarchical = isset($post['taxonomy_hierarchical']) ? FieldSchema::isTruthy($post['taxonomy_hierarchical']) : $current['hierarchical'];
        $prepared = $this->store->prepare($taxonomy, $this->rowsFromPost($post, $languages), $defaultLang, $hierarchical);

        // How the pages of this taxonomy look is edited in Theme > Archive Layouts; a form without it leaves it alone.
        $archive = is_array($post['archive'] ?? null) ? $this->archiveFromInput($taxonomy, $post['archive']) : null;

        // The content types whose entries can be filed under it: none chosen means all of them, and a form without the choice leaves it alone.
        $assign = null;
        if (isset($post['taxonomy_assign_present'])) {
            $chosen = array_values(array_intersect($listable, Taxonomies::typeList($post['taxonomy_assign'] ?? null)));
            $assign = count($chosen) === count($listable) ? [] : $chosen;
        }

        $this->store->save($taxonomy, $title, $prepared['terms'], $archive, $hierarchical, $assign);

        // A term whose address changed leaves the old one behind, in every language, like a page does. Moving a term or
        // renaming a parent changes the address of everything below it too, so each of those is in the list.
        $kind = Taxonomies::kind($taxonomy);
        foreach ($prepared['moved'] as $move) {
            foreach ($languages as $lang) {
                $prefix = $lang === $defaultLang ? '' : $lang . '/';
                $this->redirects->moved($prefix . $kind . '/' . $move['from'], $prefix . $kind . '/' . $move['to'], $by, $taxonomy);
            }
            ($this->menus)()->relink($kind . '/' . $move['from'], $kind . '/' . $move['to']);
        }
        foreach (array_merge($prepared['added'], array_column($prepared['moved'], 'id')) as $termId) {
            foreach ($languages as $lang) {
                $this->redirects->removeSource(($lang === $defaultLang ? '' : $lang . '/') . $kind . '/' . $this->store->slug($taxonomy, $termId));
            }
        }

        $usage = $this->usage([$taxonomy])[$taxonomy];
        return [
            'title' => $title,
            'terms' => count($prepared['terms']),
            'moved' => count($prepared['moved']),
            'removed' => count($prepared['removed']),
            'added' => count($prepared['added']),
            'orphaned' => array_sum(array_map(static fn(string $id): int => $usage[$id] ?? 0, $prepared['removed'])),
            'refused' => count($prepared['refused']),
        ];
    }
}
