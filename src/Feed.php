<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The feed (RSS 2.0) readers and aggregators follow: the newest posts of the site at /feed, the entries of a content type at
 * /<type>/feed, and those filed under a category, tag or term of the site's own at /category/<term>/feed, each in the language
 * of its address. A feed lists published, visible entries only, newest first, each with its address, date, categories and its
 * excerpt (or the start of its text). The choices are the site's SEO settings: a feed can be switched off, and a site that asked
 * to stay out of search has none.
 */
final class Feed
{
    /** @var string[] types that are never in a feed: pages and forms are not news */
    private const SKIP = ['pages', 'forms'];

    /** How many characters of an entry's text stand for it when it has no excerpt. */
    private const SUMMARY = 300;

    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(string, string, string): string $termPath the public path of a term: taxonomy, term id, language prefix
     */
    public function __construct(private ContentRepository $content, private \Closure $settings, private \Closure $absoluteUrl, private \Closure $taxonomies, private \Closure $termPath)
    {
    }

    public function enabled(): bool
    {
        $seo = SeoSettings::from(($this->settings)());
        return $seo['feed']['enabled'] && !$seo['discourage'];
    }

    /** Whether the site's own feed (/feed) is there to announce: the feed is on and the site has posts. */
    public function hasSiteFeed(): bool
    {
        return $this->enabled() && in_array('posts', $this->content->getTypes(), true);
    }

    /**
     * What a feed address lists: the site's posts, one content type, or one term. Null when the address is not a feed (no such
     * type or term, or a type that is not news).
     *
     * @param string[] $base the address without "feed" and the language: [], ["posts"], ["category", "news"]
     * @return array{title: string, path: string, description: string, items: ContentItem[]}|null
     */
    public function scope(array $base, string $lang): ?array
    {
        $settings = ($this->settings)();
        $default = (string)($settings['languages']['default'] ?? 'en');
        $prefix = FrontRoute::langPrefix($lang, $default);
        $site = trim((string)($settings['title'] ?? ''));
        $types = $this->content->getTypes();
        $name = static fn(string $label): string => $site !== '' ? $site . ' » ' . $label : $label;

        if ($base === []) {
            $items = in_array('posts', $types, true) ? $this->content->getItems('posts', $lang, false, false) : [];
            return ['title' => $site, 'path' => rtrim($prefix, '/'), 'description' => trim((string)($settings['tagline'] ?? '')), 'items' => $items];
        }
        if (count($base) === 1) {
            $type = $base[0];
            if (!in_array($type, $types, true) || in_array($type, self::SKIP, true)) {
                return null;
            }
            return ['title' => $name(ucfirst($type)), 'path' => ContentPaths::archive($type, $lang, $default), 'description' => trim((string)($settings['tagline'] ?? '')), 'items' => $this->content->getItems($type, $lang, false, false)];
        }

        $taxonomies = ($this->taxonomies)();
        $key = $taxonomies->fromWord($base[0]);
        $term = $key === null ? null : $taxonomies->findByPath($key, implode('/', array_slice($base, 1)));
        if ($key === null || $term === null) {
            return null;
        }
        // The feed of a parent carries what its page lists: its sub-terms' entries too, unless the archive says otherwise.
        $ids = [$term['id']];
        if ($taxonomies->includesChildren($key)) {
            $ids = array_merge($ids, $taxonomies->descendantIds($key, $term['id']));
        }
        $items = [];
        foreach ($types as $type) {
            if (in_array($type, self::SKIP, true)) {
                continue;
            }
            foreach ($this->content->getItems($type, $lang, false, false) as $item) {
                if (array_intersect($ids, Format::list($item->meta[$key] ?? null)) !== []) {
                    $items[] = $item;
                }
            }
        }
        usort($items, fn(ContentItem $a, ContentItem $b): int => $this->when($b) <=> $this->when($a));
        return [
            'title' => $name($taxonomies->label($key, $term['id'], $lang)),
            'path' => ($this->termPath)($key, $term['id'], $prefix),
            'description' => $taxonomies->description($key, $term['id'], $lang, $default),
            'items' => $items,
        ];
    }

    /** The RSS document for what {@see scope()} found. */
    public function xml(array $scope, string $lang, string $feedPath): string
    {
        $seo = SeoSettings::from(($this->settings)());
        $limit = $seo['feed']['items'];
        $paths = new ContentPaths(($this->settings)());
        $taxonomies = ($this->taxonomies)();
        $items = array_slice($scope['items'], 0, $limit);
        $built = $items === [] ? time() : max(array_map(fn(ContentItem $i): int => $this->when($i), $items));

        $e = static fn(string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n<channel>\n"
            . '  <title>' . $e($scope['title']) . "</title>\n"
            . '  <link>' . $e(($this->absoluteUrl)($scope['path'])) . "</link>\n"
            . '  <description>' . $e($scope['description'] !== '' ? $scope['description'] : $scope['title']) . "</description>\n"
            . '  <language>' . $e($lang) . "</language>\n"
            . '  <lastBuildDate>' . gmdate(DATE_RSS, $built) . "</lastBuildDate>\n"
            . '  <atom:link href="' . $e(($this->absoluteUrl)($feedPath)) . "\" rel=\"self\" type=\"application/rss+xml\"/>\n";
        foreach ($items as $item) {
            $link = ($this->absoluteUrl)($paths->publicPath($item->type, $item->slug, $item->lang));
            $xml .= "  <item>\n"
                . '    <title>' . $e(trim((string)($item->meta['title'] ?? $item->slug))) . "</title>\n"
                . '    <link>' . $e($link) . "</link>\n"
                . '    <guid isPermaLink="true">' . $e($link) . "</guid>\n"
                . '    <pubDate>' . gmdate(DATE_RSS, $this->when($item)) . "</pubDate>\n";
            foreach (Format::list($item->meta['categories'] ?? null) as $termId) {
                $label = $taxonomies->label('categories', $termId, $lang);
                if ($label !== '') {
                    $xml .= '    <category>' . $e($label) . "</category>\n";
                }
            }
            $summary = $this->summary($item);
            if ($summary !== '') {
                $xml .= '    <description>' . $e($summary) . "</description>\n";
            }
            $xml .= "  </item>\n";
        }
        return $xml . "</channel>\n</rss>\n";
    }

    private function when(ContentItem $item): int
    {
        $date = $item->meta['date'] ?? '';
        if (is_int($date)) {
            return $date;
        }
        return strtotime((string)$date) ?: $item->mtime;
    }

    /**
     * The text of an entry's HTML as one line, without the code of its blocks (fenced code in the Markdown is not text for a
     * reader). With `$skipHeadings` the headings go too, so the text starts at the first paragraph.
     */
    public static function plainText(string $html, bool $skipHeadings = false): string
    {
        $html = (string)preg_replace('#<pre\b.*?</pre>#is', ' ', $html);
        if ($skipHeadings) {
            $html = (string)preg_replace('#<h[1-6]\b.*?</h[1-6]>#is', ' ', $html);
        }
        return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** The excerpt of an entry, or the start of its text (without the code of its blocks), as plain text. */
    private function summary(ContentItem $item): string
    {
        $excerpt = trim(strip_tags((string)($item->meta['excerpt'] ?? '')));
        if ($excerpt !== '') {
            return $excerpt;
        }
        $text = self::plainText($item->html);
        if (mb_strlen($text) <= self::SUMMARY) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::SUMMARY);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > self::SUMMARY / 2 ? mb_substr($cut, 0, $space) : $cut, " ,;:.") . '…';
    }
}
