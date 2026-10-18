<?php
/*
 * Repeaters: the schema (two levels, a third refused and named), checking stored and submitted rows, defaults and icons; a content
 * type with a list of rows that holds a list of its own (definition, form input, front matter, display); and blocks (storage,
 * the Markdown guard) with the same.
 *   php tests/unit/repeaters.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{BlockRegistry, ContentTypes, FieldSchema, HtmlGuard, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

// ---- the schema
$raw = [
    'books' => ['type' => 'repeater', 'max' => 3, 'item_label' => 'Book', 'fields' => [
        'title' => ['type' => 'text'],
        'format' => ['type' => 'select', 'options' => ['paper', 'ebook']],
        'links' => ['type' => 'repeater', 'max' => 2, 'min' => 1, 'item_label' => 'Link', 'fields' => [
            'label' => ['type' => 'text', 'translatable' => true],
            'url' => ['type' => 'url'],
            'icon' => ['type' => 'icon', 'default' => 'cart'],
            'deeper' => ['type' => 'repeater', 'fields' => ['x' => ['type' => 'text']]],
        ]],
    ]],
];
$fields = FieldSchema::normalize($raw);
$links = $fields['books']['fields']['links'] ?? null;
check('a repeater in a row of a top-level repeater is kept', $links['type'] ?? null, 'repeater');
check('with its own fields, labels and limits', [array_keys($links['fields']), $links['item_label'], $links['min'], $links['max']], [['label', 'url', 'icon'], 'Link', 1, 2]);
check('a repeater a third level down is left out', isset($links['fields']['deeper']), false);
check('and named as a problem, by its path', FieldSchema::problems($raw), ['fields.books.links.deeper: a repeater can sit inside another repeater only one level deep (2 levels in all); this field is ignored.']);
check('two levels are no problem', FieldSchema::problems(['a' => ['type' => 'repeater', 'fields' => ['b' => ['type' => 'repeater', 'fields' => ['c' => ['type' => 'text']]]]]]), []);
check('the default of a nested list is an empty list', [$fields['books']['default'], $links['default']], [[], []]);
check('an inline list is never one of lists', FieldSchema::normalize(['a' => ['type' => 'repeater', 'inline' => true, 'fields' => ['b' => ['type' => 'repeater', 'fields' => ['c' => ['type' => 'text']]]]]])['a']['inline'], false);
check('an inline list of one text stays inline', FieldSchema::normalize(['a' => ['type' => 'repeater', 'inline' => true, 'fields' => ['b' => ['type' => 'text']]]])['a']['inline'], true);
$withIcons = FieldSchema::withIcons($fields, ['cart', 'book']);
check('icons are filled in inside the inner list too', [array_keys($withIcons['books']['fields']['links']['fields']['icon']['options']), $withIcons['books']['fields']['links']['fields']['icon']['default']], [['', 'cart', 'book'], 'cart']);

$stored = [
    ['title' => 'One', 'format' => 'nonsense', 'links' => [
        ['label' => 'Shop', 'url' => 'javascript:alert(1)', 'junk' => 'x'],
        ['label' => '', 'url' => ''],
        ['label' => ['default' => 'Βιβλιοπωλείο', 'en' => 'Bookshop'], 'url' => 'https://shop.example/1'],
        ['label' => 'Third', 'url' => '/three'],
    ]],
    ['title' => '', 'links' => [['label' => '', 'url' => '']]],
    'not a row',
    ['title' => 'Two', 'links' => 'not a list'],
    ['title' => 'Three'],
    ['title' => 'Four'],
];
$clean = FieldSchema::clean($fields['books'], $stored);
check('rows are checked at both levels: empty rows dropped, a row whose inner rows are all empty dropped too', array_column($clean, 'title'), ['One', 'Two', 'Three']);
check('each level keeps its own maximum', [count($clean), count($clean[0]['links'])], [3, 2]);
check('values inside inner rows are checked against their fields', $clean[0]['links'][0], ['label' => 'Shop', 'url' => '', 'icon' => 'cart']);
check('a text per language survives inside an inner row', $clean[0]['links'][1]['label'], ['default' => 'Βιβλιοπωλείο', 'en' => 'Bookshop']);
check('an outer value is checked too, and a broken inner list is empty', [$clean[0]['format'], $clean[1]['links']], ['paper', []]);
check('checking again changes nothing', FieldSchema::clean($fields['books'], $clean), $clean);
check('an inner row with only empty texts per language is empty', FieldSchema::clean($fields['books'], [['title' => 'T', 'links' => [['label' => ['default' => '', 'en' => '']]]]])[0]['links'], []);

// A form: "none" markers first, then the rows (PHP turns the marker into an array once rows follow it).
$input = ['books' => [
    ['title' => 'Kept', 'format' => 'ebook', 'links' => [['label' => ['default' => 'Α', 'en' => 'A'], 'url' => '/a', 'icon' => 'book']]],
    ['title' => 'No links', 'format' => 'paper', 'links' => ''],
]];
$values = FieldSchema::fromInput($fields, $input, []);
check('a submitted form with an inner list', $values['books'], [
    ['title' => 'Kept', 'format' => 'ebook', 'links' => [['label' => ['default' => 'Α', 'en' => 'A'], 'url' => '/a', 'icon' => 'book']]],
    ['title' => 'No links', 'format' => 'paper', 'links' => []],
]);
check('the outer marker alone means no rows', FieldSchema::fromInput($fields, ['books' => ''], ['books' => $clean])['books'], []);
check('a declared default list is checked like a value', FieldSchema::normalize(['a' => ['type' => 'repeater', 'default' => [['b' => [['c' => 'x', 'z' => 'y']]]], 'fields' => ['b' => ['type' => 'repeater', 'fields' => ['c' => ['type' => 'text']]]]]])['a']['default'], [['b' => [['c' => 'x']]]]);

// ---- a content type with a list of rows that has a list of its own
$root = sys_get_temp_dir() . '/faros-repeaters-' . getmypid();
mkdir($root . '/custom/content-types', 0775, true);
mkdir($root . '/custom/blocks/nested-fixture', 0775, true);
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
file_put_contents($root . '/custom/content-types/novels.yaml', Yaml::dump([
    'label' => 'Novels',
    'fields' => [
        'author' => ['type' => 'text', 'card' => true],
        'editions' => ['type' => 'repeater', 'card' => true, 'filterable' => true, 'label' => ['el' => 'Εκδόσεις', 'en' => 'Editions'], 'item_label' => ['el' => 'Έκδοση', 'en' => 'Edition'], 'fields' => [
            'publisher' => ['type' => 'text', 'label' => ['el' => 'Εκδότης', 'en' => 'Publisher']],
            'year' => ['type' => 'number'],
            'cover' => ['type' => 'image'],
            'buy' => ['type' => 'repeater', 'label' => 'Buy', 'fields' => [
                'shop' => ['type' => 'text', 'translatable' => true, 'label' => ['el' => 'Κατάστημα', 'en' => 'Shop']],
                'url' => ['type' => 'url'],
            ]],
        ]],
        'notes' => ['type' => 'repeater', 'show' => false, 'fields' => ['text' => ['type' => 'text']]],
    ],
], 8));
$types = new ContentTypes(new Theme($root, 'default'));
$en = $types->definition('novels', 'en', 'el');
$el = $types->definition('novels', 'el', 'el');
check('a type keeps a repeater field', $en['fields']['editions']['type'], 'repeater');
check('texts are chosen for the language, also inside rows and inner rows', [$el['fields']['editions']['label'], $el['fields']['editions']['item_label'], $el['fields']['editions']['fields']['publisher']['label'], $el['fields']['editions']['fields']['buy']['fields']['shop']['label'], $en['fields']['editions']['fields']['buy']['fields']['shop']['label']], ['Εκδόσεις', 'Έκδοση', 'Εκδότης', 'Κατάστημα', 'Shop']);
check('a repeater is never a filter', $en['fields']['editions']['filterable'], false);
check('nor an order of the list', array_filter(array_keys(ContentTypes::orderOptions($en['fields'])), fn($k) => str_contains($k, 'editions')), []);

$details = [
    'author' => 'Apostolos',
    'editions' => [
        ['publisher' => 'Ikaros', 'year' => '2010', 'cover' => '/uploads/a.jpg', 'buy' => [
            ['shop' => ['default' => 'Πολιτεία', 'en' => 'Politeia'], 'url' => 'https://politeia.example/x'],
            ['shop' => ['default' => '', 'en' => ''], 'url' => ''],
        ]],
        ['publisher' => '', 'year' => '', 'cover' => '', 'buy' => ''],
        ['publisher' => 'Bloomsbury', 'year' => '2012', 'cover' => '', 'buy' => ''],
    ],
    'notes' => '',
];
$store = $types->fromInput('novels', $details, [], 'en', 'el');
check('the form is stored checked and tidy: empty rows, empty values and empty lists left out', $store, [
    'author' => 'Apostolos',
    'editions' => [
        ['publisher' => 'Ikaros', 'year' => 2010, 'cover' => '/uploads/a.jpg', 'buy' => [['shop' => ['default' => 'Πολιτεία', 'en' => 'Politeia'], 'url' => 'https://politeia.example/x']]],
        ['publisher' => 'Bloomsbury', 'year' => 2012],
    ],
]);
$file = "---\n" . trim(Yaml::dump(['title' => 'X', 'custom_fields' => $store], 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n";
check('front matter holds the rows as YAML lists, not inline', [str_contains($file, "  editions:\n    -\n      publisher: Ikaros"), str_contains($file, '{')], [true, false]);
$meta = Yaml::parse(substr($file, 4, -5));
check('and reads back to the same values, every field of a row there', $types->values('novels', $meta, 'en', 'el'), ['author' => 'Apostolos', 'editions' => [$store['editions'][0], ['publisher' => 'Bloomsbury', 'year' => 2012, 'cover' => '', 'buy' => []]]]);
check('a stored value is checked before it reaches a template', $types->values('novels', ['custom_fields' => ['editions' => [['publisher' => '<b>P</b>', 'buy' => [['url' => 'javascript:x']], 'year' => 'old']]]], 'en', 'el')['editions'], [['publisher' => '<b>P</b>', 'buy' => [['url' => '', 'shop' => '']], 'year' => 0, 'cover' => '']]); // a row with only a refused value stays, emptied, as at the top level

$page = $types->display('novels', $meta, 'page', fn(string $d): string => $d, 'en', 'el');
check('the page lists the repeater with its rows (a field not shown on the page left out)', array_column($page, 'key'), ['author', 'editions']);
$editions = $page[1];
check('each row is a list of entries, an inner list again with rows', [count($editions['rows']), array_column($editions['rows'][0], 'key'), $editions['rows'][0][3]['type'], count($editions['rows'][0][3]['rows'])], [2, ['publisher', 'year', 'cover', 'buy'], 'repeater', 1]);
check('a text per language shows the page\'s language', [$editions['rows'][0][3]['rows'][0][0]['display'], $types->display('novels', $meta, 'page', fn(string $d): string => $d, 'el', 'el')[1]['rows'][0][3]['rows'][0][0]['display']], ['Politeia', 'Πολιτεία']);
check('the card names the rows in one line', $types->display('novels', $meta, 'card', fn(string $d): string => $d, 'en', 'el')[1]['display'], 'Ikaros, Bloomsbury');
check('an empty list is not shown', array_column($types->display('novels', ['custom_fields' => ['author' => 'A', 'editions' => []]], 'page', fn(string $d): string => $d, 'en', 'el'), 'key'), ['author']);
check('the type\'s files have no problems to report', FieldSchema::problems($types->raw('novels')['fields']), []);

// ---- a block with a list of rows that has a list of its own
file_put_contents($root . '/custom/blocks/nested-fixture/block.yaml', Yaml::dump([
    'label' => 'Nested fixture',
    'fields' => [
        'heading' => ['type' => 'text'],
        'slides' => ['type' => 'repeater', 'fields' => [
            'title' => ['type' => 'text'],
            'text' => ['type' => 'markdown'],
            'buttons' => ['type' => 'repeater', 'max' => 3, 'fields' => [
                'label' => ['type' => 'text'],
                'style' => ['type' => 'select', 'options' => ['primary', 'ghost']],
                'note' => ['type' => 'markdown'],
            ]],
        ]],
    ],
], 8));
$registry = new BlockRegistry(new Theme($root, 'default'));
$definition = $registry->get('nested-fixture');
check('a block keeps a list inside its rows', $definition['fields']['slides']['fields']['buttons']['type'] ?? null, 'repeater');
$editor = array_values(array_filter($registry->editorDefinitions(), fn($d) => $d['type'] === 'nested-fixture'))[0];
$editorButtons = array_values(array_filter(array_values(array_filter($editor['fields'], fn($f) => $f['key'] === 'slides'))[0]['fields'], fn($f) => $f['key'] === 'buttons'))[0];
check('the editor gets the inner list with its fields and choices as pairs', [$editorButtons['type'], array_column($editorButtons['fields'], 'key'), $editorButtons['fields'][1]['options']], ['repeater', ['label', 'style', 'note'], [['primary', 'Primary'], ['ghost', 'Ghost']]]);
$saved = $registry->sanitizeForStorage([['type' => 'nested-fixture', 'slides' => [
    ['title' => 'S1', 'buttons' => [['label' => 'Go', 'style' => 'primary'], ['label' => 'More', 'style' => 'ghost'], ['label' => ''], ['label' => 'A'], ['label' => 'B'], ['label' => 'C']]],
    ['title' => '', 'buttons' => [['label' => '']]],
]]]);
check('storage keeps only what differs from the defaults, also in inner rows, and each level\'s maximum', $saved, [['type' => 'nested-fixture', 'slides' => [['title' => 'S1', 'buttons' => [['label' => 'Go'], ['label' => 'More', 'style' => 'ghost'], ['label' => 'A']]]]]]);

$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$guard = new HtmlGuard(fn() => $environment, fn() => $registry);
$guarded = $guard->eachMarkdownField([['type' => 'nested-fixture', 'slides' => [['text' => 'outer', 'buttons' => [['note' => 'inner'], 'odd']]]]], fn(string $md): string => strtoupper($md));
check('the Markdown guard reaches Markdown inside inner rows', [$guarded[0]['slides'][0]['text'], $guarded[0]['slides'][0]['buttons'][0]['note'], $guarded[0]['slides'][0]['buttons'][1]], ['OUTER', 'INNER', 'odd']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
