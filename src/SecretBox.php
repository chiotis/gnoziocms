<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Secrets kept encrypted in the database (audit S8): the payment providers' keys (ShopSecrets), the passwords and keys of Settings
 * (SMTP, SES, Google, S3, GitHub, YouTube) and the AI provider's key. They are encrypted with AES-256-GCM (OpenSSL, which the CMS
 * requires) under a key kept in a file of its own, `storage/key`: made on first use (mode 0600), never in a backup, never in an
 * update package. A backup alone opens nothing.
 *
 * A stored value is `gzenc:v1:` and base64(nonce, tag, ciphertext). A value without that start is one from before (plain text) and
 * is read as it is; it is encrypted the next time it is saved. A value that cannot be opened (the backup was restored on another
 * server, or the key file is gone) reads as empty, and lost() says so, so the screens ask for it again and nothing fails.
 */
final class SecretBox
{
    public const PREFIX = 'gzenc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private ?string $key = null;

    public function __construct(private string $keyFile)
    {
    }

    public static function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** The value encrypted; '' stays ''. Kept as it is when no key can be had (an unwritable storage/), so nothing is lost. */
    public function seal(string $value): string
    {
        if ($value === '' || self::isSealed($value)) {
            return $value;
        }
        $key = $this->key(true);
        if ($key === null) {
            return $value;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return $cipher === false ? $value : self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    /** The value in plain text: as it is when it was never sealed, '' when it cannot be opened. */
    public function open(string $value): string
    {
        if (!self::isSealed($value)) {
            return $value;
        }
        $key = $this->key(false);
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($key === null || $raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    /** Whether a stored value is sealed and cannot be opened here: it has to be entered again. */
    public function lost(string $value): bool
    {
        return self::isSealed($value) && $this->open($value) === '';
    }

    /** The key: read from its file, or made there when $make and there is none. */
    private function key(bool $make): ?string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (is_file($this->keyFile)) {
            $key = base64_decode(trim((string)@file_get_contents($this->keyFile)), true);
            return $this->key = ($key !== false && strlen($key) === 32 ? $key : null);
        }
        if (!$make) {
            return null;
        }
        $dir = dirname($this->keyFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }
        $key = random_bytes(32);
        // Made once: a second request that made one at the same time keeps the first one written.
        $handle = @fopen($this->keyFile, 'x');
        if ($handle === false) {
            return is_file($this->keyFile) ? $this->key(false) : null;
        }
        fwrite($handle, base64_encode($key) . "\n");
        fclose($handle);
        @chmod($this->keyFile, 0600);
        return $this->key = $key;
    }
}
