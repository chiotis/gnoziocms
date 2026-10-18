<?php
/*
 * Taxonomies: terms with addresses made from their names, the layout choices, and what the admin form keeps.
 *   php tests/unit/taxonomies.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentTypes, Taxonomies};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/tax' . getmypid(); mkdir($dir, 0775, true);
$t = new Taxonomies($dir, ['el', 'en']);
$t->ensureDefaults();
check('the two built-in taxonomies exist', $t->names(), ['categories', 'tags']);
check('kind of a taxonomy names its address: category, tag, or the taxonomy\'s own name', [Taxonomies::kind('categories'), Taxonomies::kind('tags'), Taxonomies::kind('project-types')], ['category', 'tag', 'project-types']);
check('only the two built in are not the site\'s own', [Taxonomies::isCustom('categories'), Taxonomies::isCustom('tags'), Taxonomies::isCustom('industries')], [false, false, true]);
$t->save('industries', 'Industries', [['id' => 'retail', 'slug' => 'retail', 'labels' => ['el' => 'Λιανική', 'en' => 'Retail']]]);
check('an address word belongs to its taxonomy: category(ies), tag(s), or the name of a taxonomy the site added', [$t->fromWord('category'), $t->fromWord('categories'), $t->fromWord('tag'), $t->fromWord('tags'), $t->fromWord('industries'), $t->fromWord('nothing'), $t->fromWord('')], ['categories', 'categories', 'tags', 'tags', 'industries', null, null]);

$row = fn(string $id, string $slug, string $el, string $en = '') => ['id' => $id, 'slug' => $slug, 'labels' => ['el' => $el, 'en' => $en]];
$term = fn(array $p, string $id) => array_values(array_filter($p['terms'], fn($x) => $x['id'] === $id))[0] ?? null;

// ---- the title area a term asks for
$t->save('industries', 'Industries', [['id' => 'retail', 'slug' => 'retail', 'labels' => ['el' => 'Λιανική', 'en' => 'Retail'], 'hero_layout' => 'centered'], ['id' => 'food', 'slug' => 'food', 'labels' => ['el' => 'Φαγητό', 'en' => 'Food'], 'hero_layout' => 'not a word!']]);
check('a term can ask for a title area, and a value that is not a word is dropped', [$t->heroLayout('industries', 'retail'), $t->heroLayout('industries', 'food'), $t->heroLayout('industries', 'nothing'), $t->heroLayout('nothing', 'retail')], ['centered', '', '', '']);
check('it is written to the file only when there is one', [str_contains((string)file_get_contents("$dir/taxonomies/industries.yaml"), 'hero_layout: centered'), substr_count((string)file_get_contents("$dir/taxonomies/industries.yaml"), 'hero_layout')], [true, 1]);
$p = $t->prepare('industries', [['id' => 'retail', 'slug' => 'retail', 'labels' => ['el' => 'Λιανική', 'en' => 'Retail']]], 'el');
check('a form that did not send the choice leaves the term\'s own', $p['terms'][0]['hero_layout'], 'centered');
$p = $t->prepare('industries', [['id' => 'retail', 'slug' => 'retail', 'labels' => ['el' => 'Λιανική', 'en' => 'Retail'], 'hero_layout' => 'split'], ['id' => '', 'slug' => '', 'labels' => ['el' => 'Νέος', 'en' => ''], 'hero_layout' => 'cover']], 'el');
check('one that did changes it, also for a new term', [$p['terms'][0]['hero_layout'], $p['terms'][1]['hero_layout']], ['split', 'cover']);
$p = $t->prepare('industries', [['id' => 'retail', 'slug' => 'retail', 'labels' => ['el' => 'Λιανική', 'en' => 'Retail'], 'hero_layout' => '']], 'el');
check('and an empty one clears it', $p['terms'][0]['hero_layout'], '');

// ---- new terms get an address from their name
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα', 'News'), $row('design', 'design', 'Σχεδιασμός', 'Design'), $row('', '', 'Ελληνική κουζίνα', 'Greek cuisine')], 'el');
check('a new term gets a Latin address made from its name', [$term($p, 'elliniki-kouzina')['slug'] ?? null], ['elliniki-kouzina']);
check('the address and the id are the same to begin with', array_column($p['terms'], 'id') === array_column($p['terms'], 'slug'), true);
check('new term reported as added', $p['added'], ['elliniki-kouzina']);
check('nothing moved, nothing removed', [$p['moved'], $p['removed']], [[], []]);

$p = $t->prepare('tags', [$row('', '', '', 'Only English name')], 'el');
check('a term with only another language name still gets an address', $p['terms'][0]['slug'], 'only-english-name');
$p = $t->prepare('tags', [$row('', '', 'Νέα'), $row('', '', 'Νέα')], 'el');
check('two new terms with the same name get different addresses', array_column($p['terms'], 'slug'), ['nea', 'nea-2']);
check('and different ids', array_column($p['terms'], 'id'), ['nea', 'nea-2']);
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα'), $row('', '', 'News')], 'el');
check('a new term never takes the address of an existing one', array_column($p['terms'], 'slug'), ['news', 'news-2']);
$p = $t->prepare('tags', [$row('', 'Μεγάλη Ιδέα', 'x')], 'el');
check('a typed address is converted too', $p['terms'][0]['slug'], 'megali-idea');
$p = $t->prepare('tags', [$row('', '', ''), $row('', '  ', '')], 'el');
check('empty rows are ignored', $p['terms'], []);
$p = $t->prepare('tags', [$row('', '', '日本語')], 'el');
check('a name that cannot be converted still gets a usable address', $p['terms'][0]['slug'], 'term');

// ---- existing terms
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']], ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']]]);
$p = $t->prepare('tags', [$row('news', 'nea', 'Νέα'), $row('design', '', 'Σχεδιασμός')], 'el');
check('changing an address is reported with both', $p['moved'], [['id' => 'news', 'from' => 'news', 'to' => 'nea']]);
check('the id stays when the address changes', $term($p, 'news')['id'], 'news');
check('an empty address field keeps the address', $term($p, 'design')['slug'], 'design');
$p = $t->prepare('tags', [$row('news', 'Νέα & Άρθρα', 'Νέα')], 'el');
check('a changed address typed in Greek is converted', $p['moved'][0]['to'], 'nea-arthra');
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα')], 'el');
check('removing a term is reported', $p['removed'], ['design']);
$p = $t->prepare('tags', [$row('news', 'design', 'Νέα'), $row('design', 'news', 'Σχεδιασμός')], 'el');
check('swapping two addresses sends nobody away from either', $p['moved'], []);
$p = $t->prepare('tags', [$row('news', 'shared', 'A'), $row('design', 'shared', 'B')], 'el');
check('two terms cannot share an address', array_column($p['terms'], 'slug'), ['shared', 'shared-2']);
$p = $t->prepare('tags', [$row('news', 'news', 'A'), $row('news', 'news', 'again')], 'el');
check('the same term submitted twice is kept once', count($p['terms']), 1);

// ---- lookups
check('find by address', $t->findBySlug('tags', 'news')['id'] ?? null, 'news');
check('unknown address', $t->findBySlug('tags', 'nope'), null);
check('label in a language', $t->label('tags', 'news', 'en'), 'News');
check('label falls back to another language', (function () use ($t) { $t->save('tags', 'Tags', [['id' => 'x', 'slug' => 'x', 'labels' => ['el' => 'Ίξ', 'en' => '']]]); return $t->label('tags', 'x', 'en'); })(), 'Ίξ');
check('label of an unknown term is made from its id', $t->label('tags', 'some-thing', 'en'), 'Some Thing');
check('address of a term', $t->slug('tags', 'x'), 'x');

// ---- layout choices
$defaults = ContentTypes::resolveArchive([], fn($v) => $v);
check('defaults match a content type', [$defaults['layout'], $defaults['columns'], $defaults['per_page'], $defaults['order']], ['cards', '3', 12, 'date_desc']);
check('a taxonomy without choices follows the defaults', $t->archive('tags', 'el', 'el')['layout'], 'cards');
$saved = ContentTypes::archiveFromInput(['layout' => 'list', 'columns' => '3', 'per_page' => '5', 'order' => 'title_asc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'title' => 'Articles about {term}', 'taxonomies' => ['categories', 'nope']], [], $defaults, ['categories'], false);
check('only what differs from the defaults is stored', $saved, ['layout' => 'list', 'order' => 'title_asc', 'per_page' => 5, 'taxonomies' => ['categories'], 'title' => 'Articles about {term}']);
check('an order by a field is refused where there are no fields', ContentTypes::archiveFromInput(['order' => 'field:year:asc'], [], $defaults, [], false)['order'] ?? 'default', 'default');
check('and accepted for a content type', ContentTypes::archiveFromInput(['order' => 'field:year:asc'], [], $defaults, [], true)['order'] ?? '', 'field:year:asc');
check('keys the form does not know are kept', ContentTypes::archiveFromInput(['layout' => 'cards'], ['custom_key' => 'kept'], $defaults, [], false)['custom_key'] ?? '', 'kept');
check('a nonsense layout falls back', ContentTypes::archiveFromInput(['layout' => 'wild'], [], $defaults, [], false)['layout'] ?? 'default', 'default');
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']]], $saved + ['types' => ['posts', 'x y']]);
$a = $t->archive('tags', 'el', 'el');
check('choices survive saving and loading', [$a['layout'], $a['per_page'], $a['order'], $a['title'], $a['taxonomies']], ['list', 5, 'title_asc', 'Articles about {term}', ['categories']]);
check('content types listed are cleaned', $a['types'], ['posts']);
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']]]);
check('saving terms alone keeps the layout choices', $t->archive('tags', 'el', 'el')['layout'], 'list');
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']]], []);
check('saving an empty choice list resets to the defaults', $t->archive('tags', 'el', 'el')['layout'], 'cards');
check('a broken file does not crash', (function () use ($dir, $t) { file_put_contents($dir . '/taxonomies/broken.yaml', "title: [unclosed\n"); $t->forget(); return $t->load('broken')['terms']; })(), []);

// ---- descriptions
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News'], 'descriptions' => ['el' => "Τα νέα μας\r\nκάθε μέρα ", 'en' => '']], ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']]]);
check('a description is kept per language, trimmed', $t->load('tags')['terms'][0]['descriptions'], ['el' => "Τα νέα μας\nκάθε μέρα", 'en' => '']);
check('a term without one has empty texts', $t->load('tags')['terms'][1]['descriptions'], ['el' => '', 'en' => '']);
$yaml = file_get_contents($dir . '/taxonomies/tags.yaml');
check('the file has a description only where one is written', substr_count($yaml, 'descriptions:'), 1);
check('description in the language asked', $t->description('tags', 'news', 'el', 'el'), "Τα νέα μας\nκάθε μέρα");
check('another language falls back to the default language', $t->description('tags', 'news', 'en', 'el'), "Τα νέα μας\nκάθε μέρα");
check('no description gives nothing', [$t->description('tags', 'design', 'en', 'el'), $t->description('tags', 'ghost', 'en', 'el')], ['', '']);
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα'), $row('design', 'design', 'Σχεδιασμός')], 'el');
check('rows sent without descriptions keep what the terms have', $p['terms'][0]['descriptions']['el'], "Τα νέα μας\nκάθε μέρα");
$p = $t->prepare('tags', [['descriptions' => ['el' => '', 'en' => 'Only EN']] + $row('news', 'news', 'Νέα'), $row('', '', 'Νέος')], 'el');
check('rows sent with descriptions replace them', $p['terms'][0]['descriptions'], ['el' => '', 'en' => 'Only EN']);
check('a new term starts with none', $p['terms'][1]['descriptions'], []);
$t->save('tags', 'Tags', $p['terms']);
check('a new term saved without texts has empty ones', $t->load('tags')['terms'][1]['descriptions'], ['el' => '', 'en' => '']);


// ---- a tree of terms
check('categories can have a parent by default; tags and a site\'s own taxonomies cannot', [$t->isHierarchical('categories'), $t->isHierarchical('tags'), $t->isHierarchical('industries')], [true, false, false]);
$t->save('categories', 'Categories', [
    ['id' => 'books', 'slug' => 'books', 'labels' => ['el' => 'Βιβλία', 'en' => 'Books']],
    ['id' => 'english', 'slug' => 'english', 'parent' => 'books', 'labels' => ['el' => 'Αγγλικά', 'en' => 'English']],
    ['id' => 'plays', 'slug' => 'plays', 'labels' => ['el' => 'Έργα', 'en' => 'Plays']],
    ['id' => 'plays-english', 'slug' => 'english', 'parent' => 'plays', 'labels' => ['el' => 'Αγγλικά', 'en' => 'English']],
    ['id' => 'classics', 'slug' => 'classics', 'parent' => 'english', 'labels' => ['el' => 'Κλασικά', 'en' => 'Classics']],
]);
check('a child\'s address holds its parents\' (the same slug can sit under two parents)', array_map(fn($id) => $t->slug('categories', $id), ['books', 'english', 'plays-english', 'classics']), ['books', 'books/english', 'plays/english', 'books/english/classics']);
check('an address finds its term, however deep', array_map(fn($p) => $t->findByPath('categories', $p)['id'] ?? null, ['books', 'books/english', 'plays/english', 'books/english/classics', 'english', 'books/plays', 'books/english/nothing', '']), ['books', 'english', 'plays-english', 'classics', null, null, null, null]);
check('findBySlug takes the same address', $t->findBySlug('categories', 'plays/english')['id'], 'plays-english');
check('the terms above a term, the top one first', array_column($t->ancestors('categories', 'classics'), 'id'), ['books', 'english']);
check('the terms below a term', [$t->descendantIds('categories', 'books'), $t->descendantIds('categories', 'classics')], [['english', 'classics'], []]);
check('the tree is read parent first, then children, with a depth', array_map(fn($x) => $x['id'] . ':' . $x['depth'], $t->tree('categories')), ['books:0', 'english:1', 'classics:2', 'plays:0', 'plays-english:1']);
$file = (string)file_get_contents("$dir/taxonomies/categories.yaml");
check('the file holds a parent only where there is one, and no flag for the default', [substr_count($file, 'parent:'), str_contains($file, 'hierarchical')], [3, false]);
check('a term at the top has no parent', $t->load('categories')['terms'][0]['parent'], '');

$rows = fn(array $extra = []) => array_map(fn($x) => ['id' => $x['id'], 'slug' => $x['slug'], 'parent' => $x['parent'], 'labels' => $x['labels']], $t->load('categories')['terms']);
// Moving a term: the old addresses of it and of what is below it are listed.
$edit = $rows();
$edit[1]['parent'] = 'plays';
$p = $t->prepare('categories', $edit, 'el');
check('moving a term to another parent lists the old and new address of it and of everything below', array_map(fn($m) => $m['from'] . ' -> ' . $m['to'], $p['moved']), ['books/english -> plays/english-2', 'books/english/classics -> plays/english-2/classics']);
check('a slug is unique only among the terms of one parent', array_map(fn($x) => $x['slug'], $p['terms']), ['books', 'english-2', 'plays', 'english', 'classics']);
// Renaming a parent moves its children.
$edit = $rows();
$edit[0]['slug'] = 'library';
$p = $t->prepare('categories', $edit, 'el');
check('renaming a parent moves everything below it', array_map(fn($m) => $m['from'] . ' -> ' . $m['to'], $p['moved']), ['books -> library', 'books/english -> library/english', 'books/english/classics -> library/english/classics']);
// A cycle is refused.
$edit = $rows();
$edit[0]['parent'] = 'classics';
$p = $t->prepare('categories', $edit, 'el');
check('a parent that would make a loop is refused, the term keeps the parent it had', [$p['refused'], $p['terms'][0]['parent'], $p['moved']], [['books'], '', []]);
$edit = $rows();
$edit[0]['parent'] = 'books';
check('a term is never its own parent', [$t->prepare('categories', $edit, 'el')['refused'], $t->prepare('categories', $edit, 'el')['terms'][0]['parent']], [['books'], '']);
// Removing a parent moves its children up a level.
$edit = array_values(array_filter($rows(), fn($x) => $x['id'] !== 'english'));
$p = $t->prepare('categories', $edit, 'el');
check('removing a parent hands its children to its parent', [array_values(array_column(array_filter($p['terms'], fn($x) => $x['id'] === 'classics'), 'parent')), $p['removed']], [['books'], ['english']]);
check('and the child\'s old address leads to the new one', array_map(fn($m) => $m['from'] . ' -> ' . $m['to'], $p['moved']), ['books/english/classics -> books/classics']);
$edit = array_values(array_filter($rows(), fn($x) => !in_array($x['id'], ['books'], true)));
$p = $t->prepare('categories', $edit, 'el');
check('removing a top term makes its children top terms', [array_values(array_column(array_filter($p['terms'], fn($x) => $x['id'] === 'english'), 'parent')), $p['moved'][0]['to'] ?? null], [[''], 'english']);
// A row that does not send a parent keeps the term\'s own.
$p = $t->prepare('categories', array_map(fn($x) => ['id' => $x['id'], 'slug' => $x['slug'], 'labels' => $x['labels']], $t->load('categories')['terms']), 'el');
check('rows sent without a parent keep what the terms have', [array_column($p['terms'], 'parent'), $p['moved']], [['', 'books', '', 'plays', 'english'], []]);
// A new term under a parent.
$p = $t->prepare('categories', array_merge($rows(), [['id' => '', 'slug' => 'english', 'parent' => 'books', 'labels' => ['el' => 'Αγγλικά', 'en' => 'English']]]), 'el');
check('a new term with the slug of a sibling gets another', [end($p['terms'])['slug'], end($p['terms'])['id'], $p['added']], ['english-2', 'anglika', ['anglika']]);

// A flat taxonomy ignores a parent in its file, and in a form.
$t->save('tags', 'Tags', [['id' => 'a', 'slug' => 'a', 'parent' => 'b', 'labels' => ['el' => 'A', 'en' => 'A']], ['id' => 'b', 'slug' => 'b', 'labels' => ['el' => 'B', 'en' => 'B']]]);
check('no tree in tags: a parent is dropped and the address is the slug', [$t->load('tags')['terms'][0]['parent'], $t->slug('tags', 'a'), $t->findByPath('tags', 'b/a')], ['', 'a', null]);
// A hand-edited file can not make a loop.
file_put_contents("$dir/taxonomies/looped.yaml", "title: Looped\nhierarchical: true\nterms:\n  - {id: a, slug: a, parent: b}\n  - {id: b, slug: b, parent: a}\n  - {id: c, slug: c, parent: ghost}\n");
check('a loop or a parent that is not a term is dropped when the file is read', array_column($t->load('looped')['terms'], 'parent'), ['', 'a', '']);
check('a site\'s own taxonomy can declare a tree, written only when it differs from the default', [$t->isHierarchical('looped')], [true]);
$t->save('industries', 'Industries', $t->load('industries')['terms'], null, true);
check('the flag is saved for a taxonomy that is not a tree by default, and can be turned off for categories', [str_contains((string)file_get_contents("$dir/taxonomies/industries.yaml"), 'hierarchical: true'), $t->isHierarchical('industries')], [true, true]);
// Turning the tree off flattens it, and the old addresses are listed.
$p = $t->prepare('categories', $rows(), 'el', false);
check('turning a tree off lists the changed addresses', array_map(fn($m) => $m['from'] . ' -> ' . $m['to'], $p['moved']), ['books/english -> english', 'plays/english -> english-2', 'books/english/classics -> classics']);
// The archive choice.
check('a parent\'s page lists its children\'s entries unless the archive says no', [$t->archive('categories', 'el', 'el')['include_children'], $t->includesChildren('categories'), $t->includesChildren('tags')], [true, true, false]);
$t->save('categories', 'Categories', $t->load('categories')['terms'], ['include_children' => false]);
check('and the choice is kept', [$t->archive('categories', 'el', 'el')['include_children'], $t->includesChildren('categories')], [false, false]);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
