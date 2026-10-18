<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The JSON-LD that describes a page to search engines: who the site is (an organization, a local business or a person,
 * as Admin > SEO > Identity says), the website and its search, the article or page itself, its breadcrumb, all as one
 * graph, and the script tag that carries it.
 */
final class StructuredData
{
    /**
     * @param callable(): array<string, mixed> $settings the site settings
     * @param callable(): array<string, mixed> $themeSettings the theme's settings (logo, social profiles, footer)
     * @param callable(string, ?string): string $translate a theme string
     * @param callable(string): string $absoluteUrl a path of the site as a full address
     * @param callable(string): string $langPrefix "" for the default language, "en/" for another
     */
    public function __construct(private $settings, private $themeSettings, private $translate, private $absoluteUrl, private $langPrefix)
    {
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ($this->settings)();
    }

    /** @return array<string, mixed> */
    private function themeSettings(): array
    {
        return ($this->themeSettings)();
    }

    /** @return array<string, mixed> */
    private function seo(): array
    {
        return SeoSettings::from($this->settings());
    }

    /** The node that says who the site is, shared by every frontend page. @return array<int, array<string, mixed>> */
    public function site(): array
    {
        $seo = $this->seo();
        $identity = $seo['identity'];
        $siteUrl = ($this->absoluteUrl)('');
        $person = $identity['type'] === 'Person';
        $organization = [
            '@type' => $identity['type'],
            '@id' => $siteUrl . '#organization',
            'name' => $identity['name'] !== '' ? $identity['name'] : (string)($this->settings()['title'] ?? 'GnozioCMS'),
            'url' => $siteUrl,
        ];
        if ($identity['alternate_name'] !== '') {
            $organization['alternateName'] = $identity['alternate_name'];
        }
        if ($identity['description'] !== '') {
            $organization['description'] = $identity['description'];
        }
        $logo = trim((string)($this->themeSettings()['brand']['logo'] ?? ''));
        if ($logo !== '') {
            $address = preg_match('#^https?://#i', $logo) ? $logo : ($this->absoluteUrl)($logo);
            // A person has a picture, an organization a logo.
            $organization[$person ? 'image' : 'logo'] = $address;
        }
        $social = is_array($this->themeSettings()['social'] ?? null) ? $this->themeSettings()['social'] : [];
        $sameAs = array_values(array_filter(array_map(static fn($url): string => trim((string)$url), $social), static fn(string $url): bool => $url !== ''));
        if ($sameAs !== []) {
            $organization['sameAs'] = $sameAs;
        }
        // The phone and email of the footer are how people reach the business.
        $phone = trim((string)($this->themeSettings()['footer']['phone'] ?? ''));
        $email = trim((string)($this->themeSettings()['footer']['email'] ?? ''));
        if ($identity['type'] === 'LocalBusiness') {
            $postal = array_filter([
                'streetAddress' => $identity['street'], 'addressLocality' => $identity['locality'],
                'addressRegion' => $identity['region'], 'postalCode' => $identity['postal'], 'addressCountry' => $identity['country'],
            ], static fn(string $value): bool => $value !== '');
            if ($postal !== []) {
                $organization['address'] = ['@type' => 'PostalAddress'] + $postal;
            }
            if ($identity['price_range'] !== '') {
                $organization['priceRange'] = $identity['price_range'];
            }
        }
        if ($person) {
            if ($email !== '') {
                $organization['email'] = $email;
            }
            if ($phone !== '') {
                $organization['telephone'] = $phone;
            }
        } elseif ($phone !== '' || $email !== '') {
            $contact = ['@type' => 'ContactPoint', 'contactType' => 'customer service'];
            if ($phone !== '') {
                $contact['telephone'] = $phone;
            }
            if ($email !== '') {
                $contact['email'] = $email;
            }
            $organization['contactPoint'] = $contact;
            if ($identity['type'] === 'LocalBusiness') {
                $organization += array_filter(['telephone' => $phone, 'email' => $email], static fn(string $value): bool => $value !== '');
            }
        }
        return [$organization];
    }

    /** @return array<int, array<string, mixed>> */
    public function forItem(ContentItem $item, string $lang, string $canonical, bool $isHome, string $blockImage = ''): array
    {
        $graph = $this->site();
        $siteSeo = $this->seo();
        $siteUrl = ($this->absoluteUrl)('');
        $organization = ['@id' => $siteUrl . '#organization'];
        $prefix = ($this->langPrefix)($lang);
        $homeUrl = ($this->absoluteUrl)($prefix);

        if ($isHome) {
            $webSite = [
                '@type' => 'WebSite',
                '@id' => $siteUrl . '#website',
                'url' => $homeUrl,
                'name' => (string)($this->settings()['title'] ?? 'GnozioCMS'),
                'inLanguage' => $lang,
                'publisher' => $organization,
            ];
            if ($siteSeo['schema']['search_box']) {
                $webSite['potentialAction'] = [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => ($this->absoluteUrl)($prefix . 'search') . '?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ];
            }
            $graph[] = $webSite;
            return $graph;
        }

        $title = (string)($item->meta['title'] ?? $item->slug);
        $seo = is_array($item->meta['seo'] ?? null) ? $item->meta['seo'] : [];
        $description = trim((string)($seo['description'] ?? '')) ?: trim((string)($item->meta['excerpt'] ?? ''));
        $absolute = fn(string $url): string => preg_match('#^https?://#i', $url) ? $url : ($this->absoluteUrl)($url);
        $website = ['@type' => 'WebSite', '@id' => $siteUrl . '#website', 'url' => $homeUrl, 'name' => (string)($this->settings()['title'] ?? 'GnozioCMS')];

        if ($item->type === 'posts' || $item->type === 'projects') {
            // A post is a blog posting, a project an article; both name the author, the dates, and a picture.
            $article = [
                '@type' => $item->type === 'posts' ? $siteSeo['schema']['article_type'] : 'Article',
                'headline' => mb_substr($title, 0, 110),
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
                'url' => $canonical,
                'inLanguage' => $lang,
                'isPartOf' => ['@id' => $siteUrl . '#website'],
                'publisher' => $organization,
            ];
            $author = trim((string)($item->meta['author'] ?? ''));
            $article['author'] = $author !== '' ? ['@type' => 'Person', 'name' => $author] : $organization;
            $published = $this->time($item->meta['date'] ?? null);
            if ($published !== null) {
                $article['datePublished'] = date('c', $published);
            }
            $article['dateModified'] = date('c', max($item->mtime, $published ?? 0));
            if ($description !== '') {
                $article['description'] = $description;
            }
            // The picture people see when the link is shared: the entry's own, the share image of its SEO settings,
            // the first picture in its blocks, then the site's default.
            foreach ([$item->meta['main_image'] ?? '', $seo['og_image'] ?? '', $blockImage, $siteSeo['share_image'], $this->themeSettings()['brand']['share_image'] ?? ''] as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    $article['image'] = $absolute($candidate);
                    break;
                }
            }
            $graph[] = $article;
        } else {
            $page = [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => $title,
                'inLanguage' => $lang,
                'isPartOf' => $website,
            ];
            if ($description !== '') {
                $page['description'] = $description;
            }
            $graph[] = $page;
            $place = $this->place($item, $title, $description, $canonical, $absolute);
            if ($place !== null) {
                $graph[] = $place;
            }
        }

        if (!$siteSeo['schema']['breadcrumbs']) {
            return $graph;
        }
        $crumbs = [[($this->translate)('nav.main.home', 'Home'), $homeUrl]];
        if ($item->type !== 'pages') {
            $crumbs[] = [($this->translate)('type.' . $item->type, ucfirst($item->type)), ($this->absoluteUrl)($prefix . $item->type)];
        }
        $crumbs[] = [$title, $canonical];
        $list = [];
        foreach ($crumbs as $position => [$name, $url]) {
            $list[] = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $name, 'item' => $url];
        }
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
        return $graph;
    }

    /**
     * A point of interest, a route or a business as a place search engines can put on a map: its name, position and, when
     * it has them, address, phone, email and picture. Null for an entry with no position.
     *
     * @param \Closure(string): string $absolute
     * @return array<string, mixed>|null
     */
    private function place(ContentItem $item, string $title, string $description, string $canonical, \Closure $absolute): ?array
    {
        $types = ['points' => 'TouristAttraction', 'routes' => 'TouristAttraction', 'businesses' => 'LocalBusiness'];
        $fields = is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
        $position = Geo::parse($fields['location'] ?? null);
        if (!isset($types[$item->type]) || $position === null) {
            return null;
        }
        $place = [
            '@type' => $types[$item->type],
            '@id' => $canonical . '#place',
            'name' => $title,
            'url' => $canonical,
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $position['lat'], 'longitude' => $position['lng']],
        ];
        if ($description !== '') {
            $place['description'] = $description;
        }
        $image = trim((string)($item->meta['main_image'] ?? ''));
        if ($image !== '') {
            $place['image'] = $absolute($image);
        }
        $address = array_filter(['@type' => 'PostalAddress', 'streetAddress' => trim((string)($fields['address'] ?? '')), 'addressLocality' => trim((string)($fields['area'] ?? ''))], static fn($v): bool => $v !== '');
        if (count($address) > 1) {
            $place['address'] = $address;
        }
        foreach (['phone' => 'telephone', 'email' => 'email'] as $field => $property) {
            if (is_string($fields[$field] ?? null) && trim($fields[$field]) !== '') {
                $place[$property] = trim($fields[$field]);
            }
        }
        if ($item->type === 'businesses' && isset($fields['price_range']) && is_string($fields['price_range'])) {
            $range = ['budget' => '€', 'moderate' => '€€', 'upscale' => '€€€'][$fields['price_range']] ?? '';
            if ($range !== '') {
                $place['priceRange'] = $range;
            }
        }
        return $place;
    }

    /** A date from front matter as a Unix time: a `2026-03-04` string, a timestamp the YAML reader made of it, or nothing. */
    private function time(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 9)) {
            return (int)$value;
        }
        $time = strtotime(trim((string)$value));
        return $time === false ? null : $time;
    }

    /** JSON-LD graph as a script tag; `<`, `>` and `&` are escaped so content cannot close the tag. */
    public function script(array $graph): string
    {
        if ($graph === []) {
            return '';
        }
        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => array_values($graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
    }
}
