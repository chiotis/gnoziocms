<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * What the content list does with entries: which ones the list shows for a search, a status or a term, the bulk
 * actions (publish, move to draft, delete), and deleting one entry, which for a public one first asks whether
 * visitors of its address should be sent somewhere. Entries that are deleted stay in the history so they can be
 * brought back. The class never reads the request or checks permissions: the caller passes the submitted values and
 * what the person may do, and gets back a location to go to or the data of a page.
 */
final class ContentAdmin
{
    /** Entries per page in the list; 0 is every entry on one page. */
    public const PER_PAGE_OPTIONS = [25, 50, 100, 0];
    public const PER_PAGE_DEFAULT = 50;

    /** The filters behind "More filters" in the bar. */
    public const MORE_FILTERS = ['author', 'image', 'updated_from', 'updated_to', 'stock'];

    /** What the stock filter of products can ask for. */
    public const STOCK_FILTERS = ['in', 'low', 'out', 'untracked'];

    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(): PublicPaths $publicPaths
     * @param \Closure(): LinkScanner $linkScanner
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     * @param (\Closure(list<ContentItem>): array<string, list<string>>)|null $stockStates how each product stands (ShopStock::states) by translation id; null when the shop keeps no stock
     */
    public function __construct(
        private string $contentDir,
        private ContentRepository $content,
        private ContentIndex $index,
        private ContentTypes $types,
        private ContentEditor $editor,
        private EntryTranslations $translations,
        private RevisionRepository $revisions,
        private RedirectRepository $redirects,
        private \Closure $settings,
        private \Closure $taxonomies,
        private \Closure $publicPaths,
        private \Closure $linkScanner,
        private \Closure $log,
        private ?Relations $relations = null,
        private ?\Closure $stockStates = null
    ) {
    }

    /**
     * The list of one content type in one language.
     *
     * @param array<string, mixed> $get
     * @return array{location: string, data: array<string, mixed>}
     */
    public function listing(array $get, bool $canRedirects): array
    {
        $type = Slug::plain((string)($get['type'] ?? 'pages')) ?: 'pages';
        if ($type === 'forms') {
            return ['location' => '/admin/forms' . (isset($get['deleted']) ? '?deleted=1' : ''), 'data' => []];
        }
        $settings = ($this->settings)();
        $lang = Slug::plain((string)($get['lang'] ?? $this->defaultLang()));
        $types = $this->content->getTypes();
        if (!in_array($type, $types, true)) {
            $type = $types[0] ?? 'pages';
        }
        $matching = $this->matching($type, $lang, $get);
        $items = $matching['items'];
        $filters = $matching['filters'];
        $total = count($items);
        $perPage = self::perPage($get['per_page'] ?? null);
        $totalPages = $perPage === 0 ? 1 : max(1, (int)ceil($total / $perPage));
        $page = min(max(1, (int)($get['page'] ?? 1)), $totalPages);
        $offset = $perPage === 0 ? 0 : ($page - 1) * $perPage;
        if ($perPage !== 0) {
            $items = array_slice($items, $offset, $perPage);
        }
        $translationLangs = $this->translations->matrix($type, $items);

        return ['location' => '', 'data' => [
            'items' => $items,
            'types' => $types,
            'current_type' => $type,
            'lang' => $lang,
            'languages' => $settings['languages']['available'] ?? [],
            'admin_section' => 'content',
            'deleted' => isset($get['deleted']),
            'translation_langs' => $translationLangs,
            'filters' => $filters,
            'filters_active' => array_filter($filters, static fn(string $value): bool => $value !== '') !== [],
            'more_filters_active' => count(array_filter(array_intersect_key($filters, array_flip(self::MORE_FILTERS)), static fn(string $value): bool => $value !== '')),
            'filter_bar' => $matching['bar'],
            'status_options' => $matching['status_options'],
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'per_page_options' => self::PER_PAGE_OPTIONS,
            // The address of this list without the page: the pages, the languages and the page size link with it.
            'list_query' => self::listQuery($type, $lang, $filters, $perPage),
            'shown_from' => $total > 0 ? $offset + 1 : 0,
            'shown_to' => $offset + count($items),
            'bulk_status' => (string)($get['bulk'] ?? ''),
            'bulk_message' => trim((string)($get['bulk_msg'] ?? '')),
            'deleted_from' => trim((string)($get['from'] ?? '')),
            'deleted_to' => trim((string)($get['to'] ?? '')),
            'deleted_gone' => trim((string)($get['gone'] ?? '')),
            'can_redirects' => $canRedirects,
        ]];
    }

    /**
     * The query of the list with these filters and page size: empty filters and the default size are left out.
     *
     * @param array<string, string> $filters
     * @return array<string, string>
     */
    public static function listQuery(string $type, string $lang, array $filters, int $perPage): array
    {
        $query = ['type' => $type, 'lang' => $lang] + array_filter($filters, static fn(string $value): bool => $value !== '');
        if ($perPage !== self::PER_PAGE_DEFAULT) {
            $query['per_page'] = $perPage === 0 ? 'all' : (string)$perPage;
        }
        return $query;
    }

    /** The page size asked for: one of the options, "all" for every entry, else the default. */
    public static function perPage(mixed $value): int
    {
        if ($value === 'all' || $value === '0' || $value === 0) {
            return 0;
        }
        $value = is_numeric($value) ? (int)$value : 0;
        return $value > 0 && in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : self::PER_PAGE_DEFAULT;
    }

    /**
     * The entries of one type and language that the filters let through, newest first, with the filters as they were
     * understood (each a string, a list being comma-separated) and what the filter bar offers. The list pages through
     * them; "select all" in a bulk action takes all of them.
     *
     * The filters: q (title, slug or SKU), status (any of), in_<taxonomy> (any of its terms, a term taking in the terms
     * below it; different taxonomies must all match), author, image (with or without a main image), updated_from and
     * updated_to (the day it was last updated) and, for products when the shop keeps stock, stock (in, low, out or untracked). The link from
     * Taxonomies, taxonomy=…&term=…, is read as in_<taxonomy>.
     *
     * @param array<string, mixed> $get
     * @return array{items: list<ContentItem>, filters: array<string, string>, status_options: list<string>, bar: array<string, mixed>}
     */
    private function matching(string $type, string $lang, array $get): array
    {
        $all = $this->content->getItems($type, $lang, true, false);
        // Newest first by when each entry was made, so editing one does not move it (the site keeps its own order, by date).
        usort($all, static fn(ContentItem $a, ContentItem $b): int => $b->createdTime() <=> $a->createdTime());
        $statusOptions = array_values(array_unique(array_merge(['published', 'draft', ScheduledPublishing::STATUS], array_map(
            static fn(ContentItem $item): string => (string)($item->meta['status'] ?? 'published'),
            $all
        ))));
        $taxonomies = ($this->taxonomies)();
        $taxonomyNames = $this->filterTaxonomies($type);

        $filters = ['q' => trim((string)(is_scalar($get['q'] ?? null) ? $get['q'] : ''))];
        $statuses = array_values(array_intersect($statusOptions, self::values($get['status'] ?? null)));
        $filters['status'] = implode(',', $statuses);
        $chosenTerms = [];
        foreach ($taxonomyNames as $name) {
            $ids = array_column($taxonomies->load($name)['terms'], 'id');
            $picked = self::values($get['in_' . $name] ?? null);
            if (Slug::plain((string)(is_scalar($get['taxonomy'] ?? null) ? $get['taxonomy'] : '')) === $name) {
                $picked[] = Slug::plain((string)(is_scalar($get['term'] ?? null) ? $get['term'] : ''));
            }
            $picked = array_values(array_intersect($ids, $picked));
            $filters['in_' . $name] = implode(',', $picked);
            if ($picked !== []) {
                $chosenTerms[$name] = $picked;
            }
        }
        $authors = array_values(array_unique(array_filter(array_map(static fn(ContentItem $item): string => trim((string)($item->meta['author'] ?? '')), $all))));
        sort($authors, SORT_NATURAL | SORT_FLAG_CASE);
        $author = trim((string)(is_scalar($get['author'] ?? null) ? $get['author'] : ''));
        $filters['author'] = in_array($author, $authors, true) ? $author : '';
        $image = (string)(is_scalar($get['image'] ?? null) ? $get['image'] : '');
        $filters['image'] = in_array($image, ['with', 'without'], true) ? $image : '';
        foreach (['updated_from', 'updated_to'] as $key) {
            $day = (string)(is_scalar($get[$key] ?? null) ? $get[$key] : '');
            $filters[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && strtotime($day) !== false ? $day : '';
        }
        $stockStates = $type === Shop\Shop::TYPE && $this->stockStates !== null ? ($this->stockStates)($all) : null;
        $stock = (string)(is_scalar($get['stock'] ?? null) ? $get['stock'] : '');
        $filters['stock'] = $stockStates !== null && in_array($stock, self::STOCK_FILTERS, true) ? $stock : '';

        // A term takes in the terms below it.
        $wanted = [];
        foreach ($chosenTerms as $name => $ids) {
            $expanded = $ids;
            if ($taxonomies->isHierarchical($name)) {
                foreach ($ids as $id) {
                    $expanded = array_merge($expanded, $taxonomies->descendantIds($name, $id));
                }
            }
            $wanted[$name] = array_values(array_unique($expanded));
        }
        $needle = mb_strtolower($filters['q']);
        $from = $filters['updated_from'] !== '' ? (int)strtotime($filters['updated_from'] . ' 00:00:00') : null;
        $to = $filters['updated_to'] !== '' ? (int)strtotime($filters['updated_to'] . ' 23:59:59') : null;
        $items = array_values(array_filter($all, static function (ContentItem $item) use ($needle, $statuses, $wanted, $filters, $from, $to, $stockStates): bool {
            if ($needle !== '') {
                $custom = is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
                $haystack = mb_strtolower((string)($item->meta['title'] ?? '') . ' ' . $item->slug . ' ' . (is_scalar($custom['sku'] ?? null) ? $custom['sku'] : ''));
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }
            if ($statuses !== [] && !in_array((string)($item->meta['status'] ?? 'published'), $statuses, true)) {
                return false;
            }
            foreach ($wanted as $name => $ids) {
                if (array_intersect($ids, Format::list($item->meta[$name] ?? null)) === []) {
                    return false;
                }
            }
            if ($filters['author'] !== '' && trim((string)($item->meta['author'] ?? '')) !== $filters['author']) {
                return false;
            }
            if ($filters['image'] !== '' && (trim((string)($item->meta['main_image'] ?? '')) !== '') !== ($filters['image'] === 'with')) {
                return false;
            }
            if (($from !== null && $item->mtime < $from) || ($to !== null && $item->mtime > $to)) {
                return false;
            }
            if ($filters['stock'] !== '') {
                $states = $stockStates[(string)($item->meta['translation_id'] ?? '')] ?? [];
                if ($filters['stock'] === 'untracked' ? $states !== [] : !in_array($filters['stock'], $states, true)) {
                    return false;
                }
            }
            return true;
        }));

        // What the bar offers: a dropdown for each taxonomy that has terms, each term with how many entries are filed under it.
        $bar = ['taxonomies' => [], 'authors' => $authors, 'stock' => $stockStates !== null, 'columns' => [], 'labels' => []];
        foreach ($taxonomyNames as $name) {
            $tree = $taxonomies->tree($name);
            if ($tree === []) {
                continue;
            }
            // How many entries choosing a term lets through: filed under it, or under a term below it.
            $below = [];
            if ($taxonomies->isHierarchical($name)) {
                foreach ($tree as $term) {
                    $below[$term['id']] = $taxonomies->descendantIds($name, $term['id']);
                }
            }
            $counts = [];
            $used = [];
            foreach ($all as $item) {
                $filed = array_unique(Format::list($item->meta[$name] ?? null));
                foreach ($filed as $id) {
                    $used[$id] = true;
                }
                foreach ($tree as $term) {
                    if (in_array($term['id'], $filed, true) || array_intersect($below[$term['id']] ?? [], $filed) !== []) {
                        $counts[$term['id']] = ($counts[$term['id']] ?? 0) + 1;
                    }
                }
            }
            $labels = [];
            $options = [];
            foreach ($tree as $term) {
                $labels[$term['id']] = $taxonomies->label($name, $term['id'], $lang);
                $options[] = ['id' => $term['id'], 'label' => $labels[$term['id']], 'depth' => (int)$term['depth'], 'count' => $counts[$term['id']] ?? 0];
            }
            $bar['taxonomies'][] = [
                'name' => $name,
                'title' => $taxonomies->load($name)['title'],
                'options' => $options,
                'selected' => $chosenTerms[$name] ?? [],
            ];
            $bar['labels'][$name] = $labels;
            // A column only for a taxonomy some entry of the list is filed under.
            if (array_intersect_key($used, $labels) !== []) {
                $bar['columns'][] = ['name' => $name, 'title' => $taxonomies->load($name)['title']];
            }
        }

        return ['items' => $items, 'filters' => $filters, 'status_options' => $statusOptions, 'bar' => $bar];
    }

    /**
     * The taxonomies the list of a type filters by: those its entries can be filed under. Products take only those meant
     * for them (product categories, collections), not the ones every type can use, unless none is meant for them.
     *
     * @return string[]
     */
    private function filterTaxonomies(string $type): array
    {
        $taxonomies = ($this->taxonomies)();
        $names = $taxonomies->names($type);
        if ($type === Shop\Shop::TYPE) {
            $own = array_values(array_filter($names, static fn(string $name): bool => in_array($type, $taxonomies->load($name)['assign'], true)));
            return $own !== [] ? $own : $names;
        }
        return $names;
    }

    /**
     * A filter that can hold several values: a list, or one string with the values separated by commas.
     *
     * @return list<string>
     */
    private static function values(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', is_scalar($value) ? (string)$value : '');
        $out = [];
        foreach ($parts as $part) {
            $part = is_scalar($part) ? Slug::plain((string)$part) : '';
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Publish, move to draft or delete the entries ticked in the list, or, with all_matching, every entry the
     * submitted search, status and term let through (on every page of the list). Where to go next is returned: the
     * list with the same filters and page.
     *
     * @param array<string, mixed> $post
     */
    /**
     * What a bulk form chose: its type, language, action, the entries (the ticked ones, or every entry the filters let through when
     * "select all" was used), and the address of the list as it was. `valid` is false for a type that cannot be acted on.
     * @param array<string, mixed> $post
     * @return array{type: string, lang: string, action: string, slugs: string[], back: string, valid: bool}
     */
    public function bulkSelection(array $post): array
    {
        $type = Slug::plain((string)($post['type'] ?? 'pages')) ?: 'pages';
        $lang = Slug::plain((string)($post['lang'] ?? $this->defaultLang()));
        $action = (string)($post['bulk_action'] ?? '');
        $slugs = array_values(array_unique(array_filter(array_map(
            static fn($slug): string => Slug::plain((string)$slug),
            is_array($post['selected'] ?? null) ? $post['selected'] : []
        ))));
        $back = '/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang);
        if (!in_array($type, $this->content->getTypes(), true) || $type === 'forms') {
            return ['type' => $type, 'lang' => $lang, 'action' => $action, 'slugs' => [], 'back' => $back, 'valid' => false];
        }
        // Back to the list as it was: same search, status, term, page size and page.
        $matching = $this->matching($type, $lang, $post);
        $query = self::listQuery($type, $lang, $matching['filters'], self::perPage($post['per_page'] ?? null));
        if ((int)($post['page'] ?? 1) > 1) {
            $query['page'] = (int)$post['page'];
        }
        $back = '/admin/content?' . http_build_query($query);
        // "Select all" on every page: the entries the filters let through, not only the ticked ones on this page.
        if ((string)($post['all_matching'] ?? '') === '1') {
            $slugs = array_values(array_unique(array_map(static fn(ContentItem $item): string => $item->slug, $matching['items'])));
        }
        return ['type' => $type, 'lang' => $lang, 'action' => $action, 'slugs' => $slugs, 'back' => $back, 'valid' => true];
    }

    public function bulk(array $post, bool $isPost, bool $canRedirects, string $actor): string
    {
        $settings = ($this->settings)();
        $chosen = $this->bulkSelection($post);
        ['type' => $type, 'lang' => $lang, 'action' => $action, 'slugs' => $slugs, 'back' => $back] = $chosen;
        if (!$isPost || !$chosen['valid']) {
            return $back;
        }
        if (!in_array($action, ['publish', 'draft', 'delete'], true) || $slugs === []) {
            return $back . '&' . http_build_query(['bulk' => 'fail', 'bulk_msg' => 'Choose a bulk action and select at least one item.']);
        }

        $paths = new ContentPaths($settings);
        $homeSlug = $paths->homeSlug();
        $defaultLang = $paths->defaultLang();
        $changed = 0;
        $skipped = 0;
        $publicDeleted = 0;
        // Entries that point at one that is about to go (delete) or hide (draft): told in the message afterwards.
        $pointedAt = 0;
        if ($this->relations !== null && in_array($action, ['draft', 'delete'], true)) {
            foreach ($slugs as $slug) {
                if (is_file($this->path($type, $slug, $lang)) && $this->relations->referrers($type, $slug) !== []) {
                    $pointedAt++;
                }
            }
        }
        foreach ($slugs as $slug) {
            $path = $this->path($type, $slug, $lang);
            if (!is_file($path)) {
                $skipped++;
                continue;
            }
            if ($action === 'delete') {
                // The default-language home page is never deletable, same as the single delete.
                if ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang) {
                    $skipped++;
                    continue;
                }
                $wasPublic = $this->content->find($type, $slug, $lang, false, false) !== null;
                // Same as deleting one: the text stays in the history so it can be brought back.
                $this->revisions->baseline($type, $slug, $lang, $path, $actor);
                $this->revisions->capture($type, $slug, $lang, (string)file_get_contents($path), 'delete', $actor);
                if (@unlink($path)) {
                    $this->unindex($type, $slug, $lang);
                    $changed++;
                    $publicDeleted += $wasPublic ? 1 : 0;
                }
                continue;
            }
            [$frontmatter, $body] = FrontMatter::split((string)file_get_contents($path));
            try {
                $meta = $frontmatter !== '' ? (Yaml::parse($frontmatter) ?: []) : [];
            } catch (\Throwable) {
                $skipped++;
                continue;
            }
            if (!is_array($meta)) {
                $skipped++;
                continue;
            }
            $previousStatus = (string)($meta['status'] ?? 'published');
            $meta['status'] = $action === 'publish' ? 'published' : 'draft';
            $meta = ContentEditor::goingLive($meta, $previousStatus);
            // Published or a draft now: a time it was waiting for no longer applies.
            unset($meta['publish_at']);
            // Same layout the editor writes: front matter, one blank line, body.
            $body = preg_replace('/\A\R/', '', $body) ?? $body;
            file_put_contents($path, "---\n" . trim(Yaml::dump($meta, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . rtrim($body) . "\n");
            $this->reindex($type, $path);
            $changed++;
        }

        $verb = match ($action) {
            'publish' => 'published',
            'draft' => 'moved to draft',
            default => 'deleted',
        };
        ($this->log)('content.bulk_' . $action, $action === 'delete' ? 'warning' : 'info', $type, $lang, 'Bulk ' . $verb . '.', [
            'type' => $type,
            'lang' => $lang,
            'slugs' => $slugs,
            'changed' => $changed,
            'skipped' => $skipped,
        ]);
        $message = $changed . ' item' . ($changed === 1 ? '' : 's') . ' ' . $verb . '.' . ($skipped > 0 ? ' ' . $skipped . ' skipped.' : '');
        if ($publicDeleted > 0) {
            $message .= ' ' . $publicDeleted . ' of them ' . ($publicDeleted === 1 ? 'was' : 'were') . ' public: visitors of ' . ($publicDeleted === 1 ? 'its address' : 'their addresses') . ' will see "not found".'
                . ($canRedirects ? ' Add redirects in Admin > Redirects, or delete them one at a time to choose where visitors go.' : '');
        }
        if ($pointedAt > 0 && $changed > 0) {
            $message .= ' ' . $pointedAt . ($pointedAt === 1 ? ' of them is' : ' of them are') . ' pointed at by other entries'
                . ($action === 'delete' ? ', which now lead nowhere (Admin > Broken references lists them).' : ', which no longer show ' . ($pointedAt === 1 ? 'it' : 'them') . ' to visitors.');
        }
        return $back . '&' . http_build_query(['bulk' => $changed > 0 ? 'ok' : 'fail', 'bulk_msg' => $message]);
    }

    /**
     * Deleting one entry. A public one goes through a page of its own first, which asks whether visitors of its
     * address should be sent somewhere (a redirect) instead of finding nothing. A draft, which nobody could have
     * visited, is deleted straight away after the confirmation in the list.
     *
     * @param array<string, mixed> $source the submitted form (POST) or the address (GET)
     * @return array{location: string, view: array<string, mixed>} where to go next, or the data of the confirmation page
     */
    public function delete(string $type, array $source, bool $isPost, bool $canRedirects, bool $canManageContent, string $actor): array
    {
        $paths = new ContentPaths(($this->settings)());
        $slug = Slug::plain((string)($source['slug'] ?? ''));
        $lang = Slug::plain((string)($source['lang'] ?? $paths->defaultLang()));
        $defaultLang = $paths->defaultLang();
        $homeSlug = $paths->homeSlug();
        $back = '/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang);

        if ($slug === '' || ($type === 'pages' && $slug === $homeSlug && $lang === $defaultLang)) {
            return ['location' => $back, 'view' => []];
        }

        $path = $this->path($type, $slug, $lang);
        $isPublic = is_file($path) && $type !== 'forms' && $this->content->find($type, $slug, $lang, false, false) !== null;
        $publicPath = ContentPaths::build($type, $slug, $lang, $homeSlug, $defaultLang);
        $mayRedirect = $isPublic && $canRedirects && $this->redirects->isAvailable();

        // Where visitors go instead, when the person chose to send them somewhere.
        $target = '';
        $error = '';
        $choice = $isPost ? (string)($source['after'] ?? '') : '';
        $custom = $isPost ? (string)($source['after_target'] ?? '') : '';
        if ($isPost && $mayRedirect && in_array($choice, ['archive', 'home', 'custom'], true)) {
            $publicPaths = ($this->publicPaths)();
            $target = match ($choice) {
                'archive' => $type === 'pages' ? '' : '/' . ContentPaths::archive($type, $lang, $defaultLang),
                'home' => '/' . ($lang === $defaultLang ? '' : $lang),
                default => $publicPaths->localize($custom),
            };
            $error = $target === '' ? 'target_empty' : (string)$this->redirects->validate($publicPath, $target, 301);
            if ($error === '' && !RedirectRepository::isExternal($target) && !$publicPaths->exists(RedirectRepository::normalizePath($target))) {
                $error = 'target_missing';
            }
        }

        if (!$isPost || $error !== '') {
            if (!is_file($path)) {
                return ['location' => $back, 'view' => []];
            }
            return ['location' => '', 'view' => $this->confirmation($type, $slug, $lang, $publicPath, $isPublic, $mayRedirect, $error, $choice, $custom, $canManageContent)];
        }

        if (is_file($path)) {
            // The text is kept in the history so the item can be brought back from Admin > History.
            $this->revisions->baseline($type, $slug, $lang, $path, $actor);
            $this->revisions->capture($type, $slug, $lang, (string)file_get_contents($path), 'delete', $actor);
            unlink($path);
            $this->unindex($type, $slug, $lang);
            if ($target !== '') {
                $this->redirects->replacedBy($publicPath, $target, $actor, $type);
            }
            ($this->log)('content.delete', 'warning', $type, $slug . ':' . $lang, 'Content deleted.', [
                'type' => $type,
                'slug' => $slug,
                'lang' => $lang,
                'redirect_to' => $target,
            ]);
        }
        $query = '&deleted=1';
        if ($target !== '') {
            $query .= '&from=' . urlencode('/' . $publicPath) . '&to=' . urlencode($target);
        } elseif ($isPublic) {
            $query .= '&gone=' . urlencode('/' . $publicPath);
        }
        return ['location' => $back . $query, 'view' => []];
    }

    /**
     * The page that asks what should happen to visitors of a public entry that is about to be deleted.
     *
     * @return array<string, mixed>
     */
    private function confirmation(string $type, string $slug, string $lang, string $publicPath, bool $isPublic, bool $mayRedirect, string $error, string $choice, string $custom, bool $canManageContent): array
    {
        $defaultLang = $this->defaultLang();
        $item = $this->content->find($type, $slug, $lang, true, false);
        $known = ($this->publicPaths)()->map();
        unset($known[$publicPath]);
        $counts = $canManageContent
            ? ($this->linkScanner)()->countBySource([RedirectRepository::normalizePath($publicPath)])
            : null;
        return [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'entry_title' => $item !== null ? (string)($item->meta['title'] ?? $slug) : $slug,
            'public_path' => '/' . $publicPath,
            'is_public' => $isPublic,
            'may_redirect' => $mayRedirect,
            'archive_path' => $type === 'pages' ? '' : '/' . ContentPaths::archive($type, $lang, $defaultLang),
            'archive_label' => $this->types->definition($type, 'en', $defaultLang)['label'],
            'home_path' => '/' . ($lang === $defaultLang ? '' : $lang),
            'known_paths' => array_slice($known, 0, 500, true),
            'links' => $counts !== null ? array_sum($counts) : null,
            'referenced_by' => $this->relations?->referrers($type, $slug) ?? [],
            'siblings' => array_column($this->editor->translationSiblings($type, $slug, $lang), 'lang'),
            'choice' => $choice !== '' ? $choice : ($type === 'pages' ? 'none' : 'archive'),
            'custom_target' => $custom,
            'error' => $error,
            'types' => $this->content->getTypes(),
            'admin_section' => 'content',
            'current_type' => $type,
        ];
    }

    /**
     * Sets an entry's main image from outside the editor (a picture made in the background), the way the editor writes the file:
     * the history keeps the change, the index follows. Without `$replace` an entry that has a main image by now keeps it.
     * Returns set, kept (it had one), missing (no such entry) or unreadable (its front matter could not be read).
     */
    public function setMainImage(string $type, string $slug, string $lang, string $image, string $actor, bool $replace): string
    {
        $path = $this->path($type, $slug, $lang);
        if (!is_file($path)) {
            return 'missing';
        }
        $raw = (string)file_get_contents($path);
        [$frontmatter, $body] = FrontMatter::split($raw);
        try {
            $meta = $frontmatter !== '' ? (Yaml::parse($frontmatter) ?: []) : [];
        } catch (\Throwable) {
            return 'unreadable';
        }
        if (!is_array($meta)) {
            return 'unreadable';
        }
        if (!$replace && trim((string)($meta['main_image'] ?? '')) !== '') {
            return 'kept';
        }
        $meta['main_image'] = $image;
        $body = preg_replace('/\A\R/', '', $body) ?? $body;
        $this->revisions->baseline($type, $slug, $lang, $path, $actor);
        $payload = "---\n" . trim(Yaml::dump($meta, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . rtrim($body) . "\n";
        file_put_contents($path, $payload);
        $this->revisions->capture($type, $slug, $lang, $payload, 'save', $actor);
        $this->reindex($type, $path);
        return 'set';
    }

    /**
     * Gives an entry a status from outside the editor (a post written on a schedule): published (dated today when it goes live for the
     * first time) or scheduled for `$publishAt` (Y-m-d H:i, released by ScheduledPublishing). Returns set, missing or unreadable.
     */
    public function setStatus(string $type, string $slug, string $lang, string $status, ?string $publishAt, string $actor): string
    {
        $path = $this->path($type, $slug, $lang);
        if (!is_file($path)) {
            return 'missing';
        }
        [$frontmatter, $body] = FrontMatter::split((string)file_get_contents($path));
        try {
            $meta = $frontmatter !== '' ? (Yaml::parse($frontmatter) ?: []) : [];
        } catch (\Throwable) {
            return 'unreadable';
        }
        if (!is_array($meta)) {
            return 'unreadable';
        }
        $previous = (string)($meta['status'] ?? 'published');
        $meta['status'] = in_array($status, ['published', 'draft', ScheduledPublishing::STATUS], true) ? $status : 'draft';
        if ($meta['status'] === ScheduledPublishing::STATUS && $publishAt !== null && $publishAt !== '') {
            $meta['publish_at'] = $publishAt;
        } else {
            unset($meta['publish_at']);
            if ($meta['status'] === ScheduledPublishing::STATUS) {
                $meta['status'] = 'published';
            }
        }
        $meta = ContentEditor::goingLive($meta, $previous);
        $body = preg_replace('/\A\R/', '', $body) ?? $body;
        $this->revisions->baseline($type, $slug, $lang, $path, $actor);
        $payload = "---\n" . trim(Yaml::dump($meta, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . rtrim($body) . "\n";
        file_put_contents($path, $payload);
        $this->revisions->capture($type, $slug, $lang, $payload, 'save', $actor);
        $this->reindex($type, $path);
        return 'set';
    }

    private function path(string $type, string $slug, string $lang): string
    {
        return $this->contentDir . '/' . $type . '/' . (new ContentPaths(($this->settings)()))->filename($slug, $lang);
    }

    private function defaultLang(): string
    {
        return (string)((($this->settings)())['languages']['default'] ?? 'en');
    }

    /** The index is rebuildable, so a failed write only makes it stale and must never block editing. */
    private function reindex(string $type, string $path): void
    {
        try {
            if (is_file($path)) {
                $this->index->upsert($this->content->parseFile($type, $path));
            }
        } catch (\Throwable) {
        }
    }

    private function unindex(string $type, string $slug, string $lang): void
    {
        try {
            $this->index->remove($type, $slug, $lang);
        } catch (\Throwable) {
        }
    }
}
