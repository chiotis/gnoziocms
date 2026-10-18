<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the History screens do on top of `RevisionRepository`: bringing back an earlier version or a deleted
 * entry, and the data of the three screens (what everyone changed lately, the versions of one entry, one version
 * compared with what is there now or with the version before it). Who may see forms is decided by the caller and
 * passed in; the class never reads the request.
 */
final class RevisionAdmin
{
    private const PER_PAGE = 50;

    /** Shown for each kind of change in the history screens. */
    public const ACTIONS = [
        'create' => 'Created',
        'save' => 'Saved',
        'import' => 'Imported',
        'restore' => 'Restored',
        'links' => 'Links updated',
        'delete' => 'Deleted',
        'external' => 'Changed outside the editor',
        'baseline' => 'Earlier version',
    ];

    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(
        private RevisionRepository $revisions,
        private ContentEditor $editor,
        private ContentRepository $content,
        private string $contentDir,
        private \Closure $settings,
        private \Closure $log
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows revisions
     * @return array<int, array<string, mixed>> the same rows with what the screens need to link and label them
     */
    public function decorate(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i]['exists'] = is_file($this->path((string)$row['type'], (string)$row['slug'], (string)$row['lang']));
            $rows[$i]['action_label'] = self::ACTIONS[$row['action']] ?? ucfirst((string)$row['action']);
            $rows[$i]['when'] = str_replace('T', ' ', substr((string)$row['created_at'], 0, 16)) . ' UTC';
        }
        return $rows;
    }

    /**
     * Brings back a version (or a deleted entry) from the submitted form.
     *
     * @param array<string, mixed> $post
     * @return array{location: string, denied: string} where to go next; `denied` names a content type the person may not touch (then there is no location)
     */
    public function restore(array $post, bool $canRawHtml, bool $seesForms, string $actor): array
    {
        $do = (string)($post['do'] ?? '');
        $revision = $this->revisions->find((int)($post['id'] ?? 0));
        if ($revision === null || !in_array($do, ['restore', 'undelete'], true)) {
            return ['location' => '/admin/revisions', 'denied' => ''];
        }
        $type = (string)$revision['type'];
        if (!$this->mayOpen($type, $seesForms)) {
            return ['location' => '', 'denied' => $type];
        }
        $slug = (string)$revision['slug'];
        $lang = (string)$revision['lang'];
        $result = $this->editor->restore($type, $slug, $lang, (string)$revision['raw'], $canRawHtml, $actor, $do === 'undelete');
        if (!$result['ok']) {
            return ['location' => '/admin/revisions?id=' . (int)$revision['id'] . '&error=' . urlencode($result['error']), 'denied' => ''];
        }
        ($this->log)($do === 'undelete' ? 'content.undelete' : 'content.restore', 'warning', $type, $slug . ':' . $lang, $do === 'undelete' ? 'Deleted content brought back.' : 'Earlier version restored.', [
            'type' => $type,
            'slug' => $slug,
            'lang' => $lang,
            'revision' => (int)$revision['id'],
            'from' => (string)$revision['created_at'],
        ]);
        return [
            'location' => '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . '&saved=1&restored=1' . ($result['html_neutralized'] ? '&notice=html' : ''),
            'denied' => '',
        ];
    }

    /**
     * The screen asked for in the address.
     *
     * @param array<string, mixed> $get
     * @return array{location: string, denied: string, template: string, data: array<string, mixed>} a location to go to, or a content type that is not allowed, or the page to show
     */
    public function screen(array $get, bool $seesForms): array
    {
        $common = [
            'types' => $this->content->getTypes(),
            'admin_section' => 'history',
            'current_type' => 'pages',
            'error' => (string)($get['error'] ?? ''),
        ];
        $go = static fn(string $location): array => ['location' => $location, 'denied' => '', 'template' => '', 'data' => []];

        if (isset($get['id'])) {
            $revision = $this->revisions->find((int)$get['id']);
            if ($revision === null || !$this->mayOpen((string)$revision['type'], $seesForms)) {
                return $go('/admin/revisions');
            }
            return ['location' => '', 'denied' => '', 'template' => '@admin/revision-view.twig', 'data' => $this->version($revision, (string)($get['mode'] ?? '')) + $common];
        }

        $page = max(1, (int)($get['page'] ?? 1));
        $filters = [
            'q' => trim((string)($get['q'] ?? '')),
            'type' => trim((string)($get['type'] ?? '')),
            'actor' => trim((string)($get['actor'] ?? '')),
            'action' => trim((string)($get['action'] ?? '')),
        ];
        if (!$seesForms) {
            $filters['exclude_type'] = 'forms';
        }
        $view = 'recent';
        $item = null;

        if ((string)($get['view'] ?? '') === 'deleted') {
            $view = 'deleted';
            $rows = $this->decorate($this->revisions->deleted(100, $seesForms ? '' : 'forms'));
            $total = count($rows);
        } elseif (isset($get['slug']) && (string)$get['slug'] !== '') {
            $type = Slug::plain((string)($get['type'] ?? 'pages')) ?: 'pages';
            if (!$this->mayOpen($type, $seesForms)) {
                return ['location' => '', 'denied' => $type, 'template' => '', 'data' => []];
            }
            $slug = Slug::plain((string)$get['slug']);
            $lang = Slug::plain((string)($get['lang'] ?? $this->defaultLang()));
            $view = 'item';
            $rows = $this->decorate($this->revisions->forItem($type, $slug, $lang, 100));
            $total = count($rows);
            $item = [
                'type' => $type,
                'slug' => $slug,
                'lang' => $lang,
                'exists' => is_file($this->path($type, $slug, $lang)),
                'title' => (string)($rows[0]['title'] ?? $slug),
            ];
        } else {
            $total = $this->revisions->count($filters);
            $rows = $this->decorate($this->revisions->recent($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE));
        }

        return ['location' => '', 'denied' => '', 'template' => '@admin/revisions.twig', 'data' => [
            'view' => $view,
            'item' => $item,
            'rows' => $rows,
            'total' => $total,
            'filters' => $filters,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / self::PER_PAGE)),
            'actors' => $this->revisions->actors(),
            'content_types' => array_values(array_filter($this->content->getTypes(), static fn(string $t): bool => $seesForms || $t !== 'forms')),
            'actions' => self::ACTIONS,
            'kept' => RevisionRepository::KEEP_PER_ITEM,
            'kept_days' => RevisionRepository::KEEP_DELETED_DAYS,
        ] + $common];
    }

    /**
     * One version: what it changed, or what restoring it would do.
     *
     * @param array<string, mixed> $revision
     * @return array<string, mixed>
     */
    private function version(array $revision, string $mode): array
    {
        $type = (string)$revision['type'];
        $slug = (string)$revision['slug'];
        $lang = (string)$revision['lang'];
        $path = $this->path($type, $slug, $lang);
        $current = is_file($path) ? (string)file_get_contents($path) : null;
        $mode = $mode === 'restore' && $current !== null ? 'restore' : 'changes';
        $previous = $this->revisions->previous($revision);
        if ($mode === 'restore') {
            $diff = LineDiff::compare((string)$current, (string)$revision['raw']);
        } else {
            $diff = LineDiff::compare($previous !== null ? (string)$previous['raw'] : '', (string)$revision['raw']);
        }
        $summary = LineDiff::summary($diff);
        $latest = $this->revisions->latest($type, $slug, $lang);
        return [
            'revision' => $this->decorate([$revision])[0],
            'previous' => $previous !== null ? $this->decorate([$previous])[0] : null,
            'mode' => $mode,
            'diff' => LineDiff::withContext($diff, 4),
            'summary' => $summary,
            'identical' => $summary['added'] === 0 && $summary['removed'] === 0,
            'item_exists' => $current !== null,
            'is_latest' => $latest !== null && (int)$latest['id'] === (int)$revision['id'],
            'edit_url' => '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang),
            'history_url' => '/admin/revisions?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang),
        ];
    }

    /** Forms hold visitors' personal data, so their history is only for those who manage forms. */
    private function mayOpen(string $type, bool $seesForms): bool
    {
        return $type !== 'forms' || $seesForms;
    }

    private function path(string $type, string $slug, string $lang): string
    {
        return $this->contentDir . '/' . $type . '/' . (new ContentPaths(($this->settings)()))->filename($slug, $lang);
    }

    private function defaultLang(): string
    {
        return (string)((($this->settings)())['languages']['default'] ?? 'en');
    }
}
