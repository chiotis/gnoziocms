<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Calls an AI provider's API (docs/ai-publishing-plan.md). Not for addresses someone else chose (that is SafeHttp): the host is one
 * of the provider's own, over https only, given when the client is made. JSON or multipart (pictures sent with a request) in, JSON
 * out, long time limits (a picture can take minutes), large answers (pictures come back as base64), and every failure sorted into a
 * kind the caller acts on: no credit, rate limit, refused by the provider's filter, bad request, key, timeout, server, network.
 *
 * The key travels only in the headers the caller passes. It is never part of an error, and any secret given to the constructor is
 * cut out of every message the provider sends back, so neither a log line nor a screen can show it.
 */
final class AiHttp
{
    public const NO_CREDIT = 'no_credit';
    public const RATE_LIMIT = 'rate_limit';
    public const REFUSED = 'refused';
    public const BAD_REQUEST = 'bad_request';
    public const AUTH = 'auth';
    public const TIMEOUT = 'timeout';
    public const SERVER = 'server';
    public const NETWORK = 'network';

    /** Kinds worth trying again later; the others need a person (a key, credit, the instructions). */
    public const RETRYABLE = [self::RATE_LIMIT, self::TIMEOUT, self::SERVER, self::NETWORK];

    public const DEFAULT_TIMEOUT = 180;
    public const DEFAULT_MAX_BYTES = 41943040;
    private const AGENT = 'GnozioCMS (+https://github.com/chiotis/gnoziocms)';

    /** Provider error codes that mean the prompt or picture was refused by its safety system (not worth retrying). */
    private const REFUSAL_CODES = ['content_policy_violation', 'moderation_blocked', 'safety_violation', 'content_filter'];

    /**
     * @param string[] $hosts the only hosts this client may call (exact names)
     * @param string[] $secrets cut out of every message
     * @param (\Closure(string, string, string[], ?string, int, int): array{status: int, headers: array<string, string>, body: string, error: string, timed_out: bool})|null $transport
     *   sends one request: method, url, headers, body, time limit, size limit (tests give their own)
     */
    public function __construct(private array $hosts, private array $secrets = [], private ?\Closure $transport = null)
    {
    }

    /** @param array<string, mixed> $payload @param string[] $headers @return array<string, mixed> see result() */
    public function postJson(string $url, array $payload, array $headers, int $timeout = self::DEFAULT_TIMEOUT, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this->send('POST', $url, array_merge(['Content-Type: application/json'], $headers), $body, $timeout, $maxBytes);
    }

    /**
     * A form with files, for requests that carry pictures (OpenAI's image edits). The body is built here, not by curl, so a field
     * can repeat (`image[]` once per picture), which curl's array form cannot do.
     * @param array<string, string|string[]> $fields a list value repeats the field, named as given (use `name[]`)
     * @param array<string, string[]> $files field name => local paths; several paths repeat the field
     * @param string[] $headers
     * @return array<string, mixed>
     */
    public function postMultipart(string $url, array $fields, array $files, array $headers, int $timeout = self::DEFAULT_TIMEOUT, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        $boundary = '----GnozioCMS' . bin2hex(random_bytes(12));
        $quote = static fn(string $value): string => str_replace(['"', "\r", "\n"], ['%22', '', ''], $value);
        $body = '';
        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                $body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $quote($name) . '"' . "\r\n\r\n" . $one . "\r\n";
            }
        }
        foreach ($files as $name => $paths) {
            foreach ($paths as $path) {
                $bytes = is_file($path) ? file_get_contents($path) : false;
                if ($bytes === false) {
                    return self::failure(self::BAD_REQUEST, 'A file to send is missing: ' . basename($path) . '.', 0, [], 0, 0);
                }
                $body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $quote($name) . '"; filename="' . $quote(basename($path)) . '"' . "\r\n"
                    . 'Content-Type: ' . self::mimeOf($path) . "\r\n\r\n" . $bytes . "\r\n";
            }
        }
        $body .= '--' . $boundary . "--\r\n";
        return $this->send('POST', $url, array_merge(['Content-Type: multipart/form-data; boundary=' . $boundary], $headers), $body, $timeout, $maxBytes);
    }

    /** @param string[] $headers @return array<string, mixed> */
    public function get(string $url, array $headers, int $timeout = 30, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        return $this->send('GET', $url, $headers, null, $timeout, $maxBytes);
    }

    /**
     * @param string[] $headers
     * @return array{ok: bool, status: int, data: array<string, mixed>|null, kind: string, error: string, retry_after: int|null,
     *   rate: array<string, string>, ms: int, bytes: int}
     *   `data` is the decoded JSON answer (also on most failures); `retry_after` in seconds when the provider said; `rate` the
     *   rate-limit headers it sent (names lower-case)
     */
    private function send(string $method, string $url, array $headers, ?string $body, int $timeout, int $maxBytes): array
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !in_array($host, array_map('strtolower', $this->hosts), true)
            || isset($parts['user']) || isset($parts['port']) && (int)$parts['port'] !== 443) {
            return self::failure(self::BAD_REQUEST, 'This address is not one of the provider\'s.', 0, [], 0, 0);
        }
        $started = hrtime(true);
        $answer = ($this->transport ?? self::curl(...))($method, $url, array_merge(['Accept: application/json', 'User-Agent: ' . self::AGENT], $headers), $body, $timeout, $maxBytes);
        $ms = (int)round((hrtime(true) - $started) / 1e6);
        $bytes = strlen($answer['body']);
        $responseHeaders = array_change_key_case($answer['headers'], CASE_LOWER);
        $rate = array_filter($responseHeaders, static fn(string $name): bool => str_contains($name, 'ratelimit'), ARRAY_FILTER_USE_KEY);

        if ($answer['error'] !== '') {
            return self::failure($answer['timed_out'] ? self::TIMEOUT : self::NETWORK, $this->scrub($answer['timed_out']
                ? 'The provider did not answer within ' . $timeout . ' seconds.'
                : 'The provider could not be reached: ' . $answer['error']), $answer['status'], $rate, $ms, $bytes);
        }
        $data = json_decode($answer['body'], true);
        $data = is_array($data) ? $data : null;
        $status = $answer['status'];
        if ($status >= 200 && $status < 300) {
            if ($data === null) {
                return self::failure(self::SERVER, 'The provider\'s answer was not JSON.', $status, $rate, $ms, $bytes);
            }
            return ['ok' => true, 'status' => $status, 'data' => $data, 'kind' => '', 'error' => '', 'retry_after' => null, 'rate' => $rate, 'ms' => $ms, 'bytes' => $bytes];
        }
        $kind = self::classify($status, $data);
        $result = self::failure($kind, $this->scrub(self::sentence($kind, self::providerMessage($data), $status)), $status, $rate, $ms, $bytes);
        $result['data'] = $data;
        $result['retry_after'] = self::retryAfter($responseHeaders);
        return $result;
    }

    /** The kind of a failed answer, from its status and the error object the provider sent (OpenAI, Anthropic and Google shapes). */
    public static function classify(int $status, ?array $data): string
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $codes = array_map(static fn($v): string => strtolower((string)$v), array_filter([
            $error['code'] ?? null, $error['type'] ?? null, $error['status'] ?? null,
        ], static fn($v): bool => is_string($v) || is_int($v)));
        $has = static fn(array $wanted): bool => array_intersect($codes, $wanted) !== [];

        if ($has(['insufficient_quota', 'billing_hard_limit_reached', 'credit_balance_too_low'])
            || ($status === 400 && str_contains(strtolower((string)($error['message'] ?? '')), 'credit balance is too low'))) {
            return self::NO_CREDIT;
        }
        $message = strtolower((string)($error['message'] ?? ''));
        // OpenAI's picture endpoints call every mistake in a request image_generation_user_error (an unknown model too); only the
        // message tells a safety refusal apart.
        if ($has(self::REFUSAL_CODES) || ($has(['image_generation_user_error']) && preg_match('/safety|moderation|rejected|policy/', $message) === 1)) {
            return self::REFUSED;
        }
        // Google answers a wrong key with 400 INVALID_ARGUMENT; its message is the only sign.
        if (str_contains($message, 'api key not valid')) {
            return self::AUTH;
        }
        return match (true) {
            $status === 401, $status === 403, $has(['invalid_api_key', 'authentication_error', 'permission_error', 'permission_denied', 'unauthenticated']) => self::AUTH,
            $status === 429, $has(['rate_limit_exceeded', 'rate_limit_error', 'resource_exhausted']) => self::RATE_LIMIT,
            $status === 408, $status === 504 => self::TIMEOUT,
            $status >= 500, $has(['overloaded_error', 'api_error', 'server_error', 'unavailable', 'internal']) => self::SERVER,
            default => self::BAD_REQUEST,
        };
    }

    /**
     * Seconds to wait from the answer's headers: Retry-After (seconds or a date), else OpenAI's x-ratelimit-reset-* ("1s", "6m0s",
     * "120ms"), the longest of them. Null when nothing says.
     * @param array<string, string> $headers names lower-case
     */
    public static function retryAfter(array $headers, ?int $now = null): ?int
    {
        $value = trim((string)($headers['retry-after'] ?? ''));
        if ($value !== '') {
            if (ctype_digit($value)) {
                return (int)$value;
            }
            $at = strtotime($value);
            if ($at !== false) {
                return max(0, $at - ($now ?? time()));
            }
        }
        $longest = null;
        foreach (['x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens'] as $name) {
            $seconds = self::duration((string)($headers[$name] ?? ''));
            if ($seconds !== null) {
                $longest = max($longest ?? 0, $seconds);
            }
        }
        return $longest;
    }

    /** "6m0s", "1.5s", "120ms", "1h2m" → whole seconds, rounded up; null when not a duration. */
    public static function duration(string $text): ?int
    {
        $text = trim($text);
        if ($text === '' || preg_match('/^(?:(\d+(?:\.\d+)?)h)?(?:(\d+(?:\.\d+)?)m(?!s))?(?:(\d+(?:\.\d+)?)s)?(?:(\d+(?:\.\d+)?)ms)?$/', $text, $m) !== 1) {
            return null;
        }
        $seconds = (float)($m[1] ?? 0) * 3600 + (float)($m[2] ?? 0) * 60 + (float)($m[3] ?? 0) + (float)($m[4] ?? 0) / 1000;
        return (int)ceil($seconds);
    }

    public static function isRetryable(string $kind): bool
    {
        return in_array($kind, self::RETRYABLE, true);
    }

    /** The message inside the provider's error object, cut to a sensible length; '' when there is none. */
    private static function providerMessage(?array $data): string
    {
        $message = $data['error']['message'] ?? ($data['message'] ?? '');
        $message = trim(preg_replace('/\s+/', ' ', is_string($message) ? $message : '') ?? '');
        return mb_strlen($message) > 300 ? mb_substr($message, 0, 297) . '…' : $message;
    }

    /** One plain sentence for the admin, with the provider's own words after it when it gave some. */
    private static function sentence(string $kind, string $message, int $status): string
    {
        $base = match ($kind) {
            self::NO_CREDIT => 'The provider account has no credit left.',
            self::RATE_LIMIT => 'The provider asked to slow down (rate limit).',
            self::REFUSED => 'The provider refused the request under its safety rules.',
            self::AUTH => 'The provider did not accept the key, or the key may not use this model.',
            self::TIMEOUT => 'The provider took too long to answer.',
            self::SERVER => 'The provider had a problem on its side (' . $status . ').',
            default => 'The provider did not accept the request (' . $status . ').',
        };
        return $message !== '' ? $base . ' It said: ' . $message : $base;
    }

    /** Every secret, and anything shaped like an API key, replaced by a mark. */
    private function scrub(string $text): string
    {
        foreach ($this->secrets as $secret) {
            if (strlen($secret) >= 8) {
                $text = str_replace($secret, '[key]', $text);
            }
        }
        return preg_replace('/\b(sk-[A-Za-z0-9_\-]{8,}|AIza[0-9A-Za-z_\-]{20,})/', '[key]', $text) ?? $text;
    }

    /** @param array<string, string> $rate @return array<string, mixed> */
    private static function failure(string $kind, string $error, int $status, array $rate, int $ms, int $bytes): array
    {
        return ['ok' => false, 'status' => $status, 'data' => null, 'kind' => $kind, 'error' => $error, 'retry_after' => null, 'rate' => $rate, 'ms' => $ms, 'bytes' => $bytes];
    }

    private static function mimeOf(string $path): string
    {
        return match (SafeHttp::imageKind($path)) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param string[] $headers
     * @return array{status: int, headers: array<string, string>, body: string, error: string, timed_out: bool}
     */
    private static function curl(string $method, string $url, array $headers, ?string $body, int $timeout, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'this server has no curl', 'timed_out' => false];
        }
        $received = '';
        $tooBig = false;
        $responseHeaders = [];
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $at = strpos($line, ':');
                if ($at !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $at)))] = trim(substr($line, $at + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$received, &$tooBig, $maxBytes): int {
                if (strlen($received) + strlen($data) > $maxBytes) {
                    $tooBig = true;
                    return 0;
                }
                $received .= $data;
                return strlen($data);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = '';
        if ($tooBig) {
            $error = 'the answer was larger than ' . (int)round($maxBytes / 1048576) . ' MB';
        } elseif ($errno !== 0) {
            $error = curl_error($ch) ?: 'error ' . $errno;
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $received, 'error' => $error, 'timed_out' => $errno === CURLE_OPERATION_TIMEDOUT];
    }
}
