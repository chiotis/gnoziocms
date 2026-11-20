<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Time-based one-time passwords (RFC 6238, with the defaults every authenticator app uses: HMAC-SHA1, 6 digits, 30 seconds), for
 * the admin's second factor (SecondFactor). The secret is 20 random bytes, shown to the person as base32 (RFC 4648) and in an
 * `otpauth://` address for the QR code.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** A code is taken from the step before and the step after too, for a phone whose clock is a little off. */
    public const DRIFT = 1;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret, in base32. */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /** The code of a step for a secret in base32; '' when the secret is not base32. */
    public static function code(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secret);
        if ($key === null || $key === '') {
            return '';
        }
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The step a code was made for, when it is right at $time and newer than $lastStep (a code that was used once is never taken
     * again); null otherwise. Spaces and dashes in what was typed are ignored.
     */
    public static function verify(string $secret, string $code, int $time, int $lastStep = 0): ?int
    {
        $code = preg_replace('/[\s-]+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $now = self::step($time);
        $found = null;
        for ($step = $now - self::DRIFT; $step <= $now + self::DRIFT; $step++) {
            // Every step is compared, so the time taken says nothing about which one matched.
            if (hash_equals(self::code($secret, $step), $code) && $step > $lastStep && $found === null) {
                $found = $step;
            }
        }
        return $found;
    }

    /** The address an authenticator app reads from the QR code: `otpauth://totp/Issuer:account?secret=…&issuer=Issuer`. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $issuer = trim(str_replace(':', '', $issuer)) ?: 'GnozioCMS';
        $account = trim(str_replace(':', '', $account));
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $bytes === '' ? '' : $out;
    }

    /** The bytes of a base32 text (upper or lower case, spaces and `=` ignored); null when it is not base32. */
    public static function base32Decode(string $text): ?string
    {
        $text = strtoupper((string)preg_replace('/[\s=]+/', '', $text));
        if ($text !== '' && strspn($text, self::ALPHABET) !== strlen($text)) {
            return null;
        }
        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $text === '' ? '' : $out;
    }
}
