<?php
/*
 * Taking backups: when one is due, a snapshot with retention and the remote upload, the run history, the
 * notification, and the words for a result. The remote storage is a stand-in, so nothing leaves the machine.
 *   php tests/unit/backup-manager.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\BackupManager;
use GnozioCMS\BackupRunRepository;
use GnozioCMS\BackupService;
use GnozioCMS\NotificationRepository;
use GnozioCMS\SystemDatabase;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/backupmgr' . getmypid();
mkdir($dir . '/storage', 0775, true);
mkdir($dir . '/content/pages', 0775, true);
file_put_contents($dir . '/content/pages/a.md', "---\ntitle: A\n---\nHello\n");
$db = new SystemDatabase($dir . '/storage');
$db->initialize();
$service = new BackupService($dir, $db);
$runs = new BackupRunRepository($db);
$notes = new NotificationRepository($db);

$settings = ['title' => 'My Site!', 'backup' => ['auto' => ['enabled' => false, 'schedule' => 'daily', 'last_run' => ''], 'local' => ['keep' => 20], 'remote' => ['enabled' => false]]];
$updates = new class { function currentVersion(): string { return '9.9.9'; } function currentGitCommit(): string { return 'abc123'; } };
$lastRuns = [];
$remoteCalls = [];
$remoteBehaviour = ['upload' => ['ok' => true, 'message' => 'sent', 'object_key' => 'site/x.zip'], 'prune' => ['ok' => true, 'deleted' => 2], 'test' => ['ok' => true, 'message' => 'connected']];
$remoteFiles = []; // name => ['source' => a file to send, 'modified' => ISO time, 'size' => listed size, 'fail' => message]
$storage = function (array $config) use (&$remoteCalls, &$remoteBehaviour, &$remoteFiles) {
    return new class($config, $remoteCalls, $remoteBehaviour, $remoteFiles) {
        function __construct(public array $config, private array &$calls, private array $behaviour, private array &$files) {}
        function upload(string $path, string $filename): array { $this->calls[] = ['upload', $filename, is_file($path)]; return $this->behaviour['upload']; }
        function prune(int $keep): array { $this->calls[] = ['prune', $keep]; return $this->behaviour['prune']; }
        function testConnection(): array { $this->calls[] = ['test']; return $this->behaviour['test']; }
        function listBackups(): array {
            $this->calls[] = ['list'];
            if (isset($this->behaviour['list_error'])) { return ['ok' => false, 'message' => $this->behaviour['list_error']]; }
            $objects = [];
            foreach ($this->files as $name => $f) { $objects[] = ['key' => 'site/' . $name, 'modified' => $f['modified'], 'size' => $f['size']]; }
            return ['ok' => true, 'message' => 'listed', 'objects' => $objects];
        }
        function download(string $filename, string $destination): array {
            $this->calls[] = ['download', $filename];
            $f = $this->files[$filename] ?? null;
            if ($f === null || isset($f['fail'])) { return ['ok' => false, 'message' => $f['fail'] ?? 'Download failed: not there.']; }
            copy($f['source'], $destination);
            return ['ok' => true, 'message' => 'Remote download completed.', 'size' => filesize($destination)];
        }
    };
};
$m = new BackupManager($service, $db, $runs, $notes, function () use (&$settings) { return $settings; }, fn() => $updates, function (string $iso) use (&$lastRuns, &$settings) { $lastRuns[] = $iso; $settings['backup']['auto']['last_run'] = $iso; }, $storage);

// ---- when a backup is due
check('never run: due', BackupManager::isDue(0, 'daily'), true);
check('daily, an hour ago: not due', BackupManager::isDue(time() - 3600, 'daily'), false);
check('daily, 25 hours ago: due', BackupManager::isDue(time() - 90000, 'daily'), true);
check('weekly, 3 days ago: not due', BackupManager::isDue(time() - 3 * 86400, 'weekly'), false);
check('weekly, 8 days ago: due', BackupManager::isDue(time() - 8 * 86400, 'weekly'), true);
check('monthly, 20 days ago: not due', BackupManager::isDue(time() - 20 * 86400, 'monthly'), false);
check('monthly, 31 days ago: due', BackupManager::isDue(time() - 31 * 86400, 'monthly'), true);
check('an unknown schedule is never due', BackupManager::isDue(time() - 999999999, 'hourly'), false);

// A job that runs at the same time every day has its last run a few seconds after that time: the next day it must not be found early.
$noon = 1760270400;
check('daily, a job one day later (a few seconds short of 24 hours): due', BackupManager::isDue($noon + 12, 'daily', $noon + 86400), true);
check('daily, a minute under 22 hours later: not due (the slack is two hours)', BackupManager::isDue($noon, 'daily', $noon + 22 * 3600 - 60), false);
check('daily, 23 hours later: due', BackupManager::isDue($noon, 'daily', $noon + 23 * 3600), true);
check('weekly, a job a week later: due; five days later: not', [BackupManager::isDue($noon + 12, 'weekly', $noon + 7 * 86400), BackupManager::isDue($noon, 'weekly', $noon + 5 * 86400)], [true, false]);
check('monthly: thirty days later is due, a month of 20 days is not', [BackupManager::isDue($noon + 12, 'monthly', $noon + 30 * 86400), BackupManager::isDue($noon, 'monthly', $noon + 20 * 86400)], [true, false]);

// ---- words for a result
check('failed', BackupManager::status(['ok' => false]), 'failed');
check('success', BackupManager::status(['ok' => true, 'status' => 'success']), 'success');
check('warning', BackupManager::status(['ok' => true, 'status' => 'warning']), 'warning');
check('no status means success', BackupManager::status(['ok' => true]), 'success');
check('log levels', [BackupManager::logLevel(['ok' => false]), BackupManager::logLevel(['ok' => true, 'status' => 'warning']), BackupManager::logLevel(['ok' => true])], ['error', 'warning', 'info']);
check('query statuses', [BackupManager::queryStatus(['ok' => false]), BackupManager::queryStatus(['ok' => true, 'status' => 'warning']), BackupManager::queryStatus(['ok' => true])], ['fail', 'warn', 'ok']);

// ---- a local snapshot
$r = $m->createSnapshot();
check('a snapshot is made', [$r['ok'], $r['status']], [true, 'success']);
check('the archive exists, named after the site', is_file($dir . '/storage/backups/' . $r['filename']) && str_starts_with($r['filename'], 'my-site'), true);
check('no remote is not a failure', [$r['remote'], $remoteCalls], [null, []]);
$list = $service->list();
check('the archive knows its reason and version', [$list[0]['reason'], $list[0]['version']], ['manual', '9.9.9']);
$m->recordRun($r);
$recent = $runs->recent(5);
check('the run is recorded with its size', [$recent[0]['filename'] === $r['filename'], $recent[0]['status'], $recent[0]['size_bytes'] > 0], [true, 'success', true]);
$d = $m->createDatabaseSnapshot();
check('a database snapshot is made', [$d['ok'], str_contains($d['filename'], 'database')], [true, true]);

// ---- retention (only after a snapshot; a safety snapshot never deletes)
sleep(1);
$settings['backup']['local']['keep'] = 1;
$kept = $m->createSnapshot('pre-restore', false, false);
check('a safety snapshot is not pruned', count($service->list()) >= 3, true);
sleep(1); // the newest is decided by the time of change, in whole seconds
$last = $m->createSnapshot();
check('with keep = 1 only the newest stays', array_column($service->list(), 'filename'), [$last['filename']]);
$settings['backup']['local']['keep'] = 20;

// ---- remote upload
$settings['backup']['remote'] = ['enabled' => true, 'keep' => 5, 'bucket' => 'b'];
sleep(1);
$r = $m->createSnapshot();
check('upload succeeds', [$r['status'], $r['remote']['ok']], ['success', true]);
check('no local copy by default: the archive is removed once it is remote', [is_file($dir . '/storage/backups/' . $r['filename']), $r['removed_local'], str_contains($r['message'], 'not kept on this server')], [false, true, true]);
$m->recordRun($r);
check('and the run records the size of what was made', [$runs->recent(1)[0]['filename'] === $r['filename'], $runs->recent(1)[0]['size_bytes'] > 0], [true, true]);
check('the message says so, with the object and the pruned count', str_contains($r['message'], 'Remote upload completed.') && str_contains($r['message'], 'Object: site/x.zip.') && str_contains($r['message'], 'Pruned 2 remote backup(s).'), true);
check('the file was there when uploading, and the remote keep was used', array_slice($remoteCalls, 0, 2), [['upload', $r['filename'], true], ['prune', 5]]);
$remoteCalls = [];
sleep(1);
$skip = $m->createSnapshot('pre-restore', false, false);
check('a safety snapshot is not uploaded', [$skip['remote'], $remoteCalls], [null, []]);
check('and it stays on the server', is_file($dir . '/storage/backups/' . $skip['filename']), true);
$remoteBehaviour['upload'] = ['ok' => true, 'message' => 'sent', 'object_key' => 'site/x.zip'];
sleep(1);
$update = $m->createSnapshot('pre-update');
check('a pre-update snapshot is uploaded but stays on the server (the update can be undone from it)', [$update['remote']['ok'], is_file($dir . '/storage/backups/' . $update['filename'])], [true, true]);
$settings['backup']['remote']['keep_local'] = true;
sleep(1);
$kept = $m->createSnapshot();
check('keep_local: uploaded and kept', [$kept['remote']['ok'], is_file($dir . '/storage/backups/' . $kept['filename']), $kept['removed_local'] ?? false], [true, true, false]);
$settings['backup']['remote']['keep_local'] = false;
sleep(1);
$db2 = $m->createDatabaseSnapshot();
check('a database snapshot follows the same rule', [$db2['ok'], is_file($dir . '/storage/backups/' . $db2['filename'])], [true, false]);
// the newest backup lives only remotely: it is still known (dashboard, schedule), but not listed as a local archive
$m->recordRun($db2);
$known = $m->knownArchives();
check('a remote-only backup is known after the local ones', [$known[0]['filename'], $known[0]['remote'] ?? false, $known[0]['size'] > 0], [$db2['filename'], true, true]);
check('and is not in the local list', in_array($db2['filename'], array_column($service->list(), 'filename'), true), false);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'daily', 'last_run' => date('c')];
check('the schedule status counts it as the latest backup', str_starts_with($m->scheduleStatus()['value'], 'daily, last '), true);
$settings['backup']['auto']['enabled'] = false;
$remoteBehaviour['upload'] = ['ok' => false, 'message' => 'no route'];
sleep(1);
$w = $m->createSnapshot();
check('a failed upload is a warning, the archive is kept', [$w['ok'], $w['status'], is_file($dir . '/storage/backups/' . $w['filename'])], [true, 'warning', true]);
check('and says why', str_contains($w['message'], 'Saved locally, but remote upload failed: no route'), true);
check('testing the remote asks the storage', $m->testRemote(), ['ok' => true, 'message' => 'connected']);
$settings['backup']['remote'] = ['enabled' => false];

// ---- fetching from remote storage (an archive that lives only there is brought back to the server, never restored by it)
$settings['backup']['remote'] = ['enabled' => true, 'keep' => 5, 'bucket' => 'b'];
sleep(1);
$stash = $m->createSnapshot('manual', false, false); // a real archive to play the remote copy
$stashPath = $dir . '/storage/backups/' . $stash['filename'];
$goodName = 'my-site-backup-20260101-120000-aaaaaa.zip';
copy($stashPath, $dir . '/good.bin');
file_put_contents($dir . '/garbage.bin', str_repeat('this is not a zip file', 50));
$remoteFiles = [
    $goodName => ['source' => $dir . '/good.bin', 'modified' => '2026-01-01T12:00:00.000Z', 'size' => filesize($dir . '/good.bin')],
    'my-site-backup-20260102-120000-bbbbbb.zip' => ['source' => $dir . '/garbage.bin', 'modified' => '2026-01-02T12:00:00.000Z', 'size' => filesize($dir . '/garbage.bin')],
    'my-site-backup-20260103-120000-cccccc.zip' => ['source' => $dir . '/good.bin', 'modified' => '2026-01-03T12:00:00.000Z', 'size' => filesize($dir . '/good.bin') + 1],
    'my-site-backup-20260104-120000-dddddd.zip' => ['source' => $dir . '/good.bin', 'modified' => '2026-01-04T12:00:00.000Z', 'size' => filesize($dir . '/good.bin'), 'fail' => 'Download failed: S3 returned HTTP 403 (Access Denied)'],
    'my-site-database-backup-20260105-120000-eeeeee.zip' => ['source' => $dir . '/good.bin', 'modified' => '2026-01-05T12:00:00.000Z', 'size' => filesize($dir . '/good.bin')],
];
unlink($stashPath);
$listed = $m->remoteBackups();
check('the remote archives are listed with type, size and whether they are here', [$listed['ok'], array_column($listed['backups'], 'type', 'filename')[$goodName], array_column($listed['backups'], 'type', 'filename')['my-site-database-backup-20260105-120000-eeeeee.zip'], $listed['backups'][0]['size'] > 0, $listed['backups'][0]['local']], [true, 'full', 'database', true, false]);
$settings['backup']['remote']['enabled'] = false;
check('with remote storage off there is nothing to list or fetch', [$m->remoteBackups()['ok'], $m->fetchRemote($goodName)['message']], [false, 'Remote storage is not turned on.']);
$settings['backup']['remote']['enabled'] = true;
$remoteCalls = [];
$f = $m->fetchRemote($goodName);
check('an archive is fetched into the backups folder, verified, and named for what it is', [$f['ok'], $f['filename'], is_file($dir . '/storage/backups/' . $goodName), $service->verify($goodName)['ok']], [true, $goodName, true, true]);
check('it keeps the date it was made, so it sorts where it belongs', filemtime($dir . '/storage/backups/' . $goodName), strtotime('2026-01-01T12:00:00Z'));
check('no temporary file is left behind', glob($dir . '/storage/backups/.fetch-*'), []);
check('and it is now listed as on this server', array_column($m->remoteBackups()['backups'], 'local', 'filename')[$goodName], true);
check('fetching it again says it is already here, and downloads nothing', [$m->fetchRemote($goodName)['message'], array_count_values(array_column($remoteCalls, 0))['download']], [$goodName . ' is already on this server.', 1]);
$g = $m->fetchRemote('my-site-backup-20260102-120000-bbbbbb.zip');
check('a file that is not a backup is discarded', [$g['ok'], str_starts_with($g['message'], 'The downloaded file is not a valid backup, so it was discarded'), is_file($dir . '/storage/backups/my-site-backup-20260102-120000-bbbbbb.zip'), glob($dir . '/storage/backups/.fetch-*')], [false, true, false, []]);
$g = $m->fetchRemote('my-site-backup-20260103-120000-cccccc.zip');
check('a download that is not the size listed is discarded', [$g['ok'], $g['message'], is_file($dir . '/storage/backups/my-site-backup-20260103-120000-cccccc.zip')], [false, 'The download was incomplete, so it was discarded. Try again.', false]);
$g = $m->fetchRemote('my-site-backup-20260104-120000-dddddd.zip');
check('a refused download says what the storage said', [$g['ok'], $g['message']], [false, 'Download failed: S3 returned HTTP 403 (Access Denied)']);
check('a name that is not in the bucket is not fetched', [$m->fetchRemote('other-backup-20260101-000000-ffffff.zip')['message'], $m->fetchRemote('../../etc/passwd')['message'], $m->fetchRemote('')['message']], ['other-backup-20260101-000000-ffffff.zip was not found in remote storage.', 'Choose a backup to fetch.', 'Choose a backup to fetch.']);
$remoteBehaviour['list_error'] = 'S3 returned HTTP 403 (Access Denied)';
check('when the bucket cannot be read, both say so', [$m->remoteBackups(), $m->fetchRemote('my-site-database-backup-20260105-120000-eeeeee.zip')['message']], [['ok' => false, 'message' => 'S3 returned HTTP 403 (Access Denied)', 'backups' => []], 'S3 returned HTTP 403 (Access Denied)']);
unset($remoteBehaviour['list_error']);
$settings['backup']['remote'] = ['enabled' => false];
unlink($dir . '/storage/backups/' . $goodName);

// ---- the notification
$m->notify(['ok' => true, 'status' => 'success', 'message' => 'done', 'filename' => 'x.zip'], true);
$m->notify(['ok' => true, 'status' => 'warning', 'message' => 'careful'], false);
$m->notify(['ok' => false, 'message' => 'broke'], false);
$types = array_column($notes->recent(10), 'type');
sort($types);
check('one notification per outcome', $types, ['backup.failed', 'backup.remote_failed', 'backup.success']);
$m->notify(['ok' => true, 'status' => 'success', 'message' => 'done', 'filename' => 'x.zip'], true);
check('the same one is not repeated', count($notes->recent(10)), 3);

// ---- the schedule
$before = count($service->list());
$offResult = $m->runIfDue();
check('off: nothing runs', [count($service->list()), $lastRuns, $offResult], [$before, [], 'off']);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'hourly', 'last_run' => ''];
$unknown = $m->runIfDue();
check('an unknown schedule does not run', [count($service->list()), $unknown], [$before, 'off']);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'daily', 'last_run' => ''];
sleep(1);
check('due, asked without doing: the schedule says so', $m->isRunDue(), true);
$ran = $m->runIfDue();
check('due: a scheduled backup is made, the time stored, the run recorded', [count($service->list()) > 0, count($lastRuns), $runs->recent(1)[0]['status'], $ran], [true, 1, 'success', 'ok']);
check('and it says it was scheduled', $service->list()[0]['reason'], 'scheduled');
$count = count($service->list());
$again = $m->runIfDue();
check('just ran: not due again', [count($service->list()), count($lastRuns), $again, $m->isRunDue()], [$count, 1, 'not due', false]);
$settings['backup']['auto']['last_run'] = date('c', time() - 2 * 86400);
$lock = fopen($dir . '/storage/backups/.auto-backup.lock', 'c');
flock($lock, LOCK_EX);
$m->runIfDue();
check('while another request holds the lock nothing runs', [count($service->list()), count($lastRuns)], [$count, 1]);
flock($lock, LOCK_UN);
fclose($lock);

// ---- the status line for the system check
$settings['backup']['auto'] = ['enabled' => false];
check('off with backups made: ok', $m->scheduleStatus(), ['label' => 'Scheduled backups', 'value' => 'off', 'status' => 'ok']);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'daily', 'last_run' => date('c')];
$s = $m->scheduleStatus();
check('on and recent: ok', [$s['status'], str_starts_with($s['value'], 'daily, last ')], ['ok', true]);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'daily', 'last_run' => date('c', time() - 5 * 86400)];
$s = $m->scheduleStatus();
check('on but overdue: warning', [$s['status'], $s['value']], ['warning', 'overdue (daily)']);

// ---- a file of the site named like the archive's own manifest does not break the check
file_put_contents($dir . '/' . BackupService::MANIFEST_NAME, '{"stray":"a backup unzipped in the project"}');
$stray = $m->createSnapshot('pre-restore', false, false);
$v = $service->verify((string)$stray['filename']);
check('a stray manifest-named file: the snapshot still verifies', [$v['ok'], $v['errors']], [true, []]);
unlink($dir . '/' . BackupService::MANIFEST_NAME);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
