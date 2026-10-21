<?php
/*
 * The pages of terms for sites of places: the defaults of a taxonomy whose pages list only types with a place (the Map layout,
 * unless the site chose another; every other taxonomy and a site without places as before), what its card stores, the page of a
 * term with the Map layout (the bar and the page's form without the page's own taxonomy), the chips of the sub-terms of a tree
 * (counted by the content index, a sub-term with no entries left out; a leaf with its siblings; none for a flat taxonomy or when
 * switched off), the Archive Layouts card, the term pages of a site's own taxonomy as real addresses, and the redirects of an
 * import put into the site (kept, repointed, a hand-made one left alone, loops and admin addresses refused, Greek addresses).
 *   php tests/unit/term-pages.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ArchiveBuilder, ArchiveQuery, ContentIndex, ContentRepository, ContentTypeAdmin, ContentTypes, ImportedRedirects, LanguageAlternates, LayoutsAdmin, MapFilters, Menus, PublicPaths, RedirectRepository, SingleLayouts, SystemDatabase, SystemMetaRepository, Taxonomies, TaxonomyEditor, TaxonomyPage, Theme, WordPressImporter};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/termpages' . getmypid();
foreach (['pages', 'posts', 'routes', 'points', 'forms', 'taxonomies'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/storage/db", 0775, true);
mkdir("$dir/custom", 0775, true);
symlink($repo . '/themes', "$dir/themes");
register_shutdown_function(function () use ($dir) { @exec('rm -rf ' . escapeshellarg($dir)); });

$term = static fn(string $id, string $el, string $en, string $parent = '', string $description = ''): array => ['id' => $id, 'slug' => $id, 'labels' => ['el' => $el, 'en' => $en]] + ($parent !== '' ? ['parent' => $parent] : []) + ($description !== '' ? ['descriptions' => ['el' => $description]] : []);
$tax = static fn(string $name, array $data) => file_put_contents("$dir/content/taxonomies/$name.yaml", Yaml::dump($data, 6, 2));
$tax('categories', ['title' => 'Categories', 'terms' => [$term('news', 'Νέα', 'News')]]);
$tax('tags', ['title' => 'Tags', 'terms' => [$term('one', 'Ένα', 'One')]]);
$tax('activity', ['title' => 'Activity', 'hierarchical' => true, 'terms' => [
    $term('hiking', 'Πεζοπορία', 'Hiking', '', 'Μονοπάτια με τα πόδια.'), $term('gorges', 'Φαράγγια', 'Gorges', 'hiking'), $term('ridges', 'Κορυφογραμμές', 'Ridges', 'hiking'),
    $term('canyoning', 'Κανιόνινγκ', 'Canyoning', 'hiking'), $term('cycling', 'Ποδήλατο', 'Cycling'),
], 'archive' => ['types' => ['routes', 'points']]]);
$tax('difficulty', ['title' => 'Difficulty', 'terms' => [$term('easy', 'Εύκολη', 'Easy'), $term('hard', 'Δύσκολη', 'Hard')], 'archive' => ['types' => ['routes']]]);
$tax('topics', ['title' => 'Topics', 'terms' => [$term('food', 'Φαγητό', 'Food'), $term('wine', 'Κρασί', 'Wine')], 'archive' => ['types' => ['posts', 'routes']]]);
$entry = static function (string $type, string $slug, array $front) use ($dir): void {
    file_put_contents("$dir/content/$type/$slug.md", "---\n" . Yaml::dump(['title' => ucfirst($slug), 'status' => 'published', 'date' => '2026-01-0' . (strlen($slug) % 9 + 1)] + $front, 4, 2) . "---\n\nText\n");
};
$entry('routes', 'gorge-walk', ['activity' => ['gorges'], 'difficulty' => ['hard'], 'custom_fields' => ['location' => '35.3, 24.1']]);
$entry('routes', 'ridge-walk', ['activity' => ['ridges'], 'difficulty' => ['easy'], 'custom_fields' => ['location' => '35.4, 24.2']]);
$entry('routes', 'bike-loop', ['activity' => ['cycling'], 'difficulty' => ['easy'], 'custom_fields' => ['location' => '35.5, 24.3']]);
$entry('points', 'gorge-mouth', ['activity' => ['gorges'], 'custom_fields' => ['location' => '35.31, 24.11']]);
$entry('points', 'old-trail', ['activity' => ['hiking'], 'custom_fields' => ['location' => '35.32, 24.12']]);
$entry('posts', 'note', ['topics' => ['food']]);

$settings = ['title' => 'Site', 'base_url' => 'https://s.test', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'content_types' => ['pages', 'posts', 'routes', 'points', 'forms']];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$theme = new Theme($dir, 'default');
$types = new ContentTypes($theme);
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings, fn() => $types->catalogue());
$places = ['routes', 'points'];
$placesOf = function () use (&$places): array { return $places; };
$taxonomies = (new Taxonomies("$dir/content", ['el', 'en']))->withPlaceTypes($placesOf);
$plain = new Taxonomies("$dir/content", ['el', 'en']);
$layout = static fn(Taxonomies $t, string $name): string => $t->archive($name, 'el', 'el')['layout'];

// ---- the defaults of a taxonomy of places
check('a taxonomy whose pages list only types with a place opens as a map', [$layout($taxonomies, 'activity'), $layout($taxonomies, 'difficulty')], ['map', 'map']);
check('one that lists another type too, or every type (categories, tags), keeps the cards', [$layout($taxonomies, 'topics'), $layout($taxonomies, 'categories'), $layout($taxonomies, 'tags')], ['cards', 'cards', 'cards']);
check('and changes in nothing at all', [$taxonomies->archive('topics', 'el', 'el') === $plain->archive('topics', 'el', 'el'), $taxonomies->archive('categories', 'el', 'el') === $plain->archive('categories', 'el', 'el'), $taxonomies->archive('tags', 'en', 'el') === $plain->archive('tags', 'en', 'el')], [true, true, true]);
check('a store that is not told the types with a place is as before', [$layout($plain, 'activity'), $plain->archiveDefaults('activity')], ['cards', []]);
$places = [];
check('a site with no types with a place: nothing changes', [$layout($taxonomies, 'activity'), $taxonomies->archive('activity', 'el', 'el') === $plain->archive('activity', 'el', 'el')], ['cards', true]);
$places = ['routes', 'points'];
check('the defaults follow the types the pages would list', [$taxonomies->archiveDefaults('topics', ['routes']), $taxonomies->archiveDefaults('activity', ['routes', 'posts']), $taxonomies->archiveDefaults('activity', [])], [['layout' => 'map'], [], []]);
$own = Yaml::parseFile("$dir/content/taxonomies/difficulty.yaml");
$tax('difficulty', ['archive' => ['types' => ['routes'], 'layout' => 'list']] + $own);
$taxonomies->forget();
check('what the site chose wins', $layout($taxonomies, 'difficulty'), 'list');
$tax('difficulty', $own);
$taxonomies->forget();
check('the chips of the sub-terms are on unless switched off', [$taxonomies->archive('activity', 'el', 'el')['sub_term_chips'], $plain->archive('categories', 'el', 'el')['sub_term_chips']], [true, true]);

// ---- what the card stores
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$redirects = new RedirectRepository($db);
$menus = new Menus("$dir/content", fn() => $settings, fn(string $k, ?string $f = null) => $f ?? $k, fn() => []);
$editor = new TaxonomyEditor($taxonomies, $redirects, $content, fn() => $menus);
$form = static fn(array $input): array => $input + ['columns' => '3', 'per_page' => 12, 'order' => 'date_desc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'map_height' => 'large', 'map_list' => 'right', 'title_layout' => 'default', 'types' => ['routes', 'points'], 'children_shown' => '1', 'include_children' => '1', 'sub_term_chips' => '1'];
check('choosing the Map for a taxonomy of places stores no layout', array_key_exists('layout', $editor->archiveFromInput('activity', $form(['layout' => 'map']))), false);
check('choosing the cards stores them, so they stay', $editor->archiveFromInput('activity', $form(['layout' => 'cards']))['layout'] ?? null, 'cards');
check('the Map kept while the pages start listing every type is stored, so it stays a map', $editor->archiveFromInput('activity', $form(['layout' => 'map', 'types' => ['posts', 'routes', 'points']]))['layout'] ?? null, 'map');
check('a taxonomy of other types stores the Map as before', $editor->archiveFromInput('topics', $form(['layout' => 'map', 'types' => ['posts', 'routes']]))['layout'] ?? null, 'map');
check('chips switched off are stored, on is nothing', [$editor->archiveFromInput('activity', $form(['layout' => 'map', 'sub_term_chips' => null]))['sub_term_chips'] ?? null, array_key_exists('sub_term_chips', $editor->archiveFromInput('activity', $form(['layout' => 'map'])))], [false, false]);
check('a form without the row leaves the chips as they are', array_key_exists('sub_term_chips', $editor->archiveFromInput('activity', ['layout' => 'map', 'types' => ['routes', 'points']])), false);

// ---- the page of a term
$database = new SystemDatabase("$dir/storage");
$database->initialize();
$index = new ContentIndex($database, "$dir/content", new SystemMetaRepository($database));
$index->keepReferences(fn(string $type): array => $types->definition($type, 'el', 'el')['fields']);
$index->keepTerms(fn(): array => $taxonomies->names());
$filters = new MapFilters(fn(): Taxonomies => $taxonomies, fn(string $type): ?array => null);
$labels = fn(string $t, string $id, string $lang): string => $taxonomies->label($t, $id, $lang);
$mapNames = fn(array $types, ?array $chosen): array => $filters->names($types, $chosen);
$indexed = new ArchiveBuilder(fn() => $taxonomies->names(), $labels, new ArchiveQuery($index, $content), null, $mapNames);
$scanned = new ArchiveBuilder(fn() => $taxonomies->names(), $labels, null, null, $mapNames);
$absolute = fn(string $path): string => 'https://s.test/' . ltrim($path, '/');
$alternates = new LanguageAlternates($content, fn() => $taxonomies, fn() => $settings, $absolute);
$translate = fn(string $key, ?string $fallback = null): string => $fallback ?? $key;
$pageWith = fn(ArchiveBuilder $builder): TaxonomyPage => new TaxonomyPage($content, $theme, fn() => $taxonomies, fn() => $builder, fn() => $alternates, fn() => $settings, $translate);
$page = $pageWith($indexed);
$slugs = static fn(array $items): array => array_values(array_map(static fn($i): string => $i->type . '/' . $i->slug, $items));

$hiking = $page->build(['activity', 'hiking'], 'el', ['lang' => 'el'], []);
$data = $hiking['data'];
check('a term of a taxonomy of places has the Map layout, every entry of it and its sub-terms on one page', [$data['archive']['settings']['layout'], count($data['items']), $data['archive']['pages']], ['map', 4, 1]);
check('the term\'s description under its title', [$data['archive_title'], $data['archive_subtitle']], ['Activity: Πεζοπορία', 'Μονοπάτια με τα πόδια.']);
check('the bar over the map offers the other taxonomies of its types, not its own', $data['archive']['settings']['map_filters'], ['categories', 'difficulty', 'topics']);
check('and so does the page\'s own form (those with terms in use)', array_column($data['archive']['filters'], 'name'), ['difficulty']);
check('the chips: All (this page, with its number) and each sub-term with entries, counted by the content index', [$data['term_chips']['all'], array_map(static fn(array $c): array => [$c['label'], $c['url'], $c['count'], $c['current']], $data['term_chips']['items'])], [['label' => 'All', 'url' => '/activity/hiking', 'current' => true, 'count' => 4], [['Φαράγγια', '/activity/hiking/gorges', 2, false], ['Κορυφογραμμές', '/activity/hiking/ridges', 1, false]]]);
check('named after the term', $data['term_chips']['label'], 'Πεζοπορία');
$leaf = $page->build(['activity', 'hiking', 'gorges'], 'el', ['lang' => 'el'], [])['data'];
check('a sub-term without sub-terms of its own: its parent\'s, with it marked, and All leading up', [$leaf['term_chips']['label'], $leaf['term_chips']['all'], array_map(static fn(array $c): array => [$c['label'], $c['url'], $c['current'], $c['count']], $leaf['term_chips']['items'])], ['Πεζοπορία', ['label' => 'All', 'url' => '/activity/hiking', 'current' => false, 'count' => 4], [['Φαράγγια', '/activity/hiking/gorges', true, 2], ['Κορυφογραμμές', '/activity/hiking/ridges', false, 1]]]);
check('no entries in a language: no chips to offer', $page->build(['activity', 'hiking'], 'en', [], [])['data']['term_chips'], null);
$english = $pageWith($scanned)->build(['activity', 'hiking', 'gorges'], 'en', [], [])['data']['term_chips'];
check('in another language the chips have its names and addresses', [$english['label'], $english['all']['url'], array_column($english['items'], 'url', 'label')], ['Hiking', '/en/activity/hiking', ['Gorges' => '/en/activity/hiking/gorges', 'Ridges' => '/en/activity/hiking/ridges', 'Canyoning' => '/en/activity/hiking/canyoning']]);
check('a term at the top without sub-terms has no chips', $page->build(['activity', 'cycling'], 'el', [], [])['data']['term_chips'], null);
check('nor does a flat taxonomy; its page is a map of its own type', [$page->build(['difficulty', 'easy'], 'el', [], [])['data']['term_chips'], $slugs($page->build(['difficulty', 'easy'], 'el', [], [])['data']['items']), $page->build(['difficulty', 'easy'], 'el', [], [])['data']['archive']['settings']['map_filters']], [null, ['routes/ridge-walk', 'routes/bike-loop'], ['activity', 'categories', 'topics']]);
$scan = $pageWith($scanned)->build(['activity', 'hiking'], 'el', [], [])['data'];
check('without the content index every sub-term is a chip, without a number', array_map(static fn(array $c): array => [$c['label'], $c['count']], $scan['term_chips']['items']), [['Φαράγγια', null], ['Κορυφογραμμές', null], ['Κανιόνινγκ', null]]);
check('and the page lists the same entries', $slugs($scan['items']), $slugs($data['items']));
$topics = $page->build(['topics', 'food'], 'el', [], [])['data'];
check('a taxonomy that is not of places is as before: cards, paged, no chips', [$topics['archive']['settings']['layout'], $slugs($topics['items']), $topics['term_chips']], ['cards', ['posts/note'], null]);
$activity = Yaml::parseFile("$dir/content/taxonomies/activity.yaml");
$tax('activity', ['archive' => ['types' => ['routes', 'points'], 'sub_term_chips' => false, 'map_filters' => ['difficulty', 'activity']]] + $activity);
$taxonomies->forget();
$off = $page->build(['activity', 'hiking'], 'el', [], [])['data'];
check('chips switched off on the card: none; a choice of the bar that names the taxonomy itself still leaves it out', [$off['term_chips'], $off['archive']['settings']['map_filters']], [null, ['difficulty']]);
$tax('activity', $activity);
$taxonomies->forget();

// ---- the Archive Layouts card
$typeAdmin = new ContentTypeAdmin($types, "$dir/content", fn() => $taxonomies->names(), fn(string $key) => in_array($key, ['title', 'slug', 'status'], true), function () {});
$admin = new LayoutsAdmin($theme, new SingleLayouts($theme), $types, $typeAdmin, $taxonomies, $editor, fn() => $content->getTypes(), fn(string $t) => count(glob("$dir/content/$t/*.md") ?: []), fn(string $t) => rtrim($t, 's'));
$cards = array_column($admin->archiveCards('el')['taxonomies'], null, 'name');
check('the card of a taxonomy of places says its pages are maps by default; others do not', [$cards['activity']['place_default'], $cards['difficulty']['place_default'], $cards['topics']['place_default'], $cards['categories']['place_default']], [true, true, false, false]);
check('and shows the Map layout and the chips', [$cards['activity']['archive']['layout'], $cards['activity']['archive']['sub_term_chips']], ['map', true]);
check('its Map filters row does not offer the taxonomy itself', [in_array('activity', array_column($cards['activity']['map_filters']['options'], 'name'), true), in_array('activity', $cards['activity']['map_filters']['automatic'], true), in_array('difficulty', $cards['activity']['map_filters']['automatic'], true)], [false, false, true]);
$admin->saveArchives([], ['activity' => $form(['layout' => 'map', 'map_filters_auto' => implode(',', $cards['activity']['map_filters']['automatic']), 'map_filters' => $cards['activity']['map_filters']['automatic']])], 'el');
$taxonomies->forget();
check('saving the card as it was shown leaves the file as it was', Yaml::parseFile("$dir/content/taxonomies/activity.yaml")['archive'], ['types' => ['routes', 'points']]);

// ---- the pages of a site's own taxonomy are pages of the site
$paths = new PublicPaths($content, fn() => $taxonomies, fn() => $settings, fn() => 'https://s.test');
check('a term of a site\'s own taxonomy is a page, in the tree and in another language; a term it does not have is not', [$paths->exists('activity/hiking'), $paths->exists('activity/hiking/gorges'), $paths->exists('en/activity/hiking/gorges'), $paths->exists('activity/gorges'), $paths->exists('activity/nothing'), $paths->exists('category/news')], [true, true, true, false, false, true]);

// ---- the redirects of an import, put into the site
$list = [
    ['/activity/παρατήρηση-πουλιών', '/activity/hiking'],
    ['/en/activity/bird-watching', '/en/activity/hiking/gorges'],
    ['/route/gorge-walk', '/routes/gorge-walk'],
    ['/Activity/Old-Name/', '/activity/hiking'],
    ['/activity/old-name', '/activity/cycling'],
    ['/admin/old', '/activity/hiking'],
    ['/activity/hiking', '/activity/παρατήρηση-πουλιών'],
];
check('an old address typed or written as Greek letters is the address a browser asks for', [RedirectRepository::normalizePath('/Activity/Παρατήρηση-Πουλιών/'), RedirectRepository::normalizePath('/activity/' . rawurlencode('παρατήρηση-πουλιών')), RedirectRepository::normalizePath('/a b/ç?x=1')], ['activity/παρατήρηση-πουλιών', 'activity/παρατήρηση-πουλιών', 'a b/ç']);
$imported = new ImportedRedirects($redirects);
$dry = $imported->apply($list, false);
check('the dry run says what it would add and why it leaves some out, and adds nothing', [$dry['added'], array_column($dry['skipped'], 'reason'), $redirects->count()], [4, ['duplicate', 'source_admin', 'loop'], 0]);
$first = $imported->apply($list);
check('the run adds them; a line that would loop back is refused', [$first['added'], array_column($first['skipped'], 'reason')], [4, ['duplicate', 'source_admin', 'loop']]);
check('a Greek old address, asked for as a browser sends it, leads to the term\'s page', $redirects->resolve('/activity/' . rawurlencode('παρατήρηση-πουλιών') . '/'), ['id' => $redirects->findBySource('activity/παρατήρηση-πουλιών')['id'], 'target' => '/activity/hiking', 'code' => 301]);
check('and every target is a page of the site', array_map(fn(array $l): bool => $paths->exists(RedirectRepository::normalizePath($l[1])), array_slice($list, 0, 4)), [true, true, true, true]);
$again = $imported->apply($list);
check('a second run changes nothing', [$again['added'], $again['updated'], $again['kept'], $redirects->count()], [0, 0, 4, 4]);
$moved = $list;
$moved[0][1] = '/activity/cycling';
check('a redirect an earlier import made follows a new target', [$imported->apply($moved)['updated'], $redirects->resolve('/activity/παρατήρηση-πουλιών')['target']], [1, '/activity/cycling']);
$redirects->update((int)$redirects->findBySource('route/gorge-walk')['id'], 'route/gorge-walk', '/points/gorge-mouth', 301, true, 'Set by hand');
check('one the site changed by hand is left alone', [array_column($imported->apply($list)['skipped'], 'reason', 'from')['/route/gorge-walk'] ?? '', $redirects->resolve('/route/gorge-walk')['target']], ['taken', '/points/gorge-mouth']);
check('the lines of a redirects file', ImportedRedirects::parse(WordPressImporter::redirectList([['/a', '/b'], ['/en/a', '/en/b']]) . "\n# a note\n/c\n/d /e 302\n"), [['/a', '/b'], ['/en/a', '/en/b'], ['/d', '/e']]);
$none = new ImportedRedirects(new RedirectRepository(new SystemDatabase("$dir/nowhere")));
check('without a database nothing is added and every line says so', array_unique(array_column($none->apply($list)['skipped'], 'reason')), ['no_database']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
