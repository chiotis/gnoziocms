<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The site's side of the Omni protocol (docs/omni-protocol.md): checks an address, connects with a code, sends signed reports
 * when they are due, and disconnects. What it knows is kept in `system_meta` under `omni`: the address, the site id and secret
 * Omni gave, how often to report, and what became of the last attempt.
 */
final class OmniClient
{
    public const META_KEY = 'omni';
    public const DEFAULT_URL = 'https://omni.faroscms.com';
    public const PROTOCOL = 1;
    /** The interval Omni asks for is kept between these, in seconds; 6 hours until it says. */
    public const MIN_EVERY = 900;
    public const MAX_EVERY = 86400;
    public const DEFAULT_EVERY = 21600;
    /** After a failed attempt, the next waits this long (or what Omni's Retry-After says). */
    public const RETRY_AFTER_FAILURE = 900;

    /**
     * @param callable(string, string, array<int, string>, string, int): array{status: int, body: string, headers: array<string, string>, error: string} $http method, url, headers, body, timeout
     * @param callable(): string $reportJson the report, as OmniReport::json() gives it
     * @param callable(): array{name: string, url: string, admin_url: string, version: string} $site what the site says about itself when it connects
     * @param callable(): string $fingerprint what changes when a report should go at once (a new backup run, another version)
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        private SystemMetaRepository $meta,
        private $http,
        private $reportJson,
        private $site,
        private $fingerprint,
        private $clock = null
    ) {
    }

    /** @return array<string, mixed> what is kept, or [] when the site never connected */
    public function state(): array
    {
        return $this->meta->getJson(self::META_KEY) ?? [];
    }

    public function isConnected(): bool
    {
        $state = $this->state();
        return ($state['status'] ?? '') === 'connected' && ($state['secret'] ?? '') !== '' && ($state['site_id'] ?? '') !== '';
    }

    /**
     * An Omni address as it is kept: https (or http for a local address: localhost, 127.0.0.1, *.localhost, *.test), no query,
     * no trailing slash. '' when it is not one.
     */
    public static function normalizeUrl(string $input): string
    {
        $input = trim($input);
        if ($input !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://' . $input;
        }
        $parts = parse_url($input);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)$parts['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            return '';
        }
        if (!preg_match('/^(\[[0-9a-f:]+\]|[a-z0-9.-]+)$/', $host)) {
            return '';
        }
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path)) {
            return '';
        }
        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . $path;
    }

    /**
     * Checks the address, then trades the code for an id and a secret, and sends the first report.
     *
     * @return array{ok: bool, message: string}
     */
    public function connect(string $url, string $code): array
    {
        $url = self::normalizeUrl($url);
        if ($url === '') {
            return ['ok' => false, 'message' => 'Enter the address of an Omni over https (http only for a local one), with no query.'];
        }
        if (trim($code) === '') {
            return ['ok' => false, 'message' => 'Enter the connection code that Omni gave for this site.'];
        }

        $ping = ($this->http)('GET', $url . '/api/v1/ping', ['Accept: application/json'], '', 10);
        $about = json_decode($ping['body'], true);
        if ($ping['status'] !== 200 || !is_array($about) || ($about['omni'] ?? null) !== true) {
            return ['ok' => false, 'message' => 'No Omni answered at ' . $url . ($ping['error'] !== '' ? ' (' . $ping['error'] . ')' : ($ping['status'] > 0 ? ' (HTTP ' . $ping['status'] . ')' : '')) . '. Check the address.'];
        }
        if (!in_array(self::PROTOCOL, (array)($about['protocols'] ?? []), true)) {
            return ['ok' => false, 'message' => 'This Omni does not speak protocol ' . self::PROTOCOL . ', the one this version of GnozioCMS uses.'];
        }

        $body = (string)json_encode(['protocol' => self::PROTOCOL, 'code' => trim($code), 'site' => ($this->site)()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $answer = ($this->http)('POST', $url . '/api/v1/connect', ['Content-Type: application/json', 'Accept: application/json'], $body, 10);
        $data = json_decode($answer['body'], true);
        $data = is_array($data) ? $data : [];
        if ($answer['status'] !== 200) {
            return ['ok' => false, 'message' => match ((string)($data['error'] ?? '')) {
                'invalid_code' => 'Omni did not accept the code: it is not known, was already used, or has expired. Make a new one in Omni.',
                'rate_limited' => 'Too many attempts to connect from this server. Try again later.',
                'unsupported_protocol' => 'This Omni does not speak protocol ' . self::PROTOCOL . '.',
                default => 'Omni refused to connect' . ($answer['status'] > 0 ? ' (HTTP ' . $answer['status'] . ')' : '') . (trim((string)($data['message'] ?? $answer['error'])) !== '' ? ': ' . trim((string)($data['message'] ?? $answer['error'])) : '.'),
            }];
        }
        $siteId = (string)($data['site_id'] ?? '');
        $secret = (string)($data['secret'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $siteId) || strlen($secret) < 32) {
            return ['ok' => false, 'message' => 'Omni answered, but not with a site id and a secret.'];
        }

        $this->meta->setJson(self::META_KEY, [
            'status' => 'connected',
            'url' => $url,
            'name' => (string)($about['name'] ?? 'Omni'),
            'site_id' => $siteId,
            'secret' => $secret,
            'report_every' => self::every($data['report_every'] ?? null),
            'connected_at' => gmdate('c', $this->now()),
        ]);
        $sent = $this->send();
        return ['ok' => true, 'message' => 'Connected to ' . $url . '.' . ($sent['ok'] ? ' The first report was sent.' : ' The first report could not be sent yet: ' . $sent['message'])];
    }

    /** Whether a report is due: the interval has passed, or a backup ran or the version changed since the last one (not while waiting after a failure). */
    public function isDue(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        $state = $this->state();
        $now = $this->now();
        if ((int)($state['retry_at'] ?? 0) > $now) {
            return false;
        }
        $last = strtotime((string)($state['last_sent_at'] ?? '')) ?: 0;
        return ($state['fingerprint'] ?? null) !== ($this->fingerprint)() || $now - $last >= (int)($state['report_every'] ?? self::DEFAULT_EVERY);
    }

    /** @return array{ok: bool, message: string}|null null when no report was due */
    public function sendIfDue(): ?array
    {
        return $this->isDue() ? $this->send() : null;
    }

    /** @return array{ok: bool, message: string} */
    public function send(): array
    {
        if (!$this->isConnected()) {
            return ['ok' => false, 'message' => 'This site is not connected to an Omni.'];
        }
        $state = $this->state();
        $fingerprint = ($this->fingerprint)();
        $answer = $this->signed('/api/v1/report', ($this->reportJson)());
        $data = json_decode($answer['body'], true);
        $data = is_array($data) ? $data : [];
        $now = $this->now();
        $state['last_attempt_at'] = gmdate('c', $now);

        if ($answer['status'] === 200 && ($data['ok'] ?? false) === true) {
            $state['last_sent_at'] = gmdate('c', $now);
            $state['report_every'] = self::every($data['report_every'] ?? ($state['report_every'] ?? null));
            $state['fingerprint'] = $fingerprint;
            unset($state['last_error'], $state['retry_at']);
            $this->meta->setJson(self::META_KEY, $state);
            return ['ok' => true, 'message' => 'Report sent.'];
        }
        if ($answer['status'] === 410) {
            // The owner removed this site from Omni: the secret is of no use any more.
            $state = ['status' => 'removed', 'url' => $state['url'] ?? '', 'name' => $state['name'] ?? 'Omni', 'removed_at' => gmdate('c', $now)];
            $this->meta->setJson(self::META_KEY, $state);
            return ['ok' => false, 'message' => 'Omni removed this site. Connect again with a new code to report to it.'];
        }

        $error = (string)($data['error'] ?? '');
        $message = match (true) {
            $answer['status'] === 0 => 'Omni could not be reached' . ($answer['error'] !== '' ? ': ' . $answer['error'] : '.'),
            in_array($error, ['unknown_site', 'invalid_signature'], true) => 'Omni does not recognise this site any more (' . $error . '). Connect again with a new code.',
            $error === 'stale_timestamp' => 'Omni refused the report because this server\'s clock is off by more than five minutes.',
            $error === 'unsupported_protocol' => 'This Omni no longer speaks protocol ' . self::PROTOCOL . '.',
            default => 'Omni refused the report (HTTP ' . $answer['status'] . ($error !== '' ? ', ' . $error : '') . ').',
        };
        $retry = (int)($answer['headers']['retry-after'] ?? 0);
        $state['last_error'] = $message;
        $state['last_error_at'] = gmdate('c', $now);
        $state['retry_at'] = $now + ($answer['status'] === 429 && $retry > 0 ? min($retry, self::MAX_EVERY) : self::RETRY_AFTER_FAILURE);
        $this->meta->setJson(self::META_KEY, $state);
        return ['ok' => false, 'message' => $message];
    }

    /** Tells Omni the site leaves, then forgets the secret (also when Omni cannot be told). @return array{ok: bool, message: string} */
    public function disconnect(): array
    {
        $state = $this->state();
        if (!$this->isConnected()) {
            $this->meta->setJson(self::META_KEY, ['url' => $state['url'] ?? '']);
            return ['ok' => true, 'message' => 'Disconnected.'];
        }
        $answer = $this->signed('/api/v1/disconnect', '{}');
        $this->meta->setJson(self::META_KEY, ['url' => $state['url'] ?? '']);
        $told = $answer['status'] === 200 || in_array($answer['status'], [401, 410], true);
        return ['ok' => true, 'message' => $told ? 'Disconnected from ' . ($state['url'] ?? 'Omni') . '.' : 'Disconnected here, but Omni could not be told; remove the site there.'];
    }

    /** The headers of a signed request (protocol v1). @return array<int, string> */
    public static function signatureHeaders(string $siteId, string $secret, string $path, string $body, int $time, string $nonce): array
    {
        $toSign = implode("\n", ['POST', $path, (string)$time, $nonce, hash('sha256', $body)]);
        return [
            'X-Omni-Site: ' . $siteId,
            'X-Omni-Timestamp: ' . $time,
            'X-Omni-Nonce: ' . $nonce,
            'X-Omni-Signature: v1=' . hash_hmac('sha256', $toSign, $secret),
        ];
    }

    /** @return array{status: int, body: string, headers: array<string, string>, error: string} */
    private function signed(string $endpoint, string $body): array
    {
        $state = $this->state();
        $url = (string)$state['url'] . $endpoint;
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? $endpoint);
        $headers = self::signatureHeaders((string)$state['site_id'], (string)$state['secret'], $path, $body, $this->now(), bin2hex(random_bytes(16)));
        return ($this->http)('POST', $url, array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers), $body, 10);
    }

    private static function every(mixed $value): int
    {
        return is_int($value) ? max(self::MIN_EVERY, min(self::MAX_EVERY, $value)) : self::DEFAULT_EVERY;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /**
     * An HTTP request with PHP's own streams (no cURL needed: many hosts switch curl_exec off), and with cURL where streams
     * cannot open addresses (allow_url_fopen off). Redirects are not followed: an Omni answers at its own address.
     *
     * @param array<int, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>, error: string}
     */
    public static function request(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $headers[] = 'User-Agent: GnozioCMS Omni client';
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ]]);
            $error = '';
            set_error_handler(static function (int $no, string $message) use (&$error): bool {
                $error = preg_replace('/^.*?\):\s*/', '', $message) ?? $message;
                return true;
            });
            try {
                $stream = fopen($url, 'rb', false, $context);
                $response = $stream !== false ? stream_get_contents($stream) : false;
                // The status and headers from the stream's own metadata: every PHP version fills it (the
                // $http_response_header variable is only set where the code names it, and is deprecated from PHP 8.5).
                $lines = $stream !== false ? (array)(stream_get_meta_data($stream)['wrapper_data'] ?? []) : [];
                if ($stream !== false) {
                    fclose($stream);
                }
            } finally {
                restore_error_handler();
            }
            return self::parsed(array_map('strval', $lines), is_string($response) ? $response : '', $response === false ? ($error ?: 'no answer') : '');
        }
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $lines = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lines): int {
                    $lines[] = rtrim($line, "\r\n");
                    return strlen($line);
                },
            ]);
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $error = $response === false ? curl_error($ch) : '';
            return self::parsed($lines, is_string($response) ? $response : '', $error);
        }
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'this server can open no addresses (allow_url_fopen and cURL are both off)'];
    }

    /** @param array<int, string> $lines @return array{status: int, body: string, headers: array<string, string>, error: string} */
    private static function parsed(array $lines, string $body, string $error): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $headers, 'error' => $status === 0 ? $error : ''];
    }
}
