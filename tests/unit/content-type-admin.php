<?php
/*
 * The Content types screen: making a type, saving a submitted definition (only differences from the theme are
 * written), and the rows it shows.
 *   php tests/unit/content-type-admin.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ContentTypeAdmin, ContentTypes, Theme};

$root = sys_get_temp_dir() . '/ctadmin' . getmypid();
mkdir("$root/custom", 0775, true);
mkdir("$root/content", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$types = new ContentTypes(new Theme($root, 'default'));
$log = [];
$admin = new ContentTypeAdmin($types, "$root/content", fn() => ['categories', 'tags'], fn(string $key) => in_array($key, ['title', 'slug', 'status'], true), function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = [$action, $id]; });
$manageable = ['pages', 'posts', 'projects'];

// ---- making a type
check('a name is needed', $admin->create(['name' => '  '], $manageable), '/admin/content-types?error=name');
foreach (['pages', 'forms', 'search', 'admin', 'Tags', 'categories', 'uploads', 'custom', 'posts'] as $name) {
    check("\"$name\" is refused", $admin->create(['name' => $name], $manageable), '/admin/content-types?error=name');
}
check('a name that does not start with a letter is refused', $admin->create(['name' => '2024-news'], $manageable), '/admin/content-types?error=name');
check('a type is made: its folder, its definition, the way back, the log', [$admin->create(['name' => 'Events', 'label' => ' Our events '], $manageable), is_dir("$root/content/events"), $types->customRaw('events'), end($log)], ['/admin/content-types?type=events&saved=1', true, ['label' => 'Our events'], ['content_types.create', 'events']]);
check('with no label it is named from the address', [$admin->create(['name' => 'case-studies'], $manageable), $types->customRaw('case-studies')['label']], ['/admin/content-types?type=case-studies&saved=1', 'Case Studies']);
check('a folder that already exists is fine', [$admin->create(['name' => 'events'], ['pages']) === '/admin/content-types?type=events&saved=1'], [true]);
check('a type that exists is refused', $admin->create(['name' => 'events'], ['pages', 'events']), '/admin/content-types?error=name');
chmod("$root/content", 0555);
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
check('a folder that cannot be made is reported', $isRoot ? '/admin/content-types?error=write' : $admin->create(['name' => 'nowrite'], $manageable), '/admin/content-types?error=write');
chmod("$root/content", 0775);

// ---- saving a definition
check('an unknown type goes back to the list', $admin->update(['type' => 'nothing'], $manageable, 'el'), '/admin/content-types');
check('forms are not changed here', $admin->update(['type' => 'forms'], $manageable, 'el'), '/admin/content-types');
$theme = $types->themeDefinition('projects', 'el', 'el');
// The form as the theme has it: the boxes that are ticked are sent, unticked ones are not sent at all.
$ta = $theme['archive'];
$same = ['layout' => $ta['layout'], 'columns' => (string)$ta['columns'], 'per_page' => (string)$ta['per_page'], 'order' => $ta['order'], 'taxonomies' => $ta['taxonomies']];
foreach (['show_image', 'show_excerpt', 'show_date', 'show_meta'] as $box) { if ($ta[$box]) { $same[$box] = '1'; } }
$base = ['type' => 'projects', 'archive' => $same];
check('a form that changes nothing writes nothing', [$admin->update($base, $manageable, 'el'), $types->customRaw('projects')], ['/admin/content-types?type=projects&saved=1', []]);
$admin->update($base + ['label' => ' Our  work ', 'singular' => $theme['singular'], 'description' => ''], $manageable, 'el');
check('a label that differs is kept, tidied, and one that matches the theme is not', [$types->customRaw('projects')['label'] ?? null, isset($types->customRaw('projects')['singular'])], ['Our work', false]);
$admin->update($base + ['label' => $theme['label']], $manageable, 'el');
check('going back to the theme removes it from the file', $types->customRaw('projects'), []);
check('the update is logged with the number of fields', end($log)[0], 'content_types.update');

$form = fn(array $rows, array $more = []) => $base + $more + ['fields' => $rows];
$r1 = ['key' => 'budget', 'label' => 'Budget', 'type' => 'decimal', 'help' => ' In euros ', 'show' => '1', 'card' => '1'];
$admin->update($form([$r1]), $manageable, 'el');
$f = $types->customRaw('projects')['fields'];
check('a field of the site is added with its kind, label, and help', [$f['budget']['type'], $f['budget']['label'], $f['budget']['help'], $f['budget']['card']], ['decimal', 'Budget', 'In euros', true]);
check('a shown field stores no "show"', isset($f['budget']['show']), false);
$admin->update($form([['key' => 'budget', 'label' => '', 'type' => 'nonsense', 'show' => '']]), $manageable, 'el');
$f = $types->customRaw('projects')['fields'];
check('an empty label is made from the key, an unknown kind is text, a hidden one says so', [$f['budget']['label'], $f['budget']['type'], $f['budget']['show'], isset($f['budget']['help']), isset($f['budget']['card'])], ['Budget', 'text', false, false, false]);
$bad = ['Title', '9x', 'has space', 'slug', 'status', str_repeat('a', 41), '', 'ok_key', 'ok_key'];
$admin->update($form(array_map(fn($k) => ['key' => $k, 'label' => $k, 'type' => 'text', 'show' => '1'], $bad)), $manageable, 'el');
$f = $types->customRaw('projects')['fields'] ?? [];
check('keys that are not usable, reserved, too long, or repeated are dropped', array_keys($f), ['ok_key']);
$admin->update($form([['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => "Red\nblue|Sky Blue\n\n  |  \nGreen Dark", 'filterable' => '1', 'show' => '1']]), $manageable, 'el');
$f = $types->customRaw('projects')['fields'];
check('a select keeps its options (an empty first one), values made from plain labels', $f['kind']['options'], ['' => '—', 'red' => 'Red', 'blue' => 'Sky Blue', 'green-dark' => 'Green Dark']);
check('and can be a filter', $f['kind']['filterable'], true);
$admin->update($form([['key' => 'kind', 'label' => 'Kind', 'type' => 'text', 'show' => '1']]), $manageable, 'el');
$f = $types->customRaw('projects')['fields'];
check('turned into text it loses its options and filter', [isset($f['kind']['options']), isset($f['kind']['filterable'])], [false, false]);
// a list of rows (a repeater) is written in the YAML file; the form changes only how it is used, and never makes one
$types->saveCustom('projects', ['fields' => ['team' => ['type' => 'repeater', 'label' => 'Team', 'fields' => ['name' => ['type' => 'text'], 'links' => ['type' => 'repeater', 'fields' => ['url' => ['type' => 'url']]]]]]]);
$types = new ContentTypes(new Theme($root, 'default'));
$admin = new ContentTypeAdmin($types, "$root/content", fn() => ['categories', 'tags'], fn(string $key) => in_array($key, ['title', 'slug', 'status'], true), function (string $action, string $level, ?string $type, ?string $id, string $message, array $ctx) use (&$log) { $log[] = [$action, $id]; });
$teamRow = array_values(array_filter($admin->fieldRows($types->definition('projects', 'el', 'el'), $theme), fn($r) => $r['key'] === 'team'))[0];
check('the screen describes the fields of its rows, the inner list too', $teamRow['sub_fields'], 'Name (text), Links (list: Url (url))');
$admin->update($form([['key' => 'team', 'label' => 'Team', 'type' => 'text', 'show' => '', 'card' => '1']]), $manageable, 'el');
$f = $types->customRaw('projects')['fields'];
check('saving the form keeps it a repeater with its fields, and changes its flags', [$f['team']['type'], array_keys($f['team']['fields']), $f['team']['show'], $f['team']['card']], ['repeater', ['name', 'links'], false, true]);
$admin->update($form([['key' => 'fresh', 'label' => 'Fresh', 'type' => 'repeater', 'show' => '1']]), $manageable, 'el');
check('a new field cannot be made a repeater from the form', $types->customRaw('projects')['fields']['fresh']['type'], 'text');
$admin->update($form([]), $manageable, 'el');
check('a field whose row was removed leaves the definition', $types->customRaw('projects')['fields'] ?? null, null);

// a theme field: only how it is used can change
$themeKey = array_key_first($theme['fields']);
$base2 = $theme['fields'][$themeKey];
$row = ['key' => $themeKey, 'label' => 'Hacked', 'type' => 'toggle', 'card' => $base2['card'] ? '' : '1', 'show' => $base2['show'] ? '1' : '', 'retired' => '1'];
$admin->update($form([$row] + array_map(fn($k) => ['key' => $k, 'show' => $theme['fields'][$k]['show'] ? '1' : '', 'card' => $theme['fields'][$k]['card'] ? '1' : ''], array_slice(array_keys($theme['fields']), 1, null, true))), $manageable, 'el');
$entry = $types->customRaw('projects')['fields'][$themeKey] ?? [];
check('a theme field can be retired or put on the card, but not renamed or retyped', [$entry['hidden'] ?? null, $entry['card'] ?? null, isset($entry['label']), isset($entry['type'])], [true, !$base2['card'], false, false]);
$admin->update($form([$row] + []), $manageable, 'el');
check('theme fields missing from the form are not deleted from the theme, only not written', is_array($types->themeDefinition('projects', 'el', 'el')['fields']) && count($types->themeDefinition('projects', 'el', 'el')['fields']) === count($theme['fields']), true);

// archive
$admin->update(['archive' => ['layout' => 'list', 'per_page' => '5'] + $same] + $base, $manageable, 'el');
check('the layout of the list is kept when it differs from the theme', $types->customRaw('projects')['archive']['layout'] ?? null, 'list');
$admin->update(['type' => 'projects'], $manageable, 'el');
check('a form without the archive part (it is edited in Theme > Archive Layouts) leaves it alone', $types->customRaw('projects')['archive']['layout'] ?? null, 'list');
$admin->update($base, $manageable, 'el');
check('and dropped when it does not', $types->customRaw('projects')['archive'] ?? null, null);

// ---- the screen's rows
$def = $types->definition('projects', 'el', 'el');
$rows = $admin->fieldRows($def, $types->themeDefinition('projects', 'el', 'el'));
check('one row per field, in order, with the keys the editor needs', [array_column($rows, 'key') === array_keys($def['fields']), array_keys($rows[0])], [true, ['key', 'label', 'help', 'type', 'options', 'filterable', 'card', 'show', 'retired', 'from_theme', 'sub_fields']]);
check('theme fields are marked', array_unique(array_column($rows, 'from_theme')), [true]);
$selectRow = array_values(array_filter($rows, fn($r) => $r['type'] === 'select'))[0] ?? null;
check('a select shows its options as lines of value and label, without the empty one', $selectRow !== null && !str_contains($selectRow['options'], "|—") && str_contains($selectRow['options'], '|'), true);
$list = $admin->overview($manageable, 'el');
check('the list has a row per type with its field count, where it comes from, and its layout', [array_column($list, 'type'), array_keys($list[0])], [$manageable, ['type', 'label', 'fields', 'origin', 'layout']]);
check('retired fields are not counted', array_column($list, 'fields', 'type')['projects'], count(array_filter($def['fields'], fn($f) => !$f['hidden'])));

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
