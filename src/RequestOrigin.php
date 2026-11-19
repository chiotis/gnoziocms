<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the site believes about where a request came from: the visitor's address, whether it came over HTTPS and the host it
 * asked for. `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` are written by whoever sends the request, so they
 * count only when the request itself comes from a proxy the site trusts (Settings > General > Trusted proxies: addresses or
 * ranges such as 127.0.0.1 or 10.0.0.0/8; this machine by default). With none set, the site believes only the connection:
 * REMOTE_ADDR, HTTPS and Host.
 */
final class RequestOrigin
{
    /** @var list<string> */
    private array $trusted;

    /**
     * @param array<string, mixed> $server $_SERVER or a test's copy
     * @param list<string> $trusted addresses and ranges, as parseList() gives them
     */
    public function __construct(private array $server, array $trusted)
    {
        $this->trusted = $trusted;
    }

    /**
     * The entries of the setting, one per line or separated by commas or spaces; what is not an address or a range is left out.
     *
     * @return list<string>
     */
    public static function parseList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string)$value);
        $out = [];
        foreach ($items ?: [] as $item) {
            $item = trim((string)$item);
            if ($item !== '' && self::validEntry($item)) {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    public static function validEntry(string $entry): bool
    {
        $parts = explode('/', $entry, 2);
        $ip = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        return ctype_digit($parts[1]) && (int)$parts[1] <= $max;
    }

    /** Whether an address is one of the entries (an address or a range in CIDR form; IPv4 and IPv6). */
    public static function inList(string $ip, array $entries): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($entries as $entry) {
            [$net, $bits] = array_pad(explode('/', (string)$entry, 2), 2, null);
            $netPacked = @inet_pton((string)$net);
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int)$bits;
            $whole = intdiv($bits, 8);
            if (substr($packed, 0, $whole) !== substr($netPacked, 0, $whole)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$whole]) & $mask) === (ord($netPacked[$whole]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** Whether the connection itself comes from a trusted proxy. */
    public function viaTrustedProxy(): bool
    {
        return $this->trusted !== [] && self::inList($this->remoteAddress(), $this->trusted);
    }

    public function remoteAddress(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '');
    }

    /**
     * The visitor's address. Behind trusted proxies, `X-Forwarded-For` is read from the right (each proxy adds the address it
     * was reached from at the end) and the first address that is not a trusted proxy is the visitor's; whatever a visitor wrote
     * in the header themselves stays to the left of it and is never used.
     */
    public function clientIp(): string
    {
        $remote = $this->remoteAddress();
        if (!$this->viaTrustedProxy()) {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', (string)($this->server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::inList($ip, $this->trusted)) {
                return $ip;
            }
        }
        return $remote;
    }

    public function isHttps(): bool
    {
        $https = strtolower((string)($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string)($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (!$this->viaTrustedProxy()) {
            return false;
        }
        $proto = explode(',', (string)($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return strtolower(trim($proto[0])) === 'https';
    }

    /** The host asked for (`X-Forwarded-Host` behind a trusted proxy, otherwise `Host`), or "localhost" when it is not a host name. */
    public function host(): string
    {
        $host = '';
        if ($this->viaTrustedProxy()) {
            $host = trim(explode(',', (string)($this->server['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        }
        if ($host === '') {
            $host = trim((string)($this->server['HTTP_HOST'] ?? ''));
        }
        $host = strtolower($host);
        return preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(:\d{1,5})?$/', $host) === 1 ? $host : 'localhost';
    }
}
