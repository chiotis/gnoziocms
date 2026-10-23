<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The site's own copy of its public pages, for a host with no cache in front of it (Varnish, a CDN). A page a visitor sees without a
 * session is the same for everyone, so its answer is kept in a file and sent again until the site changes: the PHP of a visit is a
 * file read. It keeps what a cache in front would keep and nothing else:
 *
 * - a GET of the public site without a session, with no query (a filter, a sort or a search is worked out each time), on the address
 *   the site has (its own host and scheme), that answered 200 with a page, a sitemap, a feed or robots.txt, set no cookie and did not
 *   say it must not be kept (FrontCache::doNotKeep());
 * - emptied by the same call that empties the cache in front (FrontCache::purgeAll()): whatever is saved in the admin. The files are
 *   not deleted on the spot: a generation number changes at the end of that request, after what was saved is on disk, and a response
 *   that started under the old number is not kept. A copy also stops being used after TTL seconds, for what changes outside the admin.
 *
 * It is off until System > Page cache turns it on; with a cache in front it is not needed.
 */
final class PageCache
{
    public const META_ENABLED = 'page_cache_enabled';
    /** How long a copy is used, whatever happens (a file edited outside the admin shows up by then). */
    public const TTL = 600;
    /** The most copies kept, and the largest answer kept: a site has a page for each address, so this is a guard, not a limit it meets. */
    public const MAX_ENTRIES = 3000;
    public const MAX_BYTES = 2000000;

    /** The answers that are pages (not pictures or downloads). */
    private const TYPES = ['text/html', 'application/xml', 'text/xml', 'application/rss+xml', 'application/atom+xml', 'text/plain'];
    /** Headers of the first response that are not kept: the server sets them again for the one it sends. */
    private const SKIPPED_HEADERS = ['set-cookie', 'x-powered-by', 'content-length', 'transfer-encoding', 'connection', 'date', 'keep-alive', 'x-page-cache'];

    private bool $purgeQueued = false;
    private ?bool $enabled = null;

    public function __construct(private SystemMetaRepository $meta, private string $directory, private string $version)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled ??= $this->meta->isAvailable() && $this->meta->get(self::META_ENABLED) === '1';
    }

    public function setEnabled(bool $on): bool
    {
        if (!$this->meta->isAvailable()) {
            return false;
        }
        $this->meta->set(self::META_ENABLED, $on ? '1' : '0');
        $this->enabled = $on;
        // Copies from before it was turned off are not to come back.
        $this->bump();
        $this->deleteFiles();
        return true;
    }

    /**
     * The key of this request when its answer may be kept or served, else null.
     *
     * @param string $siteHost the host (and port, if any) of the site's address; '' when the site has none, and then nothing is kept
     */
    public function keyFor(string $method, string $host, bool $https, string $path, string $query, string $siteHost): ?string
    {
        if ($method !== 'GET' || $query !== '' || $siteHost === '' || strtolower($host) !== strtolower($siteHost) || !$this->isEnabled()) {
            return null;
        }
        return sha1($this->version . '|' . ($https ? 'https' : 'http') . '|' . strtolower($host) . '|' . trim($path, '/'));
    }

    /**
     * Sends the copy of `$key` when there is a current one: its headers and its body. True when the response is complete.
     * (`$send` takes the headers and the body; tests pass their own.)
     */
    public function serve(string $key, ?\Closure $send = null): bool
    {
        $file = $this->file($key);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false || ($cut = strpos($raw, "\n")) === false) {
            return false;
        }
        $head = json_decode(substr($raw, 0, $cut), true);
        if (!is_array($head) || ($head['generation'] ?? null) !== $this->generation() || (int)($head['expires'] ?? 0) < time()) {
            return false;
        }
        $body = substr($raw, $cut + 1);
        if (strlen($body) !== (int)($head['length'] ?? -1)) {
            return false;
        }
        $headers = array_values(array_filter((array)($head['headers'] ?? []), 'is_string'));
        if ($send !== null) {
            $send($headers, $body);
            return true;
        }
        header_remove();
        foreach ($headers as $line) {
            header($line, false);
        }
        header('X-Page-Cache: hit');
        echo $body;
        return true;
    }

    /**
     * Starts keeping the answer to this request: when it ends, it is written down if it is one that may be kept. Call it after the
     * headers that belong to every response (the security headers, Cache-Control) are set, so they are kept with it.
     */
    public function capture(string $key): void
    {
        $generation = $this->generation();
        header('X-Page-Cache: miss');
        ob_start(function (string $buffer, int $phase) use ($key, $generation): bool {
            // Only an answer that was buffered whole: a flush in the middle means the headers may be gone.
            if (($phase & PHP_OUTPUT_HANDLER_START) !== 0 && ($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0) {
                try {
                    $this->keep($key, $generation, $buffer);
                } catch (\Throwable) {
                    // The page is sent as it is; it is just not kept.
                }
            }
            return false;
        });
    }

    /** Keeps `$body` under `$key` when this response may be kept. Public for tests, which pass the response they stand for. */
    public function keep(string $key, string $generation, string $body, ?int $status = null, ?array $headers = null): bool
    {
        $status ??= http_response_code() ?: 200;
        $headers ??= headers_list();
        if ($status !== 200 || $body === '' || strlen($body) > self::MAX_BYTES || FrontCache::refused() || session_status() === PHP_SESSION_ACTIVE) {
            return false;
        }
        $type = '';
        $kept = [];
        foreach ($headers as $line) {
            $name = strtolower(trim((string)strstr($line, ':', true)));
            if ($name === 'set-cookie') {
                return false;
            }
            if ($name === 'cache-control' && preg_match('/\b(no-store|private|no-cache)\b/i', $line) === 1) {
                return false;
            }
            if ($name === 'content-type') {
                $type = strtolower(trim(explode(';', (string)substr($line, strpos($line, ':') + 1))[0]));
            }
            if ($name !== '' && !in_array($name, self::SKIPPED_HEADERS, true)) {
                $kept[] = $line;
            }
        }
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            return false;
        }
        // The site changed while this response was made: it may show what is no longer there.
        if ($this->generation() !== $generation || !$this->room()) {
            return false;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return false;
        }
        $head = json_encode(['generation' => $generation, 'expires' => time() + self::TTL, 'length' => strlen($body), 'headers' => $kept], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($head === false) {
            return false;
        }
        $file = $this->file($key);
        $temporary = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $head . "\n" . $body) === false) {
            @unlink($temporary);
            return false;
        }
        return @rename($temporary, $file);
    }

    /**
     * Empties the cache when the request ends: a new generation (what began before it is not kept) and the files removed. Once per
     * request. The generation is the first thing that changes, so a copy is never sent after this returns.
     */
    public function purgeAtEnd(): void
    {
        if ($this->purgeQueued) {
            return;
        }
        $this->purgeQueued = true;
        // Nothing was ever kept: nothing to do.
        if (!is_dir($this->directory)) {
            return;
        }
        register_shutdown_function(function (): void {
            $this->bump();
            $this->deleteFiles();
        });
    }

    /** How many copies are kept, and their size. @return array{entries: int, bytes: int} */
    public function stats(): array
    {
        $entries = 0;
        $bytes = 0;
        foreach (glob($this->directory . '/*.page') ?: [] as $file) {
            $entries++;
            $bytes += (int)@filesize($file);
        }
        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /** The number that copies carry: a copy of another number is not used. */
    public function generation(): string
    {
        $value = is_file($this->directory . '/generation') ? trim((string)@file_get_contents($this->directory . '/generation')) : '';
        return $value !== '' ? $value : '0';
    }

    private function bump(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }
        $temporary = $this->directory . '/generation.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, bin2hex(random_bytes(8))) !== false) {
            @rename($temporary, $this->directory . '/generation');
        }
    }

    private function deleteFiles(): void
    {
        foreach (glob($this->directory . '/*.{page,tmp}', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
    }

    private function room(): bool
    {
        return count(glob($this->directory . '/*.page') ?: []) < self::MAX_ENTRIES;
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . $key . '.page';
    }
}
