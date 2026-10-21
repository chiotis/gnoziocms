<?php

declare(strict_types=1);

namespace GnozioCMS;

use Twig\Environment as TwigEnvironment;

/**
 * Renders the `blocks:` list of a page or post.
 *
 * Every block is checked against its definition before it reaches a template: unknown types
 * and hidden blocks are skipped, fields get defaults, and invalid values fall back. Markdown
 * fields also get an `<key>_html` rendering. The first block may be a hero, which then owns the
 * page's <h1>; every other block starts at <h2>.
 *
 * The result carries the stylesheets of the block types in use and structured data that
 * blocks contribute (FAQ), so the layout can place both in <head>.
 */
final class BlockRenderer
{
    /**
     * @param \Closure(string): string $markdown
     * @param array<string, \Closure> $providers dynamic data: 'items' (type, lang, limit), 'form' (slug), 'youtube' (playlist id, picture source), 'file' (a site file's size in bytes), 'term' (a category's name), 'geo' (entries as a map draws them), 'geo_items' (given entries as a map draws them), 'geo_load' (when maps load), 'gallery' (the pictures of an entry's gallery field: entry, field key or '' for its gallery, language) and 'admin' (whether someone is signed in)
     */
    public function __construct(
        private BlockRegistry $registry,
        private TwigEnvironment $twig,
        private Theme $theme,
        private \Closure $markdown,
        private array $providers = [],
        private string $baseUrl = ''
    ) {
    }

    /**
     * @param array<int, mixed> $rawBlocks
     * @param array<string, mixed> $context template variables shared with every block (item, lang, lang_prefix, body_html, …)
     * @return array{html: string, types: string[], asset_types: string[], styles: string[], scripts: string[], structured_data: array<int, array<string, mixed>>, leads_with_hero: bool, opens_with_slider: bool, opens_with_dark_slide: bool, lead: array{type: string, variant: string, tone: string}|null, count: int, image: string}
     */
    public function render(array $rawBlocks, array $context): array
    {
        $blocks = $this->prepare($rawBlocks, (string)($context['body_html'] ?? ''), $context);
        // A template built with blocks may place the entry's text itself (Entry text); the Entry blocks it draws then do not repeat it.
        if ($context['template_mode'] ?? false) {
            $context['template_has_content'] = self::placesContent($blocks);
        }
        $html = '';
        // Each shown block as [width, tone, spacing, html]; blocks narrower than the page are gathered into rows afterwards.
        $sections = [];
        // What the blocks (also those inside containers) add to the page: their types, the FAQ questions and the first picture.
        $found = ['types' => [], 'faq' => [], 'image' => '', 'container' => false, 'row' => false, 'data' => []];
        $leadsWithHero = false;
        $opensWithSlider = false;
        $sliderDark = true;
        $lead = null;

        foreach ($blocks as $index => $entry) {
            [$type, $definition, $values] = $entry;
            $isFirst = $index === 0;
            if ($type === BlockContainer::TYPE) {
                $section = $this->container($values, $index, $context, $found);
                if ($section !== null) {
                    $sections[] = $section;
                }
                continue;
            }
            if ($isFirst && $type === 'hero') {
                $leadsWithHero = true;
            }
            if ($isFirst && self::isOpeningSlider($type, $values)) {
                $opensWithSlider = true;
                // A hero slider starts with the header the first slide needs; its script follows the slides after that.
                $sliderDark = $type !== 'hero-slider' || self::darkSlide(self::heroSlides($values)[0]);
            }
            if ($isFirst) {
                $lead = ['type' => $type, 'variant' => (string)$values['variant'], 'tone' => (string)$values['tone']];
                if ($type === 'archive-title' && is_array($context['archive'] ?? null)) {
                    // The title area of a list opens the page; with a picture behind it the header can sit over it.
                    $lead['opens_under'] = ArchiveBlocks::title($values, is_array($context['archive']['settings'] ?? null) ? $context['archive']['settings'] : [])['behind'];
                }
            }
            $section = $this->section($type, $definition, $values, $context, $index, $isFirst, '', $found);
            if ($section !== null) {
                $sections[] = $section;
            }
        }
        $types = $found['types'];
        $faq = $found['faq'];
        $firstImage = $found['image'];

        $html = self::rows($sections);
        $narrow = false;
        foreach ($sections as $section) {
            $narrow = $narrow || $section[0] < 100;
        }

        // The playlist block draws its videos with the picture, the play button and the viewer of the video block.
        $assetTypes = array_keys($types);
        if (isset($types['playlist']) && !isset($types['video'])) {
            array_unshift($assetTypes, 'video');
        }
        // The hero slider draws its slides with the hero's styles and plays their videos with the hero's script.
        if (isset($types['hero-slider'])) {
            $assetTypes = array_values(array_unique(array_merge(['hero'], array_diff($assetTypes, ['hero']))));
        }
        // Only a page with a block narrower than the page carries the styles of a row. A container's columns are rows too: the blocks in them follow the same narrow rules.
        if ($narrow || $found['container'] || $found['row']) {
            $assetTypes[] = Theme::ROW_STYLES;
        }
        if ($found['container']) {
            $assetTypes[] = Theme::CONTAINER_STYLES;
        }
        $stylesheet = $this->theme->blockStylesheetUrl($this->baseUrl, $assetTypes);
        $styles = $stylesheet !== '' ? [$stylesheet] : [];
        $script = $this->theme->blockScriptUrl($this->baseUrl, $assetTypes);
        $structuredData = [];
        if ($faq !== []) {
            $structuredData[] = ['@type' => 'FAQPage', 'mainEntity' => $faq];
        }
        // The entry's own blocks drawn inside a template (Entry blocks) bring their structured data.
        $structuredData = array_merge($structuredData, $found['data']);

        return [
            'html' => $html,
            'types' => array_keys($types),
            'asset_types' => $assetTypes,
            'styles' => $styles,
            'scripts' => $script !== '' ? [$script] : [],
            'structured_data' => $structuredData,
            'leads_with_hero' => $leadsWithHero,
            'opens_with_slider' => $opensWithSlider,
            'opens_with_dark_slide' => $sliderDark,
            'lead' => $lead,
            'count' => count($blocks),
            'image' => $firstImage,
        ];
    }

    /**
     * One block as a section of the page: its dynamic data, its template, and what it adds to the page.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $values the block's checked values
     * @param array<string, mixed> $context
     * @param array{types: array<string, bool>, faq: array<int, array<string, mixed>>, image: string, container: bool, row: bool, data: array<int, array<string, mixed>>} $found
     * @return array{0: int, 1: string, 2: string, 3: string}|null width, tone, spacing, markup; null for a block with nothing to show
     */
    private function section(string $type, array $definition, array $values, array $context, int $index, bool $isFirst, string $uid, array &$found): ?array
    {
        $values = $this->withData($type, $definition, $values, $context);
        // What a block drew inside itself (the cards of a list) needs the styles of the blocks it used.
        foreach ($values['_types'] ?? [] as $used) {
            $found['types'][(string)$used] = true;
        }
        if ($type === 'entry-blocks') {
            $values = $this->entryBlocks($values, $context, $found);
        }
        if (($values['_empty'] ?? false) === true) {
            // A dynamic block with nothing to show (no matching content, no valid video) leaves no empty section behind.
            return null;
        }
        if ($found['image'] === '' && is_string($values['image'] ?? null) && $values['image'] !== '') {
            // Share-image fallback for pages without a main image.
            $found['image'] = $values['image'];
        }
        if ($found['image'] === '' && is_string($values['video_poster'] ?? null) && $values['video_poster'] !== '' && ($values['_video'] ?? null) !== null) {
            $found['image'] = $values['video_poster'];
        }
        $uid = $uid !== '' ? $uid : ($values['anchor'] !== '' ? $values['anchor'] : (string)($context['block_uid_prefix'] ?? 'block-') . ($index + 1));

        // The block that opens the page stays full width: the header, the title and the page's <h1> belong to it.
        if ($isFirst && ($type === 'hero' || self::isOpeningSlider($type, $values))) {
            $values['width'] = '100';
        }
        $html = $this->twig->render('components/block.twig', $context + [
            'block' => $values,
            'block_type' => $type,
            'block_uid' => $uid,
            'block_index' => $index,
            'block_first' => $isFirst,
            // A layout that is a variant of another also carries the other's class (a centred cover is a cover).
            'block_extra_class' => isset($definition['variant_extends'][(string)$values['variant']]) ? 'block--' . $definition['variant_extends'][(string)$values['variant']] : '',
            // The hero that opens the page is the page title; everything else is a section.
            'heading_tag' => $isFirst && $type === 'hero' ? 'h1' : (string)($context['block_heading_tag'] ?? 'h2'),
            // Items sit one level below their block's heading; a block without a heading has none to sit under.
            'item_heading_tag' => $isFirst && $type === 'hero' ? 'h2' : (trim((string)($values['heading'] ?? '')) !== '' ? 'h3' : (string)($context['block_heading_tag'] ?? 'h2')),
            'block_labelled' => trim((string)($values['heading'] ?? '')) !== '',
        ]);
        $found['types'][$type] = true;

        if ($type === 'faq' && ($values['schema'] ?? true) === true) {
            foreach ($values['items'] as $question) {
                $name = trim((string)($question['question'] ?? ''));
                $answer = trim(strip_tags((string)($question['answer_html'] ?? '')));
                if ($name !== '' && $answer !== '') {
                    $found['faq'][] = [
                        '@type' => 'Question',
                        'name' => $name,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => preg_replace('/\s+/', ' ', $answer)],
                    ];
                }
            }
        }
        return [(int)$values['width'], (string)$values['tone'], (string)$values['spacing'], $html];
    }

    /**
     * A container: its columns with the blocks inside drawn one by one, in the theme's components/block-container.twig. A block in a
     * column takes the room of the column (its own Width is not used there), is never the first block of the page, and its place in
     * the editor is `<container>.<column>.<block>`. A container with nothing to show leaves nothing behind for visitors.
     *
     * @param array<string, mixed> $resolved BlockContainer::resolve() and `_source`
     * @param array<string, mixed> $context
     * @param array{types: array<string, bool>, faq: array<int, array<string, mixed>>, image: string, container: bool} $found
     * @return array{0: int, 1: string, 2: string, 3: string}|null
     */
    private function container(array $resolved, int $index, array $context, array &$found): ?array
    {
        $settings = $resolved['settings'];
        $count = count($resolved['columns']);
        $columns = [];
        $any = false;
        foreach ($resolved['columns'] as $c => $column) {
            $width = (string)$column['settings']['width'];
            $html = '';
            foreach ($this->prepareList($column['blocks'], $resolved['_source'] . '.' . $c . '.', false, $context['bindings'] ?? null) as $k => [$type, $definition, $values]) {
                $values['width'] = (string)BlockContainer::blockWidth($width, $count);
                $uid = $values['anchor'] !== '' ? $values['anchor'] : (string)($context['block_uid_prefix'] ?? 'block-') . ($index + 1) . '-' . ($c + 1) . '-' . ($k + 1);
                $section = $this->section($type, $definition, $values, $context, $index, false, $uid, $found);
                $html .= $section !== null ? $section[3] : '';
            }
            $any = $any || $html !== '';
            $columns[] = BlockContainer::columnMarkup($column['settings']) + ['html' => $html];
        }
        if (!$any && !($context['block_preview'] ?? false)) {
            return null;
        }
        $found['container'] = true;
        $dark = $settings['image'] !== '' && $settings['overlay'] === 'dark';
        $tone = $dark ? 'contrast' : (string)$settings['tone'];
        // A theme with no template for it shows the blocks of the columns one under another.
        if (!$this->twig->getLoader()->exists('components/block-container.twig')) {
            return [100, $tone, (string)$settings['spacing'], implode('', array_column($columns, 'html'))];
        }
        $markup = $this->twig->render('components/block-container.twig', $context + [
            'container' => array_merge($settings, ['tone' => $tone, 'has_picture' => $settings['image'] !== '']),
            'container_uid' => $settings['anchor'],
            'container_row' => BlockContainer::rowClasses($settings, $count),
            'container_columns' => $columns,
            'container_index' => $index,
            'container_source' => $resolved['_source'],
        ]);
        return [100, $tone, (string)$settings['spacing'], $markup];
    }

    /**
     * The sections of a page as markup. Blocks at 100% follow each other as they always did; blocks narrower than that, one
     * after another, share a row (`block-row`) that wraps when it is full. The row carries the spacing of its first block, so
     * a row has the spacing of one section however many blocks it holds.
     *
     * @param array<int, array{0: int, 1: string, 2: string, 3: string}> $sections width, tone, spacing, markup
     */
    private static function rows(array $sections): string
    {
        $html = '';
        $row = [];
        $flush = static function () use (&$row, &$html): void {
            if ($row === []) {
                return;
            }
            $plain = true;
            foreach ($row as $section) {
                $plain = $plain && $section[1] === 'default';
            }
            $html .= '<div class="block-row space-' . $row[0][2] . ($plain ? ' block-row--plain' : '') . '">' . implode('', array_column($row, 3)) . '</div>';
            $row = [];
        };
        foreach ($sections as $section) {
            if ($section[0] >= 100) {
                $flush();
                $html .= $section[3];
            } else {
                $row[] = $section;
            }
        }
        $flush();
        return $html;
    }

    /**
     * What the Playlist block shows: the videos of its playlist in the order and number it asks for, how long each plays when
     * YouTube says so, and (for people who are signed in) why nothing is shown. A playlist with nothing to show leaves no
     * section behind for visitors.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function playlist(array $values): array
    {
        // Someone who is signed in sees a block that has nothing to show, with the reason; a visitor sees no section.
        $admin = isset($this->providers['admin']) && ($this->providers['admin'])() === true;
        $none = ['videos' => [], 'playlist_title' => '', 'channel' => '', 'playlist_url' => '', 'note' => '', 'error' => '', 'source' => '', 'admin' => $admin, '_empty' => !$admin];
        $id = YouTubePlaylist::parseId((string)$values['playlist']);
        if ($id === '' || !isset($this->providers['youtube'])) {
            return ['error' => $id === '' ? 'There is no playlist address yet, or it is not a YouTube playlist.' : ''] + $none;
        }
        $result = ($this->providers['youtube'])($id, (string)$values['thumbs']);
        $videos = $result['items'];
        $order = (string)$values['order'];
        if ($order === 'newest') {
            usort($videos, static fn(array $a, array $b): int => $b['published'] <=> $a['published']);
        } elseif ($order === 'oldest') {
            usort($videos, static fn(array $a, array $b): int => $a['published'] <=> $b['published']);
        } elseif ($order === 'views') {
            usort($videos, static fn(array $a, array $b): int => ((int)$b['views']) <=> ((int)$a['views']));
        }
        $videos = array_slice($videos, 0, max(1, min(YouTubePlaylist::MAX, (int)$values['limit'])));
        foreach ($videos as $i => $video) {
            $videos[$i] += ['embed_url' => 'https://www.youtube-nocookie.com/embed/' . $video['id'] . '?rel=0&playsinline=1', 'provider' => 'youtube'];
        }
        return [
            'videos' => $videos,
            'playlist_title' => (string)$result['title'],
            'channel' => (string)$result['channel'],
            'playlist_url' => 'https://www.youtube.com/playlist?list=' . $id,
            'note' => (string)($result['note'] ?: ($result['stale'] && $result['error'] !== '' ? 'YouTube could not be asked just now, so the playlist is shown as it was last fetched (' . $result['error'] . ')' : '')),
            'error' => $videos === [] ? (string)$result['error'] : '',
            'source' => (string)$result['source'],
            'admin' => $admin,
            '_empty' => $videos === [] && !$admin,
        ];
    }

    /**
     * The slides of a hero slider that have something to show, as the block draws them.
     *
     * @param array<string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    private static function heroSlides(array $values): array
    {
        return array_values(array_filter(is_array($values['items'] ?? null) ? $values['items'] : [], static fn(array $slide): bool => ($slide['image'] ?? '') !== '' || ($slide['heading'] ?? '') !== '' || ($slide['text'] ?? '') !== '' || ($slide['video'] ?? '') !== ''));
    }

    /** Whether a slide of a hero slider is a picture with light text on it, so the header over it has light text too. @param array<string, mixed> $slide */
    private static function darkSlide(array $slide): bool
    {
        $picture = ($slide['background'] ?? 'image') === 'video' ? ($slide['video'] ?? '') : ($slide['image'] ?? '');
        return $picture !== '' && in_array($slide['layout'] ?? '', ['cover', 'cover-centered', 'steps', 'steps-centered'], true);
    }

    /**
     * A hero slider opens the page the way a hero does, whatever its slides are: the header can sit over it, it has no title
     * area above it, and the text of the page comes after it. The header follows the slide that shows.
     *
     * @param array<string, mixed> $values
     */
    private static function isOpeningSlider(string $type, array $values): bool
    {
        return $type === 'hero-slider' && self::heroSlides($values) !== [];
    }

    /**
     * Valid, visible blocks with resolved values. When the page body has text and no `content`
     * block places it, it is shown right after an opening hero or slider (or first).
     *
     * In a template built with blocks (`template_mode` in the context) the template decides where the text goes: nothing is added.
     *
     * @param array<int, mixed> $rawBlocks
     * @param array<string, mixed> $context
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    private function prepare(array $rawBlocks, string $bodyHtml, array $context = []): array
    {
        $prepared = $this->prepareList($rawBlocks, '', true, $context['bindings'] ?? null);
        $hasContent = self::placesContent($prepared);

        $content = $this->registry->get('content');
        if (!$hasContent && $content !== null && !($context['template_mode'] ?? false) && trim(strip_tags($bodyHtml, '<img><iframe><video>')) !== '') {
            $values = FieldSchema::resolve($content['common'], []) + FieldSchema::resolve($content['fields'], []) + ['_source' => -1];
            $position = ($prepared[0][0] ?? '') === 'hero' || self::isOpeningSlider((string)($prepared[0][0] ?? ''), $prepared[0][2] ?? []) ? 1 : 0;
            array_splice($prepared, $position, 0, [['content', $content, $values]]);
        }
        return $prepared;
    }

    /**
     * Whether a content block (visible, also inside a container) places the page's text.
     *
     * @param array<int, array{0: string, 1: array<string, mixed>|null, 2: array<string, mixed>}> $prepared
     */
    private static function placesContent(array $prepared): bool
    {
        foreach ($prepared as [$type, , $values]) {
            if ($type === 'content') {
                return true;
            }
            if ($type === BlockContainer::TYPE) {
                foreach ($values['columns'] as $column) {
                    foreach ($column['blocks'] as $block) {
                        if (($block['type'] ?? null) === 'content' && !FieldSchema::isTruthy($block['hidden'] ?? false)) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * The valid, visible blocks of a list with resolved values. At the top of the page a container is kept too, with its columns.
     *
     * @param array<int, mixed> $rawBlocks
     * @param string $source what comes before a block's place in the editor's list (the preview marks blocks with it)
     * @param EntryBindings|null $bindings the entry a template built with blocks is drawn for: a field bound to a field of the entry gets
     *        its value here, before the block's fields are checked (so it is cleaned like a typed one), and a block whose bound
     *        fields are all empty is left out when it asks for that
     * @return array<int, array{0: string, 1: array<string, mixed>|null, 2: array<string, mixed>}>
     */
    private function prepareList(array $rawBlocks, string $source, bool $containers, ?EntryBindings $bindings = null): array
    {
        $prepared = [];
        foreach ($rawBlocks as $position => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            if (($raw['type'] ?? null) === BlockContainer::TYPE) {
                // One level: a container in a column is not drawn.
                $resolved = $containers ? BlockContainer::resolve($raw) : null;
                if ($resolved !== null && $resolved['settings']['hidden'] !== true) {
                    $prepared[] = [BlockContainer::TYPE, null, $resolved + ['_source' => (int)$position]];
                }
                continue;
            }
            $raw = $this->registry->migrate($raw);
            $type = (string)($raw['type'] ?? '');
            $definition = $this->registry->get($type);
            if ($definition === null) {
                continue;
            }
            if ($bindings !== null) {
                $bound = $bindings->apply($definition['fields'], $raw);
                if ($bound['hidden']) {
                    continue;
                }
                $raw = $bound['raw'];
            }
            $values = FieldSchema::resolve($definition['common'], array_intersect_key($raw, $definition['common']));
            if ($values['hidden'] === true) {
                continue;
            }
            $values['anchor'] = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)$values['anchor'])), '-');
            $values += FieldSchema::resolve($definition['fields'], array_intersect_key($raw, $definition['fields']));
            // Where the block stands in the list the editor holds (hidden and unknown ones count), for the live preview.
            $values['_source'] = $source === '' ? (int)$position : $source . $position;
            $prepared[] = [$type, $definition, $values];
        }
        return $prepared;
    }

    /**
     * Rows of a repeater with the HTML of their Markdown fields beside them (<key>_html), also in an inner list.
     *
     * @param array<string, mixed> $field
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsWithHtml(array $field, array $rows): array
    {
        foreach ($rows as $i => $row) {
            foreach ($field['fields'] as $subKey => $subField) {
                if ($subField['type'] === 'markdown') {
                    $rows[$i][$subKey . '_html'] = $this->markdownHtml((string)$row[$subKey]);
                } elseif ($subField['type'] === 'repeater') {
                    $rows[$i][$subKey] = $this->rowsWithHtml($subField, $row[$subKey]);
                }
            }
        }
        return $rows;
    }

    /**
     * The Entry blocks block of a template: the blocks the entry has of its own, drawn where the template puts them. They are drawn
     * with the same renderer and what they need (style sheets, scripts, structured data, the row styles) is added to the page.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @param array{types: array<string, bool>, faq: array<int, array<string, mixed>>, image: string, container: bool, row: bool, data: array<int, array<string, mixed>>} $found
     * @return array<string, mixed>
     */
    private function entryBlocks(array $values, array $context, array &$found): array
    {
        $item = $context['item'] ?? null;
        $own = $item instanceof ContentItem && is_array($item->meta['blocks'] ?? null) ? array_values($item->meta['blocks']) : [];
        $values['html'] = '';
        $values['_empty'] = true;
        if ($own === []) {
            return $values;
        }
        // The entry's text sits where the template put it (Entry text); without that block the entry's own blocks may place it.
        $inside = array_diff_key($context, ['bindings' => true, 'template_mode' => true, 'template_has_content' => true]);
        // The entry's own blocks are not the template's: a click on them in the preview must not open a block of the template.
        $inside['block_preview'] = false;
        $inside['body_html'] = ($context['template_has_content'] ?? false) ? '' : (string)($context['body_html'] ?? '');
        $drawn = $this->render($own, $inside);
        if (trim($drawn['html']) === '') {
            return $values;
        }
        foreach ($drawn['types'] as $name) {
            $found['types'][$name] = true;
        }
        $found['container'] = $found['container'] || in_array(Theme::CONTAINER_STYLES, $drawn['asset_types'], true);
        $found['row'] = $found['row'] || in_array(Theme::ROW_STYLES, $drawn['asset_types'], true);
        $found['data'] = array_merge($found['data'], $drawn['structured_data']);
        $found['image'] = $found['image'] !== '' ? $found['image'] : $drawn['image'];
        $values['html'] = $drawn['html'];
        $values['_empty'] = false;
        return $values;
    }

    /**
     * Markdown renderings and dynamic data for a block.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function withData(string $type, array $definition, array $values, array $context): array
    {
        foreach ($definition['fields'] as $key => $field) {
            if ($field['type'] === 'markdown') {
                $values[$key . '_html'] = $this->markdownHtml((string)$values[$key]);
            }
            if ($field['type'] === 'repeater') {
                $values[$key] = $this->rowsWithHtml($field, $values[$key]);
            }
        }

        if ($type === 'content') {
            $values['body_html'] = (string)($context['body_html'] ?? '');
        }
        if ($type === 'fact-sheet') {
            // The declared fields of the entry, as the fields the template chose (or the ones its page shows when it chose none).
            $bindings = $context['bindings'] ?? null;
            $values['entries'] = $bindings instanceof EntryBindings
                ? $bindings->factSheet(array_map(static fn(array $row): string => (string)($row['field'] ?? ''), $values['fields']))
                : [];
            $values['_empty'] = $values['entries'] === [];
        }
        if ($type === 'gallery' && ($values['source'] ?? 'manual') === 'entry') {
            // The pictures of the entry the block is drawn for (its own page, or the entry of a template); none: no block at all.
            $item = $context['item'] ?? null;
            $urls = $item instanceof ContentItem && isset($this->providers['gallery'])
                ? ($this->providers['gallery'])($item, trim((string)($values['field'] ?? '')), (string)($context['lang'] ?? ''))
                : [];
            $values['images'] = array_map(static fn(string $url): array => ['image' => $url, 'alt' => '', 'caption' => ''], $urls);
            $values['_empty'] = $values['images'] === [];
        }
        if ($type === 'cards' && ($values['source'] ?? 'manual') !== 'manual' && isset($this->providers['items'])) {
            $values['entries'] = ($this->providers['items'])((string)$values['source'], (string)($context['lang'] ?? ''), (int)($values['limit'] ?? 3));
        }
        if (in_array($type, ['archive-title', 'filter-bar', 'result-count'], true) && is_array($context['archive'] ?? null)) {
            $values = ArchiveBlocks::fill($type, $values, $context['archive']);
        } elseif (in_array($type, ['archive-title', 'filter-bar', 'result-count', ArchiveTemplates::LIST_BLOCK, 'entry-map'], true) && !is_array($context['archive'] ?? null)) {
            // Outside a list there is nothing for these blocks to show.
            $values['_empty'] = true;
        }
        if ($type === 'entry-map' && is_array($context['archive'] ?? null)) {
            // Every entry the list's filters leave that has a place, on one map (the entries of the page are a part of them).
            $mapItems = ($context['archive']['map_items'] ?? null) instanceof \Closure ? ($context['archive']['map_items'])() : [];
            // The bar offers the taxonomies the list's card chose (null: as the card of each type says).
            $chosen = $context['archive']['settings']['map_filters'] ?? null;
            $values['geo'] = isset($this->providers['geo_items']) ? ($this->providers['geo_items'])($mapItems, (string)($context['lang'] ?? ''), is_array($chosen) ? $chosen : null) : ['items' => []];
            $values['_empty'] = $values['geo']['items'] === [];
            $values['_types'] = ['map'];
            $values['load_mode'] = ($values['load'] ?? 'site') === 'site' && isset($this->providers['geo_load']) ? ($this->providers['geo_load'])() : (($values['load'] ?? 'click') === 'auto' ? 'auto' : 'click');
        }
        if ($type === ArchiveTemplates::LIST_BLOCK && is_array($context['archive'] ?? null)) {
            $values['entries'] = $context['archive']['items'];
            $values['cards'] = null;
            // The orders a visitor may choose from, when the template lets them (ArchiveTemplates::withListChoices).
            $values['sort_options'] = [];
            foreach (is_array($context['archive']['settings']['sort_choices'] ?? null) ? $context['archive']['settings']['sort_choices'] : [] as $order => $label) {
                $values['sort_options'][] = ['value' => (string)$order, 'label' => (string)$label];
            }
            $values['_types'] = ['latest'];
            if (isset($this->providers['archive_card'])) {
                // Each entry is drawn with the card of the template: blocks that show the entry's fields.
                $cards = [];
                $used = [];
                foreach ($values['entries'] as $entry) {
                    $card = ($this->providers['archive_card'])($entry, $context);
                    $cards[] = ['item' => $entry, 'html' => $card['html']];
                    $used = array_merge($used, $card['types']);
                }
                $values['cards'] = $cards;
                $values['_types'] = array_values(array_unique($used));
            }
        }
        if ($type === 'latest') {
            $values['entries'] = isset($this->providers['items'])
                ? ($this->providers['items'])((string)$values['source'], (string)($context['lang'] ?? ''), (int)$values['limit'], (string)$values['term'])
                : [];
            $values['_empty'] = $values['entries'] === [];
        }
        if ($type === 'video') {
            $videos = [];
            foreach ($values['videos'] as $video) {
                $info = self::videoInfo((string)$video['url']);
                if ($info !== null) {
                    $videos[] = $info + $video;
                }
            }
            $values['videos'] = $videos;
            $values['_empty'] = $videos === [];
        }
        if ($type === 'playlist') {
            $values += $this->playlist($values);
        }
        if ($type === 'image') {
            $values['_empty'] = $values['image'] === '';
        }
        if ($type === 'quote') {
            $values['_empty'] = $values['quote'] === '';
        }
        if ($type === 'checklist') {
            $values['items'] = array_values(array_filter($values['items'], static fn(array $row): bool => $row['text'] !== ''));
            $values['_empty'] = $values['items'] === [];
        }
        if ($type === 'marquee') {
            $values['items'] = array_values(array_filter($values['items'], static fn(array $row): bool => $row['text'] !== '' || $row['image'] !== ''));
            $values['_empty'] = $values['items'] === [];
        }
        if ($type === 'downloads') {
            $values['items'] = $this->downloads($values['items']);
            $values['_empty'] = $values['items'] === [];
        }
        if ($type === 'table') {
            $values += self::tableData((string)$values['head'], (string)$values['rows'], (bool)$values['align_numbers']);
            $values['_empty'] = $values['body'] === [];
        }
        if ($type === 'portfolio') {
            $values += $this->portfolio($values, (string)($context['lang'] ?? ''));
            $values['_empty'] = $values['tiles'] === [];
        }
        if ($type === 'hero') {
            // A video behind the text, when the Picture is a video the site can play; otherwise the image is the picture.
            $values['_video'] = ($values['background'] ?? 'image') === 'video' ? self::backgroundVideo((string)($values['video'] ?? '')) : null;
        }
        if ($type === 'banner') {
            // A changed announcement gets a new key, so a visitor who dismissed the old one sees it again.
            $values['key'] = substr(sha1($values['title'] . '|' . $values['text'] . '|' . $values['url']), 0, 10);
        }
        if ($type === 'hero-slider') {
            // Each slide is a hero: a video behind its text when its Picture is one the site can play.
            $values['items'] = array_values(array_map(static function (array $slide): array {
                $slide['_video'] = ($slide['background'] ?? 'image') === 'video' ? self::backgroundVideo((string)($slide['video'] ?? '')) : null;
                return $slide;
            }, array_filter($values['items'], static fn(array $slide): bool => $slide['image'] !== '' || $slide['heading'] !== '' || $slide['text'] !== '' || $slide['video'] !== '')));
            $values['_empty'] = $values['items'] === [];
        }
        if (($type === 'form' || $type === 'contact') && isset($this->providers['form'])) {
            $values['form_html'] = ($this->providers['form'])((string)($values['form'] ?? ''));
        }
        if ($type === 'map') {
            if (($values['source'] ?? 'manual') === 'manual' || !isset($this->providers['geo'])) {
                $values['source'] = 'manual';
                // A layout of the other kind of map (the list over the map) is the plain one here.
                $values['variant'] = $values['variant'] === 'overlay' ? 'contained' : $values['variant'];
                $values += self::mapUrls($values['lat'] ?? '', $values['lng'] ?? '', (int)($values['zoom'] ?? 15));
            } else {
                // The entries of a content type (or all with a place) as a map draws them, with filters and a list.
                $values['variant'] = $values['variant'] === 'split' ? 'contained' : $values['variant'];
                $values['geo'] = ($this->providers['geo'])((string)$values['source'], (string)($context['lang'] ?? ''), (int)$values['limit'], (string)$values['term']);
                $values['_empty'] = $values['geo']['items'] === [];
            }
            $values['load_mode'] = ($values['load'] ?? 'site') === 'site' && isset($this->providers['geo_load']) ? ($this->providers['geo_load'])() : (($values['load'] ?? 'click') === 'auto' ? 'auto' : 'click');
        }
        return $values;
    }

    /**
     * The files of a Downloads block: the kind of file (its extension), its size (typed by the editor, or read from the file
     * when it is one of the site's uploads), and a title (the file's name when the editor gave none). A row without a file is
     * dropped for visitors, so they never see a download that goes nowhere.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function downloads(array $rows): array
    {
        $items = [];
        $admin = isset($this->providers['admin']) && ($this->providers['admin'])() === true;
        foreach ($rows as $row) {
            $file = (string)$row['file'];
            if ($file === '') {
                // Someone who is signed in sees the row that still waits for its file (a ready-made page has them), a visitor does not.
                if ($admin && ($row['title'] !== '' || $row['description'] !== '')) {
                    $items[] = ['title' => (string)$row['title'], 'description' => (string)$row['description'], 'file' => '', 'ext' => '', 'size' => (string)$row['size'], 'external' => false, 'missing' => true];
                }
                continue;
            }
            $path = (string)(parse_url($file, PHP_URL_PATH) ?? '');
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $extension = preg_match('/^[a-z0-9]{1,5}$/', $extension) ? $extension : '';
            $title = trim((string)$row['title']);
            if ($title === '') {
                $title = trim((string)preg_replace('/[\s_-]+/u', ' ', rawurldecode(pathinfo($path, PATHINFO_FILENAME))));
            }
            $size = trim((string)$row['size']);
            if ($size === '' && isset($this->providers['file'])) {
                $bytes = ($this->providers['file'])($file);
                $size = $bytes === null ? '' : Format::bytes($bytes);
            }
            $items[] = [
                'title' => $title !== '' ? $title : $file,
                'description' => (string)$row['description'],
                'file' => $file,
                'ext' => strtoupper($extension),
                'size' => $size,
                'external' => (bool)preg_match('#^https?://#i', $file),
            ];
        }
        return $items;
    }

    /**
     * What the Portfolio block draws: a tile for each piece of work (typed into the block, or the entries of a content type)
     * and the categories to filter by, in the order they first appear. A manual tile can have several categories, separated
     * by commas; an entry's are the categories it is filed under.
     *
     * @param array<string, mixed> $values
     * @return array{tiles: array<int, array<string, mixed>>, cat_list: array<int, array{slug: string, label: string}>}
     */
    private function portfolio(array $values, string $lang): array
    {
        $tiles = [];
        if ($values['source'] !== 'manual' && isset($this->providers['items'])) {
            foreach (($this->providers['items'])((string)$values['source'], $lang, (int)$values['limit']) as $entry) {
                $cats = [];
                foreach (Format::list($entry->meta['categories'] ?? null) as $slug) {
                    $label = isset($this->providers['term']) ? (string)($this->providers['term'])((string)$slug, $lang) : '';
                    $cats[] = ['slug' => Slug::plain((string)$slug), 'label' => $label !== '' ? $label : (string)$slug];
                }
                $tiles[] = ['entry' => $entry, 'cats' => $cats];
            }
        } else {
            foreach ($values['items'] as $row) {
                if ($row['title'] === '' && $row['image'] === '') {
                    continue;
                }
                $cats = [];
                foreach (explode(',', (string)$row['category']) as $label) {
                    $label = trim($label);
                    if ($label !== '' && Slug::plain($label) !== '') {
                        $cats[] = ['slug' => Slug::plain($label), 'label' => $label];
                    }
                }
                $tiles[] = ['entry' => null, 'title' => $row['title'], 'text' => $row['text'], 'image' => $row['image'], 'url' => $row['url'], 'cats' => $cats];
            }
        }
        $filters = [];
        foreach ($tiles as $tile) {
            foreach ($tile['cats'] as $cat) {
                $filters[$cat['slug']] ??= $cat;
            }
        }
        return ['tiles' => $tiles, 'cat_list' => array_values($filters)];
    }

    /**
     * The cells of a Table block. The editor types or pastes one row to a line, cells divided by `|` or by a tab (a copy from a
     * spreadsheet); the first line of `head` holds the column titles. A line of dashes (the rule under the titles of a Markdown
     * table) is skipped. Every row has as many cells as the widest, a column of figures is marked to be aligned to the right,
     * and each cell is safe HTML: its text escaped, with **bold** and [links](address) the only marks it can carry.
     *
     * @return array{columns: int, header: array<int, string>, body: array<int, array<int, string>>, align: array<int, string>}
     */
    public static function tableData(string $head, string $rows, bool $alignNumbers = true): array
    {
        $cells = static fn(string $line): array => array_map('trim', preg_split('/\t|\|/', trim($line, " \t|")) ?: []);
        $header = trim($head) === '' ? [] : $cells(strtok(str_replace("\r", '', trim($head)), "\n") ?: '');
        $body = [];
        foreach (explode("\n", str_replace("\r", '', $rows)) as $line) {
            if (trim($line) === '' || preg_match('/^[\s|:\t-]+$/', $line)) {
                continue;
            }
            $body[] = $cells($line);
            if (count($body) >= 100) {
                break;
            }
        }
        $columns = min(12, max(count($header), ...array_map('count', $body ?: [[]])));
        $pad = static fn(array $row): array => array_slice(array_pad($row, $columns, ''), 0, $columns);
        $header = $header === [] ? [] : $pad($header);
        $body = array_map($pad, $body);
        $align = [];
        for ($c = 0; $c < $columns; $c++) {
            $filled = array_values(array_filter(array_column($body, $c), static fn(string $cell): bool => $cell !== ''));
            $numeric = $alignNumbers && $filled !== [] && count(array_filter($filled, static fn(string $cell): bool => (bool)preg_match('/^[-+−]?\s?[€$£]?\s?\d[\d.,\s]*\s?(%|€|\$|£|[kKmM])?$/u', $cell))) === count($filled);
            $align[] = $numeric ? 'right' : 'left';
        }
        $html = static function (string $cell): string {
            $safe = htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safe = (string)preg_replace_callback('/\[([^\]]{1,120})\]\(([^)\s]{1,300})\)/u', static function (array $m): string {
                $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return FieldSchema::isSafeLink($href) ? '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $m[1] . '</a>' : $m[0];
            }, $safe);
            return (string)preg_replace('/\*\*([^*]{1,200})\*\*/u', '<strong>$1</strong>', $safe);
        };
        return [
            'columns' => $columns,
            'header' => array_map($html, $header),
            'body' => array_map(static fn(array $row): array => array_map($html, $row), $body),
            'align' => $align,
        ];
    }

    /**
     * Playback details for a video link, or null when it is not a supported source.
     *
     * YouTube and Vimeo are embedded through their privacy-friendly hosts (youtube-nocookie.com,
     * Vimeo's do-not-track player); a direct .mp4, .webm, .ogv, or .m4v file plays in a plain
     * <video>. Anything else is refused, so a stored URL can never become an arbitrary iframe.
     *
     * @return array{provider: string, embed_url: string, watch_url: string}|null
     */
    public static function videoInfo(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !FieldSchema::isSafeUrl($url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;
        $path = (string)($parts['path'] ?? '');
        parse_str((string)($parts['query'] ?? ''), $query);

        $youtubeId = '';
        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                $youtubeId = (string)($query['v'] ?? '');
            } elseif (preg_match('#^/(embed|shorts|live|v)/([A-Za-z0-9_-]{11})#', $path, $m)) {
                $youtubeId = $m[2];
            }
        } elseif ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})#', $path, $m)) {
            $youtubeId = $m[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeId)) {
            $start = isset($query['t']) && preg_match('/^(\d+)s?$/', (string)$query['t'], $t) ? '&start=' . (int)$t[1] : '';
            return [
                'provider' => 'youtube',
                'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $youtubeId . '?rel=0&playsinline=1' . $start,
                'watch_url' => 'https://www.youtube.com/watch?v=' . $youtubeId,
            ];
        }

        if (($host === 'vimeo.com' || $host === 'player.vimeo.com') && preg_match('#^/(?:video/)?(\d{5,12})(?:/([a-f0-9]{6,20}))?#', $path, $m)) {
            $hash = ($m[2] ?? '') !== '' ? '&h=' . $m[2] : '';
            return [
                'provider' => 'vimeo',
                'embed_url' => 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1' . $hash,
                'watch_url' => 'https://vimeo.com/' . $m[1] . (($m[2] ?? '') !== '' ? '/' . $m[2] : ''),
            ];
        }
        // A direct video file, on this site or elsewhere.
        if (preg_match('/\.(mp4|webm|ogv|m4v)$/i', $path)) {
            return ['provider' => 'file', 'embed_url' => $url, 'watch_url' => $url];
        }
        return null;
    }

    /**
     * A video that plays behind the text of a block, silently and in a loop, or null when the link is not one the site can play.
     * A file plays in a plain <video>; YouTube (the privacy-friendly host) and Vimeo (in its background mode, no controls) are
     * embedded in a frame the page opens after it has loaded. Anything else is refused, as in videoInfo().
     *
     * @return array{provider: string, src: string, type: string}|null src is the file or the address of the frame
     */
    public static function backgroundVideo(string $url): ?array
    {
        $info = self::videoInfo($url);
        if ($info === null) {
            return null;
        }
        if ($info['provider'] === 'file') {
            $types = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg'];
            $extension = strtolower(pathinfo((string)parse_url($info['embed_url'], PHP_URL_PATH), PATHINFO_EXTENSION));
            return ['provider' => 'file', 'src' => $info['embed_url'], 'type' => $types[$extension] ?? ''];
        }
        if ($info['provider'] === 'youtube' && preg_match('#/embed/([A-Za-z0-9_-]{11})#', $info['embed_url'], $m)) {
            return ['provider' => 'youtube', 'type' => '', 'src' => 'https://www.youtube-nocookie.com/embed/' . $m[1]
                . '?autoplay=1&mute=1&controls=0&loop=1&playlist=' . $m[1] . '&playsinline=1&rel=0&disablekb=1&modestbranding=1&iv_load_policy=3'];
        }
        if ($info['provider'] === 'vimeo') {
            return ['provider' => 'vimeo', 'type' => '', 'src' => $info['embed_url'] . '&background=1&autoplay=1&loop=1&muted=1'];
        }
        return null;
    }

    /**
     * OpenStreetMap embed and link URLs for a point. No API key; the embed shows a marker.
     *
     * @return array{embed_url: string, link_url: string, directions_url: string}
     */
    public static function mapUrls(mixed $lat, mixed $lng, int $zoom): array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return ['embed_url' => '', 'link_url' => '', 'directions_url' => ''];
        }
        $lat = max(-85.0, min(85.0, (float)$lat));
        $lng = max(-180.0, min(180.0, (float)$lng));
        $zoom = max(3, min(19, $zoom));
        // About 1150 × 520 px of 256 px tiles around the point.
        $lonSpan = 360 / (2 ** $zoom) * 4.5;
        $latSpan = $lonSpan * cos(deg2rad($lat)) * 0.45;
        $format = static fn(float $n): string => rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');
        $bbox = implode(',', array_map($format, [$lng - $lonSpan / 2, $lat - $latSpan / 2, $lng + $lonSpan / 2, $lat + $latSpan / 2]));
        $point = $format($lat) . ',' . $format($lng);
        return [
            'embed_url' => 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik&marker=' . $point,
            'link_url' => 'https://www.openstreetmap.org/?mlat=' . $format($lat) . '&mlon=' . $format($lng) . '#map=' . $zoom . '/' . $format($lat) . '/' . $format($lng),
            'directions_url' => 'https://www.openstreetmap.org/directions?route=%3B' . rawurlencode($point),
        ];
    }

    private function markdownHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        return $markdown === '' ? '' : ($this->markdown)($markdown);
    }
}
