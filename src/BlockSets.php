<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the block editors that keep their blocks in the theme settings share (Theme > Footer Blocks, a single template built with blocks):
 * checking the sets a form sends, and what the editor starts from. The shape of the sets is FooterBlocks'.
 */
final class BlockSets
{
    /**
     * @param \Closure(): BlockRegistry $registry
     * @param \Closure(): HtmlGuard $guard
     * @param \Closure(): bool $mayWriteHtml whether the person saving may add raw HTML
     * @param \Closure(string): array<int, mixed> $presets the ready-made sections for the editor, in a language
     * @param \Closure(): array<string, mixed> $settings the site's settings
     */
    public function __construct(private \Closure $registry, private \Closure $guard, private \Closure $mayWriteHtml, private \Closure $presets, private \Closure $settings)
    {
    }

    /**
     * Sets of blocks sent by an editor, each checked like the blocks of an entry. Raw HTML can carry script: someone who may not add it
     * sees what they type as text, and the HTML that is already stored stays.
     *
     * @param array<int|string, mixed> $posted a list, or a map of set key to blocks
     * @param mixed $stored what is stored now
     * @return array<string, array<int, mixed>>
     */
    public function clean(array $posted, mixed $stored): array
    {
        $guard = ($this->guard)();
        $mayWriteHtml = ($this->mayWriteHtml)();
        $allowed = [];
        if (!$mayWriteHtml) {
            foreach (FooterBlocks::sets($stored, array_merge(['default'], is_array($stored) && !array_is_list($stored) ? array_map('strval', array_keys($stored)) : [])) as $set) {
                $allowed = array_merge($allowed, $guard->blocksFragments($set));
            }
        }
        $sets = [];
        foreach (FooterBlocks::fromPost($posted, $stored) as $key => $set) {
            $set = ($this->registry)()->sanitizeForStorage($set);
            if (!$mayWriteHtml) {
                $set = $guard->eachMarkdownField($set, fn(string $value): string => $guard->neutralize($value, $allowed));
            }
            $sets[$key] = $set;
        }
        return $sets;
    }

    /** The languages of the site, its own first. @return string[] */
    public function languages(): array
    {
        $settings = ($this->settings)();
        $own = (string)($settings['languages']['default'] ?? 'en');
        return array_values(array_unique(array_merge([$own], array_map('strval', (array)($settings['languages']['available'] ?? [])))));
    }

    /**
     * What a block editor starts from, as JSON for a `<script type="application/json">`. With several languages the editor has a set for
     * each (the site's own first); with one it is a plain list.
     *
     * @param mixed $stored the blocks as stored
     * @param array<int, array<string, mixed>> $definitions the block types the editor offers
     * @param array<string, mixed> $extra more for the editor (the bindings of a template)
     */
    public function editorJson(string $lang, mixed $stored, string $context, array $definitions, array $extra = []): string
    {
        $languages = [];
        foreach ($this->languages() as $i => $code) {
            $languages[] = ['key' => $i === 0 ? 'default' : $code, 'code' => $code];
        }
        $multiple = count($languages) > 1;
        return (string)json_encode([
            'definitions' => $definitions,
            'container' => BlockContainer::editorData(),
            'blocks' => $multiple ? FooterBlocks::sets($stored, array_column($languages, 'key')) : FooterBlocks::forLanguage($stored, 'default', 'default'),
            'languages' => $multiple ? $languages : [],
            'presets' => ($this->presets)($lang),
            'presets_url' => rtrim((string)(($this->settings)()['base_url'] ?? ''), '/') . '/admin/block-presets',
            'lang' => $lang,
            'context' => $context,
        ] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
