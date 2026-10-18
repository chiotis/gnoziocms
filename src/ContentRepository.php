<?php

declare(strict_types=1);

namespace GnozioCMS;

use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ContentRepository
{
    private string $contentDir;
    private MarkdownConverter $markdown;
    private array $settings;

    /** @var array<string, array<string, ContentItem>> */
    private array $cache = [];

    /** @var array<string, string> files whose front matter could not be read, path => what the parser said */
    private array $unreadable = [];

    /** @var (\Closure(string, string, string): void)|null told about each file that cannot be read: type, path, message */
    private ?\Closure $onUnreadable = null;

    /** @var (\Closure(): string[])|null the content types the theme ships, which a site switches on and off */
    private ?\Closure $catalogue;

    /** Where the parsed files are kept between visits (null: parsed on every visit), and what makes that store stale. */
    private ?string $storeDir = null;
    private string $storeSalt = '';

    /** @var array<string, array{0: int, 1: int, 2: ContentItem}> parsed in this request, by path, with its time and size, so a second language or listing does not parse it again */
    private array $parsed = [];

    /** @var array<string, array<string, array{0: int, 1: int, 2: array, 3: string, 4: string}>> type => path => [mtime, size, meta, markdown, html] */
    private array $stored = [];

    /** The entry the live preview of the editor shows instead of the stored one (this request only). */
    private ?ContentItem $draft = null;

    /** @param (\Closure(): string[])|null $catalogue */
    public function __construct(string $contentDir, MarkdownConverter $markdown, array $settings, ?\Closure $catalogue = null)
    {
        $this->catalogue = $catalogue;
        $this->contentDir = rtrim($contentDir, '/');
        $this->markdown = $markdown;
        $this->settings = $settings;
    }

    /**
     * Keeps each parsed file (front matter and its Markdown as HTML) in `$dir`, one file per type, so a visit reads that instead
     * of parsing every file of the type again. A file counts as unchanged while its time and size are; `$salt` (the version of the
     * code) throws the whole store away when the way files are read may have changed.
     */
    public function storeParsedIn(string $dir, string $salt): void
    {
        $this->storeDir = rtrim($dir, '/');
        $this->storeSalt = $salt . '|' . (string)($this->settings['languages']['default'] ?? 'en');
    }

    /** Called once for each file whose front matter cannot be read, so the site can tell someone. @param \Closure(string, string, string): void $callback */
    public function onUnreadable(\Closure $callback): void
    {
        $this->onUnreadable = $callback;
    }

    /** @return array<string, string> files that could not be read so far, path => message */
    public function unreadable(): array
    {
        return $this->unreadable;
    }

    /**
     * The content types that are on. A type is on when the site lists it or has a folder for it, unless it was switched off
     * (`content_types_off`; pages and forms cannot be). A type from the theme's catalogue that is not listed needs
     * content in its folder to count, so an empty folder does not switch a prebuilt type on.
     *
     * @return string[]
     */
    public function getTypes(): array
    {
        $off = array_map('strval', is_array($this->settings['content_types_off'] ?? null) ? $this->settings['content_types_off'] : []);
        $catalogue = $this->catalogue !== null ? ($this->catalogue)() : [];
        $listed = array_map('strval', is_array($this->settings['content_types'] ?? null) ? $this->settings['content_types'] : []);
        return array_values(array_filter($this->typesOnDisk(), function (string $type) use ($off, $catalogue, $listed): bool {
            if (!in_array($type, ['pages', 'forms'], true) && in_array($type, $off, true)) {
                return false;
            }
            if (in_array($type, $catalogue, true) && !in_array($type, $listed, true)) {
                return (glob($this->contentDir . '/' . $type . '/*.md') ?: []) !== [];
            }
            return true;
        }));
    }

    /** @return string[] */
    private function typesOnDisk(): array
    {
        $types = [];
        $excluded = ['settings', 'users', 'media', 'menus', 'taxonomies', 'forms-submissions'];
        $configTypes = $this->settings['content_types'] ?? [];
        if (is_array($configTypes)) {
            foreach ($configTypes as $type) {
                if (!is_string($type)) {
                    continue;
                }
                $type = trim($type);
                if ($type === '' || in_array($type, $excluded, true)) {
                    continue;
                }
                if (!in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }
        foreach (scandir($this->contentDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (str_starts_with($entry, '.')) {
                continue;
            }
            $path = $this->contentDir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            if (in_array($entry, $excluded, true)) {
                continue;
            }
            if (!in_array($entry, $types, true)) {
                $types[] = $entry;
            }
        }
        if (empty($configTypes)) {
            sort($types);
        }
        return $types;
    }

    /** Forgets what was read in this request, for code that changed files and reads them again. */
    public function forget(): void
    {
        $this->cache = [];
        $this->parsed = [];
    }

    /** @return ContentItem[] */
    public function getItems(string $type, ?string $lang = null, bool $includeHidden = false, bool $fallbackDefault = true): array
    {
        $defaultLang = $this->settings['languages']['default'] ?? null;
        $cacheKey = $type . '|' . ($lang === null ? '_all' : $lang) . '|' . ($fallbackDefault ? 'fb' : 'strict');
        if (isset($this->cache[$cacheKey])) {
            return $this->filterVisibility($this->withDraft($type, $lang, $this->cache[$cacheKey]), $includeHidden);
        }

        $itemsBySlug = [];
        $paths = glob($this->contentDir . '/' . $type . '/*.md') ?: [];
        $changed = false;
        foreach ($paths as $path) {
            $item = $this->item($type, $path, $changed);
            if ($lang === null) {
                $itemsBySlug[$item->slug . '|' . $item->lang] = $item;
                continue;
            }

            if ($item->lang === $lang) {
                $itemsBySlug[$item->slug] = $item;
                continue;
            }

            if ($fallbackDefault && !isset($itemsBySlug[$item->slug]) && $defaultLang !== null && $item->lang === $defaultLang) {
                $itemsBySlug[$item->slug] = $item;
            }
        }

        if ($this->storeDir !== null && ($changed || array_diff_key($this->stored[$type] ?? [], array_flip($paths)) !== [])) {
            $this->keepStore($type, $paths);
        }

        $items = array_values($itemsBySlug);
        usort($items, function (ContentItem $a, ContentItem $b): int {
            $dateA = strtotime((string)($a->meta['date'] ?? '')) ?: $a->mtime;
            $dateB = strtotime((string)($b->meta['date'] ?? '')) ?: $b->mtime;
            return $dateB <=> $dateA;
        });

        $this->cache[$cacheKey] = $items;
        return $this->filterVisibility($this->withDraft($type, $lang, $items), $includeHidden);
    }

    /** The file at `$path` as an entry: from this request, from the store when the file has not changed since, or parsed. */
    private function item(string $type, string $path, bool &$changed): ContentItem
    {
        $mtime = (int)@filemtime($path);
        $size = (int)@filesize($path);
        $seen = $this->parsed[$path] ?? null;
        if ($seen !== null && $seen[0] === $mtime && $seen[1] === $size) {
            return $seen[2];
        }
        if ($this->storeDir === null) {
            return ($this->parsed[$path] = [$mtime, $size, $this->parseFile($type, $path)])[2];
        }
        $stored = $this->storedFor($type);
        $entry = $stored[$path] ?? null;
        if ($entry !== null && $entry[0] === $mtime && $entry[1] === $size) {
            return ($this->parsed[$path] = [$mtime, $size, new ContentItem($type, (string)$entry[2]['slug'], (string)$entry[2]['lang'], $entry[2], $entry[3], $entry[4], $path, $mtime)])[2];
        }
        $item = $this->parseFile($type, $path);
        // A file that cannot be read is parsed again each time, so it is reported until someone fixes it.
        if (!isset($this->unreadable[$path])) {
            $this->stored[$type][$path] = [$mtime, $size, $item->meta, $item->markdown, $item->html];
            $changed = true;
        }
        return ($this->parsed[$path] = [$mtime, $size, $item])[2];
    }

    /** @return array<string, array{0: int, 1: int, 2: array, 3: string, 4: string}> */
    private function storedFor(string $type): array
    {
        if (!isset($this->stored[$type])) {
            $this->stored[$type] = [];
            $raw = @file_get_contents($this->storeFile($type));
            if (is_string($raw) && $raw !== '') {
                $data = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($data) && ($data['salt'] ?? null) === $this->storeSalt && is_array($data['files'] ?? null)) {
                    $this->stored[$type] = $data['files'];
                }
            }
        }
        return $this->stored[$type];
    }

    /** @param string[] $paths the files the type has now; the store forgets the others. */
    private function keepStore(string $type, array $paths): void
    {
        if ($this->storeDir === null) {
            return;
        }
        $this->stored[$type] = array_intersect_key($this->stored[$type] ?? [], array_flip($paths));
        // A store that cannot be written only costs speed: the files are parsed as before.
        if (!is_dir($this->storeDir) && !@mkdir($this->storeDir, 0775, true) && !is_dir($this->storeDir)) {
            return;
        }
        $file = $this->storeFile($type);
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, serialize(['salt' => $this->storeSalt, 'files' => $this->stored[$type]])) === false || !@rename($temp, $file)) {
            @unlink($temp);
        }
    }

    private function storeFile(string $type): string
    {
        return $this->storeDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $type) . '.ser';
    }

    public function find(string $type, string $slug, ?string $lang = null, bool $includeHidden = false, bool $fallbackDefault = true): ?ContentItem
    {
        $items = $this->getItems($type, $lang, $includeHidden, $fallbackDefault);
        foreach ($items as $item) {
            if ($item->slug === $slug) {
                return $item;
            }
        }
        return null;
    }

    /** @return ContentItem[] */
    public function search(string $query, ?string $lang = null, bool $includeHidden = false): array
    {
        $query = trim($this->lowercase($query));
        if ($query === '') {
            return [];
        }

        $results = [];
        foreach ($this->getTypes() as $type) {
            foreach ($this->getItems($type, $lang, $includeHidden) as $item) {
                $tags = $item->meta['tags'] ?? '';
                $categories = $item->meta['categories'] ?? '';
                if (is_array($tags)) {
                    $tags = implode(' ', $tags);
                }
                if (is_array($categories)) {
                    $categories = implode(' ', $categories);
                }
                $haystack = $this->lowercase(
                    ($item->meta['title'] ?? '') . ' ' .
                    $tags . ' ' .
                    $categories . ' ' .
                    $item->markdown
                );
                if (str_contains($haystack, $query)) {
                    $results[] = $item;
                }
            }
        }
        return $results;
    }

    private function lowercase(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }
        return strtolower($value);
    }

    public function parseFile(string $type, string $path): ContentItem
    {
        $filename = basename($path, '.md');
        $lang = $this->settings['languages']['default'] ?? 'en';
        $slug = $filename;
        if (preg_match('/^(.*)\.([a-z]{2})$/', $filename, $langMatch)) {
            $slug = $langMatch[1];
            $lang = $langMatch[2];
        }
        return $this->build($type, $slug, $lang, (string)file_get_contents($path), $path, (int)filemtime($path));
    }

    /** An entry from the text a file would hold, with nothing read from or written to disk (the live preview of the editor). */
    public function parseText(string $type, string $slug, string $lang, string $raw): ContentItem
    {
        return $this->build($type, $slug, $lang, $raw, '', time());
    }

    /**
     * Shows `$item` in place of the stored entry with the same address (or as one more) until `null` is given: the live preview of
     * the editor draws the page as the unsaved form would make it. It lasts for this request and touches no file.
     */
    public function showInstead(?ContentItem $item): void
    {
        $this->draft = $item;
    }

    /** @param ContentItem[] $items */
    private function withDraft(string $type, ?string $lang, array $items): array
    {
        $draft = $this->draft;
        if ($draft === null || $draft->type !== $type || ($lang !== null && $draft->lang !== $lang)) {
            return $items;
        }
        $items = array_values(array_filter($items, fn(ContentItem $item): bool => $item->slug !== $draft->slug || ($lang === null && $item->lang !== $draft->lang)));
        array_unshift($items, $draft);
        return $items;
    }

    private function build(string $type, string $slug, string $lang, string $raw, string $path, int $mtime): ContentItem
    {
        $meta = [];
        $body = $raw;

        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R(.*)\z/s', $raw, $matches)) {
            $body = $matches[2];
            try {
                $parsed = Yaml::parse($matches[1]) ?: [];
                if (!is_array($parsed)) {
                    throw new ParseException('The front matter is not a list of fields.');
                }
                $meta = $parsed;
            } catch (ParseException $e) {
                // One file with a typo must not take the whole site down: it counts as an unpublished draft (so no visitor
                // sees half a page) that the admin flags, and its text stays in the file for someone to fix.
                $meta = ['status' => 'draft', 'visible' => false, 'front_matter_error' => $e->getMessage()];
                if ($path !== '') {
                    $this->unreadable[$path] = $e->getMessage();
                    if ($this->onUnreadable !== null) {
                        ($this->onUnreadable)($type, $path, $e->getMessage());
                    }
                }
            }
        }

        $meta['title'] = $meta['title'] ?? $this->titleFromSlug($slug);
        $meta['slug'] = $slug;
        $meta['type'] = $type;
        $meta['lang'] = $lang;

        $html = (string)$this->markdown->convert($body);

        return new ContentItem($type, $slug, $lang, $meta, $body, $html, $path, $mtime);
    }

    private function titleFromSlug(string $slug): string
    {
        return trim(ucwords(str_replace(['-', '_'], ' ', $slug)));
    }

    /** @param ContentItem[] $items */
    private function filterVisibility(array $items, bool $includeHidden): array
    {
        if ($includeHidden) {
            return $items;
        }

        return array_values(array_filter($items, function (ContentItem $item): bool {
            $status = strtolower((string)($item->meta['status'] ?? 'published'));
            $visible = $item->meta['visible'] ?? true;
            return $status === 'published' && $visible !== false;
        }));
    }
}
