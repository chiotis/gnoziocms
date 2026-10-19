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
        private \Closure $formEmbed
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

    /** The renderer for the blocks of one page, drawing with the given Twig (which can be replaced during a request). */
    public function renderer(string $lang, string $path, Environment $twig): BlockRenderer
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
                    $items = $this->content->getItems($type, $itemLang, $includeHidden, false);
                    $term = Slug::plain($term);
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
                    return array_slice($items, 0, max(1, min(24, $limit)));
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
                    return $view->dataset($view->gather($types, $itemLang, Slug::plain($term)), $itemLang, ['limit' => max(1, min(800, $limit))]);
                },
                'geo_load' => fn(): string => (($this->settings)()['apis']['maps']['load'] ?? 'click') === 'auto' ? 'auto' : 'click',
                'term' => fn(string $id, string $itemLang): string => ($this->termLabel)('categories', $id, $itemLang),
                'admin' => fn(): bool => ($this->signedIn)(),
            ],
            rtrim((string)(($this->settings)()['base_url'] ?? ''), '/')
        );
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
            fn(string $taxonomy, string $id): string => ($this->termLabel)($taxonomy, $id, $lang)
        );
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
        return $this->geoView ??= new GeoView(
            new GeoMap(new GeoLibrary($this->images, $this->basePath . '/storage/cache'), $this->images),
            fn(string $type, string $lang): array => in_array($type, $this->content->getTypes(), true) ? $this->content->getItems($type, $lang, ($this->signedIn)(), false) : [],
            fn(string $type, string $lang): string => $this->typeLabel($type, $lang),
            fn(string $slug, string $lang): string => ($this->termLabel)('categories', $slug, $lang),
            fn(ContentItem $item, string $lang): string => ($this->absoluteUrl)(ContentPaths::build($item->type, $item->slug, $lang, (string)((($this->settings)()['home_page'] ?? '') !== '' ? ($this->settings)()['home_page'] : 'index'), ($this->defaultLanguage)())),
            fn(): array => array_values(array_filter($this->content->getTypes(), fn(string $type): bool => $type !== 'forms' && $type !== 'pages' && $this->hasPlaceField($type))),
            [
                'tiles_url' => (string)(($this->settings)()['apis']['maps']['tiles_url'] ?? ''),
                'attribution' => (string)(($this->settings)()['apis']['maps']['attribution'] ?? ''),
            ]
        );
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
