<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * The admin's second factor (audit S9b; docs/security.md, *Second factor*): a code from an authenticator app (Totp), asked after the
 * password. Kept per person in `user_second_factors`: the secret sealed by SecretBox (so a backup alone opens nothing), ten
 * recovery codes as hashes (each works once), and the last step a code was taken for (so a code seen over a shoulder is no good
 * a second time).
 *
 * The super admin can require it for roles (Users > Roles); a person of such a role who signed in with a password and has none
 * is taken to set it up first. Signing in with Google is not asked for a code: Google has its own.
 */
final class SecondFactor
{
    public const RECOVERY_CODES = 10;
    /** The setting (system_meta) that lists the roles that must have one. */
    public const REQUIRED_ROLES = 'second_factor_roles';

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private SystemDatabase $database, private SecretBox $box, private SystemMetaRepository $meta)
    {
        $this->clock = static fn(): int => time();
    }

    /** For tests: what time it is. @param \Closure(): int $clock */
    public function setClock(\Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    public function enabled(int $userId): bool
    {
        return $this->row($userId) !== null;
    }

    /**
     * What the screens show of a person's second factor.
     *
     * @return array{enabled: bool, enabled_at: string, recovery_left: int, lost: bool} `lost`: the secret cannot be opened on this
     *         server (a backup restored elsewhere, a lost storage/key), so only a recovery code works until it is set up again
     */
    public function status(int $userId): array
    {
        $row = $this->row($userId);
        if ($row === null) {
            return ['enabled' => false, 'enabled_at' => '', 'recovery_left' => 0, 'lost' => false];
        }
        return ['enabled' => true, 'enabled_at' => (string)$row['enabled_at'], 'recovery_left' => count($this->hashes($row)), 'lost' => $this->box->lost((string)$row['secret'])];
    }

    /**
     * Turns it on with a secret the person's app has (checked with a code first, by the caller) and gives the recovery codes, to
     * be shown once.
     *
     * @return list<string>
     */
    public function enable(int $userId, string $secret, int $step = 0): array
    {
        $codes = self::newRecoveryCodes();
        $stmt = $this->pdo()->prepare(
            'INSERT INTO user_second_factors (user_id, secret, recovery, last_step, enabled_at) VALUES (:id, :secret, :recovery, :step, :at)
             ON CONFLICT(user_id) DO UPDATE SET secret = excluded.secret, recovery = excluded.recovery, last_step = excluded.last_step, enabled_at = excluded.enabled_at'
        );
        $stmt->execute(['id' => $userId, 'secret' => $this->box->seal($secret), 'recovery' => json_encode(array_map(self::hash(...), $codes)), 'step' => $step, 'at' => gmdate('c', $this->now())]);
        return $codes;
    }

    /** New recovery codes in place of the ones left. @return list<string> */
    public function renewRecoveryCodes(int $userId): array
    {
        if (!$this->enabled($userId)) {
            return [];
        }
        $codes = self::newRecoveryCodes();
        $stmt = $this->pdo()->prepare('UPDATE user_second_factors SET recovery = :recovery WHERE user_id = :id');
        $stmt->execute(['id' => $userId, 'recovery' => json_encode(array_map(self::hash(...), $codes))]);
        return $codes;
    }

    public function disable(int $userId): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM user_second_factors WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Checks what was typed: a code from the app, or one of the recovery codes (which is then used up). 'app', 'recovery', or ''
     * when it is neither.
     */
    public function verify(int $userId, string $typed): string
    {
        $row = $this->row($userId);
        if ($row === null) {
            return '';
        }
        $secret = $this->box->open((string)$row['secret']);
        if ($secret !== '') {
            $step = Totp::verify($secret, $typed, $this->now(), (int)$row['last_step']);
            if ($step !== null) {
                // Only when the step is still newer: two requests with the same code cannot both pass.
                $stmt = $this->pdo()->prepare('UPDATE user_second_factors SET last_step = :step WHERE user_id = :id AND last_step < :step');
                $stmt->execute(['id' => $userId, 'step' => $step]);
                return $stmt->rowCount() === 1 ? 'app' : '';
            }
        }
        $hashes = $this->hashes($row);
        $typedHash = self::hash($typed);
        foreach ($hashes as $i => $hash) {
            if (hash_equals($hash, $typedHash)) {
                unset($hashes[$i]);
                $stmt = $this->pdo()->prepare('UPDATE user_second_factors SET recovery = :recovery WHERE user_id = :id AND recovery = :before');
                $stmt->execute(['id' => $userId, 'recovery' => json_encode(array_values($hashes)), 'before' => (string)$row['recovery']]);
                return $stmt->rowCount() === 1 ? 'recovery' : '';
            }
        }
        return '';
    }

    /** @return list<string> the keys of the roles that must have a second factor */
    public function requiredRoles(): array
    {
        if (!$this->meta->isAvailable()) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $this->meta->getJson(self::REQUIRED_ROLES) ?? []), static fn(string $role): bool => $role !== ''));
    }

    /** @param list<string> $roles */
    public function setRequiredRoles(array $roles): void
    {
        $this->meta->setJson(self::REQUIRED_ROLES, array_values(array_unique(array_map('strval', $roles))));
    }

    public function requiredFor(?array $user): bool
    {
        return is_array($user) && in_array((string)($user['role'] ?? ''), $this->requiredRoles(), true);
    }

    /**
     * Whether this session has to set one up before anything else: the person's role requires it, they have none, and they signed
     * in with a password (not Google; a session from before this has no method and counts as a password one).
     */
    public function mustSetUp(?array $user, string $method): bool
    {
        $id = (int)($user['id'] ?? 0);
        return $id > 0 && $method !== 'google' && $this->isAvailable() && $this->requiredFor($user) && !$this->enabled($id);
    }

    /** Ten codes of ten letters and digits (about 50 bits each), as `xxxxx-xxxxx`. @return list<string> */
    public static function newRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        while (count($codes) < self::RECOVERY_CODES) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }
        return $codes;
    }

    /** A recovery code's hash: what was typed, without spaces or dashes and in lower case. They are random, so SHA-256 is enough. */
    private static function hash(string $code): string
    {
        return hash('sha256', strtolower((string)preg_replace('/[\s-]+/', '', $code)));
    }

    /** @return array<string, mixed>|null */
    private function row(int $userId): ?array
    {
        if ($userId <= 0 || !$this->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM user_second_factors WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row @return list<string> */
    private function hashes(array $row): array
    {
        $list = json_decode((string)($row['recovery'] ?? '[]'), true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
