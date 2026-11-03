<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Serves theme and custom/ assets, which live outside the public document root:
 *   /_themes/<theme>/blocks/<type>/<file>  → themes/<theme>/blocks/<type>/<file>
 *   /_themes/<theme>/<path>                → themes/<theme>/assets/<path>
 *   /_custom/blocks/<type>/<file>          → custom/blocks/<type>/<file>
 *   /_custom/<path>                        → custom/assets/<path>
 *
 * Runs from public/index.php before the application boots, so a stylesheet request costs no
 * session, database, or Twig work. Only allowlisted file types are served.
 *
 * Where public/ can be written, publish() also copies these files to the same addresses under
 * public/, so the web server serves them itself. A server whose static-file rule answers 404 for a
 * missing .css or .js without asking index.php (CloudPanel's nginx) then works with no change.
 */
final class ThemeAssets
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
    ];

    public static function contentType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::TYPES[$extension] ?? null;
    }

    /** The file that records what was last published into a folder. */
    private const STAMP = '.published';
    /** Block bundles inside public/_themes/<theme>/, kept apart from the mirrored files. */
    public const BUNDLES = '_blocks';

    /**
     * Copies the theme's and the site's files into public/_themes/<theme>/ and public/_custom/
     * when they changed, once per request. A file that is gone is removed from the copy, and the
     * theme's block bundles are dropped with any change of the theme. SVG files of custom/ are
     * left to index.php, which serves them with a restrictive CSP. False when public/ cannot be
     * written; the addresses are then answered by handle().
     */
    public static function publish(string $basePath, string $theme): bool
    {
        static $done = [];
        $public = $basePath . '/public';
        $key = $public . "\0" . $theme;
        if (!isset($done[$key])) {
            $done[$key] = is_dir($public) && is_writable($public)
                && self::mirror(['' => $basePath . '/themes/' . $theme . '/assets', 'blocks/' => $basePath . '/themes/' . $theme . '/blocks'], $public . '/_themes/' . $theme, true)
                && self::mirror(['' => $basePath . '/custom/assets', 'blocks/' => $basePath . '/custom/blocks'], $public . '/_custom', false);
        }
        return $done[$key];
    }

    /**
     * Writes a block bundle as public/_themes/<theme>/_blocks/<hash>.<kind> (see publish()) and
     * returns its path below the theme's address, or null when it cannot be written.
     *
     * @param string[] $files
     */
    public static function writeBundle(string $basePath, string $theme, array $files, string $kind, string $hash): ?string
    {
        if (!preg_match('/^[a-f0-9]{8,40}$/', $hash) || !in_array($kind, ['css', 'js'], true) || !self::publish($basePath, $theme)) {
            return null;
        }
        $relative = self::BUNDLES . '/' . $hash . '.' . $kind;
        $target = $basePath . '/public/_themes/' . $theme . '/' . $relative;
        if (is_file($target)) {
            return $relative;
        }
        return self::writeFile($target, self::bundle($files, $kind)) ? $relative : null;
    }

    /** @param string[] $files */
    public static function bundle(array $files, string $kind): string
    {
        $out = '';
        foreach ($files as $file) {
            // Each script is its own statement list; a newline plus ';' keeps concatenation safe.
            $out .= '/* ' . basename(dirname($file)) . ' */' . "\n" . (string)file_get_contents($file) . "\n" . ($kind === 'js' ? ";\n" : '');
        }
        return $out;
    }

    /** @param array<string, string> $roots address prefix => source folder */
    private static function mirror(array $roots, string $target, bool $theme): bool
    {
        $files = [];
        foreach ($roots as $prefix => $root) {
            if (!is_dir($root)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }
                $relative = $prefix . str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                if (!Theme::isSafeRelativePath($relative) || self::contentType($relative) === null || str_starts_with($relative, self::BUNDLES . '/')
                    || (!$theme && strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'svg')) {
                    continue;
                }
                $files[$relative] = [$item->getPathname(), (int)$item->getMTime(), (int)$item->getSize()];
            }
        }
        ksort($files);
        $stamp = sha1(json_encode(array_map(static fn(array $file): array => [$file[1], $file[2]], $files)) ?: '');
        if (@file_get_contents($target . '/' . self::STAMP) === $stamp) {
            return true;
        }
        foreach ($files as $relative => [$from, $mtime, $size]) {
            $to = $target . '/' . $relative;
            clearstatcache(true, $to);
            if (is_file($to) && (int)filemtime($to) === $mtime && (int)filesize($to) === $size) {
                continue;
            }
            $content = @file_get_contents($from);
            if ($content === false || !self::writeFile($to, $content, $mtime)) {
                return false;
            }
        }
        if (is_dir($target)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($target) + 1));
                if ($relative === self::STAMP) {
                    continue;
                }
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } elseif (!isset($files[$relative])) {
                    @unlink($item->getPathname());
                }
            }
        }
        return self::writeFile($target . '/' . self::STAMP, $stamp);
    }

    /** Writes a file whole (a temporary file renamed into place), so no visitor gets half of it. */
    private static function writeFile(string $file, string $content, ?int $mtime = null): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        if ($mtime !== null) {
            @touch($tmp, $mtime);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Handles the request when its path is an asset path; returns false for everything else. */
    public static function handle(string $basePath, string $requestUri): bool
    {
        $path = rawurldecode((string)(parse_url($requestUri, PHP_URL_PATH) ?? ''));
        $path = ltrim($path, '/');

        if (str_starts_with($path, '_themes/')) {
            $rest = substr($path, strlen('_themes/'));
            $slash = strpos($rest, '/');
            $theme = $slash === false ? '' : substr($rest, 0, $slash);
            if ($theme === '' || !preg_match('/^[a-z0-9_-]+$/i', $theme)) {
                self::notFound();
                return true;
            }
            $assetPath = substr($rest, $slash + 1);
            if ($assetPath === '_blocks.css' || $assetPath === '_blocks.js') {
                self::sendBlockBundle($basePath . '/themes/' . $theme, $basePath . '/custom', $assetPath === '_blocks.js' ? 'js' : 'css');
                return true;
            }
            self::send(self::resolve($basePath . '/themes/' . $theme, $assetPath));
            return true;
        }

        if (str_starts_with($path, '_custom/')) {
            self::send(self::resolve($basePath . '/custom', substr($path, strlen('_custom/'))));
            return true;
        }

        return false;
    }

    /** `blocks/<type>/<file>` maps into the blocks folder; everything else into assets/. */
    private static function resolve(string $root, string $path): ?string
    {
        if (str_starts_with($path, 'blocks/')) {
            return Theme::assetFile($root . '/blocks', substr($path, strlen('blocks/')));
        }
        return Theme::assetFile($root . '/assets', $path);
    }

    private static function send(?string $file): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return;
        }
        if ($file === null) {
            self::notFound();
            return;
        }

        $mtime = (int)filemtime($file);
        $size = (int)filesize($file);
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
        $versioned = isset($_GET['v']) && $_GET['v'] !== '';

        header('Content-Type: ' . self::contentType($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300'));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if (str_ends_with(strtolower($file), '.svg')) {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        }

        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $ifModifiedSince = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if (($ifNoneMatch !== '' && $ifNoneMatch === $etag)
            || ($ifNoneMatch === '' && $ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)) {
            http_response_code(304);
            return;
        }

        header('Content-Length: ' . $size);
        if ($method === 'GET') {
            readfile($file);
        }
    }

    /** Concatenated block stylesheets or scripts for `?b=type,type` (see Theme::blockStylesheetUrl()). */
    private static function sendBlockBundle(string $themePath, string $customPath, string $kind): void
    {
        if (!is_dir($themePath)) {
            self::notFound();
            return;
        }
        $types = explode(',', (string)($_GET['b'] ?? ''));
        $files = Theme::blockAssetFiles($themePath, $customPath, $types, $kind === 'js' ? 'block.js' : 'block.css');
        if ($files === []) {
            self::notFound();
            return;
        }
        $css = self::bundle($files, $kind);
        $stamp = '';
        foreach ($files as $file) {
            $stamp .= $file . filemtime($file) . filesize($file);
        }
        $etag = '"' . substr(sha1($stamp), 0, 16) . '"';
        header('Content-Type: ' . ($kind === 'js' ? 'text/javascript' : 'text/css') . '; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . (isset($_GET['v']) && $_GET['v'] !== '' ? 'public, max-age=31536000, immutable' : 'public, max-age=300'));
        header('ETag: ' . $etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . strlen($css));
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo $css;
        }
    }

    private static function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo 'Not found';
    }
}
