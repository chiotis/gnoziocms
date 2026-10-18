<?php
/*
 * Form fields: the kinds that exist, how a stored field is read back (for the form on the site and for the editor),
 * how options are written, and how what the editor sends is cleaned before it is stored.
 *   php tests/unit/form-fields.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\FormFields;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

check('nineteen kinds of field: seventeen to fill in and two that only show something', [count(FormFields::types()), FormFields::DISPLAY, FormFields::isDisplay('heading'), FormFields::isDisplay('text')], [19, ['heading', 'paragraph'], true, false]);
check('a name is an address-safe word', [FormFields::sanitizeName(' Your Name '), FormFields::sanitizeName('e-mail'), FormFields::sanitizeName('  ')], ['your-name', 'e-mail', '']);
check('options are split on lines and commas', FormFields::parseOptions("a, b\nc,, d"), ['a', 'b', 'c', 'd']);

// ---- reading a stored field for the site
$fields = FormFields::normalize([
    ['name' => 'Full Name', 'type' => 'text', 'required' => 'yes'],
    ['name' => 'kind', 'type' => 'select', 'label' => 'Kind', 'options' => ['a|Alpha', 'b:Beta', 'plain', '']],
    ['name' => 'note', 'type' => 'nonsense', 'rows' => '6', 'default' => 'hi', 'help' => 'H', 'placeholder' => 'P', 'min' => '1', 'max' => '9', 'step' => '2'],
    ['name' => '', 'type' => 'text'],
    'not a field',
]);
check('rows without a name and non-rows are skipped', count($fields), 3);
check('the name is made safe and the label comes from it', [$fields[0]['name'], $fields[0]['label']], ['full-name', 'Full Name']);
check('"yes" is required', $fields[0]['required'], true);
check('an unknown kind is a text field', $fields[2]['type'], 'text');
check('options have a value and a label ("|" or ":" split them)', $fields[1]['options'], [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta'], ['value' => 'plain', 'label' => 'plain']]);
check('the other settings are kept as text', [$fields[2]['rows'], $fields[2]['default'], $fields[2]['help'], $fields[2]['placeholder'], $fields[2]['min'], $fields[2]['max'], $fields[2]['step']], [6, 'hi', 'H', 'P', '1', '9', '2']);
check('anything but a list gives no fields', [FormFields::normalize('x'), FormFields::normalize(null)], [[], []]);
check('options can be typed as text', FormFields::normalizeOptions("x|One\ny, z"), [['value' => 'x', 'label' => 'One'], ['value' => 'y', 'label' => 'y'], ['value' => 'z', 'label' => 'z']]);
check('an option with an empty label uses its value', FormFields::normalizeOptions('v|'), [['value' => 'v', 'label' => 'v']]);

// ---- reading it for the builder
$builder = FormFields::forBuilder([
    ['name' => 'kind', 'type' => 'select', 'options' => ['a|Alpha', 'b'], 'required' => true, 'rows' => 3, 'default' => 'b', 'width' => 'half'],
    ['name' => 'other', 'options' => 'x, y', 'width' => 'bogus'],
    ['type' => 'heading', 'label' => 'About you'],
    ['type' => 'paragraph', 'label' => 'Why we ask'],
    ['name' => 'multi', 'type' => 'checkboxes', 'options' => ['a'], 'default' => ['a', 'b']],
]);
check('the builder gets the choices as a list of value and label', $builder[0]['options'], [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'b']]);
check('and the width, the default as text, and that the field was stored', [$builder[0]['width'], $builder[0]['default'], $builder[0]['saved'], $builder[1]['width']], ['half', 'b', true, '']);
check('a form that is not saved yet says so', FormFields::forBuilder([['name' => 'x']], false)[0]['saved'], false);
check('a heading and a paragraph are fields with a name made from their kind', [$builder[2]['type'], $builder[2]['name'], $builder[2]['label'], $builder[3]['name']], ['heading', 'heading-1', 'About you', 'paragraph-1']);
check('a list of defaults is shown as one text', $builder[4]['default'], 'a, b');
check('the width a field takes in its row is one of half, third, two-thirds, or none', array_column(FormFields::normalize([['name' => 'a', 'width' => 'half'], ['name' => 'b', 'width' => 'third'], ['name' => 'c', 'width' => 'two-thirds'], ['name' => 'd', 'width' => '5/6'], ['name' => 'e']]), 'width'), ['half', 'third', 'two-thirds', '', '']);

// ---- what the editor sends
$stored = FormFields::parseInput([
    ['name' => 'Email', 'type' => 'email', 'label' => 'Your email', 'required' => '1', 'placeholder' => ' you@x.test '],
    ['name' => 'form_secret', 'type' => 'text'],
    ['name' => 'kind', 'type' => 'select', 'options' => "a\nb", 'default' => 'a'],
    ['name' => 'plain', 'type' => 'text', 'options' => 'ignored'],
    ['name' => 'msg', 'type' => 'textarea', 'rows' => '8'],
    ['name' => 'num', 'type' => 'number', 'min' => '1', 'max' => '', 'step' => '5'],
]);
check('a name starting with form_ is dropped (it would collide with the form itself)', array_column($stored, 'name'), ['email', 'kind', 'plain', 'msg', 'num']);
check('a stored field has only what it has', $stored[0], ['type' => 'email', 'name' => 'email', 'label' => 'Your email', 'required' => true, 'placeholder' => 'you@x.test']);
check('options are only kept for kinds that use them', [isset($stored[1]['options']), isset($stored[2]['options'])], [true, false]);
check('rows are only for text areas', [$stored[3]['rows'] ?? null, isset($stored[4]['rows'])], [8, false]);
check('a value that was set is kept and an empty one is not stored', [$stored[4]['min'], isset($stored[4]['max']), $stored[4]['step']], ['1', false, '5']);
check('anything but a list gives nothing', FormFields::parseInput('x'), []);

// ---- what the form builder sends
$built = FormFields::parseInput([
    ['type' => 'heading', 'name' => '', 'label' => 'Your details', 'width' => 'half', 'required' => '1', 'placeholder' => 'x'],
    ['type' => 'paragraph', 'name' => '', 'label' => 'Explained'],
    ['type' => 'text', 'name' => 'email', 'label' => 'A'],
    ['type' => 'text', 'name' => 'email', 'label' => 'B', 'width' => 'third'],
    ['type' => 'text', 'name' => 'Email', 'label' => 'C'],
    ['type' => 'hidden', 'name' => 'ref', 'width' => 'half'],
    ['type' => 'radio', 'name' => 'size', 'options' => [['value' => 's', 'label' => 'Small, cheap'], ['value' => '', 'label' => 'Large'], ['value' => 'x|y', 'label' => ''], ['value' => '', 'label' => '']]],
]);
check('a heading or a paragraph is kept with a name of its kind, and only its kind, label and width', [$built[0], $built[1]], [['type' => 'heading', 'name' => 'heading-1', 'label' => 'Your details', 'width' => 'half'], ['type' => 'paragraph', 'name' => 'paragraph-1', 'label' => 'Explained']]);
check('a name used twice gets a number, so two fields never share an answer', array_column($built, 'name'), ['heading-1', 'paragraph-1', 'email', 'email-2', 'email-3', 'ref', 'size']);
check('a width is kept, except on a hidden field', [$built[3]['width'], isset($built[5]['width'])], ['third', false]);
check('choices that come as a list keep a comma in a label, take the label as the value when there is none, and drop empty ones', $built[6]['options'], ['s|Small, cheap', 'Large', 'x/y']);
check('choices typed as text are split on lines and commas, as before', FormFields::storeOptions("a|A\nb, c"), ['a|A', 'b', 'c']);
check('a heading that was posted with a name keeps it', FormFields::parseInput([['type' => 'heading', 'name' => 'part-two', 'label' => 'Two']])[0]['name'], 'part-two');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
