<?php
/*
 * Greek inflections in public search: a word is found in its other forms («πόλη» finds «πόλεις», «πόλης», «πόλεων») by the stem and one
 * of the usual endings, ranked under the word as typed, highlighted whole, and kept from running into other words that merely share
 * its letters. Latin text and short words are not stemmed.
 *   php tests/unit/search-stems.php
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnozioCMS\{BlockRegistry, ContentRepository, ContentSearch, ContentTypes, SearchDocument, SearchText, Theme};
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
$n = static fn(string $text): string => SearchText::normalize($text);

// ---- the stem
check('forms of one word have one stem', array_map(fn($w) => SearchText::stem($n($w)), ['πόλη', 'πόλης', 'πόλεις', 'πόλεων']), ['πολ', 'πολ', 'πολ', 'πολ']);
check('so do these', [SearchText::stem($n('βιβλίο')), SearchText::stem($n('βιβλία')), SearchText::stem($n('βιβλίου'))], ['βιβλ', 'βιβλ', 'βιβλ']);
check('and a noun in -ος and a feminine in -α', [SearchText::stem($n('δρόμος')), SearchText::stem($n('δρόμους')), SearchText::stem($n('θάλασσα')), SearchText::stem($n('θάλασσες'))], ['δρομ', 'δρομ', 'θαλασσ', 'θαλασσ']);
check('a word of under four letters is not stemmed', [SearchText::stem('πολη') !== '', SearchText::stem('για'), SearchText::stem('και'), SearchText::stem('ουσ')], [true, '', '', '']);
check('nor is a word whose stem would be under three letters', [SearchText::stem($n('ωρα')), SearchText::stem($n('ομοι'))], ['', '']);
check('nor Latin or mixed text, or a word with no usual ending', [SearchText::stem('cities'), SearchText::stem('athens'), SearchText::stem('πολιτικ2'), SearchText::stem($n('μαγαζί')) !== '', SearchText::stem($n('πολιτικός')) !== ''], ['', '', '', true, true]);
check('a word that ends otherwise has no stem', SearchText::stem($n('καφές')), 'καφ' === SearchText::stem($n('καφές')) ? 'καφ' : '');

// ---- another form of the word, not any word that starts like it
$text = $n('Η πόλη της Αθήνας και οι πόλεις του νησιού');
check('the other forms are found', [SearchText::inflected($text, 'πολη'), SearchText::inflected($n('οι πόλεις'), 'πολη'), SearchText::inflected($n('των πόλεων'), 'πολη'), SearchText::inflected($n('της πόλης'), 'πολεισ')], [true, true, true, true]);
check('and the same word, which is the word as typed and not this', SearchText::inflected($n('η πόλη'), 'πολη'), true);
check('a word that only starts like the stem is not', array_map(fn($w) => SearchText::inflected($n($w), 'πολη'), ['πόλεμος', 'πολιτεία', 'πολύ', 'πολλά', 'πολιτισμός', 'Πολωνία', 'μεγαλούπολη']), [false, false, false, false, false, false, false]);
check('a word that ends with the stem is not either', SearchText::inflected($n('Θεσσαλονίκη μητρόπολη'), 'πολη'), false);
check('a term with no stem has no other forms', [SearchText::inflected('cities', 'city'), SearchText::inflected($n('γιες γιος'), 'για')], [false, false]);
check('a short term has none', SearchText::inflected($n('πόλη'), $n('ο')), false);

// ---- highlighting takes the whole word
$parts = SearchText::parts('Οι πόλεις του νησιού και η πόλη.', ['πολη']);
check('every form is highlighted whole, with its accent', array_column(array_filter($parts, fn($p) => $p['match']), 'text'), ['πόλεις', 'πόλη']);
check('and the text is all there', implode('', array_column($parts, 'text')), 'Οι πόλεις του νησιού και η πόλη.');
check('a word that merely starts with the stem is not highlighted', array_column(array_filter(SearchText::parts('Ο πόλεμος και η πόλη', ['πολη']), fn($p) => $p['match']), 'text'), ['πόλη']);
$long = str_repeat('Πρόλογος χωρίς το ζητούμενο. ', 30) . 'Στο τέλος βρίσκονται οι πόλεις του νησιού. ' . str_repeat('Επίλογος. ', 25);
check('a snippet is cut around another form of the word', str_contains(SearchText::snippet($long, ['πολη']), 'πόλεις'), true);

// ---- through the search
$dir = sys_get_temp_dir() . '/search-stems' . getmypid();
foreach (['pages', 'posts'] as $type) { mkdir("$dir/content/$type", 0775, true); }
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$write = static function (string $type, string $slug, string $title, string $body) use ($dir): void {
    file_put_contents("$dir/content/$type/$slug.md", "---\ntitle: '$title'\nstatus: published\n---\n\n$body\n");
};
$write('pages', 'exact', 'Η πόλη', 'Κείμενο.');
$write('pages', 'plural', 'Οι πόλεις', 'Κείμενο.');
$write('pages', 'genitive', 'Ιστορία της πόλης', 'Κείμενο.');
$write('pages', 'body', 'Ταξίδι', 'Επισκεφθήκαμε τρεις πόλεις το καλοκαίρι.');
$write('pages', 'war', 'Ο πόλεμος', 'Πολιτεία και πολιτισμός.');
$write('pages', 'latin', 'Cities', 'City life.');
$write('posts', 'books', 'Βιβλία', 'Τα καλύτερα βιβλία της χρονιάς.');
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$markdown = new MarkdownConverter($environment);
$theme = new Theme(dirname(__DIR__, 2), 'default');
$documents = new SearchDocument(new BlockRegistry($theme), new ContentTypes($theme), fn($text) => (string)$markdown->convert($text), 'el');
$repository = new ContentRepository("$dir/content", $markdown, ['languages' => ['default' => 'el'], 'content_types' => ['pages', 'posts']]);
$repository->storeParsedIn("$dir/cache", 'search-stems');
$search = new ContentSearch($repository, $documents);
$slugs = static fn(array $result): array => array_map(fn($item) => $item->slug, $result['results']);

check('the singular finds the plural, the genitive and the plural in the text', $slugs($search->search('πόλη', 'el')), ['exact', 'genitive', 'plural', 'body']);
check('the plural finds the singular', $slugs($search->search('πόλεις', 'el')), ['plural', 'exact', 'genitive', 'body']);
check('with accents, capitals and final sigma as typed', $slugs($search->search('ΠΌΛΗΣ', 'el')), ['genitive', 'exact', 'plural', 'body']);
check('the word as typed ranks above the other forms in the same field', array_slice($slugs($search->search('πόλεις', 'el')), 0, 2), ['plural', 'exact']);
check('a title with another form ranks above the same form in the text', array_search('exact', $slugs($search->search('πόλη', 'el'))) < array_search('body', $slugs($search->search('πόλη', 'el'))), true);
check('words that only start like the term are not found', in_array('war', $slugs($search->search('πόλη', 'el')), true), false);
check('another word with its own forms: books', $slugs($search->search('βιβλίο', 'el')), ['books']);
check('every word of the query still has to be found, in some form', [$slugs($search->search('πόλη ταξίδι', 'el')), $slugs($search->search('πόλη nothinghere', 'el'))], [['body'], []]);
check('Latin is not stemmed', [$slugs($search->search('city', 'el')), $slugs($search->search('citie', 'el'))], [['latin'], ['latin']]);
$hit = $search->search('πόλη', 'el')['hits'][0];
check('the title of a hit is highlighted whole', array_column(array_filter($hit['title_parts'], fn($p) => $p['match']), 'text'), ['πόλη']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
