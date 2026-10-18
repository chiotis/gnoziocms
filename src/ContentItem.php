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
}
