<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Forms screens show and do with what people sent: the list of forms with how many submissions each got, one
 * form's submissions filtered and paged, deleting submissions, and the CSV export.
 */
final class FormsAdmin
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];
    public const SORTS = ['updated', 'submissions', 'name'];

    /** Columns every export starts with; one more per field the submissions have, in alphabetical order. */
    private const EXPORT_HEADERS = ['id', 'submitted_at', 'site_title', 'form_title', 'form', 'lang', 'translation_id', 'ip', 'user_agent'];

    public function __construct(private ContentRepository $content, private FormSubmissionRepository $submissions)
    {
    }

    /**
     * The forms of the site, one row each, filtered and sorted. A form is built once, in the first language; each row says how much of it
     * the other languages still lack.
     *
     * @param array{q?: string, status?: string} $filters
     * @param string[] $languages every language of the site
     * @return array{rows: array<int, array<string, mixed>>, totals: array{forms: int, published: int, submissions: int, recent: int}, sort: string}
     */
    public function overview(array $filters, string $sort, array $languages, string $defaultLang, bool $storesByDefault): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'updated';
        $groups = [];
        foreach ($this->content->getItems('forms', null, true, false) as $item) {
            $groups[$item->slug][$item->lang] = $item;
        }

        $rows = [];
        $totals = ['forms' => 0, 'published' => 0, 'submissions' => 0, 'recent' => 0];
        foreach ($groups as $slug => $versions) {
            $primary = $versions[$defaultLang] ?? reset($versions);
            $stats = $this->submissions->stats((string)$slug);
            $notifications = is_array($primary->meta['notifications'] ?? null) ? $primary->meta['notifications'] : [];
            $texts = $primary->meta;
            $texts['body'] = $primary->markdown;
            $status = (string)($primary->meta['status'] ?? 'published');
            $rows[] = [
                'slug' => (string)$slug,
                'title' => (string)($primary->meta['title'] ?? $slug),
                'status' => $status,
                'lang' => $primary->lang,
                'field_count' => count(FormFields::normalize($primary->meta['fields'] ?? [])),
                // How much each other language still lacks, and the older per-language copies that were not folded into the form yet.
                'translations' => FormTranslations::overview($texts, $languages, $defaultLang),
                'copies' => array_values(array_diff(array_keys($versions), [$primary->lang])),
                'submissions' => $stats['total'],
                'recent' => $stats['last_7_days'],
                'latest_submission' => $stats['latest'] !== '' ? FormProcessor::date($stats['latest']) : '',
                'updated' => max(array_map(static fn(ContentItem $version): int => $version->mtime, $versions)),
                'notifications' => Format::isTruthy($notifications['enabled'] ?? false),
                'stores' => Format::isTruthy($primary->meta['store_submissions'] ?? $storesByDefault),
                'shortcode' => '[form slug="' . $slug . '"]',
            ];
            $totals['forms']++;
            $totals['published'] += $status === 'published' ? 1 : 0;
            $totals['submissions'] += $stats['total'];
            $totals['recent'] += $stats['last_7_days'];
        }

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_values(array_filter($rows, static fn(array $row): bool => str_contains(mb_strtolower($row['title'] . ' ' . $row['slug']), $needle)));
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === $status));
        }
        usort($rows, static fn(array $a, array $b): int => match ($sort) {
            'submissions' => $b['submissions'] <=> $a['submissions'],
            'name' => strcasecmp($a['title'], $b['title']),
            default => $b['updated'] <=> $a['updated'],
        });
        return ['rows' => $rows, 'totals' => $totals, 'sort' => $sort];
    }

    /**
     * What the folding of per-language copies left for a person to look at: texts of fields that had no match, settings that differed,
     * and copies that had no first-language form. Null when there is nothing to look at.
     *
     * @param array<string, mixed> $report
     * @return array{unmatched: array<int, array<string, mixed>>, differing: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, ran_at: string}|null
     */
    public function migrationNote(array $report): ?array
    {
        $unmatched = [];
        $differing = [];
        foreach ((array)($report['forms'] ?? []) as $form) {
            foreach ((array)($form['unmatched'] ?? []) as $row) {
                $unmatched[] = ['form' => (string)$form['form']] + (array)$row;
            }
            foreach ((array)($form['differing'] ?? []) as $row) {
                $differing[] = ['form' => (string)$form['form']] + (array)$row;
            }
        }
        $skipped = array_values((array)($report['skipped'] ?? []));
        if ($unmatched === [] && $differing === [] && $skipped === []) {
            return null;
        }
        return ['unmatched' => $unmatched, 'differing' => $differing, 'skipped' => $skipped, 'ran_at' => (string)($report['ran_at'] ?? '')];
    }

    /** The versions of one form (language => entry), or none when there is no such form. @return array<string, ContentItem> */
    public function versions(string $slug): array
    {
        $versions = [];
        if ($slug === '') {
            return $versions;
        }
        foreach ($this->content->getItems('forms', null, true, false) as $item) {
            if ($item->slug === $slug) {
                $versions[$item->lang] = $item;
            }
        }
        return $versions;
    }

    /**
     * Deletes submissions: one (`delete`, by `id`) or the ticked ones (`bulk_delete`, by `selected_ids`).
     *
     * @param array<string, mixed> $post
     * @return array{deleted: int, ids: string[], files: int} files: how many attached files went with them
     */
    public function deleteSubmissions(string $slug, array $post): array
    {
        $action = (string)($post['submission_action'] ?? '');
        if (!in_array($action, ['delete', 'bulk_delete'], true)) {
            return ['deleted' => 0, 'ids' => [], 'files' => 0];
        }
        $ids = $action === 'delete'
            ? [(string)($post['id'] ?? '')]
            : (is_array($post['selected_ids'] ?? null) ? array_map('strval', $post['selected_ids']) : []);
        $deleted = 0;
        $files = 0;
        foreach ($ids as $id) {
            $count = FormSubmissionRepository::isValidId($id) ? $this->submissions->uploads()->count($slug, $id) : 0;
            if ($this->submissions->delete($slug, $id)) {
                $deleted++;
                $files += $count;
            }
        }
        return ['deleted' => $deleted, 'ids' => $ids, 'files' => $files];
    }

    /**
     * One page of a form's submissions, each with its fields labelled the way the form labels them.
     *
     * @param array<string, mixed> $get lang, q, date_from, date_to (YYYY-MM-DD), has_files, page, per_page
     * @return array{rows: array<int, array<string, mixed>>, filters: array<string, string>, total_all: int, total: int, recent: int, page: int, total_pages: int, per_page: int, has_file_field: bool}
     */
    public function page(ContentItem $form, array $get): array
    {
        $filters = [
            'lang' => Slug::plain((string)($get['lang'] ?? '')),
            'q' => trim((string)($get['q'] ?? '')),
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($get['date_from'] ?? '')) ? (string)$get['date_from'] : '',
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($get['date_to'] ?? '')) ? (string)$get['date_to'] : '',
            'has_files' => (string)($get['has_files'] ?? '') !== '' ? '1' : '',
        ];
        $perPage = (int)($get['per_page'] ?? 25);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 25;
        $all = $this->submissions->all($form->slug);
        $filtered = $this->submissions->filter($all, $filters);
        $total = count($filtered);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, (int)($get['page'] ?? 1)), $totalPages);

        $labels = [];
        $formFields = FormFields::normalize($form->meta['fields'] ?? []);
        foreach ($formFields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name !== '') {
                $labels[$name] = (string)($field['label'] ?? Slug::title($name));
            }
        }
        $rows = [];
        foreach (array_slice($filtered, ($page - 1) * $perPage, $perPage) as $entry) {
            $fields = [];
            foreach ($entry['fields'] as $key => $value) {
                $fields[] = ['key' => (string)$key, 'label' => $labels[(string)$key] ?? Slug::title((string)$key), 'value' => FormProcessor::text($value)];
            }
            $summaryParts = [];
            foreach ($fields as $field) {
                if (in_array($field['key'], ['name', 'full_name', 'email'], true) || $field['value'] === '' || isset($entry['files'][$field['key']])) {
                    continue;
                }
                $summaryParts[] = $field['value'];
                if (count($summaryParts) >= 2) {
                    break;
                }
            }
            // The files of the submission, each with the way to download it (the admin gives them out; they are not on the web).
            $files = [];
            foreach ($entry['files'] as $fieldName => $list) {
                $items = [];
                foreach (is_array($list) ? $list : [] as $file) {
                    $items[] = [
                        'name' => (string)($file['name'] ?? ''),
                        'original' => (string)($file['original'] ?? ''),
                        'size' => Format::bytes((int)($file['size'] ?? 0)),
                        'url' => 'admin/form-file?' . http_build_query(['slug' => $form->slug, 'id' => $entry['id'], 'f' => (string)($file['name'] ?? '')]),
                    ];
                }
                if ($items !== []) {
                    $files[] = ['label' => $labels[(string)$fieldName] ?? Slug::title((string)$fieldName), 'items' => $items];
                }
            }
            $rows[] = [
                'files' => $files,
                'file_count' => FormSubmissionRepository::fileCount($entry),
                'zip_url' => 'admin/form-files-zip?' . http_build_query(['slug' => $form->slug, 'id' => $entry['id']]),
                'id' => $entry['id'],
                'submitted_at' => FormProcessor::date((string)$entry['submitted_at']),
                'lang' => (string)$entry['lang'],
                'title' => FormProcessor::title($entry['fields']),
                'email' => FormProcessor::replyTo($entry['fields'], $formFields, ''),
                'summary' => mb_strimwidth(implode(' · ', $summaryParts), 0, 120, '…'),
                'fields' => $fields,
                'ip' => (string)$entry['ip'],
                'user_agent' => (string)$entry['user_agent'],
            ];
        }
        return [
            'rows' => $rows,
            'filters' => $filters,
            'total_all' => count($all),
            'total' => $total,
            'recent' => $this->submissions->stats($form->slug)['last_7_days'],
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'has_file_field' => count(array_filter($formFields, static fn(array $field): bool => ($field['type'] ?? '') === 'file')) > 0,
        ];
    }

    /**
     * A form's submissions as the editor's Submissions tab lists them, newest first, every field labelled from its name.
     *
     * @return array<int, array{id: string, submitted_at: string, title: string, fields: array<int, array{label: string, value: string}>}>
     */
    public function recent(string $slug): array
    {
        $entries = [];
        foreach ($this->submissions->all($slug) as $data) {
            $fields = [];
            foreach ($data['fields'] ?? [] as $key => $value) {
                $fields[] = ['label' => Slug::title((string)$key), 'value' => FormProcessor::text($value)];
            }
            $entries[] = [
                'id' => (string)($data['id'] ?? ''),
                'submitted_at' => FormProcessor::date((string)($data['submitted_at'] ?? '')),
                'title' => FormProcessor::title($data['fields'] ?? []),
                'fields' => $fields,
            ];
        }
        usort($entries, static fn(array $a, array $b): int => strcmp((string)$b['submitted_at'], (string)$a['submitted_at']));
        return $entries;
    }

    /**
     * Every submission of a form as rows for a CSV file: the fixed columns, then one for each field any submission has.
     *
     * @return array{filename: string, headers: string[], rows: array<int, array<int, string>>, count: int}
     */
    public function export(ContentItem $form, string $siteTitle): array
    {
        $submissions = $this->submissions->all($form->slug);
        $fieldKeys = [];
        foreach ($submissions as $submission) {
            foreach (array_keys($submission['fields'] ?? []) as $key) {
                if (!in_array($key, $fieldKeys, true)) {
                    $fieldKeys[] = $key;
                }
            }
        }
        sort($fieldKeys);
        $headers = array_merge(self::EXPORT_HEADERS, $fieldKeys);
        $formTitle = (string)($form->meta['title'] ?? $form->slug);

        $rows = [];
        foreach ($submissions as $submission) {
            $row = [];
            foreach ($headers as $header) {
                $row[] = match (true) {
                    in_array($header, ['id', 'submitted_at', 'form', 'lang', 'translation_id', 'ip', 'user_agent'], true) => (string)($submission[$header] ?? ''),
                    $header === 'site_title' => $siteTitle,
                    $header === 'form_title' => $formTitle,
                    default => FormProcessor::text($submission['fields'][$header] ?? ''),
                };
            }
            $rows[] = $row;
        }
        $siteSlug = Slug::plain($siteTitle !== '' ? $siteTitle : 'site') ?: 'site';
        $formSlug = Slug::plain($formTitle) ?: $form->slug;
        return ['filename' => $siteSlug . '-' . $formSlug . '-submissions.csv', 'headers' => $headers, 'rows' => $rows, 'count' => count($submissions)];
    }
}
