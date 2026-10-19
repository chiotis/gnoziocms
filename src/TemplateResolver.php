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
    /** The template that draws the page of an entry from the blocks of its type's template (Theme > Single Layouts > Edit template). */
    public const BUILT = 'templates/single-built.twig';

    /**
     * @param \Closure(): array<string, mixed> $themeSettings
     * @param \Closure(): string $defaultLanguage the site's own language (a language without a template of its own uses that one's)
     */
    public function __construct(private Theme $theme, private SingleLayouts $layouts, private \Closure $themeSettings, private ?\Closure $defaultLanguage = null)
    {
    }

    /**
     * The blocks of the template built for an entry's type, or null when its page is not drawn from one: the type is not built with
     * blocks, the template has none in the entry's language, the entry asks for a template of its own, or the type is a form.
     *
     * @return array<int, mixed>|null
     */
    public function builtBlocks(ContentItem $item): ?array
    {
        if ($item->type === 'forms' || !$this->theme->hasTemplate(self::BUILT)) {
            return null;
        }
        $custom = self::normalize((string)($item->meta['template'] ?? ''));
        if ($custom !== '' && ($this->theme->hasTemplate($custom) || $custom === 'templates/' . SingleLayouts::PLAIN . '.twig')) {
            return null;
        }
        $settings = ($this->themeSettings)();
        $default = $this->defaultLanguage !== null ? ($this->defaultLanguage)() : $item->lang;
        return SingleTemplates::applies($settings, $item->type, $item->lang, $default) ? SingleTemplates::blocks($settings, $item->type, $item->lang, $default) : null;
    }

    public function forItem(ContentItem $item): string
    {
        $custom = self::normalize((string)($item->meta['template'] ?? ''));
        if ($custom !== '' && $this->theme->hasTemplate($custom)) {
            return $custom;
        }

        // An entry's own template wins; then the template built with blocks for its type; then the theme's.
        if ($this->builtBlocks($item) !== null) {
            return self::BUILT;
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
