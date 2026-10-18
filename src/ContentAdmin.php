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
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(): PublicPaths $publicPaths
     * @param \Closure(): LinkScanner $linkScanner
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
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
        private \Closure $log
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
        $items = $this->content->getItems($type, $lang, true, false);
        $translationLangs = $this->translations->matrix($type, $items);
        $statusOptions = array_values(array_unique(array_merge(['published', 'draft'], array_map(
            static fn(ContentItem $item): string => (string)($item->meta['status'] ?? 'published'),
            $items
        ))));
        $filters = [
            'q' => trim((string)($get['q'] ?? '')),
            'status' => in_array((string)($get['status'] ?? ''), $statusOptions, true) ? (string)$get['status'] : '',
        ];
        if ($filters['q'] !== '') {
            $needle = mb_strtolower($filters['q']);
            $items = array_values(array_filter($items, static fn(ContentItem $item): bool => str_contains(mb_strtolower((string)($item->meta['title'] ?? '') . ' ' . $item->slug), $needle)));
        }
        if ($filters['status'] !== '') {
            $items = array_values(array_filter($items, static fn(ContentItem $item): bool => (string)($item->meta['status'] ?? 'published') === $filters['status']));
        }
        // Filed under a term: the link from Taxonomies. Anything that is not a term of a taxonomy is ignored.
        $filters['taxonomy'] = '';
        $filters['term'] = '';
        $termFilter = null;
        $taxonomies = ($this->taxonomies)();
        $filterTaxonomy = Slug::plain((string)($get['taxonomy'] ?? ''));
        $filterTerm = Slug::plain((string)($get['term'] ?? ''));
        if ($filterTaxonomy !== '' && $filterTerm !== '' && in_array($filterTaxonomy, $taxonomies->names(), true)
            && in_array($filterTerm, array_column($taxonomies->load($filterTaxonomy)['terms'], 'id'), true)) {
            $filters['taxonomy'] = $filterTaxonomy;
            $filters['term'] = $filterTerm;
            $termFilter = [
                'taxonomy' => $filterTaxonomy,
                'term' => $filterTerm,
                'taxonomy_title' => $taxonomies->load($filterTaxonomy)['title'],
                'label' => $taxonomies->label($filterTaxonomy, $filterTerm, $lang),
            ];
            $items = array_values(array_filter($items, static fn(ContentItem $item): bool => in_array($filterTerm, Format::list($item->meta[$filterTaxonomy] ?? null), true)));
        }

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
            'filters_active' => $filters['q'] !== '' || $filters['status'] !== '' || $termFilter !== null,
            'term_filter' => $termFilter,
            'status_options' => $statusOptions,
            'bulk_status' => (string)($get['bulk'] ?? ''),
            'bulk_message' => trim((string)($get['bulk_msg'] ?? '')),
            'deleted_from' => trim((string)($get['from'] ?? '')),
            'deleted_to' => trim((string)($get['to'] ?? '')),
            'deleted_gone' => trim((string)($get['gone'] ?? '')),
            'can_redirects' => $canRedirects,
        ]];
    }

    /**
     * Publish, move to draft or delete the entries ticked in the list. Where to go next is returned.
     *
     * @param array<string, mixed> $post
     */
    public function bulk(array $post, bool $isPost, bool $canRedirects, string $actor): string
    {
        $settings = ($this->settings)();
        $type = Slug::plain((string)($post['type'] ?? 'pages')) ?: 'pages';
        $lang = Slug::plain((string)($post['lang'] ?? $this->defaultLang()));
        $action = (string)($post['bulk_action'] ?? '');
        $slugs = array_values(array_unique(array_filter(array_map(
            static fn($slug): string => Slug::plain((string)$slug),
            is_array($post['selected'] ?? null) ? $post['selected'] : []
        ))));
        $back = '/admin/content?type=' . urlencode($type) . '&lang=' . urlencode($lang);
        if (!$isPost || !in_array($type, $this->content->getTypes(), true) || $type === 'forms') {
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
            $meta['status'] = $action === 'publish' ? 'published' : 'draft';
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
            'siblings' => array_column($this->editor->translationSiblings($type, $slug, $lang), 'lang'),
            'choice' => $choice !== '' ? $choice : ($type === 'pages' ? 'none' : 'archive'),
            'custom_target' => $custom,
            'error' => $error,
            'types' => $this->content->getTypes(),
            'admin_section' => 'content',
            'current_type' => $type,
        ];
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
