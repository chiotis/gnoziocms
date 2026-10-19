<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * The blocks an archive template built with blocks starts from (docs/theming.md, "Starting templates"), for the page of a list and for
 * its card, so the person who chooses *Built with blocks* for a list meets a page that already works. Two sources, no code for any one
 * content type:
 *
 *  1. a file of data, `archive-templates/<key>.yaml` in custom/ (the site's, wins) or in the theme: `blocks:` (the page) and `card:` in
 *     the shape of a stored template. The key of a taxonomy's file is `taxonomy-<name>`.
 *  2. otherwise a template generated from the list's settings and the type's definition: the title area, the filters, the count, the list
 *     with the order, size and columns the list had, and a card with the main image, the title and the excerpt. A type a site adds
 *     later gets this.
 *
 * Whatever the source, the blocks go through the same checks as a saved template (BlockRegistry::sanitizeForStorage), so a block that
 * does not exist is dropped instead of breaking the page.
 */
final class StartingArchives
{
    /**
     * @param \Closure(): string $defaultLanguage
     */
    public function __construct(
        private Theme $theme,
        private BlockRegistry $registry,
        private ContentTypes $types,
        private Taxonomies $taxonomies,
        private \Closure $defaultLanguage
    ) {
    }

    /** @return array{page: array<string, array<int, mixed>>, card: array<string, array<int, mixed>>} the sets to store (`default` only) */
    public function sets(string $key): array
    {
        $file = $this->fromFile($key);
        $settings = $this->settingsOf($key);
        $page = $file['blocks'] ?? self::generate($settings);
        $card = $file['card'] ?? self::generateCard($key);
        return ['page' => $this->checked($page), 'card' => $this->checked($card)];
    }

    /** The archive settings a list has now (a content type's, or a taxonomy's): its size, order and columns are what its starting page keeps. @return array<string, mixed> */
    private function settingsOf(string $key): array
    {
        $default = ($this->defaultLanguage)();
        return str_starts_with($key, 'taxonomy:') ? $this->taxonomies->archive(substr($key, 9), $default, $default) : $this->types->definition($key, $default, $default)['archive'];
    }

    /** Whether a theme or the site ships a starting template for the list (it is not generated). */
    public function hasFile(string $key): bool
    {
        return $this->fromFile($key) !== null;
    }

    /** @param array<int, mixed> $blocks @return array<string, array<int, mixed>> */
    private function checked(array $blocks): array
    {
        // A block the site does not have (a file written for another theme) is left out; a template should not carry what it cannot draw.
        $blocks = array_values(array_filter($blocks, fn(mixed $block): bool => is_array($block) && is_string($block['type'] ?? null) && ($block['type'] === BlockContainer::TYPE || $this->registry->get($block['type']) !== null)));
        $blocks = $this->registry->sanitizeForStorage($blocks);
        return $blocks === [] ? [] : ['default' => $blocks];
    }

    /** @return array{blocks?: array<int, mixed>, card?: array<int, mixed>}|null */
    private function fromFile(string $key): ?array
    {
        foreach ($this->theme->startingTemplateFiles(str_replace(':', '-', $key), 'archive-templates') as $file) {
            try {
                $data = Yaml::parseFile($file);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($data) && is_array($data['blocks'] ?? null) && array_is_list($data['blocks']) && $data['blocks'] !== []) {
                return ['blocks' => $data['blocks']] + (is_array($data['card'] ?? null) && array_is_list($data['card']) && $data['card'] !== [] ? ['card' => $data['card']] : []);
            }
        }
        return null;
    }

    /**
     * The page of any list from its settings: the title area, the filters, how many results a filter leaves, and the list itself with the
     * order, the page size and the columns the list has now.
     *
     * @param array<string, mixed> $settings the archive settings
     * @return array<int, array<string, mixed>>
     */
    public static function generate(array $settings): array
    {
        $variant = match ((string)($settings['layout'] ?? 'cards')) {
            'list' => 'list',
            'compact' => 'compact',
            default => 'grid',
        };
        $list = ['type' => ArchiveTemplates::LIST_BLOCK, 'variant' => $variant, 'columns' => (string)($settings['columns'] ?? '3'), 'per_page' => (int)($settings['per_page'] ?? 12), 'order' => (string)($settings['order'] ?? 'date_desc')];
        return [['type' => 'archive-title'], ['type' => 'filter-bar'], ['type' => 'result-count'], $list];
    }

    /** The card of any list: the main image, the title and the excerpt of the entry. @return array<int, array<string, mixed>> */
    public static function generateCard(string $key): array
    {
        return [
            ['type' => 'image', 'image' => ['bind' => 'main_image'], 'ratio' => 'landscape'],
            ['type' => 'text', 'heading' => ['bind' => 'title'], 'body' => ['bind' => 'excerpt']],
        ];
    }
}
