<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Turns the HTML of another site (WordPress, Joomla, a page pasted from Word) into Markdown: paragraphs, headings, lists,
 * quotes, links, images, simple tables and code come over as Markdown, layout leftovers (inline styles, float classes,
 * Word's own classes, tables used only to place pictures, empty paragraphs) are dropped, and only what Markdown cannot
 * say (a video embed, superscript) stays as a small piece of HTML. What it could not carry over is listed in notes().
 *
 * Links and images go through the closure given to the constructor, so the caller can point them at the new site.
 */
final class HtmlToMarkdown
{
    private const BLOCKS = ['p', 'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav', 'figure', 'figcaption', 'center', 'address', 'details', 'summary', 'form', 'fieldset'];
    private const KEEP_INLINE = ['sup', 'sub', 'small', 'u', 'mark', 'ins', 'del', 'kbd', 'abbr'];
    private const DROP = ['script', 'style', 'noscript', 'head', 'meta', 'link', 'button', 'input', 'select', 'textarea', 'object', 'embed', 'svg', 'canvas'];
    private const EMBED_HOSTS = ['youtube.com', 'youtube-nocookie.com', 'youtu.be', 'vimeo.com', 'soundcloud.com', 'player.vimeo.com', 'open.spotify.com', 'bandcamp.com', 'google.com/maps', 'maps.google.com'];

    /** @var string[] */
    private array $notes = [];

    /** @var (\Closure(string, string): string)|null address rewriting: the address and what it is ("link" or "image") */
    private ?\Closure $rewrite;

    /** @param (\Closure(string, string): string)|null $rewrite */
    public function __construct(?\Closure $rewrite = null)
    {
        $this->rewrite = $rewrite;
    }

    /** What could not be carried over faithfully in the last conversion, without repeats. @return string[] */
    public function notes(): array
    {
        return array_values(array_unique($this->notes));
    }

    public function convert(string $html): string
    {
        $this->notes = [];
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body><div id="gnozio-root">' . $html . '</div></body>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('gnozio-root');
        if (!$root instanceof \DOMElement) {
            return '';
        }
        $out = $this->blocks($root);
        $text = implode("\n\n", array_filter($out, static fn(string $block): bool => trim($block) !== ''));
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        return $text === '' ? '' : $text . "\n";
    }

    /**
     * The blocks inside a container, in order. Runs of inline content (text, emphasis, links, pictures, line breaks) between
     * block elements become paragraphs of their own.
     *
     * @return string[]
     */
    private function blocks(\DOMNode $container): array
    {
        $blocks = [];
        $run = '';
        $flush = function () use (&$blocks, &$run): void {
            foreach ($this->paragraphs($run) as $paragraph) {
                $blocks[] = $paragraph;
            }
            $run = '';
        };
        foreach (iterator_to_array($container->childNodes) as $node) {
            if ($node instanceof \DOMElement && $this->isBlock($node)) {
                $flush();
                foreach ($this->block($node) as $block) {
                    $blocks[] = $block;
                }
                continue;
            }
            if ($node instanceof \DOMElement && strtolower($node->nodeName) === 'img' && trim($run) === '' && $this->floatOf($node) === '') {
                // A picture that opens a paragraph is a picture of its own here (one floated to a side stays with the text that goes around it).
                $flush();
                $image = $this->image($node);
                if ($image !== '') {
                    $blocks[] = $image;
                }
                continue;
            }
            $run .= $this->inline($node);
        }
        $flush();
        return $blocks;
    }

    /** Text with line breaks, as paragraphs: a blank line between them, and nothing for an empty one. @return string[] */
    private function paragraphs(string $text): array
    {
        $text = $this->tidy($text);
        if ($text === '') {
            return [];
        }
        return [$this->guardLineStart($text)];
    }

    private function isBlock(\DOMElement $element): bool
    {
        $name = strtolower($element->nodeName);
        return in_array($name, self::BLOCKS, true)
            || in_array($name, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'blockquote', 'pre', 'hr', 'table', 'iframe', 'video', 'audio'], true);
    }

    /** @return string[] */
    private function block(\DOMElement $element): array
    {
        $name = strtolower($element->nodeName);
        switch ($name) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text = $this->tidy($this->inlineChildren($element));
                $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;
                $text = trim($text, " \t*_");
                return $text === '' ? [] : [str_repeat('#', (int)$name[1]) . ' ' . $text];
            case 'ul':
            case 'ol':
                $list = $this->listItems($element, 0);
                return $list === '' ? [] : [$list];
            case 'dl':
                return $this->definitionList($element);
            case 'blockquote':
                $inner = implode("\n\n", $this->blocks($element));
                if (trim($inner) === '') {
                    return [];
                }
                return [implode("\n", array_map(static fn(string $line): string => rtrim('> ' . $line), explode("\n", $inner)))];
            case 'pre':
                $code = rtrim($element->textContent, "\n");
                if (trim($code) === '') {
                    return [];
                }
                $fence = str_contains($code, '```') ? '~~~' : '```';
                return [$fence . "\n" . $code . "\n" . $fence];
            case 'hr':
                return ['---'];
            case 'table':
                return $this->table($element);
            case 'iframe':
            case 'video':
            case 'audio':
                return $this->embed($element);
            default:
                // A container with no meaning of its own: its contents are the blocks.
                return $this->blocks($element);
        }
    }

    /** A list, one item per line, nested lists indented under their item. */
    private function listItems(\DOMElement $list, int $depth): string
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $number = (int)($list->getAttribute('start') ?: 1);
        $lines = [];
        foreach ($list->childNodes as $item) {
            if (!$item instanceof \DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }
            $marker = $ordered ? ($number++) . '. ' : '- ';
            $parts = [];
            $run = '';
            $flush = function () use (&$parts, &$run): void {
                $text = $this->tidy($run);
                if ($text !== '') {
                    $parts[] = $this->guardLineStart($text);
                }
                $run = '';
            };
            foreach (iterator_to_array($item->childNodes) as $child) {
                if ($child instanceof \DOMElement && in_array(strtolower($child->nodeName), ['ul', 'ol'], true)) {
                    $flush();
                    $nested = $this->listItems($child, $depth + 1);
                    if ($nested !== '') {
                        $parts[] = $nested;
                    }
                } elseif ($child instanceof \DOMElement && $this->isBlock($child)) {
                    $flush();
                    foreach ($this->block($child) as $block) {
                        $parts[] = $block;
                    }
                } else {
                    $run .= $this->inline($child);
                }
            }
            $flush();
            if ($parts === []) {
                continue;
            }
            $indent = str_repeat(' ', strlen($marker));
            $body = implode("\n", $parts);
            $body = implode("\n" . $indent, explode("\n", $body));
            $lines[] = $marker . $body;
        }
        return implode("\n", $lines);
    }

    /** @return string[] */
    private function definitionList(\DOMElement $list): array
    {
        $blocks = [];
        foreach ($list->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $text = $this->tidy($this->inlineChildren($child));
            if ($text === '') {
                continue;
            }
            $blocks[] = strtolower($child->nodeName) === 'dt' ? '**' . trim($text, '*') . '**' : $text;
        }
        return $blocks;
    }

    /**
     * A table of plain cells becomes a Markdown table. A table used to place pictures and paragraphs side by side (cells with
     * blocks in them, or a single column) is taken apart and its cells read one after the other.
     *
     * @return string[]
     */
    private function table(\DOMElement $table): array
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $row) {
            // Rows of a table inside this one belong to that table.
            $owner = $row->parentNode;
            while ($owner !== null && strtolower($owner->nodeName) !== 'table') {
                $owner = $owner->parentNode;
            }
            if ($owner !== $table) {
                continue;
            }
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = $cell;
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return [];
        }
        $columns = max(array_map('count', $rows));
        $plain = $columns > 1 && count($rows) > 1;
        foreach ($rows as $cells) {
            foreach ($cells as $cell) {
                if ((int)$cell->getAttribute('colspan') > 1 || (int)$cell->getAttribute('rowspan') > 1 || $this->hasBlockContent($cell)) {
                    $plain = false;
                    break 2;
                }
            }
        }
        if (!$plain) {
            $blocks = [];
            foreach ($rows as $cells) {
                foreach ($cells as $cell) {
                    foreach ($this->blocks($cell) as $block) {
                        $blocks[] = $block;
                    }
                }
            }
            return $blocks;
        }

        $lines = [];
        $headerDone = false;
        foreach ($rows as $index => $cells) {
            $texts = [];
            foreach ($cells as $cell) {
                $text = $this->tidy($this->inlineChildren($cell));
                $texts[] = str_replace(['|', "\n"], ['\|', ' '], $text);
            }
            while (count($texts) < $columns) {
                $texts[] = '';
            }
            $lines[] = '| ' . implode(' | ', $texts) . ' |';
            if (!$headerDone) {
                $lines[] = '|' . str_repeat(' --- |', $columns);
                $headerDone = true;
            }
        }
        if (strtolower($rows[0][0]->nodeName) !== 'th') {
            $this->notes[] = 'A table had no header row; its first row is used as the header.';
        }
        return [implode("\n", $lines)];
    }

    private function hasBlockContent(\DOMElement $cell): bool
    {
        foreach ($cell->getElementsByTagName('*') as $child) {
            if ($this->isBlock($child) || strtolower($child->nodeName) === 'img') {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private function embed(\DOMElement $element): array
    {
        $src = trim($element->getAttribute('src'));
        if ($src === '' && strtolower($element->nodeName) !== 'iframe') {
            foreach ($element->getElementsByTagName('source') as $source) {
                $src = trim($source->getAttribute('src'));
                break;
            }
        }
        if ($src === '') {
            return [];
        }
        $src = preg_replace('#^//#', 'https://', $src) ?? $src;
        if (strtolower($element->nodeName) === 'iframe') {
            $host = strtolower((string)parse_url($src, PHP_URL_HOST));
            $path = $host . strtolower((string)parse_url($src, PHP_URL_PATH));
            $allowed = false;
            foreach (self::EMBED_HOSTS as $known) {
                if (str_contains($path, $known) || str_ends_with($host, $known)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed || !preg_match('#^https://#i', $src)) {
                $this->notes[] = 'An embedded frame from ' . ($host !== '' ? $host : 'another site') . ' was left out.';
                return [];
            }
            $title = trim($element->getAttribute('title'));
            $width = (int)$element->getAttribute('width');
            $height = (int)$element->getAttribute('height');
            return ['<iframe src="' . $this->attr($src) . '"'
                . ($title !== '' ? ' title="' . $this->attr($title) . '"' : '')
                . ($width > 0 ? ' width="' . $width . '"' : '')
                . ($height > 0 ? ' height="' . $height . '"' : '')
                . ' loading="lazy" allowfullscreen></iframe>'];
        }
        $src = $this->mapped($src, 'file');
        $tag = strtolower($element->nodeName);
        return ['<' . $tag . ' src="' . $this->attr($src) . '" controls></' . $tag . '>'];
    }

    private function inlineChildren(\DOMNode $node): string
    {
        $text = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $text .= $this->inline($child);
        }
        return $text;
    }

    private function inline(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return $this->escape(preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '');
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $name = strtolower($node->nodeName);
        if (in_array($name, self::DROP, true)) {
            return '';
        }
        switch ($name) {
            case 'br':
                return "  \n";
            case 'strong':
            case 'b':
                return $this->wrap($this->inlineChildren($node), '**');
            case 'em':
            case 'i':
            case 'cite':
                return $this->wrap($this->inlineChildren($node), '*');
            case 'code':
            case 'tt':
                $code = $node->textContent;
                return trim($code) === '' ? '' : '`' . str_replace('`', "'", $code) . '`';
            case 'a':
                return $this->link($node);
            case 'img':
                return $this->image($node);
            case 'iframe':
            case 'video':
            case 'audio':
                $embed = $this->embed($node);
                return $embed === [] ? '' : "\n\n" . $embed[0] . "\n\n";
            default:
                if (in_array($name, self::KEEP_INLINE, true)) {
                    $inner = $this->inlineChildren($node);
                    return trim($inner) === '' ? '' : '<' . $name . '>' . $inner . '</' . $name . '>';
                }
                if ($this->isBlock($node)) {
                    // A block inside an inline run (a paragraph inside a link, say): set apart on its own lines.
                    $blocks = $this->block($node);
                    return $blocks === [] ? '' : "\n\n" . implode("\n\n", $blocks) . "\n\n";
                }
                // span, font, label, and unknown tags such as <org>: their content, without the wrapper.
                return $this->inlineChildren($node);
        }
    }

    private function link(\DOMElement $anchor): string
    {
        $text = $this->inlineChildren($anchor);
        $href = trim($anchor->getAttribute('href'));
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^\s*javascript:/i', $href)) {
            return $text;
        }
        if (trim($text) === '') {
            return '';
        }
        $href = $this->mapped($href, 'link');
        if ($href === '') {
            return $text;
        }
        $title = trim($anchor->getAttribute('title'));
        $leading = preg_match('/^\s+/', $text, $m) ? $m[0] : '';
        $trailing = preg_match('/\s+$/', $text, $m) ? $m[0] : '';
        $label = trim($text);
        $label = preg_replace('/\s*\n\s*/', ' ', $label) ?? $label;
        return $leading . '[' . $label . '](' . $this->destination($href) . ($title !== '' && $title !== trim(strip_tags($label)) ? ' "' . str_replace('"', '\"', $title) . '"' : '') . ')' . $trailing;
    }

    private function image(\DOMElement $image): string
    {
        $src = trim($image->getAttribute('src'));
        if ($src === '') {
            $src = trim($image->getAttribute('data-src'));
        }
        if ($src === '' || str_starts_with($src, 'data:')) {
            return '';
        }
        $alt = $this->description($image);
        $src = $this->mapped($src, 'image');
        if ($src === '') {
            return '';
        }
        $alt = str_replace(['[', ']'], ['(', ')'], $alt);
        // A picture that floated to a side on the old page goes there here too (the attribute the editor writes for it).
        $side = $this->floatOf($image);
        return '![' . $alt . '](' . $this->destination($src) . ')' . ($side !== '' ? '{align=' . $side . '} ' : '');
    }

    /**
     * What a reader who cannot see the picture is told: its alternative text, else its title, else the caption it has on the page
     * (a figure's figcaption, WordPress's wp-caption-text). A file name or a camera's name tells nobody anything.
     */
    private function description(\DOMElement $image): string
    {
        foreach ([$image->getAttribute('alt'), $image->getAttribute('title')] as $text) {
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
            if (self::describes($text)) {
                return $text;
            }
        }
        $caption = $this->captionOf($image);
        return self::describes($caption) && mb_strlen($caption) <= 200 ? $caption : '';
    }

    /**
     * The Markdown cut at the links to a YouTube video that stand alone in a paragraph (what WordPress turns into an embedded player):
     * the text before, the video, the text after. A link inside a sentence, a list, a quote or a code block stays where it is, and
     * Markdown with no such link comes back as one text segment.
     *
     * @return array<int, array{type: 'text'|'video', value: string}> a video's value is its address, as the Video block takes it
     */
    public static function splitVideos(string $markdown): array
    {
        $segments = [];
        $text = '';
        $fenced = false;
        foreach (preg_split('/\n{2,}/', trim($markdown, "\n")) ?: [] as $paragraph) {
            // A fence can hold a blank line, so a paragraph is also looked at for where one opens and closes.
            $opens = preg_match_all('/^(?:```|~~~)/m', $paragraph);
            $video = !$fenced && $opens === 0 ? self::youtubeAddress($paragraph) : '';
            if ($video !== '') {
                if (trim($text) !== '') {
                    $segments[] = ['type' => 'text', 'value' => trim($text, "\n") . "\n"];
                }
                $text = '';
                $segments[] = ['type' => 'video', 'value' => $video];
                continue;
            }
            if ($opens % 2 === 1) {
                $fenced = !$fenced;
            }
            $text .= ($text === '' ? '' : "\n\n") . $paragraph;
        }
        if (trim($text) !== '') {
            $segments[] = ['type' => 'text', 'value' => trim($text, "\n") . "\n"];
        }
        return $segments;
    }

    /** The address of a YouTube video when the paragraph is only a link to it (written out, or as a link whose text is the address); else ''. */
    private static function youtubeAddress(string $paragraph): string
    {
        $line = trim($paragraph);
        if (str_contains($line, "\n")) {
            return '';
        }
        if (preg_match('/^\[(https?:\/\/[^\s\]]+)\]\((?:<)?(https?:\/\/[^\s>)]+)(?:>)?\)$/i', $line, $m) === 1 && $m[1] === $m[2]) {
            $line = $m[1];
        } elseif (preg_match('/^<(https?:\/\/[^\s>]+)>$/i', $line, $m) === 1) {
            $line = $m[1];
        }
        if (preg_match('/^https?:\/\/\S+$/i', $line) !== 1) {
            return '';
        }
        $info = BlockRenderer::videoInfo($line);
        return $info !== null && $info['provider'] === 'youtube' ? $info['watch_url'] : '';
    }

    /** Whether a text says something about a picture: not empty, not a file name (hoos_l.gif), not a camera's name (IMG_1234, DSC00123). */
    public static function describes(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || preg_match('/^[\w\-. ]+\.(gif|jpe?g|png|webp|bmp)$/i', $text)) {
            return false;
        }
        return preg_match('/^(?:img|dsc[nf]?|dcim|pxl|mvimg|pic|image|photo|scan|screenshot|untitled)[-_ ]*[\d_\- ]*$/i', $text) !== 1 && preg_match('/^[\d_\- ]+$/', $text) !== 1;
    }

    /** The caption a picture has on the page: the wrapper it sits in (a figure, WordPress's caption box), without the picture's own link. */
    private function captionOf(\DOMElement $image): string
    {
        $wrapper = $this->wrapperOf($image);
        if ($wrapper === null) {
            return '';
        }
        foreach ($wrapper->getElementsByTagName('*') as $child) {
            if (strtolower($child->nodeName) === 'figcaption' || preg_match('/(?:^|\s)wp-caption-text(?:\s|$)/i', $child->getAttribute('class')) === 1) {
                return trim(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');
            }
        }
        return '';
    }

    /** The figure or caption box a picture sits in (straight, or inside its own link). */
    private function wrapperOf(\DOMElement $image): ?\DOMElement
    {
        $parent = $image->parentNode;
        if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'a') {
            $parent = $parent->parentNode;
        }
        if ($parent instanceof \DOMElement && (strtolower($parent->nodeName) === 'figure' || preg_match('/(?:^|\s)(?:wp-caption|wp-block-image)(?:\s|$)/i', $parent->getAttribute('class')) === 1)) {
            return $parent;
        }
        return null;
    }

    /** The side a picture floated to on the old page, 'left' or 'right' (its align attribute, WordPress's alignleft / alignright, or a float style); '' for one in the line of text. */
    private function floatOf(\DOMElement $image): string
    {
        $align = strtolower(trim($image->getAttribute('align')));
        if ($align === 'left' || $align === 'right') {
            return $align;
        }
        $candidates = [$image];
        $parent = $image->parentNode;
        if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'a') {
            $candidates[] = $parent;
        }
        if (($wrapper = $this->wrapperOf($image)) !== null) {
            $candidates[] = $wrapper;
        }
        foreach ($candidates as $element) {
            $class = ' ' . strtolower($element->getAttribute('class')) . ' ';
            if (str_contains($class, ' alignleft ')) {
                return 'left';
            }
            if (str_contains($class, ' alignright ')) {
                return 'right';
            }
            if (preg_match('/(?:^|;)\s*float:\s*(left|right)/i', $element->getAttribute('style'), $m) === 1) {
                return strtolower($m[1]);
            }
        }
        return '';
    }

    private function mapped(string $address, string $kind): string
    {
        return $this->rewrite !== null ? ($this->rewrite)($address, $kind) : $address;
    }

    private function destination(string $address): string
    {
        $address = trim($address);
        if (preg_match('/[\s()<>]/', $address)) {
            return '<' . str_replace(['<', '>'], ['%3C', '%3E'], $address) . '>';
        }
        return $address;
    }

    /** Emphasis marks go around the words, not the spaces beside them; empty emphasis is nothing. */
    private function wrap(string $text, string $mark): string
    {
        if (trim($text) === '') {
            return $text;
        }
        $leading = preg_match('/^\s+/', $text, $m) ? $m[0] : '';
        $trailing = preg_match('/\s+$/', $text, $m) ? $m[0] : '';
        $core = trim($text);
        // Already the same emphasis inside (a bold link in a bold paragraph): once is enough.
        if (str_starts_with($core, $mark) && str_ends_with($core, $mark) && strlen($core) > 2 * strlen($mark)) {
            return $text;
        }
        // Emphasis cannot span a hard line break; each line gets its own.
        if (str_contains($core, "\n")) {
            $parts = preg_split('/(\s{2}\n)/', $core, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$core];
            $core = implode('', array_map(fn(string $part): string => trim($part) === '' ? $part : $mark . trim($part) . $mark, $parts));
            return $leading . $core . $trailing;
        }
        return $leading . $mark . $core . $mark . $trailing;
    }

    /** Characters that would turn plain words into Markdown. */
    private function escape(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        // An entity written out as text must not be read back as one.
        $text = preg_replace('/&(?=#?\w+;)/', '\\&', $text) ?? $text;
        $text = str_replace(['*', '`', '<'], ['\*', '\`', '&lt;'], $text);
        // Square brackets are plain text unless something after them could make a link.
        $text = preg_replace('/\](?=[(\[])/', '\\]', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\_', $text) ?? $text;
        return $text;
    }

    /** A paragraph that begins like a heading, a list or a quote would become one; a backslash prevents it. */
    private function guardLineStart(string $text): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            $line = preg_replace('/^(\s{0,3}\d+)([.)])(?=\s)/', '$1\\\\$2', $line) ?? $line;
            $lines[$index] = preg_replace('/^(\s{0,3})(#{1,6}(?=\s|$)|[-+](?=\s)|>|={3,}$|-{3,}$|\*{3,}$)/', '$1\\\\$2', $line) ?? $line;
        }
        return implode("\n", $lines);
    }

    /** Spaces around line breaks and at the ends, and non-breaking spaces, cleaned up. */
    private function tidy(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?(\s{2}\n) ?/', "  \n", $text) ?? $text;
        $text = preg_replace('/\n +/', "\n", $text) ?? $text;
        // A line break at the very start or end of a paragraph means nothing.
        $text = preg_replace('/^(\s*\n)+|(\s{2}\n\s*)+$/', '', $text) ?? $text;
        // Two line breaks in a row are a paragraph break.
        $text = preg_replace('/(\s{2}\n){2,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
