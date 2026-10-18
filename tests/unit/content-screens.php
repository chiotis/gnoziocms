<?php
/*
 * The content screens: the history of changes (versions, bringing one back), the list of entries with its bulk
 * actions and the deleting of one, the CSV export and the two-step import, the editor form of one entry, and the main
 * image uploaded with it.
 *   php tests/unit/content-screens.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, ContentAdmin, ContentCsv, ContentEditor, ContentIndex, ContentPaths, ContentRepository, ContentTransfer, ContentTypes, EntryForm, EntryTranslations, Format, FormsAdmin, FormSubmissionRepository, HtmlGuard, LinkScanner, MediaAdmin, MediaLibrary, MediaUsage, PresetLibrary, PublicPaths, RedirectRepository, RevisionAdmin, RevisionRepository, Slug, SystemDatabase, Taxonomies, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- the date helper
check('a timestamp, a date text and a date object are shown in the site format', [Format::dateValue(86400 * 365, 'Y-m-d'), Format::dateValue('2026-03-04', 'd/m/Y'), Format::dateValue(new DateTimeImmutable('2026-05-06'), 'd.m.Y')], ['1971-01-01', '04/03/2026', '06.05.2026']);
check('nothing stays nothing, and text that is not a date stays as written', [Format::dateValue('', 'Y'), Format::dateValue(null, 'Y'), Format::dateValue('someday', 'Y')], ['', '', 'someday']);
check('the list of a type has an address', [ContentPaths::archive('posts', 'el', 'el'), ContentPaths::archive('posts', 'en', 'el')], ['posts', 'en/posts']);

// ---- a small site
$root = dirname(__DIR__, 2);
$dir = sys_get_temp_dir() . '/screens' . getmypid();
foreach (['pages', 'posts', 'forms', 'taxonomies'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/storage/db", 0775, true);
mkdir("$dir/public/uploads/media", 0775, true);
$settings = ['title' => 'My Site', 'base_url' => 'https://s.test', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'content_types' => ['pages', 'posts', 'forms'], 'date_format' => 'd/m/Y'];
$themeSettings = [];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$markdown = new MarkdownConverter($environment);
$content = new ContentRepository("$dir/content", $markdown, $settings);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$revisions = new RevisionRepository($db);
$redirects = new RedirectRepository($db);
$index = new ContentIndex($db, "$dir/content");
$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$registry = new BlockRegistry($theme);
$guard = new HtmlGuard(fn() => $environment, fn() => $registry);
$editor = new ContentEditor("$dir/content", $settings, $content, $index, $types, $theme, $redirects, $guard, fn() => $registry, fn() => $taxonomies->names(), $revisions);
$log = [];
$logger = function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = $action; };
$translations = new EntryTranslations($content, fn() => $settings);
$publicPaths = new PublicPaths($content, fn() => $taxonomies, fn() => $settings, fn() => 'https://s.test');

$write = function (string $type, string $name, string $text) use ($dir): string { $p = "$dir/content/$type/$name.md"; file_put_contents($p, $text); return $p; };
$write('pages', 'index', "---\ntitle: Home\nstatus: published\ntranslation_id: tid-home\n---\n\nHome body\n");
$write('pages', 'about', "---\ntitle: About\nstatus: published\ntranslation_id: tid-about\nseo:\n  title: About SEO\ncolor_note: blue\n---\n\nAbout body\n");
$write('pages', 'about.en', "---\ntitle: About EN\nstatus: published\ntranslation_id: tid-about\n---\n\nEnglish\n");
$write('pages', 'secret', "---\ntitle: Secret plan\nstatus: draft\n---\n\nNot yet\n");
$write('posts', 'hello', "---\ntitle: Hello\nstatus: published\ndate: '2026-03-04'\ntags:\n  - news\n---\n\nPost\n");
$write('posts', 'plain', "---\ntitle: Plain\nstatus: published\n---\n\nPost\n");
$write('forms', 'contact', "---\ntitle: Contact\nstatus: published\nfields:\n  - name: email\n    label: Email\n    type: email\nnotifications:\n  enabled: true\n  to: boss@s.test\nsubmit_label: Send\n---\n\n");
file_put_contents("$dir/content/taxonomies/tags.yaml", "title: Tags\nterms:\n  - id: news\n    slug: news\n    labels: {el: News, en: News}\n");
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$content = new ContentRepository("$dir/content", $markdown, $settings);
$translations = new EntryTranslations($content, fn() => $settings);
$publicPaths = new PublicPaths($content, fn() => $taxonomies, fn() => $settings, fn() => 'https://s.test');
$editor = new ContentEditor("$dir/content", $settings, $content, $index, $types, $theme, $redirects, $guard, fn() => $registry, fn() => $taxonomies->names(), $revisions);
// Entries are listed once per request, so what a test changes needs the lists read again.
$flush = fn() => (function () { $this->cache = []; })->call($content);
$scanner = fn() => new LinkScanner("$dir/content", ['el', 'en'], 'el');
$history = new RevisionAdmin($revisions, $editor, $content, "$dir/content", fn() => $settings, $logger);
$admin = new ContentAdmin("$dir/content", $content, $index, $types, $editor, $translations, $revisions, $redirects, fn() => $settings, fn() => $taxonomies, fn() => $publicPaths, $scanner, $logger);

// ---- history: the screens
$revisions->capture('pages', 'about', 'el', "---\ntitle: About\n---\n\nFirst\n", 'create', 'alice');
$second = $revisions->capture('pages', 'about', 'el', "---\ntitle: About\n---\n\nSecond\n", 'save', 'bob');
$revisions->capture('forms', 'contact', 'el', "---\ntitle: Contact\n---\n\nx\n", 'create', 'alice');
$rows = $history->decorate($revisions->forItem('pages', 'about', 'el'));
check('a version is labelled, dated, and knows if its entry is still there', [$rows[0]['action_label'], $rows[1]['action_label'], str_ends_with($rows[0]['when'], ' UTC'), $rows[0]['exists']], ['Saved', 'Created', true, true]);
check('an action nobody named is shown capitalised', $history->decorate([['type' => 'pages', 'slug' => 'x', 'lang' => 'el', 'action' => 'mystery', 'created_at' => '2026-01-02T03:04:05Z']])[0]['action_label'], 'Mystery');

$s = $history->screen([], true);
check('the recent changes of everyone, with forms for those who manage forms', [$s['template'], $s['data']['view'], $s['data']['total'], in_array('forms', $s['data']['content_types'], true)], ['@admin/revisions.twig', 'recent', 3, true]);
$s = $history->screen([], false);
check('forms are left out for the others, and their changes too', [$s['data']['total'], in_array('forms', $s['data']['content_types'], true)], [2, false]);
$s = $history->screen(['q' => 'nothing-matches'], true);
check('a search that finds nothing', [$s['data']['total'], $s['data']['pages'], $s['data']['filters']['q']], [0, 1, 'nothing-matches']);
$s = $history->screen(['slug' => 'about', 'type' => 'pages', 'lang' => 'el'], true);
check('the versions of one entry', [$s['data']['view'], $s['data']['total'], $s['data']['item']['title'], $s['data']['item']['exists']], ['item', 2, 'About', true]);
check('forms history is not for everyone', $history->screen(['slug' => 'contact', 'type' => 'forms'], false)['denied'], 'forms');
check('but is for those who manage forms', $history->screen(['slug' => 'contact', 'type' => 'forms', 'lang' => 'el'], true)['data']['total'], 1);
$s = $history->screen(['view' => 'deleted'], true);
check('what was deleted', [$s['data']['view'], $s['data']['total']], ['deleted', 0]);
$s = $history->screen(['id' => $second], true);
check('one version shows what it changed from the one before', [$s['template'], $s['data']['mode'], $s['data']['summary'], $s['data']['is_latest'], $s['data']['item_exists']], ['@admin/revision-view.twig', 'changes', ['added' => 1, 'removed' => 1], true, true]);
$s = $history->screen(['id' => $second, 'mode' => 'restore'], true);
check('or what bringing it back would do, when the entry is still there', [$s['data']['mode'], $s['data']['item_exists']], ['restore', true]);
$s = $history->screen(['id' => 99999], true);
check('a version that is not there goes back to the list', $s['location'], '/admin/revisions');
check('so does one of forms for someone who may not see them', $history->screen(['id' => $revisions->latest('forms', 'contact', 'el')['id']], false)['location'], '/admin/revisions');

// ---- history: bringing back
$r = $history->restore(['do' => 'restore', 'id' => 99999], false, true, 'tester');
check('an unknown version goes back to the list', $r, ['location' => '/admin/revisions', 'denied' => '']);
$r = $history->restore(['do' => 'restore', 'id' => $revisions->latest('forms', 'contact', 'el')['id']], false, false, 'tester');
check('a form version is not for someone who may not manage forms', $r, ['location' => '', 'denied' => 'forms']);
$r = $history->restore(['do' => 'explode', 'id' => $second], false, true, 'tester');
check('only restore and undelete are known', $r['location'], '/admin/revisions');
$log = [];
$r = $history->restore(['do' => 'restore', 'id' => $second], false, true, 'tester');
check('a version is brought back and the editor opens', [$r['location'], $r['denied'], $log], ['/admin/edit?type=pages&slug=about&lang=el&saved=1&restored=1', '', ['content.restore']]);
check('the file has that text again', str_contains((string)file_get_contents("$dir/content/pages/about.md"), 'Second'), true);
$write('pages', 'about', "---\ntitle: About\nstatus: published\ntranslation_id: tid-about\nseo:\n  title: About SEO\ncolor_note: blue\n---\n\nAbout body\n");
$flush();

// ---- the list
$r = $admin->listing(['type' => 'forms', 'deleted' => '1'], true);
check('forms have their own list', $r['location'], '/admin/forms?deleted=1');
$r = $admin->listing(['type' => 'pages', 'lang' => 'el'], true)['data'];
check('the list shows every entry of the language, drafts too', [count($r['items']), $r['current_type'], $r['filters_active'], $r['can_redirects']], [3, 'pages', false, true]);
check('the statuses to filter by', $r['status_options'], ['published', 'draft']);
check('and which other languages each entry exists in', $r['translation_langs']['about|el'] ?? null, ['en']);
$r = $admin->listing(['type' => 'pages', 'lang' => 'el', 'q' => 'SECRET'], false)['data'];
check('a search looks at the title and the address, in any case', [array_map(fn($i) => $i->slug, $r['items']), $r['filters_active'], $r['can_redirects']], [['secret'], true, false]);
$r = $admin->listing(['type' => 'pages', 'lang' => 'el', 'status' => 'draft'], true)['data'];
check('a status narrows it', array_map(fn($i) => $i->slug, $r['items']), ['secret']);
$r = $admin->listing(['type' => 'pages', 'lang' => 'el', 'status' => 'nonsense'], true)['data'];
check('a status that is not one is ignored', [count($r['items']), $r['filters']['status']], [3, '']);
$r = $admin->listing(['type' => 'posts', 'lang' => 'el', 'taxonomy' => 'tags', 'term' => 'news'], true)['data'];
check('filed under a term', [array_map(fn($i) => $i->slug, $r['items']), $r['term_filter']['label'], $r['term_filter']['taxonomy_title']], [['hello'], 'News', 'Tags']);
$r = $admin->listing(['type' => 'posts', 'lang' => 'el', 'taxonomy' => 'tags', 'term' => 'ghost'], true)['data'];
check('a term that does not exist is ignored', [count($r['items']), $r['term_filter']], [2, null]);
$r = $admin->listing(['type' => 'nonsense'], true)['data'];
check('a type that does not exist opens the first one', $r['current_type'], 'pages');
$r = $admin->listing(['type' => 'pages', 'bulk' => 'ok', 'bulk_msg' => ' Done. ', 'deleted' => '1', 'from' => '/a', 'to' => '/b', 'gone' => '/c'], true)['data'];
check('what just happened is carried to the page', [$r['bulk_status'], $r['bulk_message'], $r['deleted'], $r['deleted_from'], $r['deleted_to'], $r['deleted_gone']], ['ok', 'Done.', true, '/a', '/b', '/c']);

// ---- bulk actions
$back = '/admin/content?type=pages&lang=el';
check('bulk only works as a form', $admin->bulk(['type' => 'pages', 'lang' => 'el'], false, true, 'tester'), $back);
check('and not for forms', $admin->bulk(['type' => 'forms', 'bulk_action' => 'delete', 'selected' => ['contact']], true, true, 'tester'), '/admin/content?type=forms&lang=el');
check('it asks for an action and a selection', $admin->bulk(['type' => 'pages', 'lang' => 'el', 'bulk_action' => 'publish'], true, true, 'tester'), $back . '&bulk=fail&bulk_msg=Choose+a+bulk+action+and+select+at+least+one+item.');
$log = [];
$r = $admin->bulk(['type' => 'pages', 'lang' => 'el', 'bulk_action' => 'publish', 'selected' => ['secret', 'ghost', 'secret']], true, true, 'tester');
check('publishing counts what it did and what it skipped', $r, $back . '&bulk=ok&bulk_msg=1+item+published.+1+skipped.');
check('the draft is published now, its text kept', [str_contains((string)file_get_contents("$dir/content/pages/secret.md"), 'status: published'), str_contains((string)file_get_contents("$dir/content/pages/secret.md"), 'Not yet')], [true, true]);
check('and it was logged', $log, ['content.bulk_publish']);
$admin->bulk(['type' => 'pages', 'lang' => 'el', 'bulk_action' => 'draft', 'selected' => ['about']], true, true, 'tester');
check('moving to draft', str_contains((string)file_get_contents("$dir/content/pages/about.md"), 'status: draft'), true);
check('and the rest of the front matter stays', str_contains((string)file_get_contents("$dir/content/pages/about.md"), 'About SEO'), true);
$r = $admin->bulk(['type' => 'pages', 'lang' => 'el', 'bulk_action' => 'delete', 'selected' => ['index']], true, true, 'tester');
check('the home page is never deleted', [is_file("$dir/content/pages/index.md"), $r], [true, $back . '&bulk=fail&bulk_msg=0+items+deleted.+1+skipped.']);
$flush();
$r = $admin->bulk(['type' => 'pages', 'lang' => 'el', 'bulk_action' => 'delete', 'selected' => ['secret']], true, true, 'tester');
check('a public page that is deleted says visitors will find nothing, and where to fix it', [is_file("$dir/content/pages/secret.md"), str_contains(urldecode($r), '1 of them was public'), str_contains(urldecode($r), 'Add redirects')], [false, true, true]);
check('it stays in the history to be brought back', $revisions->latest('pages', 'secret', 'el')['action'], 'delete');
$r = $admin->bulk(['type' => 'posts', 'lang' => 'el', 'bulk_action' => 'delete', 'selected' => ['plain']], true, false, 'tester');
check('those who cannot manage redirects are not pointed to them', str_contains(urldecode($r), 'Add redirects'), false);
check('the history lists what was deleted', count($history->screen(['view' => 'deleted'], true)['data']['rows']), 2);
$flush();
$r = $history->restore(['do' => 'undelete', 'id' => $revisions->latest('pages', 'secret', 'el')['id']], false, true, 'tester');
check('a deleted entry is brought back', [is_file("$dir/content/pages/secret.md"), str_contains($r['location'], 'restored=1')], [true, true]);

// ---- deleting one
$del = fn(string $type, array $src, bool $post, bool $redirects = true) => $admin->delete($type, $src, $post, $redirects, true, 'tester');
check('the home page cannot be deleted', $del('pages', ['slug' => 'index', 'lang' => 'el'], true)['location'], $back);
check('nor an empty address', $del('pages', ['slug' => '', 'lang' => 'el'], false)['location'], $back);
check('an entry that is not there goes back to the list', $del('pages', ['slug' => 'ghost', 'lang' => 'el'], false)['location'], $back);
$flush();
$v = $del('pages', ['slug' => 'secret', 'lang' => 'el'], false);
check('a public page asks before it is deleted', [$v['location'], $v['view']['entry_title'], $v['view']['is_public'], $v['view']['may_redirect'], $v['view']['public_path'], $v['view']['choice'], $v['view']['links']], ['', 'Secret plan', true, true, '/secret', 'none', 0]);
check('and the others a post is sent to its list by default', $del('posts', ['slug' => 'hello', 'lang' => 'el'], false)['view']['choice'], 'archive');
check('with the list, the home page and the languages it exists in', [$del('posts', ['slug' => 'hello', 'lang' => 'el'], false)['view']['archive_path'], $v['view']['home_path'], $del('pages', ['slug' => 'about', 'lang' => 'el'], false)['view']['siblings']], ['/posts', '/', ['en']]);
$v = $del('pages', ['slug' => 'secret', 'lang' => 'el', 'after' => 'custom', 'after_target' => ''], true);
check('a redirect to nowhere is refused and the question asked again', [$v['location'], $v['view']['error'], $v['view']['choice'], is_file("$dir/content/pages/secret.md")], ['', 'target_empty', 'custom', true]);
$v = $del('pages', ['slug' => 'secret', 'lang' => 'el', 'after' => 'custom', 'after_target' => '/no-such-page'], true);
check('so is a redirect to a page that is not there', [$v['view']['error'], is_file("$dir/content/pages/secret.md")], ['target_missing', true]);
$log = [];
$r = $del('pages', ['slug' => 'secret', 'lang' => 'el', 'after' => 'home'], true);
check('deleting with a redirect to the home page', [$r['location'], is_file("$dir/content/pages/secret.md"), $log], [$back . '&deleted=1&from=%2Fsecret&to=%2F', false, ['content.delete']]);
check('visitors of the old address are sent to the home page', [(int)($redirects->findBySource('secret')['status_code'] ?? 0), $redirects->findBySource('secret')['target'] ?? null], [301, '/']);
$r = $del('posts', ['slug' => 'hello', 'lang' => 'el'], true, false);
check('without the right to redirect, the page is deleted and says visitors will find nothing', [$r['location'], is_file("$dir/content/posts/hello.md")], ['/admin/content?type=posts&lang=el&deleted=1&gone=%2Fhello', false]);
$write('pages', 'draftonly', "---\ntitle: Draft\nstatus: draft\n---\n\nx\n");
$r = $del('pages', ['slug' => 'draftonly', 'lang' => 'el'], true);
check('a draft, which nobody could have visited, is just deleted', [$r['location'], is_file("$dir/content/pages/draftonly.md")], [$back . '&deleted=1', false]);
$v = $del('pages', ['slug' => 'about', 'lang' => 'el'], false, false);
check('without the right to redirect there is nothing to choose', $v['view']['may_redirect'], false);

// ---- the file of every entry, and the import
$csv = new ContentCsv($content, "$dir/content", fn() => $settings, $revisions, fn() => $guard, fn(string $d) => $d, fn() => 'tester', fn() => true);
$rebuilt = 0;
$transfer = new ContentTransfer($csv, $content, function () use (&$rebuilt) { $rebuilt++; return ['ok' => true, 'indexed' => 0, 'removed' => 0, 'took_ms' => 0]; }, $logger);
$log = [];
$file = $transfer->export('pages', 'Ο Ιστότοπος μου');
check('the file is named after the site, the type and says it has every language', $file['filename'], 'o-istotopos-moy-pages-all-languages.csv');
check('it has a header and a row for each entry', [in_array('slug', $file['headers'], true), count($file['rows']) >= 3, $log], [true, true, ['content.export']]);
check('a site with no name still has a file name', $transfer->export('pages', '')['filename'], 'site-pages-all-languages.csv');

$csvFile = function (string $name, string $text, int $error = UPLOAD_ERR_OK) use ($dir): array { $p = $dir . '/' . mt_rand() . '.csv'; file_put_contents($p, $text); return ['name' => $name, 'tmp_name' => $p, 'error' => $error, 'size' => strlen($text)]; };
$previews = [];
$r = $transfer->import('pages', false, [], null, $previews);
check('opening the page shows an empty import', [$r['location'], $r['view']['error'], $r['view']['preview_rows'], $r['view']['preview_summary']], ['', '', [], ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0]]);
check('no file is an error', $transfer->import('pages', true, [], null, $previews)['view']['error'], 'Please upload a valid CSV file.');
check('a failed upload is an error', $transfer->import('pages', true, [], $csvFile('a.csv', 'x', UPLOAD_ERR_PARTIAL), $previews)['view']['error'], 'Please upload a valid CSV file.');
check('a file that is not a CSV is refused', $transfer->import('pages', true, [], $csvFile('a.txt', "slug\nx"), $previews)['view']['error'], 'Please upload a .csv file.');
$r = $transfer->import('pages', true, [], $csvFile('new.csv', "content_type,language,slug,title,body\npages,el,imported,Imported page,Hello there\n"), $previews);
$token = $r['view']['preview_token'];
check('a good file is read into a preview with a token, and nothing is written yet', [$r['view']['error'], $r['view']['preview_summary']['create'], strlen($token), $r['view']['source_filename'], is_file("$dir/content/pages/imported.md"), isset($previews[$token])], ['', 1, 24, 'new.csv', false, true]);
check('the preview was logged', end($log), 'content.import_preview');
$r = $transfer->import('pages', true, ['apply' => '1', 'preview_token' => 'wrong'], null, $previews);
check('applying a preview that is not there says it expired', [$r['view']['error'], $r['view']['preview_token']], ['Import preview expired. Run dry-run again.', '']);
$r = $transfer->import('posts', true, ['apply' => '1', 'preview_token' => $token], null, $previews);
check('a preview made for another type cannot be applied', $r['view']['error'], 'Import preview expired. Run dry-run again.');
$r = $transfer->import('pages', true, ['apply' => '1', 'preview_token' => $token], null, $previews);
check('applying writes the page, rebuilds the index, forgets the preview and goes to the done page', [$r['location'], is_file("$dir/content/pages/imported.md"), $rebuilt, isset($previews[$token]), end($log)], ['/admin/import?type=pages&saved=1', true, 1, false, 'content.import_apply']);
$previews['old'] = ['type' => 'pages', 'created_at' => time() - 7200];
$previews['broken'] = 'nonsense';
$previews['fresh'] = ['type' => 'pages', 'created_at' => time()];
$transfer->import('pages', false, [], null, $previews);
check('previews older than an hour, and rubbish, are forgotten', array_keys($previews), ['fresh']);

// ---- the editor form
$form = new EntryForm("$dir/content", $content, $types, $editor, $translations, new FormsAdmin($content, new FormSubmissionRepository("$dir/content")), $redirects, $revisions, $history, $theme,
    fn() => $settings, fn() => $themeSettings, fn() => $taxonomies, $scanner, fn() => $registry,
    fn() => new PresetLibrary($theme, $registry, fn(string $v): string => Slug::plain($v)), fn(string $path): string => 'https://s.test/' . ltrim($path, '/'));
$f = $form->form('pages', 'about', 'el', [], true);
check('an entry opens with what its front matter says', [$f['meta_form']['title'], $f['meta_form']['status'], $f['meta_form']['seo_title'], $f['body'], $f['saved']], ['About', 'draft', 'About SEO', "About body\n", false]);
check('the front matter that has no field of its own is left to edit by hand', [str_contains($f['frontmatter'], 'color_note'), $f['meta_form']['custom_fields']], [true, [['key' => 'color_note', 'value' => 'blue']]]);
check('with its address, link to the site and the languages it exists in', [$f['front_url'], $f['address']['prefix'], $f['address']['exists'], $f['address']['locked'], $f['address']['siblings']], ['https://s.test/about', '/', true, false, ['en']]);
check('and its translations', array_column($f['translations'], 'exists'), [true, true]);
check('and its latest versions', [$f['history_total'] >= 1, $f['history'][0]['action_label'] ?? ''], [true, 'Restored']);
check('the home page keeps its address', $form->form('pages', 'index', 'el', [], true)['address']['locked'], true);
check('what the address was opened with is passed on', [$form->form('pages', 'about', 'el', ['saved' => '1', 'notice' => 'html', 'restored' => '1', 'address_taken' => 'About Us'], true)['notice'], $form->form('pages', 'about', 'el', ['address_taken' => 'About Us'], true)['address_taken']], ['html', 'about-us']);
$n = $form->form('posts', 'brand-new', 'el', ['translation_id' => 'from-the-address'], true);
check('a new entry has the defaults of its type, today as its date, and the group it was asked to join', [$n['address']['exists'], $n['meta_form']['title'], $n['meta_form']['date'] === date('d/m/Y'), $n['meta_form']['translation_id'], $n['frontmatter']], [false, '', true, 'from-the-address', "status: published\nvisible: true\nmain_image: \"\"\ndate: " . date('Y-m-d')]);
check('an entry with no group gets a new one', strlen($form->form('pages', 'secret', 'el', [], true)['meta_form']['translation_id']) > 8, true);
$flush();
$write('posts', 'dated', "---\ntitle: Dated\ndate: '2026-03-04'\ntags: [news]\n---\n\nx\n");
$d = $form->form('posts', 'dated', 'el', [], true);
check('a date and the terms the entry is filed under', [$d['meta_form']['date'], $d['meta_form']['taxonomy_terms']['tags'] ?? null, array_column($d['taxonomies'], 'name')], ['04/03/2026', ['news'], ['categories', 'tags']]);
$flush();
$write('pages', 'broken', "---\ntitle: [unclosed\n---\n\nBody\n");
$b = $form->form('pages', 'broken', 'el', [], true);
check('front matter that cannot be read still opens, to be fixed by hand', [$b['front_matter_error'] !== '', $b['meta_form']['title'], $b['body']], [true, 'Broken', "Body\n"]);
check('an error from the save is shown', $form->form('pages', 'about', 'el', ['frontmatter_error' => 'Bad YAML'], true)['front_matter_error'], 'Bad YAML');
$c = $form->form('forms', 'contact', 'el', [], true);
check('a form has its fields, notifications and settings', [$c['form_fields'][0]['name'] ?? '', $c['form_notifications']['enabled'], $c['form_notifications']['to'], $c['form_settings']['submit_label'], $c['admin_section'], $c['block_editor_json'], $c['page_templates']], ['email', true, 'boss@s.test', 'Send', 'forms', '', []]);
check('and its submissions, none yet', [$c['form_submissions'], $c['form_submissions_total']], [[], 0]);
$blocks = json_decode($f['block_editor_json'], true);
check('a page has the block editor, with the blocks, the sections to start from and where to save them', [is_array($blocks['definitions']), $blocks['blocks'], $blocks['lang'], $blocks['presets_url'], is_array($blocks['presets'])], [true, [], 'el', 'https://s.test/admin/block-presets', true]);
check('the choices of how an entry opens', array_keys($f['opening']), ['layouts', 'transparent', 'parallax', 'type_layout', 'type_transparent', 'type_parallax']);
$themeSettings = ['hero_layouts' => ['default' => 'default', 'posts' => 'default'], 'transparent_header' => ['default' => 'off']];
check('and what the type does today', [$form->form('pages', 'about', 'el', [], true)['opening']['type_transparent']], ['off']);
$moved = $form->form('pages', 'about', 'el', ['address' => 'changed'], true);
check('just after an address changed, with nothing that links to the old one, there is nothing to fix', $moved['link_fix'], null);
$redirects->create('/old-about', '/about', 301, 'manual', '', 'tester');
$flush();
$write('pages', 'linker', "---\ntitle: Linker\n---\n\n[see](/old-about) and [again](/old-about)\n");
$moved = $form->form('pages', 'about', 'el', ['address' => 'changed'], true);
check('but when other pages still link to it they are counted', [$moved['address_changed'], $moved['link_fix']['count'] ?? 0], [true, 2]);
check('the old addresses are shown to those who may see redirects, not to the others', [count($form->form('pages', 'about', 'el', [], true)['address']['old_addresses']), $form->form('pages', 'about', 'el', [], false)['address']['old_addresses']], [1, []]);

// ---- the main image uploaded with the form
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$media = new MediaLibrary("$dir/content", "$dir/public/uploads");
$media->ensureDirectories();
$room = true;
$grew = [];
$mediaAdmin = new MediaAdmin($media, new MediaUsage("$dir/content", fn() => [], 'el'), function (int $b) use (&$room) { return $room; }, fn() => 'Full.', function (int $b) use (&$grew) { $grew[] = $b; }, fn() => 0, $logger);
$upload = function (string $name, string $data) use ($dir): array { $t = tempnam($dir, 'up'); file_put_contents($t, $data); return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $t, 'error' => UPLOAD_ERR_OK, 'size' => filesize($t)]; };
$many = fn(array $f) => ['name' => [$f['name']], 'type' => [$f['type']], 'tmp_name' => [$f['tmp_name']], 'error' => [$f['error']], 'size' => [$f['size']]];
check('no file chosen leaves the address as typed', [$mediaAdmin->uploadMainImage(null, 'tester'), $mediaAdmin->uploadMainImage(['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]], 'tester')], [['url' => null, 'blocked' => false], ['url' => null, 'blocked' => false]]);
$r = $mediaAdmin->uploadMainImage($many($upload('hero.png', $png)), 'tester');
check('a picture becomes the main image and is in the library', [str_contains((string)$r['url'], '/uploads/'), $r['blocked'], count($media->list()), count($grew)], [true, false, 1, 1]);
$r = $mediaAdmin->uploadMainImage($many($upload('notes.php', '<?php echo 1;')), 'tester');
check('a file that is refused changes nothing', [$r, count($media->list())], [['url' => null, 'blocked' => false], 1]);
$room = false;
$r = $mediaAdmin->uploadMainImage($many($upload('big.png', $png)), 'tester');
check('a full storage keeps the file out and says so', [$r, count($media->list())], [['url' => null, 'blocked' => true], 1]);

// tidy up
exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
