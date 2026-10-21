<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The redirects an import wrote (`redirects.txt`: "old new 301" per line) put straight into the site's Redirects, without
 * pasting them in Admin > Redirects > Import (which reads 500 lines at a time). Running it again changes nothing that is
 * already there: a line whose redirect exists with the same target is kept; one an earlier import made (its note) is pointed
 * at the new target; one the site made itself (by hand, or when an address changed here) is left alone and reported.
 * Every other check is the Redirects screen's (no admin or file addresses, no loops).
 */
final class ImportedRedirects
{
    /** The note of every redirect an import made, so the next run knows its own. */
    public const NOTE = 'Imported';

    public function __construct(private RedirectRepository $repo)
    {
    }

    /**
     * The lines of a redirects list: blank lines and `#` comments skipped, "old new" with an optional code.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) >= 2) {
                $out[] = [(string)$parts[0], (string)$parts[1]];
            }
        }
        return $out;
    }

    /**
     * Adds the redirects of a list, or (with $write false) says what it would do.
     *
     * @param array<int, array{0: string, 1: string}> $list old address, new address
     * @return array{added: int, updated: int, kept: int, skipped: array<int, array{from: string, to: string, reason: string}>}
     */
    public function apply(array $list, bool $write = true, string $by = 'import'): array
    {
        $report = ['added' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => []];
        if (!$this->repo->isAvailable()) {
            foreach ($list as [$from, $to]) {
                $report['skipped'][] = ['from' => $from, 'to' => $to, 'reason' => 'no_database'];
            }
            return $report;
        }
        $seen = [];
        foreach ($list as [$from, $to]) {
            $source = RedirectRepository::normalizePath($from);
            $target = RedirectRepository::canonicalTarget($to);
            if (isset($seen[$source])) {
                // Two old addresses that differ only in case are one source here: the first line wins.
                $report['skipped'][] = ['from' => $from, 'to' => $to, 'reason' => $seen[$source] === $target ? 'repeated' : 'duplicate'];
                continue;
            }
            $existing = $this->repo->findBySource($source, false);
            if ($existing !== null && (string)$existing['target'] === $target) {
                $seen[$source] = $target;
                $report['kept']++;
                continue;
            }
            $ours = $existing !== null && (string)($existing['note'] ?? '') === self::NOTE && (string)$existing['origin'] === 'manual';
            if ($existing !== null && !$ours) {
                $report['skipped'][] = ['from' => $from, 'to' => $to, 'reason' => 'taken'];
                continue;
            }
            $problem = $this->leadsBack($source, $target, $seen) ? 'loop' : $this->repo->validate($from, $target, 301, $existing !== null ? (int)$existing['id'] : null);
            if ($problem !== null) {
                $report['skipped'][] = ['from' => $from, 'to' => $to, 'reason' => $problem];
                continue;
            }
            $seen[$source] = $target;
            if ($existing !== null) {
                if ($write) {
                    $this->repo->update((int)$existing['id'], $source, $target, 301, true, self::NOTE);
                }
                $report['updated']++;
                continue;
            }
            if ($write) {
                try {
                    $this->repo->create($source, $target, 301, 'manual', self::NOTE, $by);
                } catch (\Throwable) {
                    $report['skipped'][] = ['from' => $from, 'to' => $to, 'reason' => 'not_saved'];
                    continue;
                }
            }
            $report['added']++;
        }
        return $report;
    }

    /**
     * Whether following the redirects of this list (the lines before this one, written or not) and then the site's from the
     * target comes back to the source. The repository's own check sees only what is stored, which a dry run never writes.
     *
     * @param array<string, string> $list source => target of the lines taken so far
     */
    private function leadsBack(string $source, string $target, array $list): bool
    {
        $at = $target;
        for ($hop = 0; $hop < RedirectRepository::MAX_HOPS && !RedirectRepository::isExternal($at); $hop++) {
            $path = RedirectRepository::normalizePath($at);
            if ($path === $source) {
                return true;
            }
            $next = $list[$path] ?? $this->repo->findBySource($path, false)['target'] ?? null;
            if ($next === null) {
                return false;
            }
            $at = (string)$next;
        }
        return false;
    }
}
