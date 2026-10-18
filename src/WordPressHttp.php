<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The web as the WordPress import needs it: read an address, or save one into a file. Only http and https, redirects
 * followed a few times, and a limit on how much one answer may be.
 */
final class WordPressHttp
{
    private const AGENT = 'GnozioCMS-import (+https://github.com/chiotis/gnoziocms)';

    /** @return array{status: int, body: string}|null null when the site could not be reached */
    public static function get(string $url, int $maxBytes = 20 * 1024 * 1024): ?array
    {
        $file = tempnam(sys_get_temp_dir(), 'wpget');
        if ($file === false) {
            return null;
        }
        try {
            $status = self::fetch($url, $file, $maxBytes);
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

    private static function fetch(string $url, string $to, int $maxBytes): ?int
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        // Letters outside ASCII and spaces in the address are sent percent-encoded, as a browser would.
        $url = preg_replace_callback('/[^\x21-\x7e]/u', static fn(array $m): string => rawurlencode($m[0]), $url) ?? $url;
        if (UpdateNetwork::hasCurl()) {
            $out = fopen($to, 'wb');
            if ($out === false) {
                return null;
            }
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FILE => $out,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => self::AGENT,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static fn($r, int $total, int $got): int => $got > $maxBytes ? 1 : 0,
            ]);
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            unset($curl);
            fclose($out);
            return $ok === false ? null : $status;
        }
        $context = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => true, 'user_agent' => self::AGENT]]);
        $in = @fopen($url, 'rb', false, $context);
        if ($in === false) {
            return null;
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int)$m[1];
            }
        }
        $out = fopen($to, 'wb');
        $written = 0;
        while ($out !== false && !feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            if ($written > $maxBytes) {
                fclose($in);
                fclose($out);
                return null;
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        if ($out !== false) {
            fclose($out);
        }
        return $status ?: null;
    }
}
