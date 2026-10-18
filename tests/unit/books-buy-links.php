<?php
/*
 * The Books type's buy links: a list of shops (a repeater), and books saved with the old single link (buy_url, buy_label)
 * that must keep their button until they are saved again.
 *   php tests/unit/books-buy-links.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{ContentTypes, Theme};

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$root = sys_get_temp_dir() . '/buylinks' . getmypid();
mkdir($root . '/custom', 0775, true);
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
$types = new ContentTypes(new Theme($root, 'default'));

$def = $types->definition('books', 'en', 'en');
check('the buy links are a list of a label and a link, at most six', [$def['fields']['buy_links']['type'], array_keys($def['fields']['buy_links']['fields']), $def['fields']['buy_links']['max'], $def['fields']['buy_links']['legacy']], ['repeater', ['label', 'url'], 6, ['url' => 'buy_url', 'label' => 'buy_label']]);
check('the old fields are retired, so not editable', [$def['fields']['buy_url']['hidden'], $def['fields']['buy_label']['hidden']], [true, true]);
check('the editor offers the list, not the old fields', array_keys(array_intersect_key($types->editableFields('books'), array_flip(['buy_links', 'buy_url', 'buy_label']))), ['buy_links']);
check('the old fields still count as declared, so they never show up as free-form custom fields', [in_array('buy_url', $types->declaredKeys('books'), true), in_array('buy_label', $types->declaredKeys('books'), true)], [true, true]);

// ---- reading
$old = ['custom_fields' => ['author' => 'A', 'buy_url' => 'https://shop.example/one', 'buy_label' => 'Buy it now']];
check('a book with the old link has it as one row', $types->values('books', $old)['buy_links'], [['url' => 'https://shop.example/one', 'label' => 'Buy it now']]);
check('without the old label the row has none (the template says "Buy the book")', $types->values('books', ['custom_fields' => ['buy_url' => '/shop']])['buy_links'], [['url' => '/shop', 'label' => '']]);
check('a book with neither has no row', isset($types->values('books', ['custom_fields' => ['author' => 'A']])['buy_links']), false);
check('a book with several shops has them in order', array_column($types->values('books', ['custom_fields' => ['buy_links' => [
    ['label' => 'Politeia', 'url' => 'https://a.example'], ['label' => 'Ikaros', 'url' => 'https://b.example'], ['label' => 'Amazon', 'url' => 'https://c.example'],
]]])['buy_links'], 'label'), ['Politeia', 'Ikaros', 'Amazon']);
$both = $types->values('books', ['custom_fields' => ['buy_url' => 'https://old.example', 'buy_links' => [['label' => 'New', 'url' => 'https://new.example']]]]);
check('when both are there the list wins', array_column($both['buy_links'], 'url'), ['https://new.example']);
check('an unsafe link is emptied like any other, so no button is drawn for it', array_column($types->values('books', ['custom_fields' => ['buy_links' => [['label' => 'X', 'url' => 'javascript:alert(1)']]]])['buy_links'] ?? [], 'url'), ['']);

// ---- saving
$existing = $old['custom_fields'];
$kept = $types->fromInput('books', ['author' => 'A'], $existing);
check('saving without the list in the form (a form that never showed it) still drops the old keys: they are the first row of the list now', array_key_exists('buy_url', $kept), false);
$input = ['author' => 'A', 'buy_links' => [
    ['label' => 'Buy it now', 'url' => 'https://shop.example/one'],
    ['label' => 'Ikaros', 'url' => 'https://ikaros.example/x'],
    ['label' => '', 'url' => ''],
]];
$saved = $types->fromInput('books', $input, $existing);
check('saving the form writes the list and drops the old keys', [$saved['buy_links'], array_key_exists('buy_url', $saved), array_key_exists('buy_label', $saved)], [[['label' => 'Buy it now', 'url' => 'https://shop.example/one'], ['label' => 'Ikaros', 'url' => 'https://ikaros.example/x']], false, false]);
check('removing every row removes the button for good', array_key_exists('buy_links', $types->fromInput('books', ['author' => 'A', 'buy_links' => ''], $existing)) || array_key_exists('buy_url', $types->fromInput('books', ['author' => 'A', 'buy_links' => ''], $existing)), false);
check('a seventh shop is not kept', count($types->values('books', ['custom_fields' => ['buy_links' => array_map(fn($i) => ['label' => "S$i", 'url' => "/s$i"], range(1, 7))]])['buy_links']), 6);

// ---- a type of a site that does not use `legacy` is untouched
check('a list without legacy keys does not read other keys', FieldSchemaProbe::legacy(), []);

class FieldSchemaProbe
{
    public static function legacy(): array
    {
        return \GnozioCMS\FieldSchema::normalize(['a' => ['type' => 'repeater', 'fields' => ['b' => ['type' => 'text']]]])['a']['legacy'];
    }
}

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
