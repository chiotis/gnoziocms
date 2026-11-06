<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Reads RSS (0.9x, 1.0, 2.0) and Atom feeds, and the readable text of a web page, for AI publishing schedules
 * (docs/ai-publishing-plan.md, phase 4). Fetched through SafeHttp, so the addresses get the same guards as pictures from MCP: public
 * hosts only, a size and a time limit. What comes back is plain text: tags, scripts and entities are gone, and every field is cut to
 * a length, because it is sent to a model as quoted material, never as instructions.
 */
final class FeedReader
{
    public const MAX_FEED_BYTES = 3 * 1048576;
    public const MAX_PAGE_BYTES = 3 * 1048576;
    private const SUMMARY = 700;
    private const PAGE_TEXT = 6000;

    public function __construct(private SafeHttp $http, private string $tmpDir)
    {
    }

    /**
     * The items of a feed, newest first.
     * @return array{ok: bool, error: string, items: array<int, array{key: string, title: string, link: string, summary: string, date: int}>}
     */
    public function items(string $url, int $max = 30): array
    {
        $body = $this->fetch($url, 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.5', self::MAX_FEED_BYTES);
        if (!$body['ok']) {
            return ['ok' => false, 'error' => $body['error'], 'items' => []];
        }
        try {
            $items = self::parse($body['text'], $body['url']);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'items' => []];
        }
        return ['ok' => true, 'error' => '', 'items' => array_slice($items, 0, max(1, $max))];
    }

    /**
     * The readable text of a page: its title and the text of its article (or main part, or body) without menus, scripts and forms.
     * @return array{ok: bool, error: string, title: string, text: string, url: string}
     */
    public function page(string $url): array
    {
        $body = $this->fetch($url, 'text/html, application/xhtml+xml;q=0.9, */*;q=0.5', self::MAX_PAGE_BYTES);
        if (!$body['ok']) {
            return ['ok' => false, 'error' => $body['error'], 'title' => '', 'text' => '', 'url' => $url];
        }
        [$title, $text] = self::pageText(self::toUtf8($body['text']));
        return $text === '' ? ['ok' => false, 'error' => 'The page has no readable text.', 'title' => $title, 'text' => '', 'url' => $body['url']]
            : ['ok' => true, 'error' => '', 'title' => $title, 'text' => $text, 'url' => $body['url']];
    }

    /**
     * Items of a feed's XML. Throws InvalidArgumentException for something that is not a feed.
     * @return array<int, array{key: string, title: string, link: string, summary: string, date: int}>
     */
    public static function parse(string $xml, string $base = ''): array
    {
        if (preg_match('/<!ENTITY/i', $xml) === 1) {
            throw new \InvalidArgumentException('The feed declares entities of its own, which are not read.');
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML(ltrim($xml, "\xEF\xBB\xBF \t\r\n"), LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \InvalidArgumentException('The address does not answer with a feed (RSS or Atom).');
        }
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query("//*[local-name()='item' or local-name()='entry']");
        if ($nodes === false || $nodes->length === 0) {
            if (in_array($doc->documentElement?->localName, ['rss', 'feed', 'RDF'], true)) {
                return [];
            }
            throw new \InvalidArgumentException('The address does not answer with a feed (RSS or Atom).');
        }
        $items = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $child = static function (array $names) use ($node): ?\DOMElement {
                foreach ($names as $name) {
                    foreach ($node->childNodes as $c) {
                        if ($c instanceof \DOMElement && $c->localName === $name) {
                            return $c;
                        }
                    }
                }
                return null;
            };
            $link = '';
            foreach ($node->childNodes as $c) {
                if ($c instanceof \DOMElement && $c->localName === 'link') {
                    $href = trim($c->getAttribute('href'));
                    $rel = $c->getAttribute('rel');
                    if ($href !== '' && ($rel === '' || $rel === 'alternate')) {
                        $link = $href;
                        break;
                    }
                    if ($href === '' && trim($c->textContent) !== '') {
                        $link = trim($c->textContent);
                        break;
                    }
                }
            }
            $link = self::absolute($link, $base);
            $title = self::plain($child(['title'])?->textContent ?? '', 300);
            $summary = self::plain($child(['description', 'summary', 'encoded', 'content'])?->textContent ?? '', self::SUMMARY);
            $dateText = trim($child(['pubDate', 'published', 'updated', 'date', 'issued'])?->textContent ?? '');
            $date = $dateText !== '' ? (int)(strtotime($dateText) ?: 0) : 0;
            $id = trim($child(['guid', 'id'])?->textContent ?? '');
            if ($title === '' && $summary === '') {
                continue;
            }
            $items[] = ['key' => substr(hash('sha256', $id !== '' ? $id : ($link !== '' ? $link : $title)), 0, 32), 'title' => $title, 'link' => $link, 'summary' => $summary, 'date' => $date];
        }
        usort($items, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);
        return $items;
    }

    /**
     * The title and readable text of an HTML page.
     * @return array{0: string, 1: string}
     */
    public static function pageText(string $html): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);
        $title = self::plain($xpath->query('//title')?->item(0)?->textContent ?? '', 300);
        foreach (['script', 'style', 'noscript', 'nav', 'header', 'footer', 'form', 'aside', 'svg', 'iframe', 'template', 'button'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                $el->parentNode?->removeChild($el);
            }
        }
        $root = $xpath->query('//article')?->item(0) ?? $xpath->query('//main')?->item(0) ?? $xpath->query('//body')?->item(0);
        if ($title === '') {
            $title = self::plain($xpath->query('//h1')?->item(0)?->textContent ?? '', 300);
        }
        if ($root === null) {
            return [$title, ''];
        }
        // Block elements end a line, so paragraphs stay apart.
        foreach (['p', 'br', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'div', 'section', 'blockquote'] as $tag) {
            foreach (iterator_to_array($root instanceof \DOMElement ? $root->getElementsByTagName($tag) : []) as $el) {
                $el->appendChild($doc->createTextNode("\n"));
            }
        }
        $text = preg_replace(["/[ \t\x{00A0}]+/u", "/ ?\n ?/", "/\n{3,}/"], [' ', "\n", "\n\n"], (string)$root->textContent) ?? '';
        return [$title, mb_substr(trim($text), 0, self::PAGE_TEXT)];
    }

    /**
     * A page's bytes as UTF-8: as they are when they already are, else from the charset its meta tag names (Greek sites still use
     * windows-1253 and ISO-8859-7), with iconv where it is there.
     */
    public static function toUtf8(string $html): string
    {
        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }
        $charset = preg_match('/<meta[^>]+charset=["\']?\s*([A-Za-z0-9_\-]+)/i', $html, $m) === 1 ? strtolower($m[1]) : 'windows-1253';
        $converted = function_exists('iconv') ? @iconv($charset, 'UTF-8//IGNORE', $html) : false;
        if (!is_string($converted) || $converted === '') {
            try {
                $converted = mb_convert_encoding($html, 'UTF-8', in_array(strtoupper($charset), mb_list_encodings(), true) ? $charset : 'ISO-8859-7');
            } catch (\ValueError) {
                $converted = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-7');
            }
        }
        // The text is UTF-8 now; a meta tag that names the old charset would make the parser decode it twice.
        return (string)preg_replace('/(<meta[^>]+charset=["\']?\s*)[A-Za-z0-9_\-]+/i', '${1}utf-8', (string)$converted);
    }

    /** Text without tags or entities, on one line, cut to a length. */
    public static function plain(string $text, int $max): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    private static function absolute(string $link, string $base): string
    {
        if ($link === '' || preg_match('#^https?://#i', $link) === 1) {
            return preg_match('#^https?://#i', $link) === 1 ? $link : '';
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return str_starts_with($link, '/') ? $origin . $link : $origin . '/' . ltrim($link, './');
    }

    /** @return array{ok: bool, error: string, text: string, url: string} */
    private function fetch(string $url, string $accept, int $max): array
    {
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0775, true)) {
            return ['ok' => false, 'error' => 'A temporary folder could not be made.', 'text' => '', 'url' => $url];
        }
        $tmp = $this->tmpDir . '/fetch-' . bin2hex(random_bytes(6));
        try {
            $got = $this->http->download($url, $tmp, $max, 20, $accept);
            if (!$got['ok']) {
                return ['ok' => false, 'error' => $got['error'], 'text' => '', 'url' => $url];
            }
            // Raw bytes: a feed is decoded by the XML parser from its own declaration; a page by toUtf8().
            $text = (string)@file_get_contents($tmp);
            return ['ok' => true, 'error' => '', 'text' => $text, 'url' => $got['url']];
        } finally {
            @unlink($tmp);
        }
    }
}
