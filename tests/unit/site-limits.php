<?php
/*
 * The limits of a site: what storage it uses against the limit (measured once, kept for twelve hours, adjusted by
 * uploads and deletions), whether a file still fits, the size of one upload, and the settings form's readers.
 *   php tests/unit/site-limits.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{SiteLimits, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/limits' . getmypid();
foreach (['public/uploads/media', 'content/pages', 'storage/db'] as $d) { mkdir("$dir/$d", 0775, true); }
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$settings = ['limits' => ['storage_mb' => 1]];
$limits = new SiteLimits($meta, $dir, "$dir/content", function () use (&$settings) { return $settings; });
$put = fn(string $path, int $bytes) => file_put_contents("$dir/$path", str_repeat('x', $bytes));

// ---- measuring
check('a folder that does not exist is empty', SiteLimits::directorySize("$dir/nothing"), 0);
$put('public/uploads/media/a.png', 300000);
$put('public/uploads/media/b.png', 100000);
$put('content/pages/a.md', 50000);
$usedSystem = SiteLimits::directorySize("$dir/storage");
check('a folder is the sum of its files, in every level', SiteLimits::directorySize("$dir/public/uploads"), 400000);
$s = $limits->summary();
check('the summary adds up the three parts', [$s['used'], $s['used'] === 400000 + 50000 + $usedSystem], [450000 + $usedSystem, true]);
check('against a limit of 1 MB', [$s['limit'], $s['percent_of_limit'], $s['level'], $s['full']], [1048576, (int)round($s['used'] / 1048576 * 100), 'ok', false]);
check('the label says used and limit', $s['label'], $s['used_human'] . ' / 1.0 MB');
check('the parts are readable sizes', array_keys($s['parts']), ['uploads', 'content', 'system', 'forms']);
check('the measurement time is now', abs($s['measured_at'] - time()) <= 2, true);

// ---- kept for twelve hours
$put('public/uploads/media/c.png', 500000);
check('a file added later is not seen yet: the measurement is kept', (new SiteLimits($meta, $dir, "$dir/content", fn() => $settings))->summary()['used'], $s['used']);
$limits->measure();
check('measuring again sees it', $meta->getJson('storage_usage')['parts']['uploads'], 900000);
$limits->uploadsChanged(1000);
check('an upload adjusts the kept figure at once', $meta->getJson('storage_usage')['parts']['uploads'], 901000);
check('and the summary follows', $limits->summary()['used'] - $s['used'] > 500000, true);
$limits->uploadsChanged(-2000000);
check('a deletion never takes the figure below zero', $meta->getJson('storage_usage')['parts']['uploads'], 0);
$limits->uploadsChanged(0);
$kept = $meta->getJson('storage_usage');
$kept['measured_at'] = time() - SiteLimits::CACHE_SECONDS - 5;
$meta->setJson('storage_usage', $kept);
check('after twelve hours the folders are walked again', (new SiteLimits($meta, $dir, "$dir/content", fn() => $settings))->summary()['used'] > 900000, true);
$kept = $meta->getJson('storage_usage');
$kept['measured_at'] = time() + 3600;
$meta->setJson('storage_usage', $kept);
$again = new SiteLimits($meta, $dir, "$dir/content", fn() => $settings);
$again->summary();
check('a clock that went backwards counts as expired', $meta->getJson('storage_usage')['measured_at'] <= time(), true);
$meta->setJson('storage_usage', ['parts' => ['uploads' => 1], 'measured_at' => time()]);
check('a kept figure with parts missing is measured again', (new SiteLimits($meta, $dir, "$dir/content", fn() => $settings))->summary()['used'] > 900000, true);
$empty = new SiteLimits(new SystemMetaRepository(new SystemDatabase('/nonexistent/' . getmypid())), $dir, "$dir/content", fn() => $settings);
check('an adjustment with nothing kept is ignored', $empty->uploadsChanged(500), null);

// ---- levels and room
// The limit is in whole megabytes, so the kept figure is set directly to get exact ratios.
$keep = fn(int $bytes) => $meta->setJson('storage_usage', ['parts' => ['uploads' => $bytes, 'content' => 0, 'system' => 0, 'forms' => 0], 'measured_at' => time()]);
$with = fn(array $s) => new SiteLimits($meta, $dir, "$dir/content", fn() => $s);
$MB = 1048576;
$at = function (float $ratio) use ($keep, $with, $MB) { $keep((int)round($ratio * 10 * $MB)); return $with(['limits' => ['storage_mb' => 10]])->summary(); };
check('under 80% is ok', $at(0.5)['level'], 'ok');
check('from 80% it warns', $at(0.8)['level'], 'warn');
check('just under 80% does not', $at(0.79)['level'], 'ok');
check('from 90% it is danger', $at(0.9)['level'], 'danger');
check('at the limit it is full', [$at(1.0)['full'], $at(1.0)['percent'], $at(1.0)['level']], [true, 100, 'danger']);
check('over the limit the bar stays at 100%', [$at(1.4)['percent'], $at(1.4)['percent_of_limit']], [100, 140]);
check('a tiny use shows at least 1%', [$at(0.0001)['percent']], [1]);
$keep(3000000);
$none = $with(['limits' => ['storage_mb' => 0]])->summary();
check('no limit: always ok, nothing to show of it, the bar shows the disk', [$none['level'], $none['limit'], $none['limit_human'], $none['percent_of_limit'], $none['label']], ['ok', 0, '', null, $none['used_human']]);
check('the default limit is 1 GB', (new SiteLimits($meta, $dir, "$dir/content", fn() => []))->limitBytes(), 1073741824);
$keep(2 * $MB - 1000);
$room = $with(['limits' => ['storage_mb' => 2]]);
check('a file that fits is allowed, one byte more is not', [$room->allows(1000), $room->allows(1001), $room->allows(-5)], [true, false, true]);
check('with no limit anything is allowed', $with(['limits' => ['storage_mb' => 0]])->allows(PHP_INT_MAX / 2), true);
check('the full message says how much of what', str_starts_with($room->fullMessage(), 'The storage limit is reached (' . $room->summary()['used_human'] . ' of ' . $room->summary()['limit_human'] . ').'), true);

// ---- the size of one upload
$cap = SiteLimits::serverUploadCap();
$withLimit = fn(array $s) => (new SiteLimits($meta, $dir, "$dir/content", fn() => $s));
check('the site limit defaults to 20 MB', $withLimit([])->uploadLimitMb(), 20);
check('it can be set, and 0 means none of its own', [$withLimit(['limits' => ['upload_mb' => 5]])->uploadLimitMb(), $withLimit(['limits' => ['upload_mb' => 0]])->uploadLimitMb()], [5, 0]);
check('an older setting is still read', $withLimit(['media' => ['max_upload_mb' => 7]])->uploadLimitMb(), 7);
check('a negative one is none', $withLimit(['limits' => ['upload_mb' => -3]])->uploadLimitMb(), 0);
check('the server cap is never exceeded', $withLimit(['limits' => ['upload_mb' => 100000]])->maxUploadBytes(), $cap > 0 ? $cap : 100000 * 1048576);
check('a smaller site limit wins', $withLimit(['limits' => ['upload_mb' => 1]])->maxUploadBytes(), $cap > 0 ? min($cap, 1048576) : 1048576);
check('no site limit leaves the server cap', $withLimit(['limits' => ['upload_mb' => 0]])->maxUploadBytes(), $cap);

// ---- the settings form
check('the storage limit in GB is converted', SiteLimits::storageLimitFromForm(['storage_limit_value' => '2', 'storage_limit_unit' => 'gb']), 2048);
check('in MB as it is, a comma decimal works', [SiteLimits::storageLimitFromForm(['storage_limit_value' => '512', 'storage_limit_unit' => 'mb']), SiteLimits::storageLimitFromForm(['storage_limit_value' => '1,5', 'storage_limit_unit' => 'gb'])], [512, 1536]);
check('no unit means GB', SiteLimits::storageLimitFromForm(['storage_limit_value' => '1']), 1024);
check('zero is allowed (no limit)', SiteLimits::storageLimitFromForm(['storage_limit_value' => '0']), 0);
check('empty, negative, or text is nothing', [SiteLimits::storageLimitFromForm([]), SiteLimits::storageLimitFromForm(['storage_limit_value' => '-1']), SiteLimits::storageLimitFromForm(['storage_limit_value' => 'lots'])], [null, null, null]);
check('an absurd limit is capped', SiteLimits::storageLimitFromForm(['storage_limit_value' => '99999999', 'storage_limit_unit' => 'gb']), 10485760);
check('the upload size is read', SiteLimits::uploadSettingsFromForm(['upload_limit_mb' => '2,5']), ['mb' => 3, 'types' => null]);
check('an unusable one is nothing, and one past the cap is capped', [SiteLimits::uploadSettingsFromForm(['upload_limit_mb' => 'x'])['mb'], SiteLimits::uploadSettingsFromForm(['upload_limit_mb' => '999999'])['mb'], SiteLimits::uploadSettingsFromForm(['upload_limit_mb' => '-2'])['mb']], [null, 102400, null]);
check('the kinds of file are kept, unknown ones dropped', SiteLimits::uploadSettingsFromForm(['upload_types_present' => '1', 'upload_types' => ['images', 'nonsense', 'documents']])['types'], 'images,documents');
check('choosing none of them changes nothing', SiteLimits::uploadSettingsFromForm(['upload_types_present' => '1', 'upload_types' => []])['types'], null);
check('a form without the kinds changes nothing either', SiteLimits::uploadSettingsFromForm(['upload_types' => ['images']])['types'], null);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
