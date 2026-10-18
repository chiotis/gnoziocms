<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Responsive images for files in public/uploads.
 *
 * `render()` writes <picture>/<img> markup with intrinsic width/height, WebP srcset, and
 * lazy or high-priority loading. Variants live at
 *   /uploads/_v/<source path>/<width>-<version>.webp
 * and are created on first request: the web server falls through to index.php while the file
 * is missing, `handle()` writes it, and later requests are served as static files.
 * The version is derived from the source mtime, so a replaced source gets new URLs.
 */
final class Images
{
    public const WIDTHS = [360, 540, 720, 960, 1280, 1600, 1920, 2400];
    public const VARIANT_DIR = '_v';
    private const MAX_PIXELS = 36_000_000;
    private const WEBP_QUALITY = 80;

    /** @var array<string, array{path: string, relative: string, width: int, height: int, type: int, mtime: int}|null> */
    private array $infoCache = [];
    /** @var array<string, string>|null */
    private ?array $altIndex = null;

    public function __construct(private string $publicDir, private string $mediaMetaDir = '')
    {
    }

    public static function webpSupported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatetruecolor');
    }

    /**
     * Intrinsic data for a local raster image under /uploads, or null.
     *
     * @return array{path: string, relative: string, width: int, height: int, type: int, mtime: int}|null
     */
    public function info(string $src): ?array
    {
        $src = trim($src);
        if (array_key_exists($src, $this->infoCache)) {
            return $this->infoCache[$src];
        }
        $info = null;
        $path = (string)(parse_url($src, PHP_URL_PATH) ?? '');
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) && str_starts_with($path, '/uploads/')) {
            $relative = rawurldecode(substr($path, strlen('/uploads/')));
            $file = self::sourceFile($this->publicDir . '/uploads', $relative);
            if ($file !== null) {
                $size = @getimagesize($file);
                if (is_array($size) && in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
                    [$width, $height] = self::orientedSize($file, (int)$size[0], (int)$size[1], (int)$size[2]);
                    $info = [
                        'path' => $file,
                        'relative' => $relative,
                        'width' => $width,
                        'height' => $height,
                        'type' => (int)$size[2],
                        'mtime' => (int)filemtime($file),
                    ];
                }
            }
        }
        return $this->infoCache[$src] = $info;
    }

    /**
     * Image markup. Options:
     *  - alt: text; '' marks the image decorative; null (not given) uses the media library alt
     *  - sizes: the sizes attribute (default 100vw)
     *  - priority: true for above-the-fold images (eager + fetchpriority=high)
     *  - loading: lazy (default) | eager
     *  - class, max_width (largest variant to offer), width/height (for non-local images)
     *
     * @param array<string, mixed> $options
     */
    public function render(string $src, array $options = []): string
    {
        $src = trim($src);
        if ($src === '' || !FieldSchema::isSafeUrl($src)) {
            return '';
        }
        $alt = array_key_exists('alt', $options) && $options['alt'] !== null
            ? (string)$options['alt']
            : $this->libraryAlt($src);
        $priority = ($options['priority'] ?? false) === true;
        $loading = $priority ? 'eager' : (($options['loading'] ?? 'lazy') === 'eager' ? 'eager' : 'lazy');
        $sizes = trim((string)($options['sizes'] ?? '100vw')) ?: '100vw';
        $class = trim((string)($options['class'] ?? ''));

        $attributes = [
            'src' => $src,
            'alt' => $alt,
        ];
        $info = $this->info($src);
        if ($info !== null) {
            $attributes['width'] = (string)$info['width'];
            $attributes['height'] = (string)$info['height'];
        } elseif (isset($options['width'], $options['height'])) {
            $attributes['width'] = (string)(int)$options['width'];
            $attributes['height'] = (string)(int)$options['height'];
        }
        if ($class !== '') {
            $attributes['class'] = $class;
        }
        $attributes['loading'] = $loading;
        $attributes['decoding'] = $priority ? 'sync' : 'async';
        if ($priority) {
            $attributes['fetchpriority'] = 'high';
        }
        $img = '<img' . self::attributes($attributes) . '>';

        if ($info === null || $info['type'] === IMAGETYPE_GIF || !self::webpSupported()) {
            return $img;
        }
        $maxWidth = (int)($options['max_width'] ?? 0);
        $srcset = [];
        foreach (self::widthsFor($info['width']) as $width) {
            if ($maxWidth > 0 && $width > $maxWidth && $srcset !== []) {
                break;
            }
            $srcset[] = $this->variantUrl($info, $width) . ' ' . $width . 'w';
        }
        return '<picture><source type="image/webp"' . self::attributes([
            'srcset' => implode(', ', $srcset),
            'sizes' => $sizes,
        ]) . '>' . $img . '</picture>';
    }

    /** @param array{relative: string, mtime: int} $info */
    public function variantUrl(array $info, int $width): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', $info['relative'])));
        return '/uploads/' . self::VARIANT_DIR . '/' . $path . '/' . $width . '-' . base_convert((string)$info['mtime'], 10, 36) . '.webp';
    }

    /** Removes every variant of a source (called when a media item is deleted). */
    public function purge(string $relative): void
    {
        if (!Theme::isSafeRelativePath($relative)) {
            return;
        }
        $dir = $this->publicDir . '/uploads/' . self::VARIANT_DIR . '/' . $relative;
        foreach (glob($dir . '/*.webp') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** Widths offered for a source: the standard steps below it, plus the source width itself (capped). */
    public static function widthsFor(int $sourceWidth): array
    {
        $cap = min($sourceWidth, max(self::WIDTHS));
        $widths = array_values(array_filter(self::WIDTHS, static fn(int $w): bool => $w < $cap));
        $widths[] = $cap;
        return $widths;
    }

    /**
     * Front-controller hook for missing variant files. Returns false when the request is not
     * a variant URL.
     */
    public static function handle(string $publicDir, string $requestUri): bool
    {
        $path = rawurldecode((string)(parse_url($requestUri, PHP_URL_PATH) ?? ''));
        $prefix = '/uploads/' . self::VARIANT_DIR . '/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        $rest = substr($path, strlen($prefix));
        $slash = strrpos($rest, '/');
        if ($slash === false || !preg_match('/^(\d{2,5})-([a-z0-9]{1,12})\.webp$/', substr($rest, $slash + 1), $m)) {
            self::notFound();
            return true;
        }
        $relative = substr($rest, 0, $slash);
        $width = (int)$m[1];
        $uploads = $publicDir . '/uploads';
        $file = self::sourceFile($uploads, $relative);
        $size = $file !== null ? @getimagesize($file) : false;
        if ($file === null || !is_array($size) || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            self::notFound();
            return true;
        }
        [$sourceWidth] = self::orientedSize($file, (int)$size[0], (int)$size[1], (int)$size[2]);
        $version = base_convert((string)filemtime($file), 10, 36);
        if ($m[2] !== $version || !in_array($width, self::widthsFor($sourceWidth), true)) {
            self::notFound();
            return true;
        }

        $target = $uploads . '/' . self::VARIANT_DIR . '/' . $relative . '/' . $width . '-' . $version . '.webp';
        if (!is_file($target) && !self::generate($file, (int)$size[2], $width, $target)) {
            // Never break the page: fall back to the original file.
            header('Location: /uploads/' . implode('/', array_map('rawurlencode', explode('/', $relative))), true, 302);
            header('Cache-Control: no-store');
            FrontCache::doNotKeep();
            return true;
        }

        header('Content-Type: image/webp');
        header('Content-Length: ' . (int)filesize($target));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            readfile($target);
        }
        return true;
    }

    private function libraryAlt(string $src): string
    {
        if ($this->altIndex === null) {
            $this->altIndex = [];
            foreach ($this->mediaMetaDir !== '' ? (glob($this->mediaMetaDir . '/*.yaml') ?: []) : [] as $metaFile) {
                $raw = (string)@file_get_contents($metaFile);
                if (!str_contains($raw, 'alt:')) {
                    continue;
                }
                try {
                    $meta = \Symfony\Component\Yaml\Yaml::parse($raw);
                } catch (\Throwable) {
                    continue;
                }
                $alt = is_array($meta) ? trim((string)($meta['alt'] ?? '')) : '';
                if ($alt !== '' && !empty($meta['path'])) {
                    $this->altIndex['/uploads/' . ltrim((string)$meta['path'], '/')] = $alt;
                }
            }
        }
        return $this->altIndex[(string)(parse_url($src, PHP_URL_PATH) ?? '')] ?? '';
    }

    private static function generate(string $file, int $type, int $width, string $target): bool
    {
        if (!self::webpSupported()) {
            return false;
        }
        $size = @getimagesize($file);
        if (!is_array($size) || ((int)$size[0] * (int)$size[1]) > self::MAX_PIXELS) {
            return false;
        }
        [$orientedWidth, $orientedHeight] = self::orientedSize($file, (int)$size[0], (int)$size[1], $type);
        $height = max(1, (int)round($orientedHeight * $width / max(1, $orientedWidth)));
        $needed = ((int)$size[0] * (int)$size[1] + $width * $height) * 6;
        if (!self::memoryAvailable($needed)) {
            return false;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default => false,
        };
        if ($source === false) {
            return false;
        }
        $source = self::applyOrientation($source, $file, $type);

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            return false;
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $temp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = imagewebp($canvas, $temp, self::WEBP_QUALITY);
        if (!$ok || !@rename($temp, $target)) {
            @unlink($temp);
            return false;
        }
        return true;
    }

    /** JPEG EXIF orientations 5–8 swap width and height. @return array{0: int, 1: int} */
    private static function orientedSize(string $file, int $width, int $height, int $type): array
    {
        return in_array(self::orientation($file, $type), [5, 6, 7, 8], true) ? [$height, $width] : [$width, $height];
    }

    private static function orientation(string $file, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($file);
        return is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
    }

    private static function applyOrientation(\GdImage $image, string $file, int $type): \GdImage
    {
        $orientation = self::orientation($file, $type);
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) {
                return $rotated;
            }
        }
        return $image;
    }

    private static function memoryAvailable(int $bytes): bool
    {
        $limit = trim((string)ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $value = (int)$limit;
        $unit = strtolower(substr($limit, -1));
        $value *= match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
        return memory_get_usage(true) + $bytes < $value;
    }

    /**
     * The file behind an address under /uploads, of any type (a download, a route file), or null when the address is not one of
     * this site's uploads or the file is not there. Never a path outside the uploads folder.
     */
    public function uploadFile(string $src): ?string
    {
        $src = trim($src);
        $path = (string)(parse_url($src, PHP_URL_PATH) ?? '');
        if ($src === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) || !str_starts_with($path, '/uploads/')) {
            return null;
        }
        return self::sourceFile($this->publicDir . '/uploads', rawurldecode(substr($path, strlen('/uploads/'))));
    }

    /** Size in bytes of a file of any type under /uploads (a download), or null when it is not one of this site's uploads. */
    public function fileSize(string $src): ?int
    {
        $file = $this->uploadFile($src);
        return $file === null ? null : (int)(filesize($file) ?: 0);
    }

    /**
     * The address of a small version of a picture (for a map's popup or a card in a list): the variant closest to the width
     * asked, or the address itself when it is not a picture of this site.
     */
    public function thumbUrl(string $src, int $width = 480): string
    {
        $info = $this->info($src);
        if ($info === null) {
            return $src;
        }
        $widths = self::widthsFor($info['width']);
        $pick = $widths[count($widths) - 1];
        foreach ($widths as $candidate) {
            if ($candidate >= $width) {
                $pick = $candidate;
                break;
            }
        }
        return $this->variantUrl($info, $pick);
    }

    private static function sourceFile(string $uploadsDir, string $relative): ?string
    {
        if (!Theme::isSafeRelativePath($relative) || str_starts_with($relative, self::VARIANT_DIR . '/')) {
            return null;
        }
        $file = $uploadsDir . '/' . $relative;
        if (!is_file($file)) {
            return null;
        }
        $realRoot = realpath($uploadsDir);
        $realFile = realpath($file);
        if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $realFile;
    }

    /** @param array<string, string> $attributes */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    private static function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
    }
}
