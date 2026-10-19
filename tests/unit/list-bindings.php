<?php
/*
 * Templates built with blocks, repeater -> list bindings: a block's list (buttons, cards, ...) bound to a list of the entry with a
 * mapping of its columns. The rows, the mapping, fixed values, `max`, an empty source, a column that is gone or another kind now, a
 * text per language in a row, and how what comes through is cleaned like typed rows. The content type is a file created during the test.
 *   php tests/unit/list-bindings.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, ContentItem, ContentTypes, EntryBindings, FieldSchema, Theme};

$root = sys_get_temp_dir() . '/listbind' . getmypid();
mkdir("$root/custom/content-types", 0775, true);
mkdir("$root/public", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

file_put_contents("$root/custom/content-types/education.yaml", <<<'YAML'
label: { en: Courses }
singular: { en: Course }
fields:
  teacher: { type: text, label: Teacher }
  links:
    type: repeater
    label: Links
    fields:
      title: { type: text, label: Title, translatable: true }
      address: { type: link, label: Address }
      kind: { type: select, label: Kind, options: { video: A video, pdf: A document } }
      note: { type: textarea, label: Note }
      weight: { type: number, label: Weight }
      parts:
        type: repeater
        label: Parts
        fields:
          name: { type: text, label: Name }
  empty_list:
    type: repeater
    label: Nothing
    fields:
      title: { type: text, label: Title }
YAML);

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$registry = new BlockRegistry($theme, ['content_types' => fn() => ['posts' => 'Posts'], 'forms' => fn() => ['' => '—']]);
$fmt = static fn(string $d): string => 'D:' . $d;
$labels = static fn(string $tax, string $id): string => $id;
$links = [
    ['title' => ['default' => 'Βίντεο', 'en' => 'Video'], 'address' => '/watch', 'kind' => 'video', 'note' => 'First', 'weight' => 3],
    ['title' => 'Syllabus', 'address' => 'https://example.org/s.pdf', 'kind' => 'pdf'],
    ['title' => 'No link', 'address' => ''],
    ['title' => '', 'address' => ''],
];
$course = static fn(array $fields): ContentItem => new ContentItem('education', 'intro', 'en', ['title' => 'Intro', 'custom_fields' => $fields], '', '', '', 0);
$bind = static fn(ContentItem $item, string $lang = 'en'): EntryBindings => new EntryBindings($item, $types, $lang, 'el', $fmt, $labels);
$buttons = $registry->get('buttons');
check('the Buttons block exists and takes a list of up to 12', [$buttons !== null, $buttons['fields']['actions']['max'] ?? null], [true, 12]);
$fields = $buttons['fields'];
$B = ['bind' => 'custom_fields.links', 'map' => ['label' => 'title', 'url' => 'address']];

// ---- what a type offers
$sources = EntryBindings::sources($types, 'education', 'en', 'en');
$list = array_values(array_filter($sources, fn($s) => $s['path'] === 'custom_fields.links'))[0];
check('a list is offered with its columns, not the list inside a row', [$list['kind'], array_column($list['columns'], 'key'), array_column($list['columns'], 'kind')], ['list', ['title', 'address', 'kind', 'note', 'weight'], ['text', 'link', 'select', 'textarea', 'number']]);
check('a list is never offered as a single value', array_column(EntryBindings::compatible($sources, 'text'), 'key'), ['title', 'date', 'author', 'categories', 'tags', 'teacher']);

// ---- rows
$item = $course(['links' => $links, 'teacher' => 'Dr. Who']);
$out = $bind($item)->apply($fields, ['type' => 'buttons', 'actions' => $B]);
$rows = FieldSchema::resolve($fields, $out['raw'])['actions'];
check('each row of the entry is a row of the block, the mapped columns filled (a row with nothing mapped is left out)', $rows, [
    ['label' => 'Video', 'url' => '/watch', 'style' => 'primary'],
    ['label' => 'Syllabus', 'url' => 'https://example.org/s.pdf', 'style' => 'primary'],
    ['label' => 'No link', 'url' => '', 'style' => 'primary'],
]);
check('the block is not hidden, nothing is missing', [$out['hidden'], $out['missing']], [false, []]);
check('a text per language in a row: the page\'s language, else the site\'s own', [
    FieldSchema::resolve($fields, $bind($item, 'en')->apply($fields, ['actions' => $B])['raw'])['actions'][0]['label'],
    FieldSchema::resolve($fields, $bind($item, 'el')->apply($fields, ['actions' => $B])['raw'])['actions'][0]['label'],
    FieldSchema::resolve($fields, $bind($item, 'de')->apply($fields, ['actions' => $B])['raw'])['actions'][0]['label'],
], ['Video', 'Βίντεο', 'Βίντεο']);
check('a select gives the label of its option (the default of the field when the row has none)', array_column(FieldSchema::resolve($fields, $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.links', 'map' => ['label' => 'kind']]])['raw'])['actions'], 'label'), ['A video', 'A document', 'A video']);
check('a column left out of the map is empty', array_column(FieldSchema::resolve($fields, $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.links', 'map' => ['label' => 'title']]])['raw'])['actions'], 'url'), ['', '', '']);

// ---- fixed values
$fixed = $bind($item)->apply($fields, ['actions' => $B + ['fixed' => ['style' => 'secondary']]]);
check('a fixed value is given to every row', array_column(FieldSchema::resolve($fields, $fixed['raw'])['actions'], 'style'), ['secondary', 'secondary', 'secondary']);
$firstRest = $bind($item)->apply($fields, ['actions' => $B + ['fixed' => ['style' => ['first' => 'primary', 'rest' => 'text']]]]);
check('one value for the first row and another for the rest', array_column(FieldSchema::resolve($fields, $firstRest['raw'])['actions'], 'style'), ['primary', 'text', 'text']);
$invalid = $bind($item)->apply($fields, ['actions' => $B + ['fixed' => ['style' => 'nonsense']]]);
check('a fixed value that is not one of the choices is the default, as for a typed row', array_column(FieldSchema::resolve($fields, $invalid['raw'])['actions'], 'style'), ['primary', 'primary', 'primary']);
$over = $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.links', 'map' => ['label' => 'title'], 'fixed' => ['label' => 'Fixed']]]);
check('a mapped column wins over a fixed value of the same column', array_column($over['raw']['actions'], 'label'), ['Video', 'Syllabus', 'No link']);

// ---- max, empty, hidden
$many = $course(['links' => array_map(fn($i) => ['title' => "L$i", 'address' => "/l$i"], range(1, 20))]);
$cta = $registry->get('cta')['fields'];
check('the block\'s own max applies, as for typed rows', count(FieldSchema::resolve($cta, $bind($many)->apply($cta, ['actions' => $B])['raw'])['actions']), 2);
check('and so does its own limit of twelve in Buttons', count(FieldSchema::resolve($fields, $bind($many)->apply($fields, ['actions' => $B])['raw'])['actions']), 12);
$none = $bind($course([]))->apply($fields, ['actions' => $B]);
check('an empty source list is an empty block, and hides it', [$none['raw']['actions'], $none['hidden']], [[], true]);
check('unless the block asks to stay', $bind($course([]))->apply($fields, ['actions' => $B, 'bind_hide' => false])['hidden'], false);
check('a source with rows keeps it', $bind($item)->apply($fields, ['actions' => $B])['hidden'], false);
check('a mapping with nothing in the rows is empty too', $bind($course(['links' => [['note' => 'x']]]))->apply($fields, ['actions' => $B])['raw']['actions'], []);

// ---- a list or a column that is gone, or another kind now
$gone = $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.removed', 'map' => ['label' => 'title']]]);
check('a list that is gone leaves the field empty, says so, and hides the block', [array_key_exists('actions', $gone['raw']), $gone['missing'], $gone['hidden']], [false, ['custom_fields.removed'], true]);
$single = $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.teacher', 'map' => ['label' => 'title']]]);
check('a single field bound to a list is missing as well', [array_key_exists('actions', $single['raw']), $single['missing']], [false, ['custom_fields.teacher']]);
$column = $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.links', 'map' => ['label' => 'title', 'url' => 'removed_column']]]);
check('a column that is gone is empty and named; the others still work', [array_column(FieldSchema::resolve($fields, $column['raw'])['actions'], 'label'), array_column(FieldSchema::resolve($fields, $column['raw'])['actions'], 'url'), $column['missing']], [['Video', 'Syllabus', 'No link'], ['', '', ''], ['custom_fields.links.removed_column']]);
$retyped = $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.links', 'map' => ['label' => 'title', 'url' => 'weight']]]);
check('a column that is another kind now (a number in a link) does not fit and is empty', [array_column(FieldSchema::resolve($fields, $retyped['raw'])['actions'], 'url'), $retyped['missing']], [['', '', ''], ['custom_fields.links.weight']]);
$inner = $bind($item)->apply($registry->get('hero-slider')['fields'], ['items' => $B]);
check('a block that has no such column is not an error', is_array($inner['raw']['items'] ?? null), true);
$nested = $bind($item)->apply($fields, ['actions' => ['bind' => 'custom_fields.links', 'map' => ['label' => 'parts']]]);
check('a list inside a row is not mapped, however it is written', array_column($nested['raw']['actions'] ?? [], 'label'), []);

// ---- cleaned like typed rows
$bad = $course(['links' => [['title' => '<script>alert(1)</script>', 'address' => 'javascript:alert(1)'], ['title' => 'Fine', 'address' => '/ok']]]);
$rowsBad = FieldSchema::resolve($fields, $bind($bad)->apply($fields, ['actions' => $B])['raw'])['actions'];
check('an address with a script scheme is refused, markup stays text (escaped when drawn), the other row is untouched', [$rowsBad[0]['url'], $rowsBad[0]['label'], $rowsBad[1]], ['', '<script>alert(1)</script>', ['label' => 'Fine', 'url' => '/ok', 'style' => 'primary']]);

// ---- storage
$stored = $registry->sanitizeForStorage([['type' => 'buttons', 'actions' => ['bind' => 'custom_fields.links', 'map' => ['label' => 'title', 'url' => 'address', 'Bad Key' => 'x', 'style' => ['no']], 'fixed' => ['style' => ['first' => 'primary', 'rest' => 'secondary'], 'x y' => '1', 'label' => ['no']], 'rows' => [['label' => 'injected']]]]]);
check('the binding keeps its map and fixed values, and nothing else', $stored[0]['actions'], ['bind' => 'custom_fields.links', 'map' => ['label' => 'title', 'url' => 'address'], 'fixed' => ['style' => ['first' => 'primary', 'rest' => 'secondary']]]);
check('a value that is not a binding is typed rows, as before', $registry->sanitizeForStorage([['type' => 'buttons', 'actions' => [['label' => 'Go', 'url' => '/go']]]])[0]['actions'], [['label' => 'Go', 'url' => '/go']]);
check('first and rest that are the same make one value', EntryBindings::cleanList(['bind' => 'a', 'fixed' => ['style' => ['first' => 'text', 'rest' => 'text']]])['fixed'], ['style' => 'text']);
check('an empty map and empty fixed values are not stored', EntryBindings::cleanList(['bind' => 'a', 'map' => [], 'fixed' => ['style' => '']]), ['bind' => 'a']);
check('a list that cannot be bound as a list (not in the table) is bindable, a block field of another kind is not', [EntryBindings::bindable('repeater'), EntryBindings::bindable('toggle')], [true, false]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
