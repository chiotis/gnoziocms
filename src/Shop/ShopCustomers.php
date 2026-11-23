<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\SystemDatabase;
use PDO;

/**
 * The shop's customers (table shop_customers, docs/shop-plan.md, section 4): people who buy, never admin users. They sign in
 * with an email and a password (password_hash); a link sent by email confirms the address or sets a new password, and only
 * the hash of its token is kept, for an hour (a new password) or two days (the address), each kind in its own columns so one
 * link does not cancel the other. They may sign in with Google too (fromGoogle()): the Google account's `sub` is kept in
 * google_sub, and an account made that way has no password until they set one. session_version is raised whenever the password
 * changes or is cleared; a session that holds an older one is signed out (ShopFront::customer()).
 */
final class ShopCustomers
{
    public const MIN_PASSWORD = 8;
    public const RESET_SECONDS = 3600;
    public const VERIFY_SECONDS = 172800;
    /** A password brought from WordPress and not used here for this long is cleared (clearStaleImportedHashes()). */
    public const IMPORTED_HASH_SECONDS = 15552000;

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

    /**
     * The customer whose password this is, or null. A hash made with older settings is made again; so is one brought from a
     * WordPress shop (wordpressVerify()), on its first sign-in here, so nobody has to choose a new password after the move.
     */
    public function authenticate(string $email, string $password): ?array
    {
        $customer = $this->byEmail($email);
        $hash = $customer['password_hash'] ?? '';
        $wordpress = $customer !== null && self::isWordPressHash($hash);
        $ok = $customer !== null && $hash !== '' && ($wordpress ? self::wordpressVerify($password, $hash) : password_verify($password, $hash));
        if ($ok && $wordpress) {
            // The same password in a new hash: other sessions stay signed in.
            $this->pdo()->prepare('UPDATE shop_customers SET password_hash = :hash WHERE id = :id')->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $customer['id']]);
            return $this->authenticate($email, $password);
        }
        if (!$ok) {
            // The same work for an address that has no account, so the time taken tells nothing.
            if ($customer === null) {
                password_verify($password, '$2y$12$Jmra2FH8yxdoYvp6HI1LyOsZ/6iF7k2X7LRhWV/4TeYXsTHbQRcj2');
            }
            return null;
        }
        if (password_needs_rehash($customer['password_hash'], PASSWORD_DEFAULT)) {
            $this->pdo()->prepare('UPDATE shop_customers SET password_hash = :hash WHERE id = :id')->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $customer['id']]);
        }
        $this->pdo()->prepare('UPDATE shop_customers SET last_login_at = :now WHERE id = :id')->execute(['now' => time(), 'id' => $customer['id']]);
        return $customer;
    }

    /** A hash of WordPress's: portable phpass ($P$, $H$), its newer bcrypt ($wp$2y$, 6.8 on) or a bare MD5 of very old sites. */
    public static function isWordPressHash(string $hash): bool
    {
        return preg_match('/^\$[PH]\$[.\/0-9A-Za-z]{31}$/', $hash) === 1 || str_starts_with($hash, '$wp$2') || preg_match('/^[0-9a-f]{32}$/', $hash) === 1;
    }

    public static function wordpressVerify(string $password, string $hash): bool
    {
        if (str_starts_with($hash, '$wp$2')) {
            return password_verify(base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true)), substr($hash, 3));
        }
        if (preg_match('/^[0-9a-f]{32}$/', $hash) === 1) {
            return hash_equals($hash, md5($password));
        }
        $itoa = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $log2 = strpos($itoa, $hash[3]);
        if ($log2 === false || $log2 < 7 || $log2 > 30 || strlen($password) > 4096) {
            return false;
        }
        $salt = substr($hash, 4, 8);
        $digest = md5($salt . $password, true);
        for ($count = 1 << $log2; $count > 0; $count--) {
            $digest = md5($digest . $password, true);
        }
        $out = '';
        $i = 0;
        do {
            $value = ord($digest[$i++]);
            $out .= $itoa[$value & 0x3f];
            if ($i < 16) {
                $value |= ord($digest[$i]) << 8;
            }
            $out .= $itoa[($value >> 6) & 0x3f];
            if ($i++ >= 16) {
                break;
            }
            if ($i < 16) {
                $value |= ord($digest[$i]) << 16;
            }
            $out .= $itoa[($value >> 12) & 0x3f];
            if ($i++ >= 16) {
                break;
            }
            $out .= $itoa[($value >> 18) & 0x3f];
        } while ($i < 16);
        return hash_equals($hash, substr($hash, 0, 12) . $out);
    }

    /**
     * A customer brought from another shop, with the hash of their password as it was there (WordPress's), confirmed (they were
     * customers already). 'new', 'exists' (an account of that address is kept as it is) or 'invalid'.
     *
     * @param array<string, string> $address
     */
    public function import(string $email, string $hash, string $name, string $phone, array $address, string $lang): string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            return 'invalid';
        }
        if ($this->byEmail($email) !== null) {
            return 'exists';
        }
        $hash = self::isWordPressHash($hash) || password_get_info($hash)['algo'] !== null ? $hash : '';
        $this->pdo()->prepare('INSERT INTO shop_customers (email, password_hash, name, phone, lang, address, verified_at, created_at) VALUES (:email, :hash, :name, :phone, :lang, :address, :now, :now2)')
            ->execute(['email' => $email, 'hash' => $hash, 'name' => mb_substr(trim($name), 0, 120), 'phone' => mb_substr(trim($phone), 0, 40), 'lang' => $lang, 'address' => json_encode(ShopAddress::clean($address), JSON_UNESCAPED_UNICODE), 'now' => time(), 'now2' => time()]);
        return 'new';
    }

    public function setPassword(int $id, string $password): bool
    {
        if (mb_strlen($password) < self::MIN_PASSWORD || mb_strlen($password) > 200) {
            return false;
        }
        // A new password signs out every other session (the one that set it takes the new version, ShopFront) and ends a waiting
        // link to set one.
        $this->pdo()->prepare("UPDATE shop_customers SET password_hash = :hash, session_version = session_version + 1, reset_hash = '', reset_expires = 0 WHERE id = :id")
            ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
        return true;
    }

    /**
     * Clears the passwords brought from WordPress that nobody has used here for six months (a hash of WordPress's is replaced on
     * its first sign-in, so one still there was never used since the import). Those customers set a password by email
     * ("forgot password"). Returns how many were cleared. From the shop's tick.
     */
    public function clearStaleImportedHashes(?int $now = null): int
    {
        $stmt = $this->pdo()->prepare("SELECT id, password_hash FROM shop_customers WHERE password_hash <> '' AND created_at < :before AND (last_login_at IS NULL OR last_login_at < :before2)");
        $before = ($now ?? time()) - self::IMPORTED_HASH_SECONDS;
        $stmt->execute(['before' => $before, 'before2' => $before]);
        $cleared = 0;
        $clear = $this->pdo()->prepare("UPDATE shop_customers SET password_hash = '', session_version = session_version + 1 WHERE id = :id AND password_hash = :hash");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::isWordPressHash((string)$row['password_hash'])) {
                $clear->execute(['id' => $row['id'], 'hash' => $row['password_hash']]);
                $cleared += $clear->rowCount();
            }
        }
        return $cleared;
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

    /**
     * A token for a link sent by email ('verify' or 'reset'); only its hash is kept, in the columns of its kind, so a new link
     * to confirm the address leaves a waiting link to set a password working, and the other way round.
     */
    public function issueToken(int $id, string $kind): string
    {
        $kind = $kind === 'reset' ? 'reset' : 'verify';
        $token = bin2hex(random_bytes(24));
        $this->pdo()->prepare("UPDATE shop_customers SET {$kind}_hash = :hash, {$kind}_expires = :until WHERE id = :id")->execute([
            'hash' => hash('sha256', $token),
            'until' => time() + ($kind === 'reset' ? self::RESET_SECONDS : self::VERIFY_SECONDS),
            'id' => $id,
        ]);
        return $token;
    }

    /** The customer a link's token is for, while it is current; null otherwise. */
    public function byToken(string $token, string $kind): ?array
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1 || !in_array($kind, ['verify', 'reset'], true)) {
            return null;
        }
        $stmt = $this->pdo()->prepare("SELECT * FROM shop_customers WHERE {$kind}_hash = :hash AND {$kind}_expires >= :now");
        $stmt->execute(['hash' => hash('sha256', $token), 'now' => time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /**
     * The customer a Google account signs in as, by its `sub`, else by its address (Google has checked it), which then links the
     * two; else a new customer, when $mayCreate. Google proves the address, so the account counts as confirmed. An account that was
     * never confirmed loses its password when it is linked: someone else may have made it with this address. Returns the customer
     * with `new` true when made now, or 'signup' when there is none and none may be made.
     *
     * @return array<string, mixed>|string
     */
    public function fromGoogle(string $sub, string $email, string $name, string $lang, bool $mayCreate): array|string
    {
        $sub = trim($sub);
        $email = mb_strtolower(trim($email));
        if ($sub === '' || strlen($sub) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'email';
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM shop_customers WHERE google_sub = :sub');
        $stmt->execute(['sub' => $sub]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $found = is_array($row) ? self::row($row) : $this->byEmail($email);
        if ($found !== null) {
            // Clearing the password signs out whoever signed in with it.
            $clear = !$found['verified'] && $found['google_sub'] === '' ? ", password_hash = '', session_version = session_version + 1" : '';
            $this->pdo()->prepare("UPDATE shop_customers SET google_sub = :sub, last_login_at = :now{$clear} WHERE id = :id")
                ->execute(['sub' => $sub, 'now' => time(), 'id' => $found['id']]);
            $this->markVerified($found['id']);
            return ($this->get($found['id']) ?? $found) + ['new' => false];
        }
        if (!$mayCreate) {
            return 'signup';
        }
        $now = time();
        $this->pdo()->prepare("INSERT INTO shop_customers (email, password_hash, name, lang, google_sub, verified_at, created_at, last_login_at) VALUES (:email, '', :name, :lang, :sub, :now, :now2, :now3)")
            ->execute(['email' => $email, 'name' => mb_substr(trim($name), 0, 120), 'lang' => $lang, 'sub' => $sub, 'now' => $now, 'now2' => $now, 'now3' => $now]);
        return ($this->get((int)$this->pdo()->lastInsertId()) ?? []) + ['new' => true];
    }

    public function markVerified(int $id): void
    {
        $this->pdo()->prepare("UPDATE shop_customers SET verified_at = COALESCE(verified_at, :now), verify_hash = '', verify_expires = 0 WHERE id = :id")->execute(['now' => time(), 'id' => $id]);
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
            'has_password' => (string)$row['password_hash'] !== '',
            'google_sub' => (string)($row['google_sub'] ?? ''),
            'name' => (string)$row['name'],
            'phone' => (string)$row['phone'],
            'lang' => (string)$row['lang'],
            'address' => is_array($address) ? ShopAddress::clean($address) : ShopAddress::clean([]),
            'verified' => $row['verified_at'] !== null,
            'session_version' => (int)($row['session_version'] ?? 0),
            'created_at' => (int)$row['created_at'],
            'last_login_at' => $row['last_login_at'] === null ? null : (int)$row['last_login_at'],
        ];
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
