<?php
/*
 * The screen of a template built with blocks, without a request (SingleTemplateAdmin) and what the block editors that keep their blocks
 * in the theme settings share (BlockSets): which types can have a template, what the screen shows (the entries to preview, the fields
 * of the type as choices, the bindings), what saving stores, what the preview draws, how the sets sent are checked (raw HTML only for
 * someone who may add it, the sets not sent kept), what the editor starts from for one language and for several, and the choices of the
 * Single Layouts cards.
 *   php tests/unit/single-template-admin.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, BlockSets, ContentItem, ContentTypes, HtmlGuard, SingleTemplateAdmin, SingleTemplates, StartingTemplates, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;

$root = sys_get_temp_dir() . '/sta' . getmypid();
mkdir("$root/custom/content-types", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }
file_put_contents("$root/custom/content-types/education.yaml", "label: { en: Courses }\nsingular: { en: Course }\nfields:\n  teacher: { type: text, label: Teacher }\n  hours: { type: number, label: Hours }\n  places: { type: repeater, label: Places, fields: { name: { type: text } } }\n");

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$registry = new BlockRegistry($theme);
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$guard = new HtmlGuard(fn() => $environment, fn() => $registry);
$mayHtml = true;
$settings = ['base_url' => 'https://s.test/', 'languages' => ['default' => 'el', 'available' => ['el']]];
$sets = new BlockSets(fn() => $registry, fn() => $guard, function () use (&$mayHtml): bool { return $mayHtml; }, fn(string $lang): array => [['id' => 'p-' . $lang]], function () use (&$settings): array { return $settings; });

$item = static fn(string $slug, string $lang, string $date, string $title = ''): ContentItem => new ContentItem('education', $slug, $lang, ['title' => $title ?: $slug, 'date' => $date], '', '', '', 0);
$entries = [$item('old', 'el', '2025-01-01'), $item('new', 'el', '2026-05-01'), $item('new', 'en', '2026-05-01', 'New (en)')];
$admin = new SingleTemplateAdmin($theme, $types, $registry, $sets, new StartingTemplates($theme, $types, $registry, fn() => 'el'), fn() => ['pages', 'education', 'forms'], fn(string $type): array => $type === 'education' ? $entries : [], function (string $type, string $slug, string $lang) use ($entries): ?ContentItem {
    foreach ($entries as $e) { if ($e->type === $type && $e->slug === $slug && $e->lang === $lang) return $e; }
    return null;
}, fn() => 'el');

// ---- which types
check('a type of the site can have a template, a form and a type that is not there cannot', [$admin->available('education'), $admin->available('pages'), $admin->available('forms'), $admin->available('nothing')], [true, true, false, false]);

// ---- the screen
$view = $admin->view('education', [], []);
check('it names the type and starts with the template on (there is none yet)', [$view['type'], $view['type_label'], $view['type_singular'], $view['template_use'], $view['saved'], $view['failed']], ['education', 'Courses', 'Course', true, false, false]);
check('the entries to preview are the latest first, each in its language, the first one chosen', [array_column($view['preview_choices'], 'value'), $view['preview_chosen']], [['new|el', 'new|en', 'old|el'], 'new|el']);
check('an entry asked for is chosen, one that is not there is not', [$admin->view('education', [], ['entry' => 'old|el'])['preview_chosen'], $admin->view('education', [], ['entry' => 'nope|el'])['preview_chosen']], ['old|el', 'new|el']);
$json = json_decode($view['template_json'], true);
check('the editor is told it is a template for the type, with the sources a block can show and the table of what fits', [$json['context'], $json['bindings']['type'], in_array('custom_fields.teacher', array_column($json['bindings']['sources'], 'path'), true), isset($json['bindings']['accepts']['text'])], ['template', 'education', true, true]);
$fact = array_column($json['definitions'], null, 'type')['fact-sheet'];
check('the blocks of a template are offered, and the Fact sheet chooses among the declared fields (a list of rows included in none)', [$fact['fields'][1]['fields'][0]['options']], [[['', '—'], ['custom_fields.teacher', 'Teacher (teacher)'], ['custom_fields.hours', 'Hours (hours)']]]);
check('the screen says what happened after a save', [$admin->view('education', [], ['saved' => '1'])['saved'], $admin->view('education', [], ['fail' => '1'])['failed']], [true, true]);
$stored = ['single_templates' => ['education' => ['use' => false, 'blocks' => [['type' => 'text']]]]];
check('with a template that is off, the switch is off', $admin->view('education', $stored, [])['template_use'], false);

// ---- saving
$block = [['type' => 'text', 'heading' => ['bind' => 'title']]];
$post = ['blocks_editor' => '1', 'blocks_json' => json_encode($block), 'use' => '1'];
$saved = $admin->saved('education', ['x' => 1], $post);
check('saving stores the blocks and the switch and leaves the rest of the settings', [$saved['single_templates']['education'], $saved['x']], [['use' => true, 'blocks' => $block], 1]);
check('without the switch it is off', $admin->saved('education', [], ['blocks_editor' => '1', 'blocks_json' => json_encode($block)])['single_templates']['education']['use'], false);
check('without the editor\'s data the blocks stay as they are', $admin->saved('education', $stored, ['use' => '1'])['single_templates']['education'], ['use' => true, 'blocks' => [['type' => 'text']]]);
check('an empty template that is off leaves nothing behind', $admin->saved('education', $stored, ['blocks_editor' => '1', 'blocks_json' => '[]'])['single_templates'] ?? null, null);

// ---- the preview
$preview = $admin->preview('education', [], ['preview_entry' => 'new|en', 'blocks_editor' => '1', 'blocks_json' => json_encode($block)]);
check('it names the entry and the settings with the unsaved blocks, as if the template were on', [$preview['item']->lang, $preview['settings']['single_templates']['education']], ['en', ['use' => true, 'blocks' => $block]]);
check('an entry that is not there, a language that is not one, a path in a slug: nothing', [$admin->preview('education', [], ['preview_entry' => 'nope|el']), $admin->preview('education', [], ['preview_entry' => 'new|../x']), $admin->preview('education', [], ['preview_entry' => '|el']), $admin->preview('education', [], [])], [null, null, null, null]);

// ---- the sets sent are checked
$html = [['type' => 'text', 'heading' => 'H', 'body' => 'Hello <script>alert(1)</script>']];
check('someone who may add raw HTML keeps it', $sets->clean($html, null)['default'][0]['body'], 'Hello <script>alert(1)</script>');
$mayHtml = false;
check('someone who may not sees it as text', str_contains($sets->clean($html, null)['default'][0]['body'], '<script>'), false);
check('HTML already stored stays for them', $sets->clean($html, $html)['default'][0]['body'], 'Hello <script>alert(1)</script>');
$mayHtml = true;
check('a block the registry does not know a field of loses it, and the shapes work: a list, a map, the set not sent kept', [
    $sets->clean([['type' => 'text', 'nonsense' => 1, 'heading' => 'A']], null),
    array_keys($sets->clean(['default' => $block], ['default' => $block, 'en' => $block])),
], [['default' => [['type' => 'text', 'heading' => 'A']]], ['default', 'en']]);

// ---- what the editor starts from
$one = json_decode($sets->editorJson('el', $block, 'footer', []), true);
check('with one language it is a plain list and no language bar', [$one['blocks'], $one['languages'], $one['context'], $one['presets'], $one['presets_url']], [$block, [], 'footer', [['id' => 'p-el']], 'https://s.test/admin/block-presets']);
$settings['languages']['available'] = ['el', 'en'];
$two = json_decode($sets->editorJson('el', ['default' => $block, 'en' => []], 'template', [], ['bindings' => ['type' => 'x']]), true);
check('with several it is a set for each, the site\'s own first, and what the caller adds', [$two['blocks'], $two['languages'], $two['bindings']], [['default' => $block, 'en' => []], [['key' => 'default', 'code' => 'el'], ['key' => 'en', 'code' => 'en']], ['type' => 'x']]);
check('the languages of the site, its own first', $sets->languages(), ['el', 'en']);

// ---- the choices of the Single Layouts cards
$data = SingleTemplates::withChoices(['single_templates' => SingleTemplates::with([], 'pages', false, ['default' => $block])], ['education' => 'built', 'pages' => 'built', 'forms' => 'built', 'ghost' => 'built', 'x' => ['a']], ['pages', 'education', 'forms']);
check('a type that exists is switched on, a form and a type that is not there are not, the blocks stay', [array_keys($data['single_templates']), $data['single_templates']['pages'], $data['single_templates']['education']['use']], [['pages', 'education'], ['use' => true, 'blocks' => $block], true]);
check('"The theme\'s" switches it off and keeps the blocks', SingleTemplates::withChoices($data, ['pages' => 'theme'], ['pages'])['single_templates']['pages'], ['use' => false, 'blocks' => $block]);
check('nothing left to keep leaves no key', SingleTemplates::withChoices(['single_templates' => ['education' => ['use' => true, 'blocks' => []]]], ['education' => 'theme'], ['education']), []);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
