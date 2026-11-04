<?php
/*
 * Scheduled entries: a publishing time read in the site's time zone (an offset turned into it, an unquoted YAML time as UTC,
 * words refused), the site's zone from the settings, the release of the entries whose time has come (published, the day as
 * `date`, `publish_at` gone, the history and the index told, what was released reported once), and the ones left alone: not
 * due yet, no time, changed to a draft meanwhile. The editor keeps the time of a scheduled entry and makes one without a time
 * a draft.
 *   php tests/unit/scheduled-publishing.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{ContentIndex, ContentRepository, RevisionRepository, ScheduledPublishing, SiteSettings, SystemDatabase, SystemMetaRepository};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$athens = new DateTimeZone('Europe/Athens');

// Times.
check('a local time is kept', ScheduledPublishing::normalize('2026-11-03 09:00', $athens), '2026-11-03 09:00');
check('the editor box (T) is read', ScheduledPublishing::normalize('2026-11-03T09:00', $athens), '2026-11-03 09:00');
check('an offset is turned into the site zone', ScheduledPublishing::normalize('2026-11-03T07:00:00Z', $athens), '2026-11-03 09:00');
check('another offset too', ScheduledPublishing::normalize('2026-11-03T09:00:00+02:00', $athens), '2026-11-03 09:00');
check('an unquoted YAML time is UTC', ScheduledPublishing::normalize((int)gmmktime(7, 0, 0, 11, 3, 2026), $athens), '2026-11-03 09:00');
check('a day alone is not a time', ScheduledPublishing::normalize('2026-11-03', $athens), null);
check('words are not a time', ScheduledPublishing::normalize('tomorrow 9am', $athens), null);
check('empty is no time', ScheduledPublishing::normalize('', $athens), null);
check('a wrong date is no time', ScheduledPublishing::normalize('2026-13-45 09:00', $athens), null);

// The site's zone.
check('the chosen zone', SiteSettings::timeZone(['timezone' => 'Europe/Athens'])->getName(), 'Europe/Athens');
check('none chosen: the server zone', SiteSettings::timeZone([])->getName(), date_default_timezone_get());
check('an unknown name: the server zone', SiteSettings::timeZone(['timezone' => 'Mars/Olympus'])->getName(), date_default_timezone_get());

// Release.
$root = sys_get_temp_dir() . '/scheduled-publishing' . getmypid();
mkdir($root . '/content/posts', 0775, true);
mkdir($root . '/storage', 0775, true);
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });

$write = static function (string $name, string $front) use ($root): void {
    file_put_contents($root . '/content/posts/' . $name . '.md', "---\n" . $front . "\n---\n\nBody of $name\n");
};
$write('due', "title: Due\nstatus: scheduled\npublish_at: '2026-11-03 09:00'\ndate: '2026-10-01'");
$write('later', "title: Later\nstatus: scheduled\npublish_at: '2026-11-03 09:30'");
$write('notime', "title: No time\nstatus: scheduled");
$write('draft', "title: Draft\nstatus: draft\npublish_at: '2026-11-01 09:00'");
$write('due-en', "title: Due in English\nstatus: scheduled\npublish_at: '2026-11-03T06:00:00Z'");

$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository($root . '/content', new MarkdownConverter($environment), $settings);
$database = new SystemDatabase($root . '/storage');
$database->initialize();
$index = new ContentIndex($database, $root . '/content', new SystemMetaRepository($database));
$index->rebuild($content, ['posts']);
$revisions = new RevisionRepository($database);

// Made a draft by hand after it was indexed as scheduled: the file decides, not the index.
$write('changed', "title: Changed\nstatus: scheduled\npublish_at: '2026-11-03 08:00'");
$index->rebuild($content, ['posts']);
$write('changed', "title: Changed\nstatus: draft\npublish_at: '2026-11-03 08:00'");

$reported = [];
$scheduled = new ScheduledPublishing($content, $index, $revisions, $athens, function (array $released) use (&$reported): void { $reported[] = $released; });

$released = $scheduled->release(new DateTimeImmutable('2026-11-03 09:10', $athens));
$slugs = array_map(static fn(array $entry): string => $entry['slug'], $released);
sort($slugs);
check('only the due entries are released', $slugs, ['due', 'due-en']);
check('what was released is reported once', count($reported), 1);

$meta = Yaml::parse(explode('---', (string)file_get_contents($root . '/content/posts/due.md'))[1]);
check('published', $meta['status'] ?? null, 'published');
check('the day of the time becomes the date', $meta['date'] ?? null, '2026-11-03');
check('and the time it was scheduled for becomes when it was made', isset($meta['created']) ? (new \DateTimeImmutable((string)$meta['created']))->setTimezone($athens)->format('Y-m-d H:i') : null, '2026-11-03 09:00');
check('publish_at is gone', array_key_exists('publish_at', $meta), false);
check('the body stays', str_contains((string)file_get_contents($root . '/content/posts/due.md'), "\n---\n\nBody of due\n"), true);
check('the history names the schedule', $revisions->latest('posts', 'due', 'el')['action'] ?? null, 'schedule');
check('the history says who', $revisions->latest('posts', 'due', 'el')['actor'] ?? null, ScheduledPublishing::ACTOR);
check('the index knows it is published', $index->connection()?->query("SELECT status FROM content_index WHERE slug = 'due'")->fetchColumn(), 'published');
check('the public sees it', $content->find('posts', 'due', 'el') !== null, true);

check('not due yet: still scheduled', str_contains((string)file_get_contents($root . '/content/posts/later.md'), 'status: scheduled'), true);
check('no time: still scheduled', str_contains((string)file_get_contents($root . '/content/posts/notime.md'), 'status: scheduled'), true);
check('scheduled in the index, a draft in the file: stays a draft', str_contains((string)file_get_contents($root . '/content/posts/changed.md'), 'status: draft'), true);
check('a draft with an old time stays a draft', str_contains((string)file_get_contents($root . '/content/posts/draft.md'), 'status: draft'), true);
check('the public does not see a scheduled entry', $content->find('posts', 'later', 'el') === null, true);

$reported = [];
check('nothing again a minute later', $scheduled->release(new DateTimeImmutable('2026-11-03 09:11', $athens)), []);
check('and nothing reported', $reported, []);
check('the later one in its time', array_column($scheduled->release(new DateTimeImmutable('2026-11-03 09:30', $athens)), 'slug'), ['later']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
