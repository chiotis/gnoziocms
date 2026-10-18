<?php
/*
 * The feed: what each feed address lists, the document it makes, the choices that switch it off, and the addresses that mean a
 * feed or the page of a list the way WordPress wrote it.
 *   php tests/unit/feed.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentRepository, Feed, FrontRoute, SeoSettings, Taxonomies};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/feedtest' . getmypid();
foreach (['pages', 'posts', 'projects', 'forms', 'taxonomies'] as $d) { mkdir("$dir/content/$d", 0775, true); }
$settings = ['title' => 'Site & Co', 'tagline' => 'Words', 'base_url' => 'https://s.test', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'content_types' => ['pages', 'posts', 'projects', 'forms']];
$write = function (string $type, string $name, string $text, int $mtime = 0) use ($dir): void { $p = "$dir/content/$type/$name.md"; file_put_contents($p, $text); if ($mtime > 0) { touch($p, $mtime); } };
$write('pages', 'index', "---\ntitle: Home\nstatus: published\n---\n\nx\n");
$write('posts', 'old', "---\ntitle: Old\nstatus: published\ndate: '2025-01-01'\ncategories: [world]\ntags: [news]\nexcerpt: 'The <b>short</b> text'\n---\n\nLong body.\n", 1700000300);
$write('posts', 'new', "---\ntitle: 'New & shiny'\nstatus: published\ndate: '2026-02-01'\ncategories: [world]\n---\n\nFirst paragraph with a [link](https://x.test) in it.\n\n```block\ntype: hero\ntitle: Hidden code\n```\n\nLast.\n", 1700000400);
$write('posts', 'long', "---\ntitle: Long\nstatus: published\ndate: '2026-03-01'\n---\n\n" . str_repeat('word ', 200) . "\n", 1700000450);
$write('posts', 'old.en', "---\ntitle: Old EN\nstatus: published\ndate: '2025-01-02'\n---\n\nEnglish.\n", 1700000310);
$write('posts', 'draft', "---\ntitle: Draft\nstatus: draft\ndate: '2026-04-01'\n---\n\nx\n");
$write('posts', 'hidden', "---\ntitle: Hidden\nstatus: published\nvisible: false\ndate: '2026-04-02'\n---\n\nx\n");
$write('projects', 'proj', "---\ntitle: Proj\nstatus: published\ndate: '2025-06-01'\ntags: [news]\n---\n\nProject text.\n", 1700000500);
$write('forms', 'contact', "---\ntitle: Contact\nstatus: published\n---\n\n");
file_put_contents("$dir/content/taxonomies/tags.yaml", "title: Tags\nterms:\n  - id: news\n    slug: news\n    labels: {el: Νέα, en: News}\n    descriptions: {el: Όλα τα νέα.}\n");
file_put_contents("$dir/content/taxonomies/categories.yaml", "title: Categories\nterms:\n  - id: world\n    slug: world\n    labels: {el: Κόσμος, en: World}\n");
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$make = function (array $site) use ($content, $taxonomies): Feed {
    return new Feed($content, fn() => $site, fn(string $path): string => 'https://s.test/' . ltrim($path, '/'), fn() => $taxonomies, fn(string $name, string $id, string $prefix): string => $prefix . Taxonomies::kind($name) . '/' . $id);
};
$feed = $make($settings);
$titles = fn(?array $scope): array => $scope === null ? [] : array_map(fn($i) => $i->meta['title'], $scope['items']);

// ---- what a feed lists
check('the site feed lists the published posts, newest first, in the language of the address', $titles($feed->scope([], 'el')), ['Long', 'New & shiny', 'Old']);
check('another language lists its own', $titles($feed->scope([], 'en')), ['Old EN']);
check('a type has a feed of its own', $titles($feed->scope(['projects'], 'el')), ['Proj']);
check('pages and forms have none, nor does a type that does not exist', [$feed->scope(['pages'], 'el'), $feed->scope(['forms'], 'el'), $feed->scope(['nothing'], 'el')], [null, null, null]);
check('a category lists what is filed under it', $titles($feed->scope(['category', 'world'], 'el')), ['New & shiny', 'Old']);
check('a tag lists entries of every type', $titles($feed->scope(['tag', 'news'], 'el')), ['Proj', 'Old']);
check('a term that does not exist has no feed', $feed->scope(['category', 'nope'], 'el'), null);
$scope = $feed->scope(['tag', 'news'], 'el');
check('the feed of a term is named after it and holds its description and address', [$scope['title'], $scope['description'], $scope['path']], ['Site & Co » Νέα', 'Όλα τα νέα.', 'tag/news']);
check('the site feed is named after the site, with its tagline and the language prefix', [$feed->scope([], 'el')['title'], $feed->scope([], 'el')['description'], $feed->scope([], 'en')['path']], ['Site & Co', 'Words', 'en']);
check('a site with posts has a site feed to announce, unless the feed is off', [$feed->hasSiteFeed(), $make($settings + ['seo' => ['feed' => ['enabled' => false]]])->hasSiteFeed()], [true, false]);

// ---- the document
$xml = $feed->xml($feed->scope([], 'el'), 'el', 'feed');
$doc = new DOMDocument();
check('it is XML', $doc->loadXML($xml) !== false, true);
$x = new DOMXPath($doc);
$x->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
check('RSS 2.0 with the channel named, linked and in a language', [$doc->documentElement->getAttribute('version'), $x->evaluate('string(//channel/title)'), $x->evaluate('string(//channel/link)'), $x->evaluate('string(//channel/language)')], ['2.0', 'Site & Co', 'https://s.test/', 'el']);
check('it points to itself', $x->evaluate('string(//channel/atom:link/@href)'), 'https://s.test/feed');
check('each entry has its title, address and date', [$x->evaluate('string(//item[2]/title)'), $x->evaluate('string(//item[2]/link)'), $x->evaluate('string(//item[2]/guid)'), $x->evaluate('string(//item[2]/pubDate)')], ['New & shiny', 'https://s.test/new', 'https://s.test/new', gmdate(DATE_RSS, strtotime('2026-02-01'))]);
check('the build date is the newest entry\'s', $x->evaluate('string(//channel/lastBuildDate)'), gmdate(DATE_RSS, strtotime('2026-03-01')));
check('the categories of an entry are named in the language of the feed', $x->evaluate('string(//item[3]/category)'), 'Κόσμος');
check('an excerpt is the description, as plain text', $x->evaluate('string(//item[3]/description)'), 'The short text');
$summary = $x->evaluate('string(//item[2]/description)');
check('without an excerpt it is the start of the text, without the code of blocks', [str_contains($summary, 'First paragraph with a link in it.'), str_contains($summary, 'Hidden code'), str_contains($summary, 'Last.')], [true, false, true]);
$long = $x->evaluate('string(//item[1]/description)');
check('a long text is cut at a word with an ellipsis', [mb_strlen($long) <= 301, str_ends_with($long, 'word…')], [true, true]);
check('the ampersand of a title is written as XML needs', str_contains($xml, 'New &amp; shiny'), true);
check('drafts and hidden entries are not in it', [str_contains($xml, 'Draft'), str_contains($xml, 'Hidden')], [false, false]);

// ---- the choices
check('the defaults: on, twenty entries', [SeoSettings::from([])['feed']['enabled'], SeoSettings::from([])['feed']['items']], [true, 20]);
check('a feed can be switched off', $make($settings + ['seo' => ['feed' => ['enabled' => false]]])->enabled(), false);
check('and a site that asked to stay out of search has none', $make($settings + ['seo' => ['discourage' => true]])->enabled(), false);
check('the number of entries is held to 5–100', [SeoSettings::from(['seo' => ['feed' => ['items' => 1]]])['feed']['items'], SeoSettings::from(['seo' => ['feed' => ['items' => 5000]]])['feed']['items'], SeoSettings::from(['seo' => ['feed' => ['items' => 'x']]])['feed']['items'], SeoSettings::from(['seo' => ['feed' => 'no']])['feed']], [5, 100, 20, ['enabled' => true, 'items' => 20]]);
for ($i = 1; $i <= 8; $i++) { $write('posts', "n$i", "---\ntitle: N$i\nstatus: published\ndate: '2026-05-0$i'\n---\n\nx\n"); }
$content2 = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$limited = new Feed($content2, fn() => $settings + ['seo' => ['feed' => ['items' => 5]]], fn($p) => 'https://s.test/' . $p, fn() => $taxonomies, fn() => '');
$cut = new DOMDocument(); $cut->loadXML($limited->xml($limited->scope([], 'el'), 'el', 'feed'));
check('a feed holds no more entries than it was told to', $cut->getElementsByTagName('item')->length, 5);
$saved = SeoSettings::apply([], 'crawling', ['feed_enabled' => '1', 'feed_items' => '30'], []);
check('the crawling tab saves the feed', $saved['feed'], ['enabled' => true, 'items' => 30]);
check('a box that is not sent is off', SeoSettings::apply([], 'crawling', ['feed_items' => '10'], [])['feed'], ['enabled' => false, 'items' => 10]);
check('another tab leaves the feed as it was', SeoSettings::apply(['feed' => ['enabled' => false, 'items' => 50]], 'social', [], [])['feed'], ['enabled' => false, 'items' => 50]);

// ---- the addresses
$route = fn(string $path, bool $feeds = true): array => FrontRoute::resolve($path, $settings, ['pages', 'posts', 'projects', 'forms'], [], $feeds);
$r = $route('feed');
check('/feed is the site\'s feed', [$r['kind'], $r['segments'], $r['lang']], ['feed', [], 'el']);
$r = $route('en/feed');
check('and in another language', [$r['kind'], $r['segments'], $r['lang'], $r['lang_prefix']], ['feed', [], 'en', 'en/']);
$r = $route('projects/feed');
check('a type\'s', [$r['kind'], $r['segments']], ['feed', ['projects']]);
$r = $route('category/world/feed');
check('a term\'s', [$r['kind'], $r['segments']], ['feed', ['category', 'world']]);
check('no feed of the list of terms, of a page or of a deeper address', [$route('category/feed')['kind'], $route('about/feed')['kind'], $route('tag/news/x/feed')['kind'], $route('nothing/feed')['kind']], ['taxonomy', 'not_found', 'feed', 'not_found']);
// A deeper address is the feed route (the term is looked up by the caller); a child term has its own feed and pages.
$r = $route('category/books/english/feed');
check('a child term\'s feed', [$r['kind'], $r['segments']], ['feed', ['category', 'books', 'english']]);
check('a child term is a taxonomy page, and a page of its list is ?page=', [$route('category/books/english')['kind'], $route('category/books/english/page/2')['kind'], $route('category/books/english/page/2')['location'], $route('en/category/books/english/page/3')['location']], ['taxonomy', 'legacy_page', '/category/books/english?page=2', '/en/category/books/english?page=3']);
check('with feeds off an address ending in feed is read as before', [$route('feed', false)['kind'], $route('projects/feed', false)['kind']], ['page', 'entry']);
$r = $route('category/world/page/2');
check('WordPress\'s second page of a category goes to ?page=2', [$r['kind'], $r['location']], ['legacy_page', '/category/world?page=2']);
check('its first page goes to the list itself', $route('category/world/page/1')['location'], '/category/world');
check('also for a type and for another language', [$route('posts/page/3')['location'], $route('en/tag/news/page/12')['location']], ['/posts?page=3', '/en/tag/news?page=12']);
check('an address that is not a list stays not found', [$route('about/page/2')['kind'], $route('nothing/page/2')['kind'], $route('posts/page/x')['kind'], $route('posts/page/0')['kind'], $route('page/2')['kind']], ['not_found', 'not_found', 'not_found', 'not_found', 'not_found']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exec('rm -rf ' . escapeshellarg($dir));
exit($fail === 0 ? 0 : 1);
