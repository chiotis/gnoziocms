<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Fields of a content type that have one value for every translation of an entry (`shared: true` in the type's YAML): a
 * product's price, SKU and variants are the same in Greek and in English. Each language file keeps its own copy, so a file
 * read on its own is whole; saving one translation writes the shared values into the others, and a translation that does not
 * have them yet (one just started) is given those of its siblings.
 */
final class SharedFields
{
    /**
     * The keys of the shared fields.
     *
     * @param array<string, array<string, mixed>> $fields normalized fields of a type (ContentTypes::definition())
     * @return string[]
     */
    public static function keys(array $fields): array
    {
        $keys = [];
        foreach ($fields as $key => $field) {
            if (($field['shared'] ?? false) === true && !($field['hidden'] ?? false)) {
                $keys[] = (string)$key;
            }
        }
        return $keys;
    }

    /**
     * The shared values another translation holds, the default language's first: what a translation that lacks them is given.
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public static function fromSiblings(ContentRepository $content, string $type, string $translationId, string $lang, array $keys, string $defaultLang): array
    {
        if ($keys === [] || $translationId === '') {
            return [];
        }
        $siblings = self::siblings($content, $type, $translationId, $lang);
        usort($siblings, static fn(ContentItem $a, ContentItem $b): int => ($a->lang === $defaultLang ? 0 : 1) <=> ($b->lang === $defaultLang ? 0 : 1));
        foreach ($siblings as $item) {
            $custom = is_array($item->meta['custom_fields'] ?? null) ? $item->meta['custom_fields'] : [];
            $values = array_intersect_key($custom, array_flip($keys));
            if ($values !== []) {
                return $values;
            }
        }
        return [];
    }

    /**
     * Writes the shared values of a translation just saved into its other translations: a value it has replaces theirs, one it
     * left empty is removed from them too. Files whose shared values are already the same are not touched.
     *
     * @param array<string, mixed> $custom the saved translation's custom_fields
     * @param string[] $keys
     * @param \Closure(string, string, string): void $written called with the path, the language and the new text of each file written
     * @return int how many files were written
     */
    public static function spread(ContentRepository $content, string $type, string $translationId, string $lang, array $custom, array $keys, \Closure $written): int
    {
        if ($keys === [] || $translationId === '') {
            return 0;
        }
        $shared = array_intersect_key($custom, array_flip($keys));
        $count = 0;
        foreach (self::siblings($content, $type, $translationId, $lang) as $item) {
            $path = $item->filePath;
            if (!is_file($path)) {
                continue;
            }
            [$frontmatter, $body] = FrontMatter::split((string)file_get_contents($path));
            try {
                $data = $frontmatter === '' ? [] : Yaml::parse($frontmatter);
            } catch (\Throwable) {
                continue; // A file whose front matter does not parse is left for a person to fix.
            }
            if (!is_array($data)) {
                continue;
            }
            $theirs = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
            $next = array_diff_key($theirs, array_flip($keys));
            // In the order the type declares them, so a file does not change only because of where a key sat.
            foreach ($keys as $key) {
                if (array_key_exists($key, $shared)) {
                    $next[$key] = $shared[$key];
                }
            }
            if (self::same($theirs, $next, $keys)) {
                continue;
            }
            if ($next === []) {
                unset($data['custom_fields']);
            } else {
                $data['custom_fields'] = $next;
            }
            if (array_key_exists('blocks', $data)) {
                $blocks = $data['blocks'];
                unset($data['blocks']);
                $data['blocks'] = $blocks;
            }
            $payload = "---\n" . trim(Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . rtrim(str_starts_with($body, "\n") ? substr($body, 1) : $body) . "\n";
            if (@file_put_contents($path, $payload) === false) {
                continue;
            }
            $written($path, $item->lang, $payload);
            $count++;
        }
        return $count;
    }

    /** @return ContentItem[] the other translations of an entry */
    private static function siblings(ContentRepository $content, string $type, string $translationId, string $lang): array
    {
        $siblings = [];
        foreach ($content->getItems($type, null, true) as $item) {
            if ($item->lang !== $lang && (string)($item->meta['translation_id'] ?? '') === $translationId) {
                $siblings[] = $item;
            }
        }
        return $siblings;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param string[] $keys
     */
    private static function same(array $a, array $b, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $a) !== array_key_exists($key, $b) || ($a[$key] ?? null) != ($b[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
