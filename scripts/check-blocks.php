<?php
/*
 * Verifies that every block in the given content files (default: this site's pages, posts, projects, forms)
 * uses known fields and values that survive validation. Containers (rows with columns) are checked too: their
 * settings, their columns and the blocks inside. Run it after editing content by hand:
 *
 *   php scripts/check-blocks.php [file.md ...]
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
use GnozioCMS\{Theme, BlockRegistry, BlockContainer, FieldSchema};
use Symfony\Component\Yaml\Yaml;
$reg = new BlockRegistry(new Theme($root, 'default'), ['content_types' => fn() => ['posts' => 'Posts', 'projects' => 'Projects'], 'forms' => fn() => ['' => '—', 'contact' => 'Contact']]);
$problems = 0; $files = 0; $blocksTotal = 0;

/** One block (or container) at `$rel $i`; a container is checked with its columns and the blocks inside. */
$checkBlock = function ($block, string $rel, string $i, bool $inColumn) use (&$checkBlock, $reg, &$problems, &$blocksTotal): void {
    $blocksTotal++;
    if (!is_array($block)) { echo "PROBLEM $rel $i is not a block\n"; $problems++; return; }
    if (($block['type'] ?? '') === BlockContainer::TYPE) {
        if ($inColumn) { echo "PROBLEM $rel $i a container inside a column (one level only)\n"; $problems++; return; }
        foreach (array_diff(array_keys($block), ['type', 'settings', 'columns']) as $key) { echo "PROBLEM $rel $i (container) unknown key '$key'\n"; $problems++; }
        $parts = [['settings', BlockContainer::settingFields(), (array)($block['settings'] ?? []), 'container']];
        $columns = array_values((array)($block['columns'] ?? []));
        if (count($columns) < 1 || count($columns) > BlockContainer::MAX_COLUMNS) { echo "PROBLEM $rel $i (container) needs 1 to " . BlockContainer::MAX_COLUMNS . " columns\n"; $problems++; }
        foreach ($columns as $c => $column) { $parts[] = ['settings', BlockContainer::columnFields(), (array)($column['settings'] ?? []), "container column $c"]; }
        foreach ($parts as [, $fields, $values, $label]) {
            foreach ($values as $key => $value) {
                if (!isset($fields[$key])) { echo "PROBLEM $rel $i ($label) unknown setting '$key'\n"; $problems++; continue; }
                $clean = BlockContainer::values($fields, [$key => $value])[$key];
                if ($clean !== $value && !(is_int($value) && (string)$value === (string)$clean)) { echo "PROBLEM $rel $i ($label.$key) value changed by validation: " . json_encode($value) . " -> " . json_encode($clean) . "\n"; $problems++; }
            }
        }
        foreach ($columns as $c => $column) {
            foreach (array_values((array)($column['blocks'] ?? [])) as $k => $inner) { $checkBlock($inner, $rel, "$i.$c.$k", true); }
        }
        return;
    }
    // A block saved in a layout or a block type that has since become another is checked as what it is now.
    $block = $reg->migrate($block);
    $def = $reg->get($block['type'] ?? '');
    if (!$def) { echo "PROBLEM $rel $i unknown block type " . ($block['type'] ?? '?') . "\n"; $problems++; return; }
    $fields = $def['common'] + $def['fields'];
    foreach ($block as $key => $value) {
        if ($key === 'type') continue;
        if (!isset($fields[$key])) { echo "PROBLEM $rel $i ({$block['type']}) unknown field '$key'\n"; $problems++; continue; }
        $clean = FieldSchema::clean($fields[$key], $value);
        if ($fields[$key]['type'] === 'repeater') {
            foreach ((array)$value as $r => $row) {
                foreach ((array)$row as $k => $v) {
                    if (!isset($fields[$key]['fields'][$k])) { echo "PROBLEM $rel $i ({$block['type']}.$key) unknown sub-field '$k'\n"; $problems++; continue; }
                    $cv = FieldSchema::clean($fields[$key]['fields'][$k], $v);
                    if ($cv !== $v && !(is_string($v) && trim($v) === $cv)) { echo "PROBLEM $rel $i ({$block['type']}.$key[$r].$k) value changed by validation: " . json_encode($v) . " -> " . json_encode($cv) . "\n"; $problems++; }
                }
            }
        } elseif ($clean !== $value && !(is_int($value) && (string)$value === $clean) && !(is_string($value) && trim($value) === $clean) && !(is_string($value) && str_replace("\r\n", "\n", trim($value)) === $clean)) {
            echo "PROBLEM $rel $i ({$block['type']}.$key) value changed by validation: " . json_encode($value) . " -> " . json_encode($clean) . "\n"; $problems++;
        }
    }
};

$files_list = array_slice($argv, 1) ?: array_merge(glob("$root/content/pages/*.md"), glob("$root/content/posts/*.md"), glob("$root/content/projects/*.md"), glob("$root/content/forms/*.md"));
foreach ($files_list as $file) {
    preg_match('/\A---\R(.*?)\R---/s', file_get_contents($file), $m);
    $meta = Yaml::parse($m[1]) ?: [];
    if (empty($meta['blocks'])) continue;
    $files++;
    $rel = str_replace("$root/", '', $file);
    foreach ($meta['blocks'] as $i => $block) {
        $checkBlock($block, $rel, "#$i", false);
    }
}
echo "$files files with blocks, $blocksTotal blocks, $problems problems\n";
