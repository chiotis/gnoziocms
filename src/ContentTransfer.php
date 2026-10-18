<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Moving content in and out as a table: the file to download with every entry of a type in every language, and
 * the import in two steps (upload a CSV and see what would change, then apply it). The preview waits in the
 * session, under a token, for an hour; the caller hands the session's array in, so the class never touches
 * `$_SESSION` itself.
 */
final class ContentTransfer
{
    private const PREVIEW_SECONDS = 3600;

    /**
     * @param \Closure(): array{ok: bool, indexed: int, removed: int, took_ms: int} $rebuildIndex
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(
        private ContentCsv $csv,
        private ContentRepository $content,
        private \Closure $rebuildIndex,
        private \Closure $log
    ) {
    }

    /**
     * The file of every entry of a type, in every language.
     *
     * @return array{filename: string, headers: string[], rows: array<int, array<int, string>>}
     */
    public function export(string $type, string $siteName): array
    {
        $items = $this->content->getItems($type, null, true, false);
        $table = $this->csv->exportTable($type, $items, $siteName);
        $siteSlug = Slug::plain($siteName);
        $filename = ($siteSlug !== '' ? $siteSlug : 'site') . '-' . $type . '-all-languages.csv';
        ($this->log)('content.export', 'info', $type, 'all', 'Content exported.', [
            'type' => $type,
            'items' => count($items),
            'filename' => $filename,
        ]);
        return ['filename' => $filename, 'headers' => $table['headers'], 'rows' => $table['rows']];
    }

    /**
     * One step of the import: a preview of an uploaded file, or applying a preview kept under a token.
     *
     * @param array<string, mixed> $post the submitted form
     * @param array<string, mixed>|null $file the uploaded file (an entry of $_FILES)
     * @param array<string, array<string, mixed>> $previews the previews kept for this session, by token
     * @return array{location: string, view: array<string, mixed>} where to go next, or what the page shows
     */
    public function import(string $type, bool $isPost, array $post, ?array $file, array &$previews): array
    {
        $this->forgetOld($previews);

        $error = '';
        $rows = [];
        $summary = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $token = '';
        $filename = '';

        if ($isPost && (string)($post['apply'] ?? '') === '1') {
            $token = trim((string)($post['preview_token'] ?? ''));
            $kept = $previews[$token] ?? null;
            if (!is_array($kept) || ($kept['type'] ?? '') !== $type) {
                $error = 'Import preview expired. Run dry-run again.';
                $token = '';
            } else {
                $result = $this->csv->apply($type, is_array($kept['entries'] ?? null) ? $kept['entries'] : []);
                $summary = is_array($kept['summary'] ?? null) ? $kept['summary'] : $summary;
                $filename = (string)($kept['filename'] ?? '');
                if (($result['ok'] ?? false) === true) {
                    unset($previews[$token]);
                    ($this->rebuildIndex)();
                    ($this->log)('content.import_apply', 'info', $type, 'csv', 'Content import applied.', [
                        'type' => $type,
                        'filename' => $filename,
                        'summary' => $summary,
                    ]);
                    return ['location' => '/admin/import?type=' . urlencode($type) . '&saved=1', 'view' => []];
                }
                $error = (string)($result['error'] ?? 'Import failed.');
                $rows = is_array($kept['rows'] ?? null) ? $kept['rows'] : [];
            }
        } elseif ($isPost) {
            $preview = $this->preview($type, $file, $previews);
            $error = $preview['error'];
            $rows = $preview['rows'];
            $summary = $preview['summary'] ?? $summary;
            $token = $preview['token'];
            $filename = $preview['filename'];
        }

        return ['location' => '', 'view' => [
            'error' => $error,
            'preview_rows' => $rows,
            'preview_summary' => $summary,
            'preview_token' => $token,
            'source_filename' => $filename,
        ]];
    }

    /**
     * Reads an uploaded file and keeps what applying it would do.
     *
     * @param array<string, mixed>|null $file
     * @param array<string, array<string, mixed>> $previews
     * @return array{error: string, rows: array<int, mixed>, summary: ?array<string, int>, token: string, filename: string}
     */
    private function preview(string $type, ?array $file, array &$previews): array
    {
        $out = ['error' => '', 'rows' => [], 'summary' => null, 'token' => '', 'filename' => ''];
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $out['error'] = 'Please upload a valid CSV file.';
            return $out;
        }
        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            $out['error'] = 'Upload failed.';
            return $out;
        }
        $out['filename'] = (string)($file['name'] ?? 'import.csv');
        if (strtolower((string)pathinfo($out['filename'], PATHINFO_EXTENSION)) !== 'csv') {
            $out['error'] = 'Please upload a .csv file.';
            return $out;
        }
        $parsed = $this->csv->parseImport($tmpPath);
        if (($parsed['ok'] ?? false) !== true) {
            $out['error'] = (string)($parsed['error'] ?? 'Could not parse CSV.');
            return $out;
        }
        $preview = $this->csv->preview($type, (array)($parsed['rows'] ?? []), (array)($parsed['headers'] ?? []));
        $out['rows'] = $preview['rows'];
        $out['summary'] = $preview['summary'];
        if (!empty($preview['entries'])) {
            $out['token'] = bin2hex(random_bytes(12));
            $previews[$out['token']] = [
                'type' => $type,
                'entries' => $preview['entries'],
                'rows' => $preview['rows'],
                'summary' => $preview['summary'],
                'filename' => $out['filename'],
                'created_at' => time(),
            ];
        }
        ($this->log)('content.import_preview', 'info', $type, 'csv', 'Content import preview generated.', [
            'type' => $type,
            'filename' => $out['filename'],
            'summary' => $preview['summary'],
        ]);
        return $out;
    }

    /** @param array<string, mixed> $previews */
    private function forgetOld(array &$previews): void
    {
        $now = time();
        foreach ($previews as $token => $row) {
            $created = is_array($row) ? (int)($row['created_at'] ?? 0) : 0;
            if ($created === 0 || ($now - $created) > self::PREVIEW_SECONDS) {
                unset($previews[$token]);
            }
        }
    }
}
