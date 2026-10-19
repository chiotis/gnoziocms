<?php
/*
 * Archive templates built with blocks (Archive Layouts > Edit template), without a request: how a template is stored and chosen
 * (ArchiveTemplates), which list block asks for the size of a page and the order, the starting templates (a file of data for the
 * theme's types, generated for any other, the site's own file first), what the screen shows and stores (ArchiveTemplateAdmin: the
 * page and the card, a content type that exists only as a YAML file, a taxonomy), the blocks that only an archive template offers,
 * and which template draws a list (TemplateResolver). Nothing here knows a content type: they are files created during the test.
 *   php tests/unit/archive-templates.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{ArchivePages, ArchiveTemplateAdmin, ArchiveTemplates, BlockRegistry, BlockSets, ContentTypes, HtmlGuard, SingleLayouts, StartingArchives, Taxonomies, TemplateResolver, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;

$root = sys_get_temp_dir() . '/arct' . getmypid();
mkdir("$root/custom/content-types", 0775, true);
mkdir("$root/custom/archive-templates", 0775, true);
mkdir("$root/content/taxonomies", 0775, true);
symlink($repo . '/themes', "$root/themes");
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// A type that is only a file of data.
file_put_contents("$root/custom/content-types/recipes.yaml", "label: { en: Recipes }\nfields:\n  minutes: { type: number, label: Minutes }\n  chef: { type: text, label: Chef }\n  cover: { type: image, label: Cover }\narchive:\n  layout: list\n  columns: '2'\n  per_page: 6\n  order: field:minutes:asc\n  breadcrumbs: true\n");

// ---- keys, choice, storage
check('a list is a content type or taxonomy:<name>, nothing else', array_map([ArchiveTemplates::class, 'validKey'], ['books', 'recipes', 'taxonomy:categories', 'Bad', 'a/b', 'taxonomy:', 'x:y']), [true, true, true, false, false, false, false]);
check('a taxonomy has its key', ArchiveTemplates::forTaxonomy('tags'), 'taxonomy:tags');
$page = ['default' => [['type' => 'archive-title'], ['type' => 'entry-list', 'per_page' => 5, 'order' => 'title_asc', 'columns' => '4']]];
$card = ['default' => [['type' => 'text', 'heading' => ['bind' => 'title']]]];
$s = ['x' => 1];
$s[ArchiveTemplates::KEY] = ArchiveTemplates::with($s, 'recipes', true, $page, $card);
check('a template keeps its switch, its page and its card', [ArchiveTemplates::entry($s, 'recipes')['use'], count(ArchiveTemplates::blocks($s, 'recipes', 'en', 'en')), count(ArchiveTemplates::card($s, 'recipes', 'en', 'en'))], [true, 2, 1]);
check('it applies in a language that has blocks, and when it is on', [ArchiveTemplates::applies($s, 'recipes', 'en', 'en'), ArchiveTemplates::applies($s, 'books', 'en', 'en')], [true, false]);
$off = [ArchiveTemplates::KEY => ArchiveTemplates::with($s, 'recipes', false, null)];
check('switched off, the blocks stay and the list is the theme\'s', [ArchiveTemplates::applies($off, 'recipes', 'en', 'en'), count(ArchiveTemplates::blocks($off, 'recipes', 'en', 'en'))], [false, 2]);
check('a list left with nothing is dropped', ArchiveTemplates::with([ArchiveTemplates::KEY => ['recipes' => ['use' => false, 'blocks' => [], 'card' => []]]], 'recipes', false, [], []), []);
check('an invalid key stores nothing', ArchiveTemplates::with([], 'Bad Key', true, $page), []);
check('a card with no blocks is not stored', array_keys(ArchiveTemplates::with([], 'recipes', true, $page, [])['recipes']), ['use', 'blocks']);
$starts = fn(string $key): array => ['page' => $page, 'card' => $card];
$chosen = ArchiveTemplates::withChoices([], ['recipes' => 'built', 'taxonomy:tags' => 'built', 'nothing' => 'built'], ['recipes', 'taxonomy:tags'], $starts);
check('choosing "built" for a list with no blocks gives it its starting template; a list that is not there is ignored', [array_keys($chosen[ArchiveTemplates::KEY]), $chosen[ArchiveTemplates::KEY]['recipes']['use'], isset($chosen[ArchiveTemplates::KEY]['recipes']['card'])], [['recipes', 'taxonomy:tags'], true, true]);
$back = ArchiveTemplates::withChoices($chosen, ['recipes' => 'theme'], ['recipes'], $starts);
check('choosing the theme\'s keeps the blocks', [$back[ArchiveTemplates::KEY]['recipes']['use'], count($back[ArchiveTemplates::KEY]['recipes']['blocks'])], [false, 2]);
check('and nothing chosen leaves the key out of the settings', array_key_exists(ArchiveTemplates::KEY, ArchiveTemplates::withChoices([], ['recipes' => 'theme'], ['recipes'], $starts)), false);

// ---- the list block asks for a page size, an order, columns
$blocks = [['type' => 'archive-title'], ['type' => 'container', 'columns' => [['blocks' => [['type' => 'text'], ['type' => 'entry-list', 'per_page' => 5, 'order' => 'title_asc', 'columns' => '4']]]]]];
$list = ArchiveTemplates::listBlock($blocks);
check('the list block is found also inside a column of a container', $list['per_page'] ?? null, 5);
check('a hidden list block is not the list', ArchiveTemplates::listBlock([['type' => 'entry-list', 'hidden' => true]]), null);
$orders = ['date_desc' => 'N', 'title_asc' => 'A', 'field:minutes:asc' => 'M'];
check('what it chose replaces the archive\'s own', ArchiveTemplates::withListChoices(['per_page' => 12, 'order' => 'date_desc', 'columns' => '3', 'layout' => 'cards'], $list, $orders), ['per_page' => 5, 'order' => 'title_asc', 'columns' => '4', 'layout' => 'cards', 'sort_choices' => []]);
check('an order the list does not know, a size out of range and a map layout are not taken', ArchiveTemplates::withListChoices(['per_page' => 12, 'order' => 'date_desc', 'layout' => 'map'], ['per_page' => 900, 'order' => 'field:nope:asc', 'columns' => '9'], $orders), ['per_page' => 60, 'order' => 'date_desc', 'layout' => 'cards', 'sort_choices' => []]);
$letting = ['visitor_sort' => true, 'sort_choices' => [['order' => 'title_asc'], ['order' => 'nope'], ['order' => 'field:minutes:asc']]];
check('a list block that lets visitors choose gives the orders it names, in its order, and not one the list does not know', ArchiveTemplates::withListChoices(['order' => 'date_desc'], $letting, $orders)['sort_choices'], ['title_asc' => 'A', 'field:minutes:asc' => 'M']);
check('with none named every order but the random one is offered; without the choice none is', [array_keys(ArchiveTemplates::withListChoices([], ['visitor_sort' => true], $orders + ['random' => 'R'])['sort_choices']), ArchiveTemplates::withListChoices([], ['visitor_sort' => false, 'sort_choices' => [['order' => 'title_asc']]], $orders)['sort_choices']], [['date_desc', 'title_asc', 'field:minutes:asc'], []]);
check('without a list block nothing changes', ArchiveTemplates::withListChoices(['per_page' => 12], null, $orders), ['per_page' => 12]);

// ---- starting templates
$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$registry = new BlockRegistry($theme);
$taxonomies = new Taxonomies("$root/content", ['en']);
$taxonomies->save('categories', 'Categories', [['id' => 'news', 'slug' => 'news', 'labels' => ['en' => 'News'], 'descriptions' => []]]);
$start = new StartingArchives($theme, $registry, $types, $taxonomies, fn() => 'en');
$generated = $start->sets('recipes');
$types_of = fn(array $sets): array => array_column($sets['default'] ?? [], 'type');
check('a type nobody wrote a template for starts from a generated one: title, filters, count, the list, and a card', [$types_of($generated['page']), $types_of($generated['card']), $start->hasFile('recipes')], [['archive-title', 'filter-bar', 'result-count', 'entry-list'], ['image', 'text'], false]);
$listBlock = $generated['page']['default'][3];
check('the list keeps the layout, columns, size and order the type had', [$listBlock['variant'], $listBlock['columns'], $listBlock['per_page'], $listBlock['order']], ['list', '2', 6, 'field:minutes:asc']);
check('the card shows the main image, the title and the excerpt of any entry', [$generated['card']['default'][0]['image'], $generated['card']['default'][1]['heading'], $generated['card']['default'][1]['body']], [['bind' => 'main_image'], ['bind' => 'title'], ['bind' => 'excerpt']]);
$books = $start->sets('books');
check('the theme ships a hand-made one for its own types (a book\'s card has its author)', [$start->hasFile('books'), $books['card']['default'][1]['eyebrow'], $books['page']['default'][3]['columns']], [true, ['bind' => 'custom_fields.author'], '4']);
file_put_contents("$root/custom/archive-templates/recipes.yaml", "blocks:\n  - { type: entry-list, per_page: 3 }\n  - { type: no-such-block }\ncard:\n  - { type: text, heading: { bind: title } }\n");
$mine = $start->sets('recipes');
check('the site\'s own file wins, and a block the theme does not have is left out', [$types_of($mine['page']), $types_of($mine['card']), $start->hasFile('recipes')], [['entry-list'], ['text'], true]);
file_put_contents("$root/custom/archive-templates/taxonomy-categories.yaml", "blocks: not a list\n");
check('a taxonomy has its own file name, and a file that is not usable gives way to the generated template', $types_of($start->sets('taxonomy:categories')['page']), ['archive-title', 'filter-bar', 'result-count', 'entry-list']);

// ---- the screen
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$guard = new HtmlGuard(fn() => $environment, fn() => $registry);
$settings = ['base_url' => 'https://s.test/', 'languages' => ['default' => 'en', 'available' => ['en']]];
$sets = new BlockSets(fn() => $registry, fn() => $guard, fn() => true, fn(string $lang): array => [], fn() => $settings);
$themeSettings = [];
$resolver = new TemplateResolver($theme, new SingleLayouts($theme), function () use (&$themeSettings): array { return $themeSettings; }, fn() => 'en');
$pages = new ArchivePages($resolver, fn() => throw new \LogicException('no runtime here'), $types, fn() => 'en');
$admin = new ArchiveTemplateAdmin($theme, $types, $registry, $sets, $start, $pages, $taxonomies, fn() => ['pages', 'recipes', 'forms'], fn() => 'en');
check('a type of the site and a taxonomy can have a template; pages, forms and what is not there cannot', [[$admin->keys()[0], count($admin->keys())], $admin->available('recipes'), $admin->available('taxonomy:categories'), $admin->available('pages'), $admin->available('forms'), $admin->available('nothing'), $admin->available('taxonomy:nothing')], [['recipes', 3], true, true, false, false, false, false]);
check('a list is named as the site names it', [$admin->label('recipes'), $admin->label('taxonomy:categories')], ['Recipes', 'Categories']);
$view = $admin->view('recipes', [], []);
$json = json_decode($view['template_json'], true);
$defs = array_column($json['definitions'], null, 'type');
check('the page part starts from the starting template, with the archive blocks offered and no entry blocks', [$view['part'], $view['template_starts'], $view['template_use'], isset($defs['entry-list']), isset($defs['archive-title']), isset($defs['fact-sheet']), isset($json['bindings'])], ['page', true, true, true, true, false, false]);
check('the order of the list is a choice among the orders of the type, sorting by a field included', array_keys($defs['entry-list']['fields'][2]['options'] ?? []) !== [] ? array_column($defs['entry-list']['fields'][2]['options'], 0) : $defs['entry-list']['fields'][2], ['date_desc', 'date_asc', 'title_asc', 'title_desc', 'random', 'field:minutes:asc', 'field:minutes:desc', 'field:chef:asc', 'field:chef:desc']);
$cardView = $admin->view('recipes', [], ['part' => 'card']);
$cardJson = json_decode($cardView['template_json'], true);
$cardDefs = array_column($cardJson['definitions'], null, 'type');
check('the card part shows the fields of the type to bind and none of the blocks of a list or of a page of an entry', [$cardView['part'], in_array('custom_fields.minutes', array_column($cardJson['bindings']['sources'], 'path'), true), isset($cardDefs['entry-list']), isset($cardDefs['entry-title']), isset($cardDefs['text'])], ['card', true, false, false, true]);
$taxCard = json_decode($admin->view('taxonomy:categories', [], ['part' => 'card'])['template_json'], true);
check('a card on the page of a term offers what every entry has, not the fields of one type', array_column($taxCard['bindings']['sources'], 'path'), ['title', 'excerpt', 'main_image', 'date', 'author', 'categories', 'tags']);
check('a taxonomy page can be previewed with a term', [array_column($admin->view('taxonomy:categories', [], [])['preview_choices'], 'value'), $admin->view('taxonomy:categories', [], ['term' => 'news'])['preview_chosen'], $admin->view('recipes', [], [])['preview_choices']], [['news'], 'news', []]);

// ---- saving and the preview
$post = ['blocks_editor' => '1', 'blocks_json' => json_encode([['type' => 'text', 'heading' => ['bind' => 'title']]]), 'use' => '1', 'part' => 'card'];
$saved = $admin->saved('recipes', ['x' => 1], $post);
check('saving a card before the page also stores the starting page, so the list is drawn from the template', [ArchiveTemplates::entry($saved, 'recipes')['use'], count(ArchiveTemplates::card($saved, 'recipes', 'en', 'en')), count(ArchiveTemplates::blocks($saved, 'recipes', 'en', 'en')), $saved['x']], [true, 1, 1, 1]);
$page2 = $admin->saved('recipes', $saved, ['blocks_editor' => '1', 'blocks_json' => json_encode([['type' => 'entry-list']]), 'part' => 'page']);
check('saving the page leaves the card, and the switch not sent is off', [count(ArchiveTemplates::blocks($page2, 'recipes', 'en', 'en')), count(ArchiveTemplates::card($page2, 'recipes', 'en', 'en')), ArchiveTemplates::entry($page2, 'recipes')['use']], [1, 1, false]);
check('a form without editor data keeps the blocks', count(ArchiveTemplates::blocks($admin->saved('recipes', $saved, ['part' => 'page', 'use' => '1']), 'recipes', 'en', 'en')), 1);
$again = $admin->startedAgain('recipes', 'card', $page2);
check('start again replaces the part shown and turns the template on, the other part stays', [count(ArchiveTemplates::card($again, 'recipes', 'en', 'en')), count(ArchiveTemplates::blocks($again, 'recipes', 'en', 'en')), ArchiveTemplates::entry($again, 'recipes')['use']], [1, 1, true]);
$prev = $admin->preview('recipes', $saved, ['part' => 'card', 'blocks_editor' => '1', 'blocks_json' => json_encode([['type' => 'image']])]);
check('the preview draws the list at its address with the unsaved card, switched on', [$prev['path'], $prev['part'], ArchiveTemplates::entry($prev['settings'], 'recipes')['use'], ArchiveTemplates::card($prev['settings'], 'recipes', 'en', 'en')[0]['type']], ['recipes', 'card', true, 'image']);
check('a taxonomy preview needs a term and draws its page', [$admin->preview('taxonomy:categories', [], ['preview_term' => 'nope']), $admin->preview('taxonomy:categories', [], ['preview_term' => 'news', 'part' => 'page'])['path']], [null, 'category/news']);

// ---- which template draws a list
check('a list that is not built is drawn by the theme\'s archive template', [$resolver->forArchive('recipes', 'en'), $resolver->builtArchive('recipes', 'en')], ['templates/archive.twig', null]);
$themeSettings = $saved;
check('a built list is drawn by the built template, with its blocks and card', [$resolver->forArchive('recipes', 'en'), count($resolver->builtArchive('recipes', 'en')['blocks']), count($resolver->builtArchive('recipes', 'en')['card'])], ['templates/archive-built.twig', 1, 1]);
check('without a language the resolver gives the theme\'s, as it always did', $resolver->forArchive('recipes'), 'templates/archive.twig');
check('forms and pages never take a template', [$resolver->builtArchive('forms', 'en'), $resolver->builtArchive('pages', 'en')], [null, null]);
$themeSettings = [ArchiveTemplates::KEY => ArchiveTemplates::with([], 'taxonomy:categories', true, $page)];
check('a taxonomy is drawn by the built template too', [$resolver->forTaxonomy('category', 'news', 'categories', 'en'), $resolver->forTaxonomy('tag', 'x', 'tags', 'en'), $resolver->forTaxonomy('category', 'news')], ['templates/archive-built.twig', 'templates/archive.twig', 'templates/archive.twig']);
check('the settings the list block chose are applied while the list is built', [$pages->settings('recipes', 'en', ['per_page' => 12, 'order' => 'date_desc'])['per_page'], $pages->settings('taxonomy:categories', 'en', ['per_page' => 12, 'order' => 'date_desc', 'columns' => '3'])['per_page']], [12, 5]);

// ---- what a request comes to (the caller only carries it out)
check('an unknown list goes back to the cards', $admin->respond('nope', false, [], [], [], true), ['redirect' => '/admin/theme?tab=archive_layouts']);
check('a GET shows the screen', array_keys($admin->respond('recipes', false, ['part' => 'card'], [], [], true)), ['render']);
check('a preview request asks for a preview', array_keys($admin->respond('recipes', true, ['preview' => '1'], ['part' => 'page'], [], true)), ['preview']);
$out = $admin->respond('Recipes', true, [], ['part' => 'card', 'use' => '1', 'blocks_editor' => '1', 'blocks_json' => json_encode([['type' => 'text']]), 'preview_term' => 'a b'], [], true);
check('saving stores the settings, says what for, and goes back to the part with a message', [isset($out['save'][ArchiveTemplates::KEY]['recipes']), $out['log'], $out['redirect']], [true, 'Archive template of recipes updated.', '/admin/theme?archive_template=recipes&part=card&saved=1&term=a+b']);
check('without a database nothing is stored', $admin->respond('recipes', true, [], ['part' => 'page'], [], false), ['redirect' => '/admin/theme?archive_template=recipes&part=page&fail=1']);
check('start again says so in the log', $admin->respond('recipes', true, [], ['part' => 'page', 'start_again' => '1'], [], true)['log'], 'Archive template of recipes started again from the default.');
check('the preview JSON of nothing is the note, of a list its page and address', [json_decode($admin->previewJson(null, fn() => ''), true)['html'] === ArchiveTemplateAdmin::NOTHING, json_decode($admin->previewJson(['path' => 'recipes', 'part' => 'card', 'card_type' => 'x', 'settings' => ['a' => 1]], fn(array $s, string $p, string $part, string $t) => $part . ':' . $p . ':' . $s['a'] . ':' . $t), true)], [true, ['html' => 'card:recipes:1:x', 'path' => '/recipes']]);
check('a list with no template gets its view data back unchanged', $pages->drawn('recipes', 'en', 'recipes', new \Twig\Environment(new \Twig\Loader\ArrayLoader([])), ['x' => 1]), ['x' => 1]);
check('the part being previewed is known while drawing, and forgotten after', [$pages->previewing('card', 'recipes', fn() => 'drawn'), $pages->previewing('page', '', fn() => 1)], ['drawn', 1]);

// ---- a card for each content type, on a list of several
$settingsCards = [ArchiveTemplates::KEY => ArchiveTemplates::with([], 'taxonomy:categories', true, $page, $card)];
$settingsCards[ArchiveTemplates::KEY] = ArchiveTemplates::withTypeCard($settingsCards, 'taxonomy:categories', 'recipes', ['default' => [['type' => 'image']]]);
check('a list keeps a card for a content type of its own, besides the card for the others', [array_keys(ArchiveTemplates::cards($settingsCards, 'taxonomy:categories', 'en', 'en')), count(ArchiveTemplates::card($settingsCards, 'taxonomy:categories', 'en', 'en'))], [['recipes'], 1]);
$settingsCards[ArchiveTemplates::KEY] = ArchiveTemplates::with($settingsCards, 'taxonomy:categories', true, null, null);
check('saving the page or the card again keeps the cards of the types', array_keys(ArchiveTemplates::entry($settingsCards, 'taxonomy:categories')['card_types']), ['recipes']);
$gone = ArchiveTemplates::withTypeCard($settingsCards, 'taxonomy:categories', 'recipes', []);
check('an empty card takes the card of a type away, and a name that is not a type is not kept', [isset($gone['taxonomy:categories']['card_types']), ArchiveTemplates::withTypeCard($settingsCards, 'taxonomy:categories', 'Bad Type', ['default' => [['type' => 'x']]]) === $settingsCards[ArchiveTemplates::KEY]], [false, true]);
$onlyCards = [ArchiveTemplates::KEY => ArchiveTemplates::withTypeCard([], 'recipes', 'works', ['default' => [['type' => 'text']]])];
check('a list that has only cards of types is not dropped when switched off', isset(ArchiveTemplates::with($onlyCards, 'recipes', false, null, null)['recipes']), true);
$themeSettings = $settingsCards;
check('the resolver gives the cards of the types', array_keys($resolver->builtArchive('taxonomy:categories', 'en')['cards']), ['recipes']);
$cview = $admin->view('taxonomy:categories', [], ['part' => 'card', 'card_type' => 'recipes']);
$cjson = json_decode($cview['template_json'], true);
check('the card of a type is edited with the fields of that type, starting from the card of its own list; the page of a term offers a card for each type', [$cview['card_type'], $cjson['bindings']['type'], in_array('custom_fields.minutes', array_column($cjson['bindings']['sources'], 'path'), true), $cview['template_starts'], array_column($cview['card_types'], 'value')], ['recipes', 'recipes', true, true, ['recipes']]);
check('a content type that is not listed, a list of one type, and the page part have no card type', [$admin->view('taxonomy:categories', [], ['part' => 'card', 'card_type' => 'nope'])['card_type'], $admin->view('recipes', [], ['part' => 'card', 'card_type' => 'recipes'])['card_type'], $admin->view('taxonomy:categories', [], ['card_type' => 'recipes'])['card_type']], ['', '', '']);
$typed = $admin->saved('taxonomy:categories', [], ['part' => 'card', 'card_type' => 'recipes', 'use' => '1', 'blocks_editor' => '1', 'blocks_json' => json_encode([['type' => 'text', 'heading' => ['bind' => 'title']]])]);
check('saving the card of a type stores it apart from the card of the others, and the page starts from the default', [array_keys(ArchiveTemplates::entry($typed, 'taxonomy:categories')['card_types']), ArchiveTemplates::card($typed, 'taxonomy:categories', 'en', 'en'), count(ArchiveTemplates::blocks($typed, 'taxonomy:categories', 'en', 'en')) > 0], [['recipes'], [], true]);
check('start again for the card of a type puts back the starting card of that type only', [count(ArchiveTemplates::cards($admin->startedAgain('taxonomy:categories', 'card', $typed, 'recipes'), 'taxonomy:categories', 'en', 'en')['recipes'] ?? []), array_keys(ArchiveTemplates::cards($admin->startedAgain('taxonomy:categories', 'card', $typed, 'nope'), 'taxonomy:categories', 'en', 'en'))], [1, ['recipes']]);
$ptype = $admin->preview('taxonomy:categories', $typed, ['part' => 'card', 'card_type' => 'recipes', 'preview_term' => 'news', 'blocks_editor' => '1', 'blocks_json' => json_encode([['type' => 'image']])]);
check('the preview of the card of a type draws that card, unsaved, and says which type', [$ptype['card_type'], ArchiveTemplates::cards($ptype['settings'], 'taxonomy:categories', 'en', 'en')['recipes'][0]['type']], ['recipes', 'image']);
$redir = $admin->respond('taxonomy:categories', true, [], ['part' => 'card', 'card_type' => 'recipes', 'blocks_editor' => '1', 'blocks_json' => '[]'], [], true)['redirect'];
check('saving goes back to the same card', $redir, '/admin/theme?archive_template=taxonomy%3Acategories&part=card&card_type=recipes&saved=1');
$mapSets = (new StartingArchives($theme, $registry, $types, $taxonomies, fn() => 'en'));
check('a list that was a map starts with a map of the list, where it was', array_column(StartingArchives::generate(['layout' => 'map', 'map_list' => 'left', 'map_height' => 'tall']), 'type'), ['archive-title', 'filter-bar', 'result-count', 'entry-map', 'entry-list']);
check('the map keeps the height and the place of its list', array_slice(StartingArchives::generate(['layout' => 'map', 'map_list' => 'left', 'map_height' => 'tall'])[3], 1), ['list' => 'left', 'height' => 'tall']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
