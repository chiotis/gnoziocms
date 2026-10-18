<?php

declare(strict_types=1);

namespace GnozioCMS;

use Twig\Environment;
use Twig\TwigFunction;

/**
 * The template functions of the maps of content (see GeoView): the data of a set of entries, of the page of one, and what is near
 * one; the position and the facts of a route; the profile of its heights; and when the map is loaded. GeoView and the language are
 * asked for when a function runs, so nothing is built for a page that has no map.
 */
final class GeoTwigFunctions
{
    /**
     * @param \Closure(): GeoView $geoView
     * @param \Closure(): string $language the language being shown
     * @param \Closure(): array<string, mixed> $settings the site's settings, as they are now
     */
    public static function register(Environment $twig, \Closure $geoView, \Closure $language, \Closure $settings): void
    {
        $add = static function (string $name, callable $function, bool $html = false) use ($twig): void {
            $twig->addFunction(new TwigFunction($name, $function, $html ? ['is_safe' => ['html']] : []));
        };

        $add('geo_dataset', static function (array $items, array $options = []) use ($geoView, $language): array {
            return $geoView()->dataset(array_values(array_filter($items, static fn($i): bool => $i instanceof ContentItem)), $language(), $options);
        });
        $add('geo_single', static function (ContentItem $item, array $options = []) use ($geoView, $language): array {
            return $geoView()->single($item, $language(), $options);
        });
        $add('geo_nearby', static function (ContentItem $item, array $types, float $radius = 5.0, int|array $limit = 6) use ($geoView, $language): array {
            return $geoView()->nearby($item, $types, $radius, $limit, $language());
        });
        $add('geo_json', static function (array $data): string {
            return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        }, true);
        $add('geo_load', static function () use ($settings): string {
            return ($settings()['apis']['maps']['load'] ?? 'click') === 'auto' ? 'auto' : 'click';
        });
        $add('geo_position', static function (ContentItem $item) use ($geoView): ?array {
            return $geoView()->map()->position($item);
        });
        $add('route_facts', static function (ContentItem $item) use ($geoView): ?array {
            return $geoView()->map()->routeFacts($item);
        });
        $add('elevation_profile', static function (array $profile, string $label): string {
            return GeoView::profileSvg($profile, $label);
        }, true);
    }
}
