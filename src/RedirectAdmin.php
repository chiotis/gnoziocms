<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Redirects screen does: checking and saving a redirect typed in the form, switching one on or off, deleting,
 * importing a list, keeping the list of addresses visitors asked for that do not exist, and describing each redirect
 * for the list (does its target exist, is it a chain or a loop, does a page hide it). Also which permanent redirects
 * are ready for the links in content to be pointed at their targets.
 */
final class RedirectAdmin
{
    /** How many lines of a pasted list are read. */
    private const IMPORT_LINES = 500;

    /** @param callable(string, string, ?string, ?string, ?string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context */
    public function __construct(
        private RedirectRepository $repo,
        private PublicPaths $paths,
        private $log
    ) {
    }

    /** @return array{id: int, source: string, target: string, code: int, note: string, enabled: bool} */
    public function blankForm(): array
    {
        return ['id' => 0, 'source' => '', 'target' => '', 'code' => 301, 'note' => '', 'enabled' => true];
    }

    /**
     * The form for a redirect to change, or one started from an address (the link from a "not found" row or a delete).
     *
     * @return array{id: int, source: string, target: string, code: int, note: string, enabled: bool}
     */
    public function formFor(int $editId, ?string $source, string $target): array
    {
        $form = $this->blankForm();
        if ($editId > 0 && ($row = $this->repo->find($editId)) !== null) {
            return ['id' => (int)$row['id'], 'source' => '/' . $row['source'], 'target' => (string)$row['target'], 'code' => (int)$row['status_code'], 'note' => (string)($row['note'] ?? ''), 'enabled' => (bool)$row['enabled']];
        }
        if ($source !== null) {
            $form['source'] = '/' . RedirectRepository::normalizePath($source);
            $form['target'] = trim($target);
        }
        return $form;
    }

    /**
     * Carries out a submitted action.
     *
     * @param array<string, mixed> $post
     * @return array{location: ?string, error: string, form: array<string, mixed>, report: ?array{added: int, skipped: array<int, array{line: int, text: string, reason: string}>}, tab: ?string}
     *         `location` is where to send the browser (nothing else to show); otherwise the screen is drawn again with the error and the form as typed
     */
    public function apply(array $post, string $by): array
    {
        $result = ['location' => null, 'error' => '', 'form' => $this->blankForm(), 'report' => null, 'tab' => null];
        $do = (string)($post['do'] ?? '');
        if (!$this->repo->isAvailable()) {
            $result['location'] = '/admin/redirects?error=store';
            return $result;
        }

        if ($do === 'add' || $do === 'update') {
            $id = $do === 'update' ? (int)($post['id'] ?? 0) : 0;
            $form = [
                'id' => $id,
                'source' => trim((string)($post['source'] ?? '')),
                'target' => $this->paths->localize((string)($post['target'] ?? '')),
                'code' => (int)($post['code'] ?? 301),
                'note' => trim((string)($post['note'] ?? '')),
                'enabled' => $do === 'add' || (string)($post['enabled'] ?? '') === '1',
            ];
            $result['form'] = $form;
            $error = $this->problem($form['source'], $form['target'], $form['code'], $id ?: null);
            $normalized = RedirectRepository::normalizePath($form['source']);
            if ($error !== '') {
                $result['error'] = $error;
                return $result;
            }
            if ($do === 'add') {
                $this->repo->create($normalized, $form['target'], $form['code'], 'manual', $form['note'], $by);
                $this->log('redirects.create', 'info', 'redirect', $normalized, 'Redirect added.', ['target' => $form['target'], 'code' => $form['code']]);
                $result['location'] = '/admin/redirects?done=added';
                return $result;
            }
            $before = $this->repo->find($id);
            if ($before === null) {
                $result['location'] = '/admin/redirects';
                return $result;
            }
            $this->repo->update($id, $normalized, $form['target'], $form['code'], $form['enabled'], $form['note']);
            $this->log('redirects.update', 'info', 'redirect', $normalized, 'Redirect changed.', ['from_target' => (string)$before['target'], 'target' => $form['target'], 'code' => $form['code'], 'enabled' => $form['enabled']]);
            $result['location'] = '/admin/redirects?done=updated';
            return $result;
        }

        if ($do === 'delete' || $do === 'bulk_delete') {
            $ids = $do === 'delete' ? [(int)($post['id'] ?? 0)] : (array)($post['ids'] ?? []);
            $removed = $this->repo->deleteMany($ids);
            $this->log('redirects.delete', 'warning', 'redirect', implode(',', array_map('intval', $ids)), 'Redirects deleted.', ['count' => $removed]);
            $result['location'] = '/admin/redirects?done=deleted&n=' . $removed;
            return $result;
        }

        if ($do === 'toggle') {
            $row = $this->repo->find((int)($post['id'] ?? 0));
            if ($row !== null) {
                $this->repo->update((int)$row['id'], (string)$row['source'], (string)$row['target'], (int)$row['status_code'], !(bool)$row['enabled'], (string)($row['note'] ?? ''));
                $this->log('redirects.update', 'info', 'redirect', (string)$row['source'], !(bool)$row['enabled'] ? 'Redirect turned on.' : 'Redirect turned off.', []);
            }
            $result['location'] = '/admin/redirects?done=updated';
            return $result;
        }

        if ($do === 'import') {
            $result['report'] = $this->import((string)($post['lines'] ?? ''), $by);
            $result['tab'] = 'redirects';
            return $result;
        }

        if ($do === 'missing_delete') {
            $this->repo->ignoreNotFound((string)($post['path'] ?? ''));
            $result['location'] = '/admin/redirects?tab=missing&done=ignored';
            return $result;
        }

        if ($do === 'missing_ignore_all') {
            $q = trim((string)($post['q'] ?? ''));
            $count = $this->repo->ignoreAllNotFound($q);
            $this->log('redirects.missing_ignore_all', 'info', 'redirect', 'not_found_log', 'Missing addresses ignored.', ['count' => $count, 'search' => $q]);
            $result['location'] = '/admin/redirects?tab=missing&done=ignored_all&n=' . $count;
            return $result;
        }

        if ($do === 'missing_restore') {
            $this->repo->restoreNotFound((string)($post['path'] ?? ''));
            $result['location'] = '/admin/redirects?tab=missing&show=ignored&done=restored';
            return $result;
        }

        if ($do === 'missing_restore_all') {
            $count = $this->repo->restoreAllNotFound(trim((string)($post['q'] ?? '')));
            $this->log('redirects.missing_restore_all', 'info', 'redirect', 'not_found_log', 'Ignored addresses listed again.', ['count' => $count]);
            $result['location'] = '/admin/redirects?tab=missing&done=restored_all&n=' . $count;
            return $result;
        }

        if ($do === 'missing_clear') {
            $this->repo->clearNotFound();
            $this->log('redirects.missing_clear', 'info', 'redirect', 'not_found_log', 'List of missing addresses cleared.', []);
            $result['location'] = '/admin/redirects?tab=missing&done=cleared';
            return $result;
        }

        return $result;
    }

    /**
     * Adds the redirects of a pasted list: one per line, "old new" separated by a comma, a tab, an arrow, or a space,
     * with an optional 301 or 302. Lines that cannot be used are reported, not stopped on.
     *
     * @return array{added: int, skipped: array<int, array{line: int, text: string, reason: string}>}
     */
    public function import(string $text, string $by): array
    {
        $report = ['added' => 0, 'skipped' => []];
        $lines = preg_split('/\R/', $text) ?: [];
        foreach (array_slice($lines, 0, self::IMPORT_LINES) as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s*(?:,|\t|->|=>|\s)\s*/', $line, 3) ?: [];
            $source = (string)($parts[0] ?? '');
            $target = $this->paths->localize((string)($parts[1] ?? ''));
            $code = in_array((int)($parts[2] ?? 301), [301, 302], true) ? (int)($parts[2] ?? 301) : 301;
            $problem = $this->problem($source, $target, $code, null, true);
            if ($problem !== '') {
                $report['skipped'][] = ['line' => $number + 1, 'text' => mb_substr($line, 0, 80), 'reason' => $problem];
                continue;
            }
            $this->repo->create(RedirectRepository::normalizePath($source), $target, $code, 'manual', 'Imported', $by);
            $report['added']++;
        }
        $this->log('redirects.import', 'info', 'redirect', 'import', 'Redirects imported.', ['added' => $report['added'], 'skipped' => count($report['skipped'])]);
        return $report;
    }

    /**
     * The rows of the list, each with what the screen shows about its target and whether a page hides it.
     *
     * @param array<string, string> $filters q, origin, state
     * @return array<int, array<string, mixed>>
     */
    public function rows(array $filters, int $perPage, int $page): array
    {
        $rows = [];
        foreach ($this->repo->all($filters, $perPage, ($page - 1) * $perPage) as $row) {
            $target = (string)$row['target'];
            $state = 'external';
            if (!RedirectRepository::isExternal($target)) {
                $next = RedirectRepository::normalizePath($target);
                if ($this->paths->exists($next)) {
                    $state = 'ok';
                } elseif ($this->repo->findBySource($next) !== null) {
                    $state = $this->repo->resolve((string)$row['source']) === null ? 'loop' : 'chain';
                } else {
                    $state = 'missing';
                }
            }
            $row['target_state'] = $state;
            $row['shadowed'] = $this->paths->exists((string)$row['source']);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The addresses visitors asked for that do not exist, each with the existing address that looks most like it
     * (or, with $ignored, the ones chosen to be ignored).
     *
     * @return array<int, array<string, mixed>>
     */
    public function missing(string $q, int $limit = 200, bool $ignored = false): array
    {
        $known = $this->paths->map();
        $rows = [];
        foreach ($this->repo->notFound($q, $limit, $ignored) as $row) {
            $row['suggestion'] = PublicPaths::suggest((string)$row['path'], $known);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Where each redirect's old address should now be linked to, for redirects that are permanent and on.
     *
     * @param int[] $ids
     * @return array<string, string> normalised source => target
     */
    public function linkFixes(array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $row = $this->repo->find($id);
            if ($row === null || !(bool)$row['enabled'] || (int)$row['status_code'] !== 301) {
                continue;
            }
            // The end of a chain, so a link never goes through two redirects.
            $resolved = $this->repo->resolve((string)$row['source']);
            if ($resolved !== null && $resolved['code'] === 301) {
                $map[(string)$row['source']] = $resolved['target'];
            }
        }
        return $map;
    }

    /** Why a redirect cannot be made ('' when it can): the checks of the repository, a redirect that exists, or a page that would hide it. */
    private function problem(string $source, string $target, int $code, ?int $ignoreId, bool $anyDuplicate = false): string
    {
        $error = (string)$this->repo->validate($source, $target, $code, $ignoreId);
        if ($error !== '') {
            return $error;
        }
        $normalized = RedirectRepository::normalizePath($source);
        $existing = $this->repo->findBySource($normalized, false);
        if ($existing !== null && ($anyDuplicate || (int)$existing['id'] !== (int)$ignoreId)) {
            return 'duplicate';
        }
        return $this->paths->exists($normalized) ? 'source_has_page' : '';
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, string $level, ?string $type, ?string $id, string $message, array $context): void
    {
        ($this->log)($action, $level, $type, $id, $message, $context);
    }
}
