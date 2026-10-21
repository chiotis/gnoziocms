<?php
/*
 * The filters of the bar over a map: which taxonomies a list offers (chosen on its card, or the automatic ones: categories and every
 * taxonomy that applies to its types with 2 to 40 terms), the terms each entry on the map carries (a term of a tree also under its
 * parents) and how many entries each term has, a type with no taxonomies (no menus, as before), data made without the taxonomies
 * (the categories' menu, as before), the page's own form keeping the bar's taxonomies for a page without JavaScript, and what the
 * card stores.
 *   php tests/unit/map-filters.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\{ArchiveBuilder, ContentItem, ContentTypes, GeoLibrary, GeoMap, GeoView, Images, MapFilters, Taxonomies};
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/gnozio-map-filters-' . bin2hex(random_bytes(4));
mkdir($dir . '/content/taxonomies', 0775, true);
mkdir($dir . '/public/uploads/media', 0775, true);
$term = static fn(string $id, string $en, string $parent = ''): array => ['id' => $id, 'slug' => $id, 'labels' => ['en' => $en, 'el' => $en . ' (el)']] + ($parent !== '' ? ['parent' => $parent] : []);
$write = static fn(string $name, array $data) => file_put_contents($dir . '/content/taxonomies/' . $name . '.yaml', Yaml::dump($data, 6, 2));
$write('categories', ['title' => 'Categories', 'terms' => [$term('nature', 'Nature'), $term('beaches', 'Beaches', 'nature'), $term('forests', 'Forests', 'nature'), $term('culture', 'Culture'), $term('unused', 'Unused')]]);
$write('tags', ['title' => 'Tags', 'terms' => [$term('only', 'Only')]]);
$write('activity', ['title' => 'Activity', 'terms' => [$term('walking', 'Walking'), $term('cycling', 'Cycling'), $term('swimming', 'Swimming')]]);
$write('difficulty', ['title' => 'Difficulty', 'terms' => [$term('easy', 'Easy'), $term('hard', 'Hard')], 'archive' => ['types' => ['routes']]]);
$write('many', ['title' => 'Many', 'terms' => array_map(static fn(int $i): array => ['id' => 't' . $i, 'slug' => 't' . $i, 'labels' => ['en' => 'T' . $i]], range(1, 41))]);
$taxonomies = new Taxonomies($dir . '/content', ['en', 'el']);
$choices = [];
$filters = new MapFilters(fn(): Taxonomies => $taxonomies, function (string $type) use (&$choices): ?array { return $choices[$type] ?? null; });
$sorted = static function (array $names): array { sort($names); return $names; };

// ---- which taxonomies
check('automatic for a type: categories, and each taxonomy for it with 2 to 40 terms (not one term, not 41, not one for another type)', $sorted($filters->automaticFor(['points'])), ['activity', 'categories']);
check('a taxonomy whose pages list one type is automatic for that type', $sorted($filters->automaticFor(['routes'])), ['activity', 'categories', 'difficulty']);
check('nothing chosen: what the card of each type chose, else the automatic ones', $sorted($filters->names(['points', 'routes'])), ['activity', 'categories', 'difficulty']);
$choices['points'] = ['many'];
check('a type that chose keeps its choice, and the others theirs', [$filters->names(['points']), $sorted($filters->names(['points', 'routes']))], [['many'], ['activity', 'categories', 'difficulty', 'many']]);
$choices['points'] = [];
check('a type that chose none offers none', $filters->names(['points']), []);
$choices = [];
check('a list that chose keeps exactly that, without taxonomies that do not exist', $filters->names(['points'], ['tags', 'nope']), ['tags']);
$facets = $filters->facets(['points'], ['categories'], 'el');
check('a tree comes in its order, each term with its depth, its parents and its name in the language', array_map(static fn(array $t): array => [$t['id'], $t['depth'], $t['parents'], $t['label']], $facets[0]['terms']), [['nature', 0, [], 'Nature (el)'], ['beaches', 1, ['nature'], 'Beaches (el)'], ['forests', 1, ['nature'], 'Forests (el)'], ['culture', 0, [], 'Culture (el)'], ['unused', 0, [], 'Unused (el)']]);
check('and says it is a tree, with the taxonomy\'s title', [$facets[0]['tree'], $facets[0]['label']], [true, 'Categories']);

// ---- the data of a map
$images = new Images($dir . '/public', $dir . '/public/media');
$geo = new GeoMap(new GeoLibrary($images, $dir . '/cache'), $images);
$item = static fn(string $type, string $slug, array $meta, string $location = '35.1, 25.1') => new ContentItem($type, $slug, 'en', ['title' => ucfirst($slug), 'custom_fields' => $location === '' ? [] : ['location' => $location]] + $meta, '', '', '', 0);
$entries = [
    $item('points', 'cove', ['categories' => ['beaches'], 'activity' => ['swimming', 'walking']]),
    $item('points', 'wood', ['categories' => ['forests', 'nature'], 'activity' => ['walking']]),
    $item('points', 'temple', ['categories' => ['culture'], 'activity' => 'walking']),
    $item('points', 'hidden', ['categories' => ['culture'], 'activity' => ['cycling']], ''),
    $item('points', 'odd', ['categories' => ['not-a-term'], 'tags' => ['only']]),
];
$facetsOf = fn(array $types, ?array $chosen, string $lang): array => $filters->facets($types, $chosen, $lang);
$view = new GeoView($geo, fn() => [], fn(string $t): string => ucfirst($t), fn(string $s): string => ucfirst($s), fn(ContentItem $i): string => '/' . $i->slug, fn(): array => ['points'], ['tiles_url' => '', 'attribution' => ''], null, $facetsOf);
$data = $view->dataset($entries, 'en');
$byId = array_column($data['items'], null, 'id');
check('each entry carries its terms, a child also under its parent, a term the taxonomy does not have left out', [$byId['points/cove']['tax'], $byId['points/wood']['tax'], $byId['points/odd']['tax'] ?? null], [['activity' => ['swimming', 'walking'], 'categories' => ['beaches', 'nature']], ['activity' => ['walking'], 'categories' => ['forests', 'nature']], null]);
$offered = array_column($data['facets'], null, 'name');
check('the bar offers the automatic taxonomies the entries use, in the order of the site\'s taxonomies', array_keys($offered), ['activity', 'categories']);
check('a tree: the terms entries have, in the order of the tree, a parent counting each entry under it once', array_map(static fn(array $o): array => [$o['id'], $o['depth'], $o['count']], $offered['categories']['options']), [['nature', 0, 2], ['beaches', 1, 1], ['forests', 1, 1], ['culture', 0, 1]]);
check('the others by name, counting only the entries on the map (one without a place is not)', array_map(static fn(array $o): array => [$o['label'], $o['count']], $offered['activity']['options']), [['Swimming', 1], ['Walking', 3]]);
check('a list may choose other taxonomies, and one with fewer than two terms in use is not offered', array_column($view->dataset($entries, 'en', ['filters' => ['tags', 'activity']])['facets'], 'name'), ['activity']);
check('a list that chose none: no menus', $view->dataset($entries, 'en', ['filters' => []])['facets'], []);
$plain = [$item('points', 'a', []), $item('points', 'b', []), $item('points', 'c', ['categories' => []])];
$none = $view->dataset($plain, 'en');
check('a type with no taxonomies: no menus, no terms on the entries, the kinds and the rest as before', [$none['facets'], array_filter(array_column($none['items'], 'tax')), $none['cats'], count($none['items'])], [[], [], [], 3]);
$old = new GeoView($geo, fn() => [], fn(string $t): string => ucfirst($t), fn(string $s): string => ucfirst($s), fn(ContentItem $i): string => '/' . $i->slug, fn(): array => ['points'], ['tiles_url' => '', 'attribution' => '']);
$without = $old->dataset($entries, 'en');
check('data made without the taxonomies has no menus and keeps the categories as before', [array_key_exists('facets', $without), array_column($without['cats'], 'count', 'slug'), isset($without['items'][0]['tax'])], [false, ['beaches' => 1, 'culture' => 1, 'forests' => 1, 'nature' => 1, 'not-a-term' => 1], false]);
$asked = null;
$spy = new GeoView($geo, fn() => [], fn(string $t): string => ucfirst($t), fn(string $s): string => ucfirst($s), fn(ContentItem $i): string => '/' . $i->slug, fn(): array => ['points'], ['tiles_url' => '', 'attribution' => ''], null, function (array $types, ?array $chosen) use (&$asked): array { $asked = [$types, $chosen]; return []; });
$spy->dataset([$item('posts', 'note', [], ''), $entries[0]], 'en', ['filters' => ['activity']]);
check('only the types of the entries on the map are asked about (the cache\'s stamp holds the definitions of the types with a place), with what the list chose', $asked, [['points'], ['activity']]);
check('the map of one entry has no menus',array_key_exists('facets', $view->single($entries[0], 'en')), false);

// ---- the page's own form, for a page without JavaScript
$builder = new ArchiveBuilder(fn(): array => $taxonomies->names(), fn(string $tax, string $id, string $lang): string => $taxonomies->label($tax, $id, $lang), null, null, fn(array $types, ?array $chosen): array => $filters->names($types, $chosen));
$settings = ['taxonomies' => [], 'order' => 'title_asc', 'per_page' => 0, 'layout' => 'map', 'map_filters' => null];
$form = $builder->build($settings, [], null, 'en', $entries, []);
check('a Map list offers the taxonomies of its bar in its own form too', array_column($form['filters'], 'name'), ['activity', 'categories']);
check('and filters by them', array_map(static fn(ContentItem $i): string => $i->slug, $builder->build($settings, [], null, 'en', $entries, ['filter' => ['activity' => 'walking']])['items']), ['cove', 'temple', 'wood']);
check('the ones its card chose', array_column($builder->build(['map_filters' => ['activity']] + $settings, [], null, 'en', $entries, [])['filters'], 'name'), ['activity']);
check('another layout keeps only the filters it chose', $builder->build(['layout' => 'cards'] + $settings, [], null, 'en', $entries, [])['filters'], []);

// ---- what the card stores
$defaults = ContentTypes::resolveArchive([], static fn($v) => $v);
$names = $taxonomies->names();
$store = static fn(array $input, array $current = [], ?array $base = null): array => ContentTypes::archiveFromInput($input + ['layout' => 'map'], $current, $base ?? $defaults, $names, false);
check('nothing chosen is the automatic ones', [$defaults['map_filters'], ContentTypes::resolveArchive(['map_filters' => 'auto'], static fn($v) => $v)['map_filters']], [null, null]);
check('a list keeps the names it chose, nothing else', ContentTypes::resolveArchive(['map_filters' => ['activity', '../x', 'activity']], static fn($v) => $v)['map_filters'], ['activity']);
check('ticking exactly the automatic ones stores nothing (the list keeps following them)', array_key_exists('map_filters', $store(['map_filters_auto' => 'categories,activity', 'map_filters' => ['activity', 'categories']], ['map_filters' => ['many']])), false);
check('ticking others stores them, only taxonomies that exist', $store(['map_filters_auto' => 'categories,activity', 'map_filters' => ['difficulty', 'nope']])['map_filters'], ['difficulty']);
check('ticking none stores none', $store(['map_filters_auto' => 'categories', 'map_filters' => []])['map_filters'], []);
check('a form without the row changes nothing', $store([], ['map_filters' => ['many']])['map_filters'], ['many']);
check('the automatic ones over a theme\'s own list are stored as such', $store(['map_filters_auto' => 'categories', 'map_filters' => ['categories']], [], ['map_filters' => ['activity']] + $defaults)['map_filters'], 'auto');

$rm = static function (string $path) use (&$rm): void { foreach (glob($path . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $p) { is_dir($p) ? $rm($p) : unlink($p); } @rmdir($path); };
$rm($dir);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
