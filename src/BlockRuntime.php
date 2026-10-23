<?php

declare(strict_types=1);

namespace GnozioCMS;

use Twig\Environment;

/**
 * What the blocks of a page need from the site while they are drawn: the registry of block types (with the choices some of
 * them offer), the renderer that draws a page's blocks with the entries, forms, playlists and maps they show, and the services
 * behind the Playlist and Map blocks (YouTube playlists and their pictures, the maps of content). Each service is built the first
 * time it is asked for, so a page without such a block builds none of them.
 *
 * Everything that changes during a request (the settings, the language, who is signed in) is asked for through a closure when it
 * is used.
 */
final class BlockRuntime
{
    private ?BlockRegistry $registry = null;
    private ?YouTubePlaylist $playlist = null;
    private ?YouTubeThumbs $thumbs = null;
    private ?GeoView $geoView = null;
    private ?MapFilters $mapFilters = null;

    /**
     * @param \Closure(): array<string, mixed> $settings the site's settings, as they are now
     * @param \Closure(): bool $signedIn whether someone is signed in to the admin (they also see what is hidden)
     * @param \Closure(): ContentTypes $contentTypes
     * @param \Closure(): string $language the language being shown
     * @param \Closure(): string $defaultLanguage
     * @param \Closure(string, string, string): string $termLabel the name of a term: taxonomy, term, language
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     * @param \Closure(string, string, string): string $markdownToHtml Markdown as the site shows it: text, language, path of the page
     * @param \Closure(string, string, string): string $formEmbed the form of a slug, ready to place: slug, language, path of the page
     * @param (\Closure(): Taxonomies)|null $taxonomies the site's taxonomies (without them the bar of a map offers its categories only)
     * @param (\Closure(): ArchiveQuery)|null $archiveQuery the content index, which answers the Latest content block without reading every entry of the type
     */
    public function __construct(
        private string $basePath,
        private Theme $theme,
        private Images $images,
        private SystemMetaRepository $systemMeta,
        private ContentRepository $content,
        private \Closure $settings,
        private \Closure $signedIn,
        private \Closure $contentTypes,
        private \Closure $language,
        private \Closure $defaultLanguage,
        private \Closure $termLabel,
        private \Closure $absoluteUrl,
        private \Closure $markdownToHtml,
        private \Closure $formEmbed,
        private ?\Closure $relations = null,
        private ?\Closure $taxonomies = null,
        private ?\Closure $archiveQuery = null
    ) {
    }

    /** The block types, with the choices the editor offers for the types of content, the types with a place, and the forms. */
    public function registry(): BlockRegistry
    {
        return $this->registry ??= new BlockRegistry($this->theme, [
            'content_types' => function (): array {
                $options = [];
                foreach ($this->content->getTypes() as $type) {
                    if ($type !== 'forms' && $type !== 'pages') {
                        $options[$type] = $this->typeLabel($type, 'en');
                    }
                }
                return $options;
            },
            // The types whose entries can have a place, for the Map block: each type, and everything with a place.
            'place_types' => function (): array {
                $options = [];
                foreach ($this->geoView()->placeTypes() as $type) {
                    $options[$type] = $this->typeLabel($type, 'en');
                }
                return count($options) > 1 ? ['all' => 'Everything with a place'] + $options : $options;
            },
            'forms' => function (): array {
                $options = ['' => '—'];
                foreach ($this->content->getItems('forms', null, true) as $form) {
                    $options[$form->slug] = (string)($form->meta['title'] ?? $form->slug);
                }
                return $options;
            },
        ]);
    }

    /**
     * The newest entries of a type in a language, as the Latest content block lists them: the content index gives their addresses and only
     * those files are read. Null when the index cannot answer (the caller then reads every entry of the type, as before).
     *
     * @return ContentItem[]|null
     */
    private function latestFromIndex(string $type, string $lang, bool $includeHidden, int $limit, string $term): ?array
    {
        if ($this->archiveQuery === null) {
            return null;
        }
        $query = ($this->archiveQuery)();
        if (!$query->ready()) {
            return null;
        }
        // A category or a tag, whichever the entry is filed under.
        $scope = ['types' => [$type], 'term' => $term === '' ? null : ['taxonomy' => ['categories', 'tags'], 'ids' => [$term]]];
        return $query->page($scope, $lang, $includeHidden, [], [], 'date_desc', '', 0, 0, 1, $limit)['items'] ?? null;
    }

    /** The renderer for the blocks of one page, drawing with the given Twig (which can be replaced during a request). */
    public function renderer(string $lang, string $path, Environment $twig, array $more = []): BlockRenderer
    {
        $includeHidden = ($this->signedIn)();
        return new BlockRenderer(
            $this->registry(),
            $twig,
            $this->theme,
            fn(string $markdown): string => ($this->markdownToHtml)($markdown, $lang, $path),
            [
                'items' => function (string $type, string $itemLang, int $limit, string $term = '') use ($includeHidden): array {
                    if ($type === 'forms' || !in_array($type, $this->content->getTypes(), true)) {
                        return [];
                    }
                    $term = Slug::plain($term);
                    $limit = max(1, min(24, $limit));
                    $indexed = $this->latestFromIndex($type, $itemLang, $includeHidden, $limit, $term);
                    if ($indexed !== null) {
                        return $indexed;
                    }
                    $items = $this->content->getItems($type, $itemLang, $includeHidden, false);
                    if ($term !== '') {
                        // Only items filed under this category or tag.
                        $items = array_values(array_filter($items, function (ContentItem $item) use ($term): bool {
                            foreach (['categories', 'tags'] as $taxonomy) {
                                if (in_array($term, array_map('strval', (array)($item->meta[$taxonomy] ?? [])), true)) {
                                    return true;
                                }
                            }
                            return false;
                        }));
                    }
                    return array_slice($items, 0, $limit);
                },
                'form' => fn(string $slug): string => $slug === '' ? '' : ($this->formEmbed)(Slug::plain($slug), $lang, $path),
                'youtube' => function (string $playlistId, string $thumbs): array {
                    $result = $this->youtubePlaylist()->fetch($playlistId);
                    foreach ($result['items'] as $i => $video) {
                        $result['items'][$i]['thumb'] = 'https://i.ytimg.com/vi/' . $video['id'] . '/hqdefault.jpg';
                        if ($thumbs !== 'youtube') {
                            $result['items'][$i]['thumb_path'] = $this->youtubeThumbs()->path($video['id']);
                        }
                    }
                    return $result;
                },
                'file' => fn(string $url): ?int => $this->images->fileSize($url),
                // The Map block when it shows content: the entries to put on the map, as the data a map draws (type, language, limit, category).
                'geo' => function (string $source, string $itemLang, int $limit, string $term): array {
                    $view = $this->geoView();
                    $types = $source === 'all' ? $view->placeTypes() : (in_array($source, $view->placeTypes(), true) ? [$source] : []);
                    return $view->collection($types, $itemLang, Slug::plain($term), ['limit' => max(1, min(800, $limit))]);
                },
                // The entries of a list as a map draws them (the Map of the list block): the dataset of the entries that have a place.
                // `$filters`: the taxonomies the list's card chose for the bar (null: as each type's card says).
                'geo_items' => function (array $items, string $itemLang, ?array $filters = null): array {
                    $view = $this->geoView();
                    return $view->dataset(array_values(array_filter($items, static fn($item): bool => $item instanceof ContentItem)), $itemLang, ['limit' => ArchiveQuery::MAP_LIMIT, 'filters' => $filters]);
                },
                'geo_load' => fn(): string => (($this->settings)()['apis']['maps']['load'] ?? 'click') === 'auto' ? 'auto' : 'click',
                'term' => fn(string $id, string $itemLang): string => ($this->termLabel)('categories', $id, $itemLang),
                // The Gallery block that shows the entry's gallery: its pictures, in order (a field key, or '' for the type's gallery).
                'gallery' => fn(ContentItem $item, string $key, string $itemLang): array => ($this->contentTypes)()->gallery($item->type, $item->meta, $key, $itemLang !== '' ? $itemLang : ($this->language)(), ($this->defaultLanguage)()),
                'admin' => fn(): bool => ($this->signedIn)(),
            ] + $more,
            rtrim((string)(($this->settings)()['base_url'] ?? ''), '/')
        );
    }

    /**
     * The blocks of a template built with blocks drawn for one entry: its fields (bindings), its text (Page content) and its own blocks.
     *
     * @param array<int, mixed> $blocks
     * @param array<string, mixed> $context what every block gets (the page's view data)
     * @return array<string, mixed> what BlockRenderer::render() gives
     */
    public function renderTemplate(array $blocks, ContentItem $item, string $lang, string $path, Environment $twig, array $context): array
    {
        return $this->renderer($lang, $path, $twig)->render($blocks, $context + ['item' => $item, 'body_html' => $item->html, 'template_mode' => true, 'bindings' => $this->bindingsFor($item, $lang)]);
    }

    /**
     * The blocks of an archive template drawn for a list: the page's blocks with the archive (its entries, filters and pages) as their
     * context, and each entry of the list drawn with the card's blocks, bound to that entry's fields.
     *
     * @param array<int, mixed> $blocks the page
     * @param array<int, mixed> $card the blocks of one card (none: the theme's card is used)
     * @param array<string, mixed> $context what every block gets: the page's view data with `archive` (no `block_preview`: `$preview` says it)
     * @param string $preview which part the live preview marks for the editor: 'page', 'card' or '' (none)
     * @param array<string, array<int, mixed>> $cards the card of a content type that has one of its own, by type (the others use `$card`)
     * @param string $previewType with the card marked, the content type whose card it is ('' for the card of the others)
     * @return array<string, mixed> what BlockRenderer::render() gives
     */
    public function renderArchive(array $blocks, array $card, string $lang, string $path, Environment $twig, array $context, string $preview = '', array $cards = [], string $previewType = ''): array
    {
        $more = [];
        if ($card !== [] || $cards !== []) {
            $more['archive_card'] = function (ContentItem $item, array $page) use ($card, $cards, $lang, $path, $twig, $preview, $previewType): array {
                // The card of the entry's content type, or the one for the others. The card is drawn with the entry's fields; the blocks in it
                // are ordinary blocks, one level below the title of the page.
                $set = $cards[$item->type] ?? $card;
                $mine = $previewType === '' ? !isset($cards[$item->type]) : $previewType === $item->type;
                $drawn = $this->renderer($lang, $path, $twig)->render($set, [
                    'item' => $item, 'body_html' => '', 'template_mode' => true, 'bindings' => $this->bindingsFor($item, $lang),
                    'block_heading_tag' => 'h2', 'block_preview' => $preview === 'card' && $mine,
                    // Every card on the page has ids of its own.
                    'block_uid_prefix' => 'card-' . preg_replace('/[^a-z0-9]+/i', '-', $item->type . '-' . $item->slug) . '-',
                ] + array_diff_key($page, ['archive' => 1, 'block_preview' => 1, 'page_blocks' => 1]));
                return ['html' => $drawn['html'], 'types' => $drawn['asset_types']];
            };
        }
        return $this->renderer($lang, $path, $twig, $more)->render($blocks, ['block_preview' => $preview === 'page'] + $context);
    }

    /** The bindings of a template built with blocks for one entry: its fields as the template's blocks can show them in a language. */
    public function bindingsFor(ContentItem $item, string $lang): EntryBindings
    {
        $dateFormat = (string)(($this->settings)()['date_format'] ?? 'd/m/Y');
        return new EntryBindings(
            $item,
            ($this->contentTypes)(),
            $lang,
            ($this->defaultLanguage)(),
            static fn(string $value): string => Format::dateValue($value, $dateFormat),
            fn(string $taxonomy, string $id): string => ($this->termLabel)($taxonomy, $id, $lang),
            $this->relations !== null ? ($this->relations)() : null
        );
    }

    /**
     * What the page of one entry drawn by a theme template needs besides its own blocks: the strip of its gallery, drawn as a Gallery
     * block (the Scrolling strip layout, its pictures taken from the entry) when its Single Layouts card puts one under the title or in
     * the body and the entry has pictures, and the style sheets and scripts of the page: those of its blocks, with the map's for a type
     * that has a place and the gallery's for a strip, each once.
     *
     * @param array<string, mixed>|null $pageBlocks what BlockRenderer::render() gave for the entry's blocks (null: it has none)
     * @param string $strip where the strip goes: 'title', 'body' or 'off' (the card's `gallery_strip`)
     * @param array<string, mixed> $context the page's view data
     * @return array{styles: list<string>, scripts: list<string>, gallery_strip: array{html: string, place: string}}
     */
    public function singlePage(ContentItem $item, ?array $pageBlocks, string $strip, string $lang, string $path, Environment $twig, array $context): array
    {
        $extra = [];
        $stripHtml = '';
        if (in_array($strip, ['title', 'body'], true) && ($this->contentTypes)()->galleryKey($item->type) !== '') {
            $drawn = $this->renderer($lang, $path, $twig)->render(
                [['type' => 'gallery', 'variant' => 'strip', 'source' => 'entry', 'spacing' => $strip === 'title' ? 'compact' : 'none']],
                ['item' => $item, 'body_html' => '', 'block_preview' => false, 'block_uid_prefix' => 'gallery-strip-'] + $context
            );
            $stripHtml = trim($drawn['html']);
            $extra = $stripHtml !== '' ? $drawn['types'] : [];
        }
        if ($this->hasPlaceField($item->type)) {
            // The page of a point, a route or a business draws a map.
            $extra[] = 'map';
        }
        $styles = $pageBlocks['styles'] ?? [];
        $scripts = $pageBlocks['scripts'] ?? [];
        if ($extra !== []) {
            $base = rtrim((string)(($this->settings)()['base_url'] ?? ''), '/');
            $types = array_values(array_unique(array_merge($pageBlocks['types'] ?? [], $extra)));
            $styles = array_values(array_filter([$this->theme->blockStylesheetUrl($base, array_values(array_unique(array_merge($types, array_intersect($pageBlocks['asset_types'] ?? [], [Theme::ROW_STYLES, Theme::CONTAINER_STYLES])))))]));
            $scripts = array_values(array_filter([$this->theme->blockScriptUrl($base, $types)]));
        }
        return ['styles' => $styles, 'scripts' => $scripts, 'gallery_strip' => ['html' => $stripHtml, 'place' => $stripHtml !== '' ? $strip : 'off']];
    }

    /** The content types whose entries can have a place (pages and forms never). @return string[] */
    public function placeTypes(): array
    {
        return array_values(array_filter($this->content->getTypes(), fn(string $type): bool => $type !== 'forms' && $type !== 'pages' && $this->hasPlaceField($type)));
    }

    /** Whether entries of a content type can have a place (a field of the kind `location`). */
    public function hasPlaceField(string $type): bool
    {
        foreach (($this->contentTypes)()->definition($type, ($this->language)(), ($this->defaultLanguage)())['fields'] as $field) {
            if ($field['type'] === 'location' && !$field['hidden']) {
                return true;
            }
        }
        return false;
    }

    /** The maps of content: places, routes and what is near what (see GeoView). */
    public function geoView(): GeoView
    {
        if ($this->geoView !== null) {
            return $this->geoView;
        }
        $library = new GeoLibrary($this->images, $this->basePath . '/storage/cache');
        return $this->geoView = new GeoView(
            new GeoMap($library, $this->images),
            fn(string $type, string $lang): array => in_array($type, $this->content->getTypes(), true) ? $this->content->getItems($type, $lang, ($this->signedIn)(), false) : [],
            fn(string $type, string $lang): string => $this->typeLabel($type, $lang),
            fn(string $slug, string $lang): string => ($this->termLabel)('categories', $slug, $lang),
            fn(ContentItem $item, string $lang): string => ($this->absoluteUrl)(ContentPaths::build($item->type, $item->slug, $lang, (string)((($this->settings)()['home_page'] ?? '') !== '' ? ($this->settings)()['home_page'] : 'index'), ($this->defaultLanguage)())),
            fn(): array => $this->placeTypes(),
            [
                'tiles_url' => (string)(($this->settings)()['apis']['maps']['tiles_url'] ?? ''),
                'attribution' => (string)(($this->settings)()['apis']['maps']['attribution'] ?? ''),
            ],
            new GeoCache($this->basePath . '/storage/cache/geo-data', fn(): ?string => $this->mapsStamp(), $library, fn(): string => ($this->signedIn)() ? 'editor' : 'visitor'),
            ($filters = $this->mapFilters()) === null ? null : fn(array $types, ?array $chosen, string $lang): array => $filters->facets($types, $chosen, $lang)
        );
    }

    /** Which taxonomies the bar over a map offers (see MapFilters), or null when the site's taxonomies were not given. */
    public function mapFilters(): ?MapFilters
    {
        if ($this->mapFilters === null && $this->taxonomies !== null) {
            $this->mapFilters = new MapFilters($this->taxonomies, function (string $type): ?array {
                $default = ($this->defaultLanguage)();
                return ($this->contentTypes)()->definition($type, $default, $default)['archive']['map_filters'] ?? null;
            });
        }
        return $this->mapFilters;
    }

    /**
     * The token the data of the maps is kept under (see GeoCache), or null to keep nothing: it holds the revision of the content
     * (which also looks for files edited outside the admin) and what else the data is made from that is not an
     * entry: the settings the maps use, the site's own taxonomies and the definitions of the types that have a place.
     */
    private function mapsStamp(): ?string
    {
        $revision = $this->relations !== null && !$this->content->isPreviewing() ? ($this->relations)()->revision() : '';
        if ($revision === '') {
            return null;
        }
        $settings = ($this->settings)();
        $defaultLang = ($this->defaultLanguage)();
        $languages = array_values(array_unique(array_merge([$defaultLang], array_map('strval', (array)($settings['languages']['available'] ?? [])))));
        $types = [];
        foreach ($this->geoView()->placeTypes() as $type) {
            foreach ($languages as $lang) {
                $types[$type][$lang] = ($this->contentTypes)()->definition($type, $lang, $defaultLang);
            }
        }
        $taxonomies = [];
        foreach (glob($this->basePath . '/content/taxonomies/*') ?: [] as $file) {
            $taxonomies[basename($file)] = (int)@filemtime($file) . '|' . (int)@filesize($file);
        }
        return sha1(serialize([
            $revision, ($this->absoluteUrl)(''), $settings['apis']['maps'] ?? [], $settings['home_page'] ?? '', $languages,
            $types, $taxonomies,
        ]));
    }

    /** The videos of a YouTube playlist (see YouTubePlaylist), with the key and the time kept that the settings have when it is first asked for. */
    public function youtubePlaylist(): YouTubePlaylist
    {
        if ($this->playlist === null) {
            $settings = ($this->settings)();
            $this->playlist = new YouTubePlaylist(
                $this->systemMeta,
                trim((string)($settings['apis']['youtube']['key'] ?? '')),
                static fn(string $url): array => YouTubePlaylist::request($url),
                SiteSettings::cacheHours($settings['apis']['youtube']['cache_hours'] ?? 6)
            );
        }
        return $this->playlist;
    }

    /** Forgets the playlist service, so the next use reads the key and the time kept from the settings again (after they were saved). */
    public function resetYoutube(): void
    {
        $this->playlist = null;
    }

    /** The pictures of a playlist's videos, kept on this site (see YouTubeThumbs). */
    public function youtubeThumbs(): YouTubeThumbs
    {
        return $this->thumbs ??= new YouTubeThumbs(
            $this->basePath . '/storage/cache/youtube',
            $this->systemMeta,
            static fn(string $url): array => YouTubePlaylist::request($url, 4)
        );
    }

    /**
     * The file of the picture a `_yt/<id>.jpg` address asks for, when the signature in the address is the one that picture was
     * given; nothing otherwise.
     */
    public function youtubeThumbFile(string $path, string $signature): ?string
    {
        $id = (string)preg_replace('/\.jpg$/', '', substr($path, 4));
        return $this->youtubeThumbs()->isValid($id, $signature) ? $this->youtubeThumbs()->file($id) : null;
    }

    private function typeLabel(string $type, string $lang): string
    {
        return ($this->contentTypes)()->definition($type, $lang, ($this->defaultLanguage)())['label'];
    }
}
