<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Signed releases: the release manifest (release.json) carries an Ed25519 signature made with a key that is kept off
 * GitHub, on the maintainer's own computer. A site checks it against the public keys below before it installs anything,
 * so someone who gets into the GitHub account or the release workflow can publish a package but cannot make sites
 * install it.
 *
 * What is signed is the manifest's meaning, not its bytes: one line each for the version, the lowest PHP, the package's
 * address, its SHA-256, its size and whether a backup is required (see message()). The SHA-256 ties the signature to the
 * package itself. The signatures sit in the manifest as "signatures": [{"key": "<key id>", "sig": "<base64>"}], which
 * older sites ignore.
 *
 * While PUBLIC_KEYS is empty nothing is required: a signature cannot be checked, and an unsigned manifest is accepted as
 * before. Once a key is listed here, every manifest of the official repository must carry a valid signature by one of
 * the keys, or the release is not offered. The release that first lists a key is installed by older sites without a
 * check, which is how sites move over. To replace a key, list the new one beside the old one in a release signed by the
 * old one, then remove the old one in a later release. installer.php keeps a copy of the same list (RELEASE_KEYS).
 *
 * scripts/sign-release.php makes a key, signs a release and checks one; docs/signed-releases.md says how.
 */
final class ReleaseSignature
{
    /** Base64 Ed25519 public keys whose signatures a release of the official repository must carry (one of them). */
    public const PUBLIC_KEYS = [];

    /** The first line of what is signed, so a signature over anything else can never pass for one over a release. */
    public const CONTEXT = 'gnoziocms-release-v1';

    /** Signed by a listed key. */
    public const VERIFIED = 'verified';
    /** No signature, or none by a key this code knows. */
    public const UNSIGNED = 'unsigned';
    /** A signature by a listed key that does not match: the manifest was changed after it was signed. */
    public const INVALID = 'invalid';
    /** Signatures cannot be checked on this server (PHP without sodium). */
    public const UNAVAILABLE = 'unavailable';

    /**
     * The text that is signed, made from a manifest as release.json has it (before or after validation).
     *
     * @param array<string, mixed> $manifest
     */
    public static function message(array $manifest): string
    {
        $backup = ($manifest['requires_backup'] ?? true) !== false;
        return implode("\n", [
            self::CONTEXT,
            (string)($manifest['version'] ?? ''),
            (string)($manifest['min_php'] ?? ''),
            (string)($manifest['package_url'] ?? ''),
            strtolower((string)($manifest['sha256'] ?? '')),
            (string)(int)($manifest['size'] ?? 0),
            $backup ? 'backup' : 'no-backup',
        ]) . "\n";
    }

    /** A short name for a public key: the first 16 hex digits of its SHA-256. */
    public static function keyId(string $publicKeyBase64): string
    {
        $raw = base64_decode($publicKeyBase64, true);
        return $raw === false ? '' : substr(hash('sha256', $raw), 0, 16);
    }

    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Whether the manifest is signed by one of the keys: verified, unsigned, invalid or unavailable.
     *
     * @param array<string, mixed> $manifest the manifest as published, with its signatures
     * @param list<string> $publicKeys base64 Ed25519 public keys
     */
    public static function check(array $manifest, array $publicKeys): string
    {
        $known = [];
        foreach ($publicKeys as $key) {
            $raw = base64_decode((string)$key, true);
            if ($raw !== false && strlen($raw) === 32) {
                $known[self::keyId((string)$key)] = $raw;
            }
        }
        $signatures = is_array($manifest['signatures'] ?? null) ? $manifest['signatures'] : [];
        $ours = [];
        foreach ($signatures as $signature) {
            $id = is_array($signature) ? (string)($signature['key'] ?? '') : '';
            if ($id !== '' && isset($known[$id])) {
                $ours[] = [$known[$id], (string)($signature['sig'] ?? '')];
            }
        }
        if ($ours === []) {
            return self::UNSIGNED;
        }
        if (!self::available()) {
            return self::UNAVAILABLE;
        }
        $message = self::message($manifest);
        foreach ($ours as [$publicKey, $sig]) {
            $raw = base64_decode($sig, true);
            if ($raw !== false && strlen($raw) === 64 && sodium_crypto_sign_verify_detached($raw, $message, $publicKey)) {
                return self::VERIFIED;
            }
        }
        return self::INVALID;
    }

    /**
     * The manifest with a signature by this secret key added (one made earlier by the same key is replaced).
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function sign(array $manifest, string $secretKeyBase64): array
    {
        $secret = base64_decode($secretKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('That is not an Ed25519 secret key.');
        }
        $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        $id = self::keyId($public);
        $signatures = array_values(array_filter(
            is_array($manifest['signatures'] ?? null) ? $manifest['signatures'] : [],
            static fn($s): bool => is_array($s) && (string)($s['key'] ?? '') !== $id
        ));
        $signatures[] = ['key' => $id, 'sig' => base64_encode(sodium_crypto_sign_detached(self::message($manifest), $secret))];
        $manifest['signatures'] = $signatures;
        return $manifest;
    }

    /** @return array{public: string, secret: string} a new key pair, base64 */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /** The public key of a base64 secret key. */
    public static function publicKeyOf(string $secretKeyBase64): string
    {
        $secret = base64_decode($secretKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('That is not an Ed25519 secret key.');
        }
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    }
}
