<?php
/*
 * Overview > Publishing calendar: published entries on their date and scheduled ones on their publish_at, of every type the
 * person may see; drafts, undated entries and other periods left out; who wrote each (a person, AI publishing, MCP); the AI
 * schedules' runs added unless their post is on the calendar already; the month and week ranges, the filters and the totals.
 *   php tests/unit/publishing-calendar.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\PublishingCalendar;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$zone = new DateTimeZone('Europe/Athens');
date_default_timezone_set('Europe/Athens');
$dir = sys_get_temp_dir() . '/gnozio-calendar-' . bin2hex(random_bytes(4));
mkdir($dir . '/posts', 0777, true);
mkdir($dir . '/pages', 0777, true);
mkdir($dir . '/books', 0777, true);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE content_index (type TEXT, slug TEXT, lang TEXT, title TEXT, status TEXT, visible INTEGER, path TEXT, sort_date INTEGER, summary TEXT)');

$add = function (string $type, string $slug, string $status, string $at, array $front, string $date = 'x', string $lang = 'en') use ($pdo, $dir): void {
    $path = $type . '/' . $slug . '.md';
    file_put_contents($dir . '/' . $path, "---\n" . Symfony\Component\Yaml\Yaml::dump(['title' => ucfirst($slug), 'status' => $status] + $front) . "---\nBody\n");
    $time = (new DateTimeImmutable($at, new DateTimeZone('Europe/Athens')))->getTimestamp();
    $pdo->prepare('INSERT INTO content_index VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)')->execute([$type, $slug, $lang, ucfirst($slug), $status, $path, $time,
        json_encode(['date' => $date === 'x' ? substr($at, 0, 10) : $date])]);
};
$add('posts', 'by-hand', 'published', '2026-11-10 09:15', ['author' => 'Maria']);
$add('posts', 'by-schedule', 'published', '2026-11-11 08:00', ['generated_by' => 'AI publishing (Tips)', 'ai_schedule' => 3]);
$add('posts', 'by-claude', 'published', '2026-11-12 18:30', ['generated_by' => 'Claude']);
$add('posts', 'a-draft', 'draft', '2026-11-12 10:00', []);
$add('posts', 'last-month', 'published', '2026-10-01 10:00', []);
$add('posts', 'later', 'scheduled', '2026-11-01 10:00', ['publish_at' => '2026-11-20 07:45']);
$add('posts', 'much-later', 'scheduled', '2026-11-01 10:00', ['publish_at' => '2027-01-20 07:45']);
$add('pages', 'about', 'published', '2026-11-13 12:00', [], '');
$add('pages', 'midnight', 'published', '2026-11-14 00:00', []);
$add('books', 'secret', 'published', '2026-11-14 10:00', []);
$add('posts', 'greek', 'published', '2026-11-15 10:00', [], 'x', 'el');

$aiDays = function (DateTimeImmutable $from, int $count): array {
    $days = [];
    for ($i = 0; $i < $count; $i++) {
        $date = $from->modify('+' . $i . ' day')->format('Y-m-d');
        $runs = [];
        if ($date === '2026-11-11') {
            $runs[] = ['schedule' => 3, 'name' => 'Tips', 'time' => '08:00', 'hour' => 8, 'manual' => false, 'type' => 'posts', 'lang' => 'en', 'state' => 'published', 'title' => 'By-schedule', 'entry' => 'posts/by-schedule/en', 'error' => ''];
        }
        if ($date === '2026-11-18') {
            $runs[] = ['schedule' => 3, 'name' => 'Tips', 'time' => '08:00', 'hour' => 8, 'manual' => false, 'type' => 'posts', 'lang' => 'en', 'state' => 'planned', 'title' => '', 'entry' => '', 'error' => ''];
        }
        if ($date === '2026-11-16') {
            $runs[] = ['schedule' => 4, 'name' => 'Books', 'time' => '06:00', 'hour' => 6, 'manual' => false, 'type' => 'books', 'lang' => 'en', 'state' => 'failed', 'title' => '', 'entry' => '', 'error' => 'x'];
        }
        $days[] = ['date' => $date, 'runs' => $runs];
    }
    return $days;
};
$now = (new DateTimeImmutable('2026-11-17 12:00', $zone))->getTimestamp();
$cal = new PublishingCalendar($pdo, $dir, ['posts', 'pages'], ['en', 'el'], $zone, $aiDays, [3 => 'Tips'], $now);

$titles = static fn(array $screen): array => array_merge(...array_map(static fn(array $d): array => array_map(static fn(array $i): string => $d['date'] . ' ' . ($i['time'] ?: '--:--') . ' ' . ($i['title'] ?: $i['name']) . ' ' . $i['source'] . ' ' . ($i['kind'] === 'entry' ? $i['status'] : $i['state']), $d['items']), $screen['days']));

$month = $cal->screen([]);
check('this month by default, Monday to Sunday weeks around it', [$month['view'], $month['period'], $month['days'][0]['date'], end($month['days'])['date'], count($month['days'])], ['month', 'November 2026', '2026-10-26', '2026-12-06', 42]);
check('previous and next month, and today marked', [$month['prev'], $month['next'], $month['current'], array_values(array_filter($month['days'], static fn($d) => $d['today']))[0]['date']], ['2026-10-01', '2026-12-01', true, '2026-11-17']);
check('what the month holds, in order', $titles($month), [
    '2026-11-10 09:15 By-hand people published',
    '2026-11-11 08:00 By-schedule ai published',
    '2026-11-12 18:30 By-claude mcp published',
    '2026-11-14 --:-- Midnight people published',
    '2026-11-15 10:00 Greek people published',
    '2026-11-18 08:00 Tips ai planned',
    '2026-11-20 07:45 Later people scheduled',
]);
check('who wrote it: the person, the schedule\'s name, the MCP connection', array_column(array_filter(array_merge(...array_column($month['days'], 'items')), static fn($i) => $i['kind'] === 'entry' && $i['date'] <= '2026-11-12'), 'by'), ['Maria', 'Tips', 'Claude']);
check('the totals of the month', $month['totals'], ['published' => 5, 'scheduled' => 1, 'ai' => 2, 'people' => 4, 'planned' => 1]);
check('days outside the month are marked', [$month['days'][0]['in_period'], $month['days'][6]['in_period']], [false, true]);

$week = $cal->screen(['view' => 'week', 'date' => '2026-11-19']);
check('a week: Monday to Sunday, previous and next', [$week['period'], $week['prev'], $week['next'], count($week['days'])], ['16 Nov – 22 Nov 2026', '2026-11-09', '2026-11-23', 7]);
check('the week holds the planned run and the scheduled post; a failed run of a type not shown is left out', $titles($week), ['2026-11-18 08:00 Tips ai planned', '2026-11-20 07:45 Later people scheduled']);

check('only what people wrote', $titles($cal->screen(['source' => 'people'])), ['2026-11-10 09:15 By-hand people published', '2026-11-14 --:-- Midnight people published', '2026-11-15 10:00 Greek people published', '2026-11-20 07:45 Later people scheduled']);
check('only AI (schedules and MCP)', $titles($cal->screen(['source' => 'ai'])), ['2026-11-11 08:00 By-schedule ai published', '2026-11-12 18:30 By-claude mcp published', '2026-11-18 08:00 Tips ai planned']);
check('one type', $titles($cal->screen(['type' => 'pages'])), ['2026-11-14 --:-- Midnight people published']);
check('a type this person may not see is ignored', $cal->screen(['type' => 'books'])['filters']['type'], '');
check('one language', count($titles($cal->screen(['lang' => 'el']))), 1);
check('the scheduled post of January shows in January', $titles($cal->screen(['date' => '2027-01-05'])), ['2027-01-20 07:45 Much-later people scheduled']);

$noAi = new PublishingCalendar($pdo, $dir, ['posts', 'pages'], ['en'], $zone, null, [], $now);
check('without AI publishing rights: no runs, the AI-written posts still there', $titles($noAi->screen(['source' => 'ai'])), ['2026-11-11 08:00 By-schedule ai published', '2026-11-12 18:30 By-claude mcp published']);
check('and the screen says so', $noAi->screen([])['with_ai'], false);

array_map('unlink', glob($dir . '/*/*.md') ?: []);
array_map('rmdir', glob($dir . '/*') ?: []);
rmdir($dir);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
