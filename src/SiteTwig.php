<?php

declare(strict_types=1);

namespace GnozioCMS;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The Twig environment of the site, public pages and admin alike: where templates are looked for (the site's `custom/`, the
 * theme, `@theme`, `@admin`), and every function, filter and global a template can use. The functions that need nothing from
 * the request are in TwigFunctions, the site's choices in PageTwigFunctions, the maps in GeoTwigFunctions; here are the ones
 * that depend on the language being shown, the signed-in person, the form token and the content types and taxonomies.
 *
 * What changes during a request (settings, language, who is signed in) is read through closures when a function runs. The
 * `site`, `theme_settings` and `is_admin` globals are taken when the environment is created, as they always were: a theme
 * preview creates a new environment after changing the theme settings.
 */
final class SiteTwig
{
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(): array<string, mixed> $themeSettings
     * @param \Closure(): string $language the language being shown
     * @param \Closure(): string $defaultLanguage
     * @param \Closure(): bool $signedIn
     * @param \Closure(string): bool $can whether the signed-in person has a capability
     * @param \Closure(): string $csrfToken
     * @param \Closure(string, ?string): string $translate
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     * @param \Closure(): StructuredData $structuredData
     * @param \Closure(): ContentTypes $contentTypes
     * @param \Closure(): SingleLayouts $singleLayouts
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(string, string, ?string): string $termLabel taxonomy, term, language
     * @param \Closure(string, string, string): string $termPath taxonomy, term, language prefix
     * @param \Closure(): BlockRuntime $blockRuntime
     * @param \Closure(): bool $hasSiteFeed
     * @param \Closure(): array<int, string> $siteLanguages
     * @param (\Closure(): Relations)|null $relations
     */
    public function __construct(
        private string $basePath,
        private Theme $theme,
        private Images $images,
        private \Closure $settings,
        private \Closure $themeSettings,
        private \Closure $language,
        private \Closure $defaultLanguage,
        private \Closure $signedIn,
        private \Closure $can,
        private \Closure $csrfToken,
        private \Closure $translate,
        private \Closure $absoluteUrl,
        private \Closure $structuredData,
        private \Closure $contentTypes,
        private \Closure $singleLayouts,
        private \Closure $taxonomies,
        private \Closure $termLabel,
        private \Closure $termPath,
        private \Closure $blockRuntime,
        private \Closure $hasSiteFeed,
        private \Closure $siteLanguages,
        private ?\Closure $relations = null
    ) {
    }

    public function create(): Environment
    {
        $adminDir = $this->basePath . '/admin/templates';

        $loader = new FilesystemLoader();
        foreach ($this->theme->templateRoots() as $root) {
            $loader->addPath($root);
        }
        // `{% extends '@theme/templates/single.twig' %}` lets a custom/ override change one block only.
        $loader->addPath($this->theme->path(), 'theme');
        $loader->addPath($adminDir, 'admin');

        $twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);

        $settings = $this->settings;
        $themeSettings = $this->themeSettings;
        $language = $this->language;
        $defaultLanguage = $this->defaultLanguage;
        $absoluteUrl = $this->absoluteUrl;
        $termLabel = $this->termLabel;
        $termPath = $this->termPath;

        TwigFunctions::register(
            $twig,
            (string)($settings()['base_url'] ?? ''),
            $this->theme,
            $this->images,
            fn(string $path): string => ($absoluteUrl)($path),
            $this->structuredData,
            $this->basePath . '/public'
        );

        $formatDate = fn(mixed $value): string => Format::dateValue($value, (string)($settings()['date_format'] ?? 'd/m/Y'));

        // Declared fields of a content item, ready to print: content_fields(item) for its page,
        // content_fields(item, 'card') for the fields marked for cards.
        $twig->addFunction(new TwigFunction('content_fields', function (mixed $item, string $context = 'page') use ($formatDate, $language, $defaultLanguage): array {
            if (!$item instanceof ContentItem) {
                return [];
            }
            return ($this->contentTypes)()->display(
                $item->type,
                $item->meta,
                $context === 'card' ? 'card' : 'page',
                fn(string $value): string => $formatDate($value),
                $language(),
                $defaultLanguage()
            );
        }));

        // The checked values of the declared fields by key (a repeater as a list of rows), for a template that draws them itself.
        $twig->addFunction(new TwigFunction('content_values', function (mixed $item) use ($language, $defaultLanguage): array {
            if (!$item instanceof ContentItem) {
                return [];
            }
            return ($this->contentTypes)()->values($item->type, $item->meta, $language(), $defaultLanguage());
        }));

        // The entries an entry points at through a relation field: related(item, 'author'), or related(row.book) for the ids stored
        // in a row of a list. Each is {id, type, slug, lang, title, excerpt, main_image, date, url}; only what a visitor may see.
        $twig->addFunction(new TwigFunction('related', function (mixed $source, string $field = '') use ($language): array {
            if ($this->relations === null) {
                return [];
            }
            $relations = ($this->relations)();
            if ($source instanceof ContentItem) {
                return $field === '' ? [] : $relations->related($source, $field, $language());
            }
            return $relations->resolve(RelationFields::idList($source), [], $language());
        }));

        // The entries of a content type that point at this one through a field: referenced_by(author, 'books', 'author'), newest first
        // (`related.book` for a field in a row of a list).
        $twig->addFunction(new TwigFunction('referenced_by', function (mixed $item, string $type, string $field) use ($language): array {
            if ($this->relations === null || !$item instanceof ContentItem) {
                return [];
            }
            return ($this->relations)()->referencedBy($item, $type, $field, $language());
        }));

        GeoTwigFunctions::register(
            $twig,
            fn(): GeoView => ($this->blockRuntime)()->geoView(),
            $language,
            $settings
        );

        // What the page of one entry of a type does (Theme > Single Layouts): title style, header, parts, sidebar.
        $twig->addFunction(new TwigFunction('single_layout', function (string $type) use ($themeSettings): array {
            return ($this->singleLayouts)()->forType($type, $themeSettings());
        }));

        // The title area a term asks for the entries filed under it (Admin > Taxonomies), for an entry that has no choice of its own.
        $twig->addFunction(new TwigFunction('term_hero_layout', function (mixed $item): string {
            if (!$item instanceof ContentItem) {
                return '';
            }
            $taxonomies = ($this->taxonomies)();
            foreach ($taxonomies->names() as $name) {
                foreach (Format::list($item->meta[$name] ?? null) as $termId) {
                    $layout = $taxonomies->heroLayout($name, $termId);
                    if ($layout !== '') {
                        return $layout;
                    }
                }
            }
            return '';
        }));

        $twig->addFunction(new TwigFunction('content_type', function (string $type) use ($language, $defaultLanguage): array {
            return ($this->contentTypes)()->definition($type, $language(), $defaultLanguage());
        }));

        $twig->addFunction(new TwigFunction('t', function (string $key, ?string $fallback = null): string {
            return ($this->translate)($key, $fallback);
        }));

        $twig->addFunction(new TwigFunction('format_date', function (mixed $value) use ($formatDate): string {
            return $formatDate($value);
        }));

        // The path of an entry for a link, after the language prefix a template already has ("" or "en/"): about, my-post,
        // projects/mine, or nothing for the home page.
        $twig->addFunction(new TwigFunction('item_path', function (mixed $item, string $prefix = '') use ($settings, $defaultLanguage): string {
            if (!$item instanceof ContentItem) {
                return $prefix;
            }
            $default = $defaultLanguage();
            $home = (string)(($settings()['home_page'] ?? '') !== '' ? $settings()['home_page'] : 'index');
            return $prefix . ContentPaths::build($item->type, $item->slug, $default, $home, $default);
        }));

        $twig->addFunction(new TwigFunction('slug', function (string $value): string {
            return Slug::plain($value);
        }));

        $twig->addFunction(new TwigFunction('taxonomy_label', function (string $taxonomy, string $termId, ?string $lang = null) use ($termLabel): string {
            return $termLabel($taxonomy, $termId, $lang);
        }));

        $twig->addFunction(new TwigFunction('taxonomy_url', function (string $taxonomy, string $termId, string $prefix = '') use ($termPath, $absoluteUrl): string {
            $path = $termPath($taxonomy, $termId, $prefix);
            return $absoluteUrl($path);
        }));

        // The terms an entry has in the taxonomies a site added (categories and tags are drawn by the templates themselves):
        // [{name, title, terms: [{label, url}]}], each term with a page.
        $twig->addFunction(new TwigFunction('item_terms', function (mixed $item, string $prefix = '') use ($termLabel, $termPath, $absoluteUrl): array {
            $meta = is_object($item) && isset($item->meta) && is_array($item->meta) ? $item->meta : [];
            $taxonomies = ($this->taxonomies)();
            $groups = [];
            foreach ($taxonomies->names() as $name) {
                if (!Taxonomies::isCustom($name)) {
                    continue;
                }
                $terms = [];
                $known = array_column($taxonomies->load($name)['terms'], 'id');
                foreach (Format::list($meta[$name] ?? null) as $termId) {
                    if (!in_array((string)$termId, $known, true)) {
                        continue;
                    }
                    $label = $termLabel($name, (string)$termId, null);
                    $path = $termPath($name, (string)$termId, $prefix);
                    if ($label !== '' && $path !== '') {
                        $terms[] = ['label' => $label, 'url' => $absoluteUrl($path)];
                    }
                }
                if ($terms !== []) {
                    $groups[] = ['name' => $name, 'title' => $taxonomies->load($name)['title'], 'terms' => $terms];
                }
            }
            return $groups;
        }));

        // A text a theme setting may have in several languages, as the text for the language of the page.
        $twig->addFilter(new TwigFilter('localized', fn(array $context, mixed $value): string => Format::localized($value, (string)($context['lang'] ?? $language())), ['needs_context' => true]));

        // The blocks of Theme > Footer Blocks, drawn once for each language of a request: the markup for above the footer and the
        // style sheets and scripts they need (the layout asks for those in the head and the footer for the markup).
        $footerBlocks = [];
        $twig->addFunction(new TwigFunction('footer_blocks', function (array $context) use (&$footerBlocks, $twig, $language, $themeSettings, $settings): array {
            $lang = (string)($context['lang'] ?? $language());
            if (!isset($footerBlocks[$lang])) {
                $raw = FooterBlocks::forLanguage($themeSettings()['footer_blocks'] ?? null, $lang, (string)($settings()['languages']['default'] ?? 'en'));
                $footerBlocks[$lang] = ['html' => '', 'styles' => [], 'scripts' => []];
                if ($raw !== []) {
                    $rendered = ($this->blockRuntime)()->renderer($lang, (string)($context['path_no_lang'] ?? ''), $twig)->render($raw, [
                        'lang' => $lang,
                        'lang_prefix' => $context['lang_prefix'] ?? '',
                        'current_lang' => $lang,
                        'path_no_lang' => $context['path_no_lang'] ?? '',
                        'theme_menus' => $context['theme_menus'] ?? [],
                        'body_html' => '',
                    ]);
                    $footerBlocks[$lang] = ['html' => $rendered['html'], 'styles' => $rendered['styles'], 'scripts' => $rendered['scripts']];
                }
            }
            return $footerBlocks[$lang];
        }, ['needs_context' => true]));

        $twig->addFunction(new TwigFunction('can', function (string $capability): bool {
            return ($this->can)($capability);
        }));

        $twig->addFunction(new TwigFunction('csrf_token', function (): string {
            return ($this->csrfToken)();
        }));

        $twig->addFunction(new TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(($this->csrfToken)(), ENT_QUOTES) . '">';
        }, ['is_safe' => ['html']]));

        // The address of the site's feed in the language of the page, or nothing while the feed is off: the head announces it.
        $twig->addFunction(new TwigFunction('feed_url', fn(string $prefix = ''): string => ($this->hasSiteFeed)() ? $absoluteUrl($prefix . 'feed') : ''));

        PageTwigFunctions::register(
            $twig,
            $settings,
            $themeSettings,
            $this->signedIn,
            (string)($settings()['base_url'] ?? '')
        );

        // The languages of the site, its own first: the admin shows a version of each translatable text for each of them.
        $twig->addGlobal('admin_languages', ($this->siteLanguages)());
        $twig->addGlobal('site', $settings());
        $twig->addGlobal('theme_settings', $themeSettings());
        $twig->addGlobal('theme', ['name' => $this->theme->name(), 'version' => $this->theme->version()]);
        $twig->addGlobal('is_admin', ($this->signedIn)());

        return $twig;
    }
}
