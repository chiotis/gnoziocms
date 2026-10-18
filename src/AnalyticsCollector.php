<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Turns what a page's script sent into one row of the analytics, or refuses it: no bots, no one who is signed in (when the site says
 * so), no one who asks not to be tracked, no address that is not a page of the site. Nothing is kept of the person: the visitor is a
 * hash of the address and browser with the salt of the day, and everything else is a class (the kind of device, the browser, the
 * country the host's network says, where the visit came from). The helpers are plain functions, so they can be tested alone.
 */
final class AnalyticsCollector
{
    /** Search engines and social networks: a domain (and its subdomains) with the name it is shown with. */
    private const SEARCH = ['bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo', 'baidu.com' => 'Baidu', 'ecosia.org' => 'Ecosia', 'search.brave.com' => 'Brave', 'startpage.com' => 'Startpage', 'qwant.com' => 'Qwant', 'ask.com' => 'Ask'];
    private const SOCIAL = [
        'facebook.com' => 'Facebook', 'fb.com' => 'Facebook', 'instagram.com' => 'Instagram', 't.co' => 'X', 'twitter.com' => 'X', 'x.com' => 'X',
        'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'youtube.com' => 'YouTube', 'reddit.com' => 'Reddit', 'tiktok.com' => 'TikTok',
        't.me' => 'Telegram', 'telegram.org' => 'Telegram', 'whatsapp.com' => 'WhatsApp', 'threads.net' => 'Threads', 'bsky.app' => 'Bluesky',
    ];
    /** The same, for names that have a domain in every country (google.gr, google.co.uk): the name without its ending. */
    private const SEARCH_COUNTRY = ['google' => 'Google', 'yahoo' => 'Yahoo', 'yandex' => 'Yandex'];
    private const SOCIAL_COUNTRY = ['pinterest' => 'Pinterest'];
    /** The kinds of event a page may report. */
    public const EVENT_TYPES = ['outbound' => 'Outbound link', 'download' => 'Download', 'mailto' => 'Email link', 'tel' => 'Phone link', 'form' => 'Form sent', 'custom' => 'Event'];

    /**
     * @param \Closure(): AnalyticsStore $store
     * @param \Closure(): array<string, mixed> $settings the site settings
     */
    public function __construct(private \Closure $store, private \Closure $settings)
    {
    }

    /**
     * Counts a visit or an event.
     *
     * @param array<string, mixed> $payload what the script sent: p (path), r (referrer), q (query), w (screen width), e (event type), v (event value)
     * @param array<string, mixed> $server the request ($_SERVER)
     * @return string 'ok' when it was counted, otherwise why not
     */
    public function collect(array $payload, array $server, bool $signedIn, ?int $now = null): string
    {
        // What a script sent is text or nothing: a list or an object is nothing.
        $payload = array_map(static fn($value): string => is_scalar($value) ? (string)$value : '', $payload);
        $a = AnalyticsSettings::from(($this->settings)());
        if ($a['mode'] !== 'platform') {
            return 'off';
        }
        if ($signedIn && $a['skip_signed_in']) {
            return 'signed-in';
        }
        if ($a['respect_dnt'] && ((string)($server['HTTP_DNT'] ?? '') === '1' || (string)($server['HTTP_SEC_GPC'] ?? '') === '1')) {
            return 'dnt';
        }
        $ua = mb_substr((string)($server['HTTP_USER_AGENT'] ?? ''), 0, 400);
        if (self::isBot($ua)) {
            return 'bot';
        }
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($server['HTTP_HOST'] ?? '')));
        $origin = strtolower((string)parse_url((string)($server['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST));
        if ($origin !== '' && $origin !== $host) {
            return 'origin';
        }
        $path = self::path((string)($payload['p'] ?? ''));
        if ($path === '' || SeoSettings::matches($a['ignore_paths'], $path)) {
            return 'path';
        }
        $kind = 'view';
        $name = '';
        $type = (string)($payload['e'] ?? '');
        if ($type !== '') {
            $name = self::event($type, (string)($payload['v'] ?? ''));
            if ($name === '') {
                return 'event';
            }
            $kind = 'event';
        }
        $now ??= time();
        $day = date('Y-m-d', $now);
        $store = ($this->store)();
        if (!$store->isAvailable()) {
            return 'store';
        }
        [$source, $channel, $campaign] = self::origin((string)($payload['r'] ?? ''), (string)($payload['q'] ?? ''), $host);
        $stored = $store->record([
            'ts' => $now,
            'day' => $day,
            'visitor' => self::visitor($store->salt($day), (string)($server['REMOTE_ADDR'] ?? ''), $ua, $host),
            'kind' => $kind,
            'path' => $path,
            'name' => $name,
            'source' => $source,
            'channel' => $channel,
            'campaign' => $campaign,
            'device' => self::device((int)($payload['w'] ?? 0), $ua),
            'browser' => self::browser($ua),
            'os' => self::os($ua),
            'country' => self::country($server),
            'lang' => self::language((string)($server['HTTP_ACCEPT_LANGUAGE'] ?? '')),
        ]);
        return $stored ? 'ok' : 'limit';
    }

    public static function isBot(string $ua): bool
    {
        return $ua === '' || preg_match('/bot|crawl|spider|slurp|headless|phantom|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|preview|fetch|scan|curl|wget|python|java\/|go-http|okhttp|libwww|httpclient|axios|node-fetch|facebookexternalhit|whatsapp|telegram|discord|slack/i', $ua) === 1;
    }

    /** The path of a page: starts with a slash, no query, no control characters, not the admin; '' when it is not one. */
    public static function path(string $raw): string
    {
        $raw = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', '', $raw));
        if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//')) {
            return '';
        }
        $path = rawurldecode((string)(parse_url($raw, PHP_URL_PATH) ?? ''));
        $path = (string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $path);
        if ($path === '' || !mb_check_encoding($path, 'UTF-8')) {
            return '';
        }
        $path = '/' . trim((string)preg_replace('#/{2,}#', '/', $path), '/');
        if ($path === '/admin' || str_starts_with($path, '/admin/') || str_starts_with($path, '/_a/')) {
            return '';
        }
        return mb_substr($path, 0, 300);
    }

    /**
     * The name of an event: its kind and its value ("outbound|example.com"); '' when the kind is not one of the kinds.
     */
    public static function event(string $type, string $value): string
    {
        $type = strtolower(trim($type));
        if (!array_key_exists($type, self::EVENT_TYPES)) {
            return '';
        }
        $value = trim((string)preg_replace('/[\x00-\x1F\x7F|]+/u', ' ', strip_tags($value)));
        return $type . '|' . mb_substr($value, 0, 100);
    }

    /** The visitor of a day: a hash that is the same for one address and browser that day, and for nothing else. */
    public static function visitor(string $salt, string $ip, string $ua, string $host): string
    {
        return substr(hash('sha256', $salt . '|' . $ip . '|' . $ua . '|' . $host), 0, 16);
    }

    public static function device(int $width, string $ua): string
    {
        if ($width > 0) {
            return $width < 768 ? 'mobile' : ($width < 1100 ? 'tablet' : 'desktop');
        }
        if (preg_match('/iPad|Tablet/i', $ua) === 1) {
            return 'tablet';
        }
        return preg_match('/Mobi|iPhone|Android/i', $ua) === 1 ? 'mobile' : 'desktop';
    }

    public static function browser(string $ua): string
    {
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Opera' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'MSIE' => 'Internet Explorer', 'Trident/' => 'Internet Explorer', 'Safari/' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }
        return 'Other';
    }

    public static function os(string $ua): string
    {
        foreach (['iPhone' => 'iOS', 'iPad' => 'iOS', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Macintosh' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }
        return 'Other';
    }

    /** The two letters of the country a CDN or the host says the request came from, or ''. */
    public static function country(array $server): string
    {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE'] as $key) {
            $code = strtoupper(trim((string)($server[$key] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $code) === 1 && !in_array($code, ['XX', 'T1', 'ZZ'], true)) {
                return $code;
            }
        }
        return '';
    }

    /** The main language of the browser: the first of Accept-Language, two letters, or ''. */
    public static function language(string $header): string
    {
        return preg_match('/^\s*([A-Za-z]{2})/', $header, $m) === 1 ? strtolower($m[1]) : '';
    }

    /**
     * Where a visit came from: [the name of the source, the channel (direct, search, social, referral, paid, email, campaign), the campaign].
     * A campaign tagged in the address (utm_source, utm_medium, utm_campaign) wins over the page the visitor came from.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function origin(string $referrer, string $query, string $host): array
    {
        parse_str(ltrim($query, '?'), $utm);
        $clean = static fn(mixed $v): string => is_string($v) ? mb_substr(trim((string)preg_replace('/[^\p{L}\p{N} _.\-+:]/u', '', $v)), 0, 60) : '';
        $source = $clean($utm['utm_source'] ?? '');
        $medium = strtolower($clean($utm['utm_medium'] ?? ''));
        $campaign = $clean($utm['utm_campaign'] ?? '');
        [$refSource, $refChannel] = self::referrer($referrer, $host);
        if ($source !== '' || $medium !== '' || $campaign !== '') {
            $channel = match (true) {
                in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'display', 'cpm', 'ads', 'ad'], true) => 'paid',
                in_array($medium, ['email', 'e-mail', 'newsletter', 'mail'], true) => 'email',
                in_array($medium, ['social', 'social-media', 'social_network'], true) => 'social',
                default => 'campaign',
            };
            return [$source !== '' ? $source : $refSource, $channel, $campaign];
        }
        return [$refSource, $refChannel, ''];
    }

    /** @return array{0: string, 1: string} the name of the source and its channel for the page a visitor came from */
    public static function referrer(string $referrer, string $host): array
    {
        $ref = strtolower((string)parse_url(trim($referrer), PHP_URL_HOST));
        $ref = (string)preg_replace('/^(?:www|m|l|lm|mobile|out)\./', '', $ref);
        $own = (string)preg_replace('/^www\./', '', $host);
        if ($ref === '' || $ref === $own || !preg_match('/^[a-z0-9.\-]+$/', $ref)) {
            return ['Direct', 'direct'];
        }
        $under = static fn(string $domain): string => '/^(?:[a-z0-9-]+\.)*' . preg_quote($domain, '/') . '$/';
        $country = static fn(string $name): string => '/^(?:[a-z0-9-]+\.)*' . preg_quote($name, '/') . '\.(?:com?\.[a-z]{2}|[a-z]{2,3})$/';
        foreach ([[self::SEARCH, self::SEARCH_COUNTRY, 'search'], [self::SOCIAL, self::SOCIAL_COUNTRY, 'social']] as [$domains, $countries, $channel]) {
            foreach ($domains as $domain => $name) {
                if (preg_match($under($domain), $ref) === 1) {
                    return [$name, $channel];
                }
            }
            foreach ($countries as $word => $name) {
                if (preg_match($country($word), $ref) === 1) {
                    return [$name, $channel];
                }
            }
        }
        return [mb_substr($ref, 0, 80), 'referral'];
    }
}
