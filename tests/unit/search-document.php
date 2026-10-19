<?php
/* Search reads public words from declared fields, never arbitrary front matter or block settings.
 *   php tests/unit/search-document.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{BlockContainer, BlockRegistry, ContentItem, ContentTypes, FieldSchema, SearchDocument, SearchText, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
$checks = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail, $checks;
    $checks++;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$root = sys_get_temp_dir() . '/faros-search-document-' . getmypid();
mkdir($root . '/custom/blocks/search-fixture', 0775, true);
mkdir($root . '/custom/content-types', 0775, true);
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(static function () use ($root): void {
    unlink($root . '/themes');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
});

$fixtureFields = [
    'heading' => ['type' => 'text'],
    'intro' => ['type' => 'textarea'],
    'body' => ['type' => 'markdown'],
    'hidden_copy' => ['type' => 'text', 'hidden' => true],
    'opted_out' => ['type' => 'text', 'searchable' => false],
    'design' => ['type' => 'text'],
    'missing' => ['type' => 'text', 'default' => 'UnusedDefault'],
    'url' => ['type' => 'url'],
    'link' => ['type' => 'link'],
    'image' => ['type' => 'image'],
    'file' => ['type' => 'file'],
    'icon' => ['type' => 'icon'],
    'number' => ['type' => 'number'],
    'toggle' => ['type' => 'toggle'],
    'layout' => ['type' => 'select', 'options' => ['secret_layout' => 'LayoutSetting']],
    'conditional' => ['type' => 'text', 'when' => ['variant' => 'wide']],
    'negative' => ['type' => 'text', 'when' => ['variant' => '!wide']],
    'translated' => ['type' => 'text', 'translatable' => true],
    'items' => ['type' => 'repeater', 'max' => 2, 'fields' => [
        'title' => ['type' => 'text'],
        'body' => ['type' => 'markdown'],
        'private' => ['type' => 'text', 'searchable' => false],
        'link' => ['type' => 'link'],
        'buttons' => ['type' => 'repeater', 'max' => 1, 'fields' => [
            'label' => ['type' => 'text', 'translatable' => true],
            'secret' => ['type' => 'text', 'searchable' => false],
            'url' => ['type' => 'link'],
        ]],
    ]],
];
file_put_contents($root . '/custom/blocks/search-fixture/block.yaml', Yaml::dump([
    'label' => 'Search fixture',
    'variants' => ['normal' => 'Normal', 'wide' => 'Wide'],
    'design_fields' => ['design'],
    'fields' => $fixtureFields,
], 8));
file_put_contents($root . '/custom/content-types/search-fixtures.yaml', Yaml::dump([
    'fields' => [
        'author' => ['type' => 'text'],
        'summary' => ['type' => 'markdown'],
        'isbn' => ['type' => 'text'],
        'sector' => ['type' => 'select', 'options' => ['retail_internal' => ['el' => 'Λιανεμπόριο', 'en' => 'Retail']]],
        'hidden' => ['type' => 'text', 'hidden' => true, 'searchable' => true],
        'private' => ['type' => 'text', 'show' => false],
        'card' => ['type' => 'text', 'show' => false, 'card' => true],
        'opted_out' => ['type' => 'text', 'searchable' => false],
        'custom_template' => ['type' => 'text', 'show' => false, 'searchable' => true],
        'url' => ['type' => 'url', 'searchable' => true],
        'number' => ['type' => 'number'],
        'decimal' => ['type' => 'decimal'],
        'date' => ['type' => 'date'],
        'missing' => ['type' => 'text', 'default' => 'UnusedFieldDefault'],
        'writer' => ['type' => 'relation', 'types' => ['authors']],
        'private_writer' => ['type' => 'relation', 'types' => ['authors'], 'searchable' => false],
        'hidden_writer' => ['type' => 'relation', 'types' => ['authors'], 'hidden' => true],
        'editions' => ['type' => 'repeater', 'fields' => [
            'publisher' => ['type' => 'text'],
            'reviewer' => ['type' => 'relation', 'types' => ['authors'], 'multiple' => true],
            'format' => ['type' => 'select', 'options' => ['paper' => ['el' => 'Χαρτί', 'en' => 'Paper']]],
            'shops' => ['type' => 'repeater', 'fields' => [
                'name' => ['type' => 'text', 'translatable' => true],
                'url' => ['type' => 'url'],
                'internal' => ['type' => 'text', 'searchable' => false],
            ]],
        ]],
    ],
], 10));

$theme = new Theme($root);
$types = new ContentTypes($theme);
$registry = new BlockRegistry($theme);
$environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
$environment->addExtension(new CommonMarkCoreExtension());
$markdown = new MarkdownConverter($environment);
$search = new SearchDocument($registry, $types, static fn(string $text): string => (string)$markdown->convert($text), 'el');
$item = static fn(array $meta = [], string $html = '', string $type = 'pages', string $lang = 'el'): ContentItem => new ContentItem($type, 'fallback-slug', $lang, $meta, 'Unused raw Markdown', $html, '', 0);

$document = $search->extract($item([
    'title' => 'Κήπος &amp; θάλασσα',
    'excerpt' => '<b>Μικρό απόσπασμα</b>',
    'categories' => ['Οικολογία', ['private' => 'NestedTermSecret']],
    'tags' => ['Ελλάδα', 'Φύση'],
    'seo_description' => 'MetadataSecret',
], '<p>Πρώτη παράγραφος.</p><p>Δεύτερη <strong>παράγραφος</strong>.</p><script>ScriptSecret</script><style>StyleSecret</style><a href="https://destination-secret.test">Ορατή ετικέτα</a>'));
check('title is human text', $document['title'], 'Κήπος & θάλασσα');
check('terms include scalar categories and tags', $document['terms'], 'Οικολογία Ελλάδα Φύση');
check('excerpt is separate', $document['excerpt'], 'Μικρό απόσπασμα');
check('body has paragraph boundaries and link labels, no markup or executable text', $document['body'], 'Πρώτη παράγραφος. Δεύτερη παράγραφος. Ορατή ετικέτα');
check('no raw Markdown or other metadata leaks into document', str_contains(implode(' ', array_diff_key($document, ['normal' => true, 'relations' => true])), 'Secret') || str_contains($document['body'], 'Unused raw Markdown'), false);
check('undefined type has no custom fields', $document['fields'], '');
check('missing title falls back to slug', $search->extract($item())['title'], 'fallback-slug');

$block = [
    'type' => 'search-fixture',
    'heading' => 'HeadingWords',
    'intro' => "First line\nSecond line",
    'body' => '**MarkdownWords** [LinkWords](https://markdown-secret.test) ![image secret](/uploads/secret.jpg)',
    'hidden_copy' => 'HiddenFieldSecret',
    'opted_out' => 'OptOutSecret',
    'design' => 'DesignSecret',
    'anchor' => 'AnchorSecret',
    'url' => 'https://url-secret.test',
    'link' => '/LinkSecret',
    'image' => '/uploads/ImageSecret.jpg',
    'file' => '/uploads/FileSecret.pdf',
    'icon' => 'IconSecret',
    'number' => 892739,
    'toggle' => true,
    'layout' => 'secret_layout',
    'conditional' => 'InactiveSecret',
    'negative' => 'NegativeConditionWords',
    'translated' => ['default' => 'DefaultWords', 'el' => 'Μεταφρασμένο', 'en' => 'TranslatedWords'],
    'unknown' => 'UnknownFieldSecret',
    'items' => [
        ['title' => 'RowOne', 'body' => '*RowBody*', 'private' => 'PrivateRowSecret', 'link' => '/RowLinkSecret', 'unknown' => 'UnknownRowSecret'],
        ['title' => 'RowTwo'],
        ['title' => 'OverflowSecret'],
    ],
];
$body = $search->extract($item(['blocks' => [$block]]))['body'];
check('block content and repeaters are extracted by schema', $body, 'HeadingWords First line Second line MarkdownWords LinkWords NegativeConditionWords Μεταφρασμένο RowOne RowBody RowTwo');
check('block technical settings and private fields are absent', str_contains(strtolower($body), 'secret') || str_contains($body, '892739') || str_contains($body, 'LayoutSetting'), false);
check('missing block fields do not invent default words', str_contains($body, 'UnusedDefault'), false);
check('field condition uses the resolved common variant default', str_contains($body, 'NegativeConditionWords') && !str_contains($body, 'InactiveSecret'), true);
$wide = $search->extract($item(['blocks' => [array_replace($block, ['variant' => 'wide', 'conditional' => 'WideWords'])]]))['body'];
check('active field condition and negation follow variant', str_contains($wide, 'WideWords') && !str_contains($wide, 'NegativeConditionWords'), true);
check('translatable text uses only the entry language', str_contains($search->extract($item(['blocks' => [$block]], '', 'pages', 'en'))['body'], 'TranslatedWords'), true);
check('hidden block is ignored', $search->extract($item(['blocks' => [array_replace($block, ['hidden' => 'yes'])]]))['body'], '');
check('unknown blocks and malformed rows are ignored', $search->extract($item(['blocks' => [null, 'bad', ['type' => []], ['type' => 'missing', 'heading' => 'UnknownBlockSecret']]]))['body'], '');
check('empty and malformed repeater rows do not consume its visible row limit', $search->extract($item(['blocks' => [[
    'type' => 'search-fixture', 'items' => [null, [], ['title' => ''], ['unknown' => 'EmptyRowSecret'], ['title' => 'FirstRow'], ['title' => 'SecondRow'], ['title' => 'OverLimitSecret']],
]]]))['body'], 'FirstRow SecondRow');

$nested = $search->extract($item(['blocks' => [[
    'type' => 'search-fixture', 'items' => [
        ['title' => 'OuterRow', 'buttons' => [['label' => ['default' => 'ΚουμπίΈνα', 'en' => 'ButtonOne'], 'secret' => 'InnerPrivateSecret', 'url' => '/InnerLinkSecret'], ['label' => 'InnerOverLimitSecret']]],
        ['buttons' => [['label' => 'OnlyInnerWords']]],
    ],
]]]))['body'];
check('a list inside a row of a block is searched too, with its own limit, privacy and language', $nested, 'OuterRow ΚουμπίΈνα OnlyInnerWords');
check('and in the entry\'s language', $search->extract($item(['blocks' => [['type' => 'search-fixture', 'items' => [['buttons' => [['label' => ['default' => 'ΚουμπίΈνα', 'en' => 'ButtonOne']]]]]]]], '', 'pages', 'en'))['body'], 'ButtonOne');
check('the buttons of a Hero slider slide saved before they were a list are searched', $search->extract($item(['blocks' => [['type' => 'hero-slider', 'items' => [['heading' => 'SlideHeading', 'label' => 'OldFirstButton', 'url' => '/a', 'label_2' => 'OldSecondButton', 'url_2' => '/b']]]]]))['body'], 'SlideHeading OldFirstButton OldSecondButton');

$container = ['type' => BlockContainer::TYPE, 'settings' => ['class' => 'ContainerClassSecret'], 'columns' => [
    ['settings' => ['width' => 'ColumnWidthSecret'], 'blocks' => [['type' => 'text', 'heading' => 'InsideColumn'], ['type' => 'text', 'heading' => 'HiddenColumnBlockSecret', 'hidden' => true]]],
    ['blocks' => [['type' => 'text', 'body' => 'Column **Markdown**'], ['type' => BlockContainer::TYPE, 'columns' => [['blocks' => [['type' => 'text', 'heading' => 'NestedContainerSecret']]]]]]],
]];
check('visible columns contribute only rendered child blocks', $search->extract($item(['blocks' => [$container]]))['body'], 'InsideColumn Column Markdown');
$container['settings']['hidden'] = true;
check('hidden container excludes all descendants', $search->extract($item(['blocks' => [$container]]))['body'], '');
$container['settings']['hidden'] = false;
$container['columns'] = array_fill(0, BlockContainer::MAX_COLUMNS, ['blocks' => []]);
$container['columns'][] = ['blocks' => [['type' => 'text', 'heading' => 'ExtraColumnSecret']]];
check('columns beyond renderer limit are ignored', $search->extract($item(['blocks' => [$container]]))['body'], '');

$document = $search->extract($item(['custom_fields' => [
    'author' => 'AuthorWords', 'summary' => 'A **public** [summary](https://custom-secret.test)', 'isbn' => '978-1-234-56789-7',
    'sector' => 'retail_internal', 'hidden' => 'HiddenCustomSecret', 'private' => 'PrivateCustomSecret', 'card' => 'CardWords',
    'opted_out' => 'OptOutCustomSecret', 'custom_template' => 'CustomTemplateWords', 'url' => 'https://url-secret.test', 'number' => 987654, 'unknown' => 'UnknownCustomSecret',
]], '', 'search-fixtures'));
check('custom fields include public text, Markdown, ISBN, localized labels and explicit public overrides', $document['fields'], 'AuthorWords A public summary 978-1-234-56789-7 Λιανεμπόριο CardWords CustomTemplateWords 987654');
check('custom field keys and unpublished data are excluded', str_contains(strtolower($document['fields']), 'secret') || str_contains($document['fields'], 'retail_internal') || str_contains($document['fields'], 'UnusedFieldDefault'), false);
check('select labels use the entry language', $search->extract($item(['custom_fields' => ['sector' => 'retail_internal']], '', 'search-fixtures', 'en'))['fields'], 'Retail');
check('book author is searchable because its card publicly displays it', $search->extract($item(['custom_fields' => ['author' => 'Νίκος Καζαντζάκης', 'isbn' => '9781234567890']], '', 'books'))['fields'], 'Νίκος Καζαντζάκης 9781234567890');
check('a content type\'s list with a list in its rows is searched at both levels', $search->extract($item(['custom_fields' => ['editions' => [
    ['publisher' => 'PublisherWords', 'format' => 'paper', 'shops' => [['name' => ['default' => 'Πολιτεία', 'en' => 'Politeia'], 'url' => 'https://shop-secret.test', 'internal' => 'InternalShopSecret']]],
    ['shops' => [['name' => 'SecondShopWords']]], // a choice with no empty option has a value, shown on the page, so it is searched
]]], '', 'search-fixtures'))['fields'], 'PublisherWords Χαρτί Πολιτεία Χαρτί SecondShopWords');
$related = $search->extract($item(['custom_fields' => [
    'writer' => 'aaaaaaaa00000001', 'private_writer' => 'bbbbbbbb00000002', 'hidden_writer' => 'cccccccc00000003',
    'editions' => [['publisher' => 'PublisherWords', 'reviewer' => ['dddddddd00000004', 'aaaaaaaa00000001']], ['reviewer' => ['eeeeeeee00000005']]],
]], '', 'search-fixtures'));
check('a document lists the ids of its relation fields, also in rows, once each', $related['relations'], ['aaaaaaaa00000001', 'dddddddd00000004', 'eeeeeeee00000005']);
check('relation ids are not words of the entry: they never appear in its text', str_contains($related['fields'], '0000'), false);
check('an entry with no relations lists none', $search->extract($item(['custom_fields' => ['author' => 'x']], '', 'search-fixtures'))['relations'], []);
check('declared public number, decimal and date facts are searchable', $search->extract($item(['custom_fields' => ['number' => 2026, 'decimal' => 3.5, 'date' => '2026-10-16']], '', 'search-fixtures'))['fields'], '2026 3.5 2026-10-16');
check('searchable override is preserved in content schema', $types->definition('search-fixtures')['fields']['opted_out']['searchable'], false);
$field = FieldSchema::normalize(['ordinary' => ['type' => 'text']])['ordinary'];
check('ordinary schema shape remains unchanged', array_key_exists('searchable', $field), false);
check('both explicit boolean search overrides survive schema normalization', array_column(FieldSchema::normalize(['yes' => ['searchable' => true], 'no' => ['searchable' => false]]), 'searchable'), [true, false]);
check('invalid search override is ignored', array_key_exists('searchable', FieldSchema::normalize(['field' => ['searchable' => 'false']])['field']), false);
check('malformed front matter does not produce warnings or private text', $search->extract($item(['title' => [], 'excerpt' => [], 'custom_fields' => 'bad', 'blocks' => 'bad']))['body'], '');
check('visible URL text is omitted', $search->extract($item([], '<p>Visit https://url-secret.test/path or www.url-secret.test. Visible words.</p>'))['body'], 'Visit or Visible words.');
check('unclosed script and style content is excluded', $search->extract($item([], '<p>Visible words.</p><script>UnclosedScriptSecret'))['body'], 'Visible words.');

// Cached words must always reflect the actual content and field definitions, independently of timestamps.
$markdownCalls = 0;
$cached = static function (string $salt = 'test-version', ?string $dir = null, string $defaultLang = 'el') use ($root, $markdown, &$markdownCalls): SearchDocument {
    $theme = new Theme($root);
    $documents = new SearchDocument(new BlockRegistry($theme), new ContentTypes($theme), static function (string $text) use ($markdown, &$markdownCalls): string {
        $markdownCalls++;
        return (string)$markdown->convert($text);
    }, $defaultLang);
    $documents->cacheIn($dir ?? $root . '/cache/search', $salt);
    return $documents;
};
$entry = $item(['blocks' => [['type' => 'text', 'body' => 'Cached **words**']]]);
$cache = $cached();
$first = $cache->extract($entry);
$cache->flush();
check('cold document cache converts Markdown and stores one bucket', [$markdownCalls, count(glob($root . '/cache/search/*.ser'))], [1, 1]);
$markdownCalls = 0;
$cache = $cached();
check('warm document cache returns identical words without converting Markdown', [$cache->extract($entry), $markdownCalls], [$first, 0]);
$cache->flush();
$entry->meta['blocks'][0]['body'] = 'Edited **words**';
$cache = $cached();
check('same mtime and same-length changed metadata invalidates cached text', [$cache->extract($entry)['body'], $markdownCalls], ['Edited words', 1]);
$cache->flush();
$entry->html = '<p>Changed body HTML.</p>';
$cache = $cached();
check('changed rendered body invalidates cached text', $cache->extract($entry)['body'], 'Changed body HTML. Edited words');
$cache->flush();
$markdownCalls = 0;
$cache = $cached('new-version');
$cache->extract($entry);
$cache->flush();
check('release salt invalidates document cache', $markdownCalls, 1);
$markdownCalls = 0;
$cache = $cached('new-version', null, 'en');
$cache->extract($entry);
$cache->flush();
check('default language invalidates document cache', $markdownCalls, 1);
$entry->lang = 'en';
$cache = $cached('new-version', null, 'en');
$cache->extract($entry);
$cache->flush();
check('entry languages use separate buckets', count(glob($root . '/cache/search/*.ser')), 2);

$schemaEntry = $item(['custom_fields' => ['author' => 'PublicAuthor'], 'blocks' => [['type' => 'text', 'body' => 'Schema **words**']]], '', 'search-fixtures');
$cache = $cached();
$cache->extract($schemaEntry);
$cache->flush();
$definitionFile = $root . '/custom/content-types/search-fixtures.yaml';
$definition = Yaml::parseFile($definitionFile);
$definition['fields']['author']['show'] = false;
file_put_contents($definitionFile, Yaml::dump($definition, 8));
$markdownCalls = 0;
$cache = $cached();
$changed = $cache->extract($schemaEntry);
$cache->flush();
check('content-type show change invalidates cached text and removes private words', [$changed['fields'], $markdownCalls], ['', 1]);
$definition['fields']['author']['show'] = true;
$definition['fields']['author']['searchable'] = false;
file_put_contents($definitionFile, Yaml::dump($definition, 8));
$markdownCalls = 0;
$cache = $cached();
$changed = $cache->extract($schemaEntry);
$cache->flush();
check('content-type searchable change invalidates cache', [$changed['fields'], $markdownCalls], ['', 1]);

$blockEntry = $item(['blocks' => [['type' => 'search-fixture', 'heading' => 'BlockHeading', 'body' => 'Block **words**']]]);
$cache = $cached();
$cache->extract($blockEntry);
$cache->flush();
$blockFile = $root . '/custom/blocks/search-fixture/block.yaml';
$blockDefinition = Yaml::parseFile($blockFile);
$blockDefinition['fields']['heading']['searchable'] = false;
file_put_contents($blockFile, Yaml::dump($blockDefinition, 8));
$markdownCalls = 0;
$cache = $cached();
$changed = $cache->extract($blockEntry);
$cache->flush();
check('changed block field schema invalidates cached words', [$changed['body'], $markdownCalls], ['Block words', 1]);

$secondEntry = clone $blockEntry;
$secondEntry->slug = 'second-entry';
$cache = $cached();
$cache->extract($blockEntry);
$cache->extract($secondEntry);
$cache->flush();
$bucketFile = $root . '/cache/search/' . hash('sha256', 'pages|el') . '.ser';
$stored = unserialize(file_get_contents($bucketFile), ['allowed_classes' => false]);
check('cache stores only identities, fingerprints and extracted text', [count($stored['entries']), array_keys($stored['entries']['second-entry'][1])], [2, ['title', 'terms', 'fields', 'excerpt', 'body', 'normal', 'relations']]);
check('cached entries carry the normalized fields', array_map(SearchText::normalize(...), array_diff_key($stored['entries']['second-entry'][1], ['normal' => true, 'relations' => true])), $stored['entries']['second-entry'][1]['normal']);
$cache = $cached();
$cache->extract($secondEntry);
$cache->flush();
$stored = unserialize(file_get_contents($bucketFile), ['allowed_classes' => false]);
check('a public flush keeps entries it did not visit', array_keys($stored['entries']), ['fallback-slug', 'second-entry']);
$cache = $cached();
$cache->extract($blockEntry);
$cache->flush(true);
$stored = unserialize(file_get_contents($bucketFile), ['allowed_classes' => false]);
check('a complete flush prunes entries not visited in the scan', array_keys($stored['entries']), ['fallback-slug']);

$oldFormat = unserialize(file_get_contents($bucketFile), ['allowed_classes' => false]);
$oldFormat['entries']['fallback-slug'][1] = array_diff_key($oldFormat['entries']['fallback-slug'][1], ['normal' => true, 'relations' => true]);
file_put_contents($bucketFile, serialize($oldFormat));
$markdownCalls = 0;
$cache = $cached();
$cache->extract($blockEntry);
check('an old-format cache file without normalized text is ignored', $markdownCalls, 1);
$cache->flush();
file_put_contents($bucketFile, 'broken serialized data');
$markdownCalls = 0;
$cache = $cached();
check('corrupt cache falls back to normal extraction', [$cache->extract($secondEntry)['body'], $markdownCalls], ['Block words', 1]);
$cache->flush();
$stored = unserialize(file_get_contents($bucketFile), ['allowed_classes' => false]);
$stored['entries']['second-entry'][1]['body'] = ['bad'];
file_put_contents($bucketFile, serialize($stored));
$markdownCalls = 0;
$cache = $cached();
check('malformed cached document is ignored', [$cache->extract($secondEntry)['body'], $markdownCalls], ['Block words', 1]);
$cache->flush();
file_put_contents($root . '/not-a-directory', '');
$cache = $cached('test-version', $root . '/not-a-directory/search');
check('unwritable cache keeps search functional', $cache->extract($secondEntry)['body'], 'Block words');
$cache->flush();
check('unwritable cache leaves no temporary files', glob($root . '/cache/search/*.tmp'), []);

echo $fail === 0 ? "\nALL PASSED ($checks checks)\n" : "\n$fail FAILED ($checks checks)\n";
exit($fail === 0 ? 0 : 1);
