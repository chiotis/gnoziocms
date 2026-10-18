<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the Media screen does on top of `MediaLibrary`: which page of which list is asked for (and how the list the
 * person was looking at is kept across an action), uploading several files at once within the storage limit, saving
 * tags and text, deleting one or many (files that content still uses are only deleted one at a time, after asking),
 * adding tags in bulk, the list itself with where each file is used, and the page of pictures the image picker asks for.
 */
final class MediaAdmin
{
    public const VIEWS = ['list', 'thumbs'];
    public const PER_PAGE_OPTIONS = [20, 50, 100];
    private const USAGE_FILTERS = ['used', 'unused'];

    /**
     * @param callable(int): bool $storageAllows whether a file of that many bytes still fits in the site's storage
     * @param callable(): string $storageFullMessage what to say when it does not
     * @param callable(int): void $uploadsChanged told when uploads grew or shrank by that many bytes
     * @param callable(): int $maxUploadBytes the largest file allowed, 0 for no limit
     * @param callable(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(
        private MediaLibrary $media,
        private MediaUsage $usage,
        private $storageAllows,
        private $storageFullMessage,
        private $uploadsChanged,
        private $maxUploadBytes,
        private $log
    ) {
    }

    /** Where a media item is used, as names for a message: "About, Home page and 3 more". @param array<int, array{label: string}> $places */
    public static function placesSentence(array $places): string
    {
        $names = array_values(array_unique(array_map(static fn(array $place): string => $place['label'], $places)));
        $shown = array_slice($names, 0, 3);
        $more = count($names) - count($shown);
        return implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '');
    }

    /**
     * The list asked for in the address. The view (list or thumbnails) is remembered by the caller when it was asked for.
     *
     * @param array<string, mixed> $get
     * @return array{type: string, tag: string, q: string, usage: string, page: int, per_page: int, view: string, view_asked: bool}
     */
    public function state(array $get, string $storedView): array
    {
        $type = strtolower(trim((string)($get['type'] ?? 'all')));
        $usage = strtolower(trim((string)($get['usage'] ?? '')));
        $perPage = (int)($get['per_page'] ?? 20);
        $asked = strtolower(trim((string)($get['view'] ?? '')));
        $stored = strtolower($storedView);
        return [
            'type' => in_array($type, $this->media->typeOptions(), true) ? $type : 'all',
            'tag' => $this->media->sanitizeTag((string)($get['tag'] ?? '')),
            'q' => trim((string)($get['q'] ?? '')),
            'usage' => in_array($usage, self::USAGE_FILTERS, true) ? $usage : '',
            'page' => max(1, (int)($get['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 20,
            'view' => in_array($asked, self::VIEWS, true) ? $asked : (in_array($stored, self::VIEWS, true) ? $stored : 'thumbs'),
            'view_asked' => in_array($asked, self::VIEWS, true),
        ];
    }

    /**
     * The list a form was sent from (hidden `_state_*` fields), so the person lands where they were.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $current the list asked for in the address, used for what the form does not carry
     * @return array{type: string, tag: string, q: string, usage: string, page: int, per_page: int, view: string}
     */
    public function postState(array $post, array $current): array
    {
        $type = strtolower(trim((string)($post['_state_type'] ?? $current['type'])));
        $view = strtolower(trim((string)($post['_state_view'] ?? $current['view'])));
        $perPage = (int)($post['_state_per_page'] ?? $current['per_page']);
        $usage = strtolower(trim((string)($post['_state_usage'] ?? $current['usage'])));
        return [
            'usage' => in_array($usage, self::USAGE_FILTERS, true) ? $usage : '',
            'type' => in_array($type, $this->media->typeOptions(), true) ? $type : 'all',
            'tag' => $this->media->sanitizeTag((string)($post['_state_tag'] ?? $current['tag'])),
            'q' => trim((string)($post['_state_q'] ?? $current['q'])),
            'view' => in_array($view, self::VIEWS, true) ? $view : 'thumbs',
            'per_page' => in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 20,
            'page' => max(1, (int)($post['_state_page'] ?? $current['page'])),
        ];
    }

    /**
     * The address parameters that bring the person back to the list they were on, with what happened added.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params success, error, page…; empty values are left out
     * @return array<string, mixed>
     */
    public function returnQuery(array $state, array $params): array
    {
        $query = ['type' => $state['type'], 'view' => $state['view'], 'per_page' => $state['per_page'], 'page' => $state['page']];
        foreach (['tag', 'q', 'usage'] as $key) {
            if ($state[$key] !== '') {
                $query[$key] = $state[$key];
            }
        }
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }
        return $query;
    }

    /**
     * Carries out a submitted action.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files $_FILES
     * @return array<string, mixed>|null what to add to the address of the list (success, error, page), or null when the form asked for nothing
     */
    public function apply(array $post, array $files, string $by): ?array
    {
        $action = trim((string)($post['media_action'] ?? ''));
        if ($action === '' && isset($post['delete'])) {
            $action = 'delete';
        }
        if ($action === '' && (isset($files['asset']) || isset($files['upload_file']))) {
            $action = 'upload';
        }
        return match ($action) {
            'upload' => $this->upload($post, $files, $by),
            'save_tags' => $this->saveTags($post),
            'delete' => $this->deleteOne($post),
            'bulk_tags', 'bulk_delete' => $this->bulk($post, $action),
            default => null,
        };
    }

    /**
     * The main image uploaded with an entry's form, which replaces the address typed in its field. A file that is
     * not a picture is removed again; an upload that fails leaves the field as it was.
     *
     * @return array{url: ?string, blocked: bool} the address of the new picture, and whether the storage limit kept the file out
     */
    public function uploadMainImage(mixed $rawUpload, string $by): array
    {
        $uploads = $this->media->normalizeUploads($rawUpload);
        if ($uploads === []) {
            return ['url' => null, 'blocked' => false];
        }
        $upload = $uploads[0];
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['url' => null, 'blocked' => false];
        }
        $size = (int)($upload['size'] ?? 0);
        if (!($this->storageAllows)($size)) {
            return ['url' => null, 'blocked' => true];
        }
        try {
            $this->media->ensureDirectories();
            $uploaded = $this->media->upload($upload, '', $by, ($this->maxUploadBytes)());
            ($this->uploadsChanged)($size);
            if ((string)($uploaded['kind'] ?? '') === 'image') {
                return ['url' => isset($uploaded['direct_url']) ? (string)$uploaded['direct_url'] : null, 'blocked' => false];
            }
            $id = (string)($uploaded['id'] ?? '');
            if ($id !== '') {
                $this->deleteItem($id);
            }
        } catch (\Throwable) {
        }
        return ['url' => null, 'blocked' => false];
    }

    /** Deletes a media item and takes its size off the kept measurement of the uploads. */
    public function deleteItem(string $id): bool
    {
        $size = (int)($this->media->find($id)['size_bytes'] ?? 0);
        if (!$this->media->delete($id)) {
            return false;
        }
        ($this->uploadsChanged)(-$size);
        return true;
    }

    /**
     * One page of the list, each file with where it is used.
     *
     * @param array<string, mixed> $state from state()
     * @return array{items: array<int, array<string, mixed>>, total_items: int, total_pages: int, page: int, unused_total: int}
     */
    public function listing(array $state): array
    {
        $all = $this->media->list(['type' => $state['type'], 'tag' => $state['tag'], 'q' => $state['q']]);
        $usageMap = $this->usage->all();
        $unused = 0;
        foreach ($all as $index => $item) {
            $places = $usageMap[(string)($item['path'] ?? '')] ?? [];
            $all[$index]['places'] = $places;
            $all[$index]['places_sentence'] = $places !== [] ? self::placesSentence($places) : '';
            $unused += $places === [] ? 1 : 0;
        }
        if ($state['usage'] !== '') {
            $all = array_values(array_filter($all, static fn(array $item): bool => ($item['places'] === []) === ($state['usage'] === 'unused')));
        }
        $total = count($all);
        $pages = max(1, (int)ceil($total / $state['per_page']));
        $page = min($state['page'], $pages);
        return [
            'items' => array_slice($all, ($page - 1) * $state['per_page'], $state['per_page']),
            'total_items' => $total,
            'total_pages' => $pages,
            'page' => $page,
            'unused_total' => $unused,
        ];
    }

    /**
     * A page of pictures for the image picker.
     *
     * @param array<string, mixed> $get kind (image, video or all), tag, q, page, per_page
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int, tags: string[]}
     */
    public function picker(array $get): array
    {
        $asked = (string)($get['kind'] ?? 'image');
        $kind = in_array($asked, ['all', 'video', 'track'], true) ? $asked : 'image';
        $perPage = max(6, min(48, (int)($get['per_page'] ?? 24)));
        $all = $this->media->list(['type' => $kind, 'tag' => $this->media->sanitizeTag((string)($get['tag'] ?? '')), 'q' => trim((string)($get['q'] ?? ''))]);
        $total = count($all);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($pages, (int)($get['page'] ?? 1)));
        $items = [];
        foreach (array_slice($all, ($page - 1) * $perPage, $perPage) as $item) {
            $items[] = [
                'url' => (string)($item['direct_url'] ?? ''),
                'thumb' => (string)(($item['thumbnail_url'] ?? '') ?: ($item['direct_url'] ?? '')),
                'name' => (string)($item['original_name'] ?? ''),
                'alt' => (string)($item['alt'] ?? ''),
                'kind' => (string)($item['kind'] ?? ''),
                'tags' => array_values(array_map('strval', is_array($item['tags'] ?? null) ? $item['tags'] : [])),
            ];
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'tags' => $this->media->availableTags()];
    }

    /** @return array<string, mixed> */
    private function upload(array $post, array $files, string $by): array
    {
        $uploads = $this->media->normalizeUploads($files['upload_file'] ?? ($files['asset'] ?? null));
        if ($uploads === []) {
            return ['error' => 'No file uploaded.'];
        }
        $tagsCsv = trim((string)($post['upload_tags'] ?? ''));
        $uploaded = 0;
        $failed = 0;
        $lastError = '';
        foreach ($uploads as $upload) {
            if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (!($this->storageAllows)((int)($upload['size'] ?? 0))) {
                $failed++;
                $lastError = ($this->storageFullMessage)();
                continue;
            }
            try {
                $item = $this->media->upload($upload, $tagsCsv, $by, ($this->maxUploadBytes)());
                ($this->uploadsChanged)((int)($upload['size'] ?? 0));
                $uploaded++;
                $this->log('media.upload', 'info', 'media', (string)($item['id'] ?? ''), 'Media uploaded.', [
                    'filename' => (string)($item['filename'] ?? ''),
                    'kind' => (string)($item['kind'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();
            }
        }
        if ($uploaded > 0 && $failed === 0) {
            return ['page' => 1, 'success' => $uploaded === 1 ? 'Upload complete.' : 'Uploaded ' . $uploaded . ' files.'];
        }
        if ($uploaded > 0) {
            return ['page' => 1, 'success' => 'Uploaded ' . $uploaded . ' files.', 'error' => $failed . ' uploads failed' . ($lastError !== '' ? ': ' . $lastError : '.')];
        }
        return ['error' => $lastError !== '' ? 'Upload failed: ' . $lastError : 'Upload failed.'];
    }

    /** @return array<string, mixed> */
    private function saveTags(array $post): array
    {
        $id = $this->media->sanitizeId((string)($post['id'] ?? ''));
        if ($id === '') {
            return ['error' => 'Invalid media item.'];
        }
        $alt = isset($post['alt']) ? (string)$post['alt'] : null;
        if (!$this->media->updateTags($id, (string)($post['tags'] ?? ''), $alt)) {
            return ['error' => 'Media item not found.'];
        }
        $this->log('media.tags_update', 'info', 'media', $id, 'Media details updated.', []);
        return ['success' => 'Saved.'];
    }

    /** @return array<string, mixed> */
    private function deleteOne(array $post): array
    {
        $id = $this->media->sanitizeId((string)($post['id'] ?? ''));
        if ($id === '') {
            // Forms from before items had ids sent the file name.
            $legacyFilename = $this->safeFilename((string)($post['filename'] ?? ''));
            if ($legacyFilename !== '') {
                $id = $this->media->findByFilename($legacyFilename)['id'] ?? '';
            }
        }
        if ($id === '') {
            return ['error' => 'Invalid media item.'];
        }
        $doomed = $this->media->find($id);
        $places = $doomed !== null ? $this->usage->placesFor((string)($doomed['path'] ?? '')) : [];
        if ($places !== [] && (string)($post['confirm_used'] ?? '') !== '1') {
            // The screen asks first and sends this flag; without it nothing is deleted.
            return ['error' => 'Not deleted: this file is used in ' . self::placesSentence($places) . '.'];
        }
        if (!$this->deleteItem($id)) {
            return ['error' => 'Media item not found.'];
        }
        $this->log('media.delete', 'warning', 'media', $id, 'Media item deleted.', $places !== [] ? ['was_used_in' => array_map(static fn(array $place): string => $place['label'], $places)] : []);
        return ['success' => 'Media item deleted.'];
    }

    /** @return array<string, mixed> */
    private function bulk(array $post, string $action): array
    {
        $ids = $this->media->collectIds($post['selected_ids'] ?? []);
        if (((string)($post['apply_all_filtered'] ?? '0')) === '1') {
            $ids = $this->filteredIds($post);
        }
        if ($ids === []) {
            return ['error' => 'Select at least one media item.'];
        }

        if ($action === 'bulk_tags') {
            $bulkTags = trim((string)($post['tags'] ?? ''));
            $updated = 0;
            foreach ($ids as $id) {
                $item = $this->media->find($id);
                if ($item === null) {
                    continue;
                }
                $existing = implode(',', is_array($item['tags'] ?? null) ? $item['tags'] : []);
                if ($this->media->updateTags($id, trim($existing . ',' . $bulkTags, ', '))) {
                    $updated++;
                }
            }
            if ($updated === 0) {
                return ['error' => 'No media items were updated.'];
            }
            $this->log('media.bulk_tags_update', 'info', 'media', 'bulk', 'Bulk media tags updated.', ['count' => $updated]);
            return ['success' => 'Updated tags for ' . $updated . ' items.'];
        }

        // Files that content still points at are kept: deleting them would leave broken images. They are deleted one at a time, after asking.
        $deleted = 0;
        $kept = 0;
        foreach ($ids as $id) {
            $doomed = $this->media->find($id);
            if ($doomed !== null && $this->usage->placesFor((string)($doomed['path'] ?? '')) !== []) {
                $kept++;
                continue;
            }
            if ($this->deleteItem($id)) {
                $deleted++;
            }
        }
        $note = $kept > 0 ? ' ' . $kept . ($kept === 1 ? ' file was kept because it is' : ' files were kept because they are') . ' in use.' : '';
        if ($deleted === 0) {
            return ['error' => ($kept > 0 ? 'Nothing deleted.' : 'No media items were deleted.') . $note];
        }
        $this->log('media.bulk_delete', 'warning', 'media', 'bulk', 'Bulk media items deleted.', ['count' => $deleted, 'kept_in_use' => $kept]);
        return ['success' => 'Deleted ' . $deleted . ' items.' . $note];
    }

    /** The ids of every item the list the form was sent from shows (the "select all N" choice). @return string[] */
    private function filteredIds(array $post): array
    {
        $type = strtolower(trim((string)($post['_filter_type'] ?? 'all')));
        $usage = strtolower(trim((string)($post['_filter_usage'] ?? '')));
        $filtered = $this->media->list([
            'type' => in_array($type, $this->media->typeOptions(), true) ? $type : 'all',
            'tag' => $this->media->sanitizeTag((string)($post['_filter_tag'] ?? '')),
            'q' => trim((string)($post['_filter_q'] ?? '')),
        ]);
        if (in_array($usage, self::USAGE_FILTERS, true)) {
            $inUse = $this->usage->all();
            $filtered = array_values(array_filter($filtered, static fn(array $item): bool => (($inUse[(string)($item['path'] ?? '')] ?? []) !== []) === ($usage === 'used')));
        }
        $ids = array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $filtered);
        return array_values(array_filter(array_unique($ids), static fn(string $id): bool => $id !== ''));
    }

    private function safeFilename(string $name): string
    {
        $name = strtolower(Slug::transliterateGreek(trim($name)));
        $name = trim((string)preg_replace('/[^a-z0-9\\.\\-_]+/', '-', $name), '-');
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $base = (string)pathinfo($name, PATHINFO_FILENAME);
        if ($base === '' || $base === '.') {
            $base = 'file';
        }
        return $base . ($ext !== '' ? '.' . $ext : '');
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, string $level, ?string $type, ?string $id, string $message, array $context): void
    {
        ($this->log)($action, $level, $type, $id, $message, $context);
    }
}
