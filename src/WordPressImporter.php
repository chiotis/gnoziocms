<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Brings the content of a WordPress site over: its pages and posts as Markdown files, its categories and tags as terms,
 * the pictures and files they use into the media library, and the list of old addresses that must lead to the new ones.
 *
 * It works in two steps. plan() reads the site and works out everything without changing anything (what would be
 * created, updated or skipped, which files would be downloaded, which links cannot be resolved). apply() then does it.
 * Every id is made from the address it came from, so running it again updates what it brought and never makes a second
 * copy; a file of the site's own that was not brought by an import is never overwritten unless asked.
 *
 * WordPress keeps categories in a tree and so do this site's: a category keeps its name and its parent, its address is
 * the old one (/category/news/child), and a post is filed under the categories it has (the page of a parent also lists
 * what is filed under its children). Two terms of one slug under different parents are different terms. Duplicates are merged
 * before anything is made of them (WordPressTermMerge): the profile's `term_map`, and, when the profile asks (`merge_same_names`), names that differ only in case or accents.
 *
 * A profile's `rest_types` (WordPressRestTypes) bring the custom post types the REST API shows, with their ACF fields: an entry
 * in each language the record has text in, linked by `translation_id`, its place, route file, pictures and video, and the
 * terms of the site's own taxonomies.
 */
final class WordPressImporter
{
    private const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'rtf', 'txt', 'zip', 'mp3', 'wav', 'ogg', 'm4a', 'mp4', 'webm', 'mov'];

    /** @var array<string, array{id: string, ext: string, name: string, alt: string, uses: int, attachment: bool}> canonical address => file */
    private array $mediaFiles = [];

    /** @var array<int, string> attachment id => canonical address */
    private array $attachmentUrls = [];

    /** @var array<string, bool> canonical address => it is in the library now */
    private array $mediaReady = [];

    /** @var array<string, string> old path (no slashes at the ends) => new public path */
    private array $linkMap = [];

    /** @var array<string, int> */
    private array $unresolved = [];

    /** @var array<string, int> host => pictures of other sites that the text points to */
    private array $external = [];

    /** @var array<string, array{slug: string, label: string, description: string, parent: int, link: string}> */
    private array $termInfo = [];

    /** @var array<string, bool> canonical address => the old site does not have it either */
    private array $mediaGone = [];

    private bool $final = false;

    private bool $planned = false;

    /** @var string where the logo will be in the library, and the old address that stands for it */
    private string $logo = '';
    private string $logoMedia = '';

    /** @var array<string, array<string, mixed>> type:old slug => the page of a custom type that was read */
    private array $scrapedBySlug = [];

    /** @var array<int, array{image: string, title: string, text: string, buttons: array<int, array{label: string, url: string}>}> */
    private array $slides = [];

    /** @var array<string, mixed> the profile's custom types, term map and languages, checked (WordPressRestTypes::parse) */
    private array $rest = [];

    /** @var array<string, mixed> what the end of the run reports: merges, skipped terms, entries without a place or a translation */
    private array $report = [];

    /** @var array<string, float> canonical address of a route file => its length in km, once it is in the library */
    private array $trackKm = [];

    /**
     * @param \Closure(string, string): int $download fetches an address into a file and returns the answer's status, 0 when the site could not be reached
     * @param array{lang?: string, default_lang?: string, home_slug?: string, overwrite?: bool, only_used_media?: bool, kinds?: string[], custom_dir?: string, menus?: bool} $options
     * @param array<string, mixed> $profile what to read from the pages of the site, and how to lay out the home page (see docs/wordpress-import.md)
     */
    public function __construct(
        private WordPressReader $reader,
        private string $contentDir,
        private MediaLibrary $media,
        private Taxonomies $taxonomies,
        private \Closure $download,
        private array $options = [],
        private ?WordPressScraper $scraper = null,
        private array $profile = [],
        private ?\Closure $writeMenu = null
    ) {
        $this->contentDir = rtrim($contentDir, '/');
    }

    private function lang(): string
    {
        return (string)($this->options['lang'] ?? 'en');
    }

    private function defaultLang(): string
    {
        return (string)($this->options['default_lang'] ?? $this->lang());
    }

    private function homeSlug(): string
    {
        return (string)($this->options['home_slug'] ?? 'index');
    }

    /**
     * Reads the site and says what an import would do. Nothing is written and no file is downloaded.
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $this->reset();
        $info = $this->reader->info();
        $kinds = $this->options['kinds'] ?? ['pages', 'posts', 'types'];
        $this->rest = WordPressRestTypes::parse($this->profile);
        if ($this->rest['errors'] !== []) {
            throw new \InvalidArgumentException('The profile has mistakes: ' . implode('; ', $this->rest['errors']));
        }
        $withTypes = in_array('types', $kinds, true) && $this->rest['types'] !== [];
        $restTaxonomies = $withTypes ? $this->rest['taxonomies'] : [];

        $categories = in_array('posts', $kinds, true) || isset($restTaxonomies['categories']) ? $this->reader->terms('categories') : [];
        $tags = (in_array('posts', $kinds, true) || isset($restTaxonomies['tags'])) && in_array('tags', $info['taxonomies'], true) ? $this->reader->terms('tags') : [];
        $terms = ['categories' => $this->termsOf('categories', 'categories', $categories, true), 'tags' => $this->termsOf('tags', 'tags', $tags, false)];
        $titles = [];
        foreach ($restTaxonomies as $base => $here) {
            if ($base === 'categories' || $base === 'tags') {
                continue;
            }
            if ($info['taxonomies'] !== [] && !in_array($base, $info['taxonomies'], true)) {
                $this->report['notes'][] = "taxonomy '$base': the site's API does not list it, so it was not read";
                continue;
            }
            $terms[$here] = $this->termsOf($base, $here, $this->reader->terms($base), true);
            $titles[$here] = (string)($info['taxonomy_names'][$base] ?? '');
        }

        foreach ($this->reader->media() as $file) {
            $this->attachmentUrls[$file['id']] = $this->registerMedia($file['url'], $file['alt'], $file['title'], true);
        }

        $entries = [];
        $slugs = [];
        foreach (['pages' => 'pages', 'posts' => 'posts'] as $base => $type) {
            if (!in_array($base, $kinds, true)) {
                continue;
            }
            foreach ($this->reader->entries($base) as $entry) {
                $slug = $this->slugFor($entry, $type === 'pages' && $entry['id'] === $info['home_page_id'], $slugs[ContentPaths::isRoot($type) ? 'root' : $type] ?? []);
                $slugs[ContentPaths::isRoot($type) ? 'root' : $type][$slug] = true;
                $entry['type'] = $type;
                $entry['new_slug'] = $slug;
                $entry['new_path'] = ContentPaths::build($type, $slug, $this->lang(), $this->homeSlug(), $this->defaultLang());
                // When a page and a post share one old address, the page is what the old site showed there.
                $this->linkMap[self::pathOf($entry['link'])] ??= $entry['new_path'];
                $entries[] = $entry;
            }
        }
        foreach ($terms as $name => $list) {
            foreach ($list as $term) {
                foreach (array_merge([$term['link']], $term['alias_links'] ?? []) as $link) {
                    $this->linkMap[self::pathOf($link)] = $this->termPath($name, $term['path']);
                }
            }
        }

        // What the API does not give, read from the pages themselves.
        $this->slides = $this->scraper?->slides() ?? [];
        $logo = $this->scraper?->logo() ?? '';
        if ($logo !== '') {
            $this->logoMedia = $this->registerMedia($logo, '', '', false, true);
            $this->logo = $this->mediaPath($this->mediaFiles[$this->logoMedia]);
        }
        $menus = [];
        foreach (array_keys((array)($this->profile['menus'] ?? [])) as $menuKey) {
            $menus[(string)$menuKey] = $this->scraper?->menu((string)$menuKey) ?? [];
        }
        $scraped = $this->scraper?->entries() ?? [];
        $inCategories = [];
        if ($scraped !== [] && ($this->profile['categories_of_custom_types'] ?? false) && $categories !== []) {
            $inCategories = $this->scraper->categoryIds(array_column($scraped, 'link'), $categories);
        }
        foreach ($scraped as $entry) {
            $entry['category_ids'] = $inCategories[$entry['link']] ?? [];
            $type = $entry['type'];
            $slug = $this->slugFor($entry + ['title' => $entry['title']], false, $slugs[ContentPaths::isRoot($type) ? 'root' : $type] ?? []);
            $slugs[ContentPaths::isRoot($type) ? 'root' : $type][$slug] = true;
            $entry['new_slug'] = $slug;
            $entry['new_path'] = ContentPaths::build($type, $slug, $this->lang(), $this->homeSlug(), $this->defaultLang());
            $this->linkMap[self::pathOf($entry['link'])] ??= $entry['new_path'];
            $this->scrapedBySlug[$type . ':' . $entry['id']] = $entry;
            $entries[] = $entry;
        }
        if ($withTypes) {
            foreach ($this->restEntries($slugs, $info['types']) as $entry) {
                if ($entry['old'] !== '') {
                    $this->linkMap[self::pathOf($entry['old'])] ??= $entry['new_path'];
                }
                $entries[] = $entry;
            }
        }
        foreach ((array)($this->profile['archives'] ?? []) as $oldPath => $newType) {
            $this->linkMap[trim((string)$oldPath, '/')] = ContentPaths::archive((string)$newType, $this->lang(), $this->defaultLang());
            foreach ($this->rest['language_prefixes'] as $lang => $prefix) {
                if ($lang !== $this->lang()) {
                    $this->linkMap[trim($prefix . '/' . trim((string)$oldPath, '/'), '/')] = ContentPaths::archive((string)$newType, (string)$lang, $this->defaultLang());
                }
            }
        }

        $items = [];
        $notes = [];
        $redirects = [];
        foreach ($entries as $entry) {
            $made = $this->render($entry, $terms);
            foreach ($made['notes'] as $note) {
                $notes[$entry['type'] . '/' . $entry['new_slug'] . ': ' . $note] = 1;
            }
            $file = $this->contentDir . '/' . $entry['type'] . '/' . (new ContentPaths(['languages' => ['default' => $this->defaultLang()]]))->filename($entry['new_slug'], (string)($entry['lang'] ?? $this->lang()));
            [$state, $reason] = $this->stateOf($file, $entry['link']);
            $items[] = [
                'id' => $entry['id'],
                'type' => $entry['type'],
                'lang' => (string)($entry['lang'] ?? $this->lang()),
                'slug' => $entry['new_slug'],
                'title' => $entry['title'],
                'file' => $file,
                'state' => $state,
                'reason' => $reason,
                'from' => $entry['link'],
                'path' => $entry['new_path'],
                'words' => (int)preg_match_all('/\S+/u', $made['body']),
            ];
            if (($entry['old'] ?? $entry['link']) !== '') {
                $this->addRedirect($redirects, $entry['old'] ?? $entry['link'], '/' . $entry['new_path']);
            }
        }
        foreach ($terms as $name => $list) {
            foreach ($list as $term) {
                // A merged term's old address leads to the term it went into, in every language the old site has.
                foreach (array_merge([$term['link']], $term['alias_links'] ?? []) as $link) {
                    $this->addRedirect($redirects, $link, '/' . $this->termPath($name, $term['path']));
                    foreach ($this->rest['language_prefixes'] as $lang => $prefix) {
                        if ($lang !== $this->lang() && $link !== '') {
                            $this->addRedirect($redirects, $prefix . '/' . self::pathOf($link), '/' . $this->termPath($name, $term['path'], (string)$lang));
                        }
                    }
                }
                foreach ($term['lang_links'] ?? [] as $lang => $links) {
                    foreach ($links as $link) {
                        $this->addRedirect($redirects, $link, '/' . $this->termPath($name, $term['path'], (string)$lang));
                    }
                }
            }
        }
        foreach ((array)($this->profile['archives'] ?? []) as $oldPath => $newType) {
            $this->addRedirect($redirects, '/' . trim((string)$oldPath, '/'), '/' . ContentPaths::archive((string)$newType, $this->lang(), $this->defaultLang()));
            foreach ($this->rest['language_prefixes'] as $lang => $prefix) {
                if ($lang !== $this->lang()) {
                    $this->addRedirect($redirects, $prefix . '/' . trim((string)$oldPath, '/'), '/' . ContentPaths::archive((string)$newType, (string)$lang, $this->defaultLang()));
                }
            }
        }

        $onlyUsed = (bool)($this->options['only_used_media'] ?? false);
        // An old address that is a page of this site's own must not be sent anywhere else.
        $own = [];
        foreach ($items as $item) {
            $own['/' . $item['path']] = true;
        }
        // The pages of the terms are pages of this site too, in each language the import writes (a merged term's old address can be
        // one of them).
        foreach ($terms as $name => $list) {
            foreach ($list as $term) {
                foreach (array_unique(array_merge([$this->lang()], array_map('strval', array_keys($this->rest['language_prefixes'])))) as $lang) {
                    $own['/' . $this->termPath($name, $term['path'], (string)$lang)] = true;
                }
            }
        }
        $redirects = array_values(array_filter($redirects, static fn(array $r): bool => !isset($own[rtrim($r[0], '/')])));
        $media = array_filter($this->mediaFiles, static fn(array $file): bool => $file['uses'] > 0 || (!$onlyUsed && $file['attachment']));
        $this->planned = true;
        return [
            'site' => ['name' => $info['name'], 'url' => $info['url'], 'description' => $info['description']],
            'items' => $items,
            'entries' => $entries,
            'terms' => $terms,
            'taxonomy_titles' => $titles,
            'report' => array_map(static fn(array $lines): array => array_values(array_unique($lines)), $this->report) + ['merged' => [],'skipped_terms' => [], 'term_map_unknown' => [], 'no_coordinates' => [], 'one_coordinate' => [], 'missing_translation' => [], 'unmapped' => [], 'field_notes' => [], 'notes' => []],
            'route_files' => count(array_filter($media, static fn(array $f): bool => !empty($f['track']))),
            'menus' => $menus,
            'slides' => count($this->slides),
            'has_logo' => $this->logo !== '',
            'media' => $media,
            'redirects' => $redirects,
            'notes' => array_keys($notes),
            'unresolved' => $this->unresolved,
            'external_images' => $this->external,
            'counts' => [
                'new' => count(array_filter($items, static fn(array $i): bool => $i['state'] === 'new')),
                'update' => count(array_filter($items, static fn(array $i): bool => $i['state'] === 'update')),
                'skip' => count(array_filter($items, static fn(array $i): bool => $i['state'] === 'skip')),
                'media' => count($media),
                'redirects' => count($redirects),
            ],
        ];
    }

    /**
     * Does what plan() described: terms, then files, then the pages and posts.
     *
     * @param array<string, mixed> $plan the result of plan()
     * @return array{written: int, updated: int, skipped: int, media_new: int, media_failed: string[], terms_added: int, redirects: array<int, array{0: string, 1: string}>, notes: string[], menus: string[], content_types: string[], logo: string}
     */
    public function apply(array $plan): array
    {
        if (!$this->planned) {
            throw new \LogicException('apply() needs the same importer that made the plan.');
        }
        $this->final = true;
        $result = ['written' => 0, 'updated' => 0, 'skipped' => 0, 'media_new' => 0, 'media_failed' => [], 'route_files_failed' => [], 'terms_added' => 0, 'terms_updated' => 0, 'redirects' => [], 'notes' => [], 'menus' => [], 'content_types' => [], 'logo' => ''];

        foreach ($plan['terms'] as $name => $list) {
            [$added, $updated] = $this->saveTerms((string)$name, $list, (string)($plan['taxonomy_titles'][$name] ?? ''));
            $result['terms_added'] += $added;
            $result['terms_updated'] += $updated;
        }

        foreach ($plan['media'] as $canonical => $file) {
            if ($this->media->find($file['id']) !== null) {
                $this->mediaReady[$canonical] = true;
                $this->measureTrack($canonical, $file);
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'wpimp');
            if ($tmp === false) {
                $result['media_failed'][] = $canonical;
                continue;
            }
            $status = ($this->download)($canonical, $tmp);
            if ($status !== 200 && isset($file['original']) && $file['original'] !== $canonical) {
                $status = ($this->download)((string)$file['original'], $tmp);
            }
            try {
                if ($status !== 200) {
                    $this->mediaGone[$canonical] = $status === 404 || $status === 410;
                    throw new \RuntimeException($status === 404 || $status === 410 ? 'missing on the old site too, so the references to it are dropped' : 'the site answered ' . $status);
                }
                $this->media->import($tmp, $file['name'], $file['id'], $file['alt'], 'wordpress-import');
                $this->mediaReady[$canonical] = true;
                $this->measureTrack($canonical, $file);
                $result['media_new']++;
            } catch (\Throwable $e) {
                $result['media_failed'][] = $canonical . ' (' . $e->getMessage() . ')';
                if (!empty($file['track'])) {
                    // The library keeps a route file only when it reads as one; the entries that point at it are listed.
                    $result['route_files_failed'][] = $canonical . ' (' . $e->getMessage() . ') for ' . implode(', ', $file['entries'] ?? []);
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        $result['logo'] = $this->logoMedia !== '' && !empty($this->mediaReady[$this->logoMedia]) ? $this->logo : '';
        $result['menus'] = [];
        foreach ($plan['menus'] as $key => $items) {
            if ($items === []) {
                continue;
            }
            if (($this->options['menus'] ?? false) && $this->writeMenu !== null) {
                ($this->writeMenu)((string)$key, ['title' => Slug::title((string)$key) . ' menu', 'items' => $this->menuRows($items)]);
                $result['menus'][] = (string)$key;
            }
        }
        foreach ((array)($this->profile['content_types'] ?? []) as $name => $definition) {
            $path = rtrim((string)($this->options['custom_dir'] ?? ''), '/') . '/content-types/' . Slug::plain((string)$name) . '.yaml';
            if (($this->options['custom_dir'] ?? '') !== '' && !is_file($path) && is_array($definition)) {
                @mkdir(dirname($path), 0775, true);
                file_put_contents($path, Yaml::dump($definition, 6, 2));
                $result['content_types'][] = (string)$name;
            }
        }

        // The conversion runs again now that it is known which files arrived: a link to a file that did not come stays as it was.
        $notes = [];
        $redirects = $plan['redirects'];
        foreach ($plan['media'] as $canonical => $file) {
            if (!empty($this->mediaReady[$canonical])) {
                foreach ($file['seen'] ?? [] as $oldPath) {
                    $this->addRedirect($redirects, $oldPath, $this->mediaPath($file));
                }
            }
        }
        $byLink = []; // a page and a post can share an address on the old site, so they are told apart by kind and id
        foreach ($plan['items'] as $item) {
            $byLink[$item['type'] . '/' . $item['id'] . '/' . $item['lang']] = $item;
        }
        foreach ($plan['entries'] as $entry) {
            $item = $byLink[$entry['type'] . '/' . $entry['id'] . '/' . ($entry['lang'] ?? $this->lang())] ?? null;
            if ($item === null || $item['state'] === 'skip') {
                $result['skipped']++;
                continue;
            }
            $made = $this->render($entry, $plan['terms']);
            foreach ($made['notes'] as $note) {
                $notes[$entry['type'] . '/' . $entry['new_slug'] . ': ' . $note] = 1;
            }
            $dir = dirname($item['file']);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($item['file'], "---\n" . Yaml::dump($made['front'], 4, 2) . "---\n\n" . $made['body']);
            $item['state'] === 'update' ? $result['updated']++ : $result['written']++;
        }
        $result['redirects'] = $redirects;
        $result['notes'] = array_keys($notes);
        $this->taxonomies->forget();
        return $result;
    }

    /**
     * The text of a redirects list, one "old new 301" per line, in the form Admin > Redirects takes pasted.
     *
     * @param array<int, array{0: string, 1: string}> $redirects
     */
    public static function redirectList(array $redirects): string
    {
        $lines = ['# Old address, new address, code. Paste into Admin > Redirects > Import.'];
        foreach ($redirects as [$from, $to]) {
            $lines[] = $from . ' ' . $to . ' 301';
        }
        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------------ entries

    private function reset(): void
    {
        $this->mediaFiles = [];
        $this->attachmentUrls = [];
        $this->mediaReady = [];
        $this->linkMap = [];
        $this->unresolved = [];
        $this->termInfo = [];
        $this->mediaGone = [];
        $this->external = [];
        $this->slides = [];
        $this->scrapedBySlug = [];
        $this->logo = '';
        $this->logoMedia = '';
        $this->report = [];
        $this->trackKm = [];
        $this->planned = false;
        $this->final = false;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, bool> $taken addresses already used by this kind
     */
    private function slugFor(array $entry, bool $isHome, array $taken): string
    {
        if ($isHome) {
            return $this->homeSlug();
        }
        $slug = Slug::plain((string)$entry['slug']);
        if ($slug === '' || $slug !== $entry['slug']) {
            $slug = Slug::fromText((string)$entry['title']);
        }
        if ($slug === '') {
            $slug = Slug::plain($entry['type'] ?? 'item') . '-' . $entry['id'];
        }
        $base = $slug;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /**
     * What an existing file means for the import.
     *
     * @return array{0: string, 1: string} new, update or skip, and why
     */
    private function stateOf(string $file, string $from): array
    {
        if (!is_file($file)) {
            return ['new', ''];
        }
        [$yaml] = FrontMatter::split((string)file_get_contents($file));
        $front = [];
        try {
            $parsed = Yaml::parse($yaml);
            $front = is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            $front = [];
        }
        if (($front['imported_from'] ?? '') === $from) {
            return ['update', 'brought by an earlier import'];
        }
        if ($this->options['overwrite'] ?? false) {
            return ['update', 'replaced on request'];
        }
        // The editor of the admin keeps front matter it does not know under custom_fields when it saves a file.
        if (is_array($front['custom_fields'] ?? null) && ($front['custom_fields']['imported_from'] ?? '') === $from) {
            return ['skip', 'edited in the admin since it was imported; --overwrite replaces it'];
        }
        return ['skip', 'a file of the site with this address exists'];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<int, array<string, mixed>>> $terms
     * @return array{front: array<string, mixed>, body: string, notes: string[]}
     */
    private function render(array $entry, array $terms): array
    {
        if (isset($entry['post_type'])) {
            return $this->renderScraped($entry, $terms);
        }
        if (isset($entry['rest'])) {
            return $this->renderRest($entry, $terms);
        }
        $converter = new HtmlToMarkdown(fn(string $address, string $kind): string => $this->resolve($address, $kind));
        $body = $converter->convert((string)$entry['html']);

        $front = ['title' => $entry['title'] !== '' ? $entry['title'] : Slug::title($entry['new_slug']), 'status' => 'published', 'visible' => true];
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$entry['date'], $m)) {
            $front['date'] = $m[0];
        }
        $excerpt = $this->explicitExcerpt((string)$entry['excerpt'], (string)$entry['html']);
        if ($excerpt !== '') {
            $front['excerpt'] = $excerpt;
        }
        if ($entry['image'] > 0 && isset($this->attachmentUrls[$entry['image']])) {
            $canonical = $this->attachmentUrls[$entry['image']];
            $this->mediaFiles[$canonical]['uses']++;
            $front['main_image'] = $this->resolve($canonical, 'image');
        }
        if ($entry['type'] === 'posts') {
            $categories = $this->termIds($entry['categories'], $terms['categories']);
            if ($categories !== []) {
                $front['categories'] = $categories;
            }
            $tagSlugs = [];
            foreach ($entry['tags'] as $id) {
                foreach ($terms['tags'] as $term) {
                    if ($term['id'] === $id) {
                        $tagSlugs[] = $term['term_id'];
                    }
                }
            }
            if ($tagSlugs !== []) {
                $front['tags'] = $tagSlugs;
            }
        }
        $front['imported_from'] = $entry['link'];
        $home = $this->profile['home']['blocks'] ?? null;
        if ($entry['type'] === 'pages' && $entry['new_slug'] === $this->homeSlug() && is_array($home) && $home !== []) {
            [$image, $text] = self::splitLeadingImage($body);
            $front['template'] = 'landing';
            $front['blocks'] = $this->fill($home, ['@slides' => $this->slideItems(), '@body' => $text, '@image' => $image]);
            $front['blocks'] = $this->fillBooks($front['blocks']);
            $body = '';
        }
        return ['front' => $front, 'body' => $body, 'notes' => $converter->notes()];
    }

    /**
     * A page of a custom type (a book, a play): the cover and the description side by side, and the tabs as a Tabs block.
     *
     * @param array<string, mixed> $entry
     * @param array<string, array<int, array<string, mixed>>> $terms
     * @return array{front: array<string, mixed>, body: string, notes: string[]}
     */
    private function renderScraped(array $entry, array $terms): array
    {
        $converter = new HtmlToMarkdown(fn(string $address, string $kind): string => $this->resolve($address, $kind));
        $summary = trim($converter->convert((string)$entry['summary']));
        $image = $entry['image'] !== '' ? $this->resolve((string)$entry['image'], 'image') : '';
        $front = ['title' => $entry['title'], 'status' => 'published', 'visible' => true];
        if ($image !== '') {
            $front['main_image'] = $image;
        }
        // Front matter the profile wants on every page of the type (the opening layout, say).
        foreach ((array)($this->profile['types'][$entry['post_type']]['front'] ?? []) as $key => $value) {
            $front[(string)$key] = $value;
        }
        $categories = $this->termIds($entry['category_ids'] ?? [], $terms['categories'] ?? []);
        if ($categories !== []) {
            $front['categories'] = $categories;
        }
        $blocks = [];
        $rules = (array)($this->profile['types'][$entry['post_type']] ?? []);
        if (($rules['summary_in'] ?? '') === 'excerpt') {
            // The page of the content type shows the summary under the title and the cover beside it (the Books type does).
            $plain = WordPressReader::text((string)$entry['summary']);
            if ($plain !== '') {
                $front['excerpt'] = $plain;
            }
        } elseif ($summary !== '' || $image !== '') {
            $blocks[] = ['type' => 'text-image', 'variant' => 'image-left', 'body' => $summary, 'image' => $image, 'image_alt' => $image !== '' ? $entry['title'] : '', 'image_ratio' => 'portrait'];
        }
        $tabs = [];
        foreach ($entry['tabs'] as $tab) {
            $tabs[] = ['label' => $tab['label'], 'text' => trim($converter->convert($tab['html']))];
        }
        if ($tabs !== []) {
            $blocks[] = ['type' => 'tabs', 'variant' => 'horizontal', 'items' => $tabs];
        }
        if ($blocks !== []) {
            $front['blocks'] = $blocks;
        }
        $front['imported_from'] = $entry['link'];
        return ['front' => $front, 'body' => '', 'notes' => $converter->notes()];
    }

    /** @return array<int, array<string, mixed>> the slides of the home page as items of a hero slider block: text over a picture, with up to three buttons */
    private function slideItems(): array
    {
        $items = [];
        foreach ($this->slides as $slide) {
            $item = ['layout' => 'cover', 'image' => $slide['image'] !== '' ? $this->resolve($slide['image'], 'image') : '', 'heading' => $slide['title'], 'text' => $slide['text']];
            // A slide of a hero slider has up to three buttons: the first of the old slide's is the primary one.
            foreach (array_slice($slide['buttons'], 0, 3) as $number => $button) {
                $item['buttons'][] = ['label' => $button['label'], 'url' => $this->resolve($button['url'], 'link')] + ($number === 0 ? [] : ['style' => 'secondary']);
            }
            $items[] = $item;
        }
        return $items;
    }

    /** The picture a text starts with, and the text after it. @return array{0: string, 1: string} */
    private static function splitLeadingImage(string $markdown): array
    {
        if (preg_match('/\A!\[[^\]]*\]\(([^)\s]+)\)\s*(.*)\z/s', ltrim($markdown), $m)) {
            return [$m[1], trim($m[2])];
        }
        return ['', trim($markdown)];
    }

    /**
     * A template with the words @slides, @body and @image in it, filled in.
     *
     * @param array<string, mixed> $values
     */
    private function fill(mixed $template, array $values): mixed
    {
        if (is_string($template)) {
            return array_key_exists($template, $values) ? $values[$template] : $template;
        }
        if (!is_array($template)) {
            return $template;
        }
        $out = [];
        foreach ($template as $key => $value) {
            $out[$key] = $this->fill($value, $values);
        }
        return $out;
    }

    /**
     * Words of the form @page:<type>:<slug>:<field> in the blocks, filled from a page of a custom type that was read
     * from the site: title, text (the description as Markdown), image, address, or excerpt (the first words of the
     * description). A page that is not there leaves the words as they are.
     *
     * @param mixed $blocks
     * @return mixed
     */
    private function fillBooks(mixed $blocks): mixed
    {
        if (is_string($blocks) && preg_match('/^@page:([a-z0-9_-]+):([^:]+):(title|text|image|address|excerpt)$/i', $blocks, $m)) {
            $entry = $this->scrapedBySlug[$m[1] . ':' . $m[2]] ?? null;
            if ($entry === null) {
                return $blocks;
            }
            $converter = new HtmlToMarkdown(fn(string $address, string $kind): string => $this->resolve($address, $kind));
            return match ($m[3]) {
                'title' => $entry['title'],
                'text' => trim($converter->convert((string)$entry['summary'])),
                'image' => $entry['image'] !== '' ? $this->resolve((string)$entry['image'], 'image') : '',
                'address' => '/' . $entry['new_path'],
                default => $this->shorten(WordPressReader::text((string)$entry['summary']), 360),
            };
        }
        if (!is_array($blocks)) {
            return $blocks;
        }
        foreach ($blocks as $key => $value) {
            $blocks[$key] = $this->fillBooks($value);
        }
        return $blocks;
    }

    private function shorten(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length, 'UTF-8'), " ,.;:") . '…' : $text;
    }

    /**
     * A menu read from the pages, as items the Menus class writes, with their addresses on the new site.
     *
     * @param array<int, array{label: string, url: string, children?: array<int, mixed>}> $items
     * @return array<int, array<string, mixed>>
     */
    private function menuRows(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $row = ['label' => $item['label'], 'url' => $this->resolve($item['url'], 'link')];
            if (!empty($item['children'])) {
                $row['children'] = $this->menuRows($item['children']);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** The excerpt an author wrote; one WordPress cut from the text on its own is left out. */
    private function explicitExcerpt(string $excerptHtml, string $bodyHtml): string
    {
        $excerpt = WordPressReader::text($excerptHtml);
        $excerpt = trim(preg_replace('/\s*(\[…\]|\[&hellip;\]|…\s*continue reading|…|\.\.\.)$/ui', '', $excerpt) ?? $excerpt);
        if ($excerpt === '') {
            return '';
        }
        $compact = static fn(string $text): string => preg_replace('/\s+/u', '', $text) ?? $text;
        $head = mb_substr($compact($excerpt), 0, 40);
        return str_starts_with($compact(WordPressReader::text($bodyHtml)), $head) ? '' : $excerpt;
    }

    // ------------------------------------------------------------------ terms

    /**
     * The terms of a taxonomy as this site keeps them. A WordPress category keeps its parent: the address is the old one
     * (`/category/news/child`), a name used under several parents ("Logicomix" under News and under Reviews) stays one
     * name, and a term is only told apart from another of the same slug by its parent. A taxonomy without a tree (tags)
     * is listed flat.
     *
     * `id` is WordPress's number; `term_id` is what entries are filed under here (the slug, with a number added when
     * two terms have the same slug under different parents), the same on every run; `parent_term` is the `term_id` of the
     * parent; `path` is the address after the taxonomy's word.
     *
     * @param array<int, array{id: int, slug: string, name: string, link: string, parent: int, count: int, description: string}> $terms
     * @return array<int, array{id: int, term_id: string, slug: string, parent_term: string, path: string, label: string, description: string, parent: int, link: string}>
     */
    private function buildTerms(array $terms, bool $tree): array
    {
        $byId = [];
        foreach ($terms as $term) {
            $byId[$term['id']] = $term;
        }
        $slugs = [];
        $ids = [];
        $used = [];
        foreach ($terms as $term) {
            $slug = Slug::plain($term['slug']);
            $slugs[$term['id']] = $slug !== '' && $slug === $term['slug'] ? $slug : (Slug::fromText($term['name']) ?: 'term-' . $term['id']);
            $id = $slugs[$term['id']];
            for ($n = 2; isset($used[$id]); $n++) {
                $id = $slugs[$term['id']] . '-' . $n;
            }
            $used[$id] = true;
            $ids[$term['id']] = $id;
        }
        $parentOf = static fn(array $term): int => $tree && isset($byId[$term['parent']]) && $term['parent'] !== $term['id'] ? $term['parent'] : 0;
        $list = [];
        foreach ($terms as $term) {
            $chain = [$slugs[$term['id']]];
            $at = $parentOf($term);
            for ($guard = 0; $at > 0 && $guard < 20; $guard++) {
                array_unshift($chain, $slugs[$at]);
                $at = $parentOf($byId[$at]);
            }
            $parent = $parentOf($term);
            $list[] = [
                'id' => $term['id'],
                'term_id' => $ids[$term['id']],
                'slug' => $slugs[$term['id']],
                'parent_term' => $parent > 0 ? $ids[$parent] : '',
                'path' => implode('/', $chain),
                'label' => $term['name'],
                'description' => $term['description'],
                'parent' => $parent,
                'link' => $term['link'],
            ];
        }
        return $list;
    }

    /**
     * The terms of a post, as what entries are filed under here. Only the terms the post has: the page of a parent also
     * lists what is filed under its children.
     *
     * @param int[] $ids
     * @param array<int, array{id: int, term_id: string}> $terms
     * @return string[]
     */
    private function termIds(array $ids, array $terms): array
    {
        $byId = [];
        foreach ($terms as $term) {
            $byId[$term['id']] = $term['term_id'];
            // A post filed under a term that was merged is filed under the term it went into.
            foreach ($term['aliases'] ?? [] as $alias) {
                $byId[$alias] = $term['term_id'];
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[$byId[$id]] = true;
            }
        }
        return array_keys($out);
    }

    private function termPath(string $taxonomy, string $path, ?string $lang = null): string
    {
        $lang ??= $this->lang();
        $prefix = $lang === $this->defaultLang() ? '' : $lang . '/';
        return $prefix . Taxonomies::kind($taxonomy) . '/' . $path;
    }

    /**
     * The terms of a taxonomy of the old site as this one keeps them: duplicates merged (and reported), then the tree and the
     * ids of buildTerms(), each with its source address (`imported_from`), the ids of the terms merged into it, and its names
     * in the other languages the profile names a prefix for.
     *
     * @param array<int, array{id: int, slug: string, name: string, link: string, parent: int, count: int, description: string}> $raw
     * @return array<int, array<string, mixed>>
     */
    private function termsOf(string $base, string $here, array $raw, bool $tree): array
    {
        if ($raw === []) {
            return [];
        }
        $merged = WordPressTermMerge::apply($raw, $this->rest['term_map'][$base] ?? [], (bool)$this->rest['skip_empty_terms'], (bool)$this->rest['merge_same_names']);
        foreach ($merged['merges'] as $m) {
            $this->report['merged'][] = "$here: {$m['from_name']} ({$m['from']}) into {$m['into_name']} ({$m['into']}), " . ($m['why'] === 'term_map' ? 'term_map' : "same name; pin it with term_map.$base.{$m['from']}: {$m['into']}");
        }
        foreach ($merged['skipped'] as $t) {
            $this->report['skipped_terms'][] = "$here: {$t['name']} ({$t['id']}), no entries";
        }
        foreach ($merged['unknown'] as $u) {
            $this->report['term_map_unknown'][] = "term_map.$base.{$u['from']}: {$u['into']} names a term the site does not have, or makes a circle";
        }
        $list = $this->buildTerms($merged['terms'], $tree);
        foreach ($list as $i => $term) {
            $source = $merged['terms'][$i];
            $list[$i]['aliases'] = $source['aliases'];
            $list[$i]['alias_links'] = $source['alias_links'];
            $list[$i]['imported_from'] = $this->reader->site() . '/wp-json/wp/v2/' . $base . '/' . $source['id'];
            $list[$i]['labels'] = [$this->lang() => $term['label']];
            $list[$i]['descriptions'] = [$this->lang() => $term['description']];
        }
        // The names in another language, from the old site's pages in that language, matched by id and then by address.
        foreach ($this->rest['language_prefixes'] as $lang => $prefix) {
            if ($lang === $this->lang()) {
                continue;
            }
            try {
                $other = $this->reader->under($prefix)->terms($base);
            } catch (\Throwable $e) {
                $this->report['notes'][] = "taxonomy '$base' in '$lang': " . $e->getMessage();
                continue;
            }
            $byId = array_column($other, null, 'id');
            $bySlug = array_column($other, null, 'slug');
            foreach ($list as $i => $term) {
                $match = $byId[$term['id']] ?? $bySlug[$merged['terms'][$i]['slug']] ?? null;
                // The old addresses in that language (its own slug, /en/activity/bird-watching, of the term and of those merged
                // into it) lead to the term's page in that language, besides the prefix put before the main language's address.
                foreach (array_merge([$match], array_map(static fn(int $alias): ?array => $byId[$alias] ?? null, $merged['terms'][$i]['aliases'])) as $found) {
                    if ($found !== null && $found['link'] !== '') {
                        $list[$i]['lang_links'][$lang][] = $found['link'];
                    }
                }
                if ($match !== null && $match['name'] !== '' && $match['name'] !== $term['label']) {
                    $list[$i]['labels'][$lang] = $match['name'];
                    if ($match['description'] !== '' && $match['description'] !== $term['description']) {
                        $list[$i]['descriptions'][$lang] = $match['description'];
                    }
                }
            }
        }
        return $list;
    }

    /**
     * Adds the terms that are not in the taxonomy yet. A term an earlier import brought (the same `imported_from`) gets the
     * names and descriptions it has no text for yet; every other term of the site is left as it is. A taxonomy that has no
     * file yet takes the old site's name for it, and has a tree only when its terms do.
     *
     * @param array<int, array{term_id: string, slug: string, parent_term: string, label: string, description: string, labels?: array<string, string>, descriptions?: array<string, string>, imported_from?: string}> $list
     * @return array{0: int, 1: int} added, and updated
     */
    private function saveTerms(string $name, array $list, string $title = ''): array
    {
        if ($list === []) {
            return [0, 0];
        }
        $isNew = Taxonomies::isCustom($name) && !in_array($name, $this->taxonomies->names(), true);
        $current = $this->taxonomies->load($name);
        $terms = $current['terms'];
        $at = [];
        foreach ($terms as $i => $term) {
            $at[$term['id']] = $i;
        }
        $added = 0;
        $updated = 0;
        foreach ($list as $term) {
            $labels = $term['labels'] ?? [$this->lang() => $term['label']];
            $descriptions = $term['descriptions'] ?? [$this->lang() => $term['description']];
            $from = (string)($term['imported_from'] ?? '');
            if (isset($at[$term['term_id']])) {
                $i = $at[$term['term_id']];
                if ($from === '' || ($terms[$i]['imported_from'] ?? '') !== $from) {
                    continue;
                }
                $changed = false;
                foreach (['labels' => $labels, 'descriptions' => $descriptions] as $key => $texts) {
                    foreach ($texts as $lang => $text) {
                        if ($text !== '' && trim((string)($terms[$i][$key][$lang] ?? '')) === '') {
                            $terms[$i][$key][$lang] = $text;
                            $changed = true;
                        }
                    }
                }
                $updated += $changed ? 1 : 0;
                continue;
            }
            $terms[] = [
                'id' => $term['term_id'],
                'slug' => $term['slug'],
                'parent' => $term['parent_term'],
                'labels' => $labels,
                'descriptions' => $descriptions,
                'imported_from' => $from,
            ];
            $added++;
        }
        if ($added > 0 || $updated > 0) {
            $tree = $isNew ? array_filter($list, static fn(array $t): bool => $t['parent_term'] !== '') !== [] : null;
            // A new taxonomy's pages list the types the profile files under it (the routes, businesses and points of an activity),
            // so a taxonomy of places opens as a map. One the site already has keeps its own choice.
            $types = [];
            foreach ($isNew ? $this->rest['types'] : [] as $rules) {
                if (in_array($name, $rules['taxonomies'], true)) {
                    $types[] = $rules['content_type'];
                }
            }
            $types = array_values(array_unique($types));
            $this->taxonomies->save($name, $isNew && $title !== '' ? $title : $current['title'], $terms, $types !== [] ? ['types' => $types] + $current['archive'] : null, $tree);
        }
        return [$added, $updated];
    }

    // ------------------------------------------------------------------ custom types of the REST API

    /**
     * The entries the profile's `rest_types` bring: one for each record in the main language, and one in each other language
     * the record has a title, excerpt, text or field in. They share an address and a `translation_id` made from the old
     * address, so a second run finds them again. What the end report says about them (no place, one coordinate, no text in a
     * language, ACF fields the profile does not name) is collected here.
     *
     * @param array<string, array<string, bool>> $slugs addresses already taken, by kind
     * @param string[] $listed the REST bases of the types the site's API lists (empty when it does not say)
     * @return array<int, array<string, mixed>>
     */
    private function restEntries(array &$slugs, array $listed): array
    {
        $main = $this->lang();
        $others = array_values(array_diff(WordPressRestTypes::languages($this->rest, $main), [$main]));
        $entries = [];
        foreach ($this->rest['types'] as $rules) {
            if ($listed !== [] && !in_array($rules['endpoint'], $listed, true)) {
                $this->report['notes'][] = "type '{$rules['endpoint']}': the site's API does not list it, so it was not read";
                continue;
            }
            $type = $rules['content_type'];
            $group = ContentPaths::isRoot($type) ? 'root' : $type;
            $unmapped = [];
            foreach ($this->reader->records($rules['endpoint'], array_keys($rules['taxonomies'])) as $record) {
                $mapped = WordPressRestTypes::map($record, $rules, $main);
                $title = $mapped['texts'][$main]['title'];
                $slug = $this->slugFor(['slug' => $record['slug'], 'title' => $title, 'id' => $record['id'], 'type' => $type], false, $slugs[$group] ?? []);
                $slugs[$group][$slug] = true;
                $label = "$type/$slug";
                if ($rules['coordinates'] !== null && $mapped['coordinates'] !== 'both') {
                    $this->report[$mapped['coordinates'] === 'one' ? 'one_coordinate' : 'no_coordinates'][] = $label;
                }
                foreach ($mapped['notes'] as $note) {
                    $this->report['field_notes'][] = "$label: $note";
                }
                foreach ($mapped['unmapped'] as $key) {
                    $unmapped[$key] = ($unmapped[$key] ?? 0) + 1;
                }
                $translationId = substr(sha1('wordpress:' . $record['link']), 0, 16);
                $langs = [$main];
                foreach ($others as $lang) {
                    $text = $mapped['texts'][$lang] ?? null;
                    $has = $text !== null && (trim($text['title']) !== '' || trim(WordPressReader::text($text['excerpt'])) !== '' || trim(WordPressReader::text($text['body'])) !== '' || $text['fields'] !== []);
                    if (!$has) {
                        $this->report['missing_translation'][] = "$label: no text in '$lang'";
                        continue;
                    }
                    if (trim($text['title']) === '') {
                        $this->report['missing_translation'][] = "$label: no title in '$lang', the '$main' one is used";
                    }
                    $langs[] = $lang;
                }
                foreach ($langs as $lang) {
                    $prefix = $this->rest['language_prefixes'][$lang] ?? '';
                    $old = $lang === $main ? $record['link'] : ($prefix !== '' && $record['link'] !== '' ? $this->reader->site() . $prefix . '/' . self::pathOf($record['link']) . '/' : '');
                    $entries[] = [
                        'rest' => $rules['name'],
                        'type' => $type,
                        'id' => $record['id'],
                        'lang' => $lang,
                        'slug' => $record['slug'],
                        'title' => trim($mapped['texts'][$lang]['title'] ?? '') !== '' ? $mapped['texts'][$lang]['title'] : ($title !== '' ? $title : Slug::title($slug)),
                        'date' => (string)($record['date'] ?? ''),
                        'link' => $record['link'],
                        'old' => $old,
                        'image' => (int)($record['featured_media'] ?? 0),
                        'mapped' => $mapped,
                        'translation_id' => $translationId,
                        'new_slug' => $slug,
                        'new_path' => ContentPaths::build($type, $slug, $lang, $this->homeSlug(), $this->defaultLang()),
                    ];
                }
            }
            ksort($unmapped);
            foreach ($unmapped as $key => $count) {
                $this->report['unmapped'][] = "{$rules['name']}: $key ($count entries have a value; the profile does not say where it goes)";
            }
        }
        return $entries;
    }

    /**
     * An entry of a custom type: its text in its language, its terms, its place, its files and its video.
     *
     * @param array<string, mixed> $entry
     * @param array<string, array<int, array<string, mixed>>> $terms
     * @return array{front: array<string, mixed>, body: string, notes: string[]}
     */
    private function renderRest(array $entry, array $terms): array
    {
        $mapped = $entry['mapped'];
        $lang = (string)$entry['lang'];
        $text = $mapped['texts'][$lang] ?? ['title' => '', 'excerpt' => '', 'body' => '', 'fields' => []];
        $rules = $this->rest['types'][$entry['rest']];
        $label = $entry['type'] . '/' . $entry['new_slug'];
        $converter = new HtmlToMarkdown(fn(string $address, string $kind): string => $this->resolve($address, $kind));
        $html = self::asHtml($text['body']);
        $body = trim($converter->convert($html));

        $front = ['title' => $entry['title'], 'status' => 'published', 'visible' => true];
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$entry['date'], $m)) {
            $front['date'] = $m[0];
        }
        $excerpt = $this->explicitExcerpt($text['excerpt'], $html);
        if ($excerpt !== '') {
            $front['excerpt'] = $excerpt;
        }

        $fields = $text['fields'] + $mapped['fields'];
        if ($rules['coordinates'] !== null && $mapped['location'] !== '') {
            $fields[$rules['coordinates']['to']] = $mapped['location'];
        }
        $mainRef = $entry['image'] > 0 ? $entry['image'] : null;
        foreach ($mapped['media'] as $key => $rule) {
            if ($rule['to'] === 'main_image') {
                $mainRef = $rule['refs'][0];
                continue;
            }
            $paths = [];
            foreach ($rule['refs'] as $ref) {
                $canonical = $this->restMedia($ref, "$label: $key");
                if ($canonical === '') {
                    continue;
                }
                if (in_array(strtolower($this->mediaFiles[$canonical]['ext']), GeoTrack::EXTENSIONS, true)) {
                    $this->mediaFiles[$canonical]['track'] = true;
                    if (!in_array($label, $this->mediaFiles[$canonical]['entries'] ?? [], true)) {
                        $this->mediaFiles[$canonical]['entries'][] = $label;
                    }
                }
                $this->mediaFiles[$canonical]['uses']++;
                $path = $this->final && empty($this->mediaReady[$canonical]) ? '' : $this->mediaPath($this->mediaFiles[$canonical]);
                if ($path !== '') {
                    $paths[] = $path;
                    // A route file gives its length to the field the profile names, when the old site left that empty.
                    if ($rule['length'] !== '' && !isset($fields[$rule['length']]) && ($this->trackKm[$canonical] ?? 0.0) > 0) {
                        $fields[$rule['length']] = $this->trackKm[$canonical];
                    }
                }
            }
            if ($paths !== []) {
                $fields[$rule['to']] = $rule['list'] ? $paths : $paths[0];
            }
        }
        if ($mainRef !== null) {
            $canonical = $this->restMedia($mainRef, "$label: main image");
            if ($canonical !== '') {
                $this->mediaFiles[$canonical]['uses']++;
                $front['main_image'] = $this->resolve($canonical, 'image');
            }
        }

        foreach ($mapped['terms'] as $taxonomy => $ids) {
            $filed = $this->termIds($ids, $terms[$taxonomy] ?? []);
            if ($filed !== []) {
                $front[$taxonomy] = $filed;
            }
        }
        foreach ($rules['front'] as $key => $value) {
            $front[(string)$key] = $value;
        }
        $front['translation_id'] = $entry['translation_id'];
        if ($fields !== []) {
            ksort($fields);
            $front['custom_fields'] = $fields;
        }
        if ($mapped['video'] !== '') {
            $front['blocks'] = [['type' => 'video', 'variant' => 'featured', 'videos' => [['url' => $mapped['video']]]]];
        }
        $front['imported_from'] = $entry['link'];
        return ['front' => $front, 'body' => $body, 'notes' => $converter->notes()];
    }

    /**
     * The file a media field points at, noted for the library: an id of the old library (asked for on its own when the list
     * did not have it), or an address. Empty when the old site does not have it.
     */
    private function restMedia(int|string $ref, string $where): string
    {
        if (is_int($ref)) {
            if (!isset($this->attachmentUrls[$ref])) {
                $item = $this->final ? null : $this->reader->mediaItem($ref);
                if ($item === null) {
                    if (!$this->final) {
                        $this->report['field_notes'][] = "$where: file $ref is not in the old site's media library";
                    }
                    return '';
                }
                $this->attachmentUrls[$ref] = $this->registerMedia($item['url'], $item['alt'], $item['title'], true);
            }
            return $this->attachmentUrls[$ref];
        }
        $address = str_starts_with($ref, '//') ? 'https:' . $ref : (str_starts_with($ref, '/') ? $this->reader->site() . $ref : $ref);
        return $this->registerMedia(html_entity_decode($address, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '', '', false);
    }

    /** The length of a route file that is in the library now, for the entries that leave their own length empty. @param array<string, mixed> $file */
    private function measureTrack(string $canonical, array $file): void
    {
        if (empty($file['track'])) {
            return;
        }
        $path = $this->media->filePath((string)$file['id']);
        $parsed = $path !== null ? GeoTrack::parse((string)file_get_contents($path), (string)$file['ext']) : null;
        if ($parsed !== null) {
            $this->trackKm[$canonical] = GeoTrack::stats($parsed['lines'])['distance_km'];
        }
    }

    /** A text that is plain (an ACF textarea) as paragraphs, so it is read like the HTML of the editor. */
    private static function asHtml(string $text): string
    {
        if ($text === '' || preg_match('/<(p|div|br|h[1-6]|ul|ol|table|blockquote|img|figure)\b/i', $text)) {
            return $text;
        }
        $paragraphs = preg_split('/\R\s*\R/u', trim($text)) ?: [];
        return implode('', array_map(static fn(string $p): string => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8'), false) . '</p>', $paragraphs));
    }

    // ------------------------------------------------------------------ addresses

    /** Where an address inside the old site leads on the new one. */
    private function resolve(string $address, string $kind): string
    {
        $address = trim(html_entity_decode($address, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $site = $this->reader->site();
        $absolute = preg_match('#^[a-z][a-z0-9+.-]*:#i', $address) ? $address : (str_starts_with($address, '//') ? 'https:' . $address : rtrim($site, '/') . '/' . ltrim($address, '/'));
        if (!preg_match('#^https?://#i', $absolute)) {
            return $address; // mailto:, tel: and the like
        }
        if (!$this->sameSite($absolute)) {
            if ($kind === 'image' && !$this->final) {
                $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
                $this->external[$host] = ($this->external[$host] ?? 0) + 1;
            }
            return $address;
        }
        $parts = parse_url($absolute) ?: [];
        $path = (string)($parts['path'] ?? '/');
        $suffix = (isset($parts['query']) && !self::isMediaPath($path) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        if (self::isMediaPath($path) || ($kind === 'image' && $this->looksLikeFile($path))) {
            $canonical = $this->registerMedia($absolute, '', '', false, true);
            $file = $this->mediaFiles[$canonical];
            if ($this->final && empty($this->mediaReady[$canonical])) {
                // A file the old site does not have either is not worth a broken reference; one that could not be fetched this time stays pointing at the old site.
                return !empty($this->mediaGone[$canonical]) ? '' : $absolute;
            }
            return $this->mediaPath($file);
        }
        $key = trim(rawurldecode($path), '/');
        if ($key === '' || $key === 'index.php' || $key === '..') {
            return '/' . $suffix;
        }
        if (isset($this->linkMap[$key])) {
            return '/' . $this->linkMap[$key] . $suffix;
        }
        $this->unresolved['/' . $key . '/'] = ($this->unresolved['/' . $key . '/'] ?? 0) + 1;
        return '/' . $key . '/' . $suffix;
    }

    private function sameSite(string $absolute): bool
    {
        $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
        $mine = strtolower((string)parse_url($this->reader->site(), PHP_URL_HOST));
        return $host !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $mine);
    }

    private static function isMediaPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MEDIA_EXTENSIONS, true);
    }

    private function looksLikeFile(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) !== '';
    }

    /**
     * Notes a file as one to bring over, and gives the address that stands for it: sizes WordPress made of a picture
     * (photo-300x200.jpg) are the picture itself.
     */
    private function registerMedia(string $url, string $alt, string $title, bool $attachment, bool $used = false): string
    {
        $original = strtok($url, '?#') ?: $url;
        $canonical = (string)preg_replace('/-(?:\d{2,5}x\d{2,5}|scaled)(\.[A-Za-z0-9]+)$/', '$1', $original);
        $path = (string)parse_url($canonical, PHP_URL_PATH);
        if (!isset($this->mediaFiles[$canonical])) {
            $ext = strtolower((string)preg_replace('/[^a-z0-9]/i', '', pathinfo($path, PATHINFO_EXTENSION)));
            $this->mediaFiles[$canonical] = [
                'id' => substr(sha1($canonical), 0, 16),
                'ext' => $ext,
                'name' => rawurldecode(basename($path)),
                'alt' => $alt,
                'uses' => 0,
                'attachment' => $attachment,
                'original' => $original,
                'seen' => [],
            ];
        }
        $file = &$this->mediaFiles[$canonical];
        if ($file['alt'] === '' && $alt !== '') {
            $file['alt'] = $alt;
        }
        if ($attachment) {
            $file['attachment'] = true;
        }
        if ($used) {
            $file['uses']++;
        }
        $seenPath = rawurldecode((string)parse_url($original, PHP_URL_PATH));
        if ($seenPath !== '' && !in_array($seenPath, $file['seen'], true)) {
            $file['seen'][] = $seenPath;
        }
        unset($file);
        return $canonical;
    }

    /** @param array{id: string, ext: string} $file */
    private function mediaPath(array $file): string
    {
        return '/uploads/media/' . $file['id'] . ($file['ext'] !== '' ? '.' . $file['ext'] : '');
    }

    /** The path of an address on the old site, with no slash at either end. */
    private static function pathOf(string $address): string
    {
        // parse_url() mangles bytes outside ASCII, and an address made here ("/en/" before a decoded Greek path) has them.
        $address = (string)preg_replace_callback('/[^\x21-\x7E]/', static fn(array $m): string => rawurlencode($m[0]), trim($address));
        $path = (string)parse_url($address, PHP_URL_PATH);
        return trim(rawurldecode($path), '/');
    }

    /**
     * @param array<int, array{0: string, 1: string}> $redirects
     */
    private function addRedirect(array &$redirects, string $oldAddress, string $new): void
    {
        $old = self::pathOf($oldAddress);
        if (str_contains($oldAddress, '://')) {
            $old = '/' . $old;
        } elseif ($old !== '') {
            $old = '/' . ltrim($old, '/');
        }
        if ($old === '/' || $old === '' || rtrim($old, '/') === rtrim($new, '/')) {
            return;
        }
        foreach ($redirects as [$from]) {
            if ($from === $old) {
                return;
            }
        }
        $redirects[] = [$old, $new];
    }
}
