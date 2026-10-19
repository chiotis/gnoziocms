<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * References between entries (the `relation` field, see RelationFields): who an entry points at, who points at it, and the choices
 * an editor is offered. It reads the content index (a small card of each entry, and `content_refs`), never the files of the
 * entries it lists, and answers the same without the index: an entry that is not in it is simply not found.
 *
 * What is shown follows the public rule: an entry is listed when it is published and visible, in the language asked for or else the
 * site's own. Editors who preview ask for hidden ones too, which carry `hidden: true`.
 */
final class Relations
{
    /** @var string[]|null */
    private ?array $enabled = null;

    private bool $checked = false;

    /** @param array<string, mixed> $settings site settings (languages, home page) */
    public function __construct(
        private ContentIndex $index,
        private ContentRepository $content,
        private ContentTypes $types,
        private array $settings,
        private string $contentDir,
        private ?RevisionRepository $revisions = null
    ) {
    }

    /** Looks (at most every half minute) for files changed outside the admin before the index is asked anything. */
    private function fresh(): void
    {
        if (!$this->checked) {
            $this->checked = true;
            $this->index->ensureFresh($this->content, $this->enabledTypes());
        }
    }

    private function defaultLang(): string
    {
        return (string)($this->settings['languages']['default'] ?? 'en');
    }

    /** @return string[] */
    private function enabledTypes(): array
    {
        return $this->enabled ??= $this->content->getTypes();
    }

    /**
     * The entries behind these ids, in the order given.
     *
     * @param string[] $ids
     * @param string[] $types content types allowed (none: any that is on)
     * @return list<array<string, mixed>> id, type, slug, lang, title, excerpt, main_image, date, url, hidden
     */
    public function resolve(array $ids, array $types, string $lang, bool $includeHidden = false): array
    {
        $this->fresh();
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => preg_match(RelationFields::ID, $id) === 1)));
        if ($ids === []) {
            return [];
        }
        $allowed = $types !== [] ? array_values(array_intersect($types, $this->enabledTypes())) : $this->enabledTypes();
        if ($allowed === []) {
            return [];
        }
        $byId = [];
        foreach ($this->index->entriesById($ids, $allowed) as $row) {
            $byId[(string)$row['translation_id']][(string)$row['lang']] = $row;
        }
        $items = [];
        foreach ($ids as $id) {
            $row = $this->pick($byId[$id] ?? [], $lang, $includeHidden);
            $card = $row !== null ? $this->card($row) : null;
            if ($card !== null && ($includeHidden || !$card['hidden'])) {
                $items[] = $card;
            }
        }
        return $items;
    }

    /**
     * What an entry points at through a relation field (a field of the type itself; for one inside a row of a list, give the
     * stored ids to resolve()).
     *
     * @return list<array<string, mixed>>
     */
    public function related(ContentItem $item, string $field, string $lang, bool $includeHidden = false): array
    {
        $definition = $this->types->definition($item->type, 'en', $this->defaultLang())['fields'][$field] ?? null;
        if ($definition === null || ($definition['type'] ?? '') !== 'relation') {
            return [];
        }
        $custom = is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
        return $this->resolve(RelationFields::idList(FieldSchema::clean($definition, $custom[$field] ?? null)), $definition['types'], $lang, $includeHidden);
    }

    /**
     * The entries of a content type that point at `$target` through one of their relation fields (`author`, or `related.book` for a
     * field inside a list), newest first. Each is the version in `$lang` (else the site's own) and counts only when that version
     * holds the reference.
     *
     * @return list<array<string, mixed>>
     */
    public function referencedBy(ContentItem $target, string $fromType, string $field, string $lang, bool $includeHidden = false): array
    {
        return $this->referencedById(trim((string)($target->meta['translation_id'] ?? '')), $fromType, $field, $lang, $includeHidden);
    }

    /** The same by the id of the entry pointed at (what a fact sheet has: the front matter). @return list<array<string, mixed>> */
    public function referencedById(string $id, string $fromType, string $field, string $lang, bool $includeHidden = false): array
    {
        $this->fresh();
        if ($id === '' || !in_array($fromType, $this->enabledTypes(), true)) {
            return [];
        }
        $held = [];
        $slugs = [];
        foreach ($this->index->referencesTo($id, $fromType, $field) as $ref) {
            $held[$ref['from_slug'] . '|' . $ref['from_lang']] = true;
            $slugs[$ref['from_slug']] = [$fromType, (string)$ref['from_slug']];
        }
        if ($slugs === []) {
            return [];
        }
        $rows = [];
        foreach ($this->index->entriesByAddress(array_values($slugs), array_values(array_unique([$lang, $this->defaultLang()]))) as $row) {
            $rows[(string)$row['slug']][(string)$row['lang']] = $row;
        }
        $items = [];
        foreach ($rows as $slug => $versions) {
            $row = $this->pick($versions, $lang);
            if ($row === null || !isset($held[$slug . '|' . $row['lang']])) {
                continue;
            }
            $card = $this->card($row);
            if ($card !== null && ($includeHidden || !$card['hidden'])) {
                $items[] = $card;
            }
        }
        usort($items, static function (array $a, array $b): int {
            return (strtotime((string)$b['date']) ?: 0) <=> (strtotime((string)$a['date']) ?: 0) ?: strcasecmp((string)$a['title'], (string)$b['title']);
        });
        return $items;
    }

    /** The entry a card stands for, read from its file (for a template that shows a field of it); null when it is gone. @param array<string, mixed> $card */
    public function entry(array $card): ?ContentItem
    {
        return $this->content->find((string)$card['type'], (string)$card['slug'], (string)$card['lang'], false, false);
    }

    /**
     * The row to show for an entry: the language asked for, else the site's own (else, for an editor, any language it has).
     *
     * @param array<string, array<string, mixed>> $versions language => index row
     * @return array<string, mixed>|null
     */
    private function pick(array $versions, string $lang, bool $any = false): ?array
    {
        return $versions[$lang] ?? $versions[$this->defaultLang()] ?? ($any && $versions !== [] ? reset($versions) : null);
    }

    /** @param array<string, mixed> $row @return array<string, mixed>|null */
    private function card(array $row): ?array
    {
        $summary = json_decode((string)($row['summary'] ?? ''), true);
        $summary = is_array($summary) ? $summary : [];
        $type = (string)$row['type'];
        $slug = (string)$row['slug'];
        $lang = (string)$row['lang'];
        $home = (string)(($this->settings['home_page'] ?? '') !== '' ? $this->settings['home_page'] : 'index');
        $public = strtolower((string)$row['status']) === 'published' && (int)$row['visible'] === 1;
        return [
            'id' => (string)$row['translation_id'],
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'title' => (string)($summary['title'] ?? $row['title'] ?? $slug),
            'excerpt' => (string)($summary['excerpt'] ?? ''),
            'main_image' => (string)($summary['main_image'] ?? ''),
            'date' => (string)($summary['date'] ?? ''),
            'url' => '/' . ContentPaths::build($type, $slug, $lang, $home, $this->defaultLang()),
            'status' => strtolower((string)$row['status']),
            'hidden' => !$public,
        ];
    }

    /**
     * Turns the `type/slug` addresses typed in a form (or a CSV cell) in a type's fields into ids, so only ids are stored. An entry that
     * has no id yet gets one written to its file(s) when `$assign` is true, which is the only change made to an entry that is pointed at;
     * an address that does not exist is dropped.
     *
     * @param array<string, mixed> $values by field key (a form's `details`, or the custom_fields of a row)
     * @return array<string, mixed>
     */
    public function normalize(string $type, array $values, bool $assign = true, array $known = []): array
    {
        $fields = $this->types->definition($type, 'en', $this->defaultLang())['fields'];
        if (RelationFields::paths($fields) === []) {
            return $values;
        }
        return RelationFields::convert($fields, $values, fn(string $token, array $allowed): string => $this->lookup($token, $allowed, $assign, $known));
    }

    /**
     * The same custom_fields with each reference written as the address of its entry (`books/my-book`), which a person can read and
     * which another site can resolve (CSV export). An id that leads nowhere stays an id.
     *
     * @param array<string, mixed> $meta the front matter of an entry
     * @return array<string, mixed>
     */
    public function withAddresses(string $type, array $meta): array
    {
        $fields = $this->types->definition($type, 'en', $this->defaultLang())['fields'];
        if (!is_array($meta['custom_fields'] ?? null) || RelationFields::paths($fields) === []) {
            return $meta;
        }
        $meta['custom_fields'] = RelationFields::transform($fields, $meta['custom_fields'], function (array $field, array $ids): mixed {
            $address = [];
            foreach ($ids as $id) {
                $versions = [];
                foreach ($this->index->entriesById([$id]) as $row) {
                    $versions[(string)$row['lang']] = $row;
                }
                $row = $this->pick($versions, $this->defaultLang()) ?? ($versions === [] ? null : reset($versions));
                $address[] = $row !== null ? $row['type'] . '/' . $row['slug'] : $id;
            }
            return ($field['multiple'] ?? false) ? $address : ($address[0] ?? '');
        });
        return $meta;
    }

    /**
     * The id of the entry at `type/slug` (the types a field allows are checked), giving it one if it has none.
     *
     * @param array<string, string> $known addresses of entries that are being created at the same time => their ids
     */
    public function lookup(string $address, array $allowed, bool $assign = true, array $known = []): string
    {
        if (!str_contains($address, '/')) {
            return '';
        }
        if (($known[$address] ?? '') !== '') {
            return $known[$address];
        }
        [$type, $slug] = explode('/', $address, 2);
        $slug = preg_replace('/\.[a-z]{2}$/', '', $slug) ?? $slug;
        if ($type === '' || $slug === '' || ($allowed !== [] && !in_array($type, $allowed, true)) || !in_array($type, $this->enabledTypes(), true)) {
            return '';
        }
        $langs = array_values(array_unique(array_merge([$this->defaultLang()], array_map('strval', (array)($this->settings['languages']['available'] ?? [])))));
        $rows = $this->index->entriesByAddress([[$type, $slug]], $langs);
        if ($rows === []) {
            return '';
        }
        foreach ($rows as $row) {
            if ((string)$row['translation_id'] !== '') {
                return (string)$row['translation_id'];
            }
        }
        return $assign ? $this->ensureId($type, $slug) : '';
    }

    /**
     * The id of the entry at an address, written into its files when it has none (every language that shares the address gets the
     * same one, as the editor does when it saves). Only a `translation_id` line is added; the text is kept in the history first.
     */
    public function ensureId(string $type, string $slug): string
    {
        $paths = new ContentPaths($this->settings);
        $langs = array_values(array_unique(array_merge([$this->defaultLang()], array_map('strval', (array)($this->settings['languages']['available'] ?? [])))));
        $files = [];
        $id = '';
        foreach ($langs as $lang) {
            $path = $this->contentDir . '/' . $type . '/' . $paths->filename($slug, $lang);
            if (!is_file($path)) {
                continue;
            }
            $files[$lang] = $path;
            if (preg_match('/\A---[ \t]*\R(.*?)\R---/s', (string)file_get_contents($path), $m) === 1 && preg_match('/^translation_id:\s*[\'"]?([A-Za-z0-9_-]+)/m', $m[1], $found) === 1) {
                $id = $id !== '' ? $id : $found[1];
            }
        }
        if ($files === []) {
            return '';
        }
        $id = $id !== '' ? $id : ContentEditor::newTranslationId();
        foreach ($files as $lang => $path) {
            $raw = (string)file_get_contents($path);
            if (preg_match('/\A---[ \t]*\R(.*?)\R---/s', $raw, $m) === 1 && preg_match('/^translation_id:/m', $m[1]) === 1) {
                continue;
            }
            $this->revisions?->baseline($type, $slug, $lang, $path, 'relations');
            $updated = preg_match('/\A---[ \t]*\R/', $raw) === 1
                ? (string)preg_replace('/\A(---[ \t]*\R)/', '$1translation_id: ' . $id . "\n", $raw, 1)
                : "---\ntranslation_id: " . $id . "\n---\n" . $raw;
            if (@file_put_contents($path, $updated) === false) {
                continue;
            }
            $this->content->forget();
            try {
                $this->index->upsert($this->content->parseFile($type, $path));
            } catch (\Throwable) {
                // The index is rebuildable; a failed write only makes it stale.
            }
        }
        return $id;
    }

    /**
     * The same definitions with the entries the form already holds, so the editor can draw a chip for each: every relation field (also
     * inside rows) gets `labels`, a map of id => {title, type, status, hidden, edit_url}. An id that leads nowhere is not in it.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values the checked values of the type's fields (key => value)
     * @return array<string, array<string, mixed>>
     */
    public function withLabels(array $fields, array $values, string $lang): array
    {
        if (RelationFields::paths($fields) === []) {
            return $fields;
        }
        $this->fresh();
        $ids = array_column(RelationFields::extract($fields, $values), 'id');
        $labels = [];
        foreach ($this->resolve($ids, [], $lang, true) as $card) {
            $labels[$card['id']] = ['title' => $card['title'], 'type' => $card['type'], 'status' => $card['status'], 'hidden' => $card['hidden'], 'edit_url' => '/admin/edit?' . http_build_query(['type' => $card['type'], 'slug' => $card['slug'], 'lang' => $card['lang']])];
        }
        return $this->attach($fields, $labels);
    }

    /** @param array<string, array<string, mixed>> $fields @param array<string, mixed> $labels @return array<string, array<string, mixed>> */
    private function attach(array $fields, array $labels): array
    {
        foreach ($fields as $key => $field) {
            if (($field['type'] ?? '') === 'repeater') {
                $fields[$key]['fields'] = $this->attach($field['fields'] ?? [], $labels);
            } elseif (($field['type'] ?? '') === 'relation') {
                $fields[$key]['labels'] = $labels;
            }
        }
        return $fields;
    }

    /**
     * Entries to choose from for a search box: the entries of the types given (limited to the ones the field allows) whose title or
     * address contains the text, the ones starting with it first; with no text, the first by title.
     *
     * @param string[] $types content types asked for, which must be among `$allowed` (none: all of them)
     * @param string[] $allowed the content types the person may open
     * @return list<array<string, mixed>> value (the id, or `@type/slug` while the entry has none), title, type, status, hidden
     */
    public function search(string $query, array $types, array $allowed, int $limit = 20): array
    {
        $this->fresh();
        $types = array_values(array_intersect($types !== [] ? $types : $allowed, $allowed, $this->enabledTypes()));
        $found = [];
        foreach ($this->index->choices($types, $this->defaultLang(), max(1, min(50, $limit)), $query) as $row) {
            $id = (string)$row['translation_id'];
            $found[] = [
                'value' => $id !== '' ? $id : '@' . $row['type'] . '/' . $row['slug'],
                'title' => (string)$row['title'],
                'type' => (string)$row['type'],
                'status' => (string)$row['status'],
                'hidden' => !(strtolower((string)$row['status']) === 'published' && (int)$row['visible'] === 1),
                'edit_url' => '/admin/edit?' . http_build_query(['type' => $row['type'], 'slug' => $row['slug'], 'lang' => $row['lang']]),
            ];
        }
        return $found;
    }

    /**
     * Who points at an entry, from any type, for the screens that warn before something is taken away: one line for each entry
     * (its language version that holds the reference), with the fields it uses.
     *
     * @return list<array{type: string, slug: string, lang: string, title: string, status: string, fields: list<string>, edit_url: string}>
     */
    public function referrers(string $type, string $slug): array
    {
        $this->fresh();
        $id = '';
        foreach ($this->index->entriesByAddress([[$type, $slug]], array_values(array_unique(array_merge([$this->defaultLang()], array_map('strval', (array)($this->settings['languages']['available'] ?? [])))))) as $row) {
            $id = $id !== '' ? $id : (string)$row['translation_id'];
        }
        $lines = [];
        foreach ($this->index->referrers($id) as $row) {
            $key = $row['type'] . '|' . $row['slug'] . '|' . $row['lang'];
            $lines[$key] ??= ['type' => (string)$row['type'], 'slug' => (string)$row['slug'], 'lang' => (string)$row['lang'], 'title' => (string)($row['title'] ?? $row['slug']), 'status' => (string)($row['status'] ?? ''), 'fields' => [], 'edit_url' => '/admin/edit?' . http_build_query(['type' => $row['type'], 'slug' => $row['slug'], 'lang' => $row['lang']])];
            $lines[$key]['fields'][] = (string)$row['field'];
        }
        return array_values($lines);
    }

    /** References to entries that no longer exist: [{type, slug, lang, title, field, edit_url}]. @return list<array<string, string>> */
    public function broken(int $limit = 500): array
    {
        $this->fresh();
        return array_map(static fn(array $row): array => [
            'type' => (string)$row['type'], 'slug' => (string)$row['slug'], 'lang' => (string)$row['lang'], 'title' => (string)($row['title'] ?? $row['slug']), 'field' => (string)$row['field'],
            'edit_url' => '/admin/edit?' . http_build_query(['type' => $row['type'], 'slug' => $row['slug'], 'lang' => $row['lang']]),
        ], $this->index->brokenReferences($limit));
    }
}
