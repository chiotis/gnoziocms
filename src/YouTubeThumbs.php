<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The pictures of a playlist's videos, kept on this site. A page that shows them from YouTube sends every visitor's address to
 * Google when it opens; from here nothing is asked of YouTube until a visitor plays a video. A picture is fetched the first time
 * it is asked for and kept for 30 days. The address of each carries a signature, so the route cannot be used to fetch other
 * pictures from YouTube through this site.
 */
final class YouTubeThumbs
{
    public const KEEP_DAYS = 30;
    private const MAX_BYTES = 400_000;

    /** @param \Closure(string): array{0: int, 1: string} $http the status and body of an address */
    public function __construct(private string $directory, private SystemMetaRepository $meta, private \Closure $http)
    {
    }

    /** The address of a picture on this site (without the site's own path), signed. */
    public function path(string $videoId): string
    {
        return '_yt/' . $videoId . '.jpg?s=' . $this->signature($videoId);
    }

    public function isValid(string $videoId, string $signature): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1 && hash_equals($this->signature($videoId), $signature);
    }

    /** The file of the picture, fetched when it is not there or is old; null when YouTube has none to give. */
    public function file(string $videoId): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }
        $file = $this->directory . '/' . $videoId . '.jpg';
        if (is_file($file) && time() - (int)filemtime($file) < self::KEEP_DAYS * 86400) {
            return $file;
        }
        foreach (['hqdefault', 'mqdefault'] as $size) {
            [$status, $body] = ($this->http)('https://i.ytimg.com/vi/' . $videoId . '/' . $size . '.jpg');
            if ($status === 200 && strlen($body) > 500 && strlen($body) <= self::MAX_BYTES && str_starts_with($body, "\xFF\xD8\xFF")) {
                if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                    return null;
                }
                return @file_put_contents($file, $body, LOCK_EX) !== false ? $file : null;
            }
        }
        // An old copy is better than none.
        return is_file($file) ? $file : null;
    }

    private function signature(string $videoId): string
    {
        $secret = $this->meta->get('youtube.secret');
        if ($secret === null || $secret === '') {
            $secret = bin2hex(random_bytes(16));
            $this->meta->set('youtube.secret', $secret);
        }
        return substr(hash_hmac('sha256', $videoId, $secret), 0, 16);
    }
}
