<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;
use Symfony\Component\Yaml\Yaml;

/**
 * Overview > Publishing calendar: everything the site has published or will publish, by day, for a month or a week. Entries of
 * every active content type are placed by their date (published) or their publish_at (scheduled); drafts and entries without a
 * date stay off. Who wrote each one comes from its file: AI publishing (`generated_by: AI publishing …`), an MCP connection
 * (any other `generated_by`), or a person. The AI schedules add their runs that have no entry here: planned, due, missed,
 * failed, or a post kept as a draft.
 */
final class PublishingCalendar
{
    public const VIEWS = ['month', 'week'];
    public const SOURCES = ['all', 'people', 'ai'];

    /**
     * @param string[] $types the content types this person may see
     * @param string[] $languages the site's language codes
     * @param (\Closure(\DateTimeImmutable, int): array<int, array{date: string, runs: array<int, array<string, mixed>>}>)|null $aiDays
     *   the AI schedules' runs of a number of days from a day (AiPublishingAdmin::calendarDays), or null to leave them out
     * @param array<int, string> $scheduleNames the AI schedules' names by id
     */
    public function __construct(
        private ?PDO $pdo,
        private string $contentDir,
        private array $types,
        private array $languages,
        private \DateTimeZone $zone,
        private ?\Closure $aiDays = null,
        private array $scheduleNames = [],
        private ?int $now = null,
    ) {
    }

    /**
     * @param array<string, mixed> $get view (month, week), date (Y-m-d, any day of the period), type, source (all, people, ai), lang
     * @return array<string, mixed>
     */
    public function screen(array $get): array
    {
        $view = in_array($get['view'] ?? '', self::VIEWS, true) ? (string)$get['view'] : 'month';
        $source = in_array($get['source'] ?? '', self::SOURCES, true) ? (string)$get['source'] : 'all';
        $type = in_array($get['type'] ?? '', $this->types, true) ? (string)$get['type'] : '';
        $lang = in_array($get['lang'] ?? '', $this->languages, true) ? (string)$get['lang'] : '';
        $now = (new \DateTimeImmutable('@' . ($this->now ?? time())))->setTimezone($this->zone);
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($get['date'] ?? '')) === 1
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', (string)$get['date'], $this->zone) ?: $now)
            : $now;
        $anchor = $anchor->setTime(0, 0);

        if ($view === 'month') {
            $firstOfMonth = $anchor->modify('first day of this month');
            $from = $firstOfMonth->modify('-' . ((int)$firstOfMonth->format('N') - 1) . ' day');
            $lastOfMonth = $anchor->modify('last day of this month');
            $to = $lastOfMonth->modify('+' . (7 - (int)$lastOfMonth->format('N')) . ' day');
            $count = (int)$from->diff($to)->days + 1;
            $title = $anchor->format('F Y');
            $prev = $firstOfMonth->modify('-1 month')->format('Y-m-d');
            $next = $firstOfMonth->modify('+1 month')->format('Y-m-d');
            $current = $anchor->format('Y-m') === $now->format('Y-m');
        } else {
            $from = $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' day');
            $count = 7;
            $to = $from->modify('+6 day');
            $title = $from->format('j M') . ' – ' . $to->format('j M Y');
            $prev = $from->modify('-7 day')->format('Y-m-d');
            $next = $from->modify('+7 day')->format('Y-m-d');
            $current = $now->format('Y-m-d') >= $from->format('Y-m-d') && $now->format('Y-m-d') <= $to->format('Y-m-d');
        }

        $entries = $this->entries($from, $to->modify('+1 day'));
        $shown = [];
        foreach ($entries as $entry) {
            $shown[$entry['key']] = true;
        }
        $runs = [];
        if ($this->aiDays !== null) {
            foreach (($this->aiDays)($from, $count) as $day) {
                foreach ($day['runs'] as $run) {
                    // A run whose post is on the calendar already shows as that post.
                    if ((string)($run['entry'] ?? '') !== '' && isset($shown[(string)$run['entry']])) {
                        continue;
                    }
                    if ((string)($run['type'] ?? '') !== '' && !in_array((string)$run['type'], $this->types, true)) {
                        continue;
                    }
                    $runs[$day['date']][] = ['kind' => 'run', 'source' => 'ai', 'hour' => (int)$run['hour']] + $run;
                }
            }
        }

        $totals = ['published' => 0, 'scheduled' => 0, 'ai' => 0, 'people' => 0, 'planned' => 0];
        $byDay = [];
        foreach ($entries as $entry) {
            $byDay[$entry['date']][] = $entry;
        }
        $days = [];
        $hours = [];
        $today = $now->format('Y-m-d');
        $month = $anchor->format('Y-m');
        for ($i = 0; $i < $count; $i++) {
            $date = $from->modify('+' . $i . ' day');
            $key = $date->format('Y-m-d');
            $items = array_merge($byDay[$key] ?? [], $runs[$key] ?? []);
            $items = array_values(array_filter($items, function (array $item) use ($type, $source, $lang): bool {
                if ($type !== '' && (string)($item['type'] ?? '') !== $type) {
                    return false;
                }
                if ($lang !== '' && (string)($item['lang'] ?? '') !== '' && (string)$item['lang'] !== $lang) {
                    return false;
                }
                return match ($source) {
                    'people' => $item['source'] === 'people',
                    'ai' => $item['source'] !== 'people',
                    default => true,
                };
            }));
            // Untimed entries first (all day), then by time.
            usort($items, static fn(array $a, array $b): int => [$a['time'] !== '', $a['time'], (string)($a['title'] ?? $a['name'] ?? '')]
                <=> [$b['time'] !== '', $b['time'], (string)($b['title'] ?? $b['name'] ?? '')]);
            $inPeriod = $view === 'week' || $date->format('Y-m') === $month;
            foreach ($items as $item) {
                if ($item['time'] !== '') {
                    $hours[] = $item['hour'];
                }
                if (!$inPeriod) {
                    continue;
                }
                if ($item['kind'] === 'entry') {
                    $totals[$item['status'] === ScheduledPublishing::STATUS ? 'scheduled' : 'published']++;
                    $totals[$item['source'] === 'people' ? 'people' : 'ai']++;
                } elseif (in_array($item['state'], ['planned', 'due'], true)) {
                    $totals['planned']++;
                }
            }
            $days[] = [
                'date' => $key,
                'label' => $date->format('D j M'),
                'number' => (int)$date->format('j'),
                'weekday' => (int)$date->format('N'),
                'today' => $key === $today,
                'past' => $key < $today,
                'in_period' => $inPeriod,
                'items' => $items,
            ];
        }

        return [
            'view' => $view,
            'date' => $anchor->format('Y-m-d'),
            'period' => $title,
            'prev' => $prev,
            'next' => $next,
            'current' => $current,
            'days' => $days,
            'hours' => [min(7, ...($hours ?: [7])), max(20, ...($hours ?: [20]))],
            'filters' => ['type' => $type, 'source' => $source, 'lang' => $lang],
            'calendar_types' => $this->types,
            'languages' => $this->languages,
            'totals' => $totals,
            'zone' => $this->zone->getName(),
            'with_ai' => $this->aiDays !== null,
        ];
    }

    /**
     * The published entries dated in [$from, $to) and the scheduled ones that go live then, of the types this person may see.
     * @return array<int, array<string, mixed>>
     */
    public function entries(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($this->pdo === null || $this->types === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($this->types), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT type, slug, lang, title, status, visible, path, sort_date, summary FROM content_index
             WHERE type IN ($marks) AND (status = ? OR (status <> 'draft' AND sort_date >= ? AND sort_date < ?))"
        );
        $stmt->execute([...$this->types, ScheduledPublishing::STATUS, $from->getTimestamp(), $to->getTimestamp()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $front = $this->frontMatter((string)$row['path']);
            $scheduled = (string)$row['status'] === ScheduledPublishing::STATUS;
            if ($scheduled) {
                $at = ScheduledPublishing::moment($front['publish_at'] ?? null, $this->zone);
                if ($at === null || $at < $from || $at >= $to) {
                    continue;
                }
                $time = $at->format('H:i');
            } else {
                // Only entries with a date: a page with none has no day it went out on (its file time is when it was last saved).
                $summary = json_decode((string)$row['summary'], true);
                if (!is_array($summary) || trim((string)($summary['date'] ?? '')) === '') {
                    continue;
                }
                $at = (new \DateTimeImmutable('@' . (int)$row['sort_date']))->setTimezone($this->zone);
                $time = $at->format('H:i') === '00:00' ? '' : $at->format('H:i');
            }
            $by = trim(is_scalar($front['generated_by'] ?? null) ? (string)$front['generated_by'] : '');
            $schedule = is_numeric($front['ai_schedule'] ?? null) ? (int)$front['ai_schedule'] : 0;
            $source = match (true) {
                $schedule > 0 || str_starts_with($by, 'AI publishing') => 'ai',
                $by !== '' => 'mcp',
                default => 'people',
            };
            $author = is_scalar($front['author'] ?? null) ? trim((string)$front['author']) : '';
            $out[] = [
                'kind' => 'entry',
                'key' => $row['type'] . '/' . $row['slug'] . '/' . $row['lang'],
                'type' => (string)$row['type'],
                'slug' => (string)$row['slug'],
                'lang' => (string)$row['lang'],
                'title' => (string)($row['title'] ?: $row['slug']),
                'status' => $scheduled ? ScheduledPublishing::STATUS : 'published',
                'visible' => (int)$row['visible'] === 1,
                'date' => $at->format('Y-m-d'),
                'time' => $time,
                'hour' => (int)$at->format('G'),
                'source' => $source,
                'by' => match ($source) {
                    'ai' => $this->scheduleNames[$schedule] ?? ($by !== '' ? $by : 'AI publishing'),
                    'mcp' => $by,
                    default => $author,
                },
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> the front matter of a content file (path relative to the content folder), or nothing */
    private function frontMatter(string $path): array
    {
        $file = $this->contentDir . '/' . $path;
        $text = is_file($file) ? (string)file_get_contents($file, false, null, 0, 65536) : '';
        if (preg_match('/^---\R(.*?)\R---/s', $text, $m) !== 1) {
            return [];
        }
        try {
            $data = Yaml::parse($m[1]);
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }
}
