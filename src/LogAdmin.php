<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Activity logs and Email logs screens show: the page of entries asked for with its search and filters,
 * the options to filter by, and clearing the email log. The class never reads the request: the caller passes the
 * address's query.
 */
final class LogAdmin
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /**
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(private ActivityLogRepository $activity, private EmailLogRepository $email, private \Closure $log)
    {
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function activity(array $get): array
    {
        $filters = self::filters($get, ['level', 'action', 'actor', 'subject_type', 'date_from', 'date_to', 'q']);
        $paging = self::paging($get, $this->activity->count($filters));
        return [
            'title' => 'Activity logs',
            'logs' => $this->activity->all($filters, $paging['per_page'], $paging['offset']),
            'filters' => $filters,
            'actions' => $this->activity->actions(),
            'subject_types' => $this->activity->subjectTypes(),
            'admin_section' => 'activity',
        ] + $paging['view'];
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function email(array $get): array
    {
        $filters = self::filters($get, ['status', 'provider', 'recipient', 'date_from', 'date_to', 'q']);
        $paging = self::paging($get, $this->email->count($filters));
        return [
            'title' => 'Email logs',
            'logs' => $this->email->all($filters, $paging['per_page'], $paging['offset']),
            'filters' => $filters,
            'providers' => $this->email->providers(),
            'cleared' => isset($get['cleared']) ? (int)$get['cleared'] : null,
            'admin_section' => 'email_logs',
        ] + $paging['view'];
    }

    /** Empties the email log; how many entries went is returned. */
    public function clearEmail(): int
    {
        $cleared = $this->email->clear();
        ($this->log)('email_logs.clear', 'warning', 'email_logs', 'all', 'Email logs cleared.', ['cleared' => $cleared]);
        return $cleared;
    }

    /**
     * @param array<string, mixed> $get
     * @param string[] $names
     * @return array<string, string>
     */
    private static function filters(array $get, array $names): array
    {
        $filters = [];
        foreach ($names as $name) {
            $filters[$name] = trim((string)($get[$name] ?? ''));
        }
        return $filters;
    }

    /**
     * @param array<string, mixed> $get
     * @return array{per_page: int, offset: int, view: array<string, mixed>}
     */
    private static function paging(array $get, int $total): array
    {
        $perPage = (int)($get['per_page'] ?? 50);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 50;
        }
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, (int)($get['page'] ?? 1)), $pages);
        return [
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'view' => [
                'total_logs' => $total,
                'page' => $page,
                'total_pages' => $pages,
                'per_page' => $perPage,
                'per_page_options' => self::PER_PAGE_OPTIONS,
                'current_type' => 'pages',
            ],
        ];
    }
}
