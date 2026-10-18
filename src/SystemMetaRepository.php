<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Key/value access to the `system_meta` table (settings documents, cached status, flags). */
final class SystemMetaRepository
{
    public function __construct(private SystemDatabase $database)
    {
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    public function get(string $key): ?string
    {
        if (!$this->database->isAvailable()) {
            return null;
        }
        $stmt = $this->database->connection()->prepare('SELECT value FROM system_meta WHERE key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        if (!$this->database->isAvailable()) {
            return;
        }
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO system_meta (key, value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $value,
            'updated_at' => gmdate('c'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getJson(string $key): ?array
    {
        $raw = $this->get($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function setJson(string $key, array $value): void
    {
        $this->set($key, (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
