<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Analytics reports show for a stretch of days: the totals with their change from the stretch before, the chart (an hour
 * at a time for one day, a day at a time up to three months, a month at a time after that), and a list for each kind of count (pages,
 * where visits came from, devices, countries, goals) with the words people read instead of the codes the store keeps. It reads
 * the store and draws nothing: the template draws what this gives.
 */
final class AnalyticsReport
{
    public const RANGES = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '12m' => '12 months'];
    public const MORE = ['month' => 'This month', 'last_month' => 'Last month'];
    private const CHANNELS = ['direct' => 'Direct', 'search' => 'Search engines', 'social' => 'Social networks', 'referral' => 'Other websites', 'paid' => 'Paid', 'email' => 'Email', 'campaign' => 'Campaigns'];
    private const DEVICES = ['desktop' => 'Computer', 'mobile' => 'Phone', 'tablet' => 'Tablet'];
    /** The lists of a card: kind => what it is called. */
    public const CARDS = [
        'Pages' => ['page' => 'Top pages', 'entry' => 'Entry pages'],
        'Sources' => ['channel' => 'Channels', 'source' => 'Sources', 'campaign' => 'Campaigns'],
        'Devices' => ['device' => 'Devices', 'browser' => 'Browsers', 'os' => 'Systems'],
        'Locations' => ['country' => 'Countries', 'lang' => 'Languages'],
        'Goals' => ['event' => 'Goals'],
    ];

    public function __construct(private AnalyticsStore $store)
    {
    }

    /**
     * The stretch of days a request asks for. A custom one needs two real dates, the first not after the second, none in the future and
     * at most five years; anything else is the last 30 days.
     *
     * @return array{key: string, label: string, from: string, to: string, days: int}
     */
    public static function span(string $range, string $from, string $to, string $today): array
    {
        $day = static fn(string $base, string $change): string => date('Y-m-d', strtotime($base . ' ' . $change) ?: time());
        $custom = $range === 'custom' ? self::custom($from, $to, $today) : null;
        if ($range === 'custom' && $custom === null) {
            $range = '30d';
        }
        $span = match ($range) {
            'today' => [$today, $today],
            'yesterday' => [$day($today, '-1 day'), $day($today, '-1 day')],
            '7d' => [$day($today, '-6 days'), $today],
            '90d' => [$day($today, '-89 days'), $today],
            '12m' => [$day($today, '-1 year +1 day'), $today],
            'month' => [date('Y-m-01', strtotime($today)), $today],
            'last_month' => [date('Y-m-01', strtotime($today . ' first day of last month')), date('Y-m-t', strtotime($today . ' first day of last month'))],
            'custom' => $custom,
            default => [$day($today, '-29 days'), $today],
        };
        $key = array_key_exists($range, self::RANGES) || array_key_exists($range, self::MORE) || $range === 'custom' ? $range : '30d';
        $days = (int)round((strtotime($span[1]) - strtotime($span[0])) / 86400) + 1;
        $label = self::RANGES[$key] ?? self::MORE[$key] ?? ($span[0] === $span[1] ? $span[0] : $span[0] . ' – ' . $span[1]);
        return ['key' => $key, 'label' => $label, 'from' => $span[0], 'to' => $span[1], 'days' => $days];
    }

    /** @return array{0: string, 1: string}|null the two days, or null when they are not a stretch the reports can show */
    private static function custom(string $from, string $to, string $today): ?array
    {
        $valid = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && date('Y-m-d', strtotime($d) ?: 0) === $d;
        if ($valid($from) && $valid($to) && $from <= $to && $to <= $today && strtotime($to) - strtotime($from) <= 5 * 366 * 86400) {
            return [$from, $to];
        }
        return null;
    }

    /**
     * Everything the reports show.
     *
     * @param array{key: string, label: string, from: string, to: string, days: int} $span
     * @return array<string, mixed>
     */
    public function build(array $span, string $today, int $top = 8): array
    {
        $data = $this->store->range($span['from'], $span['to'], $today);
        $beforeTo = date('Y-m-d', strtotime($span['from'] . ' -1 day'));
        $beforeFrom = date('Y-m-d', strtotime($beforeTo . ' -' . ($span['days'] - 1) . ' days'));
        $before = $this->store->range($beforeFrom, $beforeTo, $today);
        $now = self::totals($data['days']);
        $prev = self::totals($before['days']);
        $kpis = [];
        foreach ([
            ['visitors', 'Visitors', 'number', 'A visitor is counted once a day.', false],
            ['views', 'Page views', 'number', '', false],
            ['pages', 'Pages per visit', 'decimal', '', false],
            ['bounce', 'Bounce rate', 'percent', 'Visits that ended after one page.', true],
            ['duration', 'Visit time', 'duration', 'Of the visits with more than one page.', false],
        ] as [$key, $label, $format, $help, $lowerIsBetter]) {
            $kpis[] = [
                'key' => $key, 'label' => $label, 'help' => $help,
                'value' => self::format($now[$key], $format),
                'change' => self::change($now[$key], $prev[$key], $prev['visitors'] > 0),
                'good' => self::change($now[$key], $prev[$key], $prev['visitors'] > 0) === null ? null : (($now[$key] <=> $prev[$key]) * ($lowerIsBetter ? -1 : 1)) >= 0,
            ];
        }
        $lists = [];
        foreach (AnalyticsStore::KINDS as $kind => $limit) {
            $rows = $data['kinds'][$kind] ?? [];
            $max = $rows === [] ? 1 : max(1, max(array_column($rows, 0)));
            $list = [];
            foreach ($rows as $key => $count) {
                $list[] = ['key' => (string)$key, 'label' => self::label($kind, (string)$key), 'views' => $count[0], 'visitors' => $count[1], 'pct' => (int)round($count[0] / $max * 100)];
            }
            $lists[$kind] = ['rows' => array_slice($list, 0, $top), 'total' => count($list)];
        }
        return [
            'span' => $span,
            'kpis' => $kpis,
            'empty' => $now['views'] === 0,
            'previous' => ['from' => $beforeFrom, 'to' => $beforeTo, 'views' => $prev['views']],
            'chart' => $this->chart($span, $data['days'], $today),
            'lists' => $lists,
        ];
    }

    /**
     * Totals of days: visitors (summed from the days, so a person who comes on two days is two), views, pages per visit, the share of visits
     * that ended after one page, and the average time of the others, in seconds.
     *
     * @param array<string, array{views: int, visitors: int, bounces: int, seconds: int}> $days
     * @return array{visitors: int, views: int, pages: float, bounce: float, duration: float}
     */
    private static function totals(array $days): array
    {
        $views = array_sum(array_column($days, 'views'));
        $visitors = array_sum(array_column($days, 'visitors'));
        $bounces = array_sum(array_column($days, 'bounces'));
        $seconds = array_sum(array_column($days, 'seconds'));
        return [
            'visitors' => $visitors,
            'views' => $views,
            'pages' => $visitors > 0 ? $views / $visitors : 0.0,
            'bounce' => $visitors > 0 ? $bounces / $visitors * 100 : 0.0,
            'duration' => $visitors - $bounces > 0 ? $seconds / ($visitors - $bounces) : 0.0,
        ];
    }

    private static function format(float|int $value, string $format): string
    {
        return match ($format) {
            'decimal' => number_format((float)$value, 1),
            'percent' => (string)round((float)$value) . '%',
            'duration' => self::duration((int)round($value)),
            default => number_format((int)$value),
        };
    }

    /** "2m 05s", "45s", "0s". */
    public static function duration(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60) . 'm ' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . 's' : $seconds . 's';
    }

    /** The change from the stretch before in percent, or null when there is nothing to compare with. */
    private static function change(float|int $now, float|int $before, bool $hasBefore): ?int
    {
        if (!$hasBefore || $before == 0) {
            return null;
        }
        return (int)round(($now - $before) / $before * 100);
    }

    /** The words for a value of a kind (a country by its name, a goal by what happened, a channel by what it is). */
    public static function label(string $kind, string $key): string
    {
        if ($key === '(other)') {
            return 'Other';
        }
        switch ($kind) {
            case 'channel':
                return self::CHANNELS[$key] ?? ucfirst($key);
            case 'device':
                return self::DEVICES[$key] ?? ucfirst($key);
            case 'country':
                $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $key, 'en') : '';
                $flag = strlen($key) === 2 ? mb_chr(0x1F1E6 + ord($key[0]) - 65) . mb_chr(0x1F1E6 + ord($key[1]) - 65) : '';
                return trim($flag . ' ' . ($name !== '' && $name !== $key ? $name : $key));
            case 'lang':
                $name = class_exists(\Locale::class) ? \Locale::getDisplayLanguage($key, 'en') : '';
                return $name !== '' && $name !== $key ? ucfirst($name) : strtoupper($key);
            case 'event':
                [$type, $value] = array_pad(explode('|', $key, 2), 2, '');
                $what = AnalyticsCollector::EVENT_TYPES[$type] ?? ucfirst($type);
                return $value !== '' ? $what . ': ' . $value : $what;
            default:
                return $key;
        }
    }

    /**
     * The chart: one point for each hour of a day, each day up to three months (92 days), each month after that, with the lines, the
     * areas under them, a hover area for each point, the marks of the vertical axis and a few labels of the horizontal one.
     *
     * @param array<string, array{views: int, visitors: int, bounces: int, seconds: int}> $days
     * @return array<string, mixed>
     */
    private function chart(array $span, array $days, string $today): array
    {
        $points = [];
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $hourly = $span['from'] === $span['to'] && ($span['to'] === $today || $span['to'] === $yesterday);
        if ($hourly) {
            foreach ($this->store->hours($span['to']) as $h => $count) {
                $points[] = ['label' => sprintf('%02d:00', $h), 'short' => (string)$h, 'views' => $count['views'], 'visitors' => $count['visitors']];
            }
        } elseif ($span['days'] <= 92) {
            foreach ($days as $day => $count) {
                $points[] = ['label' => date('D j M Y', strtotime($day)), 'short' => date('j M', strtotime($day)), 'views' => $count['views'], 'visitors' => $count['visitors']];
            }
        } else {
            $months = [];
            foreach ($days as $day => $count) {
                $m = substr($day, 0, 7);
                $months[$m] ??= ['views' => 0, 'visitors' => 0];
                $months[$m]['views'] += $count['views'];
                $months[$m]['visitors'] += $count['visitors'];
            }
            foreach ($months as $m => $count) {
                $points[] = ['label' => date('F Y', strtotime($m . '-01')), 'short' => date('M y', strtotime($m . '-01')), 'views' => $count['views'], 'visitors' => $count['visitors']];
            }
        }
        $w = 960.0;
        $h = 230.0;
        $left = 34.0;
        $right = 6.0;
        $top = 8.0;
        $bottom = 22.0;
        $max = max(1, ...array_map(static fn(array $p): int => $p['views'], $points));
        $step = 10 ** floor(log10($max));
        $niceMax = (float)(ceil($max / $step) <= 2 ? 2 * $step : (ceil($max / $step) <= 5 ? 5 * $step : 10 * $step));
        $niceMax = max($niceMax, (float)$max);
        $n = count($points);
        $plotW = $w - $left - $right;
        $plotH = $h - $top - $bottom;
        $x = static fn(int $i): float => $left + ($n > 1 ? $plotW * $i / ($n - 1) : $plotW / 2);
        $y = static fn(int $value): float => $top + $plotH * (1 - $value / $niceMax);
        $line = static function (string $key) use ($points, $x, $y): string {
            $d = '';
            foreach ($points as $i => $p) {
                $d .= ($i === 0 ? 'M' : 'L') . round($x($i), 1) . ' ' . round($y($p[$key]), 1);
            }
            return $d;
        };
        $area = static function (string $key) use ($points, $x, $y, $n, $top, $plotH): string {
            $base = round($top + $plotH, 1);
            $d = 'M' . round($x(0), 1) . ' ' . $base;
            foreach ($points as $i => $p) {
                $d .= 'L' . round($x($i), 1) . ' ' . round($y($p[$key]), 1);
            }
            return $d . 'L' . round($x($n - 1), 1) . ' ' . $base . 'Z';
        };
        $slot = $plotW / max(1, $n);
        $hover = [];
        foreach ($points as $i => $p) {
            $hover[] = ['x' => round($x($i) - $slot / 2, 1), 'w' => round($slot, 1), 'cx' => round($x($i), 1), 'cy' => round($y($p['visitors']), 1)] + $p;
        }
        $ticks = [];
        foreach ([0, 0.5, 1] as $f) {
            $value = (int)round($niceMax * $f);
            $ticks[] = ['value' => $value, 'y' => round($y($value), 1)];
        }
        $labels = [];
        $every = max(1, (int)ceil($n / 7));
        foreach ($points as $i => $p) {
            if ($i % $every === 0) {
                $labels[] = ['x' => round($x($i), 1), 'text' => $p['short']];
            }
        }
        return [
            'w' => (int)$w, 'h' => (int)$h, 'left' => $left, 'right' => round($w - $right, 1), 'base' => round($top + $plotH, 1),
            'views_line' => $line('views'), 'views_area' => $area('views'), 'visitors_line' => $line('visitors'), 'visitors_area' => $area('visitors'),
            'hover' => $hover, 'ticks' => $ticks, 'labels' => $labels, 'unit' => $hourly ? 'hour' : ($span['days'] <= 92 ? 'day' : 'month'),
        ];
    }

    /**
     * A kind of count as CSV: what it is, the views and the visitors, most seen first.
     *
     * @param array{key: string, label: string, from: string, to: string, days: int} $span
     */
    public function csv(string $kind, array $span, string $today): string
    {
        $data = $this->store->range($span['from'], $span['to'], $today);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, [self::CARDS_ONE[$kind] ?? ucfirst($kind), 'Views', 'Visitors'], ',', '"', '');
        foreach ($data['kinds'][$kind] ?? [] as $key => $count) {
            // A cell that starts like a formula would run in a spreadsheet: it gets a quote in front of it.
            $label = (string)$key;
            fputcsv($out, [preg_match('/^[=+\-@\t\r]/', $label) === 1 ? "'" . $label : $label, $count[0], $count[1]], ',', '"', '');
        }
        rewind($out);
        return (string)stream_get_contents($out);
    }

    private const CARDS_ONE = ['page' => 'Page', 'entry' => 'Entry page', 'channel' => 'Channel', 'source' => 'Source', 'campaign' => 'Campaign', 'device' => 'Device', 'browser' => 'Browser', 'os' => 'System', 'country' => 'Country', 'lang' => 'Language', 'event' => 'Goal'];
}
