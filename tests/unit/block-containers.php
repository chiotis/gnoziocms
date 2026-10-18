<?php
/*
 * Container rows of the block builder: the shape in the front matter, its checking for storage and for drawing, the classes and
 * variables of a column, and every reader of `blocks` seeing the blocks inside the columns (media, links, Markdown guard).
 *   php tests/unit/block-containers.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\{Theme, BlockRegistry, BlockContainer, HtmlGuard, MediaUsage, LinkScanner, FieldSchema};
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$registry = new BlockRegistry(new Theme($root, 'default'), ['content_types' => fn() => ['posts' => 'Posts'], 'forms' => fn() => ['' => '—']]);

// ---- the fields
$settings = BlockContainer::settingFields();
check('the container has the flexbox settings', array_intersect(['direction', 'justify', 'align', 'align_content', 'wrap', 'gap', 'row_gap'], array_keys($settings)), ['direction', 'justify', 'align', 'align_content', 'wrap', 'gap', 'row_gap']);
check('gaps are the theme\'s spacing steps, not free sizes', array_keys($settings['gap']['options']), ['none', 'xs', 's', 'm', 'l', 'xl']);
check('every screen chooses how columns stack', [array_keys($settings['stack_phone']['options']), $settings['stack_phone']['default'], $settings['stack_desktop']['default'], $settings['stack_tablet']['default']], [['row', 'two', 'one'], 'one', 'row', 'auto']);
check('the background is one of the theme\'s tones', array_keys($settings['tone']['options']), array_keys(BlockRegistry::TONES));
$columns = BlockContainer::columnFields();
check('a column has width, alignment, order, padding, tone, corners', array_intersect(['width', 'min_width', 'max_width', 'grow', 'shrink', 'basis', 'align_self', 'order', 'padding', 'tone', 'radius'], array_keys($columns)), ['width', 'min_width', 'max_width', 'grow', 'shrink', 'basis', 'align_self', 'order', 'padding', 'tone', 'radius']);
check('container is not a block type of the registry', [$registry->get('container'), array_key_exists('container', $registry->all())], [null, false]);

// ---- widths
foreach (['fill' => 'fill', 'AUTO' => 'auto', '1/3' => '1/3', ' 2 / 3 ' => '2/3', '37%' => '37%', '20rem' => '20rem', '320px' => '320px', '4/3' => 'fill', '0/2' => 'fill', '101%' => 'fill', '-4rem' => 'fill', '12em;color:red' => 'fill', 'calc(1px)' => 'fill', '' => 'fill', 'url(x)' => 'fill'] as $in => $out) {
    check("width '$in'", BlockContainer::width($in), $out);
}
check('a size needs a unit and cannot be negative or zero', [BlockContainer::length('12'), BlockContainer::length('0px'), BlockContainer::length('12rem'), BlockContainer::length('50%'), BlockContainer::length('auto', ['auto']), BlockContainer::length('auto')], ['', '', '12rem', '50%', 'auto', '']);
check('the room a column asks for', [BlockContainer::share('fill', 3), BlockContainer::share('1/4', 9), BlockContainer::share('25%', 9), BlockContainer::share('auto', 2), BlockContainer::share('20rem', 2)], [1 / 3, 0.25, 0.25, null, null]);
check('a block in a column takes the room of the column', [BlockContainer::blockWidth('1/4', 4), BlockContainer::blockWidth('fill', 3), BlockContainer::blockWidth('1/2', 2), BlockContainer::blockWidth('2/3', 2), BlockContainer::blockWidth('fill', 1), BlockContainer::blockWidth('18rem', 3), BlockContainer::blockWidth('60rem', 3)], [25, 33, 50, 100, 100, 25, 100]);

// ---- storage
$text = ['type' => 'text', 'heading' => 'Hello', 'body' => 'Body', 'width' => '50'];
$stored = $registry->sanitizeForStorage([[
    'type' => 'container',
    'settings' => ['gap' => 'l', 'justify' => 'center', 'direction' => 'row', 'align' => 'evil', 'wrap' => 'wrap', 'class' => 'my cls<script>', 'anchor' => 'Two Cols!', 'stack_phone' => 'one', 'extra' => 'dropped'],
    'columns' => [
        ['settings' => ['width' => '1/3', 'padding' => 'm', 'order' => '2', 'align_self' => 'bogus'], 'blocks' => [$text, ['type' => 'container', 'columns' => []]]],
        ['blocks' => []],
        ['settings' => ['width' => 'width: 10px'], 'blocks' => [['type' => 'nonsense-block', 'x' => 1]]],
    ],
]]);
check('only what differs from the default is written, checked', $stored[0]['settings'], ['justify' => 'center', 'gap' => 'l', 'anchor' => 'two-cols', 'class' => 'my clsscript']);
check('a column keeps its settings and blocks, a container in a column is dropped', [$stored[0]['columns'][0]['settings'], count($stored[0]['columns'][0]['blocks']), $stored[0]['columns'][0]['blocks'][0]['type']], [['width' => '1/3', 'order' => 2, 'padding' => 'm'], 1, 'text']);
check('blocks inside are checked like any block (the width of a block in a column is kept as it is)', $stored[0]['columns'][0]['blocks'][0], ['type' => 'text', 'width' => 50, 'heading' => 'Hello', 'body' => 'Body']);
check('an empty column is written empty', $stored[0]['columns'][1], []);
check('an unknown block inside is kept, a bad width becomes the default', [$stored[0]['columns'][2]['blocks'][0]['type'], $stored[0]['columns'][2]['settings'] ?? 'none'], ['nonsense-block', 'none']);
$many = $registry->sanitizeForStorage([['type' => 'container', 'columns' => array_fill(0, 9, ['blocks' => []])]]);
check('at most six columns', count($many[0]['columns']), 6);
$none = $registry->sanitizeForStorage([['type' => 'container']]);
check('a container always has a column', [count($none[0]['columns']), isset($none[0]['settings'])], [1, false]);
check('it survives the front matter', Yaml::parse(Yaml::dump(['blocks' => $stored], 10, 2))['blocks'], $stored);
check('stored twice it does not change', $registry->sanitizeForStorage($stored), $stored);
check('blocks without containers are stored exactly as before', $registry->sanitizeForStorage([$text, ['type' => 'text', 'heading' => 'Two', 'width' => '100']]), [['type' => 'text', 'width' => 50, 'heading' => 'Hello', 'body' => 'Body'], ['type' => 'text', 'heading' => 'Two']]);

// ---- drawing
$resolved = BlockContainer::resolve(['settings' => ['gap' => 'bad', 'stack_tablet' => 'one'], 'columns' => [['settings' => ['width' => '1/3', 'tone' => 'muted', 'radius' => 'm', 'padding' => 'l', 'min_width' => '10rem', 'order' => -1, 'valign' => 'center']], ['settings' => ['width' => '20rem', 'grow' => 2]], ['settings' => ['width' => '37%', 'basis' => '30%']], ['settings' => ['width' => 'auto', 'align_self' => 'end']]]]);
check('resolve: bad values become defaults, the columns are kept', [$resolved['settings']['gap'], $resolved['settings']['stack_tablet'], count($resolved['columns'])], ['m', 'one', 4]);
$a = BlockContainer::columnMarkup($resolved['columns'][0]['settings']);
check('a part of the row sets one variable and no style of its own', $a, ['class' => 'bkc-col bkc-col--part bkc-col--v-center bkc-col--ordered bkc-col--pad-l bkc-col--toned tone-muted bkc-col--r-m', 'style' => '--bkc-f:0.3333;--bkc-min:10rem;--bkc-order:-1']);
check('a fixed size and grow', BlockContainer::columnMarkup($resolved['columns'][1]['settings']), ['class' => 'bkc-col bkc-col--fixed bkc-col--flex', 'style' => '--bkc-w:20rem;--bkc-grow:2']);
check('a percentage with a basis', BlockContainer::columnMarkup($resolved['columns'][2]['settings']), ['class' => 'bkc-col bkc-col--part bkc-col--flex', 'style' => '--bkc-f:0.37;--bkc-basis:30%']);
check('auto and align-self', BlockContainer::columnMarkup($resolved['columns'][3]['settings']), ['class' => 'bkc-col bkc-col--auto bkc-col--self-end', 'style' => '']);
check('a plain column is only fill', BlockContainer::columnMarkup(BlockContainer::values(BlockContainer::columnFields(), []))['class'], 'bkc-col bkc-col--fill');
$row = fn(array $s, int $n) => BlockContainer::rowClasses(BlockContainer::values(BlockContainer::settingFields(), $s), $n);
check('the row: defaults', $row([], 2), 'bkc-row bkc-dir-row bkc-wrap-wrap bkc-j-start bkc-a-stretch bkc-ac-stretch bkc-gap-m bkc-rgap-m bkc-d-row bkc-t-row bkc-p-one');
check('the row: automatic on a tablet is a row for two columns, two columns for more', [str_contains($row([], 2), 'bkc-t-row'), str_contains($row([], 3), 'bkc-t-two')], [true, true]);
check('the row: flexbox and responsive settings and the reversed phone', $row(['direction' => 'row-reverse', 'justify' => 'between', 'align' => 'center', 'align_content' => 'center', 'wrap' => 'nowrap', 'gap' => 'xl', 'row_gap' => 'none', 'stack_desktop' => 'two', 'stack_tablet' => 'one', 'stack_phone' => 'row', 'reverse_phone' => true], 3), 'bkc-row bkc-dir-row-reverse bkc-wrap-nowrap bkc-j-between bkc-a-center bkc-ac-center bkc-gap-xl bkc-rgap-none bkc-d-two bkc-t-one bkc-p-row bkc-rev');

// ---- every reader sees the blocks in the columns
$tree = [$text, ['type' => 'container', 'columns' => [['blocks' => [['type' => 'image', 'image' => '/uploads/media/in-col.jpg'], ['type' => 'text', 'body' => 'x']]], ['blocks' => [['type' => 'cta', 'heading' => 'Go']]]]], ['type' => 'text']];
check('flatten lists the blocks inside after their container', array_map(fn($b) => $b['type'], BlockContainer::flatten($tree)), ['text', 'container', 'image', 'text', 'cta', 'text']);
check('nested lists the blocks of the columns in order', array_map(fn($b) => $b['type'], BlockContainer::nested($tree[1])), ['image', 'text', 'cta']);
$guard = new HtmlGuard(fn() => [], fn() => $registry);
$changed = $guard->eachMarkdownField([['type' => 'container', 'columns' => [['blocks' => [['type' => 'text', 'body' => 'plain']]], ['blocks' => [['type' => 'text', 'body' => 'two']]]]]], fn(string $v): string => strtoupper($v));
check('the Markdown fields inside the columns are reached', [$changed[0]['columns'][0]['blocks'][0]['body'], $changed[0]['columns'][1]['blocks'][0]['body']], ['PLAIN', 'TWO']);
$editor = $registry->forEditor([['type' => 'container', 'columns' => [['blocks' => [['type' => 'divider', 'width' => 'short']]]]]]);
check('the editor gets the blocks inside migrated too', $editor[0]['columns'][0]['blocks'][0]['line_width'] ?? null, 'short');

$dir = sys_get_temp_dir() . '/containers' . getmypid();
foreach (['pages', 'posts', 'forms', 'users', 'menus', 'taxonomies'] as $d) { mkdir("$dir/$d", 0775, true); }
file_put_contents("$dir/pages/home.md", "---\ntitle: Home\nblocks:\n  - type: container\n    columns:\n      - blocks:\n          - type: image\n            image: /uploads/media/in-col.jpg\n          - type: cta\n            heading: Read\n            url: /target-page\n      - settings: { width: 1/3 }\n        blocks:\n          - type: text\n            body: 'See [more](/other-page)'\n---\nBody\n");
$usage = new MediaUsage("$dir", fn() => [], 'en');
check('media usage finds a picture inside a column', array_map(fn($p) => $p['label'], $usage->placesFor('media/in-col.jpg')) !== [], true);
$scanner = new LinkScanner($dir, ['en'], 'en');
$found = $scanner->find(['target-page', 'other-page']);
check('the link scanner finds links inside a column (front matter and Markdown)', array_column($found, 'count'), [2]);
$rewritten = $scanner->rewrite((string)file_get_contents("$dir/pages/home.md"), ['target-page' => '/renamed']);
check('and rewrites them', str_contains($rewritten[0], 'url: /renamed'), true);
foreach (glob("$dir/*/*.md") as $f) unlink($f);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
