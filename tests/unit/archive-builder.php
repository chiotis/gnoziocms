<?php
/*
 * The archive of a list of entries: the filters it offers (only real values), choosing one, the order, and the page.
 *   php tests/unit/archive-builder.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ArchiveBuilder, ContentItem};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$labels = ['categories' => ['news' => 'News', 'guides' => 'Guides', 'zeta' => 'Zeta'], 'tags' => ['a' => 'Alpha']];
$b = new ArchiveBuilder(fn() => ['categories', 'tags'], fn(string $tax, string $id, string $lang): string => ($labels[$tax][$id] ?? '') . ($lang === 'el' ? ' (el)' : ''));
$item = fn(string $slug, array $meta) => new ContentItem('posts', $slug, 'en', ['title' => ucfirst($slug)] + $meta, '', '', '', 0);
$items = [
    $item('delta', ['categories' => ['news'], 'custom_fields' => ['sector' => 'retail', 'budget' => '30', 'year' => 1735689600]]),
    $item('alpha', ['categories' => ['news', 'guides'], 'custom_fields' => ['sector' => 'retail', 'budget' => '5']]),
    $item('charlie', ['categories' => 'guides', 'custom_fields' => ['sector' => 'food', 'budget' => '100', 'year' => 1704067200]]),
    $item('bravo', ['custom_fields' => ['sector' => '', 'budget' => 'n/a']]),
    $item('echo', []),
];
$settings = ['taxonomies' => ['categories', 'nothing'], 'order' => 'date_desc', 'per_page' => 0];
$fields = [
    'sector' => ['label' => 'Sector', 'type' => 'select', 'filterable' => true, 'hidden' => false, 'options' => ['' => '—', 'retail' => 'Retail', 'food' => 'Food & drink']],
    'budget' => ['label' => 'Budget', 'type' => 'number', 'filterable' => false, 'hidden' => false, 'options' => []],
    'year' => ['label' => 'Year', 'type' => 'date', 'filterable' => false, 'hidden' => false, 'options' => []],
    'old' => ['label' => 'Old', 'type' => 'select', 'filterable' => true, 'hidden' => true, 'options' => ['x' => 'X']],
];
$slugs = fn(array $r) => array_map(fn($i) => $i->slug, $r['items']);

// ---- filters
$r = $b->build($settings, $fields, null, 'en', $items, []);
check('every entry when nothing is chosen, in the order given', [$slugs($r), $r['total'], $r['filtered']], [['delta', 'alpha', 'charlie', 'bravo', 'echo'], 5, false]);
check('a filter for a taxonomy that exists and one for a select field, not for a hidden field or a taxonomy that does not exist', array_column($r['filters'], 'name'), ['categories', 'sector']);
$cat = $r['filters'][0];
check('a taxonomy filter offers the terms entries use, with counts, named, sorted by name', [$cat['label'], $cat['kind'], $cat['options']], ['Categories', 'taxonomy', [['value' => 'guides', 'label' => 'Guides', 'count' => 2], ['value' => 'news', 'label' => 'News', 'count' => 2]]]);
$sec = $r['filters'][1];
check('a field filter offers the values entries have, labelled by the field, never an empty value', $sec['options'], [['value' => 'food', 'label' => 'Food & drink', 'count' => 1], ['value' => 'retail', 'label' => 'Retail', 'count' => 2]]);
check('terms are named in the language asked', $b->build($settings, $fields, null, 'el', $items, [])['filters'][0]['options'][0]['label'], 'Guides (el)');
$one = $b->build(['taxonomies' => ['categories'], 'order' => 'date_desc', 'per_page' => 0], [], null, 'en', [$items[0], $items[3]], []);
check('a filter with a single option is not offered', $one['filters'], []);
$chosen = $b->build($settings, $fields, null, 'en', [$items[0], $items[3]], ['filter' => ['categories' => 'news']]);
check('but one already chosen is, so it can be undone', [count($chosen['filters']), $chosen['filters'][0]['selected'], $chosen['filtered'], $slugs($chosen)], [1, 'news', true, ['delta']]);
check('choosing a taxonomy term keeps the entries filed under it (a list or a single term)', $slugs($b->build($settings, $fields, null, 'en', $items, ['filter' => ['categories' => 'guides']])), ['alpha', 'charlie']);
check('choosing two filters narrows by both', $slugs($b->build($settings, $fields, null, 'en', $items, ['filter' => ['categories' => 'guides', 'sector' => 'retail']])), ['alpha']);
check('a value nobody has is ignored, not an empty page', [$slugs($b->build($settings, $fields, null, 'en', $items, ['filter' => ['sector' => 'nope']])), $b->build($settings, $fields, null, 'en', $items, ['filter' => ['sector' => 'nope']])['filtered']], [['delta', 'alpha', 'charlie', 'bravo', 'echo'], false]);
check('nonsense in the address is ignored', $b->build($settings, $fields, null, 'en', $items, ['filter' => 'x', 'page' => 'y'])['total'], 5);
check('a filter that is not one of the facets is ignored', $slugs($b->build($settings, $fields, null, 'en', $items, ['filter' => ['year' => '1']])), ['delta', 'alpha', 'charlie', 'bravo', 'echo']);

// ---- order
$order = fn(string $o) => $slugs($b->build(['order' => $o] + $settings, $fields, null, 'en', $items, []));
check('oldest first reverses the list', $order('date_asc'), ['echo', 'bravo', 'charlie', 'alpha', 'delta']);
check('title A to Z', $order('title_asc'), ['alpha', 'bravo', 'charlie', 'delta', 'echo']);
check('title Z to A', $order('title_desc'), ['echo', 'delta', 'charlie', 'bravo', 'alpha']);
check('by a number field, smallest first, entries without a number last', $order('field:budget:asc'), ['alpha', 'delta', 'charlie', 'bravo', 'echo']);
check('largest first, still without a number last', $order('field:budget:desc'), ['charlie', 'delta', 'alpha', 'bravo', 'echo']);
check('by a date field (the YAML reader turns dates into numbers)', $order('field:year:asc'), ['charlie', 'delta', 'alpha', 'bravo', 'echo']);
check('an order naming a field that is not declared sorts as text, without value last', $order('field:missing:asc'), ['delta', 'alpha', 'charlie', 'bravo', 'echo']);
check('an unknown order leaves the list as it is', $order('whatever'), ['delta', 'alpha', 'charlie', 'bravo', 'echo']);

// ---- pages
$many = array_map(fn($n) => $item('e' . $n, []), range(1, 10));
$pg = fn(int $page, int $per = 4) => $b->build(['order' => 'date_desc', 'per_page' => $per, 'taxonomies' => []], [], null, 'en', $many, ['page' => (string)$page]);
$p = $pg(2);
check('the page asked for', [$slugs($p), $p['page'], $p['pages'], $p['total']], [['e5', 'e6', 'e7', 'e8'], 2, 3, 10]);
check('with a way to the previous and the next', [$p['prev'], $p['next']], ['?', '?page=3']);
check('a link for every page, the current one marked', array_map(fn($l) => [$l['number'], $l['href'], $l['current']], $p['page_links']), [[1, '?', false], [2, '?page=2', true], [3, '?page=3', false]]);
check('the first page has no previous, the last no next', [$pg(1)['prev'], $pg(3)['next'], $pg(3)['prev']], ['', '', '?page=2']);
check('a page past the end is the last, and before the start the first', [$pg(99)['page'], $pg(0)['page'], $pg(-3)['page']], [3, 1, 1]);
check('the last page holds the rest', $slugs($pg(3)), ['e9', 'e10']);
check('a per-page of 0 shows everything on one page, with no page links', [count($pg(1, 0)['items']), $pg(1, 0)['pages'], $pg(1, 0)['page_links'], $pg(5, 0)['page']], [10, 1, [], 1]);
$f = $b->build(['order' => 'date_desc', 'per_page' => 1, 'taxonomies' => ['categories']], [], null, 'en', [$items[0], $items[1], $items[2]], ['filter' => ['categories' => 'guides'], 'page' => '1']);
check('page links keep the chosen filters', [$f['next'], array_column($f['page_links'], 'href')], ['?filter%5Bcategories%5D=guides&page=2', ['?filter%5Bcategories%5D=guides', '?filter%5Bcategories%5D=guides&page=2']]);
check('the settings and definition are passed through for the template', [$f['settings']['per_page'], $f['definition']], [1, null]);
check('an empty list is one empty page', [$b->build($settings, [], null, 'en', [], [])['total'], $b->build($settings, [], null, 'en', [], [])['pages'], $b->build($settings, [], null, 'en', [], [])['items']], [0, 1, []]);

// ---- random order: a new order for each visit, the same one while paging
$rnd = fn(array $query, int $per = 4) => $b->build(['order' => 'random', 'per_page' => $per, 'taxonomies' => []], [], null, 'en', $many, $query);
$all = fn(array $r) => $slugs($r);
$a1 = $rnd(['seed' => '7']); $a2 = $rnd(['seed' => '7']); $b1 = $rnd(['seed' => '8']);
check('with the same number the order is the same, and it is every entry once (on one page)', [$all($rnd(['seed' => '7'], 0)) === $all($rnd(['seed' => '7'], 0)), count(array_unique($all($rnd(['seed' => '7'], 0)))), $all($a1) === $all($a2)], [true, 10, true]);
check('another number gives another order', $all($rnd(['seed' => '7'], 0)) !== $all($rnd(['seed' => '8'], 0)), true);
check('the order is not the order the entries came in', $all($rnd(['seed' => '7'], 0)) !== array_map(fn($n) => 'e' . $n, range(1, 10)), true);
$pages = [];
foreach ([1, 2, 3] as $n) { $pages = array_merge($pages, $all($rnd(['seed' => '42', 'page' => (string)$n]))); }
check('paging through it shows every entry once: none twice, none missed', [count($pages), count(array_unique($pages))], [10, 10]);
$first = $rnd(['seed' => '42']);
check('the paging links carry the number, so the next page continues the same order', [$first['next'], array_column($first['page_links'], 'href')], ['?seed=42&page=2', ['?seed=42', '?seed=42&page=2', '?seed=42&page=3']]);
$fresh = $rnd([]);
check('a visit with no number gets one, and its links carry it', preg_match('/^\?seed=\d+&page=2$/', $fresh['next']) === 1 && count($fresh['items']) === 4, true);
check('a number that is not one is replaced', preg_match('/^\?seed=[1-9]\d*&page=2$/', $rnd(['seed' => 'abc'])['next']) === 1 && preg_match('/^\?seed=[1-9]\d*&page=2$/', $rnd(['seed' => '-5'])['next']) === 1, true);
check('the other orders have no number in their links', [$pg(1)['next'], $b->build(['order' => 'title_asc', 'per_page' => 4, 'taxonomies' => []], [], null, 'en', $many, ['seed' => '9'])['next']], ['?page=2', '?page=2']);
check('random is one of the orders to choose', isset(\GnozioCMS\ContentTypes::ORDERS['random']) && isset(\GnozioCMS\ContentTypes::orderOptions([])['random']), true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
