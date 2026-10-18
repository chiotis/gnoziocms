<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What search engines will make of the site's published entries (Admin > SEO > Overview): which have no description or
 * a description that is cut short or too thin, titles that are cut, titles and descriptions that two entries share, no
 * picture to show when a link is shared, and which are kept out of search. Each finding names the entry and where to
 * edit it. Drafts, hidden entries and forms are not looked at: visitors and crawlers do not see them.
 */
final class SeoAudit
{
    /** The checks, in the order they are shown, with the way each is put to the person. */
    public const CHECKS = [
        'no_description' => ['Missing description', 'Search engines make up a snippet from the page; a description you wrote is usually better and gets more clicks.', 'warn'],
        'description_length' => ['Description too short or too long', 'Between 70 and 160 characters shows in full on most results.', 'warn'],
        'title_length' => ['Title too long', 'Longer than about 60 characters, the end is cut in search results.', 'warn'],
        'duplicate_title' => ['The same title as another page', 'Two pages with one title compete with each other in search.', 'warn'],
        'duplicate_description' => ['The same description as another page', 'Each page should say what is special about it.', 'warn'],
        'no_image' => ['No picture for a shared link', 'Links to these pages show no picture on social networks. Set a default one in Social.', 'info'],
        'noindex' => ['Kept out of search', 'Entries that ask search engines to skip them, one by one or through their content type.', 'info'],
    ];

    /** @param \Closure(): array<string, mixed> $settings */
    public function __construct(private ContentRepository $content, private \Closure $settings)
    {
    }

    /**
     * @return array{entries: int, indexed: int, checks: array<string, array{label: string, hint: string, level: string, count: int, items: array<int, array{title: string, type: string, lang: string, slug: string, detail: string}>}>}
     */
    public function run(int $show = 8): array
    {
        $settings = ($this->settings)();
        $seo = SeoSettings::from($settings);
        $site = (string)($settings['title'] ?? '');
        $tagline = (string)($settings['tagline'] ?? '');
        $home = (string)($settings['home_page'] ?? 'index');
        $default = (string)($settings['languages']['default'] ?? 'en');
        $languages = array_values(array_unique(array_map('strval', $settings['languages']['available'] ?? [$default])));

        $found = array_fill_keys(array_keys(self::CHECKS), []);
        $titles = [];
        $descriptions = [];
        $entries = 0;
        $indexed = 0;
        foreach ($this->content->getTypes() as $type) {
            if ($type === 'forms') {
                continue;
            }
            foreach ($languages as $lang) {
                foreach ($this->content->getItems($type, $lang, false, false) as $item) {
                    $entries++;
                    $row = ['title' => (string)($item->meta['title'] ?? $item->slug), 'type' => $type, 'lang' => $lang, 'slug' => $item->slug, 'detail' => ''];
                    if (!SeoSettings::indexable($seo, $type, $item->meta)) {
                        $found['noindex'][] = $row;
                        continue;
                    }
                    $indexed++;
                    $own = is_array($item->meta['seo'] ?? null) ? $item->meta['seo'] : [];
                    $isHome = $type === 'pages' && $item->slug === $home;
                    $title = SeoSettings::title($seo, (string)($own['title'] ?? ''), $row['title'], $site, $tagline, $isHome, $type);
                    $description = trim((string)($own['description'] ?? '')) ?: trim((string)($item->meta['excerpt'] ?? ''));
                    if ($description === '' && $isHome) {
                        $description = $seo['home_description'];
                    }
                    if ($description === '') {
                        $found['no_description'][] = $row;
                    } else {
                        $length = mb_strlen($description);
                        if ($length < SeoSettings::DESCRIPTION_MIN || $length > SeoSettings::DESCRIPTION_MAX) {
                            $found['description_length'][] = ['detail' => $length . ' characters'] + $row;
                        }
                        $descriptions[$lang . '|' . mb_strtolower($description)][] = $row;
                    }
                    if (mb_strlen($title) > SeoSettings::TITLE_RECOMMENDED) {
                        $found['title_length'][] = ['detail' => mb_strlen($title) . ' characters'] + $row;
                    }
                    $titles[$lang . '|' . mb_strtolower($title)][] = $row;
                    $hasImage = trim((string)($item->meta['main_image'] ?? '')) !== '' || trim((string)($own['og_image'] ?? '')) !== '' || $seo['share_image'] !== '';
                    if (!$hasImage && $type !== 'pages') {
                        $found['no_image'][] = $row;
                    }
                }
            }
        }
        foreach ([[$titles, 'duplicate_title'], [$descriptions, 'duplicate_description']] as [$groups, $check]) {
            foreach ($groups as $rows) {
                if (count($rows) > 1) {
                    foreach ($rows as $row) {
                        $found[$check][] = ['detail' => 'shared with ' . (count($rows) - 1) . ' more'] + $row;
                    }
                }
            }
        }
        $checks = [];
        foreach (self::CHECKS as $key => [$label, $hint, $level]) {
            $checks[$key] = ['label' => $label, 'hint' => $hint, 'level' => $level, 'count' => count($found[$key]), 'items' => array_slice($found[$key], 0, $show)];
        }
        return ['entries' => $entries, 'indexed' => $indexed, 'checks' => $checks];
    }
}
