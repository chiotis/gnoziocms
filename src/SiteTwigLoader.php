<?php

declare(strict_types=1);

namespace GnozioCMS;

use Twig\Loader\FilesystemLoader;

/**
 * The templates' loader for the compile cache (SiteTwig): a template's cache key carries its file's time and size, so a compiled
 * copy is used only for the very file it was made from. Twig's own check (auto_reload) asks only whether the file is newer than
 * the copy; a template put back with an older time (a restore from a backup, a copy that keeps times) would keep the newer copy.
 */
final class SiteTwigLoader extends FilesystemLoader
{
    public function getCacheKey(string $name): string
    {
        $key = parent::getCacheKey($name);
        $path = $this->findTemplate($name, false);
        if ($path === null) {
            return $key;
        }
        $stat = @stat($path);
        return $stat === false ? $key : $key . '|' . $stat['mtime'] . '|' . $stat['size'];
    }
}
