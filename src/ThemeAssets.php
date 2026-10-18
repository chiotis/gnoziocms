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
        $css = '';
        $stamp = '';
        foreach ($files as $file) {
            // Each script is its own statement list; a newline plus ';' keeps concatenation safe.
            $css .= '/* ' . basename(dirname($file)) . ' */' . "\n" . (string)file_get_contents($file) . "\n" . ($kind === 'js' ? ";\n" : '');
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
