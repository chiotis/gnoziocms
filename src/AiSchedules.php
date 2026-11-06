<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * The schedules of AI publishing (docs/ai-publishing-plan.md, section 4): each writes one post per run, on chosen days of the week
 * at one time of day, in the site's time zone. Kept in `ai_schedules` (the settings as JSON); every slot that ran is written down
 * once in `ai_schedule_slots`, which is what makes a slot run once however often cron fires, and what the next runs read so as not
 * to repeat a title.
 */
final class AiSchedules
{
    public const STATUS_MODES = ['at_time', 'when_ready', 'draft'];
    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private SystemDatabase $db, private ?\Closure $clock = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->db->isAvailable();
    }

    /** @return array<int, array<string, mixed>> every schedule, in the order they were made */
    public function all(): array
    {
        $pdo = $this->pdo();
        return $pdo === null ? [] : array_map(self::decode(...), $pdo->query('SELECT * FROM ai_schedules ORDER BY id ASC')->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM ai_schedules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::decode($row) : null;
    }

    /**
     * Creates (id 0) or changes a schedule from its form. Returns its id, or the problems that keep it from being saved.
     * @param array<string, mixed> $post @param string[] $types content types that may be written @param string[] $languages
     * @return array{ok: bool, id: int, errors: string[]}
     */
    public function save(int $id, array $post, array $types, array $languages): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return ['ok' => false, 'id' => $id, 'errors' => ['The site database is not available.']];
        }
        $name = trim(mb_substr((string)preg_replace('/\s+/u', ' ', (string)($post['name'] ?? '')), 0, 120));
        $settings = self::normalize($post);
        $errors = [];
        if ($name === '') {
            $errors[] = 'Give the schedule a name.';
        }
        if ($settings['days'] === []) {
            $errors[] = 'Choose at least one day of the week.';
        }
        if ($settings['time'] === '') {
            $errors[] = 'Give a time of day, such as 08:00.';
        }
        if (!in_array($settings['type'], $types, true)) {
            $errors[] = 'Choose a content type.';
        }
        if (!in_array($settings['lang'], $languages, true)) {
            $errors[] = 'Choose a language of the site.';
        }
        if (trim($settings['content']) === '') {
            $errors[] = 'Say what the posts are about (content instructions).';
        }
        if ($settings['start_date'] !== '' && $settings['end_date'] !== '' && $settings['end_date'] < $settings['start_date']) {
            $errors[] = 'The end date comes before the start date.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'id' => $id, 'errors' => $errors];
        }
        $now = $this->now();
        $json = (string)json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($id > 0 && $this->get($id) !== null) {
            $pdo->prepare('UPDATE ai_schedules SET name = ?, settings = ?, updated_at = ? WHERE id = ?')->execute([$name, $json, $now, $id]);
            return ['ok' => true, 'id' => $id, 'errors' => []];
        }
        $pdo->prepare('INSERT INTO ai_schedules (name, enabled, settings, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, ($post['enabled'] ?? '1') === '0' ? 0 : 1, $json, $now, $now]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'errors' => []];
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE ai_schedules SET enabled = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $this->now(), $id]);
        return $stmt->rowCount() === 1;
    }

    /** A copy, switched off, named "… (copy)". Returns its id or 0. */
    public function duplicate(int $id): int
    {
        $schedule = $this->get($id);
        $pdo = $this->pdo();
        if ($schedule === null || $pdo === null) {
            return 0;
        }
        $now = $this->now();
        $pdo->prepare('INSERT INTO ai_schedules (name, enabled, settings, created_at, updated_at) VALUES (?, 0, ?, ?, ?)')
            ->execute([mb_substr($schedule['name'] . ' (copy)', 0, 120), json_encode($schedule['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
        return (int)$pdo->lastInsertId();
    }

    /** Removes a schedule; the posts it made stay. */
    public function delete(int $id): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM ai_schedules WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->prepare('DELETE FROM ai_schedule_slots WHERE schedule_id = ?')->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The slots due now: a schedule's time on one of its days, from `prepare` minutes before it until `$window` minutes after it,
     * within its dates, that has not run. Yesterday and tomorrow are looked at too, for a time near midnight.
     * @return array<int, array{schedule: array<string, mixed>, slot: string, at: int}>
     */
    public function due(\DateTimeZone $zone, int $window): array
    {
        $now = (new \DateTimeImmutable('@' . $this->now()))->setTimezone($zone);
        $due = [];
        foreach ($this->all() as $schedule) {
            if (!$schedule['enabled']) {
                continue;
            }
            foreach ([-1, 0, 1] as $offset) {
                $at = self::slotOn($schedule['settings'], $now->modify(($offset >= 0 ? '+' : '') . $offset . ' day'), $zone);
                if ($at === null) {
                    continue;
                }
                $opens = $at->getTimestamp() - $schedule['settings']['prepare'] * 60;
                if ($now->getTimestamp() >= $opens && $now->getTimestamp() <= $at->getTimestamp() + $window * 60 && !$this->ran($schedule['id'], $at->format('Y-m-d H:i'))) {
                    $due[] = ['schedule' => $schedule, 'slot' => $at->format('Y-m-d H:i'), 'at' => $at->getTimestamp()];
                }
            }
        }
        return $due;
    }

    /** The next time a schedule writes, from now (the slot's time, not the earlier start of writing), or null. */
    public function next(array $schedule, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!$schedule['enabled']) {
            return null;
        }
        $now = (new \DateTimeImmutable('@' . $this->now()))->setTimezone($zone);
        for ($offset = 0; $offset <= 8; $offset++) {
            $at = self::slotOn($schedule['settings'], $now->modify('+' . $offset . ' day'), $zone);
            if ($at !== null && $at->getTimestamp() > $now->getTimestamp() - $schedule['settings']['prepare'] * 60 && !$this->ran($schedule['id'], $at->format('Y-m-d H:i'))) {
                return $at;
            }
        }
        return null;
    }

    /**
     * Writes a slot down as taken; false when it already was (another worker, or an earlier run). This is the once-only guard.
     */
    public function claim(int $scheduleId, string $slot): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return false;
        }
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO ai_schedule_slots (schedule_id, slot, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$scheduleId, $slot, $this->now()]);
        return $stmt->rowCount() === 1;
    }

    public function attachJob(int $scheduleId, string $slot, int $jobId): void
    {
        $this->pdo()?->prepare('UPDATE ai_schedule_slots SET job_id = ? WHERE schedule_id = ? AND slot = ?')->execute([$jobId, $scheduleId, $slot]);
    }

    public function recordPost(int $scheduleId, string $slot, string $entry, string $title): void
    {
        $this->pdo()?->prepare('UPDATE ai_schedule_slots SET entry = ?, title = ? WHERE schedule_id = ? AND slot = ?')->execute([$entry, mb_substr($title, 0, 300), $scheduleId, $slot]);
    }

    /** @return string[] the titles of the schedule's last posts, newest first */
    public function recentTitles(int $scheduleId, int $limit = 30): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $stmt = $pdo->prepare("SELECT title FROM ai_schedule_slots WHERE schedule_id = ? AND title <> '' ORDER BY created_at DESC LIMIT " . max(1, $limit));
        $stmt->execute([$scheduleId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{slot: string, job_id: int, entry: string, title: string, created_at: int}|null the schedule's last run */
    public function lastRun(int $scheduleId): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT slot, job_id, entry, title, created_at FROM ai_schedule_slots WHERE schedule_id = ? ORDER BY created_at DESC, slot DESC LIMIT 1');
        $stmt->execute([$scheduleId]);
        $row = $stmt->fetch();
        return is_array($row) ? ['slot' => (string)$row['slot'], 'job_id' => (int)$row['job_id'], 'entry' => (string)$row['entry'], 'title' => (string)$row['title'], 'created_at' => (int)$row['created_at']] : null;
    }

    public function postsSince(int $scheduleId, int $since): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_schedule_slots WHERE schedule_id = ? AND entry <> '' AND created_at >= ?");
        $stmt->execute([$scheduleId, $since]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * A schedule's settings from its form (or stored), checked and with defaults.
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function normalize(array $in): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['days'] ?? [])), static fn(int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        $time = trim((string)($in['time'] ?? ''));
        $time = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) === 1 ? sprintf('%02d:%02d', (int)$m[1], (int)$m[2]) : '';
        $date = static fn(mixed $v): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) === 1 && \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v)) !== false ? trim($v) : '';
        $terms = [];
        foreach ((array)($in['terms'] ?? []) as $taxonomy => $ids) {
            $taxonomy = Slug::plain((string)$taxonomy);
            $ids = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string)$v), (array)$ids))));
            if ($taxonomy !== '' && $ids !== []) {
                $terms[$taxonomy] = $ids;
            }
        }
        $choose = array_values(array_unique(array_filter(array_map(static fn($v): string => Slug::plain((string)$v), (array)($in['choose'] ?? [])))));
        $text = static fn(mixed $v, int $max): string => mb_substr(trim(str_replace("\r\n", "\n", (string)$v)), 0, $max);
        $flag = static fn(mixed $v, bool $default): bool => $v === null ? $default : in_array($v, [true, 1, '1', 'on', 'true'], true);
        return [
            'days' => $days,
            'time' => $time,
            'start_date' => $date($in['start_date'] ?? ''),
            'end_date' => $date($in['end_date'] ?? ''),
            'prepare' => max(0, min(180, (int)($in['prepare'] ?? 30))),
            'type' => Slug::plain((string)($in['type'] ?? '')),
            'lang' => Slug::plain((string)($in['lang'] ?? '')),
            'terms' => $terms,
            'choose' => $choose,
            'content' => $text($in['content'] ?? '', 4000),
            'text_avoid' => AiSettings::lines($in['text_avoid'] ?? '', 20),
            'image' => $text($in['image'] ?? '', 1000),
            'picture_avoid' => AiSettings::lines($in['picture_avoid'] ?? '', 20),
            'no_picture' => $flag($in['no_picture'] ?? null, false),
            'picture_required' => $flag($in['picture_required'] ?? null, true),
            'status' => in_array($in['status'] ?? null, self::STATUS_MODES, true) ? (string)$in['status'] : 'at_time',
            'byline' => $text($in['byline'] ?? '', 80),
            'model' => AiSettings::modelName($in['model'] ?? ''),
        ];
    }

    /** The slot of a schedule on a day (its time, in the zone), or null when the schedule does not run that day. */
    private static function slotOn(array $settings, \DateTimeImmutable $day, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if ($settings['time'] === '' || !in_array((int)$day->format('N'), $settings['days'], true)) {
            return null;
        }
        $date = $day->format('Y-m-d');
        if (($settings['start_date'] !== '' && $date < $settings['start_date']) || ($settings['end_date'] !== '' && $date > $settings['end_date'])) {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $settings['time'], $zone);
        return $at === false ? null : $at;
    }

    private function ran(int $scheduleId, string $slot): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM ai_schedule_slots WHERE schedule_id = ? AND slot = ?');
        $stmt->execute([$scheduleId, $slot]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function decode(array $row): array
    {
        $settings = json_decode((string)$row['settings'], true);
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'enabled' => (bool)$row['enabled'],
            'settings' => self::normalize(is_array($settings) ? $settings : []),
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    private function pdo(): ?PDO
    {
        return $this->db->isAvailable() ? $this->db->connection() : null;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
