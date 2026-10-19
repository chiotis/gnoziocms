<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Theme > Archive Layouts > Edit template: what the screen of an archive template built with blocks shows, what saving it stores, and
 * which list and settings its live preview draws. A list is a content type (`books`) or a taxonomy (`taxonomy:categories`); the screen
 * has two parts, the page (the blocks around and including the list) and the card (the blocks that draw one entry). It knows no content
 * type: fields and orders come from the definition when asked. The request, the rendering and the redirects stay with the caller.
 */
final class ArchiveTemplateAdmin
{
    /** How many terms the preview of a taxonomy offers. */
    private const CHOICES = 40;

    /** What the preview shows when there is nothing to draw. */
    public const NOTHING = '<!doctype html><meta charset="utf-8"><body style="font:15px system-ui;padding:2rem;color:#4d5d73">There is nothing to show yet: this taxonomy has no term. Add one, then come back.</body>';

    public const PARTS = ['page', 'card'];

    /**
     * @param \Closure(): string[] $typeList the content types the site has
     * @param \Closure(): string $defaultLanguage
     */
    public function __construct(
        private Theme $theme,
        private ContentTypes $types,
        private BlockRegistry $registry,
        private BlockSets $sets,
        private StartingArchives $starting,
        private ArchivePages $pages,
        private Taxonomies $taxonomies,
        private \Closure $typeList,
        private \Closure $defaultLanguage
    ) {
    }

    /** The key of every list the site has: the content types that have one, then the taxonomies. @return string[] */
    public function keys(): array
    {
        $keys = array_values(array_filter(($this->typeList)(), static fn(string $type): bool => !in_array($type, ['pages', 'forms'], true)));
        foreach ($this->taxonomies->names() as $name) {
            $keys[] = ArchiveTemplates::forTaxonomy($name);
        }
        return $keys;
    }

    /** Whether a list can have a template: a list the site has, in a theme that draws one. */
    public function available(string $key): bool
    {
        return ArchiveTemplates::validKey($key) && in_array($key, $this->keys(), true) && $this->theme->hasTemplate(TemplateResolver::ARCHIVE_BUILT);
    }

    /** The name of a list as the screens show it. */
    public function label(string $key): string
    {
        if (str_starts_with($key, 'taxonomy:')) {
            return $this->taxonomies->load(substr($key, 9))['title'];
        }
        $default = ($this->defaultLanguage)();
        return $this->types->definition($key, $default, $default)['label'];
    }

    /**
     * What the screen shows (the template adds the person and the menu).
     *
     * @param array<string, mixed> $current the theme settings now
     * @param array<string, mixed> $query the address's query (`part`, `term`, `saved`, `fail`)
     * @return array<string, mixed>
     */
    public function view(string $key, array $current, array $query): array
    {
        $default = ($this->defaultLanguage)();
        $part = ($query['part'] ?? '') === 'card' ? 'card' : 'page';
        $stored = ArchiveTemplates::entry($current, $key);
        $starting = $this->starting->sets($key);
        $empty = ($part === 'card' ? ArchiveTemplates::card($current, $key, $default, $default) : ArchiveTemplates::blocks($current, $key, $default, $default)) === [];
        $starts = $empty && ($starting[$part] ?? []) !== [];
        $blocks = $starts ? FooterBlocks::toStore($starting[$part]) : ($part === 'card' ? $stored['card'] : $stored['blocks']);
        $isTaxonomy = str_starts_with($key, 'taxonomy:');
        $extra = [];
        if ($part === 'card') {
            // A card shows the fields of the entry it draws: those of the content type, or what every entry has on a page of several types.
            $sources = $isTaxonomy ? EntryBindings::commonSources() : EntryBindings::sources($this->types, $key, $default, $default);
            $extra = ['bindings' => ['type' => $isTaxonomy ? '' : $key, 'sources' => $sources, 'accepts' => EntryBindings::ACCEPTS]];
            $definitions = $this->registry->editorDefinitions(false);
        } else {
            $definitions = $this->registry->editorDefinitions('archive', [], $this->pages->orders($key, $default));
        }
        $choices = $isTaxonomy ? $this->termChoices(substr($key, 9), $default) : [];
        $chosen = (string)($query['term'] ?? '');
        if (!in_array($chosen, array_column($choices, 'value'), true)) {
            $chosen = (string)($choices[0]['value'] ?? '');
        }
        return [
            'saved' => isset($query['saved']),
            'failed' => isset($query['fail']),
            'key' => $key,
            'part' => $part,
            'list_label' => $this->label($key),
            'is_taxonomy' => $isTaxonomy,
            'template_starts' => $starts,
            'template_use' => $stored['use'] || ArchiveTemplates::blocks($current, $key, $default, $default) === [],
            'page_blocks' => count(FooterBlocks::forLanguage($stored['blocks'], 'default', 'default')),
            'card_blocks' => count(FooterBlocks::forLanguage($stored['card'], 'default', 'default')),
            'preview_choices' => $choices,
            'preview_chosen' => $chosen,
            'template_json' => $this->sets->editorJson($default, $blocks, 'template', $definitions, $extra),
        ];
    }

    /**
     * The theme settings to store after the form was sent: the part the editor sent (without the editor's data the blocks stay as they
     * are), and the switch.
     *
     * @param array<string, mixed> $current the theme settings now
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function saved(string $key, array $current, array $post): array
    {
        $part = ($post['part'] ?? '') === 'card' ? 'card' : 'page';
        $posted = $this->postedSets($key, $part, $current, $post);
        $page = $part === 'page' ? $posted : null;
        // A card saved before the page has its page: the list is drawn from the template only when it has blocks, so it starts from the default one.
        if ($part === 'card' && $posted !== null && ArchiveTemplates::entry($current, $key)['blocks'] === []) {
            $page = $this->starting->sets($key)['page'] ?: null;
        }
        $templates = ArchiveTemplates::with($current, $key, isset($post['use']), $page, $part === 'card' ? $posted : null);
        if ($templates === []) {
            unset($current[ArchiveTemplates::KEY]);
        } else {
            $current[ArchiveTemplates::KEY] = $templates;
        }
        return $current;
    }

    /**
     * What a card of the Archive Layouts tab sets for a list that has none yet when it is switched to its template.
     *
     * @return array{page: array<string, array<int, mixed>>, card: array<string, array<int, mixed>>}
     */
    public function startingSets(string $key): array
    {
        return $this->starting->sets($key);
    }

    /**
     * The theme settings after *Start again from the default*: the part shown is the list's starting one, switched on. The blocks it had
     * are replaced for every language; the other part is left as it is.
     *
     * @param array<string, mixed> $current the theme settings now
     * @return array<string, mixed>
     */
    public function startedAgain(string $key, string $part, array $current): array
    {
        $sets = $this->starting->sets($key);
        $part = $part === 'card' ? 'card' : 'page';
        if ($sets[$part] !== []) {
            $current[ArchiveTemplates::KEY] = ArchiveTemplates::with($current, $key, true, $part === 'page' ? $sets['page'] : null, $part === 'card' ? $sets['card'] : null);
        }
        return $current;
    }

    /**
     * What the live preview draws: the public address of the list and the theme settings with the unsaved blocks (nothing is stored);
     * null when there is nothing to draw (a taxonomy with no term).
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $post
     * @return array{path: string, part: string, settings: array<string, mixed>}|null
     */
    public function preview(string $key, array $current, array $post): ?array
    {
        $default = ($this->defaultLanguage)();
        $part = ($post['part'] ?? '') === 'card' ? 'card' : 'page';
        if (str_starts_with($key, 'taxonomy:')) {
            $name = substr($key, 9);
            $termId = (string)($post['preview_term'] ?? '');
            $known = in_array($termId, array_map(static fn(array $term): string => (string)$term['id'], $this->taxonomies->load($name)['terms']), true);
            $slug = $known ? $this->taxonomies->slug($name, $termId) : '';
            if ($slug === '') {
                return null;
            }
            $path = Taxonomies::kind($name) . '/' . $slug;
        } else {
            $path = ContentPaths::archive($key, $default, $default);
        }
        $posted = $this->postedSets($key, $part, $current, $post);
        $templates = ArchiveTemplates::with($current, $key, true, $part === 'page' ? $posted : null, $part === 'card' ? $posted : null);
        return ['path' => $path, 'part' => $part, 'settings' => $this->theme->resolveSettings([ArchiveTemplates::KEY => $templates] + $current)];
    }

    /** @return list<array{value: string, label: string}> */
    private function termChoices(string $name, string $lang): array
    {
        $choices = [];
        foreach (array_slice($this->taxonomies->load($name)['terms'], 0, self::CHOICES) as $term) {
            $choices[] = ['value' => (string)$term['id'], 'label' => $this->taxonomies->label($name, (string)$term['id'], $lang)];
        }
        return $choices;
    }

    /** @return array<string, array<int, mixed>>|null null when the form carries no editor data */
    private function postedSets(string $key, string $part, array $current, array $post): ?array
    {
        $posted = ($post['blocks_editor'] ?? '') === '1' ? json_decode((string)($post['blocks_json'] ?? ''), true) : null;
        $stored = ArchiveTemplates::entry($current, $key);
        return is_array($posted) ? $this->sets->clean($posted, $part === 'card' ? $stored['card'] : $stored['blocks']) : null;
    }
}
