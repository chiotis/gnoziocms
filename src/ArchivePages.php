<?php

declare(strict_types=1);

namespace GnozioCMS;

use Twig\Environment;

/**
 * The public page of a list of entries when it is built with blocks (Theme > Archive Layouts > Edit template): which settings the page's
 * list block asks the archive to use (size of a page, order, columns) and the blocks drawn with the archive as their context. A list
 * that is not built with blocks is not touched: both questions answer "no template" and the theme's `archive.twig` draws the page.
 */
final class ArchivePages
{
    /**
     * @param \Closure(): BlockRuntime $runtime
     * @param \Closure(): string $defaultLanguage
     */
    public function __construct(private TemplateResolver $templates, private \Closure $runtime, private ContentTypes $types, private \Closure $defaultLanguage)
    {
    }

    /** The orders a list can have: the common ones and one for each field of a content type that can be sorted. @return array<string, string> */
    public function orders(string $key, string $lang): array
    {
        if (str_starts_with($key, 'taxonomy:')) {
            return ContentTypes::ORDERS;
        }
        return ContentTypes::orderOptions($this->types->definition($key, $lang, ($this->defaultLanguage)())['fields']);
    }

    /**
     * The archive settings with what the list block of the template chose (the page size, the order), or as they are when the list is
     * not drawn from a template.
     *
     * @param array<string, mixed> $settings
     * @param array{blocks: array<int, mixed>, card: array<int, mixed>}|null $built the template (TemplateResolver::builtArchive), when it has been looked up
     * @return array<string, mixed>
     */
    public function settings(string $key, string $lang, array $settings, ?array $built = null): array
    {
        $built ??= $this->templates->builtArchive($key, $lang);
        return $built === null ? $settings : ArchiveTemplates::withListChoices($settings, ArchiveTemplates::listBlock($built['blocks']), $this->orders($key, $lang));
    }

    /**
     * The view data of a list drawn from its template: the page's blocks (`page_blocks`) with their style sheets and scripts. Null when the
     * list has no template, and the caller draws it as it always did.
     *
     * @param array<string, mixed> $data the view data of the list: `archive` (ArchiveBuilder), `type`, titles, the page's defaults
     * @return array<string, mixed>|null
     */
    public function draw(string $key, string $lang, string $path, Environment $twig, array $data, string $preview = ''): ?array
    {
        $built = $this->templates->builtArchive($key, $lang);
        if ($built === null) {
            return null;
        }
        $page = ($this->runtime)()->renderArchive($built['blocks'], $built['card'], $lang, $path, $twig, $data, $preview);
        return [
            'page_blocks' => $page,
            'block_styles' => $page['styles'],
            'block_scripts' => $page['scripts'],
            'structured_data' => array_merge((array)($data['structured_data'] ?? []), $page['structured_data'] ?? []),
        ];
    }
}
