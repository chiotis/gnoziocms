<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Which taxonomies the bar over a map of entries offers as filters (the Map layout of an archive, the Map block, the Map of the list
 * block), and their terms as the bar lists them. A list chooses them on its card in Theme > Archive Layouts (`map_filters`); a list
 * that chose nothing gets the automatic ones: categories, as always, and every other taxonomy that applies to its types and has
 * between MIN_TERMS and MAX_TERMS terms (a taxonomy applies to the types its own pages list, or to all when it names none).
 *
 * Nothing here knows a content type or a taxonomy by name but `categories`, whose filter the maps always had.
 */
final class MapFilters
{
    public const MIN_TERMS = 2;
    public const MAX_TERMS = 40;

    /**
     * @param \Closure(): Taxonomies $taxonomies
     * @param \Closure(string): (string[]|null) $choiceOf what the card of a content type's list chose (null: nothing, the automatic ones)
     */
    public function __construct(private \Closure $taxonomies, private \Closure $choiceOf)
    {
    }

    /**
     * The taxonomies a map of entries of these types offers, in the order of the site's taxonomies.
     *
     * @param string[] $types
     * @param string[]|null $chosen what the list chose; null: what the card of each type chose (or the automatic ones)
     * @return string[]
     */
    public function names(array $types, ?array $chosen = null): array
    {
        $taxonomies = ($this->taxonomies)();
        $known = $taxonomies->names();
        if ($chosen !== null) {
            return array_values(array_intersect($known, $chosen));
        }
        $wanted = [];
        foreach (array_values(array_unique($types)) as $type) {
            $wanted = array_merge($wanted, ($this->choiceOf)($type) ?? $this->automatic($taxonomies, [$type]));
        }
        return array_values(array_intersect($known, $wanted));
    }

    /**
     * The automatic taxonomies of some types (what the card offers ticked before anything is chosen).
     *
     * @param string[] $types
     * @return string[]
     */
    public function automaticFor(array $types): array
    {
        return $this->automatic(($this->taxonomies)(), $types);
    }

    /** @param string[] $types @return string[] */
    private function automatic(Taxonomies $taxonomies, array $types): array
    {
        $names = [];
        foreach ($taxonomies->names() as $name) {
            $loaded = $taxonomies->load($name);
            $listed = Taxonomies::typeList($loaded['archive']['types'] ?? null);
            if ($listed !== [] && array_intersect($listed, $types) === []) {
                continue;
            }
            $count = count($loaded['terms']);
            if ($name === 'categories' || ($count >= self::MIN_TERMS && $count <= self::MAX_TERMS)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * The taxonomies a map offers with their terms: a tree in its order (a child after its parent, with its depth), each term with its
     * name in the language and the ids of its parents, so an entry filed under a child is counted under each of its parents too.
     *
     * @param string[] $types
     * @param string[]|null $chosen as for names()
     * @return array<int, array{name: string, label: string, tree: bool, terms: array<int, array{id: string, label: string, depth: int, parents: string[]}>}>
     */
    public function facets(array $types, ?array $chosen, string $lang): array
    {
        $taxonomies = ($this->taxonomies)();
        $facets = [];
        foreach ($this->names($types, $chosen) as $name) {
            $loaded = $taxonomies->load($name);
            $tree = (bool)$loaded['hierarchical'];
            $terms = [];
            // In the order of the tree the parents of a term are the last term seen at each depth above its own.
            $above = [];
            foreach ($tree ? $taxonomies->tree($name) : $loaded['terms'] as $term) {
                $id = (string)$term['id'];
                $depth = $tree ? (int)($term['depth'] ?? 0) : 0;
                $above = array_slice($above, 0, $depth);
                $terms[] = ['id' => $id, 'label' => $taxonomies->label($name, $id, $lang), 'depth' => $depth, 'parents' => $above];
                $above[] = $id;
            }
            $facets[] = ['name' => $name, 'label' => (string)$loaded['title'], 'tree' => $tree, 'terms' => $terms];
        }
        return $facets;
    }
}
