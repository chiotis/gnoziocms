<?php
/*
 * The health checks of the dashboard and the System screen, and what the dashboard shows each role.
 *   php tests/unit/system-status.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ActivityLogRepository, ContentRepository, DashboardData, EmailLogRepository, SiteLimits, SystemDatabase, SystemMetaRepository, SystemStatus, UserRepository};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/sysstat' . getmypid();
foreach (['public/uploads', 'content/pages', 'content/posts', 'content/users', 'storage/db'] as $d) { mkdir("$dir/$d", 0775, true); }
$db = new SystemDatabase("$dir/storage");
$db->initialize();

$mail = 'none';
$index = ['available' => true, 'stale' => false, 'rows' => 12];
$schedule = ['label' => 'Scheduled backups', 'value' => 'daily', 'status' => 'ok'];
$status = new SystemStatus($dir, "$dir/content", $db, function () use (&$mail) { return $mail; }, function () use (&$index) { if ($index === null) { throw new RuntimeException('no'); } return $index; }, function () use (&$schedule) { return $schedule; });
$storage = ['disk_free_human' => '10 GB', 'percent' => 20];
$by = fn(array $checks) => array_column($checks, null, 'label');

$checks = $by($status->checks($storage));
check('every check is named', array_keys($checks), ['PHP', 'Upload limit', 'Memory limit', 'SQLite', 'Content directory', 'Storage directory', 'Uploads directory', 'Email provider', 'Disk free', 'Content index', 'Scheduled backups']);
$withCron = new SystemStatus($dir, "$dir/content", $db, function () use (&$mail) { return $mail; }, function () use (&$index) { return ['available' => true, 'stale' => false, 'rows' => 12]; }, fn() => $schedule, fn() => ['label' => 'Cron job', 'value' => 'running, last 2026-10-12 03:00', 'status' => 'ok']);
check('a cron job check is the last one when it is given, and absent when not', [array_column($withCron->checks(['disk_free_human' => '1 GB', 'percent' => 1]), 'label')[11] ?? null, count($withCron->checks(['disk_free_human' => '1 GB', 'percent' => 1])) - count($status->checks(['disk_free_human' => '1 GB', 'percent' => 1]))], ['Cron job', 1]);
check('PHP is ok', [$checks['PHP']['value'], $checks['PHP']['status']], [PHP_VERSION, 'ok']);
check('the database is available', [$checks['SQLite']['value'], $checks['SQLite']['status']], ['available', 'ok']);
check('folders that can be written are ok', [$checks['Content directory']['status'], $checks['Storage directory']['status'], $checks['Uploads directory']['status']], ['ok', 'ok', 'ok']);
check('no mail service is a warning', [$checks['Email provider']['value'], $checks['Email provider']['status']], ['not configured', 'warning']);
$mail = 'smtp';
check('a mail service is named in capitals', $by($status->checks($storage))['Email provider'], ['label' => 'Email provider', 'value' => 'SMTP', 'status' => 'ok']);
check('disk space is shown, and warns past 90% use', [$checks['Disk free']['value'], $checks['Disk free']['status'], $by($status->checks(['disk_free_human' => '1 GB', 'percent' => 91]))['Disk free']['status']], ['10 GB', 'ok', 'warning']);
check('the content index says how many entries', $checks['Content index'], ['label' => 'Content index', 'value' => '12 entries', 'status' => 'ok']);
$index = ['available' => true, 'stale' => true, 'rows' => 3];
check('an out of date index is a warning', $by($status->checks($storage))['Content index']['value'], 'out of date');
$index = ['available' => false];
check('no database means the index is unavailable', $by($status->checks($storage))['Content index']['value'], 'SQLite unavailable');
$index = null;
check('an index that cannot be read does not stop the checks', $by($status->checks($storage))['Content index'], ['label' => 'Content index', 'value' => 'unavailable', 'status' => 'warning']);
check('the backup check is the one handed in', $checks['Scheduled backups'], $schedule);
chmod("$dir/public/uploads", 0555);
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
check('an uploads folder that cannot be written is a warning', $isRoot ? 'warning' : $by($status->checks($storage))['Uploads directory']['status'], 'warning');
chmod("$dir/public/uploads", 0775);
$dead = new SystemStatus($dir, "$dir/content", new SystemDatabase('/nonexistent/' . getmypid()), fn() => 'none', fn() => ['available' => false], fn() => $schedule);
$deadChecks = $by($dead->checks($storage));
check('a database that cannot open is an error with its reason', [$deadChecks['SQLite']['status'], $deadChecks['SQLite']['value'] !== 'available'], ['error', true]);

// ---- the verdict
check('an error needs attention', SystemStatus::summarize([['status' => 'ok'], ['status' => 'error'], ['status' => 'warning']]), ['label' => 'Needs attention', 'status' => 'error', 'detail' => '1 critical check']);
check('one warning is not plural', SystemStatus::summarize([['status' => 'warning'], ['status' => 'ok']])['detail'], '1 check to review');
check('warnings are counted', SystemStatus::summarize([['status' => 'warning'], ['status' => 'warning'], ['status' => 'ok']]), ['label' => 'Warnings', 'status' => 'warning', 'detail' => '2 checks to review']);
check('otherwise healthy', SystemStatus::summarize([['status' => 'ok']]), ['label' => 'Healthy', 'status' => 'ok', 'detail' => 'All checks passing']);
check('nothing checked is healthy', SystemStatus::summarize([])['status'], 'ok');

// ---- the System screen
$extensions = SystemStatus::extensions();
check('every extension has a purpose and a verdict', [count($extensions), array_keys($extensions[0])], [8, ['name', 'purpose', 'loaded', 'status']]);
$sqlite = array_values(array_filter($extensions, fn($e) => $e['name'] === 'pdo_sqlite'))[0];
check('a loaded one is ok', [$sqlite['loaded'], $sqlite['status']], [true, 'ok']);
$env = $status->environment('1.2.3', 'abc123', true);
check('the environment names the version with its commit', [$env['GnozioCMS'], $env['HTTPS'], str_starts_with($env['PHP'], PHP_VERSION)], ['1.2.3 @ abc123', 'yes', true]);
check('no commit, no at-sign', $status->environment('1.2.3', '', false)['GnozioCMS'], '1.2.3');
check('the database version is shown', $env['SQLite'] === $status->sqliteVersion() && $env['SQLite'] !== 'unavailable', true);
check('a database that cannot open has no version', [$dead->sqliteVersion(), $dead->environment('v', '', false)['SQLite']], ['', 'unavailable']);

// ---- the dashboard
$settings = ['title' => 'Site', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'limits' => ['storage_mb' => 100]];
$env2 = new Environment([]);
$env2->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($env2), $settings);
$page = function (string $dir2, string $file, string $title, string $status) use ($dir) { file_put_contents("$dir/content/$dir2/$file", "---\ntitle: $title\nstatus: $status\n---\nBody\n"); };
$page('pages', 'a.md', 'Alpha', 'published');
$page('pages', 'b.md', 'Beta', 'draft');
$page('pages', 'a.en.md', 'Alpha EN', 'published');
$page('posts', 'p.md', 'Post', 'published');
touch("$dir/content/pages/a.md", time() - 100);
$betaTime = time() - 50; // kept, so that the minute shown below is the one that was set even if a minute passes between the two
touch("$dir/content/pages/b.md", $betaTime);
touch("$dir/content/pages/a.en.md", time() - 200);
touch("$dir/content/posts/p.md", time() - 10);
$users = new UserRepository($db, "$dir/content/users/users.yaml");
$users->create(['username' => 'boss', 'email' => 'boss@x.test', 'password_hash' => 'x', 'role' => 'superadmin']);
$users->create(['username' => 'gone', 'email' => 'gone@x.test', 'password_hash' => 'x', 'role' => 'user', 'status' => 'inactive']);
$activity = new ActivityLogRepository($db);
$activity->record(['action' => 'x.y', 'level' => 'info', 'message' => 'one']);
$emails = new EmailLogRepository($db);
$emails->record(['recipient' => 'a@x.test', 'subject' => 'Hi', 'status' => 'failed']);
$emails->record(['recipient' => 'b@x.test', 'subject' => 'Yo', 'status' => 'sent']);
$backups = [['filename' => 'one.zip'], ['filename' => 'two.zip']];
$meta = new SystemMetaRepository($db);
$limits = new SiteLimits($meta, $dir, "$dir/content", fn() => $settings);
$mail = 'none'; $index = ['available' => true, 'stale' => false, 'rows' => 4];
$dash = new DashboardData($content, $users, $activity, $emails, fn() => $backups, $limits, $status);
$all = fn(string $c) => true;
$d = $dash->build($all, $all);
check('content is counted by type, drafts apart', [$d['content_total'], $d['content_published'], $d['content_draft']], [4, 3, 1]);
check('each type has its own figures', $d['content_types'], [['type' => 'pages', 'count' => 3, 'published' => 2, 'draft' => 1], ['type' => 'posts', 'count' => 1, 'published' => 1, 'draft' => 0]]);
check('recent content is newest first', array_column($d['recent_content'], 'title'), ['Post', 'Beta', 'Alpha', 'Alpha EN']);
check('with its language, status and time', [$d['recent_content'][1]['lang'], $d['recent_content'][1]['status'], $d['recent_content'][1]['updated_at']], ['el', 'draft', date('Y-m-d H:i', $betaTime)]);
check('people, backups, logs and mail are shown', [$d['users_total'], $d['users_active'], $d['backups_total'], $d['last_backup'], $d['activity_total'], count($d['recent_activity']), $d['email_total'], $d['failed_emails']], [2, 1, 2, ['filename' => 'one.zip'], 1, 1, 2, 1]);
check('the storage summary and system health are included', [$d['storage']['limit'], count($d['system_checks']) > 5, $d['system_status']['status'], $d['php_version']], [104857600, true, 'warning', PHP_VERSION]);

// ---- what to watch, and the drafts
$watching = new DashboardData($content, $users, $activity, $emails, fn() => $backups, $limits, $status, fn(callable $can) => ['languages' => ['el', 'en']] + ($can('seo.manage') ? ['seo' => ['discourage' => true, 'no_description' => 2, 'duplicate_titles' => 0], 'links' => 4] : []) + ($can('forms.manage') ? ['submissions' => 3] : []));
$w = $watching->build($all, $all);
check('the drafts are listed, newest first, and counted', [array_column($w['drafts'], 'title'), $w['drafts_total'], $w['drafts_stale']], [['Beta'], 1, 0]);
touch("$dir/content/pages/b.md", time() - 40 * 86400);
$later = new DashboardData(new ContentRepository("$dir/content", new MarkdownConverter($env2), $settings), $users, $activity, $emails, fn() => $backups, $limits, $status);
$r = $later->build($all, $all);
check('a draft left alone for a month is stale', [$r['drafts_stale'], $r['drafts'][0]['title']], [1, 'Beta']);
touch("$dir/content/pages/b.md", time() - 50);
check('submissions come from the site', $w['submissions_week'], 3);
check('without the site, there is none', $d['submissions_week'], null);
$titles = array_column($w['attention'], 'title');
check('entries missing a language are counted (Beta and the post have no English)', in_array('2 entries are missing a language', $titles, true), true);
check('what the site knows is turned into lines, the serious first', [$w['attention'][0]['title'], in_array('4 links in content still use an old address', $titles, true)], ['The site asks search engines to stay away', true]);
check('the storage, the checks and the failed mail are part of it for the super admin', [in_array('Email provider: not configured', $titles, true), in_array('1 email could not be sent this week', $titles, true)], [true, true]);
$ed = $watching->build(fn(string $c) => $c === 'content.manage', $all);
check('an editor is told about drafts and languages only', array_column($ed['attention'], 'title'), ['2 entries are missing a language']);
check('and has no figures of mail from this week', $ed['failed_emails_week'], 0);

$editor = $dash->build(fn(string $c) => $c === 'content.manage', fn(string $t) => $t === 'posts');
check('an editor sees only the types allowed', [$editor['content_total'], array_column($editor['content_types'], 'type')], [1, ['posts']]);
check('and nothing about people, backups, logs, mail or the system', [$editor['users_total'], $editor['backups_total'], $editor['last_backup'], $editor['recent_activity'], $editor['activity_total'], $editor['recent_emails'], $editor['email_total'], $editor['failed_emails'], $editor['system_checks'], $editor['system_status'], $editor['php_version'], $editor['upload_limit'], $editor['memory_limit']], [0, 0, null, [], 0, [], 0, 0, [], ['status' => 'ok', 'label' => '', 'detail' => ''], '', '', '']);
$mixed = $dash->build(fn(string $c) => in_array($c, ['backups.manage', 'settings.manage'], true), $all);
check('each capability opens its own part', [$mixed['backups_total'], $mixed['users_total'], count($mixed['system_checks']) > 0, $mixed['activity_total']], [2, 0, true, 0]);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
