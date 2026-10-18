<?php
/*
 * Which kind of public page an address asks for, the Settings screen's form (saving, the limits log, the three
 * button actions), and what every admin screen gets besides its own data.
 *   php tests/unit/settings-and-front.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{AdminChrome, AdminNotices, BackupAdmin, BackupManager, BackupRunRepository, BackupService, FrontRoute, MediaLibrary, NotificationRepository, SettingsAdmin, SiteLimits, SiteSettings, SystemDatabase, SystemMetaRepository, UpdateService, UserRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- the public address
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index'];
$types = ['pages', 'posts', 'forms'];
$r = fn(string $path) => FrontRoute::resolve($path, $settings, $types);
check('the prefix of a language', [FrontRoute::langPrefix('el', 'el'), FrontRoute::langPrefix('en', 'el')], ['', 'en/']);
check('the sitemap and robots.txt', [$r('sitemap.xml')['kind'], $r('robots.txt')['kind']], ['sitemap', 'robots']);
check('but not in a language', $r('en/sitemap.xml')['kind'], 'page');
$x = $r('');
check('the empty address is the home page, in the default language', [$x['kind'], $x['slug'], $x['lang'], $x['lang_prefix'], $x['segments'], $x['path_no_lang']], ['page', 'index', 'el', '', [], '']);
$x = $r('en');
check('a language alone is its home page', [$x['kind'], $x['slug'], $x['lang'], $x['lang_prefix']], ['page', 'index', 'en', 'en/']);
$x = $r('about');
check('a page', [$x['kind'], $x['slug'], $x['lang'], $x['path_no_lang']], ['page', 'about', 'el', 'about']);
$x = $r('en/about');
check('a page in another language', [$x['kind'], $x['slug'], $x['lang'], $x['segments'], $x['path_no_lang']], ['page', 'about', 'en', ['about'], 'about']);
check('the home page by name has no path', $r('index')['path_no_lang'], '');
$rt = fn(string $path) => FrontRoute::resolve($path, $settings, $types, ['industries', 'posts']);
check('a taxonomy the site added has pages at /name/term, in any language', [$rt('industries/retail')['kind'], $rt('en/industries/retail')['kind'], $rt('en/industries/retail')['lang']], ['taxonomy', 'taxonomy', 'en']);
check('/name alone is an ordinary page address (a page can be made there)', [$rt('industries')['kind'], $rt('industries')['slug']], ['page', 'industries']);
check('a content type of that name keeps its list and entries', [$rt('posts')['kind'], $rt('posts/hello')['kind']], ['archive', 'entry']);
check('without the taxonomy in the list the address is not found', $r('industries/retail')['kind'], 'not_found');
check('an address with more after a page is not a page', [$r('about/anything')['kind'], $r('en/about/anything')['kind'], $r('index/x')['kind'], $r('a/b/c')['kind']], ['not_found', 'not_found', 'not_found', 'not_found']);
check('nor after a list, an entry, the search or an old page address', [$r('posts/hello/extra')['kind'], $r('search/extra')['kind'], $r('pages/about/extra')['kind']], ['not_found', 'not_found', 'not_found']);
check('but a term can be as deep as a tree of terms goes (the page finds out whether it is there)', [$r('tag/news/extra')['kind'], $r('category/news/extra')['kind'], $rt('industries/retail/extra')['kind']], ['taxonomy', 'taxonomy', 'taxonomy']);
check('but what has exactly its parts is what it was', [$r('about')['kind'], $r('posts')['kind'], $r('posts/hello')['kind'], $r('tag/news')['kind'], $r('search')['kind'], $r('pages/about')['kind'], $r('en')['kind'], $r('')['kind']], ['page', 'archive', 'entry', 'taxonomy', 'search', 'legacy_page', 'page', 'page']);
$x = $r('posts');
check('the list of a type', [$x['kind'], $x['type'], $x['slug']], ['archive', 'posts', '']);
check('with a trailing slash too', $r('posts/')['kind'], 'archive');
$x = $r('en/posts/hello');
check('one entry of a type', [$x['kind'], $x['type'], $x['slug'], $x['lang']], ['entry', 'posts', 'hello', 'en']);
check('a form is an entry of its type', [$r('forms/contact')['kind'], $r('forms/contact')['type']], ['entry', 'forms']);
check('a type the site does not have is not a type: gallery alone is a page, gallery/one is not found', [$r('gallery')['kind'], $r('gallery')['slug'], $r('gallery/one')['kind']], ['page', 'gallery', 'not_found']);
check('categories and tags, in both spellings', [$r('tag/news')['kind'], $r('tags/news')['kind'], $r('category/news')['kind'], $r('en/categories/x')['kind']], ['taxonomy', 'taxonomy', 'taxonomy', 'taxonomy']);
check('the search', $r('en/search')['kind'], 'search');
$x = $r('pages/about');
check('an old /pages/ address goes to the page itself', [$x['kind'], $x['location']], ['legacy_page', '/about']);
check('in another language', $r('en/pages/about')['location'], '/en/about');
check('the home page, or none, goes to the root of the language', [$r('pages/index')['location'], $r('pages')['location'], $r('en/pages/index')['location']], ['/', '/', '/en/']);
check('a language that is not one is part of the address, so this is not found', [$r('de/about')['lang'], $r('de/about')['kind'], $r('de')['slug']], ['el', 'not_found', 'de']);
check('without a home page setting it is index', FrontRoute::resolve('', ['languages' => ['default' => 'en']] + [], [])['slug'], 'index');

// ---- the Settings screen
$dir = sys_get_temp_dir() . '/setscr' . getmypid();
foreach (['content/settings', 'content/pages', 'public/uploads/media', 'custom', 'storage'] as $d) { mkdir("$dir/$d", 0775, true); }
file_put_contents("$dir/VERSION", "1.2.3\n");
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$store = new SiteSettings($meta, "$dir/content");
$running = $store->load();
$media = new MediaLibrary("$dir/content", "$dir/public/uploads");
$limits = new SiteLimits($meta, $dir, "$dir/content", function () use (&$running) { return $running; });
$log = [];
$logger = function (string $a, string $l, ?string $t, ?string $i, string $m, array $c) use (&$log) { $log[] = [$a, $l]; };
$notes = new NotificationRepository($db);
$service = new BackupService($dir, $db);
$runs = new BackupRunRepository($db);
$updates = new UpdateService($dir, ['latest_version' => '9.0.0', 'version_url' => 'file:///nowhere', 'changelog_url' => 'file:///nowhere'], $meta);
$manager = new BackupManager($service, $db, $runs, $notes, function () use (&$running) { return $running; }, fn() => $updates, fn(string $iso) => null, fn(array $c) => new class { function testConnection(): array { return ['ok' => false, 'message' => 'No remote here.']; } });
$mails = [];
$themeSaves = [];
$themeOk = true;
$marked = [];
$applied = 0;
$admin = new SettingsAdmin($store, $media, fn() => $limits, fn() => $manager, function () use (&$running) { return $running; },
    function (array $s) use (&$running, &$applied) { $running = $s; $applied++; },
    function (?array $input) use (&$themeSaves, &$themeOk) { $themeSaves[] = $input; return $themeOk ? ['ok' => true] : ['ok' => false, 'message' => 'Bad colour.']; },
    function (string $to, string $subject, string $body, array $headers) use (&$mails) { $mails[] = [$to, $subject, $headers['From']]; return $to !== 'fail@x.test'; },
    function (string $iso) use (&$marked) { $marked[] = $iso; },
    $logger);

check('a tab is one of the tabs, else the first', [SettingsAdmin::tab(' SMTP '), SettingsAdmin::tab('theme'), SettingsAdmin::tab(''), SettingsAdmin::tab('limits')], ['smtp', 'basics', 'basics', 'limits']);
check('the storage is measured again only for those who may', [$admin->recalculateStorage(false), $log], ['/admin/settings?tab=limits', []]);
check('and the way back says so', [$admin->recalculateStorage(true), $log[0][0], $meta->getJson('storage_usage') !== null], ['/admin/settings?tab=limits&storage=measured', 'limits.storage_recalculate', true]);

$log = [];
$loc = $admin->save(['title' => 'New title', 'active_tab' => 'basics'], ['storage_limit_mb' => null, 'upload_limit_mb' => null, 'upload_types' => null], 'basics');
check('saving stores the title, the site runs with it, the theme form is told it carries nothing, and the screen goes back to its tab', [$loc, $store->load()['title'], $running['title'], $applied, $themeSaves, $log], ['/admin/settings?saved=1&tab=basics', 'New title', 'New title', 1, [null], [['settings.update', 'info']]]);
$themeSaves = [];
$admin->save(['title' => 'T', 'theme_settings' => ['header' => ['x' => '1']]], ['storage_limit_mb' => null, 'upload_limit_mb' => null, 'upload_types' => null], 'basics');
check('the theme settings the form carries are passed on', $themeSaves, [['header' => ['x' => '1']]]);
check('the tab comes from the form first, then from the address', [str_ends_with($admin->save(['title' => 'T', 'active_tab' => 'smtp'], [], 'basics'), 'tab=smtp'), str_ends_with($admin->save(['title' => 'T'], [], 'backup'), 'tab=backup'), str_ends_with($admin->save(['title' => 'T', 'active_tab' => 'nonsense'], [], 'backup'), 'tab=basics')], [true, true, true]);
$log = [];
$admin->save(['title' => 'T'], ['storage_limit_mb' => 2048, 'upload_limit_mb' => null, 'upload_types' => null], 'limits');
check('a changed storage limit is logged as a warning', [$running['limits']['storage_mb'], array_column($log, 0)], [2048, ['limits.storage', 'settings.update']]);
$log = [];
$admin->save(['title' => 'T'], ['storage_limit_mb' => 2048, 'upload_limit_mb' => null, 'upload_types' => null], 'limits');
check('the same limit again is not', array_column($log, 0), ['settings.update']);
$log = [];
$admin->save(['title' => 'T'], ['storage_limit_mb' => 2048, 'upload_limit_mb' => 3, 'upload_types' => 'images'], 'limits');
check('a changed upload limit, or the kinds allowed, is logged and applied to the library', [array_column($log, 0), $media->allowedGroups(), $limits->uploadLimitMb()], [['limits.upload', 'settings.update'], ['images'], 3]);
$log = [];
$themeOk = false;
$loc = $admin->save(['title' => 'T'], [], 'basics');
check('a theme that cannot be saved goes to the theme page with why, and the settings update is not logged', [$loc, array_column($log, 0)], ['/admin/theme?theme=fail&theme_msg=Bad+colour.', []]);
$themeOk = true;

$db2 = new SystemDatabase("$dir/storage-none");
$bad = new SettingsAdmin(new SiteSettings(new SystemMetaRepository($db2), "$dir/content"), $media, fn() => $limits, fn() => $manager, fn() => $running, fn(array $s) => null, fn(?array $i) => ['ok' => true], fn() => true, fn(string $i) => null, $logger);
check('when the database cannot be used nothing is saved and the screen says why', str_contains(urldecode($bad->save(['title' => 'X'], [], 'basics')), 'settings_error=Settings could not be saved because the SQLite system database is unavailable.'), true);

$log = [];
$mails = [];
$loc = $admin->save(['title' => 'My Site', 'send_test' => '1', 'test_email_to' => ' me@x.test '], [], 'smtp');
check('the test email goes to the address given, from the site, and says it worked', [$loc, $mails, array_column($log, 0)], ['/admin/settings?saved=1&tab=smtp&test=ok', [['me@x.test', 'My Site - email test', 'noreply@localhost']], ['settings.update', 'email.test_success']]);
check('without an address it says so', $admin->save(['title' => 'T', 'send_test' => '1', 'test_email_to' => ''], [], 'smtp'), '/admin/settings?saved=1&tab=smtp&test=missing');
$log = [];
check('and one that fails says so, and is logged as an error', [$admin->save(['title' => 'T', 'send_test' => '1', 'test_email_to' => 'fail@x.test'], [], 'smtp'), end($log)], ['/admin/settings?saved=1&tab=smtp&test=fail', ['email.test_failure', 'error']]);
$log = [];
$loc = $admin->save(['title' => 'T', 'test_remote_backup' => '1'], [], 'backup');
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
check('the remote backup is tested and the result shown', [$q['tab'], $q['backup'], $q['backup_msg'] !== '', end($log)[1]], ['backup', 'fail', true, 'error']);
$log = [];
$loc = $admin->save(['title' => 'T', 'create_backup' => '1'], [], 'backup');
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
check('a backup is made now: its time kept, the run recorded, logged, and the screen says so', [$q['backup'], count($marked), $runs->recent(1)[0]['status'], end($log)[0], count($service->list())], ['ok', 1, 'success', 'backup.create_success', 1]);

$s = $admin->screen(['saved' => '1', 'storage' => 'measured', 'test' => 'ok', 'backup' => 'ok', 'backup_msg' => ' Done. ', 'tab' => 'SMTP', 'settings_error' => ' Oops '], true, [['filename' => 'a.zip']]);
check('the screen shows the form, the limits and what just happened', [$s['saved'], $s['storage_measured'], $s['test_status'], $s['backup_status'], $s['backup_message'], $s['active_tab'], $s['settings_error'], $s['backup_snapshots'], $s['settings_form']['title'], is_array($s['storage']) && $s['storage'] !== [], $s['admin_section']], [true, true, 'ok', 'ok', 'Done.', 'smtp', 'Oops', [['filename' => 'a.zip']], 'T', true, 'settings']);
check('the storage is shown only to those who may see it', $admin->screen([], false, [])['storage'], []);
check('the groups of files uploads can be limited to, and the cap of the server', [array_keys($s['upload_groups']) === array_keys(MediaLibrary::UPLOAD_GROUPS), is_int($s['server_upload_mb'])], [true, true]);

// ---- what every admin screen gets
$users = new UserRepository($db, "$dir/content/users.yaml");
$noContact = new AdminChrome($notes, $users, fn() => $updates, fn() => $limits, fn() => new AdminNotices($notes, fn() => $updates, fn() => []), fn() => $running);
$c = $noContact->defaults([], true, true, false, '/admin/content?type=pages');
check('every screen gets the notices (the backup just made and the new version), the version, whether an update is there, the storage and where it is', [$c['admin_notification_unread_count'], $c['admin_version'], $c['admin_update_available'], $c['admin_update_latest'], $c['admin_default_password'], is_array($c['admin_storage_summary']), $c['admin_current_url']], [2, '1.2.3', true, '9.0.0', false, true, '/admin/content?type=pages']);
check('the notices are the latest ones', count($c['admin_notifications']) >= 1, true);
$c = $noContact->defaults([], false, false, true, '/admin');
check('those who do not manage the site get none, and no notices are raised for them', [$c['admin_notifications'], $c['admin_notification_unread_count'], isset($c['admin_current_url']), $c['admin_default_password']], [[], 0, false, true]);
$c = $noContact->defaults(['admin_notifications' => ['x'], 'admin_notification_unread_count' => 1, 'admin_storage_summary' => ['level' => 'ok']], true, true, false, '/admin');
check('a screen that brings its own notices and storage keeps them: nothing is read again', [isset($c['admin_notifications']), isset($c['admin_storage_summary']), isset($c['admin_current_url'])], [false, false, false]);
$full = ['level' => 'danger', 'percent' => 97, 'percent_of_limit' => 97, 'label' => '9.7 MB / 10 MB'];
check('a full storage with nobody to write to has no contact', $noContact->defaults(['admin_storage_summary' => $full], true, true, false, '/admin')['admin_storage_contact'], null);
$users->create(['username' => 'root', 'email' => 'root@x.test', 'display_name' => 'Root Person', 'role' => 'superadmin', 'status' => 'active']);
$k = $noContact->defaults(['admin_storage_summary' => $full], true, true, false, '/admin')['admin_storage_contact'];
check('with a super admin it is them, with the email drafted', [$k['name'], $k['email'], str_starts_with($k['href'], 'mailto:root@x.test?subject=Storage%20almost%20full'), str_contains(rawurldecode($k['href']), '97% full (9.7 MB / 10 MB)')], ['Root Person', 'root@x.test', true, true]);
check('an ordinary storage level has no contact', array_key_exists('admin_storage_contact', $noContact->defaults(['admin_storage_summary' => ['level' => 'warn']], true, true, false, '/admin')), false);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
