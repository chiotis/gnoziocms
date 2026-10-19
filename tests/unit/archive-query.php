<?php
/*
 * A list of entries answered by the content index (ArchiveQuery) is the list the scan of every entry gives: the same entries, in the
 * same order, with the same filters, counts and pages, for a type that exists only as a YAML file, a term's page across types, drafts
 * and hidden entries, and two languages. The index follows a save, a delete and a change of an entry's file, and says no (so the caller
 * scans) when it cannot be trusted. It also reads only the page's files.
 *   php tests/unit/archive-query.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{ArchiveBuilder, ArchiveQuery, ContentIndex, ContentRepository, ContentTypes, SystemDatabase, SystemMetaRepository, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$root = sys_get_temp_dir() . '/archive-query' . getmypid();
mkdir($root . '/custom/content-types', 0775, true);
mkdir($root . '/storage', 0775, true);
foreach (['recipes', 'posts', 'authors', 'works'] as $dir) {
    mkdir($root . '/content/' . $dir, 0775, true);
}
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
// A type that is only a file of data: no code knows it.
file_put_contents($root . '/custom/content-types/recipes.yaml', <<<'YAML'
label: Recipes
fields:
  course: { type: select, label: Course, filterable: true, options: { starter: Starter, main: Main, dessert: Dessert } }
  minutes: { type: number, label: Minutes }
  served: { type: date, label: Served }
  chef: { type: text, label: Chef }
archive:
  taxonomies: [categories]
  per_page: 7
YAML);

file_put_contents($root . '/custom/content-types/authors.yaml', "label: Authors\nfields: {}\n");
file_put_contents($root . '/custom/content-types/works.yaml', "label: Works\nfields:\n  author: { type: relation, types: [authors], label: Author, filterable: true }\n  team: { type: relation, types: [authors], multiple: true, label: Team, filterable: true }\narchive:\n  per_page: 4\n");
foreach ([['ada', 'Ada', 'aaaaaaaa00000001', 'published'], ['bob', 'Bob', 'bbbbbbbb00000002', 'published'], ['cy', 'Cy', 'cccccccc00000003', 'draft']] as [$slug, $title, $id, $status]) {
    file_put_contents($root . '/content/authors/' . $slug . '.md', "---\ntitle: $title\nstatus: $status\ntranslation_id: $id\n---\n\nx\n");
}
$ids = ['aaaaaaaa00000001', 'bbbbbbbb00000002', 'cccccccc00000003', ''];
for ($i = 0; $i < 14; $i++) {
    $author = $ids[$i % 4];
    $team = $i % 3 === 0 ? "\n  team: [bbbbbbbb00000002, aaaaaaaa00000001]" : '';
    file_put_contents($root . '/content/works/w' . $i . '.md', "---\ntitle: Work $i\nstatus: published\ndate: \"2026-02-" . sprintf('%02d', $i + 1) . "\"\ncustom_fields:" . ($author !== '' ? "\n  author: $author" : "\n  other: x") . $team . "\n---\n\nx\n");
}
$courses = ['starter', 'main', 'dessert', ''];
$chefs = ['Zoe', 'adam', 'Éva', 'Bob', ''];
$cats = [[], ['soups'], ['soups', 'quick'], ['quick'], 'vegan', ['vegan', 'quick']];
$count = 53;
for ($i = 0; $i < $count; $i++) {
    $slug = 'r' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    $front = ['title: ' . ($i % 5 === 0 ? 'Ωραία' : 'Recipe') . ' ' . (($i * 7) % 23), 'status: ' . ($i % 11 === 0 ? 'draft' : 'published')];
    if ($i % 13 === 0) { $front[] = 'visible: false'; }
    if ($i % 4 !== 0) { $front[] = 'date: "' . gmdate('Y-m-d', 1600000000 + (($i * 37) % 17) * 86400) . '"'; }
    $c = $cats[$i % count($cats)];
    if ($c !== []) { $front[] = 'categories: ' . json_encode($c); }
    $front[] = 'custom_fields:';
    $front[] = '  course: "' . $courses[$i % 4] . '"';
    if ($i % 6 !== 0) { $front[] = '  minutes: ' . (($i * 11) % 90); }
    if ($i % 3 !== 0) { $front[] = '  served: ' . gmdate('Y-m-d', 1500000000 + (($i * 53) % 29) * 86400); }
    $front[] = '  chef: "' . $chefs[$i % 5] . '"';
    file_put_contents($root . '/content/recipes/' . $slug . '.md', "---\n" . implode("\n", $front) . "\n---\n\nBody $i\n");
    if ($i % 9 === 0) {
        file_put_contents($root . '/content/recipes/' . $slug . '.en.md', "---\ntitle: English $i\nstatus: published\ncategories: [soups]\ncustom_fields:\n  course: main\n---\n\nEN\n");
    }
    if ($i % 4 === 0) {
        file_put_contents($root . '/content/posts/p' . $i . '.md', "---\ntitle: Post $i\nstatus: published\ndate: \"2021-0" . (1 + $i % 9) . "-10\"\ncategories: " . json_encode($cats[($i + 1) % count($cats)]) . "\n---\n\nPost\n");
    }
    touch($root . '/content/recipes/' . $slug . '.md', 1600000000 + $i * 100);
}

$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository($root . '/content', new MarkdownConverter($environment), $settings);
$database = new SystemDatabase($root . '/storage');
$database->initialize();
$meta = new SystemMetaRepository($database);
$types = new ContentTypes(new Theme($root, 'default'));
$index = new ContentIndex($database, $root . '/content', $meta);
$index->keepReferences(fn(string $type): array => $types->definition($type, 'el', 'el')['fields']);
$index->keepTerms(fn(): array => ['tags', 'categories']);
$query = new ArchiveQuery($index, $content);
$relations = new \GnozioCMS\Relations($index, $content, $types, $settings, $root . '/content');
$relationTitles = fn(array $ids, string $lang): array => array_column($relations->resolve($ids, [], $lang), 'title', 'id');
$builder = new ArchiveBuilder(fn() => ['tags', 'categories'], fn(string $taxonomy, string $id, string $lang): string => ucfirst($id), $query, $relationTitles);
$scan = new ArchiveBuilder(fn() => ['tags', 'categories'], fn(string $taxonomy, string $id, string $lang): string => ucfirst($id), null, $relationTitles);

check('nothing is indexed yet', $index->isCurrent($content->getTypes()), false);
check('the first look makes the index, then it can answer', [$query->ready(), $index->isCurrent($content->getTypes())], [true, true]);

$definition = $types->definition('recipes', 'el', 'el');
$shape = static function (array $page): array {
    $page['items'] = array_map(static fn($i) => $i->type . '/' . $i->slug . '.' . $i->lang, $page['items']);
    unset($page['definition'], $page['map_items']);
    return $page;
};
$same = static function (string $label, array $settingsOverride, array $q, string $lang = 'el', bool $hidden = false) use ($builder, $scan, $content, $definition, $shape): void {
    $settings = $settingsOverride + $definition['archive'];
    $expected = $scan->build($settings, $definition['fields'], $definition, $lang, $content->getItems('recipes', $lang, $hidden, false), $q);
    $got = $builder->buildIndexed(['types' => ['recipes'], 'term' => null], $hidden, $settings, $definition['fields'], $definition, $lang, $q);
    check($label, $got === null ? null : $shape($got), $shape($expected));
};

$same('newest first, the first page', [], []);
$same('the last page', [], ['page' => '8']);
$same('a page past the end is the last', [], ['page' => '999']);
$same('nonsense for a page is the first', [], ['page' => 'x', 'filter' => 'y']);
$same('oldest first', ['order' => 'date_asc'], ['page' => '2']);
$same('title A to Z', ['order' => 'title_asc'], ['page' => '3']);
$same('title Z to A', ['order' => 'title_desc'], []);
$same('by a number, smallest first, entries without one last', ['order' => 'field:minutes:asc'], ['page' => '2']);
$same('by a number, largest first', ['order' => 'field:minutes:desc'], []);
$same('by a date field', ['order' => 'field:served:asc'], ['page' => '2']);
$same('by a text field (accents and capitals)', ['order' => 'field:chef:asc', 'per_page' => 12], []);
$same('by a field the type does not declare', ['order' => 'field:nothing:desc'], []);
$same('every entry on one page', ['per_page' => 0], []);
$same('a filter on a select field', [], ['filter' => ['course' => 'main']]);
$same('a filter on a taxonomy', [], ['filter' => ['categories' => 'quick'], 'page' => '2']);
$same('two filters', ['order' => 'title_asc'], ['filter' => ['categories' => 'soups', 'course' => 'starter']]);
$same('a filter value nobody has is ignored', [], ['filter' => ['course' => 'nope']]);
$same('the other language', [], []);
$same('the other language, filtered', [], ['filter' => ['course' => 'main']], 'en');
$same('a signed-in visitor also sees drafts and hidden entries', [], ['page' => '2'], 'el', true);
$same('and filters them', ['order' => 'field:minutes:asc'], ['filter' => ['categories' => 'vegan']], 'el', true);
$same('a map lists up to a limit with no pages', ['layout' => 'map'], []);

// Random: a seed makes the same order for the same list.
$a = $builder->buildIndexed(['types' => ['recipes'], 'term' => null], false, ['order' => 'random'] + $definition['archive'], $definition['fields'], $definition, 'el', ['seed' => '12345']);
$b = $scan->build(['order' => 'random'] + $definition['archive'], $definition['fields'], $definition, 'el', $content->getItems('recipes', 'el', false, false), ['seed' => '12345']);
check('a random order with a seed is the same order', $shape($a), $shape($b));

// ---- relations as filters ("works of this author"), and the order a visitor chooses
$worksDef = $types->definition('works', 'el', 'el');
$worksSame = static function (string $label, array $override, array $q) use ($builder, $scan, $content, $worksDef, $shape): array {
    $settings = $override + $worksDef['archive'];
    $expected = $scan->build($settings, $worksDef['fields'], $worksDef, 'el', $content->getItems('works', 'el', false, false), $q);
    $got = $builder->buildIndexed(['types' => ['works'], 'term' => null], false, $settings, $worksDef['fields'], $worksDef, 'el', $q);
    check($label, $got === null ? null : $shape($got), $shape($expected));
    return $expected;
};
$all = $worksSame('a list with relation filters is the same list from the index and from the scan', [], []);
$byAuthor = array_column($all['filters'], null, 'name');
check('a relation is a filter naming the entries it points at, only those a visitor may see (a draft author is not offered)', [array_keys($byAuthor), array_column($byAuthor['author']['options'], 'label'), $byAuthor['author']['kind']], [['author', 'team'], ['Ada', 'Bob'], 'relation']);
check('a field that holds several entries counts each of them', array_column($byAuthor['team']['options'], 'count', 'label'), ['Ada' => 5, 'Bob' => 5]);
$worksSame('choosing an author', [], ['filter' => ['author' => 'aaaaaaaa00000001']]);
$chosen = $worksSame('and a page of it', [], ['filter' => ['author' => 'aaaaaaaa00000001'], 'page' => '2']);
check('the entries of that author, a page at a time', [$chosen['total'], $chosen['filtered']], [4, true]);
$worksSame('a relation filter with another filter', [], ['filter' => ['author' => 'bbbbbbbb00000002', 'team' => 'aaaaaaaa00000001']]);
check('an author nobody may see is ignored, not an empty page', $worksSame('a draft author cannot be filtered by', [], ['filter' => ['author' => 'cccccccc00000003']])['filtered'], false);
$sorting = ['sort_choices' => ['title_asc' => 'A-Z', 'date_asc' => 'Oldest']];
$base = $worksSame('a list that lets visitors choose, with none chosen, is as before', $sorting, []);
$asked = $worksSame('the order a visitor chose, from the ones offered', $sorting, ['sort' => 'title_asc']);
check('is the list in that order, a page that is its own', [$asked['filtered'], $asked['sort'], $asked['next']], [true, 'title_asc', '?sort=title_asc&page=2']);
check('an order that is not offered is ignored', $worksSame('an order nobody offered', $sorting, ['sort' => 'date_asc,x'])['sort'], '');
check('and so is a choice when the list does not let visitors choose', $worksSame('no choice offered', [], ['sort' => 'title_asc'])['sort'], '');
check('the map block gets every entry the filters leave, not the page', [count(($base['map_items'])()), count(($worksSame('map items, filtered', [], ['filter' => ['author' => 'aaaaaaaa00000001']])['map_items'])())], [14, 4]);

// A term's page across types.
$scope = ['types' => ['recipes', 'posts'], 'term' => ['taxonomy' => 'categories', 'ids' => ['quick', 'vegan']]];
$archive = ['taxonomies' => ['tags'], 'order' => 'date_desc', 'per_page' => 6, 'layout' => 'cards'];
$items = [];
foreach (['recipes', 'posts'] as $type) {
    foreach ($content->getItems($type, 'el', false, false) as $item) {
        if (array_intersect(['quick', 'vegan'], \GnozioCMS\Format::list($item->meta['categories'] ?? null)) !== []) { $items[] = $item; }
    }
}
$when = static fn($i): int => strtotime((string)($i->meta['date'] ?? '')) ?: $i->mtime;
usort($items, static fn($x, $y): int => $when($y) <=> $when($x));
$expected = $scan->build($archive, [], null, 'el', $items, ['page' => '2']);
$got = $builder->buildIndexed($scope, false, $archive, [], null, 'el', ['page' => '2']);
check('the page of a term across two types is the same list', [$got['total'], $got['pages'], $got['page']], [$expected['total'], $expected['pages'], $expected['page']]);
check('in the same order (a tie between types may differ in which type comes first, the dates do not)', array_map($when, $got['items']), array_map($when, $expected['items']));

// It reads only the page.
$one = $builder->buildIndexed(['types' => ['recipes'], 'term' => null], false, $definition['archive'], $definition['fields'], $definition, 'el', []);
check('a page of seven is seven entries read', count($one['items']), 7);

// The index follows a save and a delete.
$file = $root . '/content/recipes/r001.md';
file_put_contents($file, "---\ntitle: Brand new title\nstatus: published\ndate: \"2030-01-01\"\ncustom_fields:\n  course: main\n---\n\nChanged\n");
$content->forget();
$index->upsert($content->parseFile('recipes', $file));
$first = $builder->buildIndexed(['types' => ['recipes'], 'term' => null], false, $definition['archive'], $definition['fields'], $definition, 'el', []);
check('a saved entry is where its date puts it', $first['items'][0]->slug, 'r001');
unlink($file);
$index->remove('recipes', 'r001', 'el');
$content->forget();
$first = $builder->buildIndexed(['types' => ['recipes'], 'term' => null], false, $definition['archive'], $definition['fields'], $definition, 'el', []);
check('a deleted entry is gone', in_array('r001', array_map(fn($i) => $i->slug, $first['items']), true), false);

// A file the index does not know of is found by the check of the files, and a file that is gone makes the index say no.
file_put_contents($root . '/content/recipes/zz-late.md', "---\ntitle: Late\nstatus: published\ndate: \"2031-01-01\"\ncustom_fields:\n  course: main\n---\n\nx\n");
$meta->set('content_index_checked_at', '0');
$content->forget();
$fresh = new ArchiveQuery($index, $content);
$freshBuilder = new ArchiveBuilder(fn() => ['tags', 'categories'], fn(string $t, string $id, string $l): string => $id, $fresh);
$first = $freshBuilder->buildIndexed(['types' => ['recipes'], 'term' => null], false, $definition['archive'], $definition['fields'], $definition, 'el', []);
check('a file added outside the admin is listed on the next look', $first['items'][0]->slug, 'zz-late');
unlink($root . '/content/recipes/r002.md');
$meta->set('content_index_checked_at', (string)time());
$content->forget();
$missing = $freshBuilder->buildIndexed(['types' => ['recipes'], 'term' => null], false, ['per_page' => 0] + $definition['archive'], $definition['fields'], $definition, 'el', []);
check('a file taken away outside the admin is noticed at once, without waiting', in_array('r002', array_map(fn($i) => $i->slug, $missing['items'] ?? []), true), false);

// A taxonomy the index was not made for needs the index made again.
$index->keepTerms(fn(): array => ['tags', 'categories', 'courses']);
check('a new taxonomy makes the index out of date', $index->isCurrent($content->getTypes()), false);
check('it is made again by the next look, then it answers', [(new ArchiveQuery($index, $content))->ready(), $index->isCurrent($content->getTypes())], [true, true]);

// The cost: a few thousand entries.
$big = sys_get_temp_dir() . '/archive-query-big' . getmypid();
mkdir($big . '/content/recipes', 0775, true);
mkdir($big . '/custom/content-types', 0775, true);
mkdir($big . '/storage', 0775, true);
symlink($repo . '/themes', $big . '/themes');
register_shutdown_function(static function () use ($big): void { @exec('rm -rf ' . escapeshellarg($big)); });
copy($root . '/custom/content-types/recipes.yaml', $big . '/custom/content-types/recipes.yaml');
$n = (int)(getenv('ARCHIVE_QUERY_ENTRIES') ?: 3000);
for ($i = 0; $i < $n; $i++) {
    file_put_contents($big . '/content/recipes/e' . $i . '.md', "---\ntitle: Entry $i\nstatus: published\ndate: \"" . gmdate('Y-m-d', 1500000000 + ($i * 977) % 90000000) . "\"\ncategories: [" . ['a', 'b', 'c'][$i % 3] . "]\ncustom_fields:\n  course: " . ['starter', 'main', 'dessert'][$i % 3] . "\n  minutes: " . ($i % 120) . "\n---\n\n" . str_repeat("Some words of text. ", 60) . "\n");
}
$bigContent = new ContentRepository($big . '/content', new MarkdownConverter($environment), $settings);
$bigDatabase = new SystemDatabase($big . '/storage');
$bigDatabase->initialize();
$bigIndex = new ContentIndex($bigDatabase, $big . '/content', new SystemMetaRepository($bigDatabase));
$bigTypes = new ContentTypes(new Theme($big, 'default'));
$bigIndex->keepReferences(fn(string $type): array => $bigTypes->definition($type, 'el', 'el')['fields']);
$bigIndex->keepTerms(fn(): array => ['tags', 'categories']);
$bigIndex->rebuild($bigContent, ['recipes']);
$bigDef = $bigTypes->definition('recipes', 'el', 'el');
$bigBuilder = new ArchiveBuilder(fn() => ['tags', 'categories'], fn(string $t, string $id, string $l): string => $id, new ArchiveQuery($bigIndex, $bigContent));
$ask = static function (array $q) use ($bigBuilder, $bigDef) {
    $t = microtime(true);
    $page = $bigBuilder->buildIndexed(['types' => ['recipes'], 'term' => null], false, $bigDef['archive'], $bigDef['fields'], $bigDef, 'el', $q);
    return [$page, (microtime(true) - $t) * 1000];
};
[$page, $ms] = $ask([]);
[$page2, $ms2] = $ask(['filter' => ['course' => 'main'], 'page' => '5']);
$t = microtime(true);
$bigScan = new ArchiveBuilder(fn() => ['tags', 'categories'], fn(string $t, string $id, string $l): string => $id);
$bigScan->build($bigDef['archive'], $bigDef['fields'], $bigDef, 'el', (new ContentRepository($big . '/content', new MarkdownConverter($environment), $settings))->getItems('recipes', 'el', false, false), []);
echo sprintf("     (%d entries: first page %.0f ms, a filtered fifth page %.0f ms; the scan, files parsed from cold, %.0f ms)\n", $n, $ms, $ms2, (microtime(true) - $t) * 1000);
check('a page of a large type is one page of entries', [$page['total'], count($page['items'])], [$n, 7]);
check('a filtered page of it is a third of the entries', $page2['total'], (int)ceil($n / 3));
check('and answers within half a second', $ms < 500 && $ms2 < 500, true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
