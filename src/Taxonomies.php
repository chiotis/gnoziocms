<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Categories, tags, and any other taxonomy: the terms content can be filed under, and how the page of each taxonomy lists them.
 *
 * A taxonomy is a file in content/taxonomies/<name>.yaml:
 *   title:    what the admin calls it
 *   hierarchical: whether a term can have a parent (categories do by default, tags and a site's own taxonomies do not);
 *             written only when it differs from that default
 *   terms:    id (what content refers to, never changes), slug (the address, may change), labels per language, an
 *             optional description per language (kept only when one is written) and an optional `parent` (the id of
 *             another term, hierarchical taxonomies only), and `imported_from` when an import brought it (the address of
 *             the term on the old site, kept so the import finds it again). A term's address is its parents' slugs and its own:
 *             /category/books/english. A slug is unique among the terms of one parent.
 *   archive:  only the layout choices that differ from the defaults, the same ones a content type has
 *             (layout, columns, per_page, order, show_*, title, subtitle, taxonomies), plus `types`: the
 *             content types listed on its pages (none chosen means all of them), `include_children` and
 *             `sub_term_chips` (both on unless written false).
 *
 * The defaults of a taxonomy whose pages list only types with a place (a field of the kind `location`, see placeTypes()) have
 * the Map layout: its terms (an activity, a kind of point) are pages of places. Every other taxonomy, categories and tags
 * included (they list every type), keeps the defaults of a content type.
 */
final class Taxonomies
{
    /** @var array<string, array{title: string, hierarchical: bool, terms: array<int, array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>}>, archive: array<string, mixed>}> */
    private array $cache = [];

    /** @var (\Closure(): string[])|null the content types whose entries can have a place */
    private ?\Closure $placeTypes = null;

    /** @param string[] $languages every language of the site */
    public function __construct(private string $contentDir, private array $languages)
    {
    }

    /**
     * Tells the store which content types have a place, so the pages of a taxonomy of places open as a map by default.
     *
     * @param \Closure(): string[] $placeTypes
     */
    public function withPlaceTypes(\Closure $placeTypes): self
    {
        $this->placeTypes = $placeTypes;
        return $this;
    }

    /**
     * What the archive of a taxonomy has before the site chose anything: the Map layout when its pages list only types with a
     * place, nothing otherwise (the defaults of a content type).
     *
     * @param string[]|null $types the types its pages list; null: as its file says
     * @return array<string, mixed>
     */
    public function archiveDefaults(string $name, ?array $types = null): array
    {
        if ($this->placeTypes === null) {
            return [];
        }
        $types ??= self::typeList($this->load($name)['archive']['types'] ?? null);
        return $types !== [] && array_diff($types, ($this->placeTypes)()) === [] ? ['layout' => 'map'] : [];
    }

    private function dir(): string
    {
        return $this->contentDir . '/taxonomies';
    }

    /**
     * The word in the address of a taxonomy's pages: "category" for categories (/category/news), "tag" for tags, and the
     * name of any other taxonomy as it is (/project-types/web).
     */
    public static function kind(string $name): string
    {
        return match ($name) {
            'categories' => 'category',
            'tags' => 'tag',
            default => $name,
        };
    }

    /** The taxonomy an address word belongs to (category, categories, tag, tags, or the name of another), or null. */
    public function fromWord(string $word): ?string
    {
        return match ($word) {
            'category', 'categories' => 'categories',
            'tag', 'tags' => 'tags',
            default => in_array($word, $this->names(), true) ? $word : null,
        };
    }

    /** True for a taxonomy a site added (not categories or tags). */
    public static function isCustom(string $name): bool
    {
        return !in_array($name, ['categories', 'tags'], true);
    }

    /** @return string[] */
    public function names(): array
    {
        $names = ['tags', 'categories'];
        foreach (glob($this->dir() . '/*.yaml') ?: [] as $path) {
            $name = Slug::plain(basename($path, '.yaml'));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** Writes the two built-in taxonomies with a few example terms when a site has none yet. */
    public function ensureDefaults(): void
    {
        $defaults = [
            'tags' => [
                'title' => 'Tags',
                'terms' => [
                    ['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']],
                    ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']],
                ],
            ],
            'categories' => [
                'title' => 'Categories',
                'terms' => [
                    ['id' => 'announcements', 'slug' => 'announcements', 'labels' => ['el' => 'Ανακοινώσεις', 'en' => 'Announcements']],
                    ['id' => 'insights', 'slug' => 'insights', 'labels' => ['el' => 'Ιδέες', 'en' => 'Insights']],
                ],
            ],
        ];
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0775, true);
        }
        foreach ($defaults as $name => $payload) {
            if (!is_file($this->dir() . '/' . $name . '.yaml')) {
                $this->save($name, $payload['title'], $payload['terms']);
            }
        }
    }

    /** Whether the terms of a taxonomy can have a parent when its file does not say: categories can, tags and a site's own taxonomies cannot. */
    public static function hierarchicalByDefault(string $name): bool
    {
        return $name === 'categories';
    }

    public function isHierarchical(string $name): bool
    {
        return $this->load($name)['hierarchical'];
    }

    /** @return array{title: string, hierarchical: bool, terms: array<int, array{id: string, slug: string, parent: string, labels: array<string, string>, descriptions: array<string, string>, hero_layout: string, imported_from: string}>, archive: array<string, mixed>} */
    public function load(string $name): array
    {
        $name = Slug::plain($name) ?: 'tags';
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }
        $data = [];
        $path = $this->dir() . '/' . $name . '.yaml';
        if (is_file($path)) {
            try {
                $parsed = Yaml::parseFile($path);
            } catch (\Throwable) {
                $parsed = [];
            }
            $data = is_array($parsed) ? $parsed : [];
        }
        $title = trim((string)($data['title'] ?? ''));
        $hierarchical = array_key_exists('hierarchical', $data) ? FieldSchema::isTruthy($data['hierarchical']) : self::hierarchicalByDefault($name);
        return $this->cache[$name] = [
            'title' => $title !== '' ? $title : Slug::title($name),
            'hierarchical' => $hierarchical,
            'terms' => $this->normalizeTerms($data['terms'] ?? [], $hierarchical),
            'archive' => is_array($data['archive'] ?? null) ? $data['archive'] : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     * @param array<string, mixed>|null $archive the layout choices to keep; null leaves what the file holds
     * @param bool|null $hierarchical whether terms can have a parent; null leaves what the file holds
     */
    public function save(string $name, string $title, array $terms, ?array $archive = null, ?bool $hierarchical = null): void
    {
        $name = Slug::plain($name);
        if ($name === '') {
            return;
        }
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0775, true);
        }
        $hierarchical ??= $this->load($name)['hierarchical'];
        $payload = [
            'title' => trim($title) !== '' ? trim($title) : Slug::title($name),
        ];
        if ($hierarchical !== self::hierarchicalByDefault($name)) {
            $payload['hierarchical'] = $hierarchical;
        }
        $payload += [
            'terms' => array_map(static function (array $term): array {
                if (($term['parent'] ?? '') === '') {
                    unset($term['parent']);
                }
                // A description is written only when there is one, so files stay as short as they were.
                if (implode('', $term['descriptions']) === '') {
                    unset($term['descriptions']);
                }
                if ($term['hero_layout'] === '') {
                    unset($term['hero_layout']);
                }
                if ($term['imported_from'] === '') {
                    unset($term['imported_from']);
                }
                return $term;
            }, $this->normalizeTerms($terms, $hierarchical)),
        ];
        $archive ??= $this->load($name)['archive'];
        if ($archive !== []) {
            $payload['archive'] = $archive;
        }
        file_put_contents($this->dir() . '/' . $name . '.yaml', Yaml::dump($payload, 6, 2));
        $this->cache = [];
    }

    /** Removes a taxonomy the site added (never categories or tags). True when its file is gone. */
    public function delete(string $name): bool
    {
        $name = Slug::plain($name);
        if ($name === '' || !self::isCustom($name)) {
            return false;
        }
        $path = $this->dir() . '/' . $name . '.yaml';
        $gone = !is_file($path) || @unlink($path);
        $this->cache = [];
        return $gone;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** @return array<int, array{id: string, slug: string, parent: string, labels: array<string, string>, descriptions: array<string, string>, hero_layout: string, imported_from: string}> */
    private function normalizeTerms(mixed $terms, bool $hierarchical): array
    {
        if (!is_array($terms)) {
            return [];
        }
        $rows = [];
        $seen = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $id = Slug::plain((string)($term['id'] ?? ''));
            $slug = Slug::plain((string)($term['slug'] ?? ''));
            $id = $id !== '' ? $id : $slug;
            $slug = $slug !== '' ? $slug : $id;
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $source = is_array($term['labels'] ?? null) ? $term['labels'] : [];
            $texts = is_array($term['descriptions'] ?? null) ? $term['descriptions'] : [];
            $labels = [];
            $descriptions = [];
            foreach ($this->languages as $lang) {
                $labels[(string)$lang] = trim((string)($source[(string)$lang] ?? ''));
                $descriptions[(string)$lang] = trim(str_replace("\r\n", "\n", (string)($texts[(string)$lang] ?? '')));
            }
            $parent = $hierarchical ? Slug::plain((string)($term['parent'] ?? '')) : '';
            $rows[] = ['id' => $id, 'slug' => $slug, 'parent' => $parent, 'labels' => $labels, 'descriptions' => $descriptions, 'hero_layout' => self::heroChoice($term['hero_layout'] ?? ''), 'imported_from' => is_string($term['imported_from'] ?? null) ? trim($term['imported_from']) : ''];
            $seen[$id] = true;
        }
        return $hierarchical ? self::untangle($rows) : $rows;
    }

    /**
     * A parent that is not a term, the term itself, or one that would make a loop is dropped, so a hand-edited file
     * can never send an address round in circles.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function untangle(array $rows): array
    {
        $parents = [];
        foreach ($rows as $row) {
            $parents[$row['id']] = $row['parent'];
        }
        foreach ($rows as $i => $row) {
            if ($row['parent'] === '') {
                continue;
            }
            $seen = [$row['id'] => true];
            $at = $row['parent'];
            while ($at !== '') {
                if (isset($seen[$at]) || !array_key_exists($at, $parents)) {
                    $rows[$i]['parent'] = '';
                    $parents[$row['id']] = '';
                    break;
                }
                $seen[$at] = true;
                $at = $parents[$at];
            }
        }
        return $rows;
    }

    /** The slugs from the top of the tree down to a term, from terms keyed by id; null when the term is not there. @param array<string, array<string, mixed>> $byId @return string[]|null */
    private static function slugChain(array $byId, string $id): ?array
    {
        $chain = [];
        for ($guard = 0; $id !== '' && $guard < 50; $guard++) {
            if (!isset($byId[$id])) {
                return null;
            }
            array_unshift($chain, (string)$byId[$id]['slug']);
            $id = (string)($byId[$id]['parent'] ?? '');
        }
        return $chain;
    }

    /**
     * The term at an address inside a taxonomy: "english" at the top level, "books/english" for a child. Null when
     * no term sits there (a taxonomy without a tree has no address longer than one slug).
     *
     * @return array{id: string, slug: string, parent: string, labels: array<string, string>, descriptions: array<string, string>, hero_layout: string}|null
     */
    public function findByPath(string $name, string $path): ?array
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $p): bool => $p !== ''));
        if ($parts === []) {
            return null;
        }
        $parent = '';
        $found = null;
        foreach ($parts as $part) {
            $slug = Slug::plain($part);
            $found = null;
            foreach ($this->load($name)['terms'] as $term) {
                if ($term['slug'] === $slug && $term['parent'] === $parent) {
                    $found = $term;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $parent = $found['id'];
        }
        return $found;
    }

    /** The terms above a term, the top one first (none for a term at the top). @return array<int, array<string, mixed>> */
    public function ancestors(string $name, string $termId): array
    {
        $byId = [];
        foreach ($this->load($name)['terms'] as $term) {
            $byId[$term['id']] = $term;
        }
        $chain = [];
        $at = (string)($byId[Slug::plain($termId)]['parent'] ?? '');
        for ($guard = 0; $at !== '' && isset($byId[$at]) && $guard < 50; $guard++) {
            array_unshift($chain, $byId[$at]);
            $at = $byId[$at]['parent'];
        }
        return $chain;
    }

    /** The ids of every term below a term, children first level by level. @return string[] */
    public function descendantIds(string $name, string $termId): array
    {
        $terms = $this->load($name)['terms'];
        $found = [];
        $level = [Slug::plain($termId)];
        while ($level !== []) {
            $next = [];
            foreach ($terms as $term) {
                if ($term['parent'] !== '' && in_array($term['parent'], $level, true) && !in_array($term['id'], $found, true)) {
                    $found[] = $term['id'];
                    $next[] = $term['id'];
                }
            }
            $level = $next;
        }
        return $found;
    }

    /** The terms right below a term (the top ones for ''), in the order of the file. @return array<int, array<string, mixed>> */
    public function children(string $name, string $termId): array
    {
        $parent = $termId === '' ? '' : Slug::plain($termId);
        return array_values(array_filter($this->load($name)['terms'], static fn(array $term): bool => $term['parent'] === $parent));
    }

    /**
     * The terms in the order a tree is read: each term followed by its children, siblings in the order of the file.
     * Every row gets a `depth` (0 at the top).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(string $name): array
    {
        $terms = $this->load($name)['terms'];
        $children = [];
        foreach ($terms as $term) {
            $children[$term['parent']][] = $term;
        }
        $out = [];
        $walk = static function (string $parent, int $depth) use (&$walk, &$out, $children): void {
            foreach ($children[$parent] ?? [] as $term) {
                $out[] = $term + ['depth' => $depth];
                $walk($term['id'], $depth + 1);
            }
        };
        $walk('', 0);
        return $out;
    }

    /** The term at an address, where a child's address holds its parents' slugs ("books/english"). @return array{id: string, slug: string, parent: string, labels: array<string, string>, descriptions: array<string, string>, hero_layout: string}|null */
    public function findBySlug(string $name, string $slug): ?array
    {
        return $this->findByPath($name, $slug);
    }

    public function label(string $name, string $termId, string $lang): string
    {
        $termId = Slug::plain($termId);
        if ($termId === '') {
            return '';
        }
        foreach ($this->load($name)['terms'] as $term) {
            if ($term['id'] !== $termId) {
                continue;
            }
            $label = trim((string)($term['labels'][$lang] ?? ''));
            if ($label !== '') {
                return $label;
            }
            foreach ($term['labels'] as $candidate) {
                if (trim((string)$candidate) !== '') {
                    return trim((string)$candidate);
                }
            }
            break;
        }
        return Slug::title($termId);
    }

    /** The description of a term in a language, or in the default language when this one has none; empty when there is none. */
    public function description(string $name, string $termId, string $lang, string $defaultLang): string
    {
        $termId = Slug::plain($termId);
        foreach ($this->load($name)['terms'] as $term) {
            if ($term['id'] === $termId) {
                $text = (string)($term['descriptions'][$lang] ?? '');
                return $text !== '' ? $text : (string)($term['descriptions'][$defaultLang] ?? '');
            }
        }
        return '';
    }

    /** A title area a term asks for, as a word of the theme's list (checked where it is used); '' for none. */
    private static function heroChoice(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        return preg_match('/^[a-z][a-z0-9_-]{0,23}$/', $value) === 1 ? $value : '';
    }

    /** The style of title area the entries filed under a term open with (their own choice still wins), or '' to follow their type. */
    public function heroLayout(string $name, string $termId): string
    {
        $termId = Slug::plain($termId);
        foreach ($this->load($name)['terms'] as $term) {
            if ($term['id'] === $termId) {
                return $term['hero_layout'];
            }
        }
        return '';
    }

    /** The address of a term after the taxonomy's word: its slug, with its parents' slugs in front ("books/english"). */
    public function slug(string $name, string $termId): string
    {
        $termId = Slug::plain($termId);
        if ($termId === '') {
            return '';
        }
        $byId = [];
        foreach ($this->load($name)['terms'] as $term) {
            $byId[$term['id']] = $term;
        }
        $chain = self::slugChain($byId, $termId);
        return $chain === null ? $termId : implode('/', $chain);
    }

    /** Whether the page of a term also lists what is filed under its sub-terms (the taxonomy's archive choice, on by default). */
    public function includesChildren(string $name): bool
    {
        $raw = $this->load($name)['archive'];
        return $this->isHierarchical($name) && FieldSchema::isTruthy($raw['include_children'] ?? true);
    }

    /**
     * The archive settings of a taxonomy, with defaults for everything its file leaves out.
     *
     * @return array<string, mixed> the same keys as a content type's archive, and `types`
     */
    public function archive(string $name, string $lang, string $defaultLang): array
    {
        $raw = $this->load($name)['archive'];
        $values = ContentTypes::resolveArchive($raw + $this->archiveDefaults($name), static fn(mixed $v): mixed => ContentTypes::pick($v, $lang, $defaultLang));
        $values['types'] = self::typeList($raw['types'] ?? null);
        $values['include_children'] = FieldSchema::isTruthy($raw['include_children'] ?? true);
        // A row of chips under the title of a term's page, one for each sub-term (taxonomies with a tree only).
        $values['sub_term_chips'] = FieldSchema::isTruthy($raw['sub_term_chips'] ?? true);
        return $values;
    }

    /** @return string[] */
    public static function typeList(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', is_array($value) ? $value : []),
            static fn(string $t): bool => (bool)preg_match('/^[a-z][a-z0-9_-]*$/', $t)
        )));
    }

    /**
     * What to save for the terms of a submitted form. The address of a new term is made from its label (Greek
     * converted to Latin) unless one was typed; a term whose address field is empty keeps the one it has. A slug is
     * unique among the terms of one parent, so "english" can exist under two different parents.
     *
     * A parent that would make a loop is refused (the term keeps the parent it had). A term whose parent was removed
     * moves up to that parent's parent. A move changes the address of the term and of everything below it, so every
     * one of them is in `moved`, with the path before and after (the slugs from the top of the tree).
     *
     * @param string $name the taxonomy
     * @param array<int, array{id: string, slug: string, parent?: string|null, labels: array<string, string>, descriptions?: array<string, string>|null, hero_layout?: string|null}> $rows in form order, `id` empty for a new term; `descriptions`, `hero_layout` and `parent` left out (or null) keep what the term has
     * @param bool|null $hierarchical whether terms can have a parent after this save; null: as the taxonomy is now
     * @return array{terms: array<int, array{id: string, slug: string, parent: string, labels: array<string, string>, descriptions: array<string, string>, hero_layout: string}>, moved: array<int, array{id: string, from: string, to: string}>, removed: string[], added: string[], refused: string[]}
     */
    public function prepare(string $name, array $rows, string $defaultLang, ?bool $hierarchical = null): array
    {
        $hierarchical ??= $this->isHierarchical($name);
        $before = [];
        foreach ($this->load($name)['terms'] as $term) {
            $before[$term['id']] = $term;
        }
        $drafts = [];
        $usedIds = [];
        // Terms that already exist claim their id first, so a new term never takes one of theirs.
        foreach ($rows as $row) {
            if ($row['id'] !== '' && isset($before[Slug::plain($row['id'])])) {
                $usedIds[Slug::plain($row['id'])] = true;
            }
        }
        foreach ($rows as $index => $row) {
            $id = Slug::plain($row['id']);
            $existing = $id !== '' ? ($before[$id] ?? null) : null;
            if ($existing !== null && isset($drafts[$id])) {
                continue; // The same term twice.
            }
            $label = $this->firstLabel($row['labels'], $defaultLang);
            $typed = trim($row['slug']);

            if ($existing !== null) {
                $slug = $typed !== '' ? Slug::fromText($typed) : $existing['slug'];
                $slug = $slug !== '' ? $slug : $existing['slug'];
            } else {
                if ($id === '' && $typed === '' && $label === '') {
                    continue; // An empty row.
                }
                $base = $typed !== '' ? $typed : ($label !== '' ? $label : $id);
                $slug = Slug::fromText($base);
                if ($id === '' || isset($usedIds[$id])) {
                    $id = Slug::fromText($label !== '' ? $label : $base);
                }
                $id = $this->unique($id !== '' ? $id : ($slug !== '' ? $slug : 'term'), $usedIds);
                $usedIds[$id] = true;
            }
            $parent = array_key_exists('parent', $row) && $row['parent'] !== null ? Slug::plain((string)$row['parent']) : ($existing['parent'] ?? '');
            $descriptions = is_array($row['descriptions'] ?? null) ? $row['descriptions'] : ($existing['descriptions'] ?? []);
            $hero = is_string($row['hero_layout'] ?? null) ? self::heroChoice($row['hero_layout']) : ($existing['hero_layout'] ?? '');
            $drafts[$id] = ['index' => $index, 'id' => $id, 'slug' => $slug !== '' ? $slug : 'term', 'parent' => $parent, 'labels' => $row['labels'], 'descriptions' => $descriptions, 'hero_layout' => $hero, 'imported_from' => (string)($existing['imported_from'] ?? ''), 'new' => $existing === null];
        }

        // The parents: a removed parent hands its children to its own parent; a loop is refused.
        $refused = [];
        foreach ($drafts as $id => $draft) {
            $parent = $hierarchical ? $this->livingParent($draft['parent'], $drafts, $before) : '';
            $drafts[$id]['parent'] = $parent;
        }
        foreach ($drafts as $id => $draft) {
            if ($draft['parent'] !== '' && self::loops($drafts, $id)) {
                $refused[] = $id;
                $old = (string)($before[$id]['parent'] ?? '');
                $drafts[$id]['parent'] = isset($drafts[$old]) ? $old : '';
                if (self::loops($drafts, $id)) {
                    $drafts[$id]['parent'] = '';
                }
            }
        }

        // A slug is unique among the terms of one parent. Terms that stay where they are and keep their slug are served
        // first, then terms that moved or were renamed, then new ones, so nobody loses an address to a newcomer.
        $usedSlugs = [];
        $rank = static fn(array $draft, string $id): int => $draft['new'] ? 2 : (isset($before[$id]) && $before[$id]['slug'] === $draft['slug'] && $before[$id]['parent'] === $draft['parent'] ? 0 : 1);
        $order = array_keys($drafts);
        usort($order, static fn(string $x, string $y): int => [$rank($drafts[$x], $x), $drafts[$x]['index']] <=> [$rank($drafts[$y], $y), $drafts[$y]['index']]);
        foreach ($order as $id) {
            $slug = $this->unique($drafts[$id]['slug'], $usedSlugs[$drafts[$id]['parent']] ?? []);
            $usedSlugs[$drafts[$id]['parent']][$slug] = true;
            $drafts[$id]['slug'] = $slug;
        }

        uasort($drafts, static fn(array $a, array $b): int => $a['index'] <=> $b['index']);
        $terms = [];
        foreach ($drafts as $draft) {
            $terms[] = ['id' => $draft['id'], 'slug' => $draft['slug'], 'parent' => $draft['parent'], 'labels' => $draft['labels'], 'descriptions' => $draft['descriptions'], 'hero_layout' => $draft['hero_layout'], 'imported_from' => $draft['imported_from']];
        }

        $moved = [];
        $added = [];
        $paths = [];
        foreach ($drafts as $id => $draft) {
            $paths[$id] = implode('/', self::slugChain($drafts, $id) ?? [$draft['slug']]);
            if ($draft['new']) {
                $added[] = $id;
            }
        }
        $taken = array_flip($paths);
        foreach ($drafts as $id => $draft) {
            if (!isset($before[$id])) {
                continue;
            }
            $from = implode('/', self::slugChain($before, $id) ?? [$before[$id]['slug']]);
            // An address another term still uses is not gone, so nobody is sent away from it.
            if ($from !== $paths[$id] && !isset($taken[$from])) {
                $moved[] = ['id' => $id, 'from' => $from, 'to' => $paths[$id]];
            }
        }
        return ['terms' => $terms, 'moved' => $moved, 'removed' => array_values(array_diff(array_keys($before), array_keys($drafts))), 'added' => $added, 'refused' => $refused];
    }

    /**
     * The nearest parent that is still a term: the one asked for, or when that was removed, the first of its own parents
     * that was not.
     *
     * @param array<string, array<string, mixed>> $drafts the terms that stay, by id
     * @param array<string, array<string, mixed>> $before the terms as they were, by id
     */
    private function livingParent(string $parent, array $drafts, array $before): string
    {
        for ($guard = 0; $parent !== '' && $guard < 50; $guard++) {
            if (isset($drafts[$parent])) {
                return $parent;
            }
            $parent = (string)($before[$parent]['parent'] ?? '');
        }
        return '';
    }

    /** Whether going up from a term through its parents comes back to it. @param array<string, array<string, mixed>> $terms by id */
    private static function loops(array $terms, string $id): bool
    {
        $at = (string)($terms[$id]['parent'] ?? '');
        for ($guard = 0; $at !== '' && $guard < 200; $guard++) {
            if ($at === $id) {
                return true;
            }
            $at = (string)($terms[$at]['parent'] ?? '');
        }
        return $at !== '';
    }

    /** @param array<string, string> $labels */
    private function firstLabel(array $labels, string $defaultLang): string
    {
        $first = trim((string)($labels[$defaultLang] ?? ''));
        if ($first !== '') {
            return $first;
        }
        foreach ($labels as $label) {
            if (trim((string)$label) !== '') {
                return trim((string)$label);
            }
        }
        return '';
    }

    /** @param array<string, bool> $taken */
    private function unique(string $value, array $taken): string
    {
        if (!isset($taken[$value])) {
            return $value;
        }
        for ($n = 2; $n < 1000; $n++) {
            if (!isset($taken[$value . '-' . $n])) {
                return $value . '-' . $n;
            }
        }
        return $value . '-' . bin2hex(random_bytes(2));
    }
}
