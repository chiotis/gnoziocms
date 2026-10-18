<?php

declare(strict_types=1);

namespace GnozioCMS;

use Twig\Environment;
use Twig\TwigFunction;

/**
 * The template functions that put the site's own choices into a page's head and tail: the tracking of Admin > Analytics, the
 * search engine settings of Admin > SEO, and what Theme > Branding adds. The settings are asked for each time a function runs
 * (they can change while the request is handled), and none of these functions looks at the request itself.
 */
final class PageTwigFunctions
{
    /**
     * @param \Closure(): array<string, mixed> $settings the site's settings, as they are now
     * @param \Closure(): array<string, mixed> $themeSettings the theme's settings, as they are now
     * @param \Closure(): bool $signedIn whether someone is signed in to the admin (the owner's tracking is not run for them)
     */
    public static function register(Environment $twig, \Closure $settings, \Closure $themeSettings, \Closure $signedIn, string $baseUrl): void
    {
        $add = static function (string $name, callable $function, bool $html = false) use ($twig): void {
            $twig->addFunction(new TwigFunction($name, $function, $html ? ['is_safe' => ['html']] : []));
        };

        // What Theme > Branding adds to a page: a style sheet of the choices made there, and the icons and colour of the browser.
        $add('branding_css', static fn(): string => Branding::css($themeSettings(), $baseUrl), true);
        $add('branding_head', static fn(string $language = ''): string => Branding::head($themeSettings(), $baseUrl, $language), true);

        // The tracking of Admin > Analytics: the owner's code (or the platform's script) for the head, and the owner's code for the end of the page.
        $add('analytics_head', static fn(string $scriptUrl = '', string $apiUrl = ''): string => AnalyticsSettings::head(AnalyticsSettings::from($settings()), $signedIn(), $scriptUrl, $apiUrl), true);
        $add('analytics_body', static fn(): string => AnalyticsSettings::body(AnalyticsSettings::from($settings()), $signedIn()), true);

        // The site-wide search engine settings (Admin > SEO) as a page uses them: its title and description, what the robots tag says,
        // the tags that are the same on every page, and the card of a shared link.
        $add('seo_title', static fn(mixed $own, mixed $page, bool $home, mixed $type): string => SeoSettings::title(
            SeoSettings::from($settings()), (string)$own, (string)$page, (string)($settings()['title'] ?? ''), (string)($settings()['tagline'] ?? ''), $home, (string)$type
        ));
        $add('seo_description', static fn(mixed $own, mixed $excerpt, mixed $document, bool $home): string => SeoSettings::description(
            SeoSettings::from($settings()), (string)$own, (string)$excerpt, (string)$document, (string)($settings()['tagline'] ?? ''), $home
        ));
        $add('seo_robots', static fn(bool $pageNoindex, mixed $kind): string => SeoSettings::robots(SeoSettings::from($settings()), $pageNoindex, (string)$kind));
        $add('seo_head', static fn(): string => SeoSettings::head(SeoSettings::from($settings())), true);
        $add('seo_share_image', static fn(): string => (string)SeoSettings::from($settings())['share_image']);
        $add('seo_twitter_card', static fn(bool $hasImage): string => SeoSettings::twitterCard(SeoSettings::from($settings()), $hasImage));
    }
}
