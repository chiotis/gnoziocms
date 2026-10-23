<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * A cache in front of the site (Varnish) that keeps public pages: emptied whenever the site changes, so a change shows at once
 * however long the cache keeps a page. The site's own copy of its pages (PageCache) is emptied by the same call.
 *
 * - CloudPanel: its Varnish controller (`~/.varnish-cache/controller.php`, prepended to every request) is found by itself. A purge
 *   is handed to it with the site's cache tag and sent when the request ends; nothing to set up.
 * - Any other Varnish: the address of the cache (System > Page cache). A purge is `PURGE /` with the site's Host; the cache
 *   must turn that into a ban of the host (docs/varnish.md).
 */
final class FrontCache
{
    public const META_ADDRESS = 'front_cache_address';

    private bool $queued = false;
    private static bool $refused = false;
    private ?PageCache $pages = null;

    public function __construct(
        private SystemMetaRepository $meta,
        private string $host
    ) {
    }

    /** The site's own copy of its pages, which a purge empties too. */
    public function keepPages(PageCache $pages): void
    {
        $this->pages = $pages;
    }

    /** 'cloudpanel', 'varnish' (an address is set) or 'none'. */
    public function mode(): string
    {
        if (self::cloudPanel() !== null) {
            return 'cloudpanel';
        }
        return $this->address() !== '' ? 'varnish' : 'none';
    }

    /** The cache tag CloudPanel gives every page of the site ('' elsewhere). */
    public function cloudPanelTag(): string
    {
        return self::cloudPanel() !== null ? (string)\ClpVarnish::getCacheTagPrefix() : '';
    }

    public function address(): string
    {
        return $this->meta->isAvailable() ? trim((string)$this->meta->get(self::META_ADDRESS)) : '';
    }

    /** Keeps the address of the cache ('' forgets it). False when it is not host:port or an http address. */
    public function setAddress(string $address): bool
    {
        $address = trim($address);
        if ($address !== '' && self::endpoint($address) === null) {
            return false;
        }
        if (!$this->meta->isAvailable()) {
            return false;
        }
        $this->meta->set(self::META_ADDRESS, $address);
        return true;
    }

    /**
     * Empties the cache of the whole site, once per request: CloudPanel sends it when the request ends; another Varnish is asked
     * after the response, so what was saved is on disk first; the site's own copies are dropped at the same moment. False when there
     * is no cache to empty.
     */
    public function purgeAll(): bool
    {
        if ($this->queued) {
            return true;
        }
        $this->pages?->purgeAtEnd();
        $own = $this->pages !== null && $this->pages->isEnabled();
        $cloudPanel = self::cloudPanel();
        if ($cloudPanel !== null) {
            $tag = $this->cloudPanelTag();
            if ($tag === '') {
                return $own && ($this->queued = true);
            }
            $cloudPanel->addTagsToPurge([$tag]);
            return $this->queued = true;
        }
        $address = $this->address();
        if ($address === '') {
            return $own && ($this->queued = true);
        }
        register_shutdown_function(fn() => $this->sendPurge($address));
        return $this->queued = true;
    }

    /** Asks the cache at `$address` now; the status it answered (0: no answer). */
    public function sendPurge(string $address): int
    {
        $endpoint = self::endpoint($address);
        if ($endpoint === null) {
            return 0;
        }
        $host = str_contains($endpoint['host'], ':') ? '[' . $endpoint['host'] . ']' : $endpoint['host'];
        $socket = @stream_socket_client('tcp://' . $host . ':' . $endpoint['port'], $errno, $error, 2.0);
        if ($socket === false) {
            return 0;
        }
        stream_set_timeout($socket, 2);
        fwrite($socket, "PURGE / HTTP/1.1\r\nHost: " . $this->host . "\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
        $status = (string)fgets($socket, 256);
        fclose($socket);
        return preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $status, $m) === 1 ? (int)$m[1] : 0;
    }

    /** This response is not to be kept (an editor's view, a page that failed, an answer to a script). */
    public static function doNotKeep(): void
    {
        self::$refused = true;
        self::cloudPanel()?->setIsCacheable(false);
    }

    /** Whether something in this request said its response is not to be kept (doNotKeep). */
    public static function refused(): bool
    {
        return self::$refused;
    }

    /** CloudPanel's controller of this request, when it runs. */
    private static function cloudPanel(): ?object
    {
        if (!class_exists('ClpVarnish', false)) {
            return null;
        }
        $controller = $GLOBALS['clpVarnish'] ?? null;
        return is_object($controller) && method_exists($controller, 'addTagsToPurge') && method_exists($controller, 'setIsCacheable') ? $controller : null;
    }

    /** @return array{host: string, port: int}|null */
    private static function endpoint(string $address): ?array
    {
        $url = str_contains($address, '://') ? $address : 'http://' . $address;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') === '' || isset($parts['user'])) {
            return null;
        }
        return ['host' => trim((string)$parts['host'], '[]'), 'port' => (int)($parts['port'] ?? 80)];
    }
}
