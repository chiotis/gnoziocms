<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Adding and changing a user from the admin: reading the form (a person who may not change access, or who may not
 * manage users, cannot change their own role, status, or Google link), the checks on the password, and saving.
 */
final class UserAdmin
{
    public const MIN_PASSWORD_LENGTH = 8;

    /** @param callable(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context */
    public function __construct(private UserRepository $users, private Auth $auth, private PermissionService $permissions, private $log)
    {
    }

    /** What a new user starts with. @return array<string, mixed> */
    public static function blank(): array
    {
        return ['id' => 0, 'username' => '', 'email' => '', 'display_name' => '', 'role' => 'editor', 'status' => 'active', 'google_sub' => '', 'google_email' => '', 'created_at' => '', 'updated_at' => '', 'last_login_at' => ''];
    }

    /**
     * Saves a submitted form for a new user (`$id` 0) or an existing one.
     *
     * @param array<string, mixed>|null $existing the user being changed
     * @param array<string, mixed>|null $actor the person using the form
     * @param array<string, mixed> $post
     * @return array{ok: bool, id: int, error: string, payload: array<string, string>, password_changed: bool} `payload` is what to show again after a mistake
     */
    public function save(int $id, ?array $existing, ?array $actor, array $post): array
    {
        $payload = [
            'username' => trim((string)($post['username'] ?? '')),
            'email' => trim((string)($post['email'] ?? '')),
            'display_name' => trim((string)($post['display_name'] ?? '')),
            'role' => trim((string)($post['role'] ?? 'editor')),
            'status' => trim((string)($post['status'] ?? 'active')),
            'google_sub' => trim((string)($post['google_sub'] ?? '')),
            'google_email' => trim((string)($post['google_email'] ?? '')),
        ];
        if ($id > 0 && !$this->permissions->canChangeAccessForUser($actor, $id)) {
            $payload['role'] = (string)($existing['role'] ?? 'user');
            $payload['status'] = (string)($existing['status'] ?? 'active');
        }
        if ($id > 0 && !$this->permissions->can($actor, 'users.manage')) {
            $payload['google_sub'] = (string)($existing['google_sub'] ?? '');
            $payload['google_email'] = (string)($existing['google_email'] ?? '');
        }

        $password = (string)($post['password'] ?? '');
        $error = $this->passwordProblem($id, $payload['username'], $password, (string)($post['password_confirm'] ?? ''));
        $result = ['ok' => false, 'id' => $id, 'error' => $error, 'payload' => $payload, 'password_changed' => false];
        if ($error !== '') {
            return $result;
        }

        $toStore = $payload;
        if ($password !== '') {
            $toStore['password_hash'] = $this->users->passwordHash($password);
        }
        try {
            if ($id > 0) {
                $this->users->update($id, $toStore);
                $this->log('users.update', 'info', 'user', (string)$id, 'User updated.', ['username' => $payload['username'], 'role' => $payload['role'], 'status' => $payload['status']]);
            } else {
                $id = $this->users->create($toStore);
                $this->log('users.create', 'info', 'user', (string)$id, 'User created.', ['username' => $payload['username'], 'role' => $payload['role'], 'status' => $payload['status']]);
            }
        } catch (\Throwable) {
            $result['error'] = 'User could not be saved. Check for duplicate usernames or invalid values.';
            return $result;
        }
        return ['ok' => true, 'id' => $id, 'error' => '', 'payload' => $payload, 'password_changed' => $password !== ''];
    }

    private function passwordProblem(int $id, string $username, string $password, string $confirm): string
    {
        if ($username === '') {
            return 'Username is required.';
        }
        if ($id === 0 && $password === '') {
            return 'Password is required for new users.';
        }
        if ($password !== '' && $password !== $confirm) {
            return 'Password confirmation does not match.';
        }
        if ($password !== '' && mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Passwords must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long.';
        }
        if ($password !== '' && $this->auth->isShippedDefaultPassword($username, $password)) {
            return 'Choose a password other than the one shipped with GnozioCMS.';
        }
        return '';
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, string $level, ?string $type, ?string $id, string $message, array $context): void
    {
        ($this->log)($action, $level, $type, $id, $message, $context);
    }
}
