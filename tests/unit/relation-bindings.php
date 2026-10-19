<?php
/*
 * Templates built with blocks, relationships: a block shows a field of the entry a relation points at (`custom_fields.author.title`), a
 * list of such entries (a relation that takes several), or the entries that point at this one (`referenced_by.works.author`), with
 * the column mapping of list bindings; and the fact sheet lists what points here when the relation says `reverse_label`. Types are
 * files created during the test: no code knows about them.
 *   php tests/unit/relation-bindings.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{BlockRegistry, ContentIndex, ContentItem, ContentRepository, ContentTypes, EntryBindings, FieldSchema, Relations, SystemDatabase, SystemMetaRepository, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$root = sys_get_temp_dir() . '/relbind' . getmypid();
mkdir($root . '/custom/content-types', 0775, true);
mkdir($root . '/storage', 0775, true);
foreach (['authors', 'works'] as $dir) {
    mkdir($root . '/content/' . $dir, 0775, true);
}
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
file_put_contents($root . '/custom/content-types/authors.yaml', "label: Authors\nfields:\n  country: {type: text, label: Country}\n  bio: {type: textarea, label: Bio}\n");
file_put_contents($root . '/custom/content-types/works.yaml', <<<'YAML'
label: Works
fields:
  author: {type: relation, types: [authors], label: Author, reverse_label: Books}
  team: {type: relation, types: [authors], multiple: true, label: Team}
  anyone: {type: relation, label: Anyone}
  year: {type: number, label: Year}
YAML);
$put = static function (string $path, string $front) use ($root): void {
    file_put_contents($root . '/content/' . $path, "---\n" . $front . "\n---\n\nbody\n");
};
$put('authors/ada.md', "title: Ada\nstatus: published\ntranslation_id: aaaaaaaa00000001\nexcerpt: Maths\nmain_image: /uploads/ada.jpg\ndate: 2000-05-06\ncustom_fields:\n  country: England\n  bio: 'Wrote the first program.'");
$put('authors/bob.md', "title: Bob\nstatus: published\ntranslation_id: bbbbbbbb00000002\ncustom_fields:\n  country: Wales");
$put('authors/cy.md', "title: Cy\nstatus: draft\ntranslation_id: cccccccc00000003");
$put('works/w1.md', "title: First\nstatus: published\ndate: 2020-01-01\ntranslation_id: 1111111100000001\nexcerpt: The first\ncustom_fields:\n  author: aaaaaaaa00000001\n  team: [aaaaaaaa00000001, cccccccc00000003, bbbbbbbb00000002]\n  anyone: bbbbbbbb00000002\n  year: 1843");
$put('works/w2.md', "title: Second\nstatus: published\ndate: 2022-01-01\ntranslation_id: 2222222200000002\nexcerpt: The second\ncustom_fields:\n  author: aaaaaaaa00000001\n  year: 1850");
$put('works/w3.md', "title: Draft work\nstatus: draft\ntranslation_id: 3333333300000003\ncustom_fields:\n  author: aaaaaaaa00000001");

$settings = ['languages' => ['default' => 'el', 'available' => ['el']]];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository($root . '/content', new MarkdownConverter($environment), $settings);
$database = new SystemDatabase($root . '/storage');
$database->initialize();
$theme = new Theme($root, 'default');
$types = new ContentTypes($theme);
$index = new ContentIndex($database, $root . '/content', new SystemMetaRepository($database));
$index->keepReferences(fn(string $type): array => $types->definition($type, 'en', 'el')['fields']);
$index->rebuild($content, ['authors', 'works']);
$relations = new Relations($index, $content, $types, $settings, $root . '/content');
$types->showRelationsWith(fn(array $ids, array $allowed, string $lang): array => $relations->resolve($ids, $allowed, $lang));
$types->showReferencesWith(fn(string $id, string $type, string $path, string $lang): array => $relations->referencedById($id, $type, $path, $lang));
$registry = new BlockRegistry($theme, ['content_types' => fn() => ['works' => 'Works'], 'forms' => fn() => ['' => '—']]);
$fmt = static fn(string $d): string => 'D:' . $d;
$labels = static fn(string $tax, string $id): string => $id;
$bind = static fn(ContentItem $item, string $lang = 'el'): EntryBindings => new EntryBindings($item, $types, $lang, 'el', $fmt, $labels, $relations);
$w1 = $content->find('works', 'w1', 'el', true);
$ada = $content->find('authors', 'ada', 'el', true);

// ---- what a type offers
$sources = EntryBindings::sources($types, 'works', 'en', 'el');
$byPath = array_column($sources, null, 'path');
check('a relation to one entry offers its card and the fields of the type it names, as paths with dots', array_keys(array_filter($byPath, fn($s) => str_starts_with($s['path'], 'custom_fields.author.'))), [
    'custom_fields.author.title', 'custom_fields.author.excerpt', 'custom_fields.author.main_image', 'custom_fields.author.date', 'custom_fields.author.url', 'custom_fields.author.custom_fields.country', 'custom_fields.author.custom_fields.bio',
]);
check('the kinds are the kinds the table knows, so the picker offers each where it fits', array_column(array_filter($byPath, fn($s) => str_starts_with($s['path'], 'custom_fields.author.')), 'kind'), ['text', 'textarea', 'image', 'date', 'link', 'text', 'textarea']);
check('a relation that may point at any type offers the card only', array_keys(array_filter($byPath, fn($s) => str_starts_with($s['path'], 'custom_fields.anyone.'))), ['custom_fields.anyone.title', 'custom_fields.anyone.excerpt', 'custom_fields.anyone.main_image', 'custom_fields.anyone.date', 'custom_fields.anyone.url']);
check('a relation to several is a list with the columns of the card and of the type', [$byPath['custom_fields.team']['kind'], array_column($byPath['custom_fields.team']['columns'], 'key')], ['list', ['title', 'excerpt', 'main_image', 'date', 'url', 'field_country', 'field_bio']]);
check('and offers all its titles as a text', [$byPath['custom_fields.team.title']['kind'] ?? null, isset($byPath['custom_fields.team.excerpt'])], ['text', false]);
$authors = EntryBindings::sources($types, 'authors', 'en', 'el');
$back = array_column($authors, null, 'path');
check('the type that is pointed at offers the entries that point at it, as lists', array_keys(array_filter($back, fn($s) => str_starts_with($s['path'], 'referenced_by.'))), ['referenced_by.works.author', 'referenced_by.works.team', 'referenced_by.works.anyone']);
check('with the columns of the type that points', array_column($back['referenced_by.works.author']['columns'], 'key'), ['title', 'excerpt', 'main_image', 'date', 'url', 'field_year']);
check('a type with no relation to it offers nothing of the kind', array_filter(EntryBindings::sources($types, 'works', 'en', 'el'), fn($s) => str_starts_with($s['path'], 'referenced_by.') && !str_contains($s['path'], 'anyone')), []);

// ---- one field of the entry pointed at
check('the title, the excerpt, the picture, the date and the address of the entry pointed at', [
    $bind($w1)->value('custom_fields.author.title', 'text'), $bind($w1)->value('custom_fields.author.excerpt', 'textarea'), $bind($w1)->value('custom_fields.author.main_image', 'image'),
    $bind($w1)->value('custom_fields.author.date', 'text'), $bind($w1)->value('custom_fields.author.url', 'link'),
], ['Ada', 'Maths', '/uploads/ada.jpg', 'D:2000-05-06', '/authors/ada']);
check('a field the type of that entry declares', [$bind($w1)->value('custom_fields.author.custom_fields.country', 'text'), $bind($w1)->value('custom_fields.author.custom_fields.bio', 'textarea')], ['England', 'Wrote the first program.']);
check('what does not fit the block\'s field, or leads nowhere, is null (shown as a missing field)', [
    $bind($w1)->value('custom_fields.author.main_image', 'text'), $bind($w1)->value('custom_fields.author.nothing', 'text'), $bind($w1)->value('custom_fields.year.title', 'text'),
    $bind($w1)->value('custom_fields.anyone.custom_fields.country', 'text'), $bind($w1)->value('custom_fields.author.custom_fields.gone', 'text'),
], [null, null, null, null, null]);
check('all the titles of a relation to several, a draft left out', $bind($w1)->value('custom_fields.team.title', 'text'), 'Ada, Bob');
check('an entry with no author gives nothing, not an error', $bind($content->find('works', 'w2', 'el', true))->value('custom_fields.team.title', 'text') === '' && $bind($content->parseText('works', 'x', 'el', "---\ntitle: X\n---\n"))->value('custom_fields.author.title', 'text') === '', true);
$put('authors/ada.md', "title: Ada\nstatus: draft\ntranslation_id: aaaaaaaa00000001");
$content->forget();
$index->upsert($content->parseFile('authors', $root . '/content/authors/ada.md'));
check('an author who is a draft is not shown by a binding', $bind($w1)->value('custom_fields.author.title', 'text'), '');
$put('authors/ada.md', "title: Ada\nstatus: published\ntranslation_id: aaaaaaaa00000001\nexcerpt: Maths\nmain_image: /uploads/ada.jpg\ndate: 2000-05-06\ncustom_fields:\n  country: England\n  bio: 'Wrote the first program.'");
$content->forget();
$index->upsert($content->parseFile('authors', $root . '/content/authors/ada.md'));

// ---- in a block
$cta = $registry->get('cta')['fields'];
$out = $bind($w1)->apply($cta, ['type' => 'cta', 'heading' => ['bind' => 'custom_fields.author.title'], 'text' => ['bind' => 'custom_fields.author.custom_fields.bio']]);
check('a block shows the field of the entry pointed at, cleaned like typed text', [$out['raw']['heading'], $out['raw']['text'], $out['hidden'], $out['missing']], ['Ada', 'Wrote the first program.', false, []]);
$out = $bind($w1)->apply($cta, ['heading' => ['bind' => 'custom_fields.author.nothing']]);
check('a path that leads nowhere is missing, never an error', [$out['missing'], isset($out['raw']['heading'])], [['custom_fields.author.nothing'], false]);

// ---- a list of entries in a cards block
$cards = $registry->get('cards')['fields'];
$map = ['title' => 'title', 'text' => 'excerpt', 'image' => 'main_image', 'url' => 'url', 'meta' => 'field_country'];
$rows = FieldSchema::resolve($cards, $bind($w1)->apply($cards, ['type' => 'cards', 'items' => ['bind' => 'custom_fields.team', 'map' => $map]])['raw'])['items'];
check('a relation to several is a list of cards, in the order chosen, a draft left out', array_map(fn($r) => [$r['title'], $r['text'], $r['image'], $r['url'], $r['meta']], $rows), [['Ada', 'Maths', '/uploads/ada.jpg', '/authors/ada', 'England'], ['Bob', '', '', '/authors/bob', 'Wales']]);
$rows = FieldSchema::resolve($cards, $bind($ada)->apply($cards, ['items' => ['bind' => 'referenced_by.works.author', 'map' => ['title' => 'title', 'url' => 'url', 'meta' => 'field_year', 'text' => 'excerpt']]])['raw'])['items'];
check('the entries that point at this one are a list too, newest first, drafts left out', array_map(fn($r) => [$r['title'], $r['url'], $r['meta'], $r['text']], $rows), [['Second', '/works/w2', '1850', 'The second'], ['First', '/works/w1', '1843', 'The first']]);
$none = $bind($content->find('authors', 'bob', 'el', true))->apply($cards, ['items' => ['bind' => 'referenced_by.works.author', 'map' => ['title' => 'title']]]);
check('nobody pointing here is an empty list, and hides the block', [$none['raw']['items'], $none['hidden']], [[], true]);
check('a column of the type that is gone is missing, the others still work', (function () use ($bind, $ada, $cards) {
    $out = $bind($ada)->apply($cards, ['items' => ['bind' => 'referenced_by.works.author', 'map' => ['title' => 'title', 'meta' => 'field_gone']]]);
    return [array_column($out['raw']['items'], 'title'), $out['missing']];
})(), [['Second', 'First'], ['referenced_by.works.author.field_gone']]);
check('a column that does not fit is missing too', $bind($ada)->apply($cards, ['items' => ['bind' => 'referenced_by.works.author', 'map' => ['image' => 'title']]])['missing'], ['referenced_by.works.author.title']);
check('a list that is not there (another type, another field) leaves the list empty and is missing', [
    $bind($ada)->apply($cards, ['items' => ['bind' => 'referenced_by.works.year', 'map' => ['title' => 'title']]])['missing'],
    $bind($ada)->apply($cards, ['items' => ['bind' => 'referenced_by.nothing.author', 'map' => ['title' => 'title']]])['missing'],
], [['referenced_by.works.year'], ['referenced_by.nothing.author']]);
check('the block\'s own limit applies, as for typed rows', count(FieldSchema::resolve($registry->get('cta')['fields'], $bind($ada)->apply($registry->get('cta')['fields'], ['actions' => ['bind' => 'referenced_by.works.author', 'map' => ['label' => 'title', 'url' => 'url']]])['raw'])['actions']), 2);
check('a field of the type is read from its file only when a column asks for it (the card is enough otherwise)', array_column(FieldSchema::resolve($cards, $bind($w1)->apply($cards, ['items' => ['bind' => 'custom_fields.team', 'map' => ['title' => 'title']]])['raw'])['items'], 'title'), ['Ada', 'Bob']);

// ---- the fact sheet
$sheet = $types->display('authors', $ada->meta, 'page', $fmt, 'el', 'el');
$books = array_values(array_filter($sheet, fn($e) => $e['key'] === 'referenced_by.works.author'))[0] ?? null;
check('the fact sheet of the entry pointed at lists what points here when the relation has a reverse_label', [$books['label'] ?? null, array_column($books['items'] ?? [], 'title'), $books['type'] ?? null], ['Books', ['Second', 'First'], 'relation']);
check('a relation without a reverse_label adds nothing', array_filter($sheet, fn($e) => str_contains($e['key'], 'team')), []);
check('a card lists nothing of it, and an entry nobody points at has no such line', [array_filter($types->display('authors', $ada->meta, 'card', $fmt, 'el', 'el'), fn($e) => str_starts_with($e['key'], 'referenced_by')), array_filter($types->display('authors', $content->find('authors', 'bob', 'el', true)->meta, 'page', $fmt, 'el', 'el'), fn($e) => str_starts_with($e['key'], 'referenced_by'))], [[], []]);
$named = (new EntryBindings($w1, $types, 'el', 'el', $fmt, $labels, $relations))->factSheet(['custom_fields.author', 'custom_fields.team']);
check('a fact sheet that names a relation shows it as links, in the order named', array_map(fn($e) => [$e['key'], $e['display']], $named), [['author', 'Ada'], ['team', 'Ada, Bob']]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
