<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The web as the WordPress and WooCommerce imports need it: read an address, or save one into a file, through SafeHttp (public
 * addresses only, each redirect checked, unless $allowLocal), at most five redirects, and a limit on how much one answer may be.
 */
final class WordPressHttp
{
    private const AGENT = 'GnozioCMS-import (+https://github.com/chiotis/gnoziocms)';

    /**
     * @param list<string> $headers e.g. an Authorization header
     * @return array{status: int, body: string}|null null when the site could not be reached
     */
    public static function get(string $url, array $headers = [], int $maxBytes = 20 * 1024 * 1024): ?array
    {
        $file = tempnam(sys_get_temp_dir(), 'wpget');
        if ($file === false) {
            return null;
        }
        try {
            $status = self::fetch($url, $file, $maxBytes, $headers);
            return $status === null ? null : ['status' => $status, 'body' => (string)file_get_contents($file)];
        } finally {
            @unlink($file);
        }
    }

    /** Saves an address into a file. The answer's status, or 0 when the site could not be reached or the file was too large. */
    public static function download(string $url, string $to, int $maxBytes = 20 * 1024 * 1024): int
    {
        return self::fetch($url, $to, $maxBytes) ?? 0;
    }

    /**
     * An address on this server or its network may be read: the CLI's --allow-local, or the import screen's box, for a site
     * being moved on the same server. Off, only public addresses are read, and every redirect is checked (audit S18).
     */
    public static bool $allowLocal = false;

    /** The fetcher (tests give their own). */
    public static ?SafeHttp $http = null;

    /** @param list<string> $headers sent only to the host first asked, never on to a host a redirect names */
    private static function fetch(string $url, string $to, int $maxBytes, array $headers = []): ?int
    {
        self::$lastError = '';
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        // Letters outside ASCII and spaces in the address are sent percent-encoded, as a browser would.
        $url = preg_replace_callback('/[^\x21-\x7e]/u', static fn(array $m): string => rawurlencode($m[0]), $url) ?? $url;
        $answer = (self::$http ?? new SafeHttp())->fetch($url, $to, $maxBytes, 60, '*/*', array_merge(['User-Agent: ' . self::AGENT], $headers), self::$allowLocal, 5);
        if ($answer['error'] !== '') {
            self::$lastError = $answer['error'];
            return null;
        }
        return $answer['status'];
    }

    /** Why the last address could not be read ('' when it could). */
    public static string $lastError = '';
}
