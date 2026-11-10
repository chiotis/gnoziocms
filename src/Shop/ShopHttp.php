<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

/**
 * Calls a payment provider's API (docs/shop-plan.md, section 5): only the hosts the provider is given, over https; JSON or a form
 * in, JSON out, short time limits. Secrets travel only in the headers the caller passes and are cut out of every error. A test may
 * point a provider at a fake on this machine (http://127.0.0.1:port); nothing else is ever called over plain http.
 */
final class ShopHttp
{
    private const AGENT = 'GnozioCMS shop (+https://github.com/chiotis/gnoziocms)';

    /**
     * @param string[] $hosts the only hosts this client may call
     * @param string[] $secrets cut out of every message
     * @param (\Closure(string, string, string[], ?string, int): array{status: int, body: string, error: string})|null $transport
     */
    public function __construct(private array $hosts, private array $secrets = [], private ?\Closure $transport = null)
    {
    }

    /**
     * @param string[] $headers
     * @param array<string, mixed>|null $json
     * @param array<string, string>|null $form
     * @return array{ok: bool, status: int, data: array<string, mixed>|null, error: string}
     */
    public function send(string $method, string $url, array $headers = [], ?array $json = null, ?array $form = null, int $timeout = 30): array
    {
        if (!$this->allowed($url)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'This address is not one of the provider\'s.'];
        }
        $body = null;
        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        } elseif ($form !== null) {
            $body = http_build_query($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $answer = ($this->transport ?? self::curl(...))($method, $url, array_merge(['Accept: application/json', 'User-Agent: ' . self::AGENT], $headers), $body, $timeout);
        if ($answer['error'] !== '') {
            return ['ok' => false, 'status' => $answer['status'], 'data' => null, 'error' => $this->scrub('The provider could not be reached: ' . $answer['error'])];
        }
        $data = json_decode($answer['body'], true);
        $data = is_array($data) ? $data : null;
        $ok = $answer['status'] >= 200 && $answer['status'] < 300;
        $said = '';
        foreach (is_array($data) ? ['message', 'Message', 'ErrorText', 'error_description', 'error'] : [] as $field) {
            // Meta's Graph API puts its message under error.message.
            $value = is_array($data[$field] ?? null) ? ($data[$field]['message'] ?? null) : ($data[$field] ?? null);
            if (is_scalar($value) && (string)$value !== '') {
                $said = (string)$value;
                break;
            }
        }
        return [
            'ok' => $ok && ($data !== null || $answer['body'] === ''),
            'status' => $answer['status'],
            'data' => $data,
            'error' => $ok ? ($data === null && $answer['body'] !== '' ? 'The provider\'s answer was not JSON.' : '') : $this->scrub('The provider answered ' . $answer['status'] . ($said !== '' ? ': ' . mb_substr($said, 0, 200) : '.')),
        ];
    }

    /**
     * A body sent as it is (Cardlink's XML), the answer's text back as it came.
     *
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    public function raw(string $method, string $url, string $body, string $contentType, int $timeout = 30): array
    {
        if (!$this->allowed($url)) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'This address is not one of the provider\'s.'];
        }
        $answer = ($this->transport ?? self::curl(...))($method, $url, ['Accept: application/xml', 'User-Agent: ' . self::AGENT, 'Content-Type: ' . $contentType], $body, $timeout);
        if ($answer['error'] !== '') {
            return ['ok' => false, 'status' => $answer['status'], 'body' => '', 'error' => $this->scrub('The provider could not be reached: ' . $answer['error'])];
        }
        $ok = $answer['status'] >= 200 && $answer['status'] < 300;
        return ['ok' => $ok, 'status' => $answer['status'], 'body' => $answer['body'], 'error' => $ok ? '' : 'The provider answered ' . $answer['status'] . '.'];
    }

    /** Whether an address may be called: https on one of the hosts, or a fake on this machine. */
    public function allowed(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (isset($parts['user'])) {
            return false;
        }
        if ($scheme === 'http' && self::isLocal($host)) {
            return in_array($host, array_map('strtolower', $this->hosts), true);
        }
        return $scheme === 'https' && in_array($host, array_map('strtolower', $this->hosts), true) && (!isset($parts['port']) || (int)$parts['port'] === 443);
    }

    public static function isLocal(string $host): bool
    {
        return in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    public static function basic(string $user, string $password): string
    {
        return 'Authorization: Basic ' . base64_encode($user . ':' . $password);
    }

    private function scrub(string $text): string
    {
        foreach ($this->secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace([$secret, base64_encode($secret)], '[secret]', $text);
            }
        }
        return $text;
    }

    /** @param string[] $headers @return array{status: int, body: string, error: string} */
    private static function curl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            return self::stream($method, $url, $headers, $body, $timeout);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $error = $response === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $error];
    }

    /**
     * The same through PHP's own streams, where a host has switched curl off.
     *
     * @param string[] $headers
     * @return array{status: int, body: string, error: string}
     */
    private static function stream(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!(bool)ini_get('allow_url_fopen')) {
            return ['status' => 0, 'body' => '', 'error' => 'PHP can call no other site (no curl, allow_url_fopen off).'];
        }
        $context = stream_context_create([
            'http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $lines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($lines[0] ?? ''), $m) === 1 ? (int)$m[1] : 0;
        if ($response === false && $status === 0) {
            return ['status' => 0, 'body' => '', 'error' => (string)(error_get_last()['message'] ?? 'no answer')];
        }
        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => ''];
    }
}
