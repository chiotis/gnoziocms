<?php

declare(strict_types=1);

namespace GnozioCMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\HtmlRenderer;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Markdown as the visual editor of the content screen shows it. The file stays Markdown: this only draws it the way the site
 * does, one block at a time, together with the lines each block came from, so that a block the person did not touch is written
 * back exactly as it was and only what they changed is written again. Raw HTML is shown as a block that cannot be edited there
 * (it is never run in the admin), and is kept character for character.
 */
final class VisualMarkdown
{
    /** Lines that may sit between blocks without being one: the definitions links refer to. */
    private const DEFINITION = '/^ {0,3}\[[^\]\n]+\]:\s*\S|^\s*["\'(].*["\')]\s*$/';

    /** A line that is only attributes for the block after it: {align=center}. */
    private const ATTRIBUTES = '/^ {0,3}\{[^{}\n]*\}\s*$/';

    /** A definition whose address is on the next line starts with a line that is only the label, and that address is the next line. */
    private const DEFINITION_LABEL = '/^ {0,3}\[[^\]\n]+\]:\s*$/';
    private const DEFINITION_ADDRESS = '/^\s*(<[^<>\n]*>|[^\s<>]+)\s*$/';

    /** @param \Closure(): Environment $environment the site's Markdown environment */
    public function __construct(private \Closure $environment)
    {
    }

    /**
     * @return array{blocks: array<int, array{html: string, source: string}>, tail: string, verbatim: bool}
     *   verbatim is false when the lines of the blocks did not account for the text, and then the editor writes everything again
     */
    public function render(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $environment = ($this->environment)();
        $environment->addRenderer(HtmlBlock::class, new class implements NodeRendererInterface {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
            {
                /** @var HtmlBlock $node */
                $literal = rtrim($node->getLiteral());
                $shown = mb_strlen($literal) > 160 ? mb_substr($literal, 0, 160) . '…' : $literal;
                return new HtmlElement('div', ['class' => 'md-raw', 'contenteditable' => 'false', 'data-raw' => $literal], [
                    new HtmlElement('span', ['class' => 'md-raw-tag'], 'HTML'),
                    new HtmlElement('code', [], htmlspecialchars($shown, ENT_QUOTES | ENT_SUBSTITUTE)),
                ]);
            }
        }, 10);
        $environment->addRenderer(HtmlInline::class, new class implements NodeRendererInterface {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string|HtmlElement
            {
                /** @var HtmlInline $node */
                $literal = $node->getLiteral();
                if (in_array($literal, ['<u>', '</u>'], true)) {
                    return $literal;
                }
                if (preg_match('/^<br\s*\/?>$/i', $literal) === 1) {
                    return '<br>';
                }
                return new HtmlElement('span', ['class' => 'md-raw-inline', 'contenteditable' => 'false', 'data-raw' => $literal], htmlspecialchars($literal, ENT_QUOTES | ENT_SUBSTITUTE));
            }
        }, 10);

        $document = (new MarkdownParser($environment))->parse($markdown);
        $renderer = new HtmlRenderer($environment);
        $lines = explode("\n", $markdown);
        $covered = [];
        $blocks = [];
        foreach ($document->children() as $child) {
            $start = $child instanceof AbstractBlock ? $child->getStartLine() : null;
            $end = $child instanceof AbstractBlock ? $child->getEndLine() : null;
            if ($start === null || $end === null || $start < 1 || $end < $start) {
                return $this->fallback($renderer, $document);
            }
            // The line of attributes before a block ({align=center}) is part of it: it is read with it and written back with it.
            while ($start > 1 && !isset($covered[$start - 1]) && preg_match(self::ATTRIBUTES, $lines[$start - 2]) === 1) {
                $start--;
            }
            for ($n = $start; $n <= $end; $n++) {
                $covered[$n] = true;
            }
            $source = rtrim(implode("\n", array_slice($lines, $start - 1, $end - $start + 1)));
            $blocks[] = ['html' => trim($renderer->renderNodes([$child])), 'source' => $source];
        }

        $tail = [];
        $verbatim = true;
        $afterLabel = false;
        foreach ($lines as $i => $line) {
            if (isset($covered[$i + 1]) || trim($line) === '') {
                continue;
            }
            $isLabel = preg_match(self::DEFINITION_LABEL, $line) === 1;
            if (preg_match(self::DEFINITION, $line) !== 1 && !$isLabel && !($afterLabel && preg_match(self::DEFINITION_ADDRESS, $line) === 1)) {
                $verbatim = false;
            }
            $afterLabel = $isLabel;
            $tail[] = $line;
        }
        return ['blocks' => $blocks, 'tail' => $verbatim ? implode("\n", $tail) : '', 'verbatim' => $verbatim];
    }

    /** Without trustworthy lines, the whole text is drawn at once and the editor writes it all again. @return array{blocks: array<int, array{html: string, source: string}>, tail: string, verbatim: bool} */
    private function fallback(HtmlRenderer $renderer, Node $document): array
    {
        $blocks = [];
        foreach ($document->children() as $child) {
            $blocks[] = ['html' => trim($renderer->renderNodes([$child])), 'source' => ''];
        }
        return ['blocks' => $blocks, 'tail' => '', 'verbatim' => false];
    }
}
