<?php
/*
 * The smaller admin screens: menus (list, create, copy, edit, delete), the activity and email logs, the notices the
 * site raises by itself, the Updates screen, and the Taxonomies screen with its form.
 *   php tests/unit/admin-screens.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ActivityLogRepository, AdminNotices, BackupAdmin, BackupManager, BackupRunRepository, BackupService, ContentRepository, EmailLogRepository, LogAdmin, MenuAdmin, Menus, NotificationRepository, RedirectRepository, SystemDatabase, SystemMetaRepository, TaxonomyEditor, Taxonomies, MaintenanceMode, UpdateAdmin, UpdateInstaller, UpdateService};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/adminscr' . getmypid();
foreach (['content/pages', 'content/posts', 'content/taxonomies', 'content/menus', 'storage', 'public/uploads', 'custom'] as $d) { mkdir("$dir/$d", 0775, true); }
file_put_contents("$dir/VERSION", "0.0.1\n");
file_put_contents("$dir/CHANGELOG.md", "# Changelog\n\n## 0.0.1 — 2026-01-01 — First\n\n- One thing.\n");
$settings = ['title' => 'Site', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'content_types' => ['pages', 'posts']];
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$log = [];
$logger = function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = [$action, $level, $id]; };

// ---- menus
$menus = new Menus("$dir/content", fn() => $settings, fn(string $k, ?string $f = null) => $f ?? $k, fn() => []);
$menuAdmin = new MenuAdmin($menus, fn() => $settings, $logger);
$list = $menuAdmin->overview(['saved' => '1']);
check('the list has the two menus every site has', [array_column($list['rows'], 'key'), $list['saved'], $list['created'], $list['deleted'], $list['admin_section']], [['footer', 'main'], true, false, false, 'menus']);

$r = $menuAdmin->create(['new_key' => 'Side Bar', 'new_title' => ' Side '], [], false);
check('the page to make a menu is filled from the address', [$r['location'], $r['view']['new_key'], $r['view']['new_title'], $r['view']['error'], $r['view']['menu_keys']], ['', 'side-bar', 'Side', '', ['footer', 'main']]);
check('a menu to copy fills in the key', $menuAdmin->create(['source_key' => 'main'], [], false)['view']['new_key'], 'main');
check('a key is required', $menuAdmin->create([], ['new_key' => '  '], true)['view']['error'], 'Menu key is required.');
check('and not one that exists', $menuAdmin->create([], ['new_key' => 'main'], true)['view']['error'], 'Menu key already exists.');
$log = [];
$r = $menuAdmin->create([], ['new_key' => 'Side Bar', 'new_title' => ''], true);
check('a menu is made, titled from its key', [$r['location'], $menus->load('side-bar')['title'], $menus->load('side-bar')['items'], $log], ['/admin/menus-edit?key=side-bar&created=1', 'Side Bar', [], [['menus.create', 'info', 'side-bar']]]);
$menus->write('main', ['title' => 'Main Menu', 'items' => [['label_key' => 'a', 'url' => 'about']]]);
$r = $menuAdmin->create([], ['new_key' => 'copy', 'new_title' => 'My copy', 'source_key' => 'main'], true);
check('a copy has the items of the other and its own title', [$menus->load('copy')['title'], count($menus->load('copy')['items'])], ['My copy', 1]);
$r = $menuAdmin->create([], ['new_key' => 'copy2', 'source_key' => 'main'], true);
check('a copy with no title takes the title of the other', $menus->load('copy2')['title'], 'Main Menu');

check('with no menu asked for the first one opens', $menuAdmin->edit([], [], false)['location'], '/admin/menus-edit?key=copy');
check('a menu that does not exist goes back to the list', $menuAdmin->edit(['key' => 'nope'], [], false)['location'], '/admin/menus');
$md = new MarkdownConverter((function () { $e = new Environment([]); $e->addExtension(new CommonMarkCoreExtension()); return $e; })());
file_put_contents("$dir/content/pages/about.md", "---\ntitle: Σχετικά\ntranslation_id: t1\n---\n\nText\n");
file_put_contents("$dir/content/pages/about.en.md", "---\ntitle: About\ntranslation_id: t1\n---\n\nText\n");
file_put_contents("$dir/content/pages/index.md", "---\ntitle: Αρχική\n---\n\nText\n");
$placed = [];
$editorMenus = new Menus("$dir/content", fn() => $settings, fn(string $k, ?string $f = null) => $f ?? $k, fn() => ['sidebar' => ['label' => 'Sidebar', 'default' => 'side-bar']]);
$editorAdmin = new MenuAdmin(
    $editorMenus,
    fn() => $settings,
    $logger,
    new GnozioCMS\MenuSources(new ContentRepository("$dir/content", $md, $settings), fn() => new Taxonomies("$dir/content", ['el', 'en']), fn() => $settings),
    fn(string $key, string $lang) => $key === 'nav.main.about' ? ['el' => 'Σχετικά', 'en' => 'About us'][$lang] : '',
    function (string $location, string $menu) use (&$placed): bool { $placed[] = "$location=$menu"; return true; }
);
(new Taxonomies("$dir/content", ['el', 'en']))->save('categories', 'Categories', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News'], 'descriptions' => []]]);
$e = $editorAdmin->edit(['key' => 'main', 'saved' => '1'], [], false)['view'];
$payload = json_decode($e['editor_json'], true);
check('a menu opens with its title, and the page gets the data for the editor', [$e['menu_key'], $e['menu_title'], $e['languages'], $e['saved'], $payload['key'], $payload['default_language'], $payload['max_items']], ['main', 'Main Menu', ['el', 'en'], true, 'main', 'el', 300]);
check('the items come as rows with their depth, the labels of every language and what the theme says for a theme key', [count($payload['items']), $payload['items'][0]['depth'], array_keys($payload['items'][0]['labels']), $payload['items'][0]['hints']], [1, 1, ['el', 'en'], ['el' => '', 'en' => '']]);
check('the languages are named in themselves', array_column($payload['languages'], 'name', 'code'), ['el' => 'Ελληνικά', 'en' => 'English']);
$editorMenus->write('with-key', ['title' => 'K', 'items' => [['label_key' => 'nav.main.about', 'url' => 'about'], ['label_key' => 'nav.main.nope', 'url' => '']]]);
$k = json_decode($editorAdmin->edit(['key' => 'with-key'], [], false)['view']['editor_json'], true);
check('a label left to the theme shows the theme\'s text in each language, and nothing when the theme has none', [$k['items'][0]['hints'], $k['items'][1]['hints']], [['el' => 'Σχετικά', 'en' => 'About us'], ['el' => '', 'en' => '']]);
$groups = array_column($payload['groups'], null, 'id');
check('what can be added: pages, posts, the lists, and the taxonomies that have terms (tags have none here)', array_keys($groups), ['pages', 'posts', 'lists', 'taxonomy-categories']);
check('a page is offered once for all its languages, with its title in each', array_map(fn($i) => [$i['title'], $i['url'], $i['labels']], $groups['pages']['items']), [['Σχετικά', 'about', ['el' => 'Σχετικά', 'en' => 'About']]]);
check('the home page is offered with the lists, with the list of each type and the search', array_map(fn($i) => $i['url'], $groups['lists']['items']), ['', 'posts', 'search']);
check('a category is offered with the address a menu item takes, and its name in each language', [$groups['taxonomy-categories']['items'][0]['url'], $groups['taxonomy-categories']['items'][0]['labels'], $groups['taxonomy-categories']['items'][0]['detail']], ['category/news', ['el' => 'Νέα', 'en' => 'News'], 'Category']);
check('and the places of the theme, with the menu each shows and which cannot be changed from here', [array_column($payload['locations'], 'menu', 'key'), array_column($payload['locations'], 'locked', 'key'), $payload['can_place']], [['header' => 'main', 'footer' => 'footer', 'sidebar' => 'side-bar'], ['header' => true, 'footer' => false, 'sidebar' => false], true]);
$e = $menuAdmin->edit(['key' => 'side-bar'], [], false)['view'];
$bare = json_decode($e['editor_json'], true);
check('an empty menu opens with no rows, and without the sources and places when the screen has none', [$bare['items'], $bare['groups'], $bare['can_place']], [[], [], false]);
$log = [];
$rows = fn(array $list) => json_encode($list);
$r = $editorAdmin->edit(['key' => 'side-bar'], ['menu_action' => 'save', 'menu_title' => '', 'menu_json' => $rows([
    ['depth' => 1, 'labels' => ['el' => 'Αρχική', 'en' => 'Home'], 'url' => '/'],
    ['depth' => 1, 'labels' => ['el' => 'Επαφή', 'en' => 'Contact'], 'url' => 'contact', 'class' => 'nav-cta', 'hidden' => true],
    ['depth' => 2, 'labels' => ['el' => 'Παιδί'], 'url' => 'child'],
])], true);
$menus->forget();
check('saving writes the items and the title (from the key when empty) and goes back to the editor', [$r['location'], count($menus->load('side-bar')['items']), $menus->load('side-bar')['title'], $log[0][0]], ['/admin/menus-edit?key=side-bar&saved=1', 2, 'Side Bar', 'menus.update']);
check('the nesting and the attributes are kept', [$menus->load('side-bar')['items'][1]['children'][0]['url'], $menus->load('side-bar')['items'][1]['class'], $menus->load('side-bar')['items'][1]['hidden']], ['child', 'nav-cta', true]);
foreach (['', 'not json', '{"a":1}', '"x"', json_encode(array_fill(0, MenuAdmin::MAX_ITEMS + 1, ['depth' => 1, 'url' => 'a']))] as $bad) {
    $before = file_get_contents($menus->path('side-bar'));
    $r = $editorAdmin->edit(['key' => 'side-bar'], ['menu_action' => 'save', 'menu_title' => 'Typed', 'menu_json' => $bad], true);
    check('a field that is not a list of items saves nothing, and the editor opens again with the reason and the title typed: ' . substr($bad, 0, 12), [$r['location'], $r['view']['error'] !== '', $r['view']['menu_title'], file_get_contents($menus->path('side-bar')) === $before], ['', true, 'Typed', true]);
}
$placed = [];
$editorAdmin->edit(['key' => 'side-bar'], ['menu_action' => 'save', 'menu_json' => $rows([]), 'menu_locations_present' => '1', 'menu_locations' => ['footer', 'header']], true);
check('ticked places get the menu, and a place already showing it is left alone', $placed, ['header=side-bar', 'footer=side-bar']);
$placed = [];
$editorAdmin->edit(['key' => 'main'], ['menu_action' => 'save', 'menu_json' => $rows([['depth' => 1, 'url' => 'a']]), 'menu_locations_present' => '1', 'menu_locations' => []], true);
check('a place unticked goes back to the theme\'s own menu, unless this is that menu', $placed, []);
$shown = $settings + ['menu_locations' => ['header' => 'side-bar']];
$shownMenus = new Menus("$dir/content", fn() => $shown, fn(string $k, ?string $f = null) => $f ?? $k, fn() => []);
$shownAdmin = new MenuAdmin($shownMenus, fn() => $shown, $logger, null, null, function (string $location, string $menu) use (&$placed): bool { $placed[] = "$location=$menu"; return true; });
$placed = [];
$shownAdmin->edit(['key' => 'side-bar'], ['menu_action' => 'save', 'menu_json' => $rows([]), 'menu_locations_present' => '1', 'menu_locations' => []], true);
check('a place unticked goes back to the theme\'s own menu', $placed, ['header=main']);
$placed = [];
$shownAdmin->edit(['key' => 'side-bar'], ['menu_action' => 'save', 'menu_json' => $rows([]), 'menu_locations_present' => '1', 'menu_locations' => ['header']], true);
check('and one that stays ticked is left alone', $placed, []);
$placed = [];
$editorAdmin->edit(['key' => 'side-bar'], ['menu_action' => 'save', 'menu_json' => $rows([])], true);
check('a form without places in it changes none', $placed, []);
$plain = new MenuAdmin($editorMenus, fn() => $settings, $logger);
$plain->edit(['key' => 'side-bar'], ['menu_action' => 'save', 'menu_json' => $rows([]), 'menu_locations_present' => '1', 'menu_locations' => ['header']], true);
check('and without the right to change settings no place is changed', $placed, []);
@unlink("$dir/content/taxonomies/categories.yaml");
$log = [];
$r = $menuAdmin->edit(['key' => 'side-bar'], ['menu_action' => 'delete'], true);
check('deleting removes the file and goes to the list', [$r['location'], is_file($menus->path('side-bar')), $log], ['/admin/menus?deleted=1', false, [['menus.delete', 'warning', 'side-bar']]]);
check('the key can come with the form', $menuAdmin->edit([], ['key' => 'ghost'], true)['location'], '/admin/menus');

// ---- logs
$activity = new ActivityLogRepository($db);
$email = new EmailLogRepository($db);
for ($i = 1; $i <= 60; $i++) {
    $activity->record(['level' => $i % 2 ? 'info' : 'warning', 'action' => $i % 3 ? 'content.update' : 'auth.login', 'actor_username' => 'ada', 'message' => "Entry $i", 'subject_type' => 'pages']);
}
$email->record(['status' => 'sent', 'provider' => 'smtp', 'recipient' => 'a@x.test', 'subject' => 'Hello']);
$email->record(['status' => 'failed', 'provider' => 'mail', 'recipient' => 'b@x.test', 'subject' => 'Hi', 'error' => 'No']);
$logs = new LogAdmin($activity, $email, $logger);
$a = $logs->activity([]);
check('the first page of 50, newest first, with the options to filter by', [count($a['logs']), $a['total_logs'], $a['page'], $a['total_pages'], $a['per_page'], $a['per_page_options'], $a['actions'], $a['subject_types'], $a['admin_section']], [50, 60, 1, 2, 50, [25, 50, 100], ['auth.login', 'content.update'], ['pages'], 'activity']);
check('the last page has the rest', [count($logs->activity(['page' => '2'])['logs']), $logs->activity(['page' => '2'])['page']], [10, 2]);
check('a page past the end shows the last', $logs->activity(['page' => '99'])['page'], 2);
check('the page size is one of the options', [$logs->activity(['per_page' => '25'])['per_page'], count($logs->activity(['per_page' => '25'])['logs']), $logs->activity(['per_page' => '7'])['per_page'], $logs->activity(['page' => '-3'])['page']], [25, 25, 50, 1]);
$a = $logs->activity(['level' => 'warning', 'action' => 'auth.login']);
check('filters narrow it, and come back cleaned', [$a['total_logs'], $a['filters']['level'], $a['filters']['q']], [10, 'warning', '']);
check('the search reads the message', $logs->activity(['q' => ' Entry 59 '])['total_logs'], 1);
$m = $logs->email(['status' => 'failed']);
check('the email log, filtered', [$m['total_logs'], $m['logs'][0]['recipient'], $m['providers'], $m['cleared'], $m['admin_section']], [1, 'b@x.test', ['mail', 'smtp'], null, 'email_logs']);
check('what was cleared is shown', $logs->email(['cleared' => '2'])['cleared'], 2);
$log = [];
check('the email log can be cleared, and says how many went', [$logs->clearEmail(), $email->count(), $log], [2, 0, [['email_logs.clear', 'warning', 'all']]]);

// ---- notices
$notes = new NotificationRepository($db);
$meta = new SystemMetaRepository($db);
// Local addresses, so nothing here reaches the network.
$updateSettings = ['latest_version' => '9.9.9', 'version_url' => 'file:///nowhere', 'changelog_url' => 'file:///nowhere'];
$updates = new UpdateService($dir, $updateSettings, $meta);
$checks = [['label' => 'Disk space', 'value' => '2% free', 'status' => 'error'], ['label' => 'PHP', 'value' => '8.3', 'status' => 'ok']];
$notices = new AdminNotices($notes, fn() => $updates, function () use (&$checks) { return $checks; });
$notices->syncUpdate(['has_update' => false]);
check('no update, no notice', $notes->unreadCount(), 0);
$status = $updates->status();
$notices->syncUpdate($status);
$notices->syncUpdate($status);
check('a new version is told once', [$notes->unreadCount(), $notes->recent(5)[0]['title']], [1, 'GnozioCMS 9.9.9 is available']);
$notices->sync(false);
check('a system check that is not fine raises a notice, the fine one does not', array_map(fn($n) => $n['title'], $notes->recent(5)), ['System check: Disk space', 'GnozioCMS 9.9.9 is available']);
$notices->sync(true);
check('and syncing again raises nothing twice', $notes->unreadCount(), 2);
$broken = new AdminNotices($notes, fn() => throw new RuntimeException('no'), fn() => throw new RuntimeException('no'));
$broken->sync(true);
check('a failure never gets in the way', true, true);

// ---- updates
$service = new BackupService($dir, $db);
$runs = new BackupRunRepository($db);
$manager = new BackupManager($service, $db, $runs, $notes, fn() => $settings, fn() => $updates, fn(string $iso) => null, fn(array $c) => throw new RuntimeException('no remote'));
$backupAdmin = new BackupAdmin($service, $manager, $runs, $notes, $meta, fn() => $settings, fn(string $iso) => null, fn(bool $ok) => null, function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx, ?array $actor) use (&$log) { $log[] = [$action]; });
$installer = new UpdateInstaller($dir, $db, new MaintenanceMode($dir), fn(string $u, string $d, int $m): ?string => 'No network in tests.', fn(string $t, string $v): array => ['reached' => false, 'ok' => false, 'message' => 'none'], $logger);
$updateAdmin = new UpdateAdmin(fn() => $updates, $notices, fn() => $backupAdmin, fn() => $installer, $meta, $service, "$dir/content", $dir, $logger);
$log = [];
check('checking for an update says where to go and is logged', [$updateAdmin->check(), $log[0][0]], ['/admin/updates?checked=1', 'updates.check']);
$u = $updateAdmin->screen(['checked' => '1']);
check('the screen shows the versions and that an update is there', [$u['current_version'], $u['latest_version'], $u['has_update'], $u['checked'], $u['update_source_status']], ['0.0.1', '9.9.9', true, true, 'unreachable']);
check('and the release notes, from the local file when the project cannot be reached', [$u['changelog_source'], $u['changelog_entries'][0]['version']], ['local', '0.0.1 — 2026-01-01 — First']);
check('and where updates come from', [$u['update_repository'], $u['update_branch'], $u['update_channel']], ['chiotis/faroscms', 'main', 'stable']);
check('no backup yet', [$u['latest_backup'], $u['backup_total'], $u['pre_update_backup'], $u['pre_backup_status']], [null, 0, null, '']);
$byLabel = fn(array $rows) => array_column($rows, null, 'label');
$c = $byLabel($u['preflight_checks']);
check('what has to be in order, with the backup missing', [$c['Verified pre-update backup']['value'], $c['Verified pre-update backup']['status'], $c['PHP compatibility']['status'], $c['Content writable']['value'], $c['Storage writable']['value'], $c['Update source']['value']], ['missing', 'warning', 'ok', 'yes', 'yes', 'configured']);
$loc = $updateAdmin->backupFirst();
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
check('the backup before updating is made and the way back says so', [str_starts_with($loc, '/admin/updates?'), $q['pre_backup'], $q['pre_backup_msg'] !== ''], [true, 'ok', true]);
$u = $updateAdmin->screen(['pre_backup' => 'ok', 'pre_backup_msg' => ' Done. ']);
check('then the screen knows it is ready', [$u['pre_update_backup']['verified'], $u['pre_update_backup']['version'], $byLabel($u['preflight_checks'])['Verified pre-update backup']['value'], $u['backup_total'], $u['pre_backup_status'], $u['pre_backup_message']], [true, '0.0.1', 'ready', 1, 'ok', 'Done.']);
$old = $updateAdmin->preflight($updates->sourceConfig(), ['filename' => 'x', 'created_at' => gmdate('c', time() - 3 * 86400), 'verified' => true, 'version' => '0.0.1']);
check('a backup older than a day is not ready', [$old[0]['value'], $old[0]['status']], ['older than 24h', 'warning']);
$other = $updateAdmin->preflight($updates->sourceConfig(), ['filename' => 'x', 'created_at' => gmdate('c'), 'verified' => false, 'version' => '0.0.1']);
check('nor one that was not verified', $other[0]['value'], 'not verified');
check('nor one made for another version', $updateAdmin->preflight($updates->sourceConfig(), ['filename' => 'x', 'created_at' => gmdate('c'), 'verified' => true, 'version' => '0.0.0'])[0]['status'], 'warning');
$noSource = $updateAdmin->preflight(['version_url' => ''] + $updates->sourceConfig(), null);
check('without an update source it says so', [$noSource[5]['value'], $noSource[5]['status']], ['not configured', 'warning']);

// ---- taxonomies
$content = new ContentRepository("$dir/content", new MarkdownConverter((function () { $e = new Environment([]); $e->addExtension(new CommonMarkCoreExtension()); return $e; })()), $settings);
$store = new Taxonomies("$dir/content", ['el', 'en']);
file_put_contents("$dir/content/posts/one.md", "---\ntitle: One\ntags: [news]\ncategories: [world]\n---\n\nx\n");
file_put_contents("$dir/content/posts/two.md", "---\ntitle: Two\ntags: [news, other]\n---\n\nx\n");
$store->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']], ['id' => 'other', 'slug' => 'other', 'labels' => ['el' => 'Άλλα', 'en' => 'Other']]]);
$log = [];
$editor = new TaxonomyEditor($store, new RedirectRepository($db), $content, fn() => $menus, $logger);
check('the taxonomy asked for, or the first', [$editor->selected('tags'), $editor->selected('Categories'), $editor->selected('nonsense'), $editor->selected('')], ['tags', 'categories', 'categories', 'categories']);
$label = fn(string $t): string => strtoupper($t);
$s = $editor->screen('tags', ['saved' => '1', 'moved' => '2'], ['el', 'en'], 'el', true, $label);
check('the screen has the terms with how many entries are filed under each and in which type', [array_column($s['taxonomy_terms'], 'used', 'id'), $s['taxonomy_terms'][0]['used_by_type']], [['news' => 2, 'other' => 1], ['posts' => 2]]);
check('and the tabs to switch taxonomy with their counts', array_map(fn($t) => [$t['name'], $t['terms'], $t['filed']], $s['taxonomy_tabs']), [['categories', 0, 1], ['tags', 2, 3]]);
check('and the names of the content types, the ones that can be filed apart', [$s['type_names'], $s['listable_types'], $s['type_labels']], [['pages' => 'PAGES', 'posts' => 'POSTS'], ['posts'], ['posts' => 'POSTS']]);
check('and what just happened, and what the person may do', [$s['saved'], $s['moved'], $s['removed'], $s['can_redirects'], $s['taxonomy_kind'], $s['other_taxonomies'], $s['default_language'], $s['languages']], [true, 2, 0, true, 'tag', ['categories'], 'el', ['el', 'en']]);
$post = ['taxonomy_title' => 'Labels', 'term_id' => ['news', 'other', ''], 'term_slug' => ['news', 'other', 'fresh'], 'term_label' => ['el' => ['Νέα', 'Άλλα', 'Φρέσκο'], 'en' => ['News', 'Other', 'Fresh']], 'term_description' => ['el' => ['', '', ''], 'en' => ['', '', '']]];
$loc = $editor->save('tags', $post, ['el', 'en'], 'el', 'tester');
check('saving says where to go next and is logged', [$loc, $log[0][0], $log[0][2], $store->load('tags')['title'], count($store->load('tags')['terms'])], ['/admin/taxonomies?taxonomy=tags&saved=1', 'taxonomies.update', 'tags', 'Labels', 3]);
$post['term_id'] = ['news', ''];
$post['term_slug'] = ['news', 'fresh'];
$post['term_label'] = ['el' => ['Νέα', 'Φρέσκο'], 'en' => ['News', 'Fresh']];
$post['term_description'] = ['el' => ['', ''], 'en' => ['', '']];
$loc = $editor->save('tags', $post, ['el', 'en'], 'el', 'tester');
check('removing a term that entries use is counted in the way back', $loc, '/admin/taxonomies?taxonomy=tags&saved=1&removed=1&orphaned=1');
$quiet = new TaxonomyEditor($store, new RedirectRepository($db), $content, fn() => $menus);
check('without a log the form is still saved', str_starts_with($quiet->save('tags', $post, ['el', 'en'], 'el', 'tester'), '/admin/taxonomies?taxonomy=tags&saved=1'), true);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
