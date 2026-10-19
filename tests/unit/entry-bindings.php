<?php
/*
 * Templates built with blocks, the server side: field bindings (what a block field shows of the entry, each compatible kind, a
 * select's label, numbers and dates, a text per language, a field that is gone or changed), how a bound value is cleaned like a typed
 * one, how a template is stored (a set for each language), which template draws an entry, and the blocks that draw parts of the
 * entry (Entry title, Fact sheet, Entry text, Entry blocks). The content type used is added only as a file in custom/content-types
 * during the test: nothing in the code knows it.
 *   php tests/unit/entry-bindings.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{BlockRegistry, BlockRenderer, BlockRuntime, ContentItem, ContentTypes, EntryBindings, Images, SingleLayouts, SingleTemplates, SiteTwig, StructuredData, Taxonomies, TemplateResolver, Theme};

$root = sys_get_temp_dir() . '/entrybind' . getmypid();
mkdir("$root/custom/content-types", 0775, true);
mkdir("$root/public", 0775, true);
mkdir("$root/content/taxonomies", 0775, true);
mkdir("$root/admin/templates", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// A content type that exists only as a file of the site: the code has never heard of it.
file_put_contents("$root/custom/content-types/education.yaml", <<<'YAML'
label: { en: Courses, el: Μαθήματα }
singular: { en: Course, el: Μάθημα }
fields:
  headline:
    type: text
    translatable: true
    label: { en: Headline, el: Επικεφαλίδα }
  level:
    type: select
    label: Level
    options: { beginner: Beginner level, advanced: Advanced level }
  hours:
    type: number
    label: Hours
  starts:
    type: date
    label: Starts
  ratio:
    type: decimal
    label: Ratio
  summary:
    type: textarea
    label: Summary
  syllabus:
    type: markdown
    label: Syllabus
  cover:
    type: image
    label: Cover
  apply:
    type: link
    label: Apply
  website:
    type: url
    label: Website
  certified:
    type: toggle
    label: Certified
  place:
    type: location
    label: Place
  modules:
    type: repeater
    label: Modules
    fields:
      name: { type: text, label: Name }
YAML);

$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$fmt = static fn(string $d): string => 'D:' . $d;
$labels = static fn(string $tax, string $id): string => strtoupper($tax[0]) . ':' . $id;
$course = static fn(array $fields, array $meta = []): ContentItem => new ContentItem('education', 'intro', 'en', ['title' => 'Intro course', 'custom_fields' => $fields] + $meta, '', '<p>Body text</p>', '', 0);
$bind = static fn(ContentItem $item, string $lang = 'en'): EntryBindings => new EntryBindings($item, $types, $lang, 'el', $fmt, $labels);

// ---- what a type offers to bind comes from its definition
$sources = EntryBindings::sources($types, 'education', 'en', 'en');
$paths = array_column($sources, 'path');
check('the common fields come first, then the declared ones, a list of rows is left for a list', array_slice($paths, 0, 7), ['title', 'excerpt', 'main_image', 'date', 'author', 'categories', 'tags']);
check('every declared field of the type is there (a list of rows is not)', array_values(array_filter($paths, fn($p) => str_starts_with($p, 'custom_fields.'))), ['custom_fields.headline', 'custom_fields.level', 'custom_fields.hours', 'custom_fields.starts', 'custom_fields.ratio', 'custom_fields.summary', 'custom_fields.syllabus', 'custom_fields.cover', 'custom_fields.apply', 'custom_fields.website', 'custom_fields.certified', 'custom_fields.place']);
check('a field has its label in the language asked and its key', array_values(array_filter($sources, fn($s) => $s['path'] === 'custom_fields.headline')), [['path' => 'custom_fields.headline', 'key' => 'headline', 'label' => 'Headline', 'kind' => 'text', 'group' => 'Courses']]);
check('in Greek the label is Greek', array_column(EntryBindings::sources($types, 'education', 'el', 'el'), 'label', 'path')['custom_fields.headline'], 'Επικεφαλίδα');
$of = static fn(string $type) => array_column(EntryBindings::compatible($sources, $type), 'key');
check('a text takes the title, text, select, number, date, terms and author', $of('text'), ['title', 'date', 'author', 'categories', 'tags', 'headline', 'level', 'hours', 'starts', 'ratio']);
check('a text of several lines or Markdown takes the excerpt and such fields', [$of('textarea'), $of('markdown')], [['excerpt', 'summary', 'syllabus'], ['excerpt', 'summary', 'syllabus']]);
check('a picture takes the main image and picture fields', $of('image'), ['main_image', 'cover']);
check('a link or an address takes links and addresses', [$of('link'), $of('url')], [['apply', 'website'], ['apply', 'website']]);
check('a choice, a switch or a list cannot be bound', [EntryBindings::bindable('select'), EntryBindings::bindable('toggle'), EntryBindings::bindable('repeater'), EntryBindings::bindable('text')], [false, false, false, true]);

// ---- the value of each kind
$item = $course([
    'headline' => ['default' => 'Ξεκίνα', 'en' => 'Get started'],
    'level' => 'advanced', 'hours' => 12, 'starts' => '2026-03-04', 'ratio' => '2.5',
    'summary' => "Line one\nLine two", 'syllabus' => '**Bold** notes', 'cover' => '/uploads/c.jpg',
    'apply' => '/apply', 'website' => 'https://example.org/x', 'certified' => true, 'place' => '35.2, 26.3',
], ['excerpt' => 'Short words', 'main_image' => '/uploads/main.jpg', 'date' => '2026-02-01', 'author' => 'Ada', 'categories' => ['a', 'b'], 'tags' => ['t']]);
$b = $bind($item);
check('title, excerpt, main image, author', [$b->value('title', 'text'), $b->value('excerpt', 'textarea'), $b->value('main_image', 'image'), $b->value('author', 'text')], ['Intro course', 'Short words', '/uploads/main.jpg', 'Ada']);
check('a date is written as the site writes dates, also a declared one', [$b->value('date', 'text'), $b->value('custom_fields.starts', 'text')], ['D:2026-02-01', 'D:2026-03-04']);
check('a select gives the label of its option, a number its digits', [$b->value('custom_fields.level', 'text'), $b->value('custom_fields.hours', 'text'), $b->value('custom_fields.ratio', 'text')], ['Advanced level', '12', '2.5']);
check('terms are their names, in one line', $b->value('categories', 'text'), 'C:a, C:b');
check('text, Markdown, picture, link and address fields', [$b->value('custom_fields.summary', 'textarea'), $b->value('custom_fields.syllabus', 'markdown'), $b->value('custom_fields.cover', 'image'), $b->value('custom_fields.apply', 'link'), $b->value('custom_fields.website', 'url'), $b->value('custom_fields.apply', 'url')], ["Line one\nLine two", '**Bold** notes', '/uploads/c.jpg', '/apply', 'https://example.org/x', '/apply']);
check('a text per language: the page\'s language, else the site\'s own', [$bind($item, 'en')->value('custom_fields.headline', 'text'), $bind($item, 'el')->value('custom_fields.headline', 'text'), $bind($item, 'de')->value('custom_fields.headline', 'text')], ['Get started', 'Ξεκίνα', 'Ξεκίνα']);
check('a field the entry has no value for is empty, not missing', [$bind($course([]))->value('custom_fields.hours', 'text'), $bind($course([]))->value('excerpt', 'textarea')], ['', '']);

// ---- a field that is gone, renamed, or another kind now
check('a path the type does not declare is missing', [$b->value('custom_fields.nothing', 'text'), $b->value('custom_fields.modules', 'text'), $b->value('nothing', 'text'), $b->value('custom_fields.a.b', 'text'), $b->value('', 'text')], [null, null, null, null, null]);
check('a field that is another kind does not fit (an image bound to a text, a text to a picture)', [$b->value('custom_fields.cover', 'text'), $b->value('custom_fields.headline', 'image'), $b->value('title', 'image'), $b->value('main_image', 'link')], [null, null, null, null]);
check('a field no block field can show is never offered or resolved', [$b->value('custom_fields.certified', 'text'), $b->value('custom_fields.place', 'text')], [null, null]);

// ---- applying bindings to a block
$registry = new BlockRegistry($theme, ['content_types' => fn() => ['posts' => 'Posts'], 'forms' => fn() => ['' => '—']]);
$textFields = $registry->get('text')['fields'];
$applied = $b->apply($textFields, ['type' => 'text', 'heading' => ['bind' => 'title'], 'body' => ['bind' => 'custom_fields.syllabus'], 'eyebrow' => 'Typed']);
check('bound fields get the entry\'s values, typed ones stay', [$applied['raw']['heading'], $applied['raw']['body'], $applied['raw']['eyebrow'], $applied['hidden'], $applied['missing']], ['Intro course', '**Bold** notes', 'Typed', false, []]);
$gone = $b->apply($textFields, ['type' => 'text', 'heading' => ['bind' => 'custom_fields.removed'], 'body' => ['bind' => 'custom_fields.cover']]);
check('a binding to a field that is gone or does not fit leaves the field empty and says so', [array_key_exists('heading', $gone['raw']), array_key_exists('body', $gone['raw']), $gone['missing'], $gone['hidden']], [false, false, ['custom_fields.removed', 'custom_fields.cover'], true]);
$empty = $bind($course([]));
check('a block is left out when everything bound in it is empty', [$empty->apply($textFields, ['heading' => ['bind' => 'custom_fields.headline']])['hidden'], $empty->apply($textFields, ['heading' => ['bind' => 'custom_fields.headline'], 'eyebrow' => ['bind' => 'title']])['hidden']], [true, false]);
check('unless it asks to stay (off), and a block with nothing bound is never left out', [$empty->apply($textFields, ['heading' => ['bind' => 'custom_fields.headline'], 'bind_hide' => false])['hidden'], $empty->apply($textFields, ['heading' => ''])['hidden']], [false, false]);
check('a binding in a field that cannot be bound (a choice) is dropped', array_key_exists('align', $b->apply($textFields, ['align' => ['bind' => 'title']])['raw']), false);

// ---- the registry keeps a binding as it is, and only a real one
$stored = $registry->sanitizeForStorage([
    ['type' => 'text', 'heading' => ['bind' => 'custom_fields.headline'], 'body' => ['bind' => 'title; drop'], 'align' => ['bind' => 'title'], 'bind_hide' => false],
    ['type' => 'text', 'heading' => 'Typed', 'bind_hide' => false],
    ['type' => 'text', 'heading' => ['bind' => 'title']],
]);
check('a binding is stored as it is; a path that is not one, a choice and a width are not', $stored[0], ['type' => 'text', 'heading' => ['bind' => 'custom_fields.headline'], 'bind_hide' => false]);
check('"stay when empty" is kept only in a block that has a binding', $stored[1], ['type' => 'text', 'heading' => 'Typed']);
check('and "hide when empty" (the default) is not written', $stored[2], ['type' => 'text', 'heading' => ['bind' => 'title']]);

// ---- template-only blocks are offered only to a template
$editorTypes = array_column($registry->editorDefinitions(), 'type');
$templateTypes = array_column($registry->editorDefinitions(true, [['path' => 'custom_fields.hours', 'label' => 'Hours (hours)']]), null, 'type');
check('an entry or the footer does not offer the blocks of a template', [in_array('entry-title', $editorTypes, true), in_array('fact-sheet', $editorTypes, true), in_array('entry-blocks', $editorTypes, true), in_array('content', $editorTypes, true)], [false, false, false, true]);
check('a template does, and the Fact sheet\'s list is a choice among the fields of the type', [isset($templateTypes['entry-title'], $templateTypes['entry-blocks']), $templateTypes['fact-sheet']['fields'][1]['fields'][0]['type'], $templateTypes['fact-sheet']['fields'][1]['fields'][0]['options']], [true, 'select', [['', '—'], ['custom_fields.hours', 'Hours (hours)']]]);
check('but the block still checks the name as a text when the page is drawn', $registry->get('fact-sheet')['fields']['fields']['fields']['field']['type'], 'text');

// ---- storage: a default set and a set for a language, like the Footer Blocks
$blocks = [['type' => 'text', 'heading' => 'Hello']];
$settings = ['single_templates' => SingleTemplates::with([], 'education', true, ['default' => $blocks])];
check('a template is stored under its type, a plain list for one set', $settings['single_templates'], ['education' => ['use' => true, 'blocks' => $blocks]]);
check('it applies in a language that has no set of its own (the default set)', [SingleTemplates::applies($settings, 'education', 'en', 'el'), SingleTemplates::applies($settings, 'education', 'el', 'el'), SingleTemplates::applies($settings, 'books', 'el', 'el')], [true, true, false]);
$two = SingleTemplates::with($settings, 'education', null, ['default' => $blocks, 'en' => [['type' => 'text', 'heading' => 'English']]]);
$settings['single_templates'] = $two;
check('a second language has its own set; the others keep the default', [SingleTemplates::blocks($settings, 'education', 'en', 'el')[0]['heading'], SingleTemplates::blocks($settings, 'education', 'el', 'el')[0]['heading'], SingleTemplates::blocks($settings, 'education', 'fr', 'el')[0]['heading']], ['English', 'Hello', 'Hello']);
$off = SingleTemplates::with($settings, 'education', false, null);
check('switching it off (Reset) keeps the blocks, and switching on again finds them', [SingleTemplates::applies(['single_templates' => $off], 'education', 'en', 'el'), SingleTemplates::applies(['single_templates' => SingleTemplates::with(['single_templates' => $off], 'education', true, null)], 'education', 'en', 'el')], [false, true]);
check('a type with no blocks and switched off is not kept', SingleTemplates::with(['single_templates' => $off], 'education', false, []), []);
check('a name that is not a type name is not stored', SingleTemplates::with([], '../x', true, ['default' => $blocks]), []);
check('"use" without blocks does not apply', SingleTemplates::applies(['single_templates' => SingleTemplates::with([], 'education', true, null)], 'education', 'en', 'el'), false);

// ---- which template draws an entry
$themeSettings = [];
$resolver = new TemplateResolver($theme, new SingleLayouts($theme, $types), function () use (&$themeSettings): array { return $themeSettings; }, fn(): string => 'el');
$entry = static fn(string $type, array $meta = [], string $lang = 'el'): ContentItem => new ContentItem($type, 'x', $lang, $meta, '', '', '', 0);
$standard = $theme->defaultSingleTemplate('education', 'education');
check('without a template the theme draws it', $resolver->forItem($entry('education')), $standard);
$themeSettings = ['single_templates' => SingleTemplates::with([], 'education', true, ['default' => $blocks, 'en' => [['type' => 'text']]])];
check('a type built with blocks is drawn by single-built.twig', $resolver->forItem($entry('education')), 'templates/single-built.twig');
check('also in a language with a set of its own, and one without', [$resolver->forItem($entry('education', [], 'en')), $resolver->forItem($entry('education', [], 'fr'))], ['templates/single-built.twig', 'templates/single-built.twig']);
check('the entry\'s own template comes first', $resolver->forItem($entry('education', ['template' => 'landing'])), 'templates/landing.twig');
check('so does its request for the plain layout of the theme', $resolver->forItem($entry('education', ['template' => 'standard'])), $standard);
check('a template nobody has does not stop the built one', $resolver->forItem($entry('education', ['template' => 'nothing-like-it'])), 'templates/single-built.twig');
check('another type is drawn as before, and a form never from a template', [$resolver->forItem($entry('pages')), $resolver->forItem($entry('forms'))], [$theme->defaultSingleTemplate('pages', 'page'), $theme->defaultSingleTemplate('forms', 'form')]);
$themeSettings = ['single_templates' => SingleTemplates::with([], 'education', false, ['default' => $blocks])];
check('switched off it is the theme\'s again', $resolver->forItem($entry('education')), $standard);
check('the blocks of the template, for the entry that is drawn', [(new TemplateResolver($theme, new SingleLayouts($theme, $types), fn() => ['single_templates' => SingleTemplates::with([], 'education', true, ['default' => $blocks])], fn() => 'el'))->builtBlocks($entry('education')), $resolver->builtBlocks($entry('education'))], [$blocks, null]);

// ---- drawing a template: the renderer with the entry
$settings = ['base_url' => 'https://s.test', 'home_page' => 'index', 'date_format' => 'Y-m-d', 'languages' => ['default' => 'en'], 'title' => 'Site'];
$taxonomies = new Taxonomies("$root/content", ['el', 'en']);
$twig = (new SiteTwig(
    $root, $theme, new Images("$root/public"),
    fn(): array => $settings, fn(): array => [], fn(): string => 'en', fn(): string => 'en', fn(): bool => false, fn(string $c): bool => false, fn(): string => 't',
    fn(string $k, ?string $f): string => $f ?? $k, fn(string $p): string => 'https://s.test/' . ltrim($p, '/'),
    fn(): StructuredData => throw new RuntimeException('not needed'), fn(): ContentTypes => $types, fn(): SingleLayouts => new SingleLayouts($theme, $types), fn(): Taxonomies => $taxonomies,
    fn(string $t, string $id, ?string $l): string => $taxonomies->label($t, $id, $l ?? 'en'), fn(string $t, string $id, string $prefix): string => 'x',
    function (): BlockRuntime { throw new RuntimeException('no block runtime in this test'); }, fn(): bool => true, fn(): array => ['en']
))->create();
$renderer = new BlockRenderer($registry, $twig, $theme, fn(string $md): string => '<p>' . htmlspecialchars($md, ENT_QUOTES) . '</p>', [], 'https://s.test');
$draw = static function (array $blocks, ContentItem $item, array $extra = []) use ($renderer, $bind): array {
    return $renderer->render($blocks, ['item' => $item, 'lang' => 'en', 'lang_prefix' => '', 'body_html' => $item->html, 'template_mode' => true, 'bindings' => $bind($item)] + $extra);
};

$page = $draw([['type' => 'text', 'heading' => ['bind' => 'title'], 'body' => ['bind' => 'custom_fields.syllabus']]], $item);
check('a bound heading and text show the entry\'s', [str_contains($page['html'], 'Intro course'), str_contains($page['html'], '**Bold** notes')], [true, true]);
$attack = $course(['headline' => '<script>alert(1)</script> "quoted"', 'syllabus' => '<img src=x onerror=alert(1)>', 'cover' => 'javascript:alert(1)', 'apply' => 'javascript:alert(2)', 'website' => "https://e.org/a'b\"c"]);
$page = $draw([
    ['type' => 'text', 'heading' => ['bind' => 'custom_fields.headline'], 'body' => ['bind' => 'custom_fields.syllabus']],
    ['type' => 'image', 'image' => ['bind' => 'custom_fields.cover']],
    ['type' => 'hero', 'heading' => 'Hero', 'image' => ['bind' => 'custom_fields.cover'], 'actions' => [['label' => 'Go', 'url' => 'javascript:alert(3)']]],
], $attack);
check('a script in a field is escaped, as it would be typed', [str_contains($page['html'], '<script>alert(1)'), str_contains($page['html'], '&lt;script&gt;alert(1)')], [false, true]);
check('markup in a Markdown field is escaped by the Markdown of the site, not trusted', str_contains($page['html'], '<img src=x onerror'), false);
check('an unsafe picture or link address is not used', [str_contains($page['html'], 'javascript:alert(1)'), str_contains($page['html'], 'javascript:alert(2)')], [false, false]);
$page = $draw([['type' => 'text', 'heading' => 'Always', 'body' => ['bind' => 'custom_fields.syllabus'], 'eyebrow' => ['bind' => 'custom_fields.headline']]], $course([]));
check('a block with something typed that has empty bindings is left out all the same, unless it asks to stay', str_contains($page['html'], 'Always'), false);
$page = $draw([['type' => 'text', 'heading' => 'Always', 'body' => ['bind' => 'custom_fields.syllabus'], 'bind_hide' => false], ['type' => 'image', 'image' => ['bind' => 'custom_fields.cover']], ['type' => 'text', 'heading' => 'Typed only']], $course([]));
check('"stay when empty" keeps it, a bound picture with no picture is gone, a block with nothing bound is shown', [str_contains($page['html'], 'Always'), str_contains($page['html'], 'block-image'), str_contains($page['html'], 'Typed only')], [true, false, true]);
$page = $draw([['type' => 'text', 'heading' => ['bind' => 'custom_fields.removed']]], $item);
check('a field that is gone draws nothing and raises nothing', $page['html'], '');
$page = $draw([['type' => 'text', 'heading' => ['bind' => 'custom_fields.removed'], 'body' => 'Still here', 'bind_hide' => false]], $item);
check('and with "stay" the block is drawn without it', str_contains($page['html'], 'Still here'), true);

// ---- the blocks that draw parts of the entry
$withBlocks = $course(['hours' => 5, 'level' => 'beginner'], []);
$withBlocks->meta['blocks'] = [['type' => 'quote', 'quote' => 'Its own quote'], ['type' => 'faq', 'items' => [['question' => 'Q?', 'answer' => 'A.']]]];
$page = $draw([
    ['type' => 'entry-title'],
    ['type' => 'fact-sheet', 'heading' => 'At a glance', 'fields' => [['field' => 'custom_fields.level'], ['field' => 'custom_fields.hours'], ['field' => 'custom_fields.gone']]],
    ['type' => 'content'],
    ['type' => 'entry-blocks'],
], $withBlocks);
$html = $page['html'];
check('the title area, the fact sheet, the text and the entry\'s own blocks, in the order of the template', [strpos($html, 'single-hero') < strpos($html, 'At a glance'), strpos($html, 'At a glance') < strpos($html, 'Body text'), strpos($html, 'Body text') < strpos($html, 'Its own quote')], [true, true, true]);
check('the title area draws the title of the entry', str_contains($html, '<h1>Intro course</h1>'), true);
check('the fact sheet shows the fields chosen, in that order, and skips one the type does not have', [strpos($html, 'Level') < strpos($html, 'Hours'), str_contains($html, 'Beginner level'), str_contains($html, 'gone')], [true, true, false]);
check('the text is placed once, by the template', substr_count($html, 'Body text'), 1);
check('what the entry\'s own blocks need joins the page: their types, styles and structured data', [in_array('quote', $page['types'], true), in_array('faq', $page['types'], true), in_array('faq', $page['asset_types'], true), $page['structured_data'][0]['@type'] ?? ''], [true, true, true, 'FAQPage']);
check('the first block is the title area, for the header', $page['lead']['type'], 'entry-title');
$page = $draw([['type' => 'fact-sheet']], $withBlocks);
check('a fact sheet with no fields chosen shows the fields the type shows', [str_contains($page['html'], 'Hours'), str_contains($page['html'], 'Level')], [true, true]);
$page = $draw([['type' => 'entry-blocks']], $course([]));
check('an entry with no blocks of its own leaves nothing', $page['html'], '');
$page = $draw([['type' => 'entry-blocks']], $withBlocks);
check('without an Entry text block the entry\'s own blocks may place the text', str_contains($page['html'], 'Body text'), true);
$page = $draw([['type' => 'text', 'heading' => 'Only this']], $withBlocks);
check('and a template with no Entry text does not show the text', str_contains($page['html'], 'Body text'), false);

// A field added to the type later is available at once: a new file for the same type.
file_put_contents("$root/custom/content-types/education.yaml", file_get_contents("$root/custom/content-types/education.yaml") . "\n  teacher:\n    type: text\n    label: Teacher\n");
$types2 = new ContentTypes($theme);
check('a field added to the type later is offered, and its binding works', [in_array('custom_fields.teacher', array_column(EntryBindings::sources($types2, 'education', 'en', 'en'), 'path'), true), (new EntryBindings($course(['teacher' => 'Dr. Who']), $types2, 'en', 'en', $fmt, $labels))->value('custom_fields.teacher', 'text')], [true, 'Dr. Who']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
