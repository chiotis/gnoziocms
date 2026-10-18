<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Admin > SEO: one screen with a tab for each part of how the site meets search engines and social networks (the overview,
 * search appearance, social, crawling and the sitemap, identity, and the ownership codes). The class gives a tab what it
 * shows, saves what a tab sent (only that tab's settings change), and works out the overview: a few checks of the site
 * itself and of every published entry. The things that live in the running site are handed in as closures.
 */
final class SeoAdmin
{
    /**
     * @param \Closure(): array<string, mixed> $settings the site settings the site runs with
     * @param \Closure(): void $reload makes the site run with what is stored
     * @param \Closure(): array<string, mixed> $themeSettings the theme's settings (the logo and the share image of its Branding tab)
     * @param \Closure(): string[] $types the content types the site has
     * @param \Closure(): int $notFound how many addresses visitors asked for and did not find
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity
     */
    public function __construct(
        private SiteSettings $store,
        private SeoAudit $audit,
        private \Closure $settings,
        private \Closure $reload,
        private \Closure $themeSettings,
        private \Closure $types,
        private \Closure $notFound,
        private \Closure $log
    ) {
    }

    /** A tab name from the address or the form; the overview when it is not one. */
    public static function tab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        return in_array($tab, SeoSettings::TABS, true) ? $tab : 'overview';
    }

    /** Saves what a tab sent; where to go next is returned. */
    public function save(string $tab, array $post): string
    {
        $tab = self::tab($tab);
        if ($tab === 'overview') {
            return '/admin/seo';
        }
        $site = ($this->settings)();
        $before = is_array($site['seo'] ?? null) ? $site['seo'] : [];
        $seo = SeoSettings::apply($before, $tab, $post, ($this->types)());
        if (!$this->store->setSeo($seo)) {
            return '/admin/seo?tab=' . $tab . '&error=store';
        }
        ($this->reload)();
        ($this->log)('seo.update', 'info', 'settings', 'seo', 'SEO settings saved: ' . $tab . '.', ['tab' => $tab]);
        return '/admin/seo?tab=' . $tab . '&saved=1';
    }

    /**
     * What a tab shows.
     *
     * @return array<string, mixed>
     */
    public function screen(string $tab): array
    {
        $tab = self::tab($tab);
        $site = ($this->settings)();
        $seo = SeoSettings::from($site);
        $types = [];
        foreach (($this->types)() as $type) {
            if ($type !== 'forms') {
                $types[] = ['key' => $type, 'label' => ucfirst($type)] + SeoSettings::forType($seo, $type);
            }
        }
        $data = [
            'tab' => $tab,
            'seo' => $seo,
            'type_rows' => $types,
            'site_title' => (string)($site['title'] ?? ''),
            'site_tagline' => (string)($site['tagline'] ?? ''),
            'site_url' => rtrim((string)($site['base_url'] ?? ''), '/'),
            'separators' => SeoSettings::SEPARATORS,
            'variables' => SeoSettings::VARIABLES,
            'default_format' => SeoSettings::DEFAULT_FORMAT,
            'identity_types' => SeoSettings::IDENTITY_TYPES,
            'article_types' => SeoSettings::ARTICLE_TYPES,
            'twitter_cards' => SeoSettings::TWITTER_CARDS,
            'verification' => SeoSettings::VERIFICATION,
            'ai_crawlers' => SeoSettings::AI_CRAWLERS,
            'brand_share_image' => (string)(($this->themeSettings)()['brand']['share_image'] ?? ''),
            'robots_text' => implode("\n", $seo['robots_disallow']),
            'sitemap_exclude_text' => implode("\n", $seo['sitemap']['exclude']),
            'limits' => ['title' => SeoSettings::TITLE_RECOMMENDED, 'description_min' => SeoSettings::DESCRIPTION_MIN, 'description_max' => SeoSettings::DESCRIPTION_MAX],
        ];
        if ($tab === 'overview') {
            $data['audit'] = $this->audit->run();
            $data['health'] = $this->health($site, $seo);
        }
        return $data;
    }

    /**
     * The checks of the site as a whole.
     *
     * @param array<string, mixed> $site
     * @param array<string, mixed> $seo
     * @return array<int, array{level: string, label: string, note: string, tab: string, href: string}>
     */
    private function health(array $site, array $seo): array
    {
        $rows = [];
        $add = static function (string $level, string $label, string $note, string $tab = '', string $href = '') use (&$rows): void {
            $rows[] = ['level' => $level, 'label' => $label, 'note' => $note, 'tab' => $tab, 'href' => $href];
        };
        if ($seo['discourage']) {
            $add('bad', 'Search engines are asked to stay out', 'The whole site is hidden from search, and has no sitemap. Turn this off when the site is ready.', 'crawling');
        } else {
            $add('ok', 'Open to search engines', 'Pages can be found in search.', 'crawling');
        }
        $base = trim((string)($site['base_url'] ?? ''));
        if ($base === '') {
            $add('warn', 'The site address is not set', 'Without it, canonical links, the sitemap and shared links use the address the visitor came with. Set it in Settings.', '', '/admin/settings');
        } elseif (!str_starts_with($base, 'https://')) {
            $add('warn', 'The site address is not https', 'Search engines prefer secure sites. Move the site to https and update the address in Settings.', '', '/admin/settings');
        } else {
            $add('ok', 'The site address is set, and secure', $base, '', '/admin/settings');
        }
        $tagline = trim((string)($site['tagline'] ?? ''));
        if ($tagline === '' || $tagline === (string)$this->store->defaults()['tagline']) {
            $add('warn', 'The tagline is still the default', 'It is the description of pages that have none. Write one in Settings, or give pages and the home page their own in Search appearance.', 'search');
        } else {
            $add('ok', 'The tagline is written', $tagline, 'search');
        }
        if ($seo['sitemap']['enabled'] && !$seo['discourage']) {
            $add('ok', 'The sitemap is on', 'Search engines read it at /sitemap.xml. Submit it in Search Console and Bing Webmaster Tools.', 'crawling', '/sitemap.xml');
        } elseif (!$seo['discourage']) {
            $add('warn', 'The sitemap is off', 'Search engines find pages more slowly without one.', 'crawling');
        }
        $image = $seo['share_image'] !== '' ? $seo['share_image'] : (string)(($this->themeSettings)()['brand']['share_image'] ?? '');
        $add($image !== '' ? 'ok' : 'warn', $image !== '' ? 'A default share image is set' : 'No default share image', $image !== '' ? 'Links to pages without a picture of their own show it.' : 'Links to pages without a picture show no picture on social networks.', 'social');
        $codes = array_filter($seo['verification']);
        $add($codes !== [] ? 'ok' : 'info', $codes !== [] ? 'The site is verified with ' . count($codes) . ' ' . (count($codes) === 1 ? 'service' : 'services') : 'The site is not verified with a search engine', $codes !== [] ? 'The ownership codes are in every page.' : 'Verify it in Google Search Console and Bing Webmaster Tools to see how people find the site.', 'verification');
        $missing = ($this->notFound)();
        if ($missing > 0) {
            $add('info', $missing . ($missing === 1 ? ' address was not found' : ' addresses were not found'), 'Visitors or crawlers asked for them. Add a redirect to the right page.', '', '/admin/redirects?tab=missing');
        }
        return $rows;
    }
}
