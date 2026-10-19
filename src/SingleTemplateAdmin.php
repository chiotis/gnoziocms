<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Theme > Single Layouts > Edit template: what the screen of a template built with blocks shows, what saving it stores, and which entry
 * and settings its live preview draws. It knows no content type: the fields come from the type's definition when asked. The request,
 * the rendering and the redirects stay with the caller.
 */
final class SingleTemplateAdmin
{
    /** How many entries the preview offers. */
    private const CHOICES = 40;

    /** What the preview shows when the type has no entry. */
    public const NO_ENTRY = '<!doctype html><meta charset="utf-8"><body style="font:15px system-ui;padding:2rem;color:#4d5d73">There is no entry of this type to show yet. Add one, then come back.</body>';

    /**
     * @param \Closure(): string[] $typeList the content types the site has
     * @param \Closure(string): ContentItem[] $items every entry of a type, in all languages, drafts too
     * @param \Closure(string, string, string): ?ContentItem $find an entry by type, slug and language
     * @param \Closure(): string $defaultLanguage
     */
    public function __construct(
        private Theme $theme,
        private ContentTypes $types,
        private BlockRegistry $registry,
        private BlockSets $sets,
        private \Closure $typeList,
        private \Closure $items,
        private \Closure $find,
        private \Closure $defaultLanguage
    ) {
    }

    /** Whether a type can have a template: a type the site has, not a form, in a theme that draws one. */
    public function available(string $type): bool
    {
        return in_array($type, ($this->typeList)(), true) && $type !== 'forms' && $this->theme->hasTemplate(TemplateResolver::BUILT);
    }

    /**
     * What the screen shows (the template adds the person and the menu).
     *
     * @param array<string, mixed> $current the theme settings now
     * @param array<string, mixed> $query the address's query (`entry`, `saved`, `fail`)
     * @return array<string, mixed>
     */
    public function view(string $type, array $current, array $query): array
    {
        $default = ($this->defaultLanguage)();
        $stored = SingleTemplates::entry($current, $type);
        $definition = $this->types->definition($type, $default, $default);
        $sources = EntryBindings::sources($this->types, $type, $default, $default);
        $fieldChoices = [];
        foreach ($sources as $source) {
            if (str_starts_with($source['path'], 'custom_fields.')) {
                $fieldChoices[] = ['path' => $source['path'], 'label' => $source['label'] . ' (' . $source['key'] . ')'];
            }
        }
        // The entries to look at: the latest first, the language each is in.
        $items = ($this->items)($type);
        usort($items, static fn(ContentItem $a, ContentItem $b): int => strcmp((string)($b->meta['date'] ?? ''), (string)($a->meta['date'] ?? '')) ?: $b->mtime <=> $a->mtime);
        $choices = [];
        foreach (array_slice($items, 0, self::CHOICES) as $item) {
            $choices[] = ['value' => $item->slug . '|' . $item->lang, 'label' => (string)($item->meta['title'] ?? $item->slug) . ' (' . $item->lang . ')'];
        }
        $chosen = (string)($query['entry'] ?? '');
        if (!in_array($chosen, array_column($choices, 'value'), true)) {
            $chosen = (string)($choices[0]['value'] ?? '');
        }
        return [
            'saved' => isset($query['saved']),
            'failed' => isset($query['fail']),
            'type' => $type,
            'type_label' => $definition['label'],
            'type_singular' => $definition['singular'],
            'template_use' => $stored['use'] || SingleTemplates::blocks($current, $type, $default, $default) === [],
            'preview_choices' => $choices,
            'preview_chosen' => $chosen,
            'template_json' => $this->sets->editorJson($default, $stored['blocks'], 'template', $this->registry->editorDefinitions(true, $fieldChoices), [
                'bindings' => ['type' => $type, 'sources' => $sources, 'accepts' => EntryBindings::ACCEPTS],
            ]),
        ];
    }

    /**
     * The theme settings to store after the form was sent: the sets the editor sent (without the editor's data the blocks stay as they
     * are), and the switch.
     *
     * @param array<string, mixed> $current the theme settings now
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function saved(string $type, array $current, array $post): array
    {
        $templates = SingleTemplates::with($current, $type, isset($post['use']), $this->postedSets($type, $current, $post));
        if ($templates === []) {
            unset($current['single_templates']);
        } else {
            $current['single_templates'] = $templates;
        }
        return $current;
    }

    /**
     * What the live preview draws: the entry the form names and the theme settings with the unsaved blocks (nothing is stored); null
     * when the entry is not there.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $post
     * @return array{item: ContentItem, settings: array<string, mixed>}|null
     */
    public function preview(string $type, array $current, array $post): ?array
    {
        [$slug, $lang] = array_pad(explode('|', (string)($post['preview_entry'] ?? ''), 2), 2, '');
        $item = $slug !== '' && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $lang) === 1 ? ($this->find)($type, Slug::plain($slug), $lang) : null;
        if ($item === null) {
            return null;
        }
        $settings = $this->theme->resolveSettings(['single_templates' => SingleTemplates::with($current, $type, true, $this->postedSets($type, $current, $post))] + $current);
        return ['item' => $item, 'settings' => $settings];
    }

    /** @return array<string, array<int, mixed>>|null null when the form carries no editor data */
    private function postedSets(string $type, array $current, array $post): ?array
    {
        $posted = ($post['blocks_editor'] ?? '') === '1' ? json_decode((string)($post['blocks_json'] ?? ''), true) : null;
        return is_array($posted) ? $this->sets->clean($posted, SingleTemplates::entry($current, $type)['blocks']) : null;
    }
}
