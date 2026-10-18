<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The site-wide search engine settings (Admin > SEO), kept under `seo` in the site settings: how titles are put
 * together, the share image and the cards of social networks, what crawlers may read, the sitemap, who the site is for
 * structured data, and the codes that prove ownership. The class reads them (every value has a default, so a site that
 * never opened the screen works as it always did), cleans what a form sent for one tab, and builds the pieces a page
 * needs: its title, description, robots directive and the meta tags of the head. Nothing here touches a file.
 */
final class SeoSettings
{
    public const TABS = ['overview', 'search', 'social', 'crawling', 'identity', 'verification'];

    /** What may stand between the title of a page and the name of the site. */
    public const SEPARATORS = ['|', '-', '–', '—', '·', '•', '»', '/'];

    /** The words a title format may hold, each replaced by the page's value. */
    public const VARIABLES = ['title' => 'Page title', 'site' => 'Site name', 'tagline' => 'Tagline', 'sep' => 'Separator'];

    public const DEFAULT_FORMAT = '{title} {sep} {site}';

    /** Crawlers that collect text to train or feed AI products: blocked together by one switch. */
    public const AI_CRAWLERS = [
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'CCBot',
        'PerplexityBot', 'Applebot-Extended', 'Bytespider', 'cohere-ai', 'Meta-ExternalAgent', 'Amazonbot',
    ];

    public const IDENTITY_TYPES = ['Organization' => 'Organization', 'LocalBusiness' => 'Local business', 'Person' => 'Person'];
    public const ARTICLE_TYPES = ['BlogPosting' => 'Blog posting', 'Article' => 'Article', 'NewsArticle' => 'News article'];
    public const TWITTER_CARDS = ['auto' => 'Large picture when there is one', 'summary_large_image' => 'Always a large picture', 'summary' => 'Always small'];

    /** Where a site proves it owns the address, with the name of the meta tag each service reads. */
    public const VERIFICATION = [
        'google' => ['Google Search Console', 'google-site-verification'],
        'bing' => ['Bing Webmaster Tools', 'msvalidate.01'],
        'yandex' => ['Yandex Webmaster', 'yandex-verification'],
        'pinterest' => ['Pinterest', 'p:domain_verify'],
        'baidu' => ['Baidu Search Resource', 'baidu-site-verification'],
        'facebook' => ['Facebook domain', 'facebook-domain-verification'],
    ];

    public const TITLE_RECOMMENDED = 60;
    public const DESCRIPTION_MIN = 70;
    public const DESCRIPTION_MAX = 160;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'robots_disallow' => [],
            'separator' => '|',
            'title_format' => '',
            'home_title' => '',
            'home_description' => '',
            'default_description' => '',
            'types' => [],
            'noindex_search' => true,
            'noindex_taxonomies' => false,
            'share_image' => '',
            'twitter_card' => 'auto',
            'twitter_site' => '',
            'facebook_app_id' => '',
            'discourage' => false,
            'block_ai' => false,
            'snippets' => true,
            'sitemap' => ['enabled' => true, 'images' => true, 'taxonomies' => true, 'exclude' => []],
            'feed' => ['enabled' => true, 'items' => 20],
            'identity' => ['type' => 'Organization', 'name' => '', 'alternate_name' => '', 'description' => '', 'street' => '', 'locality' => '', 'region' => '', 'postal' => '', 'country' => '', 'price_range' => ''],
            'schema' => ['article_type' => 'BlogPosting', 'breadcrumbs' => true, 'search_box' => true],
            'verification' => array_fill_keys(array_keys(self::VERIFICATION), ''),
        ];
    }

    /**
     * The settings as the site has them: what was stored over the defaults, each value checked, so a hand-edited or
     * old file never gives a page something it cannot use.
     *
     * @param array<string, mixed> $site the site settings
     * @return array<string, mixed>
     */
    public static function from(array $site): array
    {
        $stored = is_array($site['seo'] ?? null) ? $site['seo'] : [];
        $d = self::defaults();
        $out = $d;
        $out['robots_disallow'] = RobotsTxt::rules(is_array($stored['robots_disallow'] ?? null) ? $stored['robots_disallow'] : []);
        $out['separator'] = in_array(self::one($stored['separator'] ?? ''), self::SEPARATORS, true) ? self::one($stored['separator']) : $d['separator'];
        $out['title_format'] = self::format($stored['title_format'] ?? '');
        foreach (['home_title' => 120, 'home_description' => 320, 'default_description' => 320] as $key => $max) {
            $out[$key] = self::text($stored[$key] ?? '', $max);
        }
        $types = [];
        foreach (is_array($stored['types'] ?? null) ? $stored['types'] : [] as $type => $row) {
            if (is_array($row) && preg_match('/^[a-z][a-z0-9_-]*$/', (string)$type)) {
                $types[(string)$type] = [
                    'title_format' => self::format($row['title_format'] ?? ''),
                    'noindex' => Format::isTruthy($row['noindex'] ?? false),
                    'sitemap' => !array_key_exists('sitemap', $row) || Format::isTruthy($row['sitemap']),
                ];
            }
        }
        $out['types'] = $types;
        foreach (['noindex_search', 'noindex_taxonomies', 'discourage', 'block_ai', 'snippets'] as $key) {
            $out[$key] = array_key_exists($key, $stored) ? Format::isTruthy($stored[$key]) : $d[$key];
        }
        $out['share_image'] = self::address($stored['share_image'] ?? '');
        $out['twitter_card'] = array_key_exists(self::one($stored['twitter_card'] ?? ''), self::TWITTER_CARDS) ? self::one($stored['twitter_card']) : 'auto';
        $out['twitter_site'] = self::handle($stored['twitter_site'] ?? '');
        $out['facebook_app_id'] = preg_match('/^\d{1,24}$/', self::one($stored['facebook_app_id'] ?? '')) === 1 ? self::one($stored['facebook_app_id']) : '';

        $map = is_array($stored['sitemap'] ?? null) ? $stored['sitemap'] : [];
        foreach (['enabled', 'images', 'taxonomies'] as $key) {
            $out['sitemap'][$key] = array_key_exists($key, $map) ? Format::isTruthy($map[$key]) : true;
        }
        $out['sitemap']['exclude'] = RobotsTxt::rules(is_array($map['exclude'] ?? null) ? $map['exclude'] : []);

        $feed = is_array($stored['feed'] ?? null) ? $stored['feed'] : [];
        $out['feed']['enabled'] = array_key_exists('enabled', $feed) ? Format::isTruthy($feed['enabled']) : true;
        $out['feed']['items'] = self::feedItems($feed['items'] ?? null);

        $identity = is_array($stored['identity'] ?? null) ? $stored['identity'] : [];
        $out['identity']['type'] = array_key_exists(self::one($identity['type'] ?? ''), self::IDENTITY_TYPES) ? self::one($identity['type']) : 'Organization';
        foreach (['name' => 120, 'alternate_name' => 120, 'description' => 320, 'street' => 160, 'locality' => 80, 'region' => 80, 'postal' => 20, 'price_range' => 20] as $key => $max) {
            $out['identity'][$key] = self::text($identity[$key] ?? '', $max);
        }
        $out['identity']['country'] = preg_match('/^[A-Za-z]{2}$/', self::one($identity['country'] ?? '')) === 1 ? strtoupper(self::one($identity['country'])) : '';

        $schema = is_array($stored['schema'] ?? null) ? $stored['schema'] : [];
        $out['schema']['article_type'] = array_key_exists(self::one($schema['article_type'] ?? ''), self::ARTICLE_TYPES) ? self::one($schema['article_type']) : 'BlogPosting';
        foreach (['breadcrumbs', 'search_box'] as $key) {
            $out['schema'][$key] = array_key_exists($key, $schema) ? Format::isTruthy($schema[$key]) : true;
        }
        $codes = is_array($stored['verification'] ?? null) ? $stored['verification'] : [];
        foreach (array_keys(self::VERIFICATION) as $key) {
            $out['verification'][$key] = self::code($codes[$key] ?? '');
        }
        return $out;
    }

    /**
     * The stored `seo` of the site after one tab of the form was saved: only that tab's settings change, and what another
     * tab keeps stays as it was. A box that is not ticked is not sent, so for these tabs every box that is missing is off.
     *
     * @param array<string, mixed> $stored what is stored now
     * @param array<string, mixed> $post what the tab sent
     * @param string[] $types the content types the site has (a row of the search tab for each)
     * @return array<string, mixed>
     */
    public static function apply(array $stored, string $tab, array $post, array $types): array
    {
        $seo = $stored;
        $on = static fn(mixed $value): bool => $value !== null && (string)$value !== '' && (string)$value !== '0';
        $line = static fn(string $key): string => trim((string)($post[$key] ?? ''));
        if ($tab === 'search') {
            $seo['separator'] = in_array($line('separator'), self::SEPARATORS, true) ? $line('separator') : '|';
            $seo['title_format'] = self::format($line('title_format'));
            $seo['home_title'] = self::text($line('home_title'), 120);
            $seo['home_description'] = self::text($line('home_description'), 320);
            $seo['default_description'] = self::text($line('default_description'), 320);
            $seo['noindex_search'] = $on($post['noindex_search'] ?? null);
            $seo['noindex_taxonomies'] = $on($post['noindex_taxonomies'] ?? null);
            $rows = is_array($post['types'] ?? null) ? $post['types'] : [];
            $kept = [];
            foreach ($types as $type) {
                $row = is_array($rows[$type] ?? null) ? $rows[$type] : [];
                $entry = ['title_format' => self::format($row['title_format'] ?? ''), 'noindex' => $on($row['noindex'] ?? null), 'sitemap' => $on($row['sitemap'] ?? null)];
                // Only what differs from the way every type is treated is kept.
                if ($entry['title_format'] !== '' || $entry['noindex'] || !$entry['sitemap']) {
                    $kept[$type] = $entry;
                }
            }
            $seo['types'] = $kept;
        } elseif ($tab === 'social') {
            $seo['share_image'] = self::address($line('share_image'));
            $seo['twitter_card'] = array_key_exists($line('twitter_card'), self::TWITTER_CARDS) ? $line('twitter_card') : 'auto';
            $seo['twitter_site'] = self::handle($line('twitter_site'));
            $seo['facebook_app_id'] = preg_match('/^\d{1,24}$/', $line('facebook_app_id')) ? $line('facebook_app_id') : '';
        } elseif ($tab === 'crawling') {
            $seo['discourage'] = $on($post['discourage'] ?? null);
            $seo['block_ai'] = $on($post['block_ai'] ?? null);
            $seo['snippets'] = $on($post['snippets'] ?? null);
            $seo['robots_disallow'] = RobotsTxt::rules((string)($post['robots_disallow'] ?? ''));
            $seo['sitemap'] = [
                'enabled' => $on($post['sitemap_enabled'] ?? null),
                'images' => $on($post['sitemap_images'] ?? null),
                'taxonomies' => $on($post['sitemap_taxonomies'] ?? null),
                'exclude' => RobotsTxt::rules((string)($post['sitemap_exclude'] ?? '')),
            ];
            $seo['feed'] = ['enabled' => $on($post['feed_enabled'] ?? null), 'items' => self::feedItems($post['feed_items'] ?? null)];
        } elseif ($tab === 'identity') {
            $identity = [];
            $identity['type'] = array_key_exists($line('identity_type'), self::IDENTITY_TYPES) ? $line('identity_type') : 'Organization';
            foreach (['name' => 120, 'alternate_name' => 120, 'description' => 320, 'street' => 160, 'locality' => 80, 'region' => 80, 'postal' => 20, 'price_range' => 20] as $key => $max) {
                $identity[$key] = self::text($post['identity_' . $key] ?? '', $max);
            }
            $identity['country'] = preg_match('/^[A-Za-z]{2}$/', $line('identity_country')) ? strtoupper($line('identity_country')) : '';
            $seo['identity'] = $identity;
            $seo['schema'] = [
                'article_type' => array_key_exists($line('article_type'), self::ARTICLE_TYPES) ? $line('article_type') : 'BlogPosting',
                'breadcrumbs' => $on($post['breadcrumbs'] ?? null),
                'search_box' => $on($post['search_box'] ?? null),
            ];
        } elseif ($tab === 'verification') {
            $codes = [];
            foreach (array_keys(self::VERIFICATION) as $key) {
                $codes[$key] = self::code($post['verify_' . $key] ?? '');
            }
            $seo['verification'] = $codes;
        }
        // The robots rules are stored as a list; none means the key is left out.
        if (($seo['robots_disallow'] ?? []) === []) {
            unset($seo['robots_disallow']);
        }
        return $seo;
    }

    /** How many entries a feed lists: a whole number from 5 to 100, 20 when nothing usable was given. */
    private static function feedItems(mixed $value): int
    {
        return is_numeric($value) ? max(5, min(100, (int)$value)) : 20;
    }

    // ---- what a page needs

    /** @return array{title_format: string, noindex: bool, sitemap: bool} */
    public static function forType(array $seo, string $type): array
    {
        return $seo['types'][$type] ?? ['title_format' => '', 'noindex' => false, 'sitemap' => true];
    }

    /**
     * The title of a page: the one its author wrote for search engines, as it is; the home page's own; or the page's title
     * set in the format of its type (or the site's). A word the format has no value for is dropped with the separator beside it.
     */
    public static function title(array $seo, string $own, string $page, string $site, string $tagline, bool $home, string $type): string
    {
        $own = trim($own);
        if ($own !== '') {
            return $own;
        }
        if ($home) {
            return $seo['home_title'] !== '' ? $seo['home_title'] : $site;
        }
        $page = trim($page);
        if ($page === '') {
            return $site;
        }
        $format = self::forType($seo, $type)['title_format'] ?: ($seo['title_format'] ?: self::DEFAULT_FORMAT);
        $sep = (string)$seo['separator'];
        $title = strtr($format, ['{title}' => $page, '{site}' => $site, '{tagline}' => $tagline, '{sep}' => $sep]);
        $quoted = preg_quote($sep, '/');
        $title = (string)preg_replace('/(?:\s*' . $quoted . '\s*){2,}/u', ' ' . $sep . ' ', $title);
        $title = trim((string)preg_replace('/^\s*' . $quoted . '\s*|\s*' . $quoted . '\s*$/u', '', trim($title)));
        return $title !== '' ? $title : $page;
    }

    /** The description of a page: its own, the home page's, its excerpt, what the template gives, the site's default, the tagline. */
    public static function description(array $seo, string $own, string $excerpt, string $document, string $tagline, bool $home): string
    {
        foreach ([$own, $home ? $seo['home_description'] : '', $excerpt, $document, $seo['default_description'], $tagline] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * What the robots meta tag says ('' for nothing): the whole site is asked to stay out of search when it is discouraged;
     * a page, a kind of page (the search results, the term pages, a content type) can be left out; and a page that stays in
     * may say its picture and text can be shown at full size.
     *
     * @param string $kind 'search', 'taxonomy', or the content type of the page
     */
    public static function robots(array $seo, bool $pageNoindex, string $kind): string
    {
        if ($seo['discourage']) {
            return 'noindex, nofollow';
        }
        $out = $pageNoindex
            || ($kind === 'search' && $seo['noindex_search'])
            || ($kind === 'taxonomy' && $seo['noindex_taxonomies'])
            || ($kind !== '' && self::forType($seo, $kind)['noindex']);
        if ($out) {
            return 'noindex, follow';
        }
        return $seo['snippets'] ? 'max-image-preview:large, max-snippet:-1, max-video-preview:-1' : '';
    }

    /** The card of a shared link: the choice, or a large picture when the page has one. */
    public static function twitterCard(array $seo, bool $hasImage): string
    {
        return $seo['twitter_card'] === 'auto' ? ($hasImage ? 'summary_large_image' : 'summary') : (string)$seo['twitter_card'];
    }

    /** The meta tags that are the same on every page: the ownership codes, the Facebook app, the account that publishes. */
    public static function head(array $seo): string
    {
        $out = '';
        foreach (self::VERIFICATION as $key => [, $name]) {
            if ($seo['verification'][$key] !== '') {
                $out .= '<meta name="' . $name . '" content="' . htmlspecialchars($seo['verification'][$key], ENT_QUOTES) . '">' . "\n  ";
            }
        }
        if ($seo['facebook_app_id'] !== '') {
            $out .= '<meta property="fb:app_id" content="' . htmlspecialchars($seo['facebook_app_id'], ENT_QUOTES) . '">' . "\n  ";
        }
        if ($seo['twitter_site'] !== '') {
            $out .= '<meta name="twitter:site" content="@' . htmlspecialchars($seo['twitter_site'], ENT_QUOTES) . '">' . "\n  ";
        }
        return rtrim($out);
    }

    /** Whether a path is one the sitemap is told to leave out: each rule matches the start of the address, `*` and a closing `$` as in robots.txt. */
    public static function excluded(array $seo, string $path): bool
    {
        return self::matches($seo['sitemap']['exclude'], $path);
    }

    /**
     * Whether an address starts like one of the rules (paths that begin with a slash, `*` for any text, a closing `$` for the end).
     *
     * @param string[] $rules
     */
    public static function matches(array $rules, string $path): bool
    {
        $path = '/' . ltrim((string)(parse_url($path, PHP_URL_PATH) ?? $path), '/');
        foreach ($rules as $rule) {
            $pattern = str_replace('\*', '.*', preg_quote(rtrim($rule, '$'), '#'));
            if (preg_match('#^' . $pattern . (str_ends_with($rule, '$') ? '$' : '') . '#', $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Whether the entry is in the sitemap and open to search engines: its type, and its own "do not index", allow it. */
    public static function indexable(array $seo, string $type, array $meta): bool
    {
        $row = self::forType($seo, $type);
        return !$seo['discourage'] && $row['sitemap'] && !$row['noindex'] && !Format::isTruthy($meta['seo']['noindex'] ?? false);
    }

    // ---- cleaning

    /** A value as text, or nothing when it is not one (a list, in a file edited by hand). */
    private static function one(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /** One line of text: no tags, no control characters, one space between words, cut to the length. */
    public static function text(mixed $value, int $max): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags(is_scalar($value) ? (string)$value : ''))));
        return mb_substr($text, 0, $max);
    }

    /** A title format: text up to 100 characters (its words stay as typed, so a word that is not known shows as it is). */
    private static function format(mixed $value): string
    {
        return self::text($value, 100);
    }

    /** A picture: an address on the site starting with a slash, or a web address, with nothing that could leave an attribute. */
    private static function address(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string)$value : '');
        return strlen($value) <= 300 && preg_match('#^(/(?!/)|https?://)[^\s"\'<>`\\\\]+$#i', $value) === 1 ? $value : '';
    }

    /** The name of an account on a social network, without the @. */
    private static function handle(mixed $value): string
    {
        $value = ltrim(trim(is_scalar($value) ? (string)$value : ''), '@');
        return preg_match('/^[A-Za-z0-9_]{1,15}$/', $value) === 1 ? $value : '';
    }

    /** An ownership code: the code itself, or the whole meta tag a service gave (its content is taken). */
    private static function code(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string)$value : '');
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $value, $m) === 1) {
            $value = trim($m[1]);
        }
        return preg_match('#^[A-Za-z0-9_\-:.=+/]{6,128}$#', $value) === 1 ? $value : '';
    }
}
