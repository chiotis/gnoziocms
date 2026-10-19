<?php
/* Public search: Greek matching, ranking, safe display parts, filters, pagination and fresh repository reads. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnozioCMS\{BlockRegistry, ContentRepository, ContentSearch, ContentTypes, SearchDocument, SearchText, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
$checks = 0;
function check(string $label, mixed $actual, mixed $expected): void {
    global $fail, $checks;
    $checks++;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . var_export($actual, true) . ' expected ' . var_export($expected, true)) . "\n";
}

check('Greek accents, case and final sigma', SearchText::normalize('ΑΘΉΝΑ άλσος ΣΟΣ Μαΐου'), 'αθηνα αλσοσ σοσ μαιου');
check('polytonic and decomposed accents', SearchText::normalize("Ἄνθρωπος α\u{0301}λσος"), 'ανθρωποσ αλσοσ');
check('Latin accents', SearchText::normalize('CAFÉ résumé'), 'cafe resume');
check('words are unique and punctuation separates them', SearchText::terms('  Νέα, Αθήνα / ΝΕΑ '), ['νεα', 'αθηνα']);
check('only punctuation has no terms', SearchText::terms('"!? &'), []);
check('non-scalar input is not a query', SearchText::query(['bad']), '');
check('query whitespace and controls', SearchText::query(" one\n\t two\x00 "), 'one two');
check('query length is bounded in Unicode characters', mb_strlen(SearchText::query(str_repeat('ά', 500))), 200);
check('numeric zero is still a query', SearchText::query('0'), '0');

$parts = SearchText::parts('Η Αθήνα και το άλσος.', ['αθηνα', 'αλσοσ']);
check('display preserves the original Greek spelling', implode('', array_column($parts, 'text')), 'Η Αθήνα και το άλσος.');
check('highlighted words keep accents and final sigma', array_column(array_filter($parts, fn($p) => $p['match']), 'text'), ['Αθήνα', 'άλσος']);
$parts = SearchText::parts("Το α\u{0301}λσος", ['αλσοσ']);
check('a decomposed accent stays inside the highlight', $parts, [['text' => 'Το ', 'match' => false], ['text' => "α\u{0301}λσος", 'match' => true]]);
check('overlapping matches merge', SearchText::parts('foobar', ['foo', 'oobar']), [['text' => 'foobar', 'match' => true]]);
$parts = SearchText::parts('<img src=x> Αθήνα & <script>', ['αθηνα']);
check('highlighting emits plain data, not pre-escaped or trusted HTML', implode('', array_column($parts, 'text')), '<img src=x> Αθήνα & <script>');
check('empty parts', SearchText::parts('', ['a']), []);
check('a short term is not highlighted inside a longer word', SearchText::parts('Ο Δρόμος του Οδηγού', ['ο']), [['text' => 'Ο', 'match' => true], ['text' => ' Δρόμος του Οδηγού', 'match' => false]]);
check('a short Latin term only highlights whole words', array_column(array_filter(SearchText::parts('a party of art', ['a', 'of']), fn($p) => $p['match']), 'text'), ['a', 'of']);
check('word start beats mid-word, whole word beats both', [SearchText::strength('party', 'art'), SearchText::strength('artist', 'art'), SearchText::strength('the art', 'art')], [1, 2, 3]);
check('short terms never match mid-word', [SearchText::strength('δρομοσ', 'ο'), SearchText::strength('ο δρομοσ', 'ο')], [0, 3]);
$long = str_repeat('Πρόλογος χωρίς το ζητούμενο. ', 30) . 'Στο τέλος βρίσκεται η Αθήνα μέσα στο κείμενο. ' . str_repeat('Επίλογος. ', 25);
$snippet = SearchText::snippet($long, ['αθηνα']);
check('snippet finds a match late in the body', str_contains($snippet, 'Αθήνα'), true);
check('snippet is short and marks omitted text', mb_strlen($snippet) <= 224 && str_starts_with($snippet, '… ') && str_ends_with($snippet, ' …'), true);

$dir = sys_get_temp_dir() . '/faros-search-' . bin2hex(random_bytes(5));
foreach (['pages', 'posts', 'forms', 'books'] as $type) { mkdir("$dir/content/$type", 0775, true); }
$write = function (string $type, string $slug, string $title, string $body, string $extra = '') use ($dir): void {
    $status = str_contains($extra, 'status:') ? '' : "status: published\n";
    file_put_contents("$dir/content/$type/$slug.md", "---\ntitle: '$title'\n$status$extra---\n\n$body\n");
};
$write('pages', 'title', 'Αθήνα', 'Μια πόλη για περπάτημα.');
$write('pages', 'body', 'Περιήγηση', str_repeat('Αθήνα ', 100));
$write('pages', 'split', 'Αθήνα οδηγός', 'Επισκεφθείτε το άλσος.');
$write('pages', 'draft', 'Αθήνα κρυφή', '', "status: draft\n");
$write('pages', 'hidden', 'Αθήνα αόρατη', '', "visible: false\n");
$write('pages', 'foreign.en', 'Αθήνα English', 'Athens');
$write('forms', 'contact', 'Αθήνα form configuration', 'Hidden fields');
$write('books', 'book', 'Ένας περίπατος', '', "custom_fields:\n  author: 'Αθηνά Παπαδοπούλου'\n  isbn: '9781234567890'\n");
for ($n = 1; $n <= 12; $n++) { $write('posts', sprintf('page-%02d', $n), sprintf('Catalogue %02d', $n), 'Catalogue selection'); }
$write('pages', 'long-words', 'Ο Δρόμος της Πόλης', 'Κείμενο.');
$write('pages', 'noise', 'Ο πόλεμος της πόλης', 'Αλλο κείμενο.');
$write('pages', 'whole-word', 'Art gallery', 'Pictures.');
$write('pages', 'mid-word', 'Party hall', 'Hosting art.');
$write('pages', 'punct', 'Πόλεις, δρόμοι: Πάτρα', 'Κείμενο.');
$write('pages', 'phrase-title', 'Intro: Blue Moon, live!', 'Short.');
$write('pages', 'phrase-body', 'Notes', 'The blue moon rose. ' . str_repeat('blue moon ', 3));
$write('pages', 'catalogue', 'Catalogue overview', 'Selection');
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$markdown = new MarkdownConverter($environment);
$theme = new Theme(dirname(__DIR__, 2), 'default');
$documents = new SearchDocument(new BlockRegistry($theme), new ContentTypes($theme), fn($text) => (string)$markdown->convert($text), 'el');
$settings = ['languages' => ['default' => 'el'], 'content_types' => ['pages', 'posts', 'forms', 'books']];
$make = function (array $overrides = []) use ($dir, $markdown, $settings, $documents): ContentSearch {
    $repository = new ContentRepository("$dir/content", $markdown, $overrides + $settings);
    $repository->storeParsedIn("$dir/cache", 'search-test');
    return new ContentSearch($repository, $documents);
};
$search = $make();
$slugs = fn($result) => array_map(fn($item) => $item->slug, $result['results']);
$result = $search->search('αθηνα', 'el');
check('exact title wins over repeated body keywords', $result['results'][0]->slug, 'title');
check('visitor results omit drafts, hidden, other languages and forms', $slugs($result), ['title', 'split', 'book', 'body']);
check('public book author is searchable', $slugs($search->search('παπαδοπουλου', 'el')), ['book']);
check('ISBN searchable', $slugs($search->search('9781234567890', 'el')), ['book']);
check('AND terms can match across title and body, in either order', $slugs($search->search('αλσοσ αθηνα', 'el')), ['split']);
check('every term must match', $search->search('αθηνα missingword', 'el')['total'], 0);
check('strict language isolation', $slugs($search->search('αθηνα', 'en')), ['foreign']);
$signed = $slugs($search->search('αθηνα', 'el', true));
check('signed in preserves draft visibility without form config', in_array('draft', $signed) && in_array('hidden', $signed) && !in_array('contact', $signed), true);
check('disabled content types omitted', $make(['content_types_off' => ['books']])->search('παπαδοπουλου', 'el')['total'], 0);
$all = $search->search('Catalogue', 'el');
check('pagination counts all matches, returns one page of hits and retains legacy results', [$all['total'], count($all['hits']), count($all['results']), $all['pages'], $all['page']], [13, 10, 13, 2, 1]);
$second = $search->search('Catalogue', 'el', false, 'posts', '2');
check('filter and second page', [$second['total'], count($second['hits']), $second['page']], [12, 2, 2]);
check('filter counts cover all types before selection', $second['counts'], ['pages' => 1, 'posts' => 12]);
check('selected page is deterministic', array_map(fn($hit) => $hit['item']->slug, $second['hits']), ['page-11', 'page-12']);
check('out of range page clamps to last', $search->search('Catalogue', 'el', false, 'posts', '999')['page'], 2);
check('malformed type/page inputs are ignored', [$search->search('Catalogue', 'el', false, ['posts'], ['2'])['type'], $search->search('Catalogue', 'el', false, '', ['2'])['page']], ['', 1]);
check('unknown type falls back to all', $search->search('Catalogue', 'el', false, '../x')['total'], 13);
check('empty or punctuation-only query never lists the whole site', [$search->search('', 'el')['total'], $search->search('!!!', 'el')['total']], [0, 0]);
check('display retains ContentItem list for custom templates', $all['hits'][0]['item'] === $all['results'][0], true);
check('short words qualify only as whole words, long words rank first', $slugs($search->search('ο της δρομοσ', 'el')), ['long-words']);
check('short words are optional beside a long word but still raise the score', $slugs($search->search('ο της πολησ', 'el'))[0], 'long-words');
check('a long word alone matches without the short ones', in_array('punct', $slugs($search->search('το πατρα', 'el')), true), true);
check('short words alone do not match mid-word', $slugs($search->search('ο της', 'el')), ['long-words', 'noise']);
check('whole word outranks a mid-word match', $slugs($search->search('art', 'el')), ['whole-word', 'mid-word']);
check('punctuated title ranks first for its words typed without punctuation', $slugs($search->search('πολεισ δρομοι πατρα', 'el'))[0], 'punct');
check('exact punctuated title gets the exact boost over a longer title', $slugs($search->search('Πόλεις δρόμοι Πάτρα', 'el')), ['punct']);
check('phrase in a punctuated title outranks the same words in a body', $slugs($search->search('blue moon', 'el')), ['phrase-title', 'phrase-body']);
$write('pages', 'fresh', 'Freshly added', 'uncommonneedle');
check('new content becomes searchable on next request', $slugs($make()->search('uncommonneedle', 'el')), ['fresh']);
$write('pages', 'fresh', 'Freshly changed', 'replacementneedle has different size');
clearstatcache();
check('changed content replaces old search text', [$make()->search('uncommonneedle', 'el')['total'], $make()->search('replacementneedle', 'el')['total']], [0, 1]);
unlink("$dir/content/pages/fresh.md");
check('deleted content disappears on next request', $make()->search('replacementneedle', 'el')['total'], 0);

// More than ten distinct words: only the first ten are terms, and an exact title of those words still gets the boost.
$long = 'alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu';
check('terms are capped at ten, in query order', SearchText::terms($long), ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa']);
check('repeated words do not use up the cap', count(SearchText::terms(str_repeat('same ', 30) . 'other')), 2);
check('phrase stops at the last counted term', SearchText::phrase($long . ' nu'), 'alpha beta gamma delta epsilon zeta eta theta iota kappa');
$write('pages', 'ten-words', 'Alpha beta gamma delta epsilon zeta eta theta iota kappa', 'Ten.');
$write('pages', 'ten-body', 'Other', 'alpha beta gamma delta epsilon zeta eta theta iota kappa');
$capped = $make()->search($long . ' nu', 'el');
check('a capped query still finds and ranks entries', $slugs($capped), ['ten-words', 'ten-body']);
check('a very long query is still answered', $make()->search(implode(' ', array_map(fn($n) => "w$n", range(1, 100))), 'el')['total'], 0);

// Cache: normalized text is stored, and public scans neither prune nor rewrite what a signed-in scan cached.
$cacheDir = "$dir/doc-cache";
$cachedSearch = function () use ($cacheDir, $dir, $markdown): ContentSearch {
    $theme = new Theme(dirname(__DIR__, 2), 'default');
    $docs = new SearchDocument(new BlockRegistry($theme), new ContentTypes($theme), fn($text) => (string)$markdown->convert($text), 'el');
    $docs->cacheIn($cacheDir, 'search-test');
    $repository = new ContentRepository("$dir/content", $markdown, ['languages' => ['default' => 'el'], 'content_types' => ['pages', 'posts', 'forms', 'books']]);
    $repository->storeParsedIn("$dir/cache", 'search-test');
    return new ContentSearch($repository, $docs);
};
$bucketFile = $cacheDir . '/' . hash('sha256', 'pages|el') . '.ser';
$cachedSearch()->search('αθηνα', 'el', true);
$stored = unserialize(file_get_contents($bucketFile), ['allowed_classes' => false]);
check('cached drafts carry normalized text', [isset($stored['entries']['draft']), $stored['entries']['draft'][1]['normal']['title'] ?? null], [true, 'αθηνα κρυφη']);
touch($bucketFile, time() - 100);
clearstatcache();
$before = [filemtime($bucketFile), md5_file($bucketFile)];
$public = $slugs($cachedSearch()->search('αθηνα', 'el'));
clearstatcache();
check('a public search after a signed-in one leaves the cache file alone', [filemtime($bucketFile), md5_file($bucketFile)], $before);
check('and does not expose cached drafts or hidden entries', in_array('draft', $public) || in_array('hidden', $public), false);
unlink("$dir/content/pages/draft.md");
$cachedSearch()->search('αθηνα', 'el', true);
$stored = unserialize(file_get_contents($bucketFile), ['allowed_classes' => false]);
check('a signed-in scan prunes entries that no longer exist', isset($stored['entries']['draft']), false);

// ---- Related titles: an entry is found by the titles of the entries it points at (a book by its author).
$rroot = sys_get_temp_dir() . '/faros-search-rel-' . bin2hex(random_bytes(5));
mkdir("$rroot/custom/content-types", 0775, true);
mkdir("$rroot/storage", 0775, true);
foreach (['authors', 'works'] as $type) { mkdir("$rroot/content/$type", 0775, true); }
symlink(dirname(__DIR__, 2) . '/themes', "$rroot/themes");
file_put_contents("$rroot/custom/content-types/works.yaml", "label: Works\nfields:\n  author: { type: relation, types: [authors] }\n  quiet: { type: relation, types: [authors], searchable: false }\n  editions:\n    type: repeater\n    fields:\n      editor: { type: relation, types: [authors] }\n");
file_put_contents("$rroot/custom/content-types/authors.yaml", "label: Authors\nfields:\n  country: { type: text }\n");
$put = static function (string $path, string $front, string $body = 'Text.') use ($rroot): void {
    file_put_contents("$rroot/content/$path", "---\n$front\n---\n\n$body\n");
};
$put('authors/ada.md', "title: Ada Lovelace\nstatus: published\ntranslation_id: aaaaaaaa00000001");
$put('authors/ada.en.md', "title: Countess Augusta\nstatus: published\ntranslation_id: aaaaaaaa00000001");
$put('authors/cy.md', "title: Cyrano Secretname\nstatus: draft\ntranslation_id: cccccccc00000003");
$put('authors/dee.md', "title: Dee Editorial\nstatus: published\ntranslation_id: dddddddd00000004");
$put('works/engine.md', "title: Engine notes\nstatus: published\ntranslation_id: 1111111100000001\ncustom_fields:\n  author: aaaaaaaa00000001");
$put('works/engine.en.md', "title: Engine notes EN\nstatus: published\ntranslation_id: 1111111100000001\ncustom_fields:\n  author: aaaaaaaa00000001");
$put('works/ghost.md', "title: Ghost work\nstatus: published\ntranslation_id: 2222222200000002\ncustom_fields:\n  author: cccccccc00000003");
$put('works/quiet.md', "title: Quiet work\nstatus: published\ntranslation_id: 3333333300000003\ncustom_fields:\n  quiet: aaaaaaaa00000001");
$put('works/row.md', "title: Row work\nstatus: published\ntranslation_id: 4444444400000004\ncustom_fields:\n  editions:\n    - { editor: dddddddd00000004 }");
$put('works/row.en.md', "title: Row work EN\nstatus: published\ntranslation_id: 4444444400000004\ncustom_fields:\n  editions:\n    - { editor: dddddddd00000004 }");
$rsettings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$rtheme = new Theme($rroot);
$rtypes = new ContentTypes($rtheme);
$rrepo = new ContentRepository("$rroot/content", $markdown, $rsettings);
$rdb = new GnozioCMS\SystemDatabase("$rroot/storage");
$rdb->initialize();
$rindex = new GnozioCMS\ContentIndex($rdb, "$rroot/content", new GnozioCMS\SystemMetaRepository($rdb));
$rindex->keepReferences(fn(string $type): array => $rtypes->definition($type, 'en', 'el')['fields']);
$rindex->rebuild($rrepo, ['authors', 'works']);
$rrelations = new GnozioCMS\Relations($rindex, $rrepo, $rtypes, $rsettings, "$rroot/content");
$rmake = function (?string $cache = null) use ($rrepo, $rtheme, $rtypes, $rrelations, $markdown): ContentSearch {
    $docs = new SearchDocument(new BlockRegistry($rtheme), $rtypes, fn($text) => (string)$markdown->convert($text), 'el');
    if ($cache !== null) { $docs->cacheIn($cache, 'rel-test'); }
    return new ContentSearch($rrepo, $docs, $rrelations);
};
$rslugs = fn(array $r): array => array_map(fn($i) => $i->type . '/' . $i->slug, $r['results']);
$rs = $rmake();
check('a work is found by the title of its author', $rslugs($rs->search('lovelace', 'el')), ['authors/ada', 'works/engine']);
check('and in the page\'s language, by the author\'s title there', $rslugs($rs->search('countess', 'en')), ['authors/ada', 'works/engine']);
check('a target with no page in that language shows its title in the site\'s own', $rslugs($rs->search('editorial', 'en')), ['works/row']);
check('and a title of another language is not matched where the target has its own', $rslugs($rs->search('lovelace', 'en')), []);
check('title words and author words are matched together', $rslugs($rs->search('engine lovelace', 'el')), ['works/engine']);
check('a draft target\'s title does not reach visitors', $rslugs($rs->search('secretname', 'el')), []);
check('but a signed-in searcher finds the work by it', $rslugs($rs->search('secretname', 'el', true)), ['authors/cy', 'works/ghost']);
check('searchable: false on the relation field keeps its targets out', $rslugs($rs->search('lovelace', 'el')), ['authors/ada', 'works/engine']);
check('a relation inside a repeater row counts', $rslugs($rs->search('editorial', 'el')), ['authors/dee', 'works/row']);
$hit = $rs->search('lovelace', 'el')['hits'][1];
check('the snippet shows the related title, highlighted', [$hit['item']->slug, implode('', array_column($hit['snippet_parts'], 'text')), array_column(array_filter($hit['snippet_parts'], fn($p) => $p['match']), 'text')], ['engine', 'Ada Lovelace', ['Lovelace']]);
check('the own title still outranks a related one', $rslugs($rs->search('ada', 'el')), ['authors/ada', 'works/engine']);
check('without the relations service nothing changes', $rslugs((new ContentSearch($rrepo, new SearchDocument(new BlockRegistry($rtheme), $rtypes, fn($t) => (string)$markdown->convert($t), 'el')))->search('lovelace', 'el')), ['authors/ada']);

// The extracted-text cache never holds a related title, so changes to a target show on the next search with no invalidation.
$rcache = "$rroot/cache";
check('cached: found by the author', $rslugs($rmake($rcache)->search('lovelace', 'el')), ['authors/ada', 'works/engine']);
$put('authors/ada.md', "title: Ada Byron\nstatus: published\ntranslation_id: aaaaaaaa00000001");
$rrepo->forget();
$rindex->upsert($rrepo->parseFile('authors', "$rroot/content/authors/ada.md"));
check('cached: a renamed author changes the results at once', [$rslugs($rmake($rcache)->search('lovelace', 'el')), $rslugs($rmake($rcache)->search('byron', 'el'))], [[], ['authors/ada', 'works/engine']]);
$put('authors/ada.md', "title: Ada Byron\nstatus: draft\ntranslation_id: aaaaaaaa00000001");
$rrepo->forget();
$rindex->upsert($rrepo->parseFile('authors', "$rroot/content/authors/ada.md"));
check('cached: unpublishing the author hides its title from visitors', [$rslugs($rmake($rcache)->search('byron', 'el')), $rslugs($rmake($rcache)->search('byron', 'el', true))], [[], ['authors/ada', 'works/engine']]);
$cachedFiles = glob("$rcache/*.ser");
$stored = unserialize(file_get_contents($rcache . '/' . hash('sha256', 'works|el') . '.ser'), ['allowed_classes' => false]);
check('the cache holds ids but no title of the target', [$stored['entries']['engine'][1]['relations'], str_contains(serialize($stored), 'Byron')], [['aaaaaaaa00000001'], false]);
exec('rm -rf ' . escapeshellarg($rroot));

$remove = function (string $path) use (&$remove): void {
    if (is_dir($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $remove($path . '/' . $name); } } rmdir($path); }
    else { unlink($path); }
};
$remove($dir);
echo "\n$checks checks, " . ($fail ? "$fail FAILED" : 'ALL PASSED') . "\n";
exit($fail ? 1 : 0);
