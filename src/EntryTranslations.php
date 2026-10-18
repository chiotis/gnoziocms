<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The translations of an entry, as the admin shows them: which languages each entry of a list also exists in, and
 * for one entry the row of every language with a link to edit that translation or to start it. Translations are kept
 * together by `translation_id`; entries written before it existed are matched by their address.
 */
final class EntryTranslations
{
    /** @param callable(): array<string, mixed> $settings the site settings, read each time */
    public function __construct(private ContentRepository $content, private $settings)
    {
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ($this->settings)();
    }

    /** @return array<int, array{lang: string, exists: bool, slug: string, title: string, edit_url: string, create_url: string}> */
    public function links(string $type, string $slug, string $translationId): array
    {
        $available = $this->settings()['languages']['available'] ?? [];
        $items = $this->content->getItems($type, null, true);
        $matches = [];
        $fallback = [];

        foreach ($items as $item) {
            $itemId = (string)($item->meta['translation_id'] ?? '');
            if ($translationId !== '' && $itemId !== '' && $itemId === $translationId) {
                $matches[$item->lang] = $item;
            } elseif ($itemId === '' && $item->slug === $slug) {
                $fallback[$item->lang] = $item;
            }
        }

        $rows = [];
        foreach ($available as $lang) {
            $item = $matches[$lang] ?? $fallback[$lang] ?? null;
            $exists = $item !== null;
            $targetSlug = $item ? $item->slug : $slug;
            $rows[] = [
                'lang' => $lang,
                'exists' => $exists,
                'slug' => $targetSlug,
                'title' => $item ? (string)($item->meta['title'] ?? '') : '',
                'edit_url' => $exists
                    ? '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($targetSlug) . '&lang=' . urlencode($lang)
                    : '',
                'create_url' => $exists
                    ? ''
                    : '/admin/edit?type=' . urlencode($type) . '&slug=' . urlencode($slug) . '&lang=' . urlencode($lang) . '&translation_id=' . urlencode($translationId),
            ];
        }

        return $rows;
    }

    /** @param ContentItem[] $items */
    public function matrix(string $type, array $items): array
    {
        $all = $this->content->getItems($type, null, true);
        $langsByKey = [];
        $slugToId = [];
        foreach ($all as $item) {
            $id = (string)($item->meta['translation_id'] ?? '');
            if ($id !== '' && !isset($slugToId[$item->slug])) {
                $slugToId[$item->slug] = $id;
            }
        }
        foreach ($all as $item) {
            $key = $this->groupKey($item, $slugToId);
            $langsByKey[$key] ??= [];
            if (!in_array($item->lang, $langsByKey[$key], true)) {
                $langsByKey[$key][] = $item->lang;
            }
        }

        $matrix = [];
        foreach ($items as $item) {
            $key = $this->groupKey($item, $slugToId);
            $langs = $langsByKey[$key] ?? [];
            $other = array_values(array_filter($langs, fn($lang) => $lang !== $item->lang));
            $matrix[$item->slug . '|' . $item->lang] = $other;
        }

        return $matrix;
    }

    /** @param array<string, string> $slugToId */
    private function groupKey(ContentItem $item, array $slugToId): string
    {
        $id = (string)($item->meta['translation_id'] ?? '');
        if ($id !== '') {
            return 'id:' . $id;
        }
        $fallbackId = $slugToId[$item->slug] ?? '';
        if ($fallbackId !== '') {
            return 'id:' . $fallbackId;
        }
        return 'slug:' . $item->slug;
    }
}
