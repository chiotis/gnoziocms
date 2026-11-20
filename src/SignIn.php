<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Signing in with a username and password: repeated failures from one place block it for a while, every attempt is
 * logged, and a sign-in with the password that ships with GnozioCMS is flagged so the person is told to change it.
 *
 * A person with a second factor (SecondFactor) is not signed in by the password alone: the session remembers who gave it, for
 * five minutes, and code() signs them in with a code from their app or a recovery code. A wrong code counts against the same
 * limits as a wrong password.
 */
final class SignIn
{
    /** The session key of a password that was right, waiting for its second factor. */
    public const PENDING = 'second_factor_pending';
    public const PENDING_SECONDS = 300;

    /** @param callable(string, string, ?string, ?string, string, array<string, mixed>, ?array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context, actor */
    public function __construct(private Auth $auth, private LoginThrottle $throttle, private $log, private ?SecondFactor $secondFactor = null, private ?UserRepository $users = null)
    {
    }

    /**
     * @return array{status: string, message: string, retry_after: int, default_password: bool, username: string} status is `ok`,
     *         `code` (the password was right; the second factor is asked next), `blocked`, or `failed`
     */
    public function password(string $username, string $password, string $address): array
    {
        $result = ['status' => 'failed', 'message' => 'Invalid credentials.', 'retry_after' => 0, 'default_password' => false, 'username' => $username];
        unset($_SESSION[self::PENDING]);
        $blocked = $this->blocked($address, $username, $result);
        if ($blocked !== null) {
            return $blocked;
        }

        $user = $this->auth->checkPassword($username, $password);
        if ($user !== null) {
            $signedIn = (string)($user['username'] ?? $username);
            $default = $this->auth->isShippedDefaultPassword($signedIn, $password);
            $id = (int)($user['id'] ?? 0);
            if ($this->secondFactor !== null && $this->secondFactor->enabled($id)) {
                // The failures so far are not cleared yet: only a right code does that, or a right password could be given
                // again and again to guess codes without limit.
                $_SESSION[self::PENDING] = ['id' => $id, 'username' => $signedIn, 'typed' => $username, 'at' => $this->secondFactor->now(), 'default_password' => $default];
                ($this->log)('auth.second_factor_asked', 'info', 'user', $signedIn, 'Password accepted; second factor asked.', ['method' => 'password'], null);
                return ['status' => 'code', 'message' => '', 'username' => $signedIn] + $result;
            }
            $this->throttle->recordSuccess($address, $username);
            $this->auth->complete($user, 'password');
            ($this->log)('auth.login_success', 'info', 'user', $signedIn, 'User logged in.', ['method' => 'password'], null);
            return ['status' => 'ok', 'message' => '', 'default_password' => $default, 'username' => $signedIn] + $result;
        }

        $this->throttle->recordFailure($address, $username);
        ($this->log)('auth.login_failure', 'warning', 'user', $username, 'Invalid password login attempt.', ['method' => 'password', 'username' => $username], ['username' => $username]);
        return $result;
    }

    /** Who gave a right password and has five minutes to give the code: ['id', 'username', …], or null. */
    public function pending(): ?array
    {
        $pending = $_SESSION[self::PENDING] ?? null;
        if (!is_array($pending) || $this->secondFactor === null) {
            return null;
        }
        if ($this->secondFactor->now() - (int)($pending['at'] ?? 0) > self::PENDING_SECONDS) {
            unset($_SESSION[self::PENDING]);
            return null;
        }
        return $pending;
    }

    public function forget(): void
    {
        unset($_SESSION[self::PENDING]);
    }

    /**
     * The second step: a code from the app, or a recovery code.
     *
     * @return array{status: string, message: string, retry_after: int, default_password: bool, username: string, recovery_left: int}
     *         status is `ok`, `expired` (sign in again), `blocked`, or `failed`
     */
    public function code(string $code, string $address): array
    {
        $pending = $this->pending();
        $result = ['status' => 'failed', 'message' => 'That code is not right. Try the newest code from your app, or a recovery code.', 'retry_after' => 0, 'default_password' => false, 'username' => '', 'recovery_left' => 0];
        if ($pending === null || $this->secondFactor === null || $this->users === null) {
            return ['status' => 'expired', 'message' => 'That took too long. Sign in again.'] + $result;
        }
        $username = (string)$pending['username'];
        // The limits count under the name that was typed with the password (a username or an email), as password() did.
        $typed = (string)($pending['typed'] ?? $username);
        $result['username'] = $username;
        $blocked = $this->blocked($address, $typed, $result);
        if ($blocked !== null) {
            return $blocked;
        }
        $id = (int)$pending['id'];
        $row = $this->users->find($id);
        if (!$row || !$this->users->isActive($row) || UserRepository::isAutomation($row)) {
            $this->forget();
            return ['status' => 'expired', 'message' => 'Invalid credentials.'] + $result;
        }

        $kind = $this->secondFactor->verify($id, $code);
        if ($kind === '') {
            $this->throttle->recordFailure($address, $typed);
            ($this->log)('auth.second_factor_failure', 'warning', 'user', $username, 'Wrong second-factor code.', ['method' => 'password', 'username' => $username], ['username' => $username]);
            return $result;
        }

        $this->forget();
        $this->throttle->recordSuccess($address, $typed);
        $this->auth->complete($this->users->publicUser($row), 'password');
        $left = $this->secondFactor->status($id)['recovery_left'];
        ($this->log)('auth.login_success', 'info', 'user', $username, 'User logged in.', ['method' => 'password', 'second_factor' => $kind] + ($kind === 'recovery' ? ['recovery_left' => $left] : []), null);
        return ['status' => 'ok', 'message' => '', 'default_password' => (bool)($pending['default_password'] ?? false), 'recovery_left' => $kind === 'recovery' ? $left : -1] + $result;
    }

    /** @param array<string, mixed> $result */
    private function blocked(string $address, string $username, array $result): ?array
    {
        $throttle = $this->throttle->check($address, $username);
        if (!$throttle['blocked']) {
            return null;
        }
        $minutes = max(1, (int)ceil($throttle['retry_after'] / 60));
        ($this->log)('auth.login_throttled', 'warning', 'user', $username, 'Login blocked after repeated failures.', ['username' => $username, 'retry_after' => $throttle['retry_after']], ['username' => $username]);
        return ['status' => 'blocked', 'message' => 'Too many failed sign-in attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.', 'retry_after' => (int)$throttle['retry_after']] + $result;
    }
}
