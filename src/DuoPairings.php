<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The colour pairings of Duo (duo.alexpate.com, by Alex Pate), fetched live for the palette picker in the admin.
 *
 * The collection has no licence, so none of it is part of this code: the admin asks for the list when someone opens the picker
 * and keeps the answer in storage/cache for a week. The public site never calls it. When the list cannot be had (the
 * feature is off, the address is not allowed, the request fails or times out, the answer is not what was expected) the picker
 * is simply not offered. The list is `{"colors":[{"colors":["#rrggbb","#rrggbb"],"aa_accessible":true}, ...]}`.
 */
final class DuoPairings
{
    public const DEFAULT_URL = 'https://duo.alexpate.com/colors.json';
    public const SITE_URL = 'https://duo.alexpate.com';
    public const CREDIT = 'Colour pairings from Duo by Alex Pate';
    public const TTL = 604800; // seven days
    public const TIMEOUT = 4;
    public const MAX_BYTES = 262144;
    private const CACHE_FILE = 'duo-pairings.json';

    /** @var callable(string): ?string */
    private $http;
    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(string): ?string|null $http returns the body of an address or null; the real request when omitted
     * @param callable(): int|null $clock the time, for tests
     */
    public function __construct(private string $cacheDir, ?callable $http = null, ?callable $clock = null)
    {
        $this->http = $http ?? [self::class, 'request'];
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * The pairs, from the cache while it is fresh and otherwise from the address; null when there are none to show.
     *
     * @return array<int, array{colors: array{0: string, 1: string}, aa: bool}>|null
     */
    public function pairs(string $url): ?array
    {
        $url = trim($url) === '' ? self::DEFAULT_URL : trim($url);
        if (!UpdateNetwork::isAllowedUrl($url)) {
            return null;
        }
        $file = rtrim($this->cacheDir, '/') . '/' . self::CACHE_FILE;
        $cached = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        if (is_array($cached) && ($cached['url'] ?? '') === $url && is_int($cached['fetched'] ?? null)
            && ($this->clock)() - $cached['fetched'] < self::TTL && is_array($cached['pairs'] ?? null) && $cached['pairs'] !== []) {
            return $cached['pairs'];
        }
        try {
            $body = ($this->http)($url);
        } catch (\Throwable) {
            $body = null;
        }
        $pairs = is_string($body) ? self::parse($body) : null;
        if ($pairs === null) {
            return null;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($file, json_encode(['url' => $url, 'fetched' => ($this->clock)(), 'pairs' => $pairs]), LOCK_EX);
        return $pairs;
    }

    /**
     * What a list holds, checked: pairs of two hex colours and the AA flag. Null when it is not a list of pairs.
     *
     * @return array<int, array{colors: array{0: string, 1: string}, aa: bool}>|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        $list = is_array($data) ? ($data['colors'] ?? null) : null;
        if (!is_array($list)) {
            return null;
        }
        $pairs = [];
        foreach ($list as $item) {
            $colors = is_array($item) && is_array($item['colors'] ?? null) ? array_values($item['colors']) : [];
            $a = isset($colors[0]) && is_string($colors[0]) ? FieldSchema::normalizeHex($colors[0]) : null;
            $b = isset($colors[1]) && is_string($colors[1]) ? FieldSchema::normalizeHex($colors[1]) : null;
            if ($a !== null && $b !== null) {
                $pairs[] = ['colors' => [$a, $b], 'aa' => ($item['aa_accessible'] ?? false) === true];
            }
            if (count($pairs) >= 500) {
                break;
            }
        }
        return $pairs === [] ? null : $pairs;
    }

    /** The request itself: https only, a few seconds at most, a small answer at most. */
    public static function request(string $url): ?string
    {
        if (!UpdateNetwork::isAllowedUrl($url)) {
            return null;
        }
        if (UpdateNetwork::hasCurl()) {
            $ch = curl_init($url);
            $body = '';
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_USERAGENT => 'GnozioCMS admin',
                CURLOPT_FAILONERROR => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_REDIR_PROTOCOLS => str_starts_with(strtolower($url), 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    $body .= $chunk;
                    return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch) !== false;
            unset($ch);
            return $ok ? $body : null;
        }
        $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => self::TIMEOUT, 'follow_location' => 1, 'max_redirects' => 3, 'user_agent' => 'GnozioCMS admin']]));
        if ($in === false) {
            return null;
        }
        $body = (string)stream_get_contents($in, self::MAX_BYTES + 1);
        fclose($in);
        return strlen($body) > self::MAX_BYTES ? null : $body;
    }
}
