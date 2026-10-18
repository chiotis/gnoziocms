<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * "On this page" contents for rendered Markdown: gives every <h2> (and <h3>) an id when it has
 * none and returns the list of headings.
 */
final class Toc
{
    /** @return array{html: string, items: array<int, array{id: string, text: string, level: int}>} */
    public static function build(string $html): array
    {
        $items = [];
        $used = [];
        $html = (string)preg_replace_callback('#<(h[23])((?:\s[^>]*)?)>(.*?)</\1>#si', static function (array $m) use (&$items, &$used): string {
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                return $m[0];
            }
            $attributes = $m[2];
            if (preg_match('/\sid="([^"]+)"/', $attributes, $existing)) {
                $id = $existing[1];
            } else {
                $base = self::slug($text);
                $id = $base;
                for ($i = 2; isset($used[$id]); $i++) {
                    $id = $base . '-' . $i;
                }
                $attributes .= ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
            }
            $used[$id] = true;
            $items[] = ['id' => $id, 'text' => $text, 'level' => (int)$m[1][1]];
            return '<' . $m[1] . $attributes . '>' . $m[3] . '</' . $m[1] . '>';
        }, $html);
        return ['html' => $html, 'items' => $items];
    }

    /** Readable ids that keep Greek letters (browsers handle them in fragments). */
    private static function slug(string $text): string
    {
        $slug = mb_strtolower($text, 'UTF-8');
        $slug = (string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? mb_substr($slug, 0, 60) : 'section';
    }
}
