<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Finds the links inside content that point at an address of this site, and rewrites them.
 *
 * Content is read as text, so a link is found wherever it is written: in the body ([text](/old-page)), in HTML
 * (href="/old-page"), and in the front matter (a button in a block, a canonical address). A link is a path that starts
 * with a slash, or a full address on one of this site's hosts, and ends at the end of the path (a query string or an
 * anchor after it is kept). Menus and theme settings are not content files and are not looked at here.
 */
final class LinkScanner
{
    /** Above this many files a scan is not offered on screens that would run it on every visit. */
    public const MAX_FILES_FOR_LISTINGS = 3000;

    /** Folders of content/ that hold something other than pages of the site. */
    private const NOT_CONTENT = ['users', 'menus', 'taxonomies', 'media', 'uploads'];

    /**
     * @param string[] $languages every language of the site
     * @param string[] $hosts host names the site answers to, so a link with the full address is found too
     */
    public function __construct(private string $contentDir, private array $languages, private string $defaultLang, private array $hosts = [])
    {
        $this->hosts = array_values(array_unique(array_filter(array_map('strtolower', array_map('strval', $hosts)))));
    }

    /** @return array<int, array{type: string, slug: string, lang: string, path: string}> */
    public function files(): array
    {
        $found = [];
        foreach (glob($this->contentDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $type = basename($dir);
            if (in_array($type, self::NOT_CONTENT, true)) {
                continue;
            }
            $files = glob($dir . '/*.md') ?: [];
            foreach ($files as $path) {
                $name = basename($path, '.md');
                $lang = $this->defaultLang;
                if (preg_match('/^(.+)\.([a-z]{2,3}(?:-[a-z0-9]+)?)$/i', $name, $m) && in_array(strtolower($m[2]), $this->languages, true)) {
                    [$name, $lang] = [$m[1], strtolower($m[2])];
                }
                $found[] = ['type' => $type, 'slug' => $name, 'lang' => $lang, 'path' => $path];
            }
        }
        return $found;
    }

    /**
     * How many links each address has, and where. Nothing is listed for an address without links.
     *
     * @param string[] $sources normalised paths (lower case, no slashes at the ends)
     * @return array<int, array{type: string, slug: string, lang: string, path: string, count: int, by_source: array<string, int>}>
     */
    public function find(array $sources): array
    {
        $regex = $this->regex($sources);
        $out = [];
        if ($regex === null) {
            return $out;
        }
        foreach ($this->files() as $file) {
            $counts = $this->countIn((string)@file_get_contents($file['path']), $regex[0], $regex[1]);
            if ($counts !== []) {
                $out[] = $file + ['count' => array_sum($counts), 'by_source' => $counts];
            }
        }
        return $out;
    }

    /**
     * Links per address across all content, or null when there is too much content to scan on every visit.
     *
     * @param string[] $sources
     * @return array<string, int>|null
     */
    public function countBySource(array $sources): ?array
    {
        $files = $this->files();
        if (count($files) > self::MAX_FILES_FOR_LISTINGS) {
            return null;
        }
        $total = [];
        foreach ($this->find($sources) as $found) {
            foreach ($found['by_source'] as $source => $n) {
                $total[$source] = ($total[$source] ?? 0) + $n;
            }
        }
        return $total;
    }

    /**
     * The text with every link to one of the addresses pointed at its replacement.
     *
     * @param array<string, string> $map normalised path => where it goes now: "/path" on this site, or a full address
     * @return array{0: string, 1: int} the new text and how many links changed
     */
    public function rewrite(string $raw, array $map): array
    {
        $regex = $this->regex(array_keys($map));
        if ($regex === null) {
            return [$raw, 0];
        }
        $changed = 0;
        $out = preg_replace_callback($regex[0], function (array $m) use ($regex, $map, &$changed): string {
            $source = $regex[1][strtolower($m['p'])] ?? null;
            $to = $source !== null ? ($map[$source] ?? null) : null;
            if ($to === null) {
                return $m[0];
            }
            $changed++;
            // A full address keeps its host when the new place is on this site.
            return RedirectRepository::isExternal($to) ? $to : $m['abs'] . $to;
        }, $raw);
        return $out === null ? [$raw, 0] : [$out, $changed];
    }

    /**
     * @param string[] $sources
     * @return array{0: string, 1: array<string, string>}|null the pattern, and which source each spelling belongs to
     */
    private function regex(array $sources): ?array
    {
        $spellings = [];
        foreach ($sources as $source) {
            $source = trim((string)$source, '/');
            if ($source === '') {
                continue;
            }
            // A link may spell the address as it is or percent-encoded (Greek letters, spaces).
            $encoded = implode('/', array_map('rawurlencode', explode('/', $source)));
            foreach (array_unique([$source, $encoded]) as $form) {
                $spellings[strtolower($form)] = $source;
            }
        }
        if ($spellings === []) {
            return null;
        }
        $keys = array_keys($spellings);
        usort($keys, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $paths = implode('|', array_map(static fn(string $k): string => preg_quote($k, '~'), $keys));
        $hosts = $this->hosts !== [] ? '(?:https?://(?:' . implode('|', array_map(static fn(string $h): string => preg_quote($h, '~'), $this->hosts)) . ')(?::\d+)?)?' : '';
        // Not part of a longer path or address before it, and a real end of the path after it.
        $pattern = '~(?<![\w/.:%@=-])(?<abs>' . $hosts . ')/(?<p>' . $paths . ')(?=/?(?:["\'()\s#?<>\]\\\\]|$))~i';
        return [$pattern, $spellings];
    }

    /**
     * @param array<string, string> $spellings
     * @return array<string, int>
     */
    private function countIn(string $raw, string $pattern, array $spellings): array
    {
        $counts = [];
        if (preg_match_all($pattern, $raw, $all, PREG_SET_ORDER) === false) {
            return $counts;
        }
        foreach ($all as $m) {
            $source = $spellings[strtolower($m['p'])] ?? null;
            if ($source !== null) {
                $counts[$source] = ($counts[$source] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
