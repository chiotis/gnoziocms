<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

final class EmailLogRepository
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
            'INSERT INTO email_logs
                (recipient, subject, status, provider, error_message, context_json, created_at)
             VALUES
                (:recipient, :subject, :status, :provider, :error_message, :context_json, :created_at)'
        );
        $stmt->execute([
            'recipient' => trim((string)($data['recipient'] ?? '')),
            'subject' => trim((string)($data['subject'] ?? '')),
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'failed')),
            'provider' => $this->nullableString($data['provider'] ?? null),
            'error_message' => $this->nullableString($data['error_message'] ?? null),
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
        $sql = 'SELECT * FROM email_logs';
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
        $sql = 'SELECT COUNT(*) FROM email_logs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function clear(): int
    {
        if (!$this->database->isAvailable()) {
            return 0;
        }
        $count = $this->count();
        $this->pdo()->exec('DELETE FROM email_logs');
        return $count;
    }

    /** @return array<int, string> */
    public function providers(): array
    {
        $rows = $this->pdo()->query('SELECT DISTINCT provider FROM email_logs WHERE provider IS NOT NULL AND provider != "" ORDER BY provider ASC')->fetchAll();
        return array_values(array_filter(array_map(fn(array $row): string => (string)($row['provider'] ?? ''), $rows)));
    }

    /** @return array{0: array<int, string>, 1: array<string, string>} */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $status = $this->normalizeStatus((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $provider = $this->normalizeProvider((string)($filters['provider'] ?? ''));
        if ($provider !== '') {
            $where[] = 'provider = :provider';
            $params['provider'] = $provider;
        }

        $recipient = trim((string)($filters['recipient'] ?? ''));
        if ($recipient !== '') {
            $where[] = 'recipient LIKE :recipient';
            $params['recipient'] = '%' . $recipient . '%';
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(subject LIKE :q OR error_message LIKE :q OR context_json LIKE :q)';
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

    private function normalizeStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['sent', 'failed'], true) ? $value : '';
    }

    private function normalizeProvider(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['smtp', 'ses', 'none'], true) ? $value : '';
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
