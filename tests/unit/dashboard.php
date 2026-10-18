<?php
/*
 * The dashboard's list of things to watch: what each fact turns into, the order (most serious first), what a quiet site shows,
 * and the counts of submissions and redirects that feed it.
 *   php tests/unit/dashboard.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{DashboardAttention, FormSubmissionRepository, RedirectRepository, SystemDatabase};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$now = 1_800_000_000;
$titles = fn(array $facts): array => array_column(DashboardAttention::items($facts, $now), 'title');
$levels = fn(array $facts): array => array_column(DashboardAttention::items($facts, $now), 'level');

check('a quiet site has nothing to watch', DashboardAttention::items([], $now), []);
check('facts that are fine say nothing', DashboardAttention::items([
    'storage' => ['level' => 'ok', 'used_human' => '1 MB', 'limit' => 0],
    'checks' => [['label' => 'PHP', 'value' => '8.3', 'status' => 'ok']],
    'backups' => ['count' => 2, 'last' => $now - 86400, 'schedule' => ['status' => 'ok']],
    'failed_emails' => 0, 'seo' => ['discourage' => false, 'no_description' => 0, 'duplicate_titles' => 0],
    'links' => 0, 'not_found' => 0, 'drafts_stale' => 0, 'untranslated' => 0,
    'analytics' => ['mode' => 'platform', 'empty' => true], 'no_site_address' => false,
], $now), []);

// ---- one by one
check('a new version', DashboardAttention::items(['update' => ['latest' => '0.2.0', 'current' => '0.1.9']], $now)[0]['title'], 'GnozioCMS 0.2.0 is available');
$full = DashboardAttention::items(['storage' => ['level' => 'danger', 'used_human' => '9.5 MB', 'limit' => 10485760, 'limit_human' => '10.0 MB', 'can_change' => true]], $now)[0];
check('storage nearly full is serious, and offers the limit to those who may change it', [$full['level'], $full['title'], $full['action'], $full['href']], ['bad', 'Storage is almost full', 'Change the limit', '/admin/settings']);
check('the note says how much of the limit', str_contains($full['note'], '9.5 MB used of the 10.0 MB limit'), true);
$filling = DashboardAttention::items(['storage' => ['level' => 'warn', 'used_human' => '8.5 MB', 'limit' => 0, 'can_change' => false]], $now)[0];
check('storage filling up is a warning, and points to unused files for the others', [$filling['level'], $filling['action'], $filling['href']], ['warn', 'Find unused files', '/admin/media?usage=unused']);

$checks = DashboardAttention::items(['checks' => [
    ['label' => 'PHP', 'value' => '8.3', 'status' => 'ok'],
    ['label' => 'SQLite', 'value' => 'unavailable', 'status' => 'error'],
    ['label' => 'Email provider', 'value' => 'not configured', 'status' => 'warning'],
    ['label' => 'Disk free', 'value' => '1 GB', 'status' => 'warning'],
    ['label' => 'Scheduled backups', 'value' => 'off', 'status' => 'warning'],
]], $now);
check('each check that is not fine is one line, an error before a warning', array_map(fn($i) => [$i['level'], $i['title']], $checks), [['bad', 'SQLite: unavailable'], ['warn', 'Email provider: not configured']]);
check('the checks that have a note of their own are not told twice', array_column($checks, 'title'), ['SQLite: unavailable', 'Email provider: not configured']);

check('no backup yet', $titles(['backups' => ['count' => 0, 'last' => 0, 'schedule' => []]]), ['There is no backup yet']);
check('an old backup says how old', $titles(['backups' => ['count' => 1, 'last' => $now - 20 * 86400, 'schedule' => []]]), ['The last backup is 20 days old']);
check('a backup of unknown time is not called old', $titles(['backups' => ['count' => 1, 'last' => 0, 'schedule' => []]]), []);
check('a recent backup with an overdue schedule', $titles(['backups' => ['count' => 1, 'last' => $now - 86400, 'schedule' => ['status' => 'warning', 'value' => 'overdue (daily)']]]), ['Scheduled backups are overdue (daily)']);
check('failed mail, in the singular and plural', [$titles(['failed_emails' => 1]), $titles(['failed_emails' => 3])], [['1 email could not be sent this week'], ['3 emails could not be sent this week']]);

$seo = DashboardAttention::items(['seo' => ['discourage' => true, 'no_description' => 1, 'duplicate_titles' => 4]], $now);
check('the site hidden from search comes first, then pages without a description, then titles', array_map(fn($i) => [$i['level'], $i['title']], $seo), [
    ['bad', 'The site asks search engines to stay away'], ['warn', '1 page has no description for search'], ['info', '4 pages share a title with another'],
]);
check('links that use an old address', DashboardAttention::items(['links' => 2], $now)[0]['href'], '/admin/redirects');
check('addresses that were not found go to that tab', DashboardAttention::items(['not_found' => 5], $now)[0]['href'], '/admin/redirects?tab=missing');
check('forgotten drafts open the drafts', [$titles(['drafts_stale' => 2]), DashboardAttention::items(['drafts_stale' => 2], $now)[0]['href']], [['2 drafts not touched for over ' . DashboardAttention::STALE_DRAFT_DAYS . ' days'], '/admin/content?status=draft']);
check('entries missing a language', $titles(['untranslated' => 1]), ['1 entry is missing a language']);
check('no analytics is a hint, a custom code that is empty is a warning, the platform is silent', [
    DashboardAttention::items(['analytics' => ['mode' => 'off', 'empty' => true]], $now)[0]['level'],
    DashboardAttention::items(['analytics' => ['mode' => 'custom', 'empty' => true]], $now)[0]['level'],
    DashboardAttention::items(['analytics' => ['mode' => 'custom', 'empty' => false]], $now),
    DashboardAttention::items(['analytics' => ['mode' => 'platform', 'empty' => true]], $now),
], ['info', 'warn', [], []]);
check('no site address', $titles(['no_site_address' => true]), ['The site address is not set']);

// ---- the order
check('most serious first, and the order inside a kind stays', $levels([
    'drafts_stale' => 1, 'failed_emails' => 1, 'links' => 1, 'seo' => ['discourage' => true], 'update' => ['latest' => '1', 'current' => '0'],
]), ['bad', 'warn', 'warn', 'info', 'info']);
check('every line says where to go', array_unique(array_map(fn($i) => $i['href'] !== '' && $i['action'] !== '', DashboardAttention::items([
    'update' => ['latest' => '1'], 'failed_emails' => 1, 'links' => 1, 'not_found' => 1, 'drafts_stale' => 1, 'untranslated' => 1, 'no_site_address' => true,
], $now))), [true]);

// ---- the counts that feed it
$dir = sys_get_temp_dir() . '/dash' . getmypid();
mkdir("$dir/content/forms-submissions/contact", 0775, true);
mkdir("$dir/content/forms-submissions/quote", 0775, true);
foreach ([['contact', 'a', 100], ['contact', 'b', 3 * 86400], ['contact', 'c', 9 * 86400], ['quote', 'd', 6 * 86400], ['quote', 'e', 30 * 86400]] as [$form, $id, $age]) {
    file_put_contents("$dir/content/forms-submissions/$form/$id.json", '{"id":"' . $id . '"}');
    touch("$dir/content/forms-submissions/$form/$id.json", time() - $age);
}
file_put_contents("$dir/content/forms-submissions/contact/notes.txt", 'x');
$submissions = new FormSubmissionRepository("$dir/content");
check('submissions of the last 7 days are counted over every form, by their files', [$submissions->recentCount(7), $submissions->recentCount(1), $submissions->recentCount(60)], [3, 1, 5]);
check('a site without submissions has none', (new FormSubmissionRepository("$dir/none"))->recentCount(7), 0);

mkdir("$dir/storage/db", 0775, true);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$redirects = new RedirectRepository($db);
$redirects->create('/old-a', '/new-a', 301, 'manual', '', 'me');
$redirects->create('/old-b', '/new-b', 302, 'manual', '', 'me');
$redirects->create('/old-c', '/new-c', 301, 'manual', '', 'me');
$off = $redirects->findBySource('old-c');
$redirects->update((int)$off['id'], 'old-c', '/new-c', 301, false, '');
check('only permanent redirects that are on can have their links updated', $redirects->permanentSources(), ['old-a']);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
