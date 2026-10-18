<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Adds the demo site of starter/ to a site that already has content (Admin > System > Demo content): pages, posts, projects,
 * books, forms, taxonomies, and the pictures they use. Only what is missing is copied: a file that exists is never replaced,
 * so nothing the site has written, renamed or deleted-and-recreated is touched. (A new site starts from the same demo with
 * `php scripts/use-starter.php`, which refuses to run on a site that has content.)
 *
 * What is offered comes from the folders of starter/content (a group each) and starter/uploads (the pictures and files). The
 * menus are left out: a site's menus are its own, and the demo's links would point at pages it may not want.
 */
final class StarterContent
{
    /** The groups, in the order they are offered, with their words. */
    private const GROUPS = [
        'pages' => 'Pages',
        'posts' => 'Posts',
        'projects' => 'Projects',
        'books' => 'Books',
        'forms' => 'Forms',
        'taxonomies' => 'Taxonomies',
        'media' => 'Pictures and files',
    ];

    public function __construct(private string $basePath, private string $contentDir, private string $uploadsDir)
    {
    }

    /** Whether this copy of the code has a demo site to offer. */
    public function available(): bool
    {
        return is_dir($this->basePath . '/starter/content');
    }

    /**
     * What each group would add now.
     *
     * @return array<string, array{label: string, new: int, total: int}>
     */
    public function groups(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => $label) {
            $files = $this->files($key);
            $new = count(array_filter($files, fn(array $file): bool => !is_file($file['to'])));
            if ($files !== []) {
                $out[$key] = ['label' => $label, 'new' => $new, 'total' => count($files)];
            }
        }
        return $out;
    }

    /**
     * Copies what is missing of the chosen groups.
     *
     * @param string[] $chosen group keys
     * @return array{added: int, groups: array<string, int>, failed: int}
     */
    public function add(array $chosen): array
    {
        $result = ['added' => 0, 'groups' => [], 'failed' => 0];
        foreach (self::GROUPS as $key => $label) {
            if (!in_array($key, $chosen, true)) {
                continue;
            }
            $count = 0;
            foreach ($this->files($key) as $file) {
                // Checked again here: a file that appeared since the screen was drawn is not replaced either.
                if (is_file($file['to'])) {
                    continue;
                }
                $dir = dirname($file['to']);
                if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || !@copy($file['from'], $file['to'])) {
                    $result['failed']++;
                    continue;
                }
                $count++;
            }
            if ($count > 0) {
                $result['groups'][$key] = $count;
                $result['added'] += $count;
            }
        }
        return $result;
    }

    /**
     * The files of a group: where each comes from and where it goes. Only files the demo holds, never a path from outside.
     *
     * @return array<int, array{from: string, to: string}>
     */
    private function files(string $group): array
    {
        $files = [];
        if ($group === 'media') {
            // The description of each picture (content/media) and the pictures and files themselves (public/uploads).
            $files = array_merge(
                $this->tree($this->basePath . '/starter/content/media', $this->contentDir . '/media'),
                $this->tree($this->basePath . '/starter/uploads', $this->uploadsDir)
            );
        } elseif (isset(self::GROUPS[$group])) {
            $files = $this->tree($this->basePath . '/starter/content/' . $group, $this->contentDir . '/' . $group);
        }
        return $files;
    }

    /** @return array<int, array{from: string, to: string}> */
    private function tree(string $from, string $to): array
    {
        if (!is_dir($from)) {
            return [];
        }
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($items as $item) {
            if (!$item->isFile() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            $files[] = ['from' => $item->getPathname(), 'to' => $to . '/' . substr($item->getPathname(), strlen($from) + 1)];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['from'], $b['from']));
        return $files;
    }
}
