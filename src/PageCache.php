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
 *   that started under the old number is not kept. A copy also stops being used after its lifetime (ttl(), Settings > Cache), for
 *   what changes outside the admin (after Images::PENDING_PAGE_TTL when it names a picture that is not written yet);
 * - or, for a saved entry, only the copies made from it (purgeChangedAtEnd(), "Refresh only what changed"): each copy keeps what it
 *   was made from (CacheTags), a save stamps what it changed in `stamps.json`, and a copy made before a stamp of one of its tags is
 *   not used any more (nor kept, when it was being made meanwhile). Anything else saved in the admin still empties all of them;
 * - made again after it was emptied (rebuild(), "Build the pages again"), by asking the site for the pages that were dropped, after
 *   the response was sent; and made all at once from Settings > Cache > Build cache now (the browser asks for every address).
 *
 * It is off until Settings > Cache turns it on; with a cache in front it is not needed.
 *
 * A copy is sent before the application boots (serveEarly(), from public/index.php): for a GET with no query and no session
 * cookie, it needs only two small files the application writes next to the copies, `frontdoor.json` (the site's host and its
 * trusted proxies, as when the copies were made) and `quiet` (the time until which nothing is due after a response, P3). Without
 * them, or when something may be due, the application answers as before and serves the copy itself.
 */
final class PageCache
{
    public const META_ENABLED = 'page_cache_enabled';
    public const META_TTL = 'page_cache_ttl';
    public const META_TARGETED = 'page_cache_targeted';
    public const META_REBUILD = 'page_cache_rebuild';
    /** How long a copy is used at most when nothing else is said (a file edited outside the admin shows up by then). */
    public const TTL = 600;
    /** The lifetimes Settings > Cache offers, in seconds, with their names. */
    public const TTL_CHOICES = [300 => '5 minutes', 600 => '10 minutes', 1800 => '30 minutes', 3600 => '1 hour', 21600 => '6 hours', 43200 => '12 hours', 86400 => '1 day', 604800 => '1 week'];
    /** How many dropped pages are made again after a change, and for how long at most (seconds), so the work ends. */
    public const REBUILD_MAX = 50;
    public const REBUILD_SECONDS = 60;
    /** The most copies kept, and the largest answer kept: a site has a page for each address, so this is a guard, not a limit it meets. */
    public const MAX_ENTRIES = 3000;
    public const MAX_BYTES = 2000000;

    /** The answers that are pages (not pictures or downloads). */
    private const TYPES = ['text/html', 'application/xml', 'text/xml', 'application/rss+xml', 'application/atom+xml', 'text/plain'];
    /** Headers of the first response that are not kept: the server sets them again for the one it sends. */
    private const SKIPPED_HEADERS = ['set-cookie', 'x-powered-by', 'content-length', 'transfer-encoding', 'connection', 'date', 'keep-alive', 'x-page-cache', 'server-timing'];

    /** How long a visit served from a copy may skip asking whether work is due after the response (backups, scheduled entries). */
    public const QUIET_SECONDS = 60;

    private bool $purgeQueued = false;
    /** Whether this request's purge empties everything (true) or only what the saved entries were in (false). */
    private bool $purgeAll = false;
    private ?bool $enabled = null;
    /** When the page being kept started to be made, and its address (capture()). */
    private float $started = 0.0;
    private string $url = '';
    /** @var array<string, array<string, float>> stamps.json of each directory, read once per request */
    private static array $stamps = [];
    /** @var array{site_host: string, trusted: list<string>}|null what serveEarly() needs, written beside the copies */
    private ?array $frontDoor = null;

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

    /** How long a copy is used at most, in seconds (Settings > Cache > Keep each page for). */
    public function ttl(): int
    {
        $value = $this->meta->isAvailable() ? (int)$this->meta->get(self::META_TTL) : 0;
        return isset(self::TTL_CHOICES[$value]) ? $value : self::TTL;
    }

    public function setTtl(int $seconds): bool
    {
        if (!isset(self::TTL_CHOICES[$seconds]) || !$this->meta->isAvailable()) {
            return false;
        }
        $this->meta->set(self::META_TTL, (string)$seconds);
        return true;
    }

    /** Whether saving an entry drops only the pages made from it (on unless it was turned off). */
    public function refreshesChangedOnly(): bool
    {
        return !$this->meta->isAvailable() || $this->meta->get(self::META_TARGETED) !== '0';
    }

    /** Whether the pages a change dropped are made again at once, after the response (on unless it was turned off). */
    public function rebuildsAfterChange(): bool
    {
        return !$this->meta->isAvailable() || $this->meta->get(self::META_REBUILD) !== '0';
    }

    public function setRefreshChangedOnly(bool $on): bool
    {
        return $this->setSwitch(self::META_TARGETED, $on);
    }

    public function setRebuildAfterChange(bool $on): bool
    {
        return $this->setSwitch(self::META_REBUILD, $on);
    }

    private function setSwitch(string $key, bool $on): bool
    {
        if (!$this->meta->isAvailable()) {
            return false;
        }
        $this->meta->set($key, $on ? '1' : '0');
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
        return self::key($this->version, $https, $host, $path);
    }

    public static function key(string $version, bool $https, string $host, string $path): string
    {
        return sha1($version . '|' . ($https ? 'https' : 'http') . '|' . strtolower($host) . '|' . trim($path, '/'));
    }

    /** What serveEarly() needs to make the same key: the site's host and the trusted proxies of now. Written with the next copy. */
    public function setFrontDoor(string $siteHost, array $trusted): void
    {
        $this->frontDoor = ['site_host' => strtolower($siteHost), 'trusted' => array_values(array_map('strval', $trusted))];
    }

    /** Nothing is due after a response for QUIET_SECONDS: visits served before the application boots need not ask. */
    public function markQuiet(): void
    {
        if (is_dir($this->directory)) {
            @file_put_contents($this->directory . '/quiet', (string)(time() + self::QUIET_SECONDS), LOCK_EX);
        }
    }

    /** Whether a visit served from a copy may skip the work after the response (nothing was due a moment ago). */
    public function isQuiet(): bool
    {
        return self::quietUntil($this->directory) > time();
    }

    private static function quietUntil(string $directory): int
    {
        return (int)trim((string)@file_get_contents($directory . '/quiet'));
    }

    /**
     * public/index.php, before the application: sends the copy of this request when it is one that may be served and it is
     * current. False sends nothing, and the application answers. Mirrors what App::handle() asks before it serves a copy.
     *
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $cookies $_COOKIE
     */
    public static function serveEarly(string $basePath, array $server, array $cookies, ?\Closure $send = null): bool
    {
        $uri = (string)($server['REQUEST_URI'] ?? '/');
        if (($server['REQUEST_METHOD'] ?? 'GET') !== 'GET' || str_contains($uri, '?') || isset($server['HTTP_X_GNOZIO_UPDATE'])
            || isset($server['HTTP_X_FAROS_UPDATE']) /* the old name, MaintenanceMode::OLD */
            || isset($cookies[(string)ini_get('session.name')]) || is_file($basePath . '/storage/maintenance.flag')) {
            return false;
        }
        $directory = $basePath . '/storage/cache/pages';
        $door = json_decode((string)@file_get_contents($directory . '/frontdoor.json'), true);
        if (!is_array($door) || !is_string($door['site_host'] ?? null) || $door['site_host'] === '' || self::quietUntil($directory) <= time()) {
            return false;
        }
        $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
        $path = trim((string)(parse_url($uri, PHP_URL_PATH) ?? '/'), '/');
        if ($host !== $door['site_host'] || str_starts_with($path, 'admin')) {
            return false;
        }
        $https = (new RequestOrigin($server, RequestOrigin::parseList($door['trusted'] ?? [])))->isHttps();
        $version = trim((string)@file_get_contents($basePath . '/VERSION'));
        return self::serveFile($directory, self::key($version, $https, $host, $path), $send, 'page cache hit, before the application');
    }

    /**
     * Sends the copy of `$key` when there is a current one: its headers and its body. True when the response is complete.
     * (`$send` takes the headers and the body; tests pass their own.)
     */
    public function serve(string $key, ?\Closure $send = null): bool
    {
        return self::serveFile($this->directory, $key, $send, 'page cache hit');
    }

    private static function serveFile(string $directory, string $key, ?\Closure $send, string $timing): bool
    {
        $file = $directory . '/' . $key . '.page';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false || ($cut = strpos($raw, "\n")) === false) {
            return false;
        }
        $head = json_decode(substr($raw, 0, $cut), true);
        if (!is_array($head) || ($head['generation'] ?? null) !== self::generationIn($directory) || (int)($head['expires'] ?? 0) < time()
            || self::changedSince($directory, (array)($head['tags'] ?? []), (float)($head['made'] ?? 0))) {
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
        header('Server-Timing: cache;desc="' . $timing . '"');
        echo $body;
        return true;
    }

    /**
     * Starts keeping the answer to this request: when it ends, it is written down if it is one that may be kept. Call it after the
     * headers that belong to every response (the security headers, Cache-Control) are set, so they are kept with it.
     */
    public function capture(string $key, string $url = ''): void
    {
        $generation = $this->generation();
        $this->started = microtime(true);
        $this->url = $url;
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
    public function keep(string $key, string $generation, string $body, ?int $status = null, ?array $headers = null, ?array $tags = null, ?float $started = null): bool
    {
        $status ??= http_response_code() ?: 200;
        $headers ??= headers_list();
        $tags ??= CacheTags::read();
        $started ??= $this->started > 0 ? $this->started : microtime(true);
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
        self::$stamps = [];
        if ($this->generation() !== $generation || self::changedSince($this->directory, $tags, $started) || !$this->room()) {
            return false;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return false;
        }
        // A page that names a picture still to be written is drawn again soon, with the picture's own address (Images).
        $ttl = Images::namesPending($body) ? min($this->ttl(), Images::PENDING_PAGE_TTL) : $this->ttl();
        $head = json_encode([
            'generation' => $generation, 'expires' => time() + $ttl, 'made' => $started, 'tags' => array_values($tags), 'url' => $this->url,
            'length' => strlen($body), 'headers' => $kept,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($head === false) {
            return false;
        }
        $file = $this->file($key);
        $temporary = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $head . "\n" . $body) === false) {
            @unlink($temporary);
            return false;
        }
        $this->writeFrontDoor();
        return @rename($temporary, $file);
    }

    /** frontdoor.json beside the copies, when what it says changed (most copies find it as it is). */
    private function writeFrontDoor(): void
    {
        if ($this->frontDoor === null) {
            return;
        }
        $json = (string)json_encode($this->frontDoor, JSON_UNESCAPED_SLASHES);
        $path = $this->directory . '/frontdoor.json';
        if (@file_get_contents($path) === $json) {
            return;
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $json) !== false) {
            @rename($temporary, $path);
        }
    }

    /**
     * Empties the cache when the request ends: a new generation (what began before it is not kept) and the files removed. Once per
     * request. The generation is the first thing that changes, so a copy is never sent after this returns. It wins over
     * purgeChangedAtEnd() in the same request.
     */
    public function purgeAtEnd(): void
    {
        $this->purgeAll = true;
        $this->queuePurge();
    }

    /**
     * Drops, when the request ends, only the copies made from the entries this request wrote (CacheTags): the entry's own page and
     * the pages that list its type, show it through a relation, or are made from all the content. When nothing was written nothing
     * is dropped; when an entry was taken away (deleted, moved) everything is, since links and menus may have changed with it.
     */
    public function purgeChangedAtEnd(): void
    {
        $this->queuePurge();
    }

    private function queuePurge(): void
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
            $dropped = $this->purgeAll || CacheTags::anyRemoved() ? $this->dropAll() : $this->dropChanged(CacheTags::changes());
            if ($dropped !== [] && $this->isEnabled() && $this->rebuildsAfterChange() && CronStatus::finishFunction() !== null) {
                // After whatever else runs at the end (a Varnish's purge), and after the response was sent.
                register_shutdown_function(fn() => $this->rebuildAfterResponse($dropped));
            }
        });
    }

    /** Empties everything now. @return list<string> the addresses that were kept, the ones most worth making again first */
    public function dropAll(): array
    {
        $this->bump();
        $urls = $this->rebuildsAfterChange() ? $this->addressesOf(glob($this->directory . '/*.page') ?: []) : [];
        $this->deleteFiles();
        @unlink($this->directory . '/stamps.json');
        self::$stamps = [];
        return $urls;
    }

    /**
     * Drops now the copies made from any of `$tags` (what CacheTags::changes() says was written): the tags are stamped first, so a
     * copy is not sent once this starts, then the files go. @return list<string> the addresses of the copies dropped
     */
    public function dropChanged(array $tags): array
    {
        if ($tags === [] || !is_dir($this->directory)) {
            return [];
        }
        $this->stamp($tags, microtime(true));
        $wanted = array_flip($tags);
        $dropped = [];
        foreach (glob($this->directory . '/*.page') ?: [] as $file) {
            $head = self::headOf($file);
            if ($head === null) {
                continue;
            }
            foreach ((array)($head['tags'] ?? []) as $tag) {
                if (isset($wanted[$tag])) {
                    $dropped[] = $file;
                    break;
                }
            }
        }
        $urls = $this->addressesOf($dropped);
        foreach ($dropped as $file) {
            @unlink($file);
        }
        return $urls;
    }

    /**
     * The addresses the copies in `$files` were made for, the home pages first, then the ones made last, at most REBUILD_MAX.
     * @param string[] $files @return list<string>
     */
    private function addressesOf(array $files): array
    {
        $found = [];
        foreach ($files as $file) {
            $head = self::headOf($file);
            $url = is_array($head) ? (string)($head['url'] ?? '') : '';
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
                // A home page (of the site, of a language) first: it is the most visited.
                $found[$url] = [preg_match('#^([a-z]{2})?$#', $path) === 1 ? 0 : 1, -(float)($head['made'] ?? 0)];
            }
        }
        uasort($found, static fn(array $a, array $b): int => $a <=> $b);
        return array_slice(array_keys($found), 0, self::REBUILD_MAX);
    }

    /** The first line of a kept copy: what it is (generation, time, tags, address). */
    private static function headOf(string $file): ?array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        $line = fgets($handle, 65536);
        fclose($handle);
        $head = is_string($line) ? json_decode($line, true) : null;
        return is_array($head) ? $head : null;
    }

    /** Writes when each of `$tags` changed into stamps.json, forgetting stamps older than any copy can be. */
    private function stamp(array $tags, float $now): void
    {
        $path = $this->directory . '/stamps.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $stamps = json_decode((string)stream_get_contents($handle), true);
        $stamps = is_array($stamps) ? $stamps : [];
        $oldest = $now - max(array_keys(self::TTL_CHOICES)) - 60;
        $stamps = array_filter($stamps, static fn($time): bool => is_numeric($time) && (float)$time >= $oldest);
        foreach ($tags as $tag) {
            $stamps[(string)$tag] = $now;
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string)json_encode($stamps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        self::$stamps = [];
    }

    /** Whether one of `$tags` changed at `$since` or later (stamps.json), so a copy made from them then is old. */
    private static function changedSince(string $directory, array $tags, float $since): bool
    {
        if ($tags === []) {
            return false;
        }
        if (!isset(self::$stamps[$directory])) {
            $read = is_file($directory . '/stamps.json') ? json_decode((string)@file_get_contents($directory . '/stamps.json'), true) : [];
            self::$stamps[$directory] = is_array($read) ? $read : [];
        }
        $stamps = self::$stamps[$directory];
        foreach ($tags as $tag) {
            if (isset($stamps[$tag]) && (float)$stamps[$tag] >= $since) {
                return true;
            }
        }
        return false;
    }

    /** After the response was sent: the session let go (the next screen must not wait), then the dropped pages asked for again. */
    private function rebuildAfterResponse(array $urls): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $finish = CronStatus::finishFunction();
        if ($finish !== null) {
            $finish();
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::REBUILD_SECONDS + 30);
        }
        $this->rebuild($urls);
    }

    /**
     * Asks the site for each of `$urls` the way a visitor would (no cookie), so its copy is made and kept: on this server first
     * (the site's host resolved to 127.0.0.1, which also works behind a CDN), else through the public address. Stops after
     * REBUILD_SECONDS, or at the first address that cannot be reached either way. How many pages answered is returned.
     *
     * @param list<string> $urls
     * @param \Closure(string, bool): int|null $fetch the status a GET of the address got (0: no answer), true to ask this server
     */
    public function rebuild(array $urls, ?\Closure $fetch = null, int $seconds = self::REBUILD_SECONDS): int
    {
        $fetch ??= self::fetch(...);
        $deadline = microtime(true) + $seconds;
        $local = true;
        $done = 0;
        foreach (array_slice($urls, 0, self::REBUILD_MAX) as $url) {
            if (microtime(true) > $deadline) {
                break;
            }
            $status = $local ? $fetch($url, true) : 0;
            if ($status === 0 || $status === 421) {
                // This server does not answer for the site's address: ask the public one from now on.
                $local = false;
                $status = $fetch($url, false);
            }
            if ($status === 0) {
                break;
            }
            $done++;
        }
        return $done;
    }

    /** A GET of `$url` with no cookie; the status (0: no answer, or curl cannot be used here). */
    public static function fetch(string $url, bool $local): int
    {
        if (!function_exists('curl_init') || !function_exists('curl_exec') || preg_match('#^(https?)://([^/:]+)(?::(\d+))?#i', $url, $m) !== 1) {
            return 0;
        }
        $port = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : (strtolower($m[1]) === 'https' ? 443 : 80);
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'GnozioCMS page cache',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xml;q=0.9,*/*;q=0.8'],
        ];
        if ($local) {
            $options[CURLOPT_RESOLVE] = [$m[2] . ':' . $port . ':127.0.0.1'];
        }
        $ours = false;
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$ours): int {
            if (stripos($line, 'x-page-cache:') === 0) {
                $ours = true;
            }
            return strlen($line);
        };
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch) !== false;
        // On this server, an answer without the page cache's header came from another site (the server's default one).
        return $ok && (!$local || $ours) ? (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) : 0;
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
        return self::generationIn($this->directory);
    }

    private static function generationIn(string $directory): string
    {
        $value = is_file($directory . '/generation') ? trim((string)@file_get_contents($directory . '/generation')) : '';
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
        // What the copies were made under, and the quiet time: an admin change may have changed either.
        @unlink($this->directory . '/frontdoor.json');
        @unlink($this->directory . '/quiet');
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
