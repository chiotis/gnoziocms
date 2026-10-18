<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The two things an update asks of the network: fetching the package, and asking the site whether the new version
 * works. Both are plain functions so the installer can be given others in tests.
 */
final class UpdateNetwork
{
    public const CHECK_HEADER = 'X-Faros-Update-Check';
    private const CHECK_SECONDS = 20;
    private const REQUEST_SECONDS = 5;
    private const MAX_CHECK_BYTES = 2097152;

    /** Whether cURL can be used: hosts often switch off `curl_exec` while leaving the extension in place. */
    public static function hasCurl(): bool
    {
        foreach (['curl_init', 'curl_setopt_array', 'curl_exec', 'curl_error', 'curl_getinfo'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        return true;
    }

    /** Whether an address may be fetched: https, or http on this machine (for tests). */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        return $host !== '' && ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)));
    }

    /** Downloads an address into a file, stopping past a number of bytes. An error message, or null. */
    public static function download(string $url, string $destination, int $maxBytes): ?string
    {
        if (!self::isAllowedUrl($url)) {
            return 'The address is not allowed (it must be https).';
        }
        $out = @fopen($destination, 'wb');
        if ($out === false) {
            return 'The file could not be written.';
        }
        $written = 0;
        $error = null;
        if (self::hasCurl()) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => 'GnozioCMS updater',
                CURLOPT_FAILONERROR => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                // A redirect may only go to https, unless the address itself is on this machine (tests).
                CURLOPT_REDIR_PROTOCOLS => str_starts_with(strtolower($url), 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($out, $maxBytes, &$written): int {
                    $written += strlen($chunk);
                    return $written > $maxBytes ? 0 : (int)fwrite($out, $chunk);
                },
            ]);
            if (curl_exec($ch) === false) {
                $error = $written > $maxBytes ? 'The file is larger than the package should be.' : curl_error($ch);
            }
            unset($ch);
        } else {
            $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'GnozioCMS updater']]));
            if ($in === false) {
                $error = 'The address could not be reached (cURL is not available here and PHP may not be allowed to open web addresses: allow_url_fopen).';
            } else {
                while (!feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        $error = 'The download was interrupted.';
                        break;
                    }
                    $written += strlen($chunk);
                    if ($written > $maxBytes) {
                        $error = 'The file is larger than the package should be.';
                        break;
                    }
                    fwrite($out, $chunk);
                }
                fclose($in);
            }
        }
        fclose($out);
        if ($error !== null) {
            @unlink($destination);
        }
        return $error;
    }

    /**
     * A short, read-only smoke check of the login, home, one known published entry and the sitemap.
     * A fresh challenge proves the expected version answered instead of an intermediary's cached page.
     * Inconclusive requests keep the install unverified; a definite bad response still triggers rollback.
     *
     * @return array{reached: bool, ok: bool, complete: bool, message: string}
     */
    public static function checkSite(string $baseUrl, string $token, string $version = '', ?string $contentPath = null, bool $sitemapEnabled = true): array
    {
        $checks = ['/admin/login' => [200], '/' => [200, 404]];
        if ($contentPath !== null && str_starts_with($contentPath, '/') && !str_starts_with($contentPath, '//') && !preg_match('/[?#\r\n]/', $contentPath)) {
            $checks[$contentPath] = [200];
        }
        $checks['/sitemap.xml'] = [$sitemapEnabled ? 200 : 404];
        $deadline = microtime(true) + self::CHECK_SECONDS;
        $reached = false;
        $problems = [];
        $unknown = [];
        foreach ($checks as $path => $statuses) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $unknown[] = $path . ' was not checked (time limit)';
                continue;
            }
            $nonce = bin2hex(random_bytes(16));
            [$status, $body, $proof] = self::get(rtrim($baseUrl, '/') . $path . '?_faros_check=' . $nonce, $token, min(self::REQUEST_SECONDS, $remaining));
            if ($status === 0) {
                $unknown[] = $path . ' could not be checked (no complete response)';
                continue;
            }
            $reached = true;
            if ($status >= 500) {
                $problems[] = $path . ' answered ' . $status;
            } elseif (preg_match('/(?:^|<br \/>\s*|<b>)\s*(?:PHP )?(?:Fatal error|Parse error)(?:<\/b>)?:/mi', $body)) {
                $problems[] = $path . ' shows a PHP error';
            } elseif ($status >= 300 && $status < 400) {
                $unknown[] = $path . ' redirected (not followed by the check)';
            } elseif ($version !== '' && !hash_equals(hash_hmac('sha256', $version . '|' . $nonce, $token), $proof)) {
                $unknown[] = $path . ' did not confirm version ' . $version . ' (cache, redirect or access restriction)';
            } elseif (!in_array($status, $statuses, true)) {
                $problems[] = $path . ' answered ' . $status;
            } elseif ($status === 200 && trim($body) === '') {
                $problems[] = $path . ' returned an empty page';
            } elseif ($path === '/sitemap.xml' && $status === 200 && !preg_match('/<(urlset|sitemapindex)\b[^>]*(?:\/>|>[\s\S]*<\/\1\s*>)/', $body)) {
                $problems[] = $path . ' did not return a sitemap';
            }
        }
        if (!$reached) {
            return ['reached' => false, 'ok' => false, 'complete' => false, 'message' => 'no answer from ' . $baseUrl];
        }
        return ['reached' => true, 'ok' => $problems === [], 'complete' => $unknown === [],
            'message' => $problems === [] && $unknown === [] ? 'Checked ' . implode(', ', array_keys($checks)) : implode('; ', array_merge($problems, $unknown))];
    }

    /** @return array{0: int, 1: string, 2: string} status (0 for an incomplete response), body, fresh-version proof */
    private static function get(string $url, string $token, float $timeout): array
    {
        $headers = [MaintenanceMode::HEADER . ': ' . $token, 'Accept: text/html, application/xml', 'Cache-Control: no-cache, no-store'];
        $proof = '';
        if (self::hasCurl()) {
            $body = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)), CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)($timeout * 1000)), CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'GnozioCMS updater',
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$proof): int {
                    if (stripos($line, self::CHECK_HEADER . ':') === 0) {
                        $proof = trim(substr($line, strlen(self::CHECK_HEADER) + 1));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_CHECK_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);
            return [$ok !== false ? $status : 0, $body, $proof];
        }
        $deadline = microtime(true) + $timeout;
        $stream = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'header' => implode("\r\n", $headers) . "\r\n"]]));
        if ($stream === false) {
            return [0, '', ''];
        }
        $status = 0;
        $length = null;
        $chunked = false;
        foreach (stream_get_meta_data($stream)['wrapper_data'] ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                $length = null;
                $chunked = false;
                $proof = '';
            } elseif (preg_match('/^Content-Length:\s*(\d+)\s*$/i', $line, $m)) {
                $length = (int)$m[1];
            } elseif (preg_match('/^Transfer-Encoding:.*\bchunked\b/i', $line)) {
                $chunked = true;
            } elseif (stripos($line, self::CHECK_HEADER . ':') === 0) {
                $proof = trim(substr($line, strlen(self::CHECK_HEADER) + 1));
            }
        }
        $body = '';
        while (!feof($stream)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || strlen($body) > self::MAX_CHECK_BYTES) {
                $status = 0;
                break;
            }
            stream_set_timeout($stream, (int)$remaining, (int)(($remaining - (int)$remaining) * 1000000));
            $chunk = fread($stream, min(65536, self::MAX_CHECK_BYTES + 1 - strlen($body)));
            if ($chunk === false || stream_get_meta_data($stream)['timed_out']) {
                $status = 0;
                break;
            }
            $body .= $chunk;
        }
        fclose($stream);
        $complete = strlen($body) <= self::MAX_CHECK_BYTES && ($chunked || $length === null || strlen($body) === $length);
        return [$complete ? $status : 0, $body, $proof];
    }
}
