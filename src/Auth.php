<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

final class Auth
{
    private string $usersFile;
    private ?UserRepository $users;

    public function __construct(string $usersFile, ?UserRepository $users = null)
    {
        $this->usersFile = $usersFile;
        $this->users = $users;
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
                if ($this->users->isActive($user) && $hash !== '' && password_verify($password, $hash)) {
                    $this->users->markLastLogin((int)$user['id']);
                    $sessionUser = $this->users->publicUser($user);
                    $sessionUser['last_login_at'] = gmdate('c');
                    $this->establishSession($sessionUser);
                    return true;
                }
                return false;
            }
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
        if ($this->users && isset($user['id'])) {
            $this->users->markLastLogin((int)$user['id']);
            $user = $this->users->publicUser($user);
            $user['last_login_at'] = gmdate('c');
        }
        $this->establishSession($user);
    }

    public function logout(): void
    {
        unset($_SESSION['user'], $_SESSION['security_default_password']);
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
        $id = (int)($current['id'] ?? 0);
        if ($id <= 0 || !$this->users || !$this->users->isAvailable()) {
            return true;
        }
        $fresh = $this->users->find($id);
        if (!$fresh || !$this->users->isActive($fresh)) {
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
