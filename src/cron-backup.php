<?php
/*
 * Takes the scheduled backup when it is due: for a cron job, so backups do not depend on someone visiting the site.
 * (It lives in src/ because an update replaces that folder; a new file elsewhere would stop the older sites from updating.)
 *   php src/cron-backup.php
 * Run it once a day (the schedule in Settings > Backup decides whether a backup is really due; running it more often does no harm).
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
$result = $app->runScheduledBackup();
echo $result . PHP_EOL;
exit($result === 'failed' ? 1 : 0);
