<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Signing in with a username and password: repeated failures from one place block it for a while, every attempt is
 * logged, and a sign-in with the password that ships with GnozioCMS is flagged so the person is told to change it.
 */
final class SignIn
{
    /** @param callable(string, string, ?string, ?string, string, array<string, mixed>, ?array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context, actor */
    public function __construct(private Auth $auth, private LoginThrottle $throttle, private $log)
    {
    }

    /**
     * @return array{status: string, message: string, retry_after: int, default_password: bool, username: string} status is `ok`, `blocked`, or `failed`
     */
    public function password(string $username, string $password, string $address): array
    {
        $result = ['status' => 'failed', 'message' => 'Invalid credentials.', 'retry_after' => 0, 'default_password' => false, 'username' => $username];
        $throttle = $this->throttle->check($address, $username);
        if ($throttle['blocked']) {
            $minutes = max(1, (int)ceil($throttle['retry_after'] / 60));
            ($this->log)('auth.login_throttled', 'warning', 'user', $username, 'Login blocked after repeated failures.', ['username' => $username, 'retry_after' => $throttle['retry_after']], ['username' => $username]);
            return ['status' => 'blocked', 'message' => 'Too many failed sign-in attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.', 'retry_after' => (int)$throttle['retry_after']] + $result;
        }

        if ($this->auth->attempt($username, $password)) {
            $this->throttle->recordSuccess($address, $username);
            $signedIn = (string)($this->auth->user()['username'] ?? $username);
            ($this->log)('auth.login_success', 'info', 'user', $signedIn, 'User logged in.', ['method' => 'password'], null);
            return ['status' => 'ok', 'message' => '', 'default_password' => $this->auth->isShippedDefaultPassword($signedIn, $password), 'username' => $signedIn] + $result;
        }

        $this->throttle->recordFailure($address, $username);
        ($this->log)('auth.login_failure', 'warning', 'user', $username, 'Invalid password login attempt.', ['method' => 'password', 'username' => $username], ['username' => $username]);
        return $result;
    }
}
