<?php

declare(strict_types=1);

namespace GnozioCMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\MarkdownParser;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps raw HTML (which can carry script) out of content written by people who may not add it. HTML that is
 * already in a stored file, put there by someone allowed to, is left alone so an edit never breaks an embed.
 */
final class HtmlGuard
{
    /**
     * @param \Closure(): Environment $environment the site's Markdown environment
     * @param \Closure(): BlockRegistry $blocks
     */
    public function __construct(private \Closure $environment, private \Closure $blocks)
    {
    }

    /**
     * Raw HTML the Markdown contains, with the lines it sits on. Used to keep raw HTML (which can carry
     * script) out of content written by people who may not add it.
     *
     * @return array<int, array{kind: string, literal: string, start: ?int, end: ?int}>
     */
    private function rawHtmlNodes(string $markdown): array
    {
        if (!str_contains($markdown, '<')) {
            return [];
        }
        $document = (new MarkdownParser(($this->environment)()))->parse($markdown);
        $found = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof HtmlBlock) {
                $found[] = ['kind' => 'block', 'literal' => $node->getLiteral(), 'start' => $node->getStartLine(), 'end' => $node->getEndLine()];
            } elseif ($node instanceof HtmlInline) {
                $parent = $node->parent();
                while ($parent !== null && !$parent instanceof AbstractBlock) {
                    $parent = $parent->parent();
                }
                $found[] = ['kind' => 'inline', 'literal' => $node->getLiteral(), 'start' => $parent?->getStartLine(), 'end' => $parent?->getEndLine()];
            }
        }
        return $found;
    }

    /**
     * Shows raw HTML as plain text instead of letting it through, except HTML that is already in the
     * content being edited (placed there by someone allowed to), so an edit never breaks an embed.
     *
     * @param string[] $allowed HTML fragments to leave as they are
     */
    public function neutralize(string $markdown, array $allowed = []): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $markdown);
        for ($pass = 0; $pass < 3; $pass++) {
            $blocked = array_values(array_filter($this->rawHtmlNodes($body), static fn(array $n): bool => !in_array($n['literal'], $allowed, true)));
            if ($blocked === []) {
                return $body;
            }
            $lines = explode("\n", $body);
            foreach ($blocked as $node) {
                if ($node['start'] === null || $node['end'] === null) {
                    return str_replace('<', '&lt;', $body);
                }
                for ($i = $node['start'] - 1; $i <= $node['end'] - 1 && isset($lines[$i]); $i++) {
                    $lines[$i] = $node['kind'] === 'block'
                        ? str_replace('<', '&lt;', $lines[$i])
                        : str_replace($node['literal'], str_replace('<', '&lt;', $node['literal']), $lines[$i]);
                }
            }
            $body = implode("\n", $lines);
        }
        // Still something left after three passes (an unusual construct): escape every tag opener.
        return str_replace('<', '&lt;', $body);
    }

    /**
     * Raw HTML fragments in the Markdown of an existing content file (its body and its blocks' Markdown fields).
     *
     * @return string[]
     */
    public function storedFragments(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            return [];
        }
        [$frontmatter, $body] = FrontMatter::split((string)file_get_contents($path));
        try {
            $meta = $frontmatter !== '' ? Yaml::parse($frontmatter) : [];
        } catch (\Throwable) {
            $meta = [];
        }
        return $this->fragmentsOf([$body], is_array($meta) && is_array($meta['blocks'] ?? null) ? $meta['blocks'] : []);
    }

    /**
     * Raw HTML fragments in the Markdown fields of blocks that are stored outside a content file (the footer's blocks).
     *
     * @param array<int, mixed> $blocks
     * @return string[]
     */
    public function blocksFragments(array $blocks): array
    {
        return $this->fragmentsOf([], $blocks);
    }

    /**
     * @param string[] $texts
     * @param array<int, mixed> $blocks
     * @return string[]
     */
    private function fragmentsOf(array $texts, array $blocks): array
    {
        if ($blocks !== []) {
            $this->eachMarkdownField($blocks, static function (string $value) use (&$texts): string {
                $texts[] = $value;
                return $value;
            });
        }
        $fragments = [];
        foreach ($texts as $text) {
            foreach ($this->rawHtmlNodes((string)$text) as $node) {
                $fragments[] = $node['literal'];
            }
        }
        return array_values(array_unique($fragments));
    }

    /**
     * The rows of a repeater with $change applied to their Markdown fields, also in an inner list.
     *
     * @param array<string, mixed> $field
     * @param array<mixed> $rows
     * @param \Closure(string): string $change
     * @return array<mixed>
     */
    private static function eachMarkdownInRows(array $field, array $rows, \Closure $change): array
    {
        foreach ($rows as $r => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($field['fields'] as $subKey => $subField) {
                if ($subField['type'] === 'markdown' && is_string($row[$subKey] ?? null)) {
                    $rows[$r][$subKey] = $change($row[$subKey]);
                } elseif ($subField['type'] === 'repeater' && is_array($row[$subKey] ?? null)) {
                    $rows[$r][$subKey] = self::eachMarkdownInRows($subField, $row[$subKey], $change);
                }
            }
        }
        return $rows;
    }

    /**
     * Applies $change to every Markdown field of the blocks (including items inside repeaters) and returns the blocks.
     *
     * @param array<int, mixed> $blocks
     * @param \Closure(string): string $change
     * @return array<int, mixed>
     */
    public function eachMarkdownField(array $blocks, \Closure $change): array
    {
        foreach ($blocks as $i => $block) {
            if (is_array($block) && ($block['type'] ?? null) === BlockContainer::TYPE && is_array($block['columns'] ?? null)) {
                // The blocks inside the columns of a container are blocks like any other.
                foreach (array_values($block['columns']) as $c => $column) {
                    if (is_array($column) && is_array($column['blocks'] ?? null)) {
                        $blocks[$i]['columns'][$c]['blocks'] = $this->eachMarkdownField(array_values($column['blocks']), $change);
                    }
                }
                continue;
            }
            $definition = is_array($block) && is_string($block['type'] ?? null) ? ($this->blocks)()->get($block['type']) : null;
            if ($definition === null) {
                continue;
            }
            foreach ($definition['fields'] as $key => $field) {
                if ($field['type'] === 'markdown' && is_string($block[$key] ?? null)) {
                    $blocks[$i][$key] = $change($block[$key]);
                } elseif ($field['type'] === 'repeater' && is_array($block[$key] ?? null)) {
                    $blocks[$i][$key] = self::eachMarkdownInRows($field, $block[$key], $change);
                }
            }
        }
        return $blocks;
    }
}
