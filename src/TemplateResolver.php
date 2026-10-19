<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Which theme template draws a page of the public site: an entry (its own `template:`, the type's template, the page layout
 * chosen in Theme > Single Layouts), the archive of a type, and the page of a category or tag. The theme says which templates
 * exist; the theme settings are read when asked, because a theme preview changes them during a request.
 */
final class TemplateResolver
{
    /** @param \Closure(): array<string, mixed> $themeSettings */
    public function __construct(private Theme $theme, private SingleLayouts $layouts, private \Closure $themeSettings)
    {
    }

    public function forItem(ContentItem $item): string
    {
        $custom = self::normalize((string)($item->meta['template'] ?? ''));
        if ($custom !== '' && $this->theme->hasTemplate($custom)) {
            return $custom;
        }

        $default = $this->theme->defaultSingleTemplate($item->type, self::singular($item->type));
        // An entry can ask for the plain layout although its content type has another.
        if ($custom === 'templates/' . SingleLayouts::PLAIN . '.twig') {
            return $default;
        }
        // The page layout chosen for the type (Theme > Single Layouts) applies to the types whose own template is the standard one.
        $chosen = $this->layouts->effectiveTemplate($item->type, ($this->themeSettings)());
        if ($chosen !== 'default' && $item->type !== 'forms' && $this->theme->hasTemplate('templates/' . $chosen . '.twig') && $this->theme->usesTitleArea($default)) {
            return 'templates/' . $chosen . '.twig';
        }

        return $default;
    }

    public function forArchive(string $type): string
    {
        $singular = self::singular($type);
        return $this->theme->findTemplate([
            'templates/archive-' . $type . '.twig',
            'templates/archive-' . $singular . '.twig',
            'templates/' . $type . '_archive.twig',
            'templates/archive.twig',
        ]) ?? 'templates/archive.twig';
    }

    public function forTaxonomy(string $kind, string $slug): string
    {
        $safeSlug = Slug::plain(basename($slug));
        return $this->theme->findTemplate([
            'templates/archive-' . $kind . '-' . $safeSlug . '.twig',
            'templates/archive-' . $kind . '.twig',
            'templates/archive.twig',
        ]) ?? 'templates/archive.twig';
    }

    /** Front matter `template:` accepts `landing`, `landing.twig`, or `templates/landing.twig`. */
    public static function normalize(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (!str_ends_with($name, '.twig')) {
            $name .= '.twig';
        }
        if (!str_starts_with($name, 'templates/')) {
            $name = 'templates/' . $name;
        }
        return Theme::isSafeRelativePath($name) ? $name : '';
    }

    public static function singular(string $type): string
    {
        // businesses → business, stories → story, routes → route
        if (str_ends_with($type, 'sses')) {
            return substr($type, 0, -2);
        }
        if (str_ends_with($type, 'ies') && strlen($type) > 4) {
            return substr($type, 0, -3) . 'y';
        }
        if (str_ends_with($type, 's') && strlen($type) > 1) {
            return substr($type, 0, -1);
        }
        return $type;
    }
}
