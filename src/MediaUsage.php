<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Where each uploaded file is used. Content refers to uploads by address (`/uploads/media/photo.jpg`), in the front
 * matter, in blocks, and in the Markdown text, so this reads every content file once, and the settings texts it is
 * given, and collects the addresses found. One pass answers for every file, and the result is kept for the request.
 *
 * A file is "used" when its address appears anywhere in that text. It does not know about links a visitor typed by
 * hand in a browser, or about files a theme or `custom/` folder points to.
 *
 * Reading every file is the slow part on a large site, so the result is kept in the system database together with a
 * fingerprint of what it was made from (the name, size, and time of change of every content file, and the settings
 * texts). Looking at the fingerprint only asks the file system for those facts, without reading anything; while it is
 * the same the kept result is used, and any edit, addition, or deletion makes it differ, so the answer is never stale.
 */
final class MediaUsage
{
    /** Bumped when what is kept changes shape, so an old copy is not trusted. */
    private const FORMAT = 1;
    private const CACHE_KEY = 'media_usage';
    /** A result larger than this (as JSON) is not kept; it would cost more to load than to make. */
    private const CACHE_MAX_BYTES = 4194304;

    /** @var array<string, list<array{label: string, url: string, kind: string}>>|null uploads-relative path => places */
    private ?array $map = null;

    /**
     * @param string $contentDir the site's content folder
     * @param callable(): list<array{label: string, url: string, kind: string, text: string}> $extraSources settings texts to read too
     * @param string $defaultLang language of files that have no language in their name
     */
    public function __construct(
        private string $contentDir,
        private $extraSources,
        private string $defaultLang = 'en',
        private ?SystemMetaRepository $cache = null
    ) {
    }

    /**
     * The places one file is used, for the file at this path under `public/uploads` (e.g. `media/abc.jpg`).
     *
     * @return list<array{label: string, url: string, kind: string}>
     */
    public function placesFor(string $uploadsPath): array
    {
        return $this->all()[ltrim($uploadsPath, '/')] ?? [];
    }

    /** @return array<string, list<array{label: string, url: string, kind: string}>> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $files = $this->contentFiles();
        $sources = ($this->extraSources)();
        $fingerprint = $this->fingerprint($files, $sources);
        $kept = $this->cache?->getJson(self::CACHE_KEY);
        if (is_array($kept) && ($kept['format'] ?? null) === self::FORMAT && ($kept['fingerprint'] ?? null) === $fingerprint && is_array($kept['map'] ?? null)) {
            return $this->map = $kept['map'];
        }

        $map = $this->scan($files, $sources);
        $payload = ['format' => self::FORMAT, 'fingerprint' => $fingerprint, 'map' => $map];
        if ($this->cache !== null && strlen((string)json_encode($payload)) <= self::CACHE_MAX_BYTES) {
            $this->cache->setJson(self::CACHE_KEY, $payload);
        }
        return $this->map = $map;
    }

    /**
     * What the content and the settings are, without reading them: a hash of every content file's name, size, and time
     * of change, and of the settings texts.
     *
     * @param list<array{path: string, mtime: int, size: int}> $files
     * @param list<array{label: string, url: string, kind: string, text: string}> $sources
     */
    private function fingerprint(array $files, array $sources): string
    {
        $context = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($context, $file['path'] . '|' . $file['mtime'] . '|' . $file['size'] . "\n");
        }
        foreach ($sources as $source) {
            hash_update($context, $source['label'] . '|' . $source['url'] . '|' . md5((string)$source['text']) . "\n");
        }
        return hash_final($context);
    }

    /**
     * @param list<array{path: string, mtime: int, size: int}> $files
     * @param list<array{label: string, url: string, kind: string, text: string}> $sources
     * @return array<string, list<array{label: string, url: string, kind: string}>>
     */
    private function scan(array $files, array $sources): array
    {
        $map = [];
        $note = static function (string $text, array $place) use (&$map): void {
            if (!preg_match_all('#/?uploads/([A-Za-z0-9_\-./%]+)#', $text, $matches)) {
                return;
            }
            foreach (array_unique($matches[1]) as $found) {
                $path = rtrim(rawurldecode($found), '.');
                if ($path === '' || str_contains($path, '..')) {
                    continue;
                }
                $map[$path][] = $place;
            }
        };

        foreach ($files as $file) {
            $text = @file_get_contents($file['path']);
            if (!is_string($text) || !str_contains($text, 'uploads/')) {
                continue;
            }
            $note($text, $this->placeOf($file['path'], $text));
        }
        foreach ($sources as $source) {
            $note((string)$source['text'], ['label' => (string)$source['label'], 'url' => (string)$source['url'], 'kind' => (string)$source['kind']]);
        }
        return $map;
    }

    /** @return list<array{path: string, mtime: int, size: int}> */
    private function contentFiles(): array
    {
        if (!is_dir($this->contentDir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->contentDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            if (!$info->isFile() || !in_array(strtolower($info->getExtension()), ['md', 'yaml', 'yml'], true)) {
                continue;
            }
            $relative = substr($info->getPathname(), strlen(rtrim($this->contentDir, '/')) + 1);
            // The library's own records name the files, they do not use them; user accounts are not content.
            if (str_starts_with($relative, 'media/') || str_starts_with($relative, 'users/')) {
                continue;
            }
            $files[] = ['path' => $info->getPathname(), 'mtime' => $info->getMTime(), 'size' => $info->getSize()];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $files;
    }

    /** @return array{label: string, url: string, kind: string} */
    private function placeOf(string $file, string $text): array
    {
        $relative = substr($file, strlen(rtrim($this->contentDir, '/')) + 1);
        $parts = explode('/', $relative);
        $type = $parts[0];
        $name = (string)end($parts);
        $base = preg_replace('/\.(md|ya?ml)$/i', '', $name) ?? $name;

        if ($type === 'menus') {
            return ['label' => 'Menu: ' . $base, 'url' => '/admin/menus-edit?key=' . rawurlencode($base), 'kind' => 'menu'];
        }
        if ($type === 'taxonomies') {
            return ['label' => 'Taxonomy: ' . $base, 'url' => '/admin/taxonomies?taxonomy=' . rawurlencode($base), 'kind' => 'taxonomy'];
        }

        $lang = $this->defaultLang;
        $slug = $base;
        if (preg_match('/^(.+)\.([a-z]{2,3})$/', $base, $m)) {
            [$slug, $lang] = [$m[1], $m[2]];
        }
        $title = '';
        if (preg_match('/^title:\s*(.+)$/m', $text, $t)) {
            $title = trim($t[1], " \t\r\"'");
        }
        $label = ($title !== '' ? $title : $slug) . ($lang !== $this->defaultLang ? ' (' . strtoupper($lang) . ')' : '');
        return [
            'label' => $label,
            'url' => '/admin/edit?type=' . rawurlencode($type) . '&slug=' . rawurlencode($slug) . '&lang=' . rawurlencode($lang),
            'kind' => $type,
        ];
    }
}
