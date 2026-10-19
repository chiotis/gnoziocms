<?php
/*
 * Starting templates of the template builder: the template a type starts from when it is switched to "Built with blocks". A theme (or the
 * site's custom/) ships it as data; for any other type it is generated from the type's definition. Nothing here knows a content type:
 * the types are files created during the test.
 *   php tests/unit/starting-templates.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, BlockSets, ContentTypes, HtmlGuard, SingleTemplateAdmin, SingleTemplates, StartingTemplates, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;

$root = sys_get_temp_dir() . '/start' . getmypid();
mkdir("$root/custom/content-types", 0775, true);
mkdir("$root/custom/single-templates", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($actual === $expected ? '' : $expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

file_put_contents("$root/custom/content-types/notes.yaml", "label: { en: Notes }\nsingular: { en: Note }\nfields:\n  topic: { type: text, label: Topic }\n  mood: { type: select, label: Mood, options: { a: A, b: B } }\n  private: { type: text, label: Private, show: false }\n  old: { type: text, hidden: true }\n");
file_put_contents("$root/custom/content-types/bare.yaml", "label: { en: Bare }\nsingular: { en: Bare }\nfields: {}\n");
file_put_contents("$root/custom/content-types/education.yaml", <<<'YAML'
label: { en: Courses }
singular: { en: Course }
fields:
  teacher: { type: text, label: Teacher }
  cover: { type: image, label: Cover }
  gallery: { type: image, label: Second picture }
  resources:
    type: repeater
    label: Resources
    fields:
      name: { type: text, label: Name }
      size: { type: number, label: Size }
      target: { type: url, label: Target }
      sub: { type: link, label: Other link }
  modules:
    type: repeater
    label: Modules
    fields:
      name: { type: text, label: Name }
YAML);

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$registry = new BlockRegistry($theme);
$gen = static fn(string $type): array => StartingTemplates::generate($types, $type, 'en', 'en');
$starting = new StartingTemplates($theme, $types, $registry, fn() => 'en');

// ---- generated: any type
check('a type with no fields: the title, the text, and the entry\'s own blocks', array_column($gen('bare'), 'type'), ['entry-title', 'content', 'entry-blocks']);
$notes = $gen('notes');
check('a type with plain fields: a fact sheet of the fields the page shows (not the retired or the hidden-from-the-page ones)', [array_column($notes, 'type'), $notes[1]['fields']], [['entry-title', 'fact-sheet', 'content', 'entry-blocks'], [['field' => 'custom_fields.topic'], ['field' => 'custom_fields.mood']]]);
$edu = $gen('education');
check('a type with a picture and a list of links: the picture and the buttons are bound, in a sensible order', array_column($edu, 'type'), ['entry-title', 'image', 'fact-sheet', 'buttons', 'content', 'entry-blocks']);
check('the picture block shows the first picture field', $edu[1], ['type' => 'image', 'image' => ['bind' => 'custom_fields.cover']]);
check('the buttons show the first list that has a text and a link, label and address mapped, the first one primary', $edu[3]['actions'], ['bind' => 'custom_fields.resources', 'map' => ['label' => 'name', 'url' => 'target'], 'fixed' => ['style' => ['first' => 'primary', 'rest' => 'secondary']]]);
check('the other fields, a second picture excepted, are in the fact sheet; a list with no link is a fact', $edu[2]['fields'], [['field' => 'custom_fields.teacher'], ['field' => 'custom_fields.modules']]);
check('the generator is the same for any language of the admin', StartingTemplates::generate($types, 'education', 'el', 'en'), $edu);

// ---- from the stores: checked like a saved template
$sets = $starting->sets('education');
check('the sets to store are the default set, the blocks checked', [array_keys($sets), array_column($sets['default'], 'type')], [['default'], ['entry-title', 'image', 'fact-sheet', 'buttons', 'content', 'entry-blocks']]);
check('a binding with a mapping is kept as it is through the checks', $sets['default'][3]['actions'], $edu[3]['actions']);
check('a type with nothing to show still gets a template', array_keys($starting->sets('bare')), ['default']);

// ---- a theme ships hand-made ones
check('the default theme has one for books (data), none for a type of the site', [$starting->hasFile('books'), $starting->hasFile('education')], [true, false]);
foreach (['books', 'posts', 'projects', 'points', 'routes', 'businesses'] as $type) {
    $set = $starting->sets($type);
    check("$type: the theme's starting template survives the checks and ends with the text and the entry's blocks", [array_key_exists('default', $set), array_slice(array_column($set['default'] ?? [], 'type'), -2)], [true, ['content', 'entry-blocks']]);
}
$books = $starting->sets('books')['default'];
check('books: the title area with the cover beside it, the details, buttons from buy_links', [$books[0], array_column($books, 'type'), $books[2]['actions']], [['type' => 'entry-title', 'variant' => 'split'], ['entry-title', 'fact-sheet', 'buttons', 'content', 'entry-blocks'], ['bind' => 'custom_fields.buy_links', 'map' => ['label' => 'label', 'url' => 'url'], 'fixed' => ['style' => ['first' => 'primary', 'rest' => 'secondary']]]]);
check('and the fields it names are fields the type has', array_diff(array_column($books[1]['fields'], 'field'), array_map(fn($k) => 'custom_fields.' . $k, array_keys($types->editableFields('books', 'en', 'en')))), []);

// ---- the site's own wins, a broken one gives way
file_put_contents("$root/custom/single-templates/books.yaml", "blocks:\n  - { type: entry-title }\n  - { type: content }\n  - { type: nothing-like-it }\n");
check('custom/ overrides the theme (and a block that does not exist is dropped like in any template)', array_column($starting->sets('books')['default'], 'type'), ['entry-title', 'content']);
file_put_contents("$root/custom/single-templates/books.yaml", "blocks: [this is: not valid\n");
check('a file that cannot be read gives way to the theme\'s', array_column($starting->sets('books')['default'], 'type')[1], 'fact-sheet');
file_put_contents("$root/custom/single-templates/education.yaml", "blocks: []\n");
check('a file with no blocks gives way to the generator', array_column($starting->sets('education')['default'], 'type')[1], 'image');
check('a name that is not a type name has no file', $theme->startingTemplateFiles('../books'), []);

// ---- switching a type to its template starts it, once
$st = fn(string $type) => $starting->sets($type);
$data = SingleTemplates::withChoices([], ['education' => 'built', 'notes' => 'theme'], ['education', 'notes'], $st);
check('built, with no blocks yet: the type starts from its starting template and the template is on', [SingleTemplates::entry($data, 'education')['use'], count(SingleTemplates::blocks($data, 'education', 'en', 'en'))], [true, 6]);
check('a type left on the theme\'s is not given one', array_key_exists('notes', $data['single_templates']), false);
$mine = ['single_templates' => SingleTemplates::with([], 'education', false, ['default' => [['type' => 'text', 'heading' => 'Mine']]])];
$again = SingleTemplates::withChoices($mine, ['education' => 'built'], ['education'], $st);
check('a template with blocks of its own is switched on as it is', [SingleTemplates::entry($again, 'education')['use'], SingleTemplates::blocks($again, 'education', 'en', 'en')], [true, [['type' => 'text', 'heading' => 'Mine']]]);
$emptied = ['single_templates' => ['education' => ['use' => true, 'blocks' => []]]];
check('a template that is on and was emptied is not filled again by saving the cards', SingleTemplates::blocks(SingleTemplates::withChoices($emptied, ['education' => 'built'], ['education'], $st), 'education', 'en', 'en'), []);
check('without a way to start, nothing changes', SingleTemplates::entry(SingleTemplates::withChoices([], ['education' => 'built'], ['education']), 'education')['blocks'], []);

// ---- the screen and "Start again"
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$guard = new HtmlGuard(fn() => $environment, fn() => $registry);
$settings = ['base_url' => 'https://s.test/', 'languages' => ['default' => 'en', 'available' => ['en', 'el']]];
$sets2 = new BlockSets(fn() => $registry, fn() => $guard, fn() => true, fn(string $lang): array => [], fn() => $settings);
$admin = new SingleTemplateAdmin($theme, $types, $registry, $sets2, $starting, fn() => ['education', 'books'], fn() => [], fn() => null, fn() => 'en');
$view = $admin->view('education', [], []);
$json = json_decode($view['template_json'], true);
check('a template with no blocks opens on the starting template, and says so', [$view['template_starts'], array_column($json['blocks']['default'], 'type')], [true, ['entry-title', 'image', 'fact-sheet', 'buttons', 'content', 'entry-blocks']]);
$stored = ['single_templates' => ['education' => ['use' => true, 'blocks' => ['default' => [['type' => 'text', 'heading' => 'Mine']], 'el' => [['type' => 'text', 'heading' => 'Δικό μου']]]]]];
$view = $admin->view('education', $stored, []);
check('a template with blocks opens on them', [$view['template_starts'], json_decode($view['template_json'], true)['blocks']['default'][0]['heading']], [false, 'Mine']);
$after = $admin->startedAgain('education', $stored + ['other' => 1]);
check('start again replaces the blocks of every language, switches the template on and touches nothing else', [
    SingleTemplates::entry($after, 'education')['use'],
    array_column(SingleTemplates::blocks($after, 'education', 'el', 'en'), 'type'),
    array_column(SingleTemplates::blocks($after, 'education', 'en', 'en'), 'type'),
    $after['other'],
], [true, ['entry-title', 'image', 'fact-sheet', 'buttons', 'content', 'entry-blocks'], ['entry-title', 'image', 'fact-sheet', 'buttons', 'content', 'entry-blocks'], 1]);
$off = ['single_templates' => ['education' => ['use' => false, 'blocks' => [['type' => 'text']]]]];
check('start again on a template that was off switches it on', SingleTemplates::entry($admin->startedAgain('education', $off), 'education')['use'], true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
