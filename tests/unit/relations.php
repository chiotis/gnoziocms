<?php
/*
 * Relations between entries (the `relation` field): the field checks its values, the content index keeps the ids and the
 * references, a reference survives a new address and follows the language, an entry that is hidden or gone is not shown, the
 * reverse question is one query, an entry without an id is given one when it is first pointed at, and a type that exists only as
 * a YAML file works with no code.
 *   php tests/unit/relations.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{ContentIndex, ContentRepository, ContentTypes, FieldSchema, RelationFields, Relations, SystemDatabase, SystemMetaRepository, Theme};
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

// ---- the field
$one = FieldSchema::normalize(['author' => ['type' => 'relation', 'types' => ['authors']]])['author'];
$many = FieldSchema::normalize(['team' => ['type' => 'relation', 'types' => ['authors', 'Bad Type!'], 'multiple' => true, 'max' => 2]])['team'];
check('a relation keeps the types it may point at, and drops what is not a type name', [$one['types'], $many['types']], [['authors'], ['authors']]);
check('a single relation holds one id', [FieldSchema::clean($one, 'aaaaaaaa00000001'), FieldSchema::clean($one, ['aaaaaaaa00000001', 'bbbbbbbb00000002'])], ['aaaaaaaa00000001', 'aaaaaaaa00000001']);
check('an id that could hold markup is not kept', [FieldSchema::clean($one, '<script>x</script>'), FieldSchema::clean($one, 'a/b'), FieldSchema::clean($one, 'short')], ['', '', '']);
check('a relation with several holds a list without repeats, up to max', FieldSchema::clean($many, ['aaaaaaaa00000001', 'aaaaaaaa00000001', 'bbbbbbbb00000002', 'cccccccc00000003']), ['aaaaaaaa00000001', 'bbbbbbbb00000002']);
check('nothing is an empty value of the right shape', [FieldSchema::clean($one, null), FieldSchema::clean($many, '')], ['', []]);

// ---- the site
$root = sys_get_temp_dir() . '/relations' . getmypid();
mkdir($root . '/custom/content-types', 0775, true);
mkdir($root . '/storage', 0775, true);
foreach (['authors', 'works'] as $dir) {
    mkdir($root . '/content/' . $dir, 0775, true);
}
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
file_put_contents($root . '/custom/content-types/works.yaml', <<<'YAML'
label: Works
fields:
  author:
    type: relation
    types: [authors]
    label: Author
  team:
    type: relation
    types: [authors]
    multiple: true
    max: 3
  editions:
    type: repeater
    item_label: Edition
    fields:
      earlier: { type: relation, types: [works] }
      note: { type: text }
YAML);
file_put_contents($root . '/custom/content-types/authors.yaml', "label: Authors\nfields:\n  country: { type: text }\n");

$put = static function (string $path, string $front, string $body = 'x') use ($root): void {
    file_put_contents($root . '/content/' . $path, "---\n" . $front . "\n---\n\n" . $body . "\n");
};
$put('authors/ada.md', "title: Ada\nstatus: published\ntranslation_id: aaaaaaaa00000001\nexcerpt: Maths");
$put('authors/ada.en.md', "title: Ada EN\nstatus: published\ntranslation_id: aaaaaaaa00000001");
$put('authors/bob.md', "title: Bob\nstatus: published");
$put('authors/cy.md', "title: Cy\nstatus: draft\ntranslation_id: cccccccc00000003");
$put('works/w1.md', "title: First\nstatus: published\ndate: 2020-01-01\ntranslation_id: 1111111100000001\ncustom_fields:\n  author: aaaaaaaa00000001\n  team: [cccccccc00000003, aaaaaaaa00000001]");
$put('works/w2.md', "title: Second\nstatus: published\ndate: 2022-01-01\ntranslation_id: 2222222200000002\ncustom_fields:\n  author: aaaaaaaa00000001\n  editions:\n    - { earlier: 1111111100000001, note: 'revised' }");
$put('works/w3.md', "title: Draft work\nstatus: draft\ntranslation_id: 3333333300000003\ncustom_fields:\n  author: aaaaaaaa00000001");
$put('works/w2.en.md', "title: Second EN\nstatus: published\ndate: 2022-01-01\ntranslation_id: 2222222200000002\ncustom_fields:\n  author: aaaaaaaa00000001");

$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository($root . '/content', new MarkdownConverter($environment), $settings);
$database = new SystemDatabase($root . '/storage');
$database->initialize();
$meta = new SystemMetaRepository($database);
$types = new ContentTypes(new Theme($root, 'default'));
$index = new ContentIndex($database, $root . '/content', $meta);
$index->keepReferences(fn(string $type): array => $types->definition($type, 'en', 'el')['fields']);
$rebuilt = $index->rebuild($content, ['authors', 'works']);
$relations = new Relations($index, $content, $types, $settings, $root . '/content');
$types->showRelationsWith(fn(array $ids, array $allowed, string $lang): array => $relations->resolve($ids, $allowed, $lang));
check('the index is built', [$rebuilt['ok'], $rebuilt['indexed']], [true, 8]);
check('the index holds no stale state right after a build', $index->status(['authors', 'works'])['stale'], false);

$w1 = $content->find('works', 'w1', 'el', true);
$w2 = $content->find('works', 'w2', 'el', true);
$ada = $content->find('authors', 'ada', 'el', true);

// ---- reading
check('related() gives the author in the language of the page', [array_column($relations->related($w1, 'author', 'el'), 'title'), array_column($relations->related($w1, 'author', 'en'), 'title')], [['Ada'], ['Ada EN']]);
check('a language the author has no page in falls back to the site\'s own', array_column($relations->resolve(['aaaaaaaa00000001'], ['authors'], 'fr'), 'title'), ['Ada']);
check('a card has the address of the entry in that language', array_column($relations->related($w1, 'author', 'en'), 'url'), ['/en/authors/ada']);
check('a draft is not shown, and the order of the others is kept', array_column($relations->related($w1, 'team', 'el'), 'title'), ['Ada']);
check('an editor who previews also gets the draft, marked hidden', array_map(fn($c) => [$c['title'], $c['hidden']], $relations->related($w1, 'team', 'el', true)), [['Cy', true], ['Ada', false]]);
check('an id that leads nowhere is skipped, not an error', $relations->resolve(['ffffffff00000009'], [], 'el'), []);
check('an entry of another type than the field allows is not shown', $relations->resolve(['1111111100000001'], ['authors'], 'el'), []);
check('the ids in a row of a list resolve the same way', array_column($relations->resolve(RelationFields::idList($types->values('works', $w2->meta)['editions'][0]['earlier']), [], 'el'), 'title'), ['First']);
check('the excerpt travels with the card', $relations->related($w1, 'author', 'el')[0]['excerpt'], 'Maths');

// ---- display
$shown = $types->display('works', $w1->meta, 'page', fn($d) => $d, 'el', 'el');
check('the fact sheet names the author and links to it', [$shown[0]['type'], $shown[0]['display'], $shown[0]['items'][0]['url']], ['relation', 'Ada', '/authors/ada']);
check('a row of a list shows its relation', $types->display('works', $w2->meta, 'page', fn($d) => $d, 'el', 'el')[1]['rows'][0][0]['display'], 'First');

// ---- the reverse question
check('the works of an author, newest first, drafts left out', array_column($relations->referencedBy($ada, 'works', 'author', 'el'), 'title'), ['Second', 'First']);
check('in another language each work shows its version there, or the site\'s own', array_column($relations->referencedBy($ada, 'works', 'author', 'en'), 'title'), ['Second EN', 'First']);
check('a field inside a list can be asked about too', array_column($relations->referencedBy($content->find('works', 'w1', 'el', true), 'works', 'editions.earlier', 'el'), 'title'), ['Second']);
check('a field that is not a reference of that type gives nothing', $relations->referencedBy($ada, 'works', 'nothing', 'el'), []);
check('all the references to an entry are known', count($index->allReferencesTo('aaaaaaaa00000001')), 5);

// ---- an address change keeps the reference
rename($root . '/content/authors/ada.md', $root . '/content/authors/ada-lovelace.md');
rename($root . '/content/authors/ada.en.md', $root . '/content/authors/ada-lovelace.en.md');
$content->forget();
$index->rebuild($content, ['authors', 'works']);
check('after a new address the work still finds its author, at the new address', array_column($relations->related($w1, 'author', 'el'), 'url'), ['/authors/ada-lovelace']);
check('and the author still finds the works', array_column($relations->referencedBy($content->find('authors', 'ada-lovelace', 'el', true), 'works', 'author', 'el'), 'title'), ['Second', 'First']);

// ---- hidden and deleted targets
$put('authors/ada-lovelace.md', "title: Ada\nstatus: draft\ntranslation_id: aaaaaaaa00000001");
$content->forget();
$index->upsert($content->find('authors', 'ada-lovelace', 'el', true));
check('an author who is unpublished is not shown in that language (the reference stays)', array_column($relations->related($w1, 'author', 'el'), 'title'), []);
check('the stored reference is untouched', $w1->meta['custom_fields']['author'], 'aaaaaaaa00000001');
$index->remove('authors', 'ada-lovelace', 'el');
check('a deleted entry\'s own references go with it', count(array_filter($index->allReferencesTo('aaaaaaaa00000001'), fn($r) => $r['from_slug'] === 'ada-lovelace')), 0);

// ---- an entry without an id gets one when it is first pointed at
check('an entry typed by address that has no id gets one', strlen($relations->lookup('authors/bob', ['authors'])), 16);
$bob = $content->find('authors', 'bob', 'el', true);
$content->forget();
check('the id is written to the file, and the index knows it', [(bool)preg_match('/^translation_id: [a-f0-9]{16}$/m', (string)file_get_contents($root . '/content/authors/bob.md')), $index->entriesByAddress([['authors', 'bob']], ['el'])[0]['translation_id'] !== ''], [true, true]);
check('asking again gives the same id', $relations->lookup('authors/bob', ['authors']), $index->entriesByAddress([['authors', 'bob']], ['el'])[0]['translation_id']);
check('a type the field does not allow, a missing entry and a bare word give nothing', [$relations->lookup('works/w1', ['authors']), $relations->lookup('authors/nobody', ['authors']), $relations->lookup('bob', ['authors'])], ['', '', '']);
$typed = $relations->normalize('works', ['author' => 'authors/bob', 'team' => ['authors/bob', 'aaaaaaaa00000001', 'authors/nobody'], 'editions' => [['earlier' => 'works/w1', 'note' => 'n']]]);
$bobId = $relations->lookup('authors/bob', ['authors']);
check('typed addresses become ids, in a list and in a row', [$typed['author'], $typed['team'], $typed['editions'][0]['earlier']], [$bobId, [$bobId, 'aaaaaaaa00000001'], '1111111100000001']);
check('a preview does not give an entry an id', $relations->normalize('works', ['author' => 'authors/cy'], false)['author'], 'cccccccc00000003');
$put('authors/dee.md', "title: Dee\nstatus: published");
$content->forget();
$index->upsert($content->parseFile('authors', $root . '/content/authors/dee.md'));
check('...and cannot write one', [$relations->normalize('works', ['author' => 'authors/dee'], false)['author'], str_contains((string)file_get_contents($root . '/content/authors/dee.md'), 'translation_id')], ['', false]);

// ---- exporting addresses
$out = $relations->withAddresses('works', $w1->meta);
check('an export writes the address of each entry', [$out['custom_fields']['author'], $out['custom_fields']['team']], ['authors/ada-lovelace', ['authors/cy', 'authors/ada-lovelace']]);

// ---- the search box
$put('authors/doxiadis.md', "title: Δοξιάδης\nstatus: published\ntranslation_id: dddddddd00000004");
$content->forget();
$index->upsert($content->parseFile('authors', $root . '/content/authors/doxiadis.md'));
$all = ['authors', 'works'];
check('with no text the entries of the type come by title, drafts marked hidden', array_map(fn($c) => [$c['title'], $c['hidden']], $relations->search('', ['authors'], $all)), [['Ada EN', false], ['Bob', false], ['Cy', true], ['Dee', false], ['Δοξιάδης', false]]);
check('a title is found by a part of it, any case', array_column($relations->search('BO', ['authors'], $all), 'title'), ['Bob']);
check('and a Greek one too, in lower case', array_column($relations->search('δοξ', ['authors'], $all), 'title'), ['Δοξιάδης']);
check('the ones that start with the text come first', array_column($relations->search('d', ['authors'], $all), 'title'), ['Dee', 'Ada EN', 'Δοξιάδης']);
check('an entry without an id is offered by its address until it gets one', array_column($relations->search('dee', ['authors'], $all), 'value'), ['@authors/dee']);
check('a type the person may not open, or the field does not allow, is never offered', [$relations->search('', ['works'], ['authors']), $relations->search('', ['authors'], ['works'])], [[], []]);
check('a limit is kept', count($relations->search('', ['authors'], $all, 2)), 2);

// ---- what the form holds
$fieldsOfWorks = $relations->withLabels($types->editableFields('works', 'en', 'el'), $types->values('works', $w2->meta), 'el');
check('the form knows the title and state of what it holds, in a list too', [(function () use ($fieldsOfWorks) { $k = array_map('strval', array_keys($fieldsOfWorks['author']['labels'])); sort($k); return $k; })(), $fieldsOfWorks['editions']['fields']['earlier']['labels']['1111111100000001']['title']], [['1111111100000001', 'aaaaaaaa00000001'], 'First']);
$labelsOfDraft = $relations->withLabels($types->editableFields('works', 'en', 'el'), $types->values('works', $w1->meta), 'el')['team']['labels'];
check('a draft is in the labels, marked', [$labelsOfDraft['cccccccc00000003']['hidden'], $labelsOfDraft['cccccccc00000003']['status']], [true, 'draft']);
check('an id that leads nowhere is not in them', isset($relations->withLabels($types->editableFields('works', 'en', 'el'), ['author' => 'ffffffff00000009'], 'el')['author']['labels']['ffffffff00000009']), false);

// ---- who points at an entry, and what is broken
$byAda = $relations->referrers('authors', 'ada-lovelace');
check('an entry knows who points at it, one line for each entry with the fields it uses', array_map(fn($r) => [$r['slug'] . '.' . $r['lang'], $r['fields']], $byAda), [['w3.el', ['author']], ['w1.el', ['author', 'team']], ['w2.el', ['author']], ['w2.en', ['author']]]);
check('an entry nobody points at has no referrers', $relations->referrers('authors', 'dee'), []);
$put('works/w4.md', "title: Orphan\nstatus: published\ncustom_fields:\n  author: ffffffff00000009");
$content->forget();
$index->upsert($content->parseFile('works', $root . '/content/works/w4.md'));
check('a reference to an entry that is gone is listed', array_map(fn($r) => [$r['slug'], $r['field']], $relations->broken()), [['w4', 'author']]);

// ---- the index follows the definitions
file_put_contents($root . '/custom/content-types/works.yaml', "label: Works\nfields:\n  author: { type: text }\n");
$types2 = new ContentTypes(new Theme($root, 'default'));
$index2 = new ContentIndex($database, $root . '/content', $meta);
$index2->keepReferences(fn(string $type): array => $types2->definition($type, 'en', 'el')['fields']);
check('changing what a type declares makes the references stale, so they are built again', $index2->status(['authors', 'works'])['stale'], true);
$index2->rebuild($content, ['authors', 'works']);
check('...and they are gone when the field is no longer a relation', count($index2->referencesTo('aaaaaaaa00000001', 'works', 'author')), 0);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
