<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * Where the platform's analytics are kept (SQLite, the system database). A visit is a row of analytics_hits for two days: no
 * address, no cookie, and a visitor that is a hash with a salt that is thrown away every day, so it cannot be traced back or
 * matched with tomorrow's. A finished day is summed into analytics_daily (a row for each kind of count and value: the total, each
 * page, each source, ...) and its rows are deleted, so the store stays small however long it is kept. Today is read from its rows.
 */
final class AnalyticsStore
{
    /** The kinds of count a day is summed into, and how many values of each are kept (the rest are added to "(other)"). */
    public const KINDS = ['page' => 300, 'entry' => 100, 'channel' => 20, 'source' => 100, 'campaign' => 100, 'device' => 10, 'browser' => 30, 'os' => 30, 'country' => 100, 'lang' => 50, 'event' => 100];
    /** How many rows a visitor may leave in a day: more is a script, not a person. */
    private const VISITOR_LIMIT = 400;
    /** The longest a visit is taken to last, in seconds (a tab left open is not time spent). */
    private const VISIT_LIMIT = 1800;

    public function __construct(private SystemDatabase $database, private SystemMetaRepository $meta, private int $keepMonths = 24)
    {
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }

    /** The salt of a day: made when the day first asks for it, and the one of yesterday is gone. */
    public function salt(string $day): string
    {
        $stored = (string)$this->meta->get('analytics_salt');
        if (str_starts_with($stored, $day . ':') && strlen($stored) > 20) {
            return substr($stored, strlen($day) + 1);
        }
        $salt = bin2hex(random_bytes(16));
        $this->meta->set('analytics_salt', $day . ':' . $salt);
        return $salt;
    }

    /**
     * Keeps one row: a view or an event. Returns false when the store is not there or the visitor has left too many today.
     *
     * @param array<string, mixed> $row ts, day, visitor, kind, path, name, source, channel, campaign, device, browser, os, country, lang
     */
    public function record(array $row): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $this->maintain((string)$row['day']);
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM analytics_hits WHERE day = :day AND visitor = :visitor');
        $count->execute(['day' => $row['day'], 'visitor' => $row['visitor']]);
        if ((int)$count->fetchColumn() >= self::VISITOR_LIMIT) {
            return false;
        }
        $columns = ['ts', 'day', 'visitor', 'kind', 'path', 'name', 'source', 'channel', 'campaign', 'device', 'browser', 'os', 'country', 'lang'];
        $stmt = $this->pdo()->prepare('INSERT INTO analytics_hits (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
        $stmt->execute(array_intersect_key($row, array_flip($columns)));
        return true;
    }

    /**
     * Once a day (the first time anything asks after midnight): sums the days that are over, drops their rows and the rows of the
     * day before yesterday, and drops the days past the time they are kept.
     */
    public function maintain(string $today, ?int $keepMonths = null): void
    {
        $keepMonths ??= $this->keepMonths;
        if (!$this->isAvailable() || (string)$this->meta->get('analytics_maintained') === $today) {
            return;
        }
        $this->meta->set('analytics_maintained', $today);
        $days = $this->pdo()->query('SELECT DISTINCT day FROM analytics_hits WHERE day < ' . $this->pdo()->quote($today) . ' ORDER BY day LIMIT 60')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($days as $day) {
            $this->rollup((string)$day);
        }
        $keepFrom = date('Y-m-d', strtotime($today . ' -1 day') ?: time());
        $this->pdo()->prepare('DELETE FROM analytics_hits WHERE day < :day')->execute(['day' => $keepFrom]);
        if ($keepMonths > 0) {
            $this->pdo()->prepare('DELETE FROM analytics_daily WHERE day < :day')->execute(['day' => date('Y-m-d', strtotime($today . ' -' . $keepMonths . ' months') ?: time())]);
        }
    }

    /** Sums one day into analytics_daily (again, if it was summed before). */
    public function rollup(string $day): void
    {
        $data = $this->compute($day);
        // A day whose rows are gone is already summed: never replace its counts with nothing.
        if ($data['total']['views'] === 0 && array_filter($data['kinds']) === []) {
            return;
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM analytics_daily WHERE day = :day')->execute(['day' => $day]);
            $insert = $pdo->prepare('INSERT INTO analytics_daily (day, kind, key, views, visitors, bounces, seconds) VALUES (:day, :kind, :key, :views, :visitors, :bounces, :seconds)');
            if ($data['total']['views'] > 0) {
                $insert->execute(['day' => $day, 'kind' => 'total', 'key' => '', 'views' => $data['total']['views'], 'visitors' => $data['total']['visitors'], 'bounces' => $data['total']['bounces'], 'seconds' => $data['total']['seconds']]);
            }
            foreach ($data['kinds'] as $kind => $values) {
                foreach ($this->capped($values, self::KINDS[$kind] ?? 100) as $key => $count) {
                    $insert->execute(['day' => $day, 'kind' => $kind, 'key' => (string)$key, 'views' => $count[0], 'visitors' => $count[1], 'bounces' => 0, 'seconds' => 0]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The counts of a day, read from its rows: the total (views, visitors, how many left after one page, the seconds of the visits that
     * had more) and, for each kind, value => [views, visitors]. Where a visitor came from, and with what, is read from the first row of
     * the visitor that day.
     *
     * @return array{total: array{views: int, visitors: int, bounces: int, seconds: int}, kinds: array<string, array<string, array{0: int, 1: int}>>}
     */
    public function compute(string $day): array
    {
        $total = ['views' => 0, 'visitors' => 0, 'bounces' => 0, 'seconds' => 0];
        $kinds = array_fill_keys(array_keys(self::KINDS), []);
        if (!$this->isAvailable()) {
            return ['total' => $total, 'kinds' => $kinds];
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM analytics_hits WHERE day = :day ORDER BY ts, id');
        $stmt->execute(['day' => $day]);
        $visitors = [];
        $pageSeen = [];
        $add = static function (array &$list, string $key, int $views, bool $newVisitor) {
            $list[$key] ??= [0, 0];
            $list[$key][0] += $views;
            $list[$key][1] += $newVisitor ? 1 : 0;
        };
        foreach ($stmt as $row) {
            $v = (string)$row['visitor'];
            if ($row['kind'] === 'event') {
                $seen = isset($pageSeen['event|' . $v . '|' . $row['name']]);
                $pageSeen['event|' . $v . '|' . $row['name']] = true;
                $add($kinds['event'], (string)$row['name'], 1, !$seen);
                continue;
            }
            $total['views']++;
            $visitors[$v] ??= ['views' => 0, 'first' => (int)$row['ts'], 'last' => (int)$row['ts'], 'row' => $row];
            $visitors[$v]['views']++;
            $visitors[$v]['last'] = (int)$row['ts'];
            $seen = isset($pageSeen['page|' . $v . '|' . $row['path']]);
            $pageSeen['page|' . $v . '|' . $row['path']] = true;
            $add($kinds['page'], (string)$row['path'], 1, !$seen);
        }
        foreach ($visitors as $info) {
            $total['visitors']++;
            if ($info['views'] === 1) {
                $total['bounces']++;
            } else {
                $total['seconds'] += min(self::VISIT_LIMIT, max(0, $info['last'] - $info['first']));
            }
            $first = $info['row'];
            $add($kinds['entry'], (string)$first['path'], 1, true);
            foreach (['channel' => 'channel', 'source' => 'source', 'campaign' => 'campaign', 'device' => 'device', 'browser' => 'browser', 'os' => 'os', 'country' => 'country', 'lang' => 'lang'] as $kind => $column) {
                $value = (string)$first[$column];
                if ($value !== '') {
                    $add($kinds[$kind], $value, $info['views'], true);
                }
            }
        }
        return ['total' => $total, 'kinds' => $kinds];
    }

    /**
     * The values of a kind, the most seen first, cut to a number with the rest added up.
     *
     * @param array<string, array{0: int, 1: int}> $values
     * @return array<string, array{0: int, 1: int}>
     */
    private function capped(array $values, int $limit): array
    {
        uasort($values, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        if (count($values) <= $limit) {
            return $values;
        }
        $kept = array_slice($values, 0, $limit - 1, true);
        $rest = [0, 0];
        foreach (array_slice($values, $limit - 1, null, true) as $count) {
            $rest[0] += $count[0];
            $rest[1] += $count[1];
        }
        $kept['(other)'] = $rest;
        return $kept;
    }

    /**
     * The counts of a stretch of days (both ends included), with today read from its rows.
     *
     * @return array{days: array<string, array{views: int, visitors: int, bounces: int, seconds: int}>, kinds: array<string, array<string, array{0: int, 1: int}>>}
     */
    public function range(string $from, string $to, string $today): array
    {
        $days = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $days[$d] = ['views' => 0, 'visitors' => 0, 'bounces' => 0, 'seconds' => 0];
        }
        $kinds = array_fill_keys(array_keys(self::KINDS), []);
        if (!$this->isAvailable() || $days === []) {
            return ['days' => $days, 'kinds' => $kinds];
        }
        $this->maintain($today);
        $stmt = $this->pdo()->prepare("SELECT day, views, visitors, bounces, seconds FROM analytics_daily WHERE kind = 'total' AND day BETWEEN :from AND :to");
        $stmt->execute(['from' => $from, 'to' => $to]);
        foreach ($stmt as $row) {
            $days[$row['day']] = ['views' => (int)$row['views'], 'visitors' => (int)$row['visitors'], 'bounces' => (int)$row['bounces'], 'seconds' => (int)$row['seconds']];
        }
        $stmt = $this->pdo()->prepare("SELECT kind, key, SUM(views) AS views, SUM(visitors) AS visitors FROM analytics_daily WHERE kind != 'total' AND day BETWEEN :from AND :to GROUP BY kind, key");
        $stmt->execute(['from' => $from, 'to' => $to]);
        foreach ($stmt as $row) {
            $kinds[$row['kind']][(string)$row['key']] = [(int)$row['views'], (int)$row['visitors']];
        }
        // The days that are not summed yet (today, and any day nobody asked after) are read from their rows.
        foreach (array_keys($days) as $day) {
            if ($day >= $today || ($days[$day]['views'] === 0 && $this->hasRows($day))) {
                $live = $this->compute($day);
                $days[$day] = $live['total'];
                foreach ($live['kinds'] as $kind => $values) {
                    foreach ($values as $key => $count) {
                        $kinds[$kind][$key] ??= [0, 0];
                        $kinds[$kind][$key][0] += $count[0];
                        $kinds[$kind][$key][1] += $count[1];
                    }
                }
            }
        }
        foreach ($kinds as $kind => $values) {
            uasort($values, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
            $kinds[$kind] = $values;
        }
        return ['days' => $days, 'kinds' => $kinds];
    }

    private function hasRows(string $day): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM analytics_hits WHERE day = :day LIMIT 1');
        $stmt->execute(['day' => $day]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * The views and visitors of each hour of a day that still has its rows (today, yesterday).
     *
     * @return array<int, array{views: int, visitors: int}> hour 0..23
     */
    public function hours(string $day): array
    {
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[$h] = ['views' => 0, 'visitors' => 0];
        }
        if (!$this->isAvailable()) {
            return $out;
        }
        $stmt = $this->pdo()->prepare("SELECT visitor, ts FROM analytics_hits WHERE day = :day AND kind = 'view'");
        $stmt->execute(['day' => $day]);
        $seen = [];
        foreach ($stmt as $row) {
            $h = (int)date('G', (int)$row['ts']);
            $out[$h]['views']++;
            if (!isset($seen[$h . '|' . $row['visitor']])) {
                $seen[$h . '|' . $row['visitor']] = true;
                $out[$h]['visitors']++;
            }
        }
        return $out;
    }

    /**
     * Who is on the site now: the visitors with a view in the last minutes, and the pages they are on.
     *
     * @return array{visitors: int, pages: array<string, int>}
     */
    public function now(int $minutes = 5): array
    {
        if (!$this->isAvailable()) {
            return ['visitors' => 0, 'pages' => []];
        }
        $stmt = $this->pdo()->prepare("SELECT visitor, path, MAX(ts) AS ts FROM analytics_hits WHERE kind = 'view' AND ts >= :since GROUP BY visitor ORDER BY ts DESC");
        $stmt->execute(['since' => time() - $minutes * 60]);
        $pages = [];
        $visitors = 0;
        foreach ($stmt as $row) {
            $visitors++;
            $pages[(string)$row['path']] = ($pages[(string)$row['path']] ?? 0) + 1;
        }
        arsort($pages);
        return ['visitors' => $visitors, 'pages' => array_slice($pages, 0, 8, true)];
    }

    /** @return array{hits: int, days: int, first: string} */
    public function stats(): array
    {
        if (!$this->isAvailable()) {
            return ['hits' => 0, 'days' => 0, 'first' => ''];
        }
        $row = $this->pdo()->query("SELECT COUNT(DISTINCT day) AS days, MIN(day) AS first FROM analytics_daily WHERE kind = 'total'")->fetch() ?: [];
        return ['hits' => (int)$this->pdo()->query('SELECT COUNT(*) FROM analytics_hits')->fetchColumn(), 'days' => (int)($row['days'] ?? 0), 'first' => (string)($row['first'] ?? '')];
    }

    /** Deletes everything counted. */
    public function clear(): void
    {
        if ($this->isAvailable()) {
            $this->pdo()->exec('DELETE FROM analytics_hits');
            $this->pdo()->exec('DELETE FROM analytics_daily');
            $this->meta->set('analytics_maintained', '');
        }
    }
}
