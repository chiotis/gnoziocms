<?php

declare(strict_types=1);

namespace GnozioCMS;

final class ContentItem
{
    public string $type;
    public string $slug;
    public string $lang;
    public array $meta;
    public string $markdown;
    public string $html;
    public string $filePath;
    public int $mtime;

    public function __construct(
        string $type,
        string $slug,
        string $lang,
        array $meta,
        string $markdown,
        string $html,
        string $filePath,
        int $mtime
    ) {
        $this->type = $type;
        $this->slug = $slug;
        $this->lang = $lang;
        $this->meta = $meta;
        $this->markdown = $markdown;
        $this->html = $html;
        $this->filePath = $filePath;
        $this->mtime = $mtime;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /** When the entry was made, as a Unix time: its `created`, or for one made before entries had it, its date or else its file's time. */
    public function createdTime(): int
    {
        foreach (['created', 'date'] as $key) {
            $value = $this->meta[$key] ?? null;
            $time = is_int($value) ? $value : (is_string($value) && trim($value) !== '' ? strtotime($value) : false);
            if ($time !== false) {
                return $time;
            }
        }
        return $this->mtime;
    }

    /**
     * The moment lists put the entry at, newest first: its `date`, or when the file changed when there is none. A date
     * without a time of day (the editor keeps days only) takes the time of day the entry was made (`created`), so the
     * entries of one day come in the order they were made rather than tied.
     */
    public function sortTime(): int
    {
        $value = $this->meta['date'] ?? null;
        $date = is_int($value) ? $value : (is_string($value) && trim($value) !== '' ? strtotime($value) : false);
        if ($date === false) {
            return $this->mtime;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            $created = $this->meta['created'] ?? null;
            $made = is_int($created) ? $created : (is_string($created) && trim($created) !== '' ? strtotime($created) : false);
            if ($made !== false) {
                $midnight = strtotime(trim($value) . ' 00:00');
                $sinceMidnight = $made - (int)strtotime(date('Y-m-d', $made) . ' 00:00');
                return (int)$midnight + max(0, min(86399, $sinceMidnight));
            }
        }
        return $date;
    }

    /** When a scheduled entry goes live, as a Unix time, or null when it is not scheduled or has no time. */
    public function publishAtTime(): ?int
    {
        if (($this->meta['status'] ?? '') !== 'scheduled') {
            return null;
        }
        return ScheduledPublishing::moment($this->meta['publish_at'] ?? null, new \DateTimeZone(date_default_timezone_get()))?->getTimestamp();
    }
}
