<?php
/*
 * Typo tolerance, the search index and the filters for places:
 * - a word that is nowhere on the site finds the site's words a letter away (two for a long word, a swap of neighbours as one),
 *   ranked under the word as typed, highlighted whole, and reported with the spelling of the text; a word that is on the site is
 *   never corrected; short words, numbers and words in drafts a visitor cannot see are not used;
 * - the index (storage/cache/search/index.sqlite) gives the same results as reading every entry, follows edits and deletions, and
 *   keeps search working when it cannot be written;
 * - places can be filtered by area and by category (a parent category includes its children), with counts that follow the other
 *   filters, offered only when the results include places.
 *   php tests/unit/search-typos.php
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnozioCMS\{BlockRegistry, ContentRepository, ContentSearch, ContentTypes, SearchDocument, SearchText, Taxonomies, Theme};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

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

// ---- the distance between two words
$bytes = static fn(string $w): string => implode('', array_map(static fn(string $l): string => chr(0x40 + (mb_ord($l) % 60)), mb_str_split($w)));
check('one letter changed, added or taken out is one edit', [SearchText::distance('hotel', 'hostel'), SearchText::distance('hotel', 'hote'), SearchText::distance('hotel', 'hotal')], [1, 1, 1]);
check('two neighbours swapped are one edit', [SearchText::distance('hotel', 'htoel'), SearchText::distance('hotel', 'hoetl')], [1, 1]);
check('two letters apart swapped are two', SearchText::distance('hotel', 'lothe') > 1, true);

$dir = sys_get_temp_dir() . '/gnozio-search-typos-' . bin2hex(random_bytes(5));
foreach (['pages', 'points', 'posts'] as $type) { mkdir("$dir/content/$type", 0775, true); }
mkdir("$dir/content/taxonomies", 0775, true);
$write = function (string $type, string $slug, string $title, string $body, string $extra = '') use ($dir): void {
    $status = str_contains($extra, 'status:') ? '' : "status: published\n";
    file_put_contents("$dir/content/$type/$slug.md", "---\ntitle: '$title'\n$status$extra---\n\n$body\n");
};
$write('pages', 'hotel', 'Ξενοδοχείο στη θάλασσα', 'Δωμάτια με θέα. Restaurant and garden.');
$write('pages', 'museum', 'Αρχαιολογικό Μουσείο', 'Εκθέματα από την αρχαιότητα. Opening hours every day.');
$write('pages', 'garden', 'Gardens of the island', 'A walk through the botanical gardens.');
$write('pages', 'code', 'Product 12345', 'Catalogue number 12345.');
$write('pages', 'secret', 'Κρυφό', 'Μυστικοπάθεια μόνο εδώ.', "status: draft\n");
$write('pages', 'short', 'Ο δρόμος', 'Μια οδός.');
file_put_contents("$dir/content/taxonomies/categories.yaml", "title: Categories\nhierarchical: true\nterms:\n  - { id: nature, slug: nature, parent: '', labels: { el: Φύση } }\n  - { id: beaches, slug: beaches, parent: nature, labels: { el: Παραλίες } }\n  - { id: culture, slug: culture, parent: '', labels: { el: Πολιτισμός } }\n  - { id: food, slug: food, parent: '', labels: { el: Φαγητό } }\n");
$place = function (string $slug, string $title, string $area, array $categories, string $body = 'Ένα μέρος για κολύμπι.') use ($write): void {
    $write('points', $slug, $title, $body, "categories: [" . implode(', ', $categories) . "]\ncustom_fields:\n  area: '$area'\n  location: '35.2, 26.2'\n");
};
$place('vai', 'Παραλία Βάι', 'Σητεία', ['beaches']);
$place('kouremenos', 'Παραλία Κουρεμένος', 'Παλαίκαστρο', ['beaches']);
$place('xerokampos', 'Παραλία Ξερόκαμπος', 'Ζάκρος', ['beaches']);
$place('gorge', 'Φαράγγι των Νεκρών', 'Ζάκρος', ['nature'], 'Μονοπάτι προς την παραλία και κολύμπι.');
$place('palace', 'Ανάκτορο Ζάκρου', 'Ζάκρος', ['culture'], 'Μινωικό ανάκτορο κοντά στην παραλία, μετά το κολύμπι.');
$place('tavern', 'Ταβέρνα', '  ζάκρος ', ['food'], 'Φαγητό δίπλα στην παραλία μετά το κολύμπι.');
$write('posts', 'swim', 'Κολύμπι τον χειμώνα', 'Ένα άρθρο για κολύμπι.', "categories: [beaches]\n");

$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$markdown = new MarkdownConverter($environment);
$theme = new Theme(dirname(__DIR__, 2), 'default');
$settings = ['languages' => ['default' => 'el'], 'content_types' => ['pages', 'points', 'posts']];
$taxonomies = new Taxonomies("$dir/content", ['el']);
$make = function (?string $cache = "cache/search", array $placeTypes = ['points']) use ($dir, $markdown, $settings, $theme, $taxonomies): ContentSearch {
    $documents = new SearchDocument(new BlockRegistry($theme), new ContentTypes($theme), fn($text) => (string)$markdown->convert($text), 'el');
    if ($cache !== null) { $documents->cacheIn("$dir/$cache", 'typos-test'); }
    $repository = new ContentRepository("$dir/content", $markdown, $settings);
    return new ContentSearch($repository, $documents, null, $placeTypes, $taxonomies);
};
$slugs = static fn(array $result): array => array_map(static fn($item) => $item->slug, $result['results']);
$set = static function (array $result) use ($slugs): array { $found = $slugs($result); sort($found); return $found; };

// ---- typos
$found = $make()->search('ξενοδοχιο', 'el');
check('a misspelt Greek word finds the word on the site', $slugs($found), ['hotel']);
check('and says which word it used, as the text writes it', $found['similar'], ['ξενοδοχιο' => ['Ξενοδοχείο']]);
check('the word found is highlighted whole', array_values(array_filter($found['hits'][0]['title_parts'], fn($p) => $p['match'])), [['text' => 'Ξενοδοχείο', 'match' => true]]);
check('two letters swapped side by side', $slugs($make()->search('μουσίεο', 'el')), ['museum']);
check('a Latin word with a letter missing', $slugs($make()->search('restaurnt', 'el')), ['hotel']);
check('a long word with two mistakes', $slugs($make()->search('αρχαιολογκο μουσειο', 'el')), ['museum']);
check('every other word of the query still has to match', [$slugs($make()->search('ξενοδοχιο μουσειο', 'el')), $slugs($make()->search('ξενοδοχιο θαλασσα', 'el'))], [[], ['hotel']]);
$garden = $make()->search('garden', 'el');
check('a word that is on the site is not corrected', [$slugs($garden), $garden['similar']], [['garden', 'hotel'], []]);
check('so a word inside a longer one is not corrected either', $make()->search('garde', 'el')['similar'], []);
check('a word of four letters is not corrected', $make()->search('οδοσ', 'el')['total'] > 0 && $make()->search('οδρσ', 'el')['total'] === 0, true);
check('numbers are not corrected', [$make()->search('12346', 'el')['total'], $make()->search('12345', 'el')['total']], [0, 1]);
check('a word only a draft has does not help a visitor', $make()->search('μυστικοπαθια', 'el')['total'], 0);
check('someone signed in finds the draft through it', $slugs($make()->search('μυστικοπαθια', 'el', true)), ['secret']);
check('a word that is nowhere and close to nothing finds nothing', $make()->search('ζζζζζζζ', 'el')['total'], 0);
$exact = $make()->search('gardens', 'el');
check('the word as typed ranks above a close word', $slugs($exact)[0], 'garden');
check('without the index there is no correction, and no error', [$make(null)->search('ξενοδοχιο', 'el')['total'], $slugs($make(null)->search('ξενοδοχειο', 'el'))], [0, ['hotel']]);

// ---- the index gives the results of a full read
foreach (['παραλια', 'κολυμπι', 'ζακρο', 'garden', 'opening day', 'ο', 'ο δρομοσ', 'παραλιεσ'] as $query) {
    check("the index and a full read agree: $query", $slugs($make()->search($query, 'el')), $slugs($make(null)->search($query, 'el')));
}
$index = "$dir/cache/search/index.sqlite";
check('the index is one SQLite file', is_file($index), true);
$write('pages', 'museum', 'Αρχαιολογικό Μουσείο', 'Νέα εκθέματα, λιθοτεχνία.');
check('an edited entry is found by its new words at once', $slugs($make()->search('λιθοτεχνια', 'el')), ['museum']);
check('and no longer by the words it lost', $make()->search('opening', 'el')['total'], 0);
unlink("$dir/content/pages/museum.md");
check('a deleted entry is gone at once', $make()->search('λιθοτεχνια', 'el')['total'], 0);
$make()->search('λιθοτεχνια', 'el', true);
$rows = (int)(new PDO('sqlite:' . $index))->query("SELECT COUNT(*) FROM docs WHERE scope = 'pages|el' AND slug = 'museum'")->fetchColumn();
check('a signed-in search forgets it in the index', $rows, 0);
check('and its words are no longer offered as corrections', $make()->search('λιθοτεχνεια', 'el')['similar'], []);
file_put_contents("$dir/not-a-folder", '');
check('an index that cannot be written keeps search working', $slugs($make('not-a-folder/search')->search('ξενοδοχειο', 'el')), ['hotel']);

// ---- places: area and category
$swim = $make()->search('κολυμπι', 'el');
check('all results first', $swim['total'], 7);
check('the areas of the results, most first, one spelling for one area', array_map(fn($a) => [$a['label'], $a['count']], $swim['areas']), [['Ζάκρος', 4], ['Παλαίκαστρο', 1], ['Σητεία', 1]]);
check('the categories of the results in the order of the tree, a parent counting its children', array_map(fn($c) => [$c['value'], $c['label'], $c['count'], $c['depth']], $swim['categories']), [['nature', 'Φύση', 5, 0], ['beaches', 'Παραλίες', 4, 1], ['culture', 'Πολιτισμός', 1, 0], ['food', 'Φαγητό', 1, 0]]);
$zakros = $make()->search('κολυμπι', 'el', false, '', 1, 'ζακροσ');
check('an area filters the results (any spelling of it)', $set($zakros), ['gorge', 'palace', 'tavern', 'xerokampos']);
check('and the categories count only that area', array_map(fn($c) => [$c['value'], $c['count']], $zakros['categories']), [['nature', 2], ['beaches', 1], ['culture', 1], ['food', 1]]);
check('the areas still list the others, the chosen one marked', array_map(fn($a) => [$a['label'], $a['active']], $zakros['areas']), [['Ζάκρος', true], ['Παλαίκαστρο', false], ['Σητεία', false]]);
check('the type counts follow the area', $zakros['counts'], ['points' => 4]);
$nature = $make()->search('κολυμπι', 'el', false, '', 1, '', 'nature');
check('a parent category includes the entries of its children, of every type', $set($nature), ['gorge', 'kouremenos', 'swim', 'vai', 'xerokampos']);
check('area and category together', $slugs($make()->search('κολυμπι', 'el', false, '', 1, 'Ζάκρος', 'beaches')), ['xerokampos']);
check('with a type', $slugs($make()->search('κολυμπι', 'el', false, 'posts', 1, '', 'beaches')), ['swim']);
$none = $make()->search('κολυμπι', 'el', false, '', 1, 'Αθήνα');
check('an area no result has: no results, and it can be cleared', [$none['total'], array_map(fn($a) => [$a['label'], $a['count'], $a['active']], array_slice($none['areas'], -1))], [0, [['Αθήνα', 0, true]]]);
check('unknown and malformed filters are ignored', [$make()->search('κολυμπι', 'el', false, '', 1, ['x'], ['y'])['total'], $make()->search('κολυμπι', 'el', false, '', 1, '', '<b>')['total']], [7, 0]);
$pagesOnly = $make()->search('garden', 'el');
check('no place filters when no result is a place', [$pagesOnly['areas'], $pagesOnly['categories']], [[], []]);
check('nor on a site without place types', $make('cache/search', [])->search('κολυμπι', 'el')['areas'], []);
$one = $make()->search('βαι', 'el');
check('nor when no choice would leave a result out (one area, a category and its parent)', [$one['areas'], $one['categories']], [[], []]);

$remove = function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $remove($path . '/' . $name); } } rmdir($path); }
    else { unlink($path); }
};
$remove($dir);
echo "\n$checks checks, " . ($fail ? "$fail FAILED" : 'ALL PASSED') . "\n";
exit($fail ? 1 : 0);
