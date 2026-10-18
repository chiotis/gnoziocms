<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

final class NotificationRepository
{
    public function __construct(private SystemDatabase $database)
    {
    }

    public function create(array $data): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }

        $context = $data['context'] ?? [];
        if (!is_array($context)) {
            $context = ['value' => $context];
        }
        $contextJson = $context === []
            ? null
            : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $stmt = $this->pdo()->prepare(
            'INSERT INTO notifications
                (type, title, body, severity, is_read, target_url, context_json, created_at, read_at)
             VALUES
                (:type, :title, :body, :severity, :is_read, :target_url, :context_json, :created_at, NULL)'
        );
        $stmt->execute([
            'type' => $this->normalizeToken((string)($data['type'] ?? 'general')),
            'title' => trim((string)($data['title'] ?? 'Notification')),
            'body' => $this->nullableString($data['body'] ?? null),
            'severity' => $this->normalizeSeverity((string)($data['severity'] ?? 'info')),
            'is_read' => !empty($data['is_read']) ? 1 : 0,
            'target_url' => $this->nullableString($data['target_url'] ?? null),
            'context_json' => $contextJson,
            'created_at' => (string)($data['created_at'] ?? gmdate('c')),
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    public function createIfMissing(array $data, bool $includeRead = false): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }

        $type = $this->normalizeToken((string)($data['type'] ?? 'general'));
        $title = trim((string)($data['title'] ?? 'Notification'));
        $targetUrl = $this->nullableString($data['target_url'] ?? null);
        $existing = $this->findDuplicate($type, $title, $targetUrl, $includeRead);
        if ($existing > 0) {
            return $existing;
        }

        return $this->create($data);
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 6): array
    {
        if (!$this->database->isAvailable()) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM notifications
             ORDER BY is_read ASC, created_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadCount(): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }

        return (int)$this->pdo()->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
    }

    public function markRead(int $id): bool
    {
        if (!$this->database->isAvailable() || $id <= 0) {
            return false;
        }

        $stmt = $this->pdo()->prepare(
            'UPDATE notifications
             SET is_read = 1, read_at = :read_at
             WHERE id = :id'
        );
        $stmt->execute([
            'read_at' => gmdate('c'),
            'id' => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function markAllRead(): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }

        $stmt = $this->pdo()->prepare(
            'UPDATE notifications
             SET is_read = 1, read_at = :read_at
             WHERE is_read = 0'
        );
        $stmt->execute(['read_at' => gmdate('c')]);
        return $stmt->rowCount();
    }

    private function findDuplicate(string $type, string $title, ?string $targetUrl, bool $includeRead): int
    {
        $sql = 'SELECT id FROM notifications WHERE type = :type AND title = :title';
        $params = [
            'type' => $type,
            'title' => $title,
        ];
        if (!$includeRead) {
            $sql .= ' AND is_read = 0';
        }
        if ($targetUrl === null) {
            $sql .= ' AND target_url IS NULL';
        } else {
            $sql .= ' AND target_url = :target_url';
            $params['target_url'] = $targetUrl;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 1';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function normalizeSeverity(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['info', 'success', 'warning', 'error'], true) ? $value : 'info';
    }

    private function normalizeToken(string $value): string
    {
        return preg_replace('/[^a-z0-9_.-]/', '', strtolower(trim($value))) ?: 'general';
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
