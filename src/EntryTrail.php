<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The trail above an entry's title written as a pattern (the Entry title block's "Trail" field): its parts in order, separated by
 * `>`. A part is one of
 *
 *   {home}             Home, with the address of the home page
 *   {type}             the name of the entry's content type, with the address of its list
 *   {title}            the entry's title (the page itself)
 *   {term:NAME}        the entry's first term in the taxonomy NAME, with the terms above it, each with the address of its page
 *   {term:NAME:alone}  that term without the terms above it
 *   {taxonomy:NAME}    the name of the taxonomy NAME, without an address
 *   Text               the text as written; Text|/address gives it an address (a path of the site, or a full address)
 *
 * so "Collections|/shop > {term:collections} > {term:product-categories}" draws "Collections / Balos / Earrings". A part with nothing
 * to show (an entry with no term in that taxonomy, an unknown token) is left out. An empty pattern is the trail of old: Home, the
 * type (for anything but a page) and the title.
 */
final class EntryTrail
{
    /** The pattern of an empty field. */
    public const DEFAULT = '{home} > {type} > {title}';

    /**
     * @param array<string, mixed> $meta the entry's front matter
     * @param string $prefix the language prefix of addresses ('' or 'en/')
     * @param \Closure(string, ?string): string $translate key, fallback
     * @param \Closure(string, string): string $label the label of a term (taxonomy, term id) in the page's language
     * @param \Closure(string, string): string $url the address of a term's page (taxonomy, term id); '' when it has none
     * @return array{crumbs: array<int, array{label: string, url: string}>, current: bool} the crumbs, and whether the last one is
     *         the page itself (the pattern ends with {title})
     */
    public static function build(string $pattern, string $type, array $meta, string $prefix, Taxonomies $taxonomies, \Closure $translate, \Closure $label, \Closure $url): array
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            $pattern = $type === 'pages' ? '{home} > {title}' : self::DEFAULT;
        }
        $parts = array_values(array_filter(array_map('trim', explode('>', $pattern)), static fn(string $part): bool => $part !== ''));
        $crumbs = [];
        $current = false;
        foreach ($parts as $index => $part) {
            $current = false;
            if (preg_match('/^\{([a-z]+)(?::([A-Za-z0-9_-]+))?(:alone)?\}$/', $part, $token)) {
                $name = $token[2] ?? '';
                switch ($token[1]) {
                    case 'home':
                        $crumbs[] = ['label' => $translate('nav.main.home', 'Home'), 'url' => '/' . $prefix];
                        break;
                    case 'type':
                        $crumbs[] = ['label' => $translate('type.' . $type, Slug::title($type)), 'url' => '/' . $prefix . $type];
                        break;
                    case 'title':
                        $title = trim((string)($meta['title'] ?? ''));
                        if ($title !== '') {
                            $crumbs[] = ['label' => $title, 'url' => ''];
                            $current = $index === count($parts) - 1;
                        }
                        break;
                    case 'taxonomy':
                        if ($name !== '' && in_array($name, $taxonomies->names(), true)) {
                            $crumbs[] = ['label' => (string)$taxonomies->load($name)['title'], 'url' => ''];
                        }
                        break;
                    case 'term':
                        $chain = self::termChain($name, $meta, $taxonomies);
                        foreach (($token[3] ?? '') !== '' ? array_slice($chain, -1) : $chain as $termId) {
                            $text = $label($name, $termId);
                            if ($text !== '') {
                                $crumbs[] = ['label' => $text, 'url' => $url($name, $termId)];
                            }
                        }
                        break;
                }
                continue;
            }
            [$text, $address] = array_pad(array_map('trim', explode('|', $part, 2)), 2, '');
            if ($text !== '') {
                $crumbs[] = ['label' => $text, 'url' => self::address($address, $prefix)];
            }
        }
        return ['crumbs' => $crumbs, 'current' => $current];
    }

    /** The ids of the entry's first known term in a taxonomy, the top one of its tree first; none when it has none. @return string[] */
    private static function termChain(string $name, array $meta, Taxonomies $taxonomies): array
    {
        if ($name === '' || !in_array($name, $taxonomies->names(), true)) {
            return [];
        }
        $known = array_column($taxonomies->load($name)['terms'], 'id');
        foreach (Format::list($meta[$name] ?? null) as $termId) {
            if (in_array((string)$termId, $known, true)) {
                return [...array_column($taxonomies->ancestors($name, (string)$termId), 'id'), (string)$termId];
            }
        }
        return [];
    }

    /** A written address: a site path gets the language prefix, a full address stays, anything else is no address. */
    private static function address(string $address, string $prefix): string
    {
        if ($address === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $address)) {
            return $address;
        }
        if (str_starts_with($address, '/') && !str_starts_with($address, '//')) {
            return '/' . $prefix . ltrim($address, '/');
        }
        return '';
    }
}
