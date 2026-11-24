<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * After a move: does every address of the old site still lead somewhere? The old site's addresses come from its sitemap (the
 * WordPress one, /wp-sitemap.xml, Yoast's /sitemap_index.xml or a plain /sitemap.xml, with the sitemaps they list) or from a list
 * (the importer's redirects.txt, or one address a line). Each is asked of the new site one step at a time: a page that is the same
 * here answers 200; one that moved answers 301 (or 308) and the address it points at answers 200. Anything else is reported: a
 * temporary redirect (302, 307), a chain of several, a loop, a redirect to another site, an address that leads nowhere, and,
 * when the list says where an address should lead, one that leads somewhere else.
 */
final class RedirectCheck
{
    public const MAX_HOPS = 5;
    private const MAX_SITEMAPS = 200;

    /** What each outcome means, in the order the report lists them. */
    public const OUTCOMES = [
        'moved' => 'moved: 301 to a page that answers 200',
        'same' => 'the same address here (200)',
        'chain' => 'more than one redirect before the page (works, but slower; point the first at the last)',
        'temporary' => 'a temporary redirect (302 or 307) where a permanent one (301) is wanted',
        'elsewhere' => 'redirected to another site',
        'wrong_target' => 'leads to a page, but not the one the list says',
        'missing' => 'leads nowhere (404 or 410)',
        'broken' => 'an error, a loop, or a page that does not answer 200',
    ];

    /** Outcomes that are not problems. */
    public const GOOD = ['moved', 'same'];

    /**
     * @param \Closure(string): (array{status: int, body: string}|null) $get reads an address (a sitemap)
     * @param \Closure(string): array{status: int, location: string, error: string} $step asks for an address once, following no redirect
     */
    public function __construct(private \Closure $get, private \Closure $step)
    {
    }

    /**
     * The addresses a sitemap lists, following the sitemaps an index lists. A site's own sitemap is found from its address.
     *
     * @return list<string> the page addresses, without repeats, in the order found
     */
    public function sitemapAddresses(string $site): array
    {
        $site = rtrim($site, '/');
        $start = preg_match('#\.xml(\?.*)?$#i', $site) ? [$site] : [$site . '/wp-sitemap.xml', $site . '/sitemap_index.xml', $site . '/sitemap.xml'];
        foreach ($start as $first) {
            $found = [];
            $queue = [$first];
            $seen = [];
            while ($queue !== [] && count($seen) < self::MAX_SITEMAPS) {
                $url = array_shift($queue);
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $answer = ($this->get)($url);
                if ($answer === null || $answer['status'] !== 200 || !str_contains($answer['body'], '<loc')) {
                    continue;
                }
                [$sitemaps, $pages] = self::locs($answer['body']);
                array_push($queue, ...$sitemaps);
                foreach ($pages as $page) {
                    $found[$page] = true;
                }
            }
            if ($found !== []) {
                return array_keys($found);
            }
        }
        return [];
    }

    /**
     * The lines of a list: "old new [code]" (the importer's redirects.txt, Admin > Redirects' form) or one old address a line.
     * Comments (#) and empty lines are left out.
     *
     * @return list<array{0: string, 1: string}> old address or path, and where it should lead ('' when the line does not say)
     */
    public static function listLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $out[] = [(string)$parts[0], (string)($parts[1] ?? '')];
        }
        return $out;
    }

    /**
     * Asks the new site for one old address and follows it step by step.
     *
     * @param string $old the old address (its path and query are asked of the new site) or a path
     * @param string $expected where it should lead, a path or an address ('' when anything that answers 200 will do)
     * @return array{old: string, asked: string, outcome: string, steps: list<string>, final: string, note: string}
     */
    public function check(string $newSite, string $old, string $expected = ''): array
    {
        $newSite = rtrim($newSite, '/');
        $newHost = self::host($newSite);
        $asked = $newSite . self::pathAndQuery($old);
        $url = $asked;
        $steps = [];
        $redirects = 0;
        $temporary = false;
        $visited = [];
        $result = static function (string $outcome, string $note = '') use ($old, $asked, &$steps, &$url): array {
            return ['old' => $old, 'asked' => $asked, 'outcome' => $outcome, 'steps' => $steps, 'final' => $url, 'note' => $note];
        };
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            if (isset($visited[$url])) {
                return $result('broken', 'a loop');
            }
            $visited[$url] = true;
            $answer = ($this->step)($url);
            if ($answer['error'] !== '') {
                $steps[] = 'error';
                return $result('broken', $answer['error']);
            }
            $status = $answer['status'];
            $steps[] = (string)$status;
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($answer['location'] === '') {
                    return $result('broken', 'a redirect without an address');
                }
                $redirects++;
                $temporary = $temporary || in_array($status, [302, 303, 307], true);
                $url = $answer['location'];
                if (self::host($url) !== $newHost) {
                    $steps[] = '→ ' . self::host($url);
                    return $result('elsewhere', $url);
                }
                continue;
            }
            if ($status === 404 || $status === 410) {
                return $result('missing');
            }
            if ($status !== 200) {
                return $result('broken', 'the page answered ' . $status);
            }
            if ($expected !== '' && $redirects > 0 && !self::samePath($url, $expected)) {
                return $result('wrong_target', 'expected ' . $expected);
            }
            if ($temporary) {
                return $result('temporary');
            }
            return $result($redirects === 0 ? 'same' : ($redirects === 1 ? 'moved' : 'chain'));
        }
        return $result('broken', 'more than ' . self::MAX_HOPS . ' redirects');
    }

    /**
     * Checks a list of old addresses, calling $each after each one (for a progress line, and a pause between requests).
     *
     * @param list<array{0: string, 1: string}> $lines old address and where it should lead
     * @param (\Closure(int, array<string, mixed>): void)|null $each
     * @return array{counts: array<string, int>, problems: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function run(string $newSite, array $lines, ?\Closure $each = null): array
    {
        $counts = array_fill_keys(array_keys(self::OUTCOMES), 0);
        $rows = [];
        $problems = [];
        foreach ($lines as $i => [$old, $expected]) {
            $row = $this->check($newSite, $old, $expected);
            $counts[$row['outcome']]++;
            $rows[] = $row;
            if (!in_array($row['outcome'], self::GOOD, true)) {
                $problems[] = $row;
            }
            if ($each !== null) {
                $each($i + 1, $row);
            }
        }
        return ['counts' => $counts, 'problems' => $problems, 'rows' => $rows];
    }

    /**
     * The rows as CSV: old address, outcome, the steps' statuses, where it ended, a note.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['old', 'outcome', 'steps', 'final', 'note'], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, [$row['old'], $row['outcome'], implode(' ', $row['steps']), $row['final'], $row['note']], ',', '"', '\\');
        }
        rewind($out);
        return (string)stream_get_contents($out);
    }

    /** @return array{0: list<string>, 1: list<string>} the sitemaps and the pages a sitemap lists */
    private static function locs(string $xml): array
    {
        $sitemaps = [];
        $pages = [];
        // Read as text: a sitemap may carry namespaces, a BOM or a stylesheet line that a strict parser would refuse.
        if (preg_match_all('#<(sitemap|url)\b[^>]*>.*?<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]]+?)\s*(?:\]\]>)?\s*</loc>#is', $xml, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $loc = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (!preg_match('#^https?://#i', $loc)) {
                    continue;
                }
                if (strtolower($match[1]) === 'sitemap') {
                    $sitemaps[] = $loc;
                } else {
                    $pages[] = $loc;
                }
            }
        }
        return [$sitemaps, $pages];
    }

    /** The path and query of an address, as the new site is asked for them (letters outside ASCII percent-encoded). */
    public static function pathAndQuery(string $address): string
    {
        $address = trim($address);
        if (preg_match('#^https?://[^/]+(.*)$#i', $address, $m)) {
            $address = $m[1];
        }
        $address = '/' . ltrim(strtok($address, '#') ?: '', '/');
        $address = preg_replace_callback('/[^\x21-\x7e]/u', static fn(array $m): string => rawurlencode($m[0]), $address) ?? $address;
        // %ce%b1 and %CE%B1 are one address; it is asked in the form a browser sends.
        return preg_replace_callback('/%[0-9a-f]{2}/i', static fn(array $m): string => strtoupper($m[0]), $address) ?? $address;
    }

    private static function host(string $url): string
    {
        return (string)preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
    }

    private static function samePath(string $url, string $expected): bool
    {
        $norm = static fn(string $a): string => rtrim(rawurldecode((string)parse_url(self::pathAndQuery($a), PHP_URL_PATH)), '/');
        return $norm($url) === $norm($expected);
    }
}
