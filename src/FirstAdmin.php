<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The first visit to a site that has no accounts yet (a new install, which ships none): the sign-in page asks for
 * the first administrator instead. It is open only while there is no account at all; the account made is a super
 * admin, and its password must not be the one the demo ships with.
 */
final class FirstAdmin
{
    /** @param callable(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context */
    public function __construct(private UserRepository $users, private Auth $auth, private $log)
    {
    }

    /** Whether the site has no accounts, so the first one is to be made. */
    public function needed(): bool
    {
        try {
            return $this->users->isAvailable() && $this->users->count() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Makes the first administrator from the submitted form and signs them in.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, error: string, payload: array<string, string>} `payload` is what to show again after a mistake
     */
    public function create(array $post): array
    {
        $payload = [
            'username' => preg_replace('/[^a-zA-Z0-9_.-]/', '', trim((string)($post['username'] ?? ''))) ?: '',
            'display_name' => trim((string)($post['display_name'] ?? '')),
            'email' => strtolower(trim((string)($post['email'] ?? ''))),
        ];
        $password = (string)($post['password'] ?? '');
        $error = match (true) {
            !$this->needed() => 'This site already has an account. Sign in instead.',
            strlen($payload['username']) < 3 => 'The username needs at least 3 letters or numbers.',
            filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false => 'Enter a valid email address.',
            mb_strlen($password) < UserAdmin::MIN_PASSWORD_LENGTH => 'The password needs at least ' . UserAdmin::MIN_PASSWORD_LENGTH . ' characters.',
            $password !== (string)($post['password_confirm'] ?? '') => 'The two passwords are not the same.',
            $this->auth->isShippedDefaultPassword($payload['username'], $password) => 'Choose a password other than the one shipped with GnozioCMS.',
            default => '',
        };
        if ($error !== '') {
            return ['ok' => false, 'error' => $error, 'payload' => $payload];
        }
        try {
            $id = $this->users->create([
                'username' => $payload['username'],
                'display_name' => $payload['display_name'] !== '' ? $payload['display_name'] : $payload['username'],
                'email' => $payload['email'],
                'role' => 'superadmin',
                'status' => 'active',
                'password_hash' => $this->users->passwordHash($password),
                'source' => 'setup',
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'The account could not be made. Try again.', 'payload' => $payload];
        }
        $user = $this->users->findByUsername($payload['username']);
        if ($user !== null) {
            $this->auth->loginUser($user);
        }
        ($this->log)('setup.first_admin', 'info', 'user', (string)$id, 'The first administrator was made.', ['username' => $payload['username']]);
        return ['ok' => true, 'error' => '', 'payload' => $payload];
    }
}
