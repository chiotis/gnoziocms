<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Entries kept as `status: scheduled` with a `publish_at` time (Y-m-d H:i in the site's time zone). Until then they are seen
 * like drafts; once the time has passed, release() rewrites them as published, with `date` set to that day, and the caches
 * are emptied. The file always says what visitors see, so lists, feeds and the sitemap need not know about times.
 */
final class ScheduledPublishing
{
    public const STATUS = 'scheduled';
    public const ACTOR = 'Schedule';
    public const FORMAT = 'Y-m-d H:i';

    /** @param \Closure(array<int, array{type: string, slug: string, lang: string, title: string}>): void $afterRelease */
    public function __construct(
        private ContentRepository $content,
        private ContentIndex $index,
        private ?RevisionRepository $revisions,
        private \DateTimeZone $zone,
        private \Closure $afterRelease,
    ) {
    }

    /**
     * A publishing time as the site keeps it, or null when the value is not a time. A text with an offset (ISO 8601) is turned
     * into the site's zone; one without is read in it. An unquoted YAML time arrives as a number and is UTC, as YAML says.
     */
    public static function normalize(mixed $value, \DateTimeZone $zone): ?string
    {
        return self::moment($value, $zone)?->format(self::FORMAT);
    }

    public static function moment(mixed $value, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (is_int($value)) {
            return (new \DateTimeImmutable('@' . $value))->setTimezone($zone);
        }
        $text = is_string($value) ? trim($value) : '';
        // Only a date with a time of day: words such as "tomorrow" are not a time someone chose.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $text)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($text, $zone))->setTimezone($zone);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Publishes the scheduled entries whose time has come. An entry whose file no longer says scheduled, or has no time, is
     * left as it is.
     *
     * @return array<int, array{type: string, slug: string, lang: string, title: string}> what was published
     */
    public function release(?\DateTimeImmutable $now = null): array
    {
        $pdo = $this->index->connection();
        if ($pdo === null) {
            return [];
        }
        $now ??= new \DateTimeImmutable('now', $this->zone);
        $stmt = $pdo->prepare('SELECT type, slug, lang, path FROM content_index WHERE status = :status');
        $stmt->execute(['status' => self::STATUS]);
        $released = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $done = $this->releaseOne((string)$row['type'], (string)$row['slug'], (string)$row['lang'], $this->content->directory() . '/' . (string)$row['path'], $now);
            if ($done !== null) {
                $released[] = $done;
            }
        }
        if ($released !== []) {
            ($this->afterRelease)($released);
        }
        return $released;
    }

    /** @return array{type: string, slug: string, lang: string, title: string}|null */
    private function releaseOne(string $type, string $slug, string $lang, string $path, \DateTimeImmutable $now): ?array
    {
        $raw = is_file($path) ? (string)@file_get_contents($path) : '';
        if ($raw === '') {
            return null;
        }
        [$frontmatter, $body] = FrontMatter::split($raw);
        try {
            $meta = $frontmatter !== '' ? Yaml::parse($frontmatter) : [];
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($meta) || (string)($meta['status'] ?? '') !== self::STATUS) {
            return null;
        }
        $at = self::moment($meta['publish_at'] ?? null, $this->zone);
        if ($at === null || $at > $now) {
            return null;
        }
        $meta['status'] = 'published';
        $meta['date'] = $at->format('Y-m-d');
        unset($meta['publish_at']);
        // Same layout the editor writes: front matter, one blank line, body.
        $body = preg_replace('/\A\R/', '', $body) ?? $body;
        $updated = "---\n" . trim(Yaml::dump($meta, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . rtrim($body) . "\n";
        $this->revisions?->baseline($type, $slug, $lang, $path, self::ACTOR);
        if (@file_put_contents($path, $updated) === false) {
            return null;
        }
        try {
            $this->index->upsert($this->content->parseFile($type, $path));
        } catch (\Throwable) {
            // The index is rebuildable; a failed write only makes it stale.
        }
        $this->revisions?->capture($type, $slug, $lang, $updated, 'schedule', self::ACTOR);
        return ['type' => $type, 'slug' => $slug, 'lang' => $lang, 'title' => (string)($meta['title'] ?? $slug)];
    }
}
