<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Reads from the pages of a WordPress site what its REST API does not give: custom post types (books, events, slides
 * that the theme keeps out of the API), the slides of the home page, and the menu. A profile says where to look in each
 * page with XPath, because every theme draws these differently; see docs/wordpress-import.md and docs/wordpress-profiles/.
 *
 * The addresses of a custom type come from the site's own sitemap (/wp-sitemap-posts-<type>-1.xml); the pages are read
 * with the reader's closure, so tests can answer from files.
 */
final class WordPressScraper
{
    /** @param array<string, mixed> $profile */
    public function __construct(private WordPressReader $reader, private array $profile)
    {
    }

    /** @return array<string, array<string, mixed>> the custom types the profile describes, by WordPress type name */
    public function types(): array
    {
        $types = $this->profile['types'] ?? [];
        return is_array($types) ? $types : [];
    }

    /**
     * Every page of the custom types the profile describes.
     *
     * @return array<int, array{type: string, post_type: string, id: string, slug: string, link: string, title: string, image: string, summary: string, tabs: array<int, array{label: string, html: string}>, fields: array<string, mixed>, notes: string[]}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->types() as $postType => $rules) {
            if (!is_array($rules)) {
                continue;
            }
            foreach ($this->reader->sitemapAddresses((string)$postType) as $address) {
                $html = $this->reader->page($address);
                if ($html === null) {
                    continue;
                }
                $xpath = $this->xpath($html);
                $title = $this->text($xpath, (string)($rules['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $slug = basename(rtrim((string)parse_url($address, PHP_URL_PATH), '/'));
                $entries[] = [
                    'type' => (string)($rules['content_type'] ?? $postType),
                    'post_type' => (string)$postType,
                    'id' => rawurldecode($slug),
                    'slug' => rawurldecode($slug),
                    'link' => $address,
                    'title' => $title,
                    'image' => $this->attribute($xpath, (string)($rules['image'] ?? '')),
                    'summary' => $this->html($xpath, (string)($rules['summary'] ?? '')),
                    'tabs' => $this->tabs($xpath, is_array($rules['tabs'] ?? null) ? $rules['tabs'] : []),
                    'fields' => $this->fields($xpath, is_array($rules['fields'] ?? null) ? $rules['fields'] : []),
                    'notes' => [],
                ];
            }
        }
        return $entries;
    }

    /**
     * Which categories the pages of custom types are in. The API does not say, but the archive page of each category lists them,
     * so those pages are read (a following page only while the one before had some of them).
     *
     * @param string[] $entryLinks the addresses of the custom pages
     * @param array<int, array{id: int, link: string, count: int}> $terms the categories of the site
     * @return array<string, int[]> address of a custom page => the ids of its categories
     */
    public function categoryIds(array $entryLinks, array $terms): array
    {
        $wanted = [];
        foreach ($entryLinks as $link) {
            $wanted[rtrim($link, '/')] = $link;
        }
        $found = [];
        foreach ($terms as $term) {
            if ($term['count'] < 1 || $term['link'] === '') {
                continue;
            }
            for ($page = 1; $page <= 20; $page++) {
                $html = $this->reader->page(rtrim($term['link'], '/') . ($page > 1 ? '/page/' . $page : '') . '/');
                if ($html === null) {
                    break;
                }
                $hits = 0;
                if (preg_match_all('/href=["\']?([^"\'\s>]+)/i', $html, $links)) {
                    foreach ($links[1] as $href) {
                        $key = rtrim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/');
                        if (isset($wanted[$key]) && !isset($found[$wanted[$key]][$term['id']])) {
                            $found[$wanted[$key]][$term['id']] = true;
                            $hits++;
                        }
                    }
                }
                if ($hits === 0 || !str_contains($html, '/page/' . ($page + 1))) {
                    break;
                }
            }
        }
        return array_map(static fn(array $ids): array => array_keys($ids), $found);
    }

    /** The address of the site's logo picture, found on the home page ('' when the profile has no rule or nothing is found). */
    public function logo(): string
    {
        $rule = (string)($this->profile['logo'] ?? '');
        if ($rule === '') {
            return '';
        }
        $html = $this->reader->page($this->reader->site() . '/');
        return $html === null ? '' : $this->attribute($this->xpath($html), $rule);
    }

    /**
     * The slides of the home page, in order.
     *
     * @return array<int, array{image: string, title: string, text: string, buttons: array<int, array{label: string, url: string}>}>
     */
    public function slides(): array
    {
        $rules = $this->profile['slides'] ?? null;
        if (!is_array($rules) || ($rules['items'] ?? '') === '') {
            return [];
        }
        $html = $this->reader->page($this->reader->site() . '/');
        if ($html === null) {
            return [];
        }
        $xpath = $this->xpath($html);
        $slides = [];
        foreach ($this->nodes($xpath, (string)$rules['items']) as $node) {
            $image = '';
            $style = $node instanceof \DOMElement ? $node->getAttribute('style') : '';
            if (($rules['background'] ?? 'style') === 'style' && preg_match('/url\(\s*[\'"]?([^\'")\s]+)/i', $style, $m)) {
                $image = $m[1];
            } elseif (($rules['image'] ?? '') !== '') {
                $image = $this->attribute($xpath, (string)$rules['image'], $node);
            }
            $buttons = [];
            foreach ($this->nodes($xpath, (string)($rules['buttons'] ?? ''), $node) as $anchor) {
                if ($anchor instanceof \DOMElement && trim($anchor->getAttribute('href')) !== '') {
                    $buttons[] = ['label' => $this->clean($anchor->textContent), 'url' => trim($anchor->getAttribute('href'))];
                }
            }
            $title = $this->text($xpath, (string)($rules['title'] ?? ''), $node);
            if ($title === '' && $image === '') {
                continue;
            }
            $slides[] = ['image' => $image, 'title' => $title, 'text' => $this->text($xpath, (string)($rules['text'] ?? ''), $node), 'buttons' => $buttons];
        }
        return $slides;
    }

    /**
     * A menu as nested items with a label and an address.
     *
     * @return array<int, array{label: string, url: string, children?: array<int, mixed>}>
     */
    public function menu(string $name): array
    {
        $rules = $this->profile['menus'][$name] ?? null;
        if (!is_array($rules) || ($rules['list'] ?? '') === '') {
            return [];
        }
        $html = $this->reader->page($this->reader->site() . '/');
        if ($html === null) {
            return [];
        }
        $xpath = $this->xpath($html);
        $list = $this->nodes($xpath, (string)$rules['list'])[0] ?? null;
        return $list instanceof \DOMElement ? $this->menuItems($list) : [];
    }

    /** @return array<int, array{label: string, url: string, children?: array<int, mixed>}> */
    private function menuItems(\DOMElement $list): array
    {
        $items = [];
        foreach ($list->childNodes as $li) {
            if (!$li instanceof \DOMElement || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $anchor = null;
            $children = [];
            foreach ($li->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                if (strtolower($child->nodeName) === 'a' && $anchor === null) {
                    $anchor = $child;
                } elseif (strtolower($child->nodeName) === 'ul') {
                    $children = $this->menuItems($child);
                }
            }
            if ($anchor === null) {
                continue;
            }
            $item = ['label' => $this->clean($anchor->textContent), 'url' => trim($anchor->getAttribute('href'))];
            if ($children !== []) {
                $item['children'] = $children;
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<int, array{label: string, html: string}>
     */
    private function tabs(\DOMXPath $xpath, array $rules): array
    {
        if (($rules['labels'] ?? '') === '' || ($rules['panels'] ?? '') === '') {
            return [];
        }
        $labels = array_map(fn(\DOMNode $n): string => $this->clean($n->textContent), $this->nodes($xpath, (string)$rules['labels']));
        // Tabs whose facts the profile's `fields` read (the book's details) are left out of the text, so they are not there twice.
        $skip = array_map(static fn($label): string => mb_strtolower(trim((string)$label)), is_array($rules['skip'] ?? null) ? $rules['skip'] : []);
        $tabs = [];
        foreach ($this->nodes($xpath, (string)$rules['panels']) as $index => $panel) {
            $html = $this->inner($panel);
            // A panel with next to nothing in it (an empty list of editions) is not a tab.
            if (mb_strlen(trim(strip_tags($html))) < 3 || ($labels[$index] ?? '') === '' || in_array(mb_strtolower($labels[$index]), $skip, true)) {
                continue;
            }
            $tabs[] = ['label' => $labels[$index], 'html' => $html];
        }
        return $tabs;
    }

    /**
     * The values of the content type's own fields, read from the page (the profile's `types.<name>.fields`):
     *
     *   author: "//p[@class='author']"                         the text of the first node
     *   year: { xpath: "//li[contains(.,'Year')]", match: '(\d{4})', type: number }
     *   isbn: { xpath: "//li[contains(.,'ISBN')]", match: 'ISBN[:\s]*([0-9Xx-]+)' }
     *   cover: { xpath: "//img[@class='cover']/@src", type: image }   a picture, brought into the library
     *   site: { xpath: "//a[@class='site']/@href", type: link }      an address, pointed at the new site when it is the old one
     *   formats: { xpath: "//ul[@class='formats']/li", list: true }  every node, as a list
     *   buy_links: { xpath: "//div[@class='buy']//a", rows: { label: text, url: href } }   a repeater: a row for each node
     *
     * `match` keeps the part of the text its first group catches (or all it matches). A row's column is `text`, an attribute
     * (`href`, `@title`), or an XPath from the node (`.//span`). Links and pictures are left as the old site has them; the importer
     * points them at the new site. Values that are empty are left out.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function fields(\DOMXPath $xpath, array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $rule) {
            $rule = is_array($rule) ? $rule : ['xpath' => (string)$rule];
            $nodes = $this->nodes($xpath, (string)($rule['xpath'] ?? ''));
            if (is_array($rule['rows'] ?? null)) {
                $rows = [];
                foreach ($nodes as $node) {
                    $row = [];
                    foreach ($rule['rows'] as $column => $what) {
                        $value = $this->part($xpath, $node, (string)$what);
                        if ($value !== '') {
                            $row[(string)$column] = $value;
                        }
                    }
                    if ($row !== []) {
                        $rows[] = $row;
                    }
                }
                if ($rows !== []) {
                    $out[(string)$key] = $rows;
                }
                continue;
            }
            $values = [];
            foreach ($nodes as $node) {
                $text = $this->clean($node->nodeValue ?? '');
                if (($rule['match'] ?? '') !== '') {
                    $text = @preg_match('~' . str_replace('~', '\~', (string)$rule['match']) . '~u', $text, $m) ? trim($m[1] ?? $m[0]) : '';
                }
                if (($rule['type'] ?? '') === 'number') {
                    $text = preg_match('/-?\d+(?:[.,]\d+)?/', $text, $m) ? $m[0] : '';
                }
                if ($text !== '') {
                    $values[] = $text;
                }
            }
            if ($values === []) {
                continue;
            }
            $value = !empty($rule['list']) ? $values : $values[0];
            if (($rule['type'] ?? '') === 'number') {
                $value = is_array($value) ? $value : (ctype_digit(ltrim($value, '-')) ? (int)$value : (float)str_replace(',', '.', $value));
            }
            $out[(string)$key] = $value;
        }
        return $out;
    }

    /** A column of a row: the node's text, an attribute of it, or the text of an XPath from it. */
    private function part(\DOMXPath $xpath, \DOMNode $node, string $what): string
    {
        $what = trim($what);
        if ($what === '' || $what === 'text') {
            return $this->clean($node->textContent);
        }
        if (preg_match('/^@?([A-Za-z_:][A-Za-z0-9_:.-]*)$/', $what, $m)) {
            return $node instanceof \DOMElement ? trim($node->getAttribute($m[1])) : '';
        }
        $found = $this->nodes($xpath, $what, $node)[0] ?? null;
        return $found === null ? '' : $this->clean($found->nodeValue ?? '');
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($document);
    }

    /** @return \DOMNode[] */
    private function nodes(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): array
    {
        if (trim($expression) === '') {
            return [];
        }
        $found = @$xpath->query($expression, $context);
        return $found === false ? [] : iterator_to_array($found);
    }

    private function text(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): string
    {
        $node = $this->nodes($xpath, $expression, $context)[0] ?? null;
        return $node === null ? '' : $this->clean($node->textContent);
    }

    private function attribute(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): string
    {
        $node = $this->nodes($xpath, $expression, $context)[0] ?? null;
        return $node === null ? '' : trim($node->nodeValue ?? '');
    }

    private function html(\DOMXPath $xpath, string $expression): string
    {
        $node = $this->nodes($xpath, $expression)[0] ?? null;
        return $node === null ? '' : $this->inner($node);
    }

    private function inner(\DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return trim($html);
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);
    }
}
