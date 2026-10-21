<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Reads what a WordPress site shows to everybody through its public REST API (/wp-json/wp/v2): pages, posts, media,
 * categories and tags. No sign-in is needed; what the site keeps private (drafts, menus, settings) is not here.
 *
 * It asks the web through a closure so tests can answer from files: the closure takes an address and returns
 * ['status' => int, 'body' => string], or null when the site could not be reached.
 */
final class WordPressReader
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 200;

    private string $site;

    /** @param \Closure(string): (array{status: int, body: string}|null) $get */
    public function __construct(string $site, private \Closure $get)
    {
        $this->site = rtrim(trim($site), '/');
    }

    public function site(): string
    {
        return $this->site;
    }

    /**
     * The site's name and description, the page shown as the home page, and what the API offers.
     *
     * @return array{name: string, description: string, url: string, home_page_id: int, types: string[], taxonomies: string[], taxonomy_names: array<string, string>}
     */
    public function info(): array
    {
        $root = $this->json($this->site . '/wp-json/');
        if (!is_array($root) || !isset($root['namespaces']) || !in_array('wp/v2', (array)$root['namespaces'], true)) {
            throw new \RuntimeException('This address does not answer like a WordPress site with its REST API open (' . $this->site . '/wp-json/).');
        }
        $types = $this->json($this->site . '/wp-json/wp/v2/types');
        $taxonomies = $this->json($this->site . '/wp-json/wp/v2/taxonomies');
        return [
            'name' => self::text((string)($root['name'] ?? '')),
            'description' => self::text((string)($root['description'] ?? '')),
            'url' => rtrim((string)($root['home'] ?? $this->site), '/'),
            'home_page_id' => ($root['show_on_front'] ?? '') === 'page' ? (int)($root['page_on_front'] ?? 0) : 0,
            'types' => is_array($types) ? array_values(array_map(static fn($t): string => (string)($t['rest_base'] ?? ''), $types)) : [],
            'taxonomies' => is_array($taxonomies) ? array_values(array_map(static fn($t): string => (string)($t['rest_base'] ?? ''), $taxonomies)) : [],
            'taxonomy_names' => is_array($taxonomies) ? array_column(array_map(static fn($t): array => ['base' => (string)($t['rest_base'] ?? ''), 'name' => self::text((string)($t['name'] ?? ''))], array_filter($taxonomies, 'is_array')), 'name', 'base') : [],
        ];
    }

    /** The same site seen under a path, such as the /en of its English pages. */
    public function under(string $prefix): self
    {
        return new self($this->site . '/' . trim($prefix, '/'), $this->get);
    }

    /**
     * Every published record of a type the REST API shows (a custom post type such as "route"), as the API gives it: id,
     * slug, link, title, date, content, excerpt, the featured picture, the ACF fields under `acf`, and the term ids of each
     * taxonomy named. Oldest id first.
     *
     * @param string[] $taxonomies the REST bases of the taxonomies to read
     * @return array<int, array<string, mixed>>
     */
    public function records(string $base, array $taxonomies = []): array
    {
        $fields = implode(',', array_unique(array_merge(['id', 'slug', 'link', 'title', 'content', 'excerpt', 'date', 'modified', 'status', 'featured_media', 'acf'], $taxonomies)));
        $records = [];
        foreach ($this->collection('/wp-json/wp/v2/' . $base, $fields) as $row) {
            if (($row['status'] ?? 'publish') !== 'publish') {
                continue;
            }
            $row['id'] = (int)($row['id'] ?? 0);
            $row['slug'] = rawurldecode((string)($row['slug'] ?? ''));
            $row['link'] = (string)($row['link'] ?? '');
            $records[] = $row;
        }
        usort($records, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $records;
    }

    /**
     * One file of the media library by its id, for an id a field points at that the list did not have.
     *
     * @return array{id: int, url: string, alt: string, title: string, mime: string, parent: int}|null
     */
    public function mediaItem(int $id): ?array
    {
        $row = $id > 0 ? $this->json($this->site . '/wp-json/wp/v2/media/' . $id . '?_fields=id,source_url,alt_text,title,mime_type,post') : null;
        if (!is_array($row) || (string)($row['source_url'] ?? '') === '') {
            return null;
        }
        return ['id' => (int)($row['id'] ?? $id), 'url' => (string)$row['source_url'], 'alt' => self::text((string)($row['alt_text'] ?? '')), 'title' => self::text((string)($row['title']['rendered'] ?? '')), 'mime' => (string)($row['mime_type'] ?? ''), 'parent' => (int)($row['post'] ?? 0)];
    }

    /**
     * Every published page or post of a kind ("pages", "posts"), oldest id first.
     *
     * @return array<int, array{id: int, slug: string, link: string, title: string, html: string, excerpt: string, date: string, modified: string, categories: int[], tags: int[], image: int, parent: int, template: string}>
     */
    public function entries(string $base): array
    {
        $entries = [];
        foreach ($this->collection('/wp-json/wp/v2/' . $base, 'id,slug,link,title,content,excerpt,date,modified,status,categories,tags,featured_media,parent,template') as $row) {
            if (($row['status'] ?? 'publish') !== 'publish') {
                continue;
            }
            $entries[] = [
                'id' => (int)($row['id'] ?? 0),
                'slug' => rawurldecode((string)($row['slug'] ?? '')),
                'link' => (string)($row['link'] ?? ''),
                'title' => self::text((string)($row['title']['rendered'] ?? '')),
                'html' => (string)($row['content']['rendered'] ?? ''),
                'excerpt' => (string)($row['excerpt']['rendered'] ?? ''),
                'date' => (string)($row['date'] ?? ''),
                'modified' => (string)($row['modified'] ?? ''),
                'categories' => array_map('intval', (array)($row['categories'] ?? [])),
                'tags' => array_map('intval', (array)($row['tags'] ?? [])),
                'image' => (int)($row['featured_media'] ?? 0),
                'parent' => (int)($row['parent'] ?? 0),
                'template' => (string)($row['template'] ?? ''),
            ];
        }
        usort($entries, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $entries;
    }

    /**
     * The terms of a taxonomy ("categories", "tags").
     *
     * @return array<int, array{id: int, slug: string, name: string, link: string, parent: int, count: int, description: string}>
     */
    public function terms(string $base): array
    {
        $terms = [];
        foreach ($this->collection('/wp-json/wp/v2/' . $base, 'id,slug,name,link,parent,count,description') as $row) {
            $terms[] = [
                'id' => (int)($row['id'] ?? 0),
                'slug' => rawurldecode((string)($row['slug'] ?? '')),
                'name' => self::text((string)($row['name'] ?? '')),
                'link' => (string)($row['link'] ?? ''),
                'parent' => (int)($row['parent'] ?? 0),
                'count' => (int)($row['count'] ?? 0),
                'description' => self::text((string)($row['description'] ?? '')),
            ];
        }
        usort($terms, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $terms;
    }

    /**
     * The files in the media library.
     *
     * @return array<int, array{id: int, url: string, alt: string, title: string, mime: string, parent: int}>
     */
    public function media(): array
    {
        $files = [];
        foreach ($this->collection('/wp-json/wp/v2/media', 'id,source_url,alt_text,title,mime_type,post') as $row) {
            $url = (string)($row['source_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $files[] = [
                'id' => (int)($row['id'] ?? 0),
                'url' => $url,
                'alt' => self::text((string)($row['alt_text'] ?? '')),
                'title' => self::text((string)($row['title']['rendered'] ?? '')),
                'mime' => (string)($row['mime_type'] ?? ''),
                'parent' => (int)($row['post'] ?? 0),
            ];
        }
        return $files;
    }

    /**
     * The addresses a site lists in its sitemap for one kind of content ("book", "theater", "slide"): the way to find
     * custom content types, which the REST API only shows when the site's developer switched that on.
     *
     * @return string[]
     */
    public function sitemapAddresses(string $postType): array
    {
        $addresses = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = ($this->get)($this->site . '/wp-sitemap-posts-' . $postType . '-' . $page . '.xml');
            if ($response === null || $response['status'] !== 200) {
                break;
            }
            if (!preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $response['body'], $found) || $found[1] === []) {
                break;
            }
            foreach ($found[1] as $loc) {
                $addresses[] = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        return array_values(array_unique($addresses));
    }

    /** The text of a page address, for reading a page that is not in the API. */
    public function page(string $address): ?string
    {
        $response = ($this->get)($address);
        return $response !== null && $response['status'] === 200 ? $response['body'] : null;
    }

    /** What a WordPress field holds as plain text: no tags, entities turned into characters. */
    public static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collection(string $path, string $fields): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $url = $this->site . $path . '?per_page=' . self::PAGE_SIZE . '&page=' . $page . '&_fields=' . $fields;
            $response = ($this->get)($url);
            if ($response === null) {
                throw new \RuntimeException('Could not reach ' . $url);
            }
            if ($response['status'] === 400 && $page > 1) {
                break; // asking for a page after the last one
            }
            if ($response['status'] !== 200) {
                throw new \RuntimeException('The site answered ' . $response['status'] . ' for ' . $path . '.');
            }
            $batch = json_decode($response['body'], true);
            if (!is_array($batch)) {
                throw new \RuntimeException('The answer for ' . $path . ' is not the list WordPress sends.');
            }
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }
        return $rows;
    }

    private function json(string $url): mixed
    {
        $response = ($this->get)($url);
        if ($response === null || $response['status'] !== 200) {
            return null;
        }
        return json_decode($response['body'], true);
    }
}
