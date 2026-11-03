<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Downloads a file from an address someone else chose (an MCP job, docs/mcp.md) without letting that address reach the site's own
 * network: http or https on ports 80 and 443 only, no name and password in the address, every address the host resolves to must be
 * public, the connection goes to the address that was checked (so a DNS answer that changes in between cannot point it inward),
 * at most MAX_REDIRECTS redirects, each checked the same way, no cookies, and a size and a time limit.
 */
final class SafeHttp
{
    public const MAX_REDIRECTS = 3;
    private const AGENT = 'GnozioCMS (+https://github.com/chiotis/gnoziocms)';

    /** Ranges that filter_var's flags do not cover, or cover only in some PHP builds. */
    private const BLOCKED_V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

    /**
     * @param (\Closure(string): string[])|null $resolve a host's addresses (tests give their own)
     * @param (\Closure(string, string, string, int, int): array{status: int, location: string, error: string})|null $transport fetches one
     *   address, connecting to the given IP, into a file; no redirects followed (tests give their own)
     */
    public function __construct(private ?\Closure $resolve = null, private ?\Closure $transport = null)
    {
    }

    /** @return array{ok: bool, error: string, url: string} the address the file came from in the end */
    public function download(string $url, string $to, int $maxBytes, int $timeout = 15): array
    {
        $fail = static fn(string $error): array => ['ok' => false, 'error' => $error, 'url' => $url];
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                return $fail('Only http and https addresses can be fetched.');
            }
            if (isset($parts['user']) || isset($parts['pass'])) {
                return $fail('An address with a name and password cannot be fetched.');
            }
            if (!in_array($port, [80, 443], true)) {
                return $fail('Only the usual ports (80, 443) can be fetched.');
            }
            $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve ?? self::lookup(...))($host);
            if ($ips === []) {
                return $fail('The address ' . $host . ' could not be found.');
            }
            foreach ($ips as $ip) {
                if (!self::isPublic($ip)) {
                    return $fail('The address ' . $host . ' is on a private or reserved network and cannot be fetched.');
                }
            }
            $answer = ($this->transport ?? self::curl(...))($url, $ips[0], $to, $maxBytes, $timeout);
            if ($answer['error'] !== '') {
                return $fail($answer['error']);
            }
            if (in_array($answer['status'], [301, 302, 303, 307, 308], true) && $answer['location'] !== '') {
                $url = self::absolute($answer['location'], $url);
                continue;
            }
            if ($answer['status'] !== 200) {
                return $fail('The address answered ' . $answer['status'] . '.');
            }
            return ['ok' => true, 'error' => '', 'url' => $url];
        }
        return $fail('Too many redirects.');
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // An IPv4 address written as IPv6 (::ffff:10.0.0.1) is the IPv4 one.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as $range) {
                [$net, $bits] = explode('/', $range);
                $mask = -1 << (32 - (int)$bits);
                if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        $first = ord($bin[0]);
        // ::, ::1, IPv4-compatible, unique local (fc00::/7), link-local (fe80::/10), multicast (ff00::/8), documentation (2001:db8::/32).
        return !(trim(bin2hex(substr($bin, 0, 12)), '0') === '' || ($first & 0xFE) === 0xFC || ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)
            || $first === 0xFF || str_starts_with(bin2hex($bin), '20010db8'))
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** The file's kind from its first bytes: jpg, png, gif, webp or avif; '' for anything else. */
    public static function imageKind(string $path): string
    {
        $head = (string)@file_get_contents($path, false, null, 0, 16);
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a') => 'gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            in_array(substr($head, 4, 8), ['ftypavif', 'ftypavis'], true) => 'avif',
            default => '',
        };
    }

    /** @return string[] */
    private static function lookup(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = (string)$record['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    /** @return array{status: int, location: string, error: string} */
    private static function curl(string $url, string $ip, string $to, int $maxBytes, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'location' => '', 'error' => 'This server cannot fetch addresses (no curl).'];
        }
        $out = fopen($to, 'wb');
        if ($out === false) {
            return ['status' => 0, 'location' => '', 'error' => 'A temporary file could not be written.'];
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: (str_starts_with(strtolower($url), 'https') ? 443 : 80));
        $location = '';
        $written = 0;
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [trim($host, '[]') . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::AGENT,
            CURLOPT_HTTPHEADER => ['Accept: image/*'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($out, $maxBytes, &$written, &$tooBig): int {
                $written += strlen($data);
                if ($written > $maxBytes) {
                    $tooBig = true;
                    return 0;
                }
                return (int)fwrite($out, $data);
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) !== 0 && !$tooBig ? 'The address could not be fetched: ' . curl_error($ch) . '.' : '';
        fclose($out);
        if ($tooBig) {
            $error = 'The file is larger than ' . (int)round($maxBytes / 1048576) . ' MB.';
        }
        return ['status' => $status, 'location' => $location, 'error' => $error];
    }

    private static function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname((string)($parts['path'] ?? '/')), '/');
        return $origin . $dir . '/' . $location;
    }
}
