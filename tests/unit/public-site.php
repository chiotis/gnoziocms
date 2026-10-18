<?php
/*
 * What visitors get that is not an entry's own page: the sitemap, the page of a category or tag, forms (being sent,
 * shown, placed in text by a shortcode), and the template functions that depend on nothing about the request.
 *   php tests/unit/public-site.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ArchiveBuilder, ContentItem, ContentRepository, FormProcessor, FormSubmissionRepository, Images, LanguageAlternates, PublicForms, Sitemap, StructuredData, Taxonomies, TaxonomyPage, Theme, TwigFunctions};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Twig\Environment as Twig;
use Twig\Loader\ArrayLoader;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = dirname(__DIR__, 2);
$dir = sys_get_temp_dir() . '/pubsite' . getmypid();
foreach (['pages', 'posts', 'projects', 'forms', 'taxonomies'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/public", 0775, true);
$settings = ['title' => 'Site', 'base_url' => 'https://s.test', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'content_types' => ['pages', 'posts', 'projects', 'forms']];
$write = function (string $type, string $name, string $text, int $mtime = 0) use ($dir): void { $p = "$dir/content/$type/$name.md"; file_put_contents($p, $text); if ($mtime > 0) { touch($p, $mtime); } };
$write('pages', 'index', "---\ntitle: Home\nstatus: published\n---\n\nx\n", 1700000000);
$write('pages', 'about', "---\ntitle: About\nstatus: published\n---\n\nx\n", 1700000100);
$write('pages', 'about.en', "---\ntitle: About EN\nstatus: published\n---\n\nx\n", 1700000200);
$write('pages', 'draft', "---\ntitle: Draft\nstatus: draft\n---\n\nx\n");
$write('posts', 'old', "---\ntitle: Old\nstatus: published\ndate: '2025-01-01'\ntags: [news]\n---\n\nx\n", 1700000300);
$write('posts', 'new', "---\ntitle: New\nstatus: published\ndate: '2026-02-01'\ntags: [news]\ncategories: [world]\n---\n\nx\n", 1700000400);
$write('projects', 'proj', "---\ntitle: Proj\nstatus: published\ndate: '2025-06-01'\ntags: [news]\n---\n\nx\n", 1700000500);
$write('posts', 'hidden', "---\ntitle: Hidden\nstatus: published\nvisible: false\ntags: [news]\n---\n\nx\n");
$write('forms', 'contact', "---\ntitle: Contact\nstatus: published\nfields:\n  - name: name\n    label: Name\n    type: text\n    required: true\n  - name: email\n    label: Email\n    type: email\n    required: true\nnotifications:\n  enabled: true\n  to: boss@s.test\n  reply_to_field: email\nsuccess_message: Got it.\n---\n\n");
file_put_contents("$dir/content/taxonomies/tags.yaml", "title: Tags\nterms:\n  - id: news\n    slug: news\n    labels: {el: Νέα, en: News}\n    descriptions: {en: All the news.}\narchive:\n  title: 'Tagged {term}'\n");
file_put_contents("$dir/content/taxonomies/categories.yaml", "title: Categories\nterms:\n  - id: world\n    slug: world\n    labels: {el: Κόσμος, en: World}\n");
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$absolute = fn(string $path): string => 'https://s.test/' . ltrim($path, '/');

// ---- the sitemap
$sitemap = new Sitemap($content, fn() => $settings, $absolute);
$entries = $sitemap->entries();
$locs = array_column($entries, 'loc');
check('every public entry in every language is there, the home page at the root', [in_array('https://s.test/', $locs, true), in_array('https://s.test/about', $locs, true), in_array('https://s.test/en/about', $locs, true), in_array('https://s.test/old', $locs, true), in_array('https://s.test/en/old', $locs, true)], [true, true, true, true, true]);
check('drafts and hidden entries are not', [in_array('https://s.test/draft', $locs, true), in_array('https://s.test/posts/hidden', $locs, true)], [false, false]);
check('an entry is listed once however it is found', count($locs) === count(array_unique($locs)), true);
check('each has the time it last changed', date('c', 1700000100), $entries[array_search('https://s.test/about', $locs, true)]['lastmod']);
$xml = $sitemap->xml();
check('the sitemap is XML in the sitemap namespace', [str_starts_with($xml, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">"), str_ends_with($xml, "</urlset>\n"), substr_count($xml, '<url>') === count($entries)], [true, true, true]);
check('and parses', (new DOMDocument())->loadXML($xml) !== false, true);
check('addresses are escaped', str_contains((new Sitemap($content, fn() => $settings, fn(string $p): string => 'https://s.test/?a=1&b=2'))->xml(), '?a=1&amp;b=2'), true);

// ---- the page of a term
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$labels = fn(string $tax, string $id, string $lang): string => $taxonomies->label($tax, $id, $lang);
$archive = new ArchiveBuilder(fn() => $taxonomies->names(), $labels);
$alternates = new LanguageAlternates($content, fn() => $taxonomies, fn() => $settings, $absolute);
$theme = new Theme($root, 'default');
$translate = fn(string $key, ?string $fallback = null): string => $fallback ?? $key;
$page = new TaxonomyPage($content, $theme, fn() => $taxonomies, fn() => $archive, fn() => $alternates, fn() => $settings, $translate);
check('no term named is no page', [$page->build(['tag'], 'en', [], []), $page->build(['tag', ''], 'en', [], [])], [null, null]);
check('a term that does not exist is no page', $page->build(['tag', 'ghost'], 'en', [], []), null);
$p = $page->build(['tag', 'news'], 'el', ['canonical_url' => 'https://s.test/tag/news', 'lang' => 'el'], []);
check('a tag page: its kind and address', [$p['kind'], $p['slug']], ['tag', 'news']);
check('has the entries filed under it, any type but pages and forms, newest first, not hidden ones', array_map(fn($i) => $i->slug, $p['data']['items']), ['new', 'proj', 'old']);
check('and what a page of entries needs', [$p['data']['type'], $p['data']['archive']['total'], $p['data']['noindex_page'], $p['data']['lang']], ['tags', 3, false, 'el']);
check('its title is the taxonomy\'s own, with the term in it', $p['data']['archive_title'], 'Tagged Νέα');
check('with no description in this language there is no subtitle (the English one is for English)', [$p['data']['archive_subtitle'], $p['data']['term_description']], ['', '']);
check('the other languages it exists in', array_keys($p['data']['alternate_urls']), ['el', 'en']);
check('and the stylesheet of the blocks it lists', str_contains($p['data']['block_styles'][0], 'https://s.test'), true);
$c = $page->build(['categories', 'world'], 'el', [], []);
check('a category page has the default title of its kind', [$c['kind'], $c['data']['archive_title'], array_map(fn($i) => $i->slug, $c['data']['items'])], ['category', 'Category: Κόσμος', ['new']]);
check('both spellings of a kind are one', $page->build(['category', 'world'], 'el', [], [])['kind'], 'category');
file_put_contents("$dir/content/taxonomies/tags.yaml", "title: Tags\nterms:\n  - id: news\n    slug: news\n    labels: {el: Νέα, en: News}\narchive:\n  types: [posts]\n  per_page: 1\n  subtitle: 'About {term}'\n");
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$page = new TaxonomyPage($content, $theme, fn() => $taxonomies, fn() => $archive, fn() => $alternates, fn() => $settings, $translate);
$p = $page->build(['tag', 'news'], 'el', ['canonical_url' => 'https://s.test/tag/news'], ['page' => '2']);
check('the taxonomy chooses which types it lists, how many on a page, and its subtitle', [array_map(fn($i) => $i->slug, $p['data']['items']), $p['data']['archive']['total'], $p['data']['archive']['page'], $p['data']['archive_subtitle']], [['old'], 2, 2, 'About Νέα']);
check('a page after the first has its own address for search engines', $p['data']['canonical_url'], 'https://s.test/tag/news?page=2');

// ---- the page of a child term
$write('posts', 'asia-post', "---\ntitle: Asia post\nstatus: published\ndate: '2026-03-01'\ncategories: [asia]\n---\n\nx\n", 1700000600);
$write('posts', 'asia.en', "---\ntitle: Asia EN\nstatus: published\ndate: '2026-03-02'\ncategories: [asia]\n---\n\nx\n", 1700000700);
file_put_contents("$dir/content/taxonomies/categories.yaml", "title: Categories\nterms:\n  - id: world\n    slug: world\n    labels: {el: Κόσμος, en: World}\n  - id: asia\n    slug: asia\n    parent: world\n    labels: {el: Ασία, en: Asia}\n  - id: japan\n    slug: japan\n    parent: asia\n    labels: {el: Ιαπωνία, en: Japan}\n");
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$alternates = new LanguageAlternates($content, fn() => $taxonomies, fn() => $settings, $absolute);
$page = new TaxonomyPage($content, $theme, fn() => $taxonomies, fn() => $archive, fn() => $alternates, fn() => $settings, $translate);
$child = $page->build(['category', 'world', 'asia'], 'el', ['canonical_url' => 'https://s.test/category/world/asia'], []);
check('a child term has a page at its address in the tree', [$child['kind'], $child['slug'], $child['data']['archive_title']], ['category', 'asia', 'Category: Ασία']);
check('the page of a child lists its own entries and those of its sub-terms\' (none here)', array_map(fn($i) => $i->slug, $child['data']['items']), ['asia-post']);
check('its trail follows the tree: each parent with its address', $child['data']['term_trail'], [['label' => 'Κόσμος', 'url' => '/category/world']]);
check('and a term at the top has none', $page->build(['category', 'world'], 'el', [], [])['data']['term_trail'], []);
$grand = $page->build(['category', 'world', 'asia', 'japan'], 'en', [], []);
check('deeper still, in another language the trail has its prefix', [$grand['data']['term_trail'], $grand['data']['archive_title']], [[['label' => 'World', 'url' => '/en/category/world'], ['label' => 'Asia', 'url' => '/en/category/world/asia']], 'Category: Japan']);
check('a child is not at the top level, nor under another parent, nor deeper than the tree', [$page->build(['category', 'asia'], 'el', [], []), $page->build(['category', 'japan', 'asia'], 'el', [], []), $page->build(['category', 'world', 'asia', 'japan', 'x'], 'el', [], []), $page->build(['tag', 'news', 'x'], 'el', [], [])], [null, null, null, null]);
$parent = $page->build(['category', 'world'], 'el', [], []);
check('a parent\'s page also lists what is filed under its children, newest first', array_map(fn($i) => $i->slug, $parent['data']['items']), ['asia-post', 'new']);
file_put_contents("$dir/content/taxonomies/categories.yaml", file_get_contents("$dir/content/taxonomies/categories.yaml") . "archive:\n  include_children: false\n");
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$page = new TaxonomyPage($content, $theme, fn() => $taxonomies, fn() => $archive, fn() => $alternates, fn() => $settings, $translate);
check('unless the archive says not to', array_map(fn($i) => $i->slug, $page->build(['category', 'world'], 'el', [], [])['data']['items']), ['new']);
file_put_contents("$dir/content/taxonomies/categories.yaml", str_replace("archive:\n  include_children: false\n", '', file_get_contents("$dir/content/taxonomies/categories.yaml")));
$taxonomies = new Taxonomies("$dir/content", ['el', 'en']);
$alternates = new LanguageAlternates($content, fn() => $taxonomies, fn() => $settings, $absolute);
check('the language versions of a child page keep the tree address, and a parent has the languages of its children', [array_keys($alternates->forTaxonomy('categories', 'world/asia')['urls']), $alternates->forTaxonomy('categories', 'world/asia')['urls']['en'] ?? '', array_keys($alternates->forTaxonomy('categories', 'world')['urls'])], [['el', 'en'], 'https://s.test/en/category/world/asia', ['el', 'en']]);

// ---- forms: being sent
$processor = new FormProcessor($translate);
$submissions = new FormSubmissionRepository("$dir/content");
$sent = [];
$limited = false;
$marked = [];
$forms = new PublicForms($processor, $submissions, fn() => $settings, $translate, $absolute,
    function (string $to, string $subject, string $body, array $headers) use (&$sent) { $sent[] = [$to, $subject, $headers['Reply-To'] ?? '']; },
    function (string $slug, int $seconds) use (&$limited): bool { return $limited; },
    function (string $slug) use (&$marked): void { $marked[] = $slug; });
$contentForms = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$form = $contentForms->find('forms', 'contact', 'el', false, false);
$req = fn(string $method, array $post = [], array $get = []) => ['method' => $method, 'get' => $get, 'post' => $post, 'ip' => '10.0.0.1', 'agent' => 'Agent/1'];

$r = $forms->handle($form, 'el', 'contact', $req('GET'));
check('opening a form shows it empty, with its message, address and way back', [$r['location'], array_column($r['state']['fields'], 'name'), $r['state']['values'], $r['state']['errors'], $r['state']['success'], $r['state']['message'], $r['state']['action'], $r['state']['honeypot'], $r['state']['redirect']], ['', ['name', 'email'], ['name' => '', 'email' => ''], [], false, 'Got it.', 'forms/contact', 'website', '/contact']);
check('coming back after a send shows it was sent', $forms->handle($form, 'el', 'contact', $req('GET', [], ['sent' => '1', 'form' => 'contact']))['state']['success'], true);
check('but not for another form', $forms->handle($form, 'el', 'contact', $req('GET', [], ['sent' => '1', 'form' => 'other']))['state']['success'], false);
check('a form sent for another form is left alone', [$forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'other', 'name' => 'x']))['state']['errors'], $sent], [[], []]);
$r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => 'Ada']));
check('a form with a mistake is shown again with the error and what was typed', [$r['location'], array_keys($r['state']['errors']), $r['state']['values']['name'], $r['state']['success'], $sent, $submissions->all('contact')], ['', ['email'], 'Ada', false, [], []]);
$r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => 'Bot', 'email' => 'bot@x.test', 'website' => 'http://spam']));
check('a bot that fills the hidden field is told it worked, and nothing is kept or sent', [$r['state']['success'], $r['location'], $sent, $submissions->all('contact')], [true, '', [], []]);
$limited = true;
$r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => 'Ada', 'email' => 'ada@x.test']));
check('no limit is set for the form, so waiting is not asked', [$r['state']['errors'], count($sent)], [[], 1]);
$sent = [];
$write('forms', 'limited', "---\ntitle: Limited\nstatus: published\nantispam:\n  rate_limit_seconds: 30\nfields:\n  - name: name\n    label: Name\n    type: text\n---\n\n");
$contentForms = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$r = $forms->handle($contentForms->find('forms', 'limited', 'el', false, false), 'el', 'limited', $req('POST', ['form_slug' => 'limited', 'name' => 'Ada']));
check('a visitor who sent it a moment ago is asked to wait', [$r['state']['errors'], $r['state']['success']], [['_form' => 'Please wait before submitting again.'], false]);
$limited = false;
$sent = [];
$marked = [];
$r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => ' Ada ', 'email' => 'ada@x.test']));
check('a good submission is kept, the site is told by email, the visitor noted, and success shown', [$r['state']['success'], $r['state']['message'], $r['location'], $sent, $marked], [true, 'Got it.', '', [['boss@s.test', 'New submission: Contact', 'ada@x.test']], ['contact']]);
$stored = $submissions->all('contact');
check('the submission has the values, where from, and the form', [count($stored), $stored[0]['fields']['name'], $stored[0]['ip'], $stored[0]['user_agent'], $stored[0]['form']], [2, 'Ada', '10.0.0.1', 'Agent/1', 'contact']);
$r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => 'Ada', 'email' => 'ada@x.test', 'redirect_url' => '/thanks']));
check('a way back given in the form is where the visitor goes', $r['location'], '/thanks?form=contact&sent=1');
$r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => 'Ada', 'email' => 'ada@x.test', 'redirect_url' => 'thanks?x=1']));
check('a path without a slash gets one, and a query gets the extra part joined', $r['location'], '/thanks?x=1&form=contact&sent=1');
$r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => 'Ada', 'email' => 'ada@x.test', 'redirect_url' => 'https://s.test/done']));
check('a full address on the site\'s own host is allowed', $r['location'], 'https://s.test/done?form=contact&sent=1');
foreach (['https://evil.test/x', '//evil.test/x'] as $bad) {
    $r = $forms->handle($form, 'el', 'contact', $req('POST', ['form_slug' => 'contact', 'name' => 'Ada', 'email' => 'ada@x.test', 'redirect_url' => $bad]));
    check("somewhere else ($bad) is not a way back", [$r['location'], $r['state']['success']], ['', true]);
}
$write('forms', 'quiet', "---\ntitle: Quiet\nstatus: published\nstore_submissions: false\nfields:\n  - name: name\n    label: Name\n    type: text\n---\n\n");
$contentForms = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$forms->handle($contentForms->find('forms', 'quiet', 'el', false, false), 'el', 'quiet', $req('POST', ['form_slug' => 'quiet', 'name' => 'Ada']));
check('a form that is not to keep its submissions does not', $submissions->all('quiet'), []);
$en = $contentForms->find('forms', 'contact', 'en', false, true);
check('a form in another language sends to its own address', $forms->handle($en, 'en', 'en/contact', $req('GET'))['state']['action'], 'en/forms/contact');

// ---- forms: shown inside a page
$e = $forms->embed($form, 'contact', null, []);
check('a form in a page takes its address from the page, and its way back too', [$e['form_action'], $e['form_redirect'], $e['form_success'], $e['form_message'], $e['form_honeypot'], array_keys($e['form_fields'])], ['/contact', '/contact', false, 'Got it.', 'website', [0, 1]]);
check('on the home page the address is empty', $forms->embed($form, '', null, [])['form_action'], '');
$e = $forms->embed($form, 'contact', ['values' => ['name' => 'Typed'], 'errors' => ['email' => 'Bad'], 'success' => false, 'message' => 'Hmm'], []);
check('the state of a form that was sent is what it shows', [$e['form_values'], $e['form_errors'], $e['form_message']], [['name' => 'Typed'], ['email' => 'Bad'], 'Hmm']);
check('after a send it says so', $forms->embed($form, 'contact', null, ['sent' => '1', 'form' => 'contact'])['form_success'], true);

// ---- shortcodes
$render = fn(string $slug): string => "[FORM:$slug]";
check('a shortcode is replaced, and the paragraph Markdown put around it goes', PublicForms::replaceShortcodes("<p>Hi</p>\n<p>[form slug=\"contact\"]</p>\n<p>Bye</p>", $render), "<p>Hi</p>\n[FORM:contact]\n<p>Bye</p>");
check('quotes the Markdown encoded are understood, and so are single quotes and none', [PublicForms::replaceShortcodes('[form slug=&quot;a&quot;]', $render), PublicForms::replaceShortcodes("[form slug='b']", $render), PublicForms::replaceShortcodes('[form slug=c]', $render)], ['[FORM:a]', '[FORM:b]', '[FORM:c]']);
check('the slug is cleaned', PublicForms::replaceShortcodes('[form slug="Contact Us"]', $render), '[FORM:contact-us]');
check('a shortcode with no slug is left as written', PublicForms::replaceShortcodes('[form title="x"]', $render), '[form title="x"]');
check('text with no shortcode is untouched', PublicForms::replaceShortcodes('<p>no forms [here]</p>', $render), '<p>no forms [here]</p>');
check('the attributes of a shortcode', PublicForms::attributes('slug="a b" id=7 class=\'x\''), ['slug' => 'a b', 'id' => '7', 'class' => 'x']);

// ---- template functions
$twig = new Twig(new ArrayLoader([
    'a' => "{{ asset('app.css') }}|{{ admin_asset('/x.js') }}|{{ url('about') }}|{{ url() }}|{{ link_url('contact', 'en/') }}|{{ link_url('/about') }}|{{ link_url('https://x.test/a') }}|{{ link_url('mailto:a@b.test') }}|{{ link_url('#top') }}|[{{ link_url('javascript:alert(1)') }}]|[{{ link_url('') }}]|{{ absolute_url('contact') }}|{{ absolute_url('https://o.test/x') }}",
    'b' => "{{ toc('<h2 id=\"one\">One</h2><h3 id=\"two\">Two</h3>')|length }}|{{ json_ld({'@type': 'Thing'}) }}|{{ icon('nothing-like-this') }}|{{ theme_asset('missing.css') }}|[{{ custom_asset('missing.css') }}]",
]), ['autoescape' => 'html']);
$graphs = [];
$structured = new StructuredData(fn() => $settings, fn() => [], $translate, $absolute, fn(string $lang): string => $lang === 'el' ? '' : $lang . '/');
TwigFunctions::register($twig, 'https://s.test/', $theme, new Images("$dir/public"), $absolute, fn() => $structured);
check('addresses are made from the base address, whether or not it ends with a slash', $twig->render('a'), 'https://s.test/assets/app.css|https://s.test/admin-assets/x.js|https://s.test/about|https://s.test/|https://s.test/en/contact|https://s.test/about|https://x.test/a|mailto:a@b.test|#top|[]|[]|https://s.test/contact|https://o.test/x');
$out = explode('|', $twig->render('b'));
check('the table of contents, the script of structured data (not escaped), no icon for a name that is not there, the theme asset address', [$out[0] >= 1, str_contains($out[1], '<script type="application/ld+json">'), $out[2], $out[3], $out[4]], [true, true, '', 'https://s.test/_themes/default/missing.css', '[]']);
$twig3 = new Twig(new ArrayLoader(['p' => "{{ plain_text('<p>One &amp; <em>two</em></p><p>Three&nbsp;four<br>five</p><ul><li>six</li><li>seven</li></ul>') }}|{{ plain_text('') }}|{{ plain_text('<h2>Head</h2><p>Text</p>') }}"]), ['autoescape' => 'html']);
TwigFunctions::register($twig3, 'https://s.test', $theme, new Images("$dir/public"), $absolute, fn() => $structured);
check('the words of some HTML: no tags, entities as characters (escaped again on output), a space between paragraphs and items', $twig3->render('p'), 'One &amp; two Three four five six seven||Head Text');
$twig2 = new Twig(new ArrayLoader(['i' => "{{ icon_library_json()|length > 2 ? 'full' : 'empty' }}|{{ image('/nothing.png') }}"]), ['autoescape' => 'html']);
TwigFunctions::register($twig2, 'https://s.test', $theme, new Images("$dir/public"), $absolute, fn() => $structured);
check('the icon library is a JSON object of the icons', explode('|', $twig2->render('i'))[0], 'full');

// ---- the address of a file in public/assets carries a stamp that changes with the file
@mkdir("$dir/public/assets/css", 0775, true);
file_put_contents("$dir/public/assets/css/a.css", 'a{}');
$twig4 = new Twig(new ArrayLoader(['s' => "{{ asset('css/a.css') }}|{{ asset('/css/a.css') }}|{{ asset('css/none.css') }}|{{ asset('../index.php') }}"]), ['autoescape' => 'html']);
TwigFunctions::register($twig4, 'https://s.test/', $theme, new Images("$dir/public"), $absolute, fn() => $structured, "$dir/public");
$first = explode('|', $twig4->render('s'));
check('a file that exists has a version stamp, a file that does not has none, and a path that climbs out is not looked up', [preg_match('#^https://s\.test/assets/css/a\.css\?v=[a-z0-9]+$#', $first[0]), $first[0] === $first[1], $first[2], $first[3]], [1, true, 'https://s.test/assets/css/none.css', 'https://s.test/assets/../index.php']);
file_put_contents("$dir/public/assets/css/a.css", 'a{color:red}');
check('the stamp changes when the file does (so a cached copy is asked for again)', explode('|', $twig4->render('s'))[0] !== $first[0], true);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
