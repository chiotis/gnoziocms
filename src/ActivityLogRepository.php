<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

final class ActivityLogRepository
{
    public function __construct(private SystemDatabase $database)
    {
    }

    public function record(array $data): int
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
            'INSERT INTO activity_logs
                (level, action, actor_username, actor_role, ip_address, user_agent, subject_type, subject_id, message, context_json, created_at)
             VALUES
                (:level, :action, :actor_username, :actor_role, :ip_address, :user_agent, :subject_type, :subject_id, :message, :context_json, :created_at)'
        );
        $stmt->execute([
            'level' => $this->normalizeLevel((string)($data['level'] ?? 'info')),
            'action' => $this->normalizeToken((string)($data['action'] ?? 'event')),
            'actor_username' => $this->nullableString($data['actor_username'] ?? null),
            'actor_role' => $this->nullableString($data['actor_role'] ?? null),
            'ip_address' => $this->nullableString($data['ip_address'] ?? null),
            'user_agent' => $this->nullableString($data['user_agent'] ?? null),
            'subject_type' => $this->nullableString($data['subject_type'] ?? null),
            'subject_id' => $this->nullableString($data['subject_id'] ?? null),
            'message' => $this->nullableString($data['message'] ?? null),
            'context_json' => $contextJson,
            'created_at' => (string)($data['created_at'] ?? gmdate('c')),
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public function all(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM activity_logs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT COUNT(*) FROM activity_logs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @return array<int, string> */
    public function actions(): array
    {
        $rows = $this->pdo()->query('SELECT DISTINCT action FROM activity_logs ORDER BY action ASC')->fetchAll();
        return array_values(array_filter(array_map(fn(array $row): string => (string)($row['action'] ?? ''), $rows)));
    }

    /** @return array<int, string> */
    public function subjectTypes(): array
    {
        $rows = $this->pdo()->query('SELECT DISTINCT subject_type FROM activity_logs WHERE subject_type IS NOT NULL AND subject_type != "" ORDER BY subject_type ASC')->fetchAll();
        return array_values(array_filter(array_map(fn(array $row): string => (string)($row['subject_type'] ?? ''), $rows)));
    }

    /** @return array{0: array<int, string>, 1: array<string, string>} */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $level = $this->normalizeLevel((string)($filters['level'] ?? ''));
        if ($level !== '') {
            $where[] = 'level = :level';
            $params['level'] = $level;
        }

        $action = $this->normalizeToken((string)($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'action = :action';
            $params['action'] = $action;
        }

        $actor = trim((string)($filters['actor'] ?? ''));
        if ($actor !== '') {
            $where[] = 'actor_username LIKE :actor';
            $params['actor'] = '%' . $actor . '%';
        }

        $subjectType = $this->normalizeToken((string)($filters['subject_type'] ?? ''));
        if ($subjectType !== '') {
            $where[] = 'subject_type = :subject_type';
            $params['subject_type'] = $subjectType;
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(message LIKE :q OR subject_id LIKE :q OR context_json LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = $dateFrom . 'T00:00:00';
        }

        $dateTo = trim((string)($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'created_at <= :date_to';
            $params['date_to'] = $dateTo . 'T23:59:59';
        }

        return [$where, $params];
    }

    private function normalizeLevel(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['info', 'warning', 'error'], true) ? $value : '';
    }

    private function normalizeToken(string $value): string
    {
        return preg_replace('/[^a-z0-9_.-]/', '', strtolower(trim($value))) ?: '';
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
