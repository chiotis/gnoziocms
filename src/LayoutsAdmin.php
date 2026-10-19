<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The two tabs of Admin > Theme that gather how pages look: Single Layouts (one card for each content type, for the page
 * of one entry) and Archive Layouts (one card for each content type and each taxonomy, for its list of entries). This builds
 * what the cards show and saves what they submit. Archive settings stay where they always were (the site's content type
 * files and the taxonomy files); single layouts are theme settings.
 */
final class LayoutsAdmin
{
    /**
     * @param callable(): string[] $typeList the content types the site has, pages and forms included
     * @param callable(string): int $itemCount how many entries a type has
     * @param callable(string): string $singular the singular form of a type's name, as the template hierarchy uses it
     */
    public function __construct(
        private Theme $theme,
        private SingleLayouts $single,
        private ContentTypes $types,
        private ContentTypeAdmin $typeAdmin,
        private Taxonomies $taxonomies,
        private TaxonomyEditor $taxonomyEditor,
        private $typeList,
        private $itemCount,
        private $singular
    ) {
    }

    /**
     * The cards of Single Layouts.
     *
     * @param array<string, mixed> $themeSettings the resolved theme settings
     * @return array<int, array<string, mixed>>
     */
    public function singleCards(array $themeSettings, string $default): array
    {
        $cards = [];
        foreach (($this->typeList)() as $type) {
            $definition = $this->types->definition($type, $default, $default);
            $template = $this->theme->defaultSingleTemplate($type, ($this->singular)($type));
            $source = $this->theme->templateSource($template);
            $standard = str_contains($source, 'components/page-header.twig');
            $values = $this->single->forType($type, $themeSettings);
            $declared = $definition['single'];
            // A page built with blocks (Edit template): any type but a form can have one, whatever fields it declares.
            $built = null;
            if ($type !== 'forms' && $this->theme->hasTemplate(TemplateResolver::BUILT)) {
                $stored = SingleTemplates::entry($themeSettings, $type);
                $sets = FooterBlocks::sets($stored['blocks'], array_merge(['default'], is_array($stored['blocks']) && !array_is_list($stored['blocks']) ? array_map('strval', array_keys($stored['blocks'])) : []));
                $built = [
                    'use' => $stored['use'],
                    'blocks' => array_sum(array_map('count', $sets)),
                    'url' => 'admin/theme?template=' . $type,
                ];
            }
            $cards[] = [
                'type' => $type,
                'label' => $definition['label'],
                'singular' => $definition['singular'],
                'count' => ($this->itemCount)($type),
                'values' => $values,
                // A page of its own (the book page) has no title area to style; its choices are the content type's fields.
                'standard' => $standard,
                'layouts' => $standard && $type !== 'forms',
                'byline' => $standard && str_contains($source, 'hero_meta'),
                // A page of its own draws the sidebar and the header over it itself when its content type says so.
                'sidebar' => ($standard && $type !== 'forms') || $declared['sidebar'],
                'header' => $standard || $declared['header'],
                // What the content type declares for its page (a book: where the cover goes, what it shows).
                'options' => $declared['options'],
                'template_file' => basename($template),
                'built' => $built,
                'edit_url' => $type === 'forms' ? '' : 'admin/content-types?type=' . $type,
            ];
        }
        return $cards;
    }

    /**
     * The cards of Archive Layouts: the content types that have a list, then the taxonomies.
     *
     * @return array{types: array<int, array<string, mixed>>, taxonomies: array<int, array<string, mixed>>, taxonomy_names: string[], type_labels: array<string, string>}
     */
    public function archiveCards(string $default): array
    {
        $names = $this->taxonomies->names();
        $cards = [];
        foreach (($this->typeList)() as $type) {
            if (in_array($type, ['pages', 'forms'], true)) {
                continue;
            }
            $definition = $this->types->definition($type, $default, $default);
            $cards[] = [
                'type' => $type,
                'label' => $definition['label'],
                'count' => ($this->itemCount)($type),
                'archive' => $definition['archive'],
                'options' => $definition['archive_options'],
                'orders' => ContentTypes::orderOptions($definition['fields']),
                'filters' => $names,
            ];
        }
        $listable = array_values(array_filter(($this->typeList)(), static fn(string $t): bool => !in_array($t, ['pages', 'forms'], true)));
        $taxonomies = [];
        foreach ($names as $name) {
            $loaded = $this->taxonomies->load($name);
            $taxonomies[] = [
                'orders' => ContentTypes::ORDERS,
                'name' => $name,
                'title' => $loaded['title'],
                'kind' => Taxonomies::kind($name),
                'hierarchical' => $loaded['hierarchical'],
                'terms' => count($loaded['terms']),
                'archive' => $this->taxonomies->archive($name, $default, $default),
                'filters' => array_values(array_diff($names, [$name])),
                'listable' => $listable,
            ];
        }
        $labels = [];
        foreach ($listable as $type) {
            $labels[$type] = $this->types->definition($type, $default, $default)['label'];
        }
        return ['types' => $cards, 'taxonomies' => $taxonomies, 'taxonomy_names' => $names, 'type_labels' => $labels];
    }

    /**
     * Saves the archive cards that were submitted.
     *
     * @param array<string, mixed> $types the submitted `archive_types[<type>][...]`
     * @param array<string, mixed> $taxonomies the submitted `archive_taxonomies[<name>][...]`
     * @return string[] the types whose file could not be written
     */
    public function saveArchives(array $types, array $taxonomies, string $default): array
    {
        $failed = [];
        $known = ($this->typeList)();
        foreach ($types as $type => $input) {
            $type = (string)$type;
            if (in_array($type, $known, true) && !in_array($type, ['pages', 'forms'], true) && is_array($input) && !$this->typeAdmin->updateArchive($type, $input, $default)) {
                $failed[] = $type;
            }
        }
        $names = $this->taxonomies->names();
        foreach ($taxonomies as $name => $input) {
            if (in_array((string)$name, $names, true) && is_array($input)) {
                $this->taxonomyEditor->updateArchive((string)$name, $input);
            }
        }
        return $failed;
    }
}
