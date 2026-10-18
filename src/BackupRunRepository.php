<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

final class BackupRunRepository
{
    public function __construct(private SystemDatabase $database)
    {
    }

    public function record(array $data): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO backup_runs
                (filename, status, size_bytes, message, created_at)
             VALUES
                (:filename, :status, :size_bytes, :message, :created_at)'
        );
        $stmt->execute([
            'filename' => $this->nullableString($data['filename'] ?? null),
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'failed')),
            'size_bytes' => max(0, (int)($data['size_bytes'] ?? 0)),
            'message' => $this->nullableString($data['message'] ?? null),
            'created_at' => (string)($data['created_at'] ?? gmdate('c')),
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        if (!$this->database->isAvailable()) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM backup_runs
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countByStatus(string $status): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM backup_runs WHERE status = :status');
        $stmt->execute(['status' => $this->normalizeStatus($status)]);
        return (int)$stmt->fetchColumn();
    }

    private function normalizeStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['success', 'warning', 'failed'], true) ? $value : 'failed';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
