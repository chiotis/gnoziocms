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
}
