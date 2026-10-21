<?php
/*
 * NearbyGroups: the entries near one under the top terms of a taxonomy with a tree. Which taxonomy is used (the one with a tree most
 * of them are filed in, a site's own over the categories on a tie, none without a tree), the order of the groups (the tree's), a
 * child and a grandchild under their top term, entries filed nowhere last, the label and address of each group, and no groups at
 * all when nothing is filed in a tree (the list stays one list).
 *   php tests/unit/nearby-groups.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, NearbyGroups, Taxonomies};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/near' . getmypid(); mkdir($dir, 0775, true);
$t = new Taxonomies($dir, ['en']);
$t->ensureDefaults();
$term = fn(string $id, string $parent = '') => ['id' => $id, 'slug' => $id, 'parent' => $parent, 'labels' => ['en' => ucfirst($id)]];
$item = fn(string $slug, array $meta = []) => new ContentItem('points', $slug, 'en', $meta + ['title' => $slug], '', '', '', 0);
$near = fn(array $items) => array_map(fn(ContentItem $i, int $n) => ['item' => $i, 'km' => $n / 10], $items, array_keys($items));
$label = fn(string $name, string $id): string => $t->label($name, $id, 'en');
$url = fn(string $name, string $id): string => '/' . $name . '/' . $t->slug($name, $id);
$group = fn(array $entries) => NearbyGroups::group($entries, $t, $label, $url);
$shape = fn(array $groups) => array_map(fn(array $g) => [$g['id'], $g['title'], array_map(fn($e) => $e['item']->slug, $g['entries'])], $groups);

check('nothing near, no groups', $group([]), []);
$entries = $near([$item('a', ['categories' => ['announcements']]), $item('b')]);
check('a site with only its two taxonomies and a flat list of categories: no groups', $group($entries), []);

$t->save('kinds', 'Kinds', [$term('nature'), $term('beaches'), $term('culture'), $term('gorges', 'nature'), $term('sandy', 'beaches'), $term('museums', 'culture'), $term('folk', 'museums')], null, true);
$t->save('facets', 'Facets', [$term('wetland')]);
$entries = $near([
    $item('beach', ['kinds' => ['sandy'], 'facets' => ['wetland']]),
    $item('gorge', ['kinds' => 'gorges']),
    $item('none'),
    $item('folk', ['kinds' => ['folk']]),
    $item('top', ['kinds' => ['nature']]),
    $item('ghost', ['kinds' => ['not-a-term']]),
]);
$groups = $group($entries);
check('groups in the order of the tree, each nearest first, the rest last with an empty id', $shape($groups), [
    ['nature', 'Nature', ['gorge', 'top']],
    ['beaches', 'Beaches', ['beach']],
    ['culture', 'Culture', ['folk']],
    ['', '', ['none', 'ghost']],
]);
check('a group has its taxonomy and the address of its term; "other" has none', [$groups[0]['taxonomy'], $groups[0]['url'], $groups[3]['url']], ['kinds', '/kinds/nature', '']);
check('the distances stay with their entries', array_map(fn($e) => $e['km'], $groups[0]['entries']), [0.1, 0.4]);
check('a top term with nothing near is not a group', count($group($near([$item('x', ['kinds' => ['gorges']])]))), 1);

// Categories with a tree too: the taxonomy most of the entries are filed in wins, and a site's own wins a tie.
$t->save('categories', 'Categories', [$term('sights'), $term('springs', 'sights')], null, true);
$tie = $near([$item('p', ['categories' => ['springs'], 'kinds' => ['gorges']])]);
check('a tie goes to the site\'s own taxonomy over the categories', $group($tie)[0]['taxonomy'], 'kinds');
$more = $near([$item('p', ['categories' => ['springs']]), $item('q', ['categories' => ['sights']]), $item('r', ['kinds' => ['gorges']])]);
check('more entries filed in the categories: the categories', [$group($more)[0]['taxonomy'], $shape($group($more))], ['categories', [['sights', 'Sights', ['p', 'q']], ['', '', ['r']]]]);
check('entries filed in no tree at all: no groups', $group($near([$item('a', ['facets' => ['wetland']]), $item('b')])), []);

// A loop of parents (a broken file) still ends.
file_put_contents("$dir/taxonomies/loop.yaml", "title: Loop\nhierarchical: true\nterms:\n  - id: one\n    slug: one\n    parent: two\n  - id: two\n    slug: two\n    parent: one\n  - id: three\n    slug: three\n    parent: one\n");
$t = new Taxonomies($dir, ['en']);
$group = fn(array $entries) => NearbyGroups::group($entries, $t, $label, $url);
check('a loop of parents does not hang', is_array($group($near([$item('l', ['loop' => ['three']])]))), true);

array_map('unlink', glob("$dir/taxonomies/*"));
rmdir("$dir/taxonomies");
rmdir($dir);
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
