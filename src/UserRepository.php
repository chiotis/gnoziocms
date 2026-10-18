<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;
use Symfony\Component\Yaml\Yaml;

final class UserRepository
{
    /** @var array<string, string> */
    private array $roles = ['superadmin' => 'superadmin', 'admin' => 'admin', 'editor' => 'editor', 'user' => 'user'];
    /** @var string[] Roles the super admin made, which a person can also have. */
    private array $extraRoles = [];
    /** @var array<string, string> */
    private array $statuses = ['active' => 'active', 'inactive' => 'inactive'];

    public function __construct(private SystemDatabase $database, private string $usersFile)
    {
    }

    /** @param string[] $keys the keys of the site's own roles */
    public function allowRoles(array $keys): void
    {
        $this->extraRoles = array_values(array_map('strval', $keys));
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    public function importYamlUsersIfEmpty(): void
    {
        if (!$this->isAvailable() || $this->count() > 0 || !file_exists($this->usersFile)) {
            return;
        }

        $rows = Yaml::parseFile($this->usersFile);
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $username = trim((string)($row['username'] ?? ''));
            if ($username === '') {
                continue;
            }
            if ($this->findByUsername($username)) {
                continue;
            }
            $password = (string)($row['password'] ?? '');
            $hash = $this->normalizePasswordForImport($password);
            try {
                $this->create([
                    'username' => $username,
                    'email' => trim((string)($row['email'] ?? '')),
                    'display_name' => trim((string)($row['display_name'] ?? $username)),
                    'role' => (string)($row['role'] ?? 'admin'),
                    'status' => (string)($row['status'] ?? 'active'),
                    'password_hash' => $hash,
                    'source' => 'yaml-import',
                ]);
            } catch (\Throwable) {
                // Another request may have imported this user first.
            }
        }
    }

    public function ensureSuperadminExists(): void
    {
        if (!$this->isAvailable() || $this->count() === 0) {
            return;
        }

        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM users WHERE role = "superadmin" AND status = "active"');
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $candidate = $this->pdo()->query('SELECT id FROM users WHERE status = "active" ORDER BY role = "admin" DESC, id ASC LIMIT 1');
        $id = (int)$candidate->fetchColumn();
        if ($id <= 0) {
            return;
        }

        $update = $this->pdo()->prepare('UPDATE users SET role = "superadmin", updated_at = :updated_at WHERE id = :id');
        $update->execute(['id' => $id, 'updated_at' => gmdate('c')]);
    }

    public function count(): int
    {
        $stmt = $this->pdo()->query('SELECT COUNT(*) FROM users');
        return (int)$stmt->fetchColumn();
    }

    /** Active users with a role, to show how many people a permission change reaches. */
    public function countByRole(string $role, bool $activeOnly = true): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE role = :role' . ($activeOnly ? " AND status = 'active'" : ''));
        $stmt->execute(['role' => $role]);
        return (int)$stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function all(array $filters = []): array
    {
        $where = [];
        $params = [];
        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(username LIKE :query OR email LIKE :query OR display_name LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        $role = $this->normalizeRole((string)($filters['role'] ?? ''));
        if ($role !== '') {
            $where[] = 'role = :role';
            $params['role'] = $role;
        }
        $status = $this->normalizeStatus((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT * FROM users';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY role = "superadmin" DESC, display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE lower(email) = lower(:email) LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findForLogin(string $identifier): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE username = :identifier OR lower(email) = lower(:identifier) LIMIT 1');
        $stmt->execute(['identifier' => $identifier]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo()->prepare(
            'INSERT INTO users (username, email, display_name, password_hash, role, status, source, google_sub, google_email, created_at, updated_at)
             VALUES (:username, :email, :display_name, :password_hash, :role, :status, :source, :google_sub, :google_email, :created_at, :updated_at)'
        );
        $stmt->execute([
            'username' => $this->normalizeUsername((string)($data['username'] ?? '')),
            'email' => $this->normalizeEmail((string)($data['email'] ?? '')),
            'display_name' => trim((string)($data['display_name'] ?? '')),
            'password_hash' => (string)($data['password_hash'] ?? ''),
            'role' => $this->normalizeRole((string)($data['role'] ?? 'user')) ?: 'user',
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'active')) ?: 'active',
            'source' => (string)($data['source'] ?? 'sqlite'),
            'google_sub' => trim((string)($data['google_sub'] ?? '')) ?: null,
            'google_email' => $this->normalizeEmail((string)($data['google_email'] ?? '')) ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int)$this->pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [
            'username' => $this->normalizeUsername((string)($data['username'] ?? '')),
            'email' => $this->normalizeEmail((string)($data['email'] ?? '')),
            'display_name' => trim((string)($data['display_name'] ?? '')),
            'role' => $this->normalizeRole((string)($data['role'] ?? 'user')) ?: 'user',
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'active')) ?: 'active',
            'google_sub' => trim((string)($data['google_sub'] ?? '')) ?: null,
            'google_email' => $this->normalizeEmail((string)($data['google_email'] ?? '')) ?: null,
            'updated_at' => gmdate('c'),
            'id' => $id,
        ];
        $password = (string)($data['password_hash'] ?? '');
        $passwordSql = '';
        if ($password !== '') {
            $passwordSql = ', password_hash = :password_hash';
            $fields['password_hash'] = $password;
        }
        $stmt = $this->pdo()->prepare(
            'UPDATE users
             SET username = :username, email = :email, display_name = :display_name, role = :role, status = :status,
                 google_sub = :google_sub, google_email = :google_email, updated_at = :updated_at' . $passwordSql . '
             WHERE id = :id'
        );
        $stmt->execute($fields);
    }

    public function markLastLogin(int $id): void
    {
        $stmt = $this->pdo()->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'last_login_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);
    }

    public function linkGoogle(int $id, string $sub, string $email): void
    {
        $stmt = $this->pdo()->prepare('UPDATE users SET google_sub = :sub, google_email = :email, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'sub' => trim($sub),
            'email' => $this->normalizeEmail($email),
            'updated_at' => gmdate('c'),
        ]);
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->pdo()->prepare('UPDATE users SET status = "inactive", updated_at = :updated_at WHERE id = :id');
        $stmt->execute(['id' => $id, 'updated_at' => gmdate('c')]);
    }

    public function passwordHash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function publicUser(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }

    public function isActive(array $user): bool
    {
        return ($user['status'] ?? 'active') === 'active';
    }

    private function normalizePasswordForImport(string $password): string
    {
        if (str_starts_with($password, 'plain:')) {
            return $this->passwordHash(substr($password, 6));
        }
        return $password;
    }

    private function normalizeUsername(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '', trim($value)) ?: '';
    }

    private function normalizeEmail(string $value): string
    {
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : '';
    }

    private function normalizeRole(string $value): string
    {
        $value = strtolower(trim($value));
        return $this->roles[$value] ?? (in_array($value, $this->extraRoles, true) ? $value : '');
    }

    private function normalizeStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return $this->statuses[$value] ?? '';
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
