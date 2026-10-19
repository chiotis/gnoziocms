<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * The blocks a template built with blocks starts from (docs/theming.md, "Starting templates"), so the person who chooses *Built with
 * blocks* for a content type meets a page that already works instead of an empty list. Two sources, no code for any one type:
 *
 *  1. a file of data, `single-templates/<type>.yaml` in custom/ (the site's, wins) or in the theme: `blocks:` in the shape of a stored
 *     template. The default theme ships one for each of its types.
 *  2. otherwise a template generated from the type's definition: the title, a picture field and a list of links when the type has
 *     them, a fact sheet of the fields the page shows, the text, and the entry's own blocks. A type a site adds later gets this.
 *
 * Whatever the source, the blocks go through the same checks as a saved template (BlockRegistry::sanitizeForStorage), so a block or a
 * field that does not exist is dropped instead of breaking the page.
 */
final class StartingTemplates
{
    /**
     * @param \Closure(): string $defaultLanguage
     */
    public function __construct(
        private Theme $theme,
        private ContentTypes $types,
        private BlockRegistry $registry,
        private \Closure $defaultLanguage
    ) {
    }

    /** The sets to store for a type (`default` only): hand-made if a file has them, else generated. @return array<string, array<int, mixed>> */
    public function sets(string $type): array
    {
        $blocks = $this->fromFile($type) ?? self::generate($this->types, $type, ($this->defaultLanguage)(), ($this->defaultLanguage)());
        // A block the site does not have (a file written for another theme) is left out; a template should not carry what it cannot draw.
        $blocks = array_values(array_filter($blocks, fn(mixed $block): bool => is_array($block) && is_string($block['type'] ?? null) && ($block['type'] === BlockContainer::TYPE || $this->registry->get($block['type']) !== null)));
        $blocks = $this->registry->sanitizeForStorage($blocks);
        return $blocks === [] ? [] : ['default' => $blocks];
    }

    /** Whether a theme or the site ships a starting template for the type (it is not generated). */
    public function hasFile(string $type): bool
    {
        return $this->fromFile($type) !== null;
    }

    /** The blocks of the first file that has some (a file that cannot be read gives way to the next). @return array<int, mixed>|null */
    private function fromFile(string $type): ?array
    {
        foreach ($this->theme->startingTemplateFiles($type) as $file) {
            try {
                $data = Yaml::parseFile($file);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($data) && is_array($data['blocks'] ?? null) && array_is_list($data['blocks']) && $data['blocks'] !== []) {
                return $data['blocks'];
            }
        }
        return null;
    }

    /**
     * A template for any content type, from its definition alone: the entry's title area; the first picture field and the first list
     * of links (a repeater with a text and a link in its rows) as blocks bound to them; a fact sheet of the fields the page shows; the
     * entry's text; and the blocks the entry has of its own.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function generate(ContentTypes $types, string $type, string $lang, string $defaultLang): array
    {
        $picture = null;
        $links = null;
        $facts = [];
        foreach ($types->editableFields($type, $lang, $defaultLang) as $key => $field) {
            $key = (string)$key;
            if ($field['type'] === 'image' && $picture === null) {
                $picture = $key;
                continue;
            }
            if ($field['type'] === 'repeater' && $links === null && ($map = self::linkColumns($field)) !== null) {
                $links = [$key, $map];
                continue;
            }
            if ($field['type'] !== 'image' && (($field['show'] ?? true) === true || ($field['card'] ?? false) === true)) {
                $facts[] = ['field' => 'custom_fields.' . $key];
            }
        }
        $blocks = [['type' => 'entry-title']];
        if ($picture !== null) {
            $blocks[] = ['type' => 'image', 'image' => ['bind' => 'custom_fields.' . $picture]];
        }
        if ($facts !== []) {
            $blocks[] = ['type' => 'fact-sheet', 'fields' => $facts];
        }
        if ($links !== null) {
            $blocks[] = ['type' => 'buttons', 'actions' => ['bind' => 'custom_fields.' . $links[0], 'map' => $links[1], 'fixed' => ['style' => ['first' => 'primary', 'rest' => 'secondary']]]];
        }
        $blocks[] = ['type' => 'content'];
        $blocks[] = ['type' => 'entry-blocks'];
        return $blocks;
    }

    /**
     * The columns of a list of links: the first text of its rows is the label, the first link or address the target. Null for any other list.
     *
     * @param array<string, mixed> $field a repeater
     * @return array{label: string, url: string}|null
     */
    private static function linkColumns(array $field): ?array
    {
        $label = null;
        $url = null;
        foreach ($field['fields'] as $key => $column) {
            if ($column['type'] === 'text' && $label === null) {
                $label = (string)$key;
            } elseif (in_array($column['type'], ['link', 'url'], true) && $url === null) {
                $url = (string)$key;
            }
        }
        return $label !== null && $url !== null ? ['label' => $label, 'url' => $url] : null;
    }
}
