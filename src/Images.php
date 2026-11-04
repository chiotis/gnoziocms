<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Responsive images for files in public/uploads.
 *
 * `render()` writes <picture>/<img> markup with intrinsic width/height, AVIF (where GD has it) and
 * WebP srcsets, and lazy or high-priority loading. Variants live at
 *   /uploads/_v/<source path>/<width>-<version>-<format tag>.<webp|avif>
 * and are served as static files. While a variant is missing its address is
 *   /_image/<source path>/<width>-<version>-<format tag>
 * which has no file extension, so every web server hands it to index.php (CloudPanel's nginx
 * answers 404 for a missing .webp itself); `handle()` writes the file and serves it.
 * The version is derived from the source mtime, so a replaced source gets new URLs; the format tag
 * (`w72`: WebP at quality 72, `a50`: AVIF at 50) changes with the encoding, so files of an older
 * encoding are not reused. Names without a tag (`<width>-<version>`) are the WebP of older releases,
 * still answered for pages kept from before.
 *
 * A page names a missing variant by its pending address. The variants a page asked for are written
 * after the response (generatePending(), where the server can finish a response first) and by the
 * cron job (fill()); a kept page that still has pending addresses is kept only PENDING_PAGE_TTL
 * seconds, so it is drawn again with the files' own addresses.
 */
final class Images
{
    public const WIDTHS = [360, 540, 720, 800, 960, 1280, 1600, 1920, 2400];
    public const VARIANT_DIR = '_v';
    /** The address of a variant that is not written yet; see the class comment. */
    public const PENDING_PREFIX = '/_image/';
    private const MAX_PIXELS = 36_000_000;
    private const WEBP_QUALITY = 72;
    private const AVIF_QUALITY = 50;
    private const AVIF_SPEED = 6;
    /** How long a kept page that still names a pending variant is used (PageCache). */
    public const PENDING_PAGE_TTL = 60;
    /** Files of an older encoding are removed by fill() once they are this old (pages kept from before may still name them). */
    private const STALE_AFTER = 7 * 86400;
    /** A transparent pixel: the src of a deferred picture until a script gives it its own. */
    private const PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** @var array<string, array{path: string, relative: string, width: int, height: int, type: int, mtime: int}|null> */
    private array $infoCache = [];
    /** @var array<string, string>|null */
    private ?array $altIndex = null;
    /** Variants this request named by their pending address. @var array<string, array{relative: string, width: int, format: string}> */
    private array $pending = [];

    public function __construct(private string $publicDir, private string $mediaMetaDir = '')
    {
    }

    public static function webpSupported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatetruecolor');
    }

    public static function avifSupported(): bool
    {
        return function_exists('imageavif') && function_exists('imagecreatetruecolor');
    }

    /** The formats variants are written in, best first. @return list<string> */
    public static function formats(): array
    {
        if (!self::webpSupported()) {
            return [];
        }
        return self::avifSupported() ? ['avif', 'webp'] : ['webp'];
    }

    /** The tag of the current encoding of a format, in a variant's name. */
    private static function tag(string $format): string
    {
        return $format === 'avif' ? 'a' . self::AVIF_QUALITY : 'w' . self::WEBP_QUALITY;
    }

    /** A variant's name without its extension. */
    private static function variantName(int $width, int $mtime, string $format): string
    {
        return $width . '-' . base_convert((string)$mtime, 10, 36) . '-' . self::tag($format);
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
     *  - defer: true writes the addresses as data-src/data-srcset (with a <noscript> copy) for a script to fill in
     *    later (the slides of a slider after the first), so the browser does not fetch them with the page
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
        $defer = ($options['defer'] ?? false) === true && !$priority;

        if ($info === null || $info['type'] === IMAGETYPE_GIF || !self::webpSupported()) {
            return $defer ? self::deferred($img, $attributes) : $img;
        }
        $maxWidth = (int)($options['max_width'] ?? 0);
        $widths = [];
        foreach (self::widthsFor($info['width']) as $width) {
            if ($maxWidth > 0 && $width > $maxWidth && $widths !== []) {
                break;
            }
            $widths[] = $width;
        }
        $sources = '';
        foreach (self::formats() as $format) {
            $srcset = implode(', ', array_map(fn(int $width): string => $this->variantUrl($info, $width, $format) . ' ' . $width . 'w', $widths));
            $sources .= '<source type="image/' . $format . '"' . self::attributes(['srcset' => $srcset, 'sizes' => $sizes]) . '>';
        }
        $picture = '<picture>' . $sources . $img . '</picture>';
        return $defer ? self::deferred($picture, $attributes) : $picture;
    }

    /**
     * The markup of a deferred picture: srcset and src as data attributes, a transparent pixel as src, and the markup itself
     * in <noscript> for a browser without scripts.
     *
     * @param array<string, string> $attributes the attributes of the <img>
     */
    private static function deferred(string $markup, array $attributes): string
    {
        $noscript = '<noscript>' . $markup . '</noscript>';
        $markup = str_replace(' srcset="', ' data-srcset="', $markup);
        $deferredImg = '<img' . self::attributes(['src' => self::PLACEHOLDER, 'data-src' => $attributes['src']] + $attributes) . '>';
        $markup = str_replace('<img' . self::attributes($attributes) . '>', $deferredImg, $markup);
        return $markup . $noscript;
    }

    /** @param array{relative: string, mtime: int} $info */
    public function variantUrl(array $info, int $width, string $format = 'webp'): string
    {
        $format = $format === 'avif' ? 'avif' : 'webp';
        $path = implode('/', array_map('rawurlencode', explode('/', $info['relative'])));
        $name = self::variantName($width, $info['mtime'], $format);
        if (!is_file($this->publicDir . '/uploads/' . self::VARIANT_DIR . '/' . $info['relative'] . '/' . $name . '.' . $format)) {
            $this->pending[$info['relative'] . "\0" . $name] = ['relative' => $info['relative'], 'width' => $width, 'format' => $format];
            return self::PENDING_PREFIX . $path . '/' . $name;
        }
        return '/uploads/' . self::VARIANT_DIR . '/' . $path . '/' . $name . '.' . $format;
    }

    /** How many variants this request named by their pending address. */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /** Whether a page names a variant by its pending address. */
    public static function namesPending(string $html): bool
    {
        return str_contains($html, self::PENDING_PREFIX);
    }

    /**
     * Writes the variants this request named by their pending address (after the response), until `$deadline`
     * (a microtime(true)). How many were written.
     */
    public function generatePending(float $deadline): int
    {
        $written = 0;
        foreach ($this->pending as $key => $variant) {
            if (microtime(true) >= $deadline) {
                break;
            }
            unset($this->pending[$key]);
            $file = self::sourceFile($this->publicDir . '/uploads', $variant['relative']);
            $size = $file !== null ? @getimagesize($file) : false;
            if ($file === null || !is_array($size)) {
                continue;
            }
            $name = self::variantName($variant['width'], (int)filemtime($file), $variant['format']);
            $target = $this->publicDir . '/uploads/' . self::VARIANT_DIR . '/' . $variant['relative'] . '/' . $name . '.' . $variant['format'];
            if (!is_file($target) && self::generate($file, (int)$size[2], $variant['width'], $target, $variant['format'])) {
                $written++;
            }
        }
        return $written;
    }

    /**
     * For the cron job: writes the missing variants of every picture under uploads/ (every width and format a page may ask for),
     * and removes variants of an older encoding or an older version of their source once they are STALE_AFTER old. Stops at
     * `$deadline` (a microtime(true)); the next run goes on. How many files were written.
     */
    public function fill(float $deadline): int
    {
        $uploads = $this->publicDir . '/uploads';
        if (!is_dir($uploads) || self::formats() === []) {
            return 0;
        }
        $written = 0;
        $items = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $item): bool => !($item->isDir() && $item->getPathname() === $uploads . '/' . self::VARIANT_DIR)
        ));
        foreach ($items as $item) {
            if (microtime(true) >= $deadline) {
                break;
            }
            if (!$item->isFile() || !in_array(strtolower($item->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($uploads) + 1));
            $file = self::sourceFile($uploads, $relative);
            $size = $file !== null ? @getimagesize($file) : false;
            if ($file === null || !is_array($size) || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                continue;
            }
            [$sourceWidth] = self::orientedSize($file, (int)$size[0], (int)$size[1], (int)$size[2]);
            $mtime = (int)filemtime($file);
            $dir = $uploads . '/' . self::VARIANT_DIR . '/' . $relative;
            $current = [];
            foreach (self::widthsFor($sourceWidth) as $width) {
                foreach (self::formats() as $format) {
                    $name = self::variantName($width, $mtime, $format) . '.' . $format;
                    $current[$name] = true;
                    if (is_file($dir . '/' . $name)) {
                        continue;
                    }
                    if (microtime(true) >= $deadline) {
                        return $written;
                    }
                    if (self::generate($file, (int)$size[2], $width, $dir . '/' . $name, $format)) {
                        $written++;
                    }
                }
            }
            foreach (glob($dir . '/*.{webp,avif}', GLOB_BRACE) ?: [] as $old) {
                if (!isset($current[basename($old)]) && (int)@filemtime($old) < time() - self::STALE_AFTER) {
                    @unlink($old);
                }
            }
        }
        return $written;
    }

    /** Removes every variant of a source (called when a media item is deleted). */
    public function purge(string $relative): void
    {
        if (!Theme::isSafeRelativePath($relative)) {
            return;
        }
        $dir = $this->publicDir . '/uploads/' . self::VARIANT_DIR . '/' . $relative;
        foreach (glob($dir . '/*.{webp,avif}', GLOB_BRACE) ?: [] as $file) {
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
        $pending = str_starts_with($path, self::PENDING_PREFIX);
        if ($pending) {
            $path = $prefix . substr($path, strlen(self::PENDING_PREFIX));
        }
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        $rest = substr($path, strlen($prefix));
        $slash = strrpos($rest, '/');
        // <width>-<version>[-<tag>], with the extension of its format unless it is a pending address.
        $pattern = $pending ? '/^(\d{2,5})-([a-z0-9]{1,12})(?:-([wa]\d{1,3}))?$/' : '/^(\d{2,5})-([a-z0-9]{1,12})(?:-([wa]\d{1,3}))?\.(webp|avif)$/';
        if ($slash === false || !preg_match($pattern, substr($rest, $slash + 1), $m)) {
            self::notFound();
            return true;
        }
        $tag = (string)($m[3] ?? '');
        $format = str_starts_with($tag, 'a') ? 'avif' : 'webp';
        if ((!$pending && $m[4] !== $format) || ($tag !== '' && $tag !== self::tag($format)) || ($format === 'avif' && !self::avifSupported())) {
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

        // A name without a tag is the WebP of an older release, still asked for by pages kept from before.
        $name = $width . '-' . $version . ($tag !== '' ? '-' . $tag : '');
        $target = $uploads . '/' . self::VARIANT_DIR . '/' . $relative . '/' . $name . '.' . $format;
        if (!is_file($target) && !self::generate($file, (int)$size[2], $width, $target, $format)) {
            // Never break the page: fall back to the original file.
            header('Location: /uploads/' . implode('/', array_map('rawurlencode', explode('/', $relative))), true, 302);
            header('Cache-Control: no-store');
            FrontCache::doNotKeep();
            return true;
        }

        header('Content-Type: image/' . $format);
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

    private static function generate(string $file, int $type, int $width, string $target, string $format = 'webp'): bool
    {
        if (!self::webpSupported() || ($format === 'avif' && !self::avifSupported())) {
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
        $ok = $format === 'avif'
            ? @imageavif($canvas, $temp, self::AVIF_QUALITY, self::AVIF_SPEED)
            : imagewebp($canvas, $temp, self::WEBP_QUALITY);
        clearstatcache(true, $temp);
        if (!$ok || (int)@filesize($temp) === 0 || !@rename($temp, $target)) {
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
