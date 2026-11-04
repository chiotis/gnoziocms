<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The `Link: …; rel=preload` headers of a public page: its main stylesheet and the picture that opens it (the one drawn with
 * fetchpriority="high", see Images::render()). A browser starts on them as soon as the headers arrive, and a CDN that sends
 * 103 Early Hints (Cloudflare) sends them before the page is even made, while the server is still drawing it.
 *
 * For the picture, the first <source> of its <picture> is preloaded with the same srcset, sizes and type, so the browser picks
 * the same file it will use in the page; a browser that cannot show that type skips it.
 */
final class EarlyHints
{
    /** A header value is kept under this length: a server or CDN may refuse a long header. */
    private const MAX_LENGTH = 2000;

    /** @return list<string> the values of the Link headers */
    public static function links(string $html, string $stylesheet): array
    {
        // Only a page (not a feed or a sitemap), and only a stylesheet that exists (its address carries a version).
        if (stripos($html, '<head') === false) {
            return [];
        }
        $links = [];
        if (str_contains($stylesheet, '?v=') && self::safe($stylesheet)) {
            $links[] = '<' . $stylesheet . '>; rel=preload; as=style';
        }
        $picture = self::openingPicture($html);
        if ($picture !== null) {
            $links[] = $picture;
        }
        return $links;
    }

    /** The preload of the picture drawn with fetchpriority="high", or null. */
    private static function openingPicture(string $html): ?string
    {
        $at = strpos($html, 'fetchpriority="high"');
        if ($at === false) {
            return null;
        }
        $start = strrpos(substr($html, 0, $at), '<picture>');
        $img = strrpos(substr($html, 0, $at), '<img');
        if ($img === false) {
            return null;
        }
        $tag = substr($html, $img, (int)strpos($html, '>', $img) - $img);
        $src = self::attribute($tag, 'src');
        // A <picture> whose <img> this is (nothing closes it before the <img>): its first source.
        if ($start !== false && !str_contains(substr($html, $start, $img - $start), '</picture>')
            && preg_match('/<source\s[^>]*>/', substr($html, $start, $img - $start), $m) === 1) {
            $srcset = self::attribute($m[0], 'srcset');
            $sizes = self::attribute($m[0], 'sizes');
            $type = self::attribute($m[0], 'type');
            $first = trim(explode(' ', trim(explode(',', $srcset)[0]))[0]);
            if ($srcset !== '' && $first !== '' && self::safe($srcset . $sizes . $type . $first)) {
                $link = '<' . $first . '>; rel=preload; as=image; imagesrcset="' . $srcset . '"'
                    . ($sizes !== '' ? '; imagesizes="' . $sizes . '"' : '') . ($type !== '' ? '; type="' . $type . '"' : '') . '; fetchpriority=high';
                return strlen($link) <= self::MAX_LENGTH ? $link : null;
            }
            return null;
        }
        return $src !== '' && !str_starts_with($src, 'data:') && self::safe($src) ? '<' . $src . '>; rel=preload; as=image; fetchpriority=high' : null;
    }

    private static function attribute(string $tag, string $name): string
    {
        return preg_match('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    /** Nothing that could end the address, the quoted value or the header. */
    private static function safe(string $value): bool
    {
        return preg_match('/[<>"\r\n\x00-\x1f\x7f]/', $value) !== 1;
    }
}
