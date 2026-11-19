<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

final class Auth
{
    /** An admin session ends after this long without use unless Settings > General says otherwise (Settings: admin_idle_minutes). */
    public const IDLE_MINUTES = 120;
    public const IDLE_MIN = 10;
    public const IDLE_MAX = 1440;
    /** And this long after signing in, whatever happens. */
    public const ABSOLUTE_SECONDS = 86400;
    /** The session key that says why the last session ended ('idle' or 'absolute'), for the sign-in page. */
    public const ENDED = 'auth_ended';

    private string $usersFile;
    private ?UserRepository $users;
    private int $idleSeconds = self::IDLE_MINUTES * 60;
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(string $usersFile, ?UserRepository $users = null)
    {
        $this->usersFile = $usersFile;
        $this->users = $users;
        $this->clock = static fn(): int => time();
    }

    /** The time without use after which a session ends, in minutes (kept between IDLE_MIN and IDLE_MAX). */
    public function setIdleMinutes(int $minutes): void
    {
        $this->idleSeconds = self::idleMinutes($minutes) * 60;
    }

    public static function idleMinutes(mixed $value): int
    {
        $minutes = is_numeric($value) ? (int)$value : self::IDLE_MINUTES;
        return max(self::IDLE_MIN, min(self::IDLE_MAX, $minutes));
    }

    /** For tests: what time it is. @param \Closure(): int $clock */
    public function setClock(\Closure $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * Whether the signed-in session has ended: 'idle' after the time without use, 'absolute' 24 hours after signing in, else ''.
     * A session from before these limits has no times yet: it starts counting now, so an update signs nobody out.
     */
    public function ended(): string
    {
        if (!isset($_SESSION['user'])) {
            return '';
        }
        $now = ($this->clock)();
        $started = (int)($_SESSION['auth_started'] ?? 0);
        $seen = (int)($_SESSION['auth_seen'] ?? 0);
        if ($started <= 0 || $seen <= 0) {
            $_SESSION['auth_started'] = $started > 0 ? $started : $now;
            $_SESSION['auth_seen'] = $now;
            return '';
        }
        if ($now - $started >= self::ABSOLUTE_SECONDS) {
            return 'absolute';
        }
        if ($now - $seen >= $this->idleSeconds) {
            return 'idle';
        }
        return '';
    }

    public function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function attempt(string $username, string $password): bool
    {
        if ($this->users && $this->users->isAvailable()) {
            $user = $this->users->findForLogin($username);
            if ($user) {
                $hash = (string)($user['password_hash'] ?? '');
                if ($this->users->isActive($user) && !UserRepository::isAutomation($user) && $hash !== '' && password_verify($password, $hash)) {
                    $this->users->markLastLogin((int)$user['id']);
                    $sessionUser = $this->users->publicUser($user);
                    $sessionUser['last_login_at'] = gmdate('c');
                    $this->establishSession($sessionUser);
                    return true;
                }
                return false;
            }
            // The database knows every account once it is there (users.yaml is imported into it): a name it does not have is not
            // one that signs in, whatever an old users.yaml still says about a renamed, demoted or deleted account.
            return false;
        }

        $users = $this->loadUsers();
        foreach ($users as $user) {
            if (($user['username'] ?? '') !== $username) {
                continue;
            }

            $hash = (string)($user['password'] ?? '');
            if (str_starts_with($hash, 'plain:')) {
                if (hash_equals(substr($hash, 6), $password)) {
                    $this->establishSession($this->withoutPassword($user));
                    return true;
                }
                return false;
            }

            if ($hash !== '' && password_verify($password, $hash)) {
                $this->establishSession($this->withoutPassword($user));
                return true;
            }
            return false;
        }
        return false;
    }

    public function loginUser(array $user): void
    {
        // An automation account is never a signed-in person, whatever leads here.
        if (UserRepository::isAutomation($user)) {
            return;
        }
        if ($this->users && isset($user['id'])) {
            $this->users->markLastLogin((int)$user['id']);
            $user = $this->users->publicUser($user);
            $user['last_login_at'] = gmdate('c');
        }
        $this->establishSession($user);
    }

    public function logout(): void
    {
        unset($_SESSION['user'], $_SESSION['security_default_password'], $_SESSION['auth_started'], $_SESSION['auth_seen']);
        $this->rotateSession();
    }

    /**
     * Re-reads the signed-in user so role changes and deactivations apply immediately
     * instead of waiting for the session to expire. Returns false when the user lost access.
     */
    public function refresh(): bool
    {
        $current = $_SESSION['user'] ?? null;
        if (!is_array($current)) {
            return false;
        }
        $ended = $this->ended();
        if ($ended !== '') {
            $this->logout();
            $_SESSION[self::ENDED] = $ended;
            return false;
        }
        $_SESSION['auth_seen'] = ($this->clock)();
        if (!$this->users || !$this->users->isAvailable()) {
            return true;
        }
        $id = (int)($current['id'] ?? 0);
        if ($id <= 0) {
            // A session from users.yaml, from before the database was there: it has no account to keep up with.
            $this->logout();
            return false;
        }
        $fresh = $this->users->find($id);
        if (!$fresh || !$this->users->isActive($fresh) || UserRepository::isAutomation($fresh)) {
            $this->logout();
            return false;
        }
        $user = $this->users->publicUser($fresh);
        $user['last_login_at'] = (string)($current['last_login_at'] ?? ($fresh['last_login_at'] ?? ''));
        $_SESSION['user'] = $user;
        return true;
    }

    /** True when the password is still the plain-text one shipped in content/users/users.yaml. */
    public function isShippedDefaultPassword(string $username, string $password): bool
    {
        foreach ($this->loadUsers() as $user) {
            if (!is_array($user) || strcasecmp((string)($user['username'] ?? ''), $username) !== 0) {
                continue;
            }
            $stored = (string)($user['password'] ?? '');
            return str_starts_with($stored, 'plain:') && hash_equals(substr($stored, 6), $password);
        }
        return false;
    }

    private function establishSession(array $user): void
    {
        // A fresh session id on privilege change prevents session fixation.
        $this->rotateSession();
        $_SESSION['user'] = $user;
        $now = ($this->clock)();
        $_SESSION['auth_started'] = $now;
        $_SESSION['auth_seen'] = $now;
        unset($_SESSION[self::ENDED]);
    }

    private function rotateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf_token']);
    }

    private function withoutPassword(array $user): array
    {
        unset($user['password']);
        return $user;
    }

    /** @return array<int, array<string, mixed>> */
    public function loadUsers(): array
    {
        if (!file_exists($this->usersFile)) {
            return [];
        }
        $data = Yaml::parseFile($this->usersFile);
        return is_array($data) ? $data : [];
    }
}
