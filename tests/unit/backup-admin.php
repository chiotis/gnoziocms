<?php
/*
 * What the Backups screens do: verify, create, delete, restore (safety snapshot first, only the areas chosen), the
 * backup before an update, and the data of the screens. Real archives on a small test site.
 *   php tests/unit/backup-admin.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{BackupAdmin, BackupManager, BackupRunRepository, BackupService, NotificationRepository, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/backupadm' . getmypid();
foreach (['content/pages', 'public/uploads', 'custom', 'storage'] as $d) { mkdir("$dir/$d", 0775, true); }
file_put_contents("$dir/content/pages/a.md", "---\ntitle: A\n---\nOriginal\n");
file_put_contents("$dir/public/uploads/pic.txt", "picture");
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$service = new BackupService($dir, $db);
$runs = new BackupRunRepository($db);
$notes = new NotificationRepository($db);
$meta = new SystemMetaRepository($db);
$settings = ['title' => 'Site', 'backup' => ['auto' => ['enabled' => true, 'schedule' => 'weekly', 'last_run' => '2026-01-01T00:00:00+00:00'], 'local' => ['keep' => 20], 'remote' => ['enabled' => true, 'provider' => 'aws_s3', 'bucket' => 'b', 'prefix' => 'p', 'keep' => 3]]];
$updates = new class { function currentVersion(): string { return '9.9.9'; } function currentGitCommit(): string { return ''; } };
$lastRuns = [];
$manager = new BackupManager($service, $db, $runs, $notes, function () use (&$settings) { return $settings; }, fn() => $updates, function (string $iso) use (&$lastRuns) { $lastRuns[] = $iso; }, fn(array $c) => throw new RuntimeException('no remote here'));
$log = [];
$after = [];
$admin = new BackupAdmin($service, $manager, $runs, $notes, $meta, function () use (&$settings) { return $settings; }, function (string $iso) use (&$lastRuns) { $lastRuns[] = $iso; }, function (bool $ok) use (&$after) { $after[] = $ok; }, function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx, ?array $actor) use (&$log) { $log[] = [$action, $level, $id, $actor]; });
$settings['backup']['remote']['enabled'] = false;

// ---- creating
check('an unknown action is nothing', [$admin->apply('whatever', []), $admin->apply('', [])], [null, null]);
$loc = $admin->apply('create_full', []);
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
check('a full backup is made and the way back says so', [str_starts_with($loc, '/admin/backups?'), $q['backup'], $q['backup_msg'] !== ''], [true, 'ok', true]);
check('its time is stored in the settings, the run recorded, it is logged and notified', [count($lastRuns), $runs->recent(1)[0]['status'], $log[0][0], count($notes->recent(5))], [1, 'success', 'backup.create_success', 1]);
$full = $service->list()[0]['filename'];
sleep(1);
$loc = $admin->apply('create_database', []);
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
check('a database backup is made without touching the last-run time', [$q['backup'], count($lastRuns), end($log)[0], count($service->list())], ['ok', 1, 'backup.database_success', 2]);

// ---- verifying and deleting
parse_str((string)parse_url($admin->apply('verify', ['filename' => $full]), PHP_URL_QUERY), $q);
check('a good archive verifies', [$q['backup'], str_starts_with($q['backup_msg'], $full . ': '), end($log)[0]], ['ok', true, 'backup.verify_success']);
parse_str((string)parse_url($admin->apply('verify', ['filename' => '../../etc/passwd']), PHP_URL_QUERY), $q);
check('a bad file name is a failure, not a path', [$q['backup'], end($log)[0]], ['fail', 'backup.verify_failure']);
$bare = "$dir/storage/backups/bare.zip";
$zip = new ZipArchive();
$zip->open($bare, ZipArchive::CREATE);
$zip->addFromString('content/pages/a.md', "---\ntitle: Bare\n---\nBare\n");
$zip->close();
parse_str((string)parse_url($admin->apply('verify', ['filename' => 'bare.zip']), PHP_URL_QUERY), $q);
check('an archive without a manifest is valid but warns', $q['backup'], 'warn');
parse_str((string)parse_url($admin->apply('delete', ['filename' => 'bare.zip']), PHP_URL_QUERY), $q);
check('an archive is deleted', [$q['deleted'], is_file($bare), end($log)[0], end($log)[1]], ['ok', false, 'backup.delete_success', 'warning']);
parse_str((string)parse_url($admin->apply('delete', ['filename' => 'bare.zip']), PHP_URL_QUERY), $q);
check('deleting what is gone fails', [$q['deleted'], $q['backup_msg']], ['fail', 'Backup file not found.']);

// ---- the screen
$settings['backup']['local']['keep'] = 0;
$o = $admin->overview();
check('the list has the archives, their total size, the newest, the runs', [$o['backup_total'], $o['last_backup']['filename'] === $service->list()[0]['filename'], $o['backup_storage_human'] !== '', count($o['backup_runs'])], [2, true, true, 2]);
check('the schedule is shown, keeping at least one', $o['backup_schedule'], ['enabled' => true, 'frequency' => 'weekly', 'last_run' => '2026-01-01T00:00:00+00:00', 'keep' => 1]);
check('and the remote storage, off here', $o['backup_remote'], ['enabled' => false, 'provider' => 'aws_s3', 'bucket' => 'b', 'prefix' => 'p', 'keep' => 3]);
$settings['backup'] = [];
check('with nothing set it shows the defaults', [$admin->overview()['backup_schedule'], $admin->overview()['backup_remote']['provider']], [['enabled' => false, 'frequency' => 'daily', 'last_run' => '', 'keep' => 20], 'custom']);

// ---- the restore screen
check('there is no screen for an archive that does not exist', [$admin->restoreScreen('nope.zip'), $admin->restoreScreen('../x')], [null, null]);
$s = $admin->restoreScreen($full);
check('the screen names the archive and verifies it', [$s['filename'], $s['verification']['ok'], $s['snapshot']['filename']], [$full, true, $full]);
$byKey = array_column($s['scopes'], null, 'key');
check('every area says how much of it the archive holds, and is ticked when it has some', [array_keys($byKey), $byKey['content']['available'], $byKey['content']['checked'], $byKey['custom']['available'], $byKey['custom']['checked']], [['content', 'uploads', 'form-uploads', 'custom', 'database'], true, true, false, false]);
check('only the areas asked for are ticked', array_column($admin->restoreScreen($full, ['uploads'])['scopes'], 'checked', 'key')['content'], false);

// ---- restoring
check('an archive that does not exist goes back to the list', $admin->restore(['filename' => 'nope.zip'], null)['location'], '/admin/backups?backup=fail&backup_msg=Backup+file+not+found.');
$r = $admin->restore(['filename' => $full, 'scopes' => []], null);
check('no area chosen asks again', [$r['location'], $r['error']], [null, 'Select at least one area to restore.']);
$r = $admin->restore(['filename' => $full, 'scopes' => ['content', 'nonsense'], 'confirm_filename' => 'wrong.zip'], null);
check('the name must be typed, and the areas chosen are remembered (unknown ones dropped)', [$r['error'], $r['selected']], ['Type the archive name exactly as shown to confirm the restore.', ['content']]);
$unsafe = $service->list();
file_put_contents("$dir/content/pages/a.md", "---\ntitle: A\n---\nCHANGED\n");
file_put_contents("$dir/content/pages/new.md", "---\ntitle: New\n---\nNew page\n");
$count = count($service->list());
$log = []; $after = [];
sleep(1);
$actor = ['id' => 7, 'username' => 'boss'];
$r = $admin->restore(['filename' => $full, 'scopes' => ['content'], 'confirm_filename' => $full], $actor);
check('the restore puts the content back', [$r['location'] !== null, str_contains((string)file_get_contents("$dir/content/pages/a.md"), 'Original')], [true, true]);
parse_str((string)parse_url($r['location'], PHP_URL_QUERY), $q);
check('and says what was restored, with the safety snapshot and nothing missing', [$q['backup'], str_contains($q['backup_msg'], 'Safety snapshot: '), str_contains($q['backup_msg'], 'Not in archive:')], ['ok', true, false]);
check('a safety snapshot was made first, and is kept on its own (no pruning)', [count($service->list()) > $count, $service->list()[0]['reason']], [true, 'pre-restore']);
check('the running request was brought up to date', $after, [true]);
check('the restore is logged with who did it, and notified', [$log[0][0], $log[0][1], $log[0][3], in_array('backup.restored', array_column($notes->recent(10), 'type'), true)], ['backup.restore_success', 'warning', $actor, true]);
check('the safety snapshot is in the run history', array_column($runs->recent(3), 'filename')[0] === $service->list()[0]['filename'], true);
check('the area is put back as it was (a page added since is gone), other areas are untouched', [is_file("$dir/content/pages/new.md"), is_file("$dir/public/uploads/pic.txt")], [false, true]);

// an archive without a manifest needs an acknowledgement
file_put_contents("$dir/content/pages/a.md", "---\ntitle: A\n---\nCHANGED AGAIN\n");
$zip = new ZipArchive();
$zip->open($bare, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('content/pages/a.md', "---\ntitle: Bare\n---\nBare\n");
$zip->close();
$r = $admin->restore(['filename' => 'bare.zip', 'scopes' => ['content'], 'confirm_filename' => 'bare.zip'], null);
check('an archive with no checksums is not restored without the acknowledgement', [$r['error'], str_contains((string)file_get_contents("$dir/content/pages/a.md"), 'CHANGED AGAIN')], ['This archive has no checksum manifest. Tick the acknowledgement to restore it anyway.', true]);
$r = $admin->restore(['filename' => 'bare.zip', 'scopes' => ['content'], 'confirm_filename' => 'bare.zip', 'ack_unverified' => '1'], null);
check('with it, it is', [$r['location'] !== null, str_contains((string)file_get_contents("$dir/content/pages/a.md"), 'Bare')], [true, true]);
$r = $admin->restore(['filename' => $full, 'scopes' => ['custom', 'content'], 'confirm_filename' => $full], null);
parse_str((string)parse_url($r['location'], PHP_URL_QUERY), $q);
check('an area the archive does not hold is named as not in it', str_contains($q['backup_msg'], 'Not in archive: custom'), true);
$garbage = "$dir/storage/backups/garbage.zip";
file_put_contents($garbage, 'not a zip');
$r = $admin->restore(['filename' => 'garbage.zip', 'scopes' => ['content'], 'confirm_filename' => 'garbage.zip'], null);
check('an archive that fails verification restores nothing', [$r['location'], $r['error']], [null, 'The archive failed verification, so nothing was restored.']);

// ---- the backup before an update
$made = $admin->preUpdateBackup('1.2.3');
check('a verified backup is made for the update, and says which file', [$made['ok'], str_starts_with($made['message'], 'Verified pre-update backup created: '), $made['filename'] !== ''], [true, true, true]);
$st = $admin->preUpdateStatus();
check('it is remembered with its version, time, and that it verified', [$st['filename'] === $made['filename'], $st['verified'], $st['version'], strtotime($st['created_at']) >= time() - 5], [true, true, '1.2.3', true]);
check('it is logged', [end($log)[0]], ['updates.pre_backup_success']);
unlink("$dir/storage/backups/" . $made['filename']);
check('once its file is gone there is no such backup', $admin->preUpdateStatus(), null);
check('with nothing remembered there is none', (new BackupAdmin($service, $manager, $runs, $notes, new SystemMetaRepository(new SystemDatabase('/nonexistent/' . getmypid())), fn() => [], fn($i) => null, fn($o) => null, fn(...$a) => null))->preUpdateStatus(), null);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
