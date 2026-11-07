<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * The shop's customers (table shop_customers, docs/shop-plan.md, section 4): people who buy, never admin users. They sign in
 * with an email and a password (password_hash); a link sent by email confirms the address or sets a new password, and only
 * the hash of its token is kept, for an hour (a new password) or two days (the address).
 */
final class ShopCustomers
{
    public const MIN_PASSWORD = 8;
    public const RESET_SECONDS = 3600;
    public const VERIFY_SECONDS = 172800;

    public function __construct(private SystemDatabase $database)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_customers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function byEmail(string $email): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_customers WHERE email = :email');
        $stmt->execute(['email' => trim($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /**
     * A new customer. Returns their id, or an error: 'email' (not an address), 'taken', 'password' (too short).
     */
    public function create(string $email, string $password, string $name, string $lang): int|string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            return 'email';
        }
        if (mb_strlen($password) < self::MIN_PASSWORD || mb_strlen($password) > 200) {
            return 'password';
        }
        if ($this->byEmail($email) !== null) {
            return 'taken';
        }
        $stmt = $this->pdo()->prepare('INSERT INTO shop_customers (email, password_hash, name, lang, created_at) VALUES (:email, :hash, :name, :lang, :now)');
        $stmt->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'name' => mb_substr(trim($name), 0, 120), 'lang' => $lang, 'now' => time()]);
        return (int)$this->pdo()->lastInsertId();
    }

    /** The customer whose password this is, or null. A hash made with older settings is made again. */
    public function authenticate(string $email, string $password): ?array
    {
        $customer = $this->byEmail($email);
        if ($customer === null || $customer['password_hash'] === '' || !password_verify($password, $customer['password_hash'])) {
            // The same work for an address that has no account, so the time taken tells nothing.
            if ($customer === null) {
                password_verify($password, '$2y$12$Jmra2FH8yxdoYvp6HI1LyOsZ/6iF7k2X7LRhWV/4TeYXsTHbQRcj2');
            }
            return null;
        }
        if (password_needs_rehash($customer['password_hash'], PASSWORD_DEFAULT)) {
            $this->setPassword($customer['id'], $password);
        }
        $this->pdo()->prepare('UPDATE shop_customers SET last_login_at = :now WHERE id = :id')->execute(['now' => time(), 'id' => $customer['id']]);
        return $customer;
    }

    public function setPassword(int $id, string $password): bool
    {
        if (mb_strlen($password) < self::MIN_PASSWORD || mb_strlen($password) > 200) {
            return false;
        }
        $this->pdo()->prepare("UPDATE shop_customers SET password_hash = :hash, token_hash = '', token_kind = '', token_expires = 0 WHERE id = :id")
            ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
        return true;
    }

    /**
     * @param array<string, mixed> $address
     */
    public function updateProfile(int $id, string $name, string $phone, array $address): void
    {
        $this->pdo()->prepare('UPDATE shop_customers SET name = :name, phone = :phone, address = :address WHERE id = :id')->execute([
            'name' => mb_substr(trim($name), 0, 120),
            'phone' => mb_substr(trim($phone), 0, 40),
            'address' => json_encode(ShopAddress::clean($address), JSON_UNESCAPED_UNICODE),
            'id' => $id,
        ]);
    }

    /** A token for a link sent by email ('verify' or 'reset'); only its hash is kept. */
    public function issueToken(int $id, string $kind): string
    {
        $token = bin2hex(random_bytes(24));
        $this->pdo()->prepare('UPDATE shop_customers SET token_hash = :hash, token_kind = :kind, token_expires = :until WHERE id = :id')->execute([
            'hash' => hash('sha256', $token),
            'kind' => $kind,
            'until' => time() + ($kind === 'reset' ? self::RESET_SECONDS : self::VERIFY_SECONDS),
            'id' => $id,
        ]);
        return $token;
    }

    /** The customer a link's token is for, while it is current; null otherwise. */
    public function byToken(string $token, string $kind): ?array
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_customers WHERE token_hash = :hash AND token_kind = :kind AND token_expires >= :now');
        $stmt->execute(['hash' => hash('sha256', $token), 'kind' => $kind, 'now' => time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    public function markVerified(int $id): void
    {
        $this->pdo()->prepare("UPDATE shop_customers SET verified_at = COALESCE(verified_at, :now), token_hash = '', token_kind = '', token_expires = 0 WHERE id = :id")->execute(['now' => time(), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM shop_customers WHERE id = :id')->execute(['id' => $id]);
        $this->pdo()->prepare('UPDATE shop_carts SET customer_id = NULL WHERE customer_id = :id')->execute(['id' => $id]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(string $q, int $page = 1, int $perPage = 30): array
    {
        $where = '';
        $params = [];
        if (trim($q) !== '') {
            $where = ' WHERE c.email LIKE :q OR c.name LIKE :q2';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr(trim($q), 0, 80)) . '%';
            $params = ['q' => $like, 'q2' => $like];
        }
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM shop_customers c' . $where);
        $count->execute($params);
        $stmt = $this->pdo()->prepare(
            "SELECT c.*, (SELECT COUNT(*) FROM shop_orders o WHERE o.customer_id = c.id) AS orders,
                    (SELECT COALESCE(SUM(total_cents), 0) FROM shop_orders o WHERE o.customer_id = c.id AND o.status NOT IN ('cancelled', 'refunded', 'pending_payment')) AS spent
             FROM shop_customers c" . $where . ' ORDER BY c.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return ['rows' => array_map(static fn(array $r): array => self::row($r) + ['orders' => (int)$r['orders'], 'spent' => (int)$r['spent']], $stmt->fetchAll(PDO::FETCH_ASSOC)), 'total' => (int)$count->fetchColumn()];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $address = json_decode((string)($row['address'] ?? '{}'), true);
        return [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'password_hash' => (string)$row['password_hash'],
            'name' => (string)$row['name'],
            'phone' => (string)$row['phone'],
            'lang' => (string)$row['lang'],
            'address' => is_array($address) ? ShopAddress::clean($address) : ShopAddress::clean([]),
            'verified' => $row['verified_at'] !== null,
            'created_at' => (int)$row['created_at'],
            'last_login_at' => $row['last_login_at'] === null ? null : (int)$row['last_login_at'],
        ];
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
