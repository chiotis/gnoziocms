<?php
/*
 * Translations: the theme's words as the admin edits them (overrides kept apart from the theme), the translations of
 * an entry as the admin lists them, and the other languages of a page (language switcher and hreflang addresses).
 *   php tests/unit/translations.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentRepository, EntryTranslations, LanguageAlternates, Taxonomies, Theme, ThemeStrings};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/trans' . getmypid();
mkdir("$dir/themes/default/lang", 0775, true);
foreach (['pages', 'posts', 'forms'] as $d) { mkdir("$dir/content/$d", 0775, true); }
file_put_contents("$dir/themes/default/lang/el.php", "<?php return ['read_more' => 'Διαβάστε περισσότερα', 'nav.main.home' => 'Αρχική', 'footer.note' => 'Σημείωση'];\n");
file_put_contents("$dir/themes/default/lang/en.php", "<?php return ['read_more' => 'Read more', 'nav.main.home' => 'Home'];\n");

// ---- the theme's words
$theme = new Theme($dir, 'default');
$strings = new ThemeStrings($theme);
check('menu labels are never shown on the screen', [ThemeStrings::isHidden('nav.main.home'), ThemeStrings::isHidden('read_more')], [true, false]);
check('visible keeps the rest, as text', ThemeStrings::visible(['nav.x' => 'a', 'k' => 5, 7 => 'seven']), ['k' => '5', '7' => 'seven']);

$s = $strings->screen('el', 'el');
check('the default language shows the theme words without menu labels', $s['translations'], ['footer.note' => 'Σημείωση', 'read_more' => 'Διαβάστε περισσότερα']);
check('nothing is customised yet', [$s['customized'], $s['custom_file']], [[], 'custom/lang/el.yaml']);
$s = $strings->screen('en', 'el');
check('another language inherits what it lacks from the default language', $s['translations'], ['footer.note' => 'Σημείωση', 'read_more' => 'Read more']);
check('and the defaults column is the default language', $s['defaults']['read_more'], 'Διαβάστε περισσότερα');

$r = $strings->save('en', 'el', ['keys' => ['read_more', 'footer.note', 'nav.main.home', ' ', 'brand'], 'values' => ['Learn more', 'Σημείωση', 'Hacked', 'x', 'Faros']]);
check('saving reports success and how many overrides there are', $r, ['ok' => true, 'overrides' => 2]);
check('only what differs from the theme is kept, menu labels and blank keys never', $theme->customTranslations('en'), ['brand' => 'Faros', 'read_more' => 'Learn more']);
check('the file says what it is for', str_contains((string)file_get_contents("$dir/custom/lang/en.yaml"), 'Kept across updates'), true);
$s = $strings->screen('en', 'el');
check('the screen shows the override and marks it', [$s['translations']['read_more'], $s['customized']], ['Learn more', ['brand', 'read_more']]);
check('the site uses it', $theme->translations('en', 'el')['read_more'], 'Learn more');
$strings->save('en', 'el', ['keys' => ['read_more', 'brand'], 'values' => ['Read more', 'Faros'], 'reset' => ['brand']]);
check('a value set back to the theme, or ticked for reset, is no longer an override', $theme->customTranslations('en'), []);
check('with none left the file is removed', is_file("$dir/custom/lang/en.yaml"), false);
$strings->save('en', 'el', ['keys' => ['brand'], 'values' => ['Faros']]);
$strings->save('en', 'el', ['keys' => ['read_more'], 'values' => ['Learn more']]);
check('a key the form leaves out keeps its override', $theme->customTranslations('en'), ['brand' => 'Faros', 'read_more' => 'Learn more']);
check('junk in the form is harmless', $strings->save('en', 'el', ['keys' => 'x', 'values' => 5, 'reset' => 'y']), ['ok' => true, 'overrides' => 2]);
// A theme folder that sits below a file cannot be made, not even by root (a path that is only missing can be made by root).
$notAFolder = "$dir/not-a-folder";
file_put_contents($notAFolder, 'x');
$blocked = new ThemeStrings(new Theme("$notAFolder/theme", 'default'));
check('a folder that cannot be written is reported', $blocked->save('en', 'el', ['keys' => ['a'], 'values' => ['b']])['ok'], false);

// ---- the screen: areas, states, progress
file_put_contents("$dir/themes/default/lang/el.php", "<?php return ['read_more' => 'Διαβάστε περισσότερα', 'nav.main.home' => 'Αρχική', 'footer.note' => 'Σημείωση', 'footer.legal' => 'Νομικά', 'form.error.required' => 'Υποχρεωτικό', 'longtext' => '" . str_repeat('α', 90) . "'];\n");
file_put_contents("$dir/themes/default/lang/en.php", "<?php return ['read_more' => 'Read more', 'nav.main.home' => 'Home', 'footer.note' => 'Σημείωση', 'footer.legal' => 'Legal'];\n");
$theme = new Theme($dir, 'default');
$strings = new ThemeStrings($theme);
$strings->save('en', 'el', ['keys' => ['brand', 'read_more'], 'values' => ['', ''], 'reset' => ['brand', 'read_more']]);
$o = $strings->overview('en', 'el', ['el', 'en']);
check('the strings are in areas, the ones without a dot first, then by key', array_map(fn($r) => $r['key'], $o['rows']), ['longtext', 'read_more', 'footer.legal', 'footer.note', 'form.error.required']);
check('a key with no dot is in "general"', [ThemeStrings::group('read_more'), ThemeStrings::group('footer.note'), ThemeStrings::group('a.b.c'), ThemeStrings::groupLabel('general'), ThemeStrings::groupLabel('before_after')], ['general', 'footer', 'a', 'General', 'Before after']);
check('the areas, with their counts', array_map(fn($g) => [$g['label'], $g['count']], $o['groups']), ['general' => ['General', 2], 'footer' => ['Footer', 2], 'form' => ['Form', 1]]);
$by = array_column($o['rows'], null, 'key');
check('a string is translated, still the source language text, or missing', [$by['read_more']['status'], $by['footer.note']['status'], $by['form.error.required']['status']], ['translated', 'source', 'source']);
check('the source, the value, long ones', [$by['read_more']['source'], $by['read_more']['value'], $by['longtext']['long'], $by['read_more']['long']], ['Διαβάστε περισσότερα', 'Read more', true, false]);
check('the counts of the filters', $o['stats'], ['total' => 5, 'translated' => 2, 'missing' => 0, 'source' => 3, 'custom' => 0]);
check('how far each language is translated (the source language is whole)', $o['progress'], ['el' => 100, 'en' => 40]);
$strings->save('en', 'el', ['keys' => ['footer.note', 'mine.custom'], 'values' => ['Note', 'Mine']]);
$o = $strings->overview('en', 'el', ['el', 'en']);
$by = array_column($o['rows'], null, 'key');
check('a string the site changed is customized, one it added is its own', [$by['footer.note']['custom'], $by['footer.note']['status'], $by['mine.custom']['own'], $by['mine.custom']['custom'], $by['read_more']['own']], [true, 'translated', true, true, false]);
check('and counted', [$o['stats']['custom'], $o['stats']['total'], $o['progress']['en']], [2, 6, 66]);
$strings->save('en', 'el', ['keys' => ['footer.legal'], 'values' => ['']]);
check('a string emptied is missing', array_column($strings->overview('en', 'el', ['el', 'en'])['rows'], 'status', 'key')['footer.legal'], 'missing');
check('the default language is not "the same as the source"', array_unique(array_map(fn($r) => $r['status'], $strings->overview('el', 'el', ['el', 'en'])['rows'])), ['translated']);
check('a language the site does not list is still measured', array_keys($strings->overview('en', 'el', ['el'])['progress']), ['el', 'en']);
$strings->save('en', 'el', ['keys' => ['footer.note', 'mine.custom', 'footer.legal'], 'values' => ['Σημείωση', 'x', 'Legal'], 'reset' => ['mine.custom']]);

// ---- entries in several languages
$settings = ['title' => 'Site', 'base_url' => 'https://s.test', 'languages' => ['default' => 'el', 'available' => ['el', 'en', 'de']], 'home_page' => 'index'];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$page = fn(string $file, string $title, string $extra = '', string $status = 'published') => file_put_contents("$dir/content/pages/$file", "---\ntitle: $title\nstatus: $status\n$extra---\nBody\n");
$page('index.md', 'Αρχική', "translation_id: home1\n");
$page('index.en.md', 'Home', "translation_id: home1\n");
$page('about.md', 'Σχετικά', "translation_id: about1\n");
$page('about.en.md', 'About', "translation_id: about1\n");
$page('about.de.md', 'Über uns', "translation_id: about1\n", 'draft');
$page('team.md', 'Ομάδα', "translation_id: team1\n");
$page('team-en.en.md', 'Team', "translation_id: team1\n"); // same entry, different address
$page('old.md', 'Παλιό'); // no translation_id
$page('old.en.md', 'Old');
$page('lonely.md', 'Μόνο');
file_put_contents("$dir/content/posts/hello.md", "---\ntitle: Γεια\nstatus: published\ntags: [news]\ntranslation_id: post1\n---\nP\n");
file_put_contents("$dir/content/posts/hello.en.md", "---\ntitle: Hello\nstatus: published\ntags: [news]\ntranslation_id: post1\n---\nP\n");

$tr = new EntryTranslations($content, fn() => $settings);
$items = $content->getItems('pages', 'el', true, false);
$matrix = $tr->matrix('pages', $items);
check('each entry lists the other languages it exists in', [$matrix['about|el'], $matrix['team|el'], $matrix['lonely|el']], [['de', 'en'], ['en'], []]);
check('entries without a translation id are matched by address', $matrix['old|el'], ['en']);
check('the matrix is keyed by address and language', array_keys($matrix), array_map(fn($i) => $i->slug . '|' . $i->lang, $items));

$rows = $tr->links('pages', 'about', 'about1');
check('one row per language of the site', array_column($rows, 'lang'), ['el', 'en', 'de']);
check('a translation that exists links to its editor', [$rows[1]['exists'], $rows[1]['title'], $rows[1]['edit_url'], $rows[1]['create_url']], [true, 'About', '/admin/edit?type=pages&slug=about&lang=en', '']);
check('drafts count as existing in the editor', [$rows[2]['exists'], $rows[2]['title']], [true, 'Über uns']);
$rows = $tr->links('pages', 'team', 'team1');
check('a translation at another address is found by its id and linked there', [$rows[1]['exists'], $rows[1]['slug'], $rows[1]['edit_url']], [true, 'team-en', '/admin/edit?type=pages&slug=team-en&lang=en']);
check('a missing one offers to start it, keeping the group', [$rows[2]['exists'], $rows[2]['edit_url'], $rows[2]['create_url']], [false, '', '/admin/edit?type=pages&slug=team&lang=de&translation_id=team1']);
$rows = $tr->links('pages', 'old', '');
check('without an id the same address is the match', [$rows[0]['exists'], $rows[1]['exists'], $rows[2]['exists']], [true, true, false]);
check('a new entry has only the language being written', array_column(array_filter($tr->links('pages', 'brand-new', 'fresh1'), fn($r) => $r['exists']), 'lang'), []);

// ---- the other languages of a public page
$taxonomies = new Taxonomies("$dir/content", ['el', 'en', 'de']);
$taxonomies->ensureDefaults();
$taxonomies->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News', 'de' => '']], ['id' => 'empty', 'slug' => 'empty', 'labels' => ['el' => 'Κενό', 'en' => '', 'de' => '']]]);
$abs = function (string $path) { return rtrim('https://s.test', '/') . ($path === '' || $path === '/' ? '/' : '/' . ltrim($path, '/')); };
$alt = new LanguageAlternates($content, fn() => $taxonomies, fn() => $settings, $abs);

$about = $content->find('pages', 'about', 'el', true, false);
check('the switcher links each published translation, by path', $alt->languageLinks('pages', $about), ['el' => 'about', 'en' => 'en/about', 'de' => 'de/about']);
$team = $content->find('pages', 'team', 'el', true, false);
check('a translation at another address is linked at its own', $alt->languageLinks('pages', $team), ['el' => 'team', 'en' => 'en/team-en']);
$home = $content->find('pages', 'index', 'el', true, false);
check('the home page is the language root', $alt->languageLinks('pages', $home), ['el' => '', 'en' => 'en']);
$old = $content->find('pages', 'old', 'el', true, false);
check('without an id the same address is used', $alt->languageLinks('pages', $old), ['el' => 'old', 'en' => 'en/old']);
$post = $content->find('posts', 'hello', 'el', true, false);
check('a post has its address at the root, as a page does', $alt->languageLinks('posts', $post), ['el' => 'hello', 'en' => 'en/hello']);

check('hreflang addresses are complete and only for published translations', $alt->forItem('pages', 'about'), ['urls' => ['el' => 'https://s.test/about', 'en' => 'https://s.test/en/about'], 'default' => 'https://s.test/about']);
// A language with no translation shows the default language's entry, so it is listed too (the draft German 'about' is not).
check('the home page is the site address, and each language root', $alt->forItem('pages', 'index')['urls'], ['el' => 'https://s.test/', 'en' => 'https://s.test/en', 'de' => 'https://s.test/de']);
check('an entry that exists in no language has none', $alt->forItem('pages', 'ghost'), ['urls' => [], 'default' => '']);
check('a page missing in the default language has no default address', $alt->forItem('pages', 'team-en'), ['urls' => ['en' => 'https://s.test/en/team-en'], 'default' => '']);
check('a content type list exists where it has entries', $alt->forArchive('posts'), ['urls' => ['el' => 'https://s.test/posts', 'en' => 'https://s.test/en/posts', 'de' => 'https://s.test/de/posts'], 'default' => 'https://s.test/posts']);
check('a type with none has no addresses', $alt->forArchive('projects'), ['urls' => [], 'default' => '']);
check('a tag page exists in the languages that have entries under it', $alt->forTaxonomy('tags', 'news'), ['urls' => ['el' => 'https://s.test/tag/news', 'en' => 'https://s.test/en/tag/news', 'de' => 'https://s.test/de/tag/news'], 'default' => 'https://s.test/tag/news']);
check('a tag nothing uses has none', $alt->forTaxonomy('tags', 'empty'), ['urls' => [], 'default' => '']);
check('an unknown tag has none', $alt->forTaxonomy('tags', 'nope')['urls'], []);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
