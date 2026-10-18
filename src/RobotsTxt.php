<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The site's robots.txt: everything is open to crawlers, minus the paths the site owner listed (Admin > SEO > Crawling),
 * and the address of the sitemap. Rules are checked when they are read and again when they are
 * written out, so nothing typed in the admin can add a line of its own to the file.
 */
final class RobotsTxt
{
    public const MAX_RULES = 100;
    public const MAX_LENGTH = 200;

    /**
     * Turns what was typed (one path per line, or a list) into clean `Disallow` paths: each starts with `/`, may use
     * the `*` and `$` that crawlers understand, and appears once. A line typed as `Disallow: /private/` is accepted.
     * Anything else (a comment, a full address, spaces) is dropped.
     *
     * @param string|array<int, mixed> $raw
     * @return list<string>
     */
    public static function rules(string|array $raw): array
    {
        $lines = is_array($raw) ? array_map('strval', $raw) : preg_split('/\R/', $raw);
        $rules = [];
        foreach ($lines ?: [] as $line) {
            $line = trim((string)preg_replace('/^\s*disallow\s*:\s*/i', '', trim($line)));
            if ($line === '' || strlen($line) > self::MAX_LENGTH || !preg_match('#^/[A-Za-z0-9\-._~!$&\'()*+,;=:@%/?]*$#', $line)) {
                continue;
            }
            $rules[$line] = true;
            if (count($rules) >= self::MAX_RULES) {
                break;
            }
        }
        return array_keys($rules);
    }

    /**
     * The file: everything is open to crawlers minus the listed paths, then the sitemap. A site that asked to be left out of
     * search closes everything; the crawlers of AI products can be closed out of a site that stays open.
     *
     * @param string|array<int, mixed> $disallow
     * @param string $sitemapUrl the address of the sitemap, or '' when the site has none (it is not listed then)
     * @param string[] $blocked crawlers (user agents) closed out of the whole site
     */
    public static function render(string $sitemapUrl, string|array $disallow = [], bool $closed = false, array $blocked = []): string
    {
        $out = '';
        if (!$closed) {
            foreach ($blocked as $agent) {
                if (preg_match('/^[A-Za-z0-9._-]{2,40}$/', $agent) === 1) {
                    $out .= 'User-agent: ' . $agent . "\nDisallow: /\n\n";
                }
            }
        }
        $out .= "User-agent: *\n" . ($closed ? "Disallow: /\n" : "Allow: /\n");
        if (!$closed) {
            foreach (self::rules($disallow) as $rule) {
                $out .= 'Disallow: ' . $rule . "\n";
            }
        }
        if ($sitemapUrl !== '' && !$closed) {
            $out .= 'Sitemap: ' . str_replace(["\r", "\n"], '', $sitemapUrl) . "\n";
        }
        return $out;
    }
}
