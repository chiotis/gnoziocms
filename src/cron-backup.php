<?php
/*
 * Publishes the scheduled entries whose time has come, writes the picture sizes pages may ask for that are missing (a few minutes'
 * worth per run; the next run goes on), works the background jobs that are due (AI pictures, a few minutes' worth), then takes the scheduled backup when it is due: for a cron job, so none of it depends on
 * someone visiting the site.
 * (It lives in src/ because an update replaces that folder; a new file elsewhere would stop the older sites from updating.)
 *   php src/cron-backup.php
 * Run it every 5 minutes on a site that schedules entries, otherwise once a day (the schedule in Settings > Backup decides
 * whether a backup is really due; running it more often does no harm).
 * Prints what it did: off, not due, busy, ok or failed; the exit status is 1 only when a backup was due and failed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new GnozioCMS\App($root);
$published = $app->releaseScheduled(false);
if ($published > 0) {
    echo 'published on schedule: ' . $published . PHP_EOL;
}
$pictures = $app->fillImageVariants(120);
if ($pictures > 0) {
    echo 'picture sizes written: ' . $pictures . PHP_EOL;
}
$jobs = $app->runJobs(180);
if ($jobs > 0) {
    echo 'background jobs worked: ' . $jobs . PHP_EOL;
}
$result = $app->runScheduledBackup();
echo $result . PHP_EOL;
exit($result === 'failed' ? 1 : 0);
