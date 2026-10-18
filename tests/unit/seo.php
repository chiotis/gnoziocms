<?php
/*
 * The site-wide SEO settings: what is read from storage and how it is checked, what one tab of the form changes, the title
 * format, the description, the robots tag, the tags of the head, the sitemap's exclusions, and robots.txt.
 *   php tests/unit/seo.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\RobotsTxt;
use GnozioCMS\SeoSettings as S;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$none = S::from([]);
check('a site that never opened the screen has the defaults', [$none['separator'], $none['title_format'], $none['discourage'], $none['block_ai'], $none['snippets'], $none['noindex_search'], $none['sitemap']['enabled']], ['|', '', false, false, true, true, true]);
check('and a title as it always was', S::title($none, '', 'About', 'Faros', 'Tag', false, 'pages'), 'About | Faros');
check('the home page is the site name', S::title($none, '', 'Home', 'Faros', 'Tag', true, 'pages'), 'Faros');
check('a page with no title is the site name', S::title($none, '', '', 'Faros', 'Tag', false, 'pages'), 'Faros');
check('a title written for search engines is kept as it is', S::title($none, 'My own', 'About', 'Faros', 'Tag', false, 'pages'), 'My own');

$seo = S::from(['seo' => ['separator' => '–', 'title_format' => '{site} {sep} {title}', 'home_title' => 'Faros — Studio', 'types' => ['posts' => ['title_format' => '{title} {sep} Blog {sep} {site}', 'noindex' => false, 'sitemap' => true], 'projects' => ['noindex' => true, 'sitemap' => false]]]]);
check('the format and the separator', S::title($seo, '', 'About', 'Faros', 'Tag', false, 'pages'), 'Faros – About');
check('a type has a format of its own', S::title($seo, '', 'Hello', 'Faros', 'Tag', false, 'posts'), 'Hello – Blog – Faros');
check('the home page has a title of its own', S::title($seo, '', 'Home', 'Faros', 'Tag', true, 'pages'), 'Faros — Studio');
$gap = S::from(['seo' => ['title_format' => '{title} {sep} {tagline} {sep} {site}']]);
check('a word with no value takes its separator with it', S::title($gap, '', 'About', 'Faros', '', false, 'pages'), 'About | Faros');
check('a separator that is not on the list is not used', S::from(['seo' => ['separator' => '<b>']])['separator'], '|');

check('a description: its own first', S::description($none, 'Own', 'Excerpt', 'Doc', 'Tag', false), 'Own');
check('then the summary, the template, the tagline', [S::description($none, '', 'Excerpt', 'Doc', 'Tag', false), S::description($none, '', '', 'Doc', 'Tag', false), S::description($none, '', '', '', 'Tag', false)], ['Excerpt', 'Doc', 'Tag']);
$d = S::from(['seo' => ['home_description' => 'Home text', 'default_description' => 'Default text']]);
check('the home page and the default', [S::description($d, '', '', '', 'Tag', true), S::description($d, '', '', '', 'Tag', false), S::description($d, '', 'Excerpt', '', 'Tag', false)], ['Home text', 'Default text', 'Excerpt']);

check('robots: open pages ask for large previews', S::robots($none, false, 'pages'), 'max-image-preview:large, max-snippet:-1, max-video-preview:-1');
check('robots: a page can be kept out', S::robots($none, true, 'pages'), 'noindex, follow');
check('robots: the search results are kept out, the term pages are not', [S::robots($none, false, 'search'), S::robots($none, false, 'taxonomy') !== 'noindex, follow'], ['noindex, follow', true]);
check('robots: a whole type can be kept out', [S::robots($seo, false, 'projects'), S::robots($seo, false, 'posts') !== 'noindex, follow'], ['noindex, follow', true]);
check('robots: a site that asked to stay out says so everywhere', [S::robots(S::from(['seo' => ['discourage' => true]]), false, 'pages'), S::robots(S::from(['seo' => ['discourage' => true]]), false, 'search')], ['noindex, nofollow', 'noindex, nofollow']);
check('robots: large previews can be turned off', S::robots(S::from(['seo' => ['snippets' => false]]), false, 'pages'), '');
check('twitter card', [S::twitterCard($none, true), S::twitterCard($none, false), S::twitterCard(S::from(['seo' => ['twitter_card' => 'summary']]), true)], ['summary_large_image', 'summary', 'summary']);

// ---- the head
$codes = S::from(['seo' => ['verification' => ['google' => 'abc123XYZ', 'bing' => '<meta name="msvalidate.01" content="BING-CODE-1" />', 'yandex' => '"><script>x</script>'], 'twitter_site' => '@faros', 'facebook_app_id' => '12345']]);
$head = S::head($codes);
check('the head has the codes, the account and the app', [str_contains($head, '<meta name="google-site-verification" content="abc123XYZ">'), str_contains($head, 'name="msvalidate.01" content="BING-CODE-1"'), str_contains($head, 'name="twitter:site" content="@faros"'), str_contains($head, 'property="fb:app_id" content="12345"')], [true, true, true, true]);
check('a code that is not a code is dropped, and nothing can leave the tag', [str_contains($head, 'yandex'), str_contains($head, '<script')], [false, false]);
check('no head when there is nothing', S::head($none), '');

// ---- what a tab saves
$types = ['pages', 'posts', 'projects'];
$s = S::apply([], 'search', ['separator' => '·', 'title_format' => '{title} {sep} {site}', 'home_title' => '<b>Home</b>', 'noindex_search' => '1', 'types' => ['posts' => ['title_format' => '', 'noindex' => '1', 'sitemap' => '1'], 'pages' => ['sitemap' => '1'], 'projects' => ['title_format' => '{title}']]], $types);
check('the search tab keeps its choices, cleaned', [$s['separator'], $s['home_title'], $s['noindex_search'], $s['noindex_taxonomies']], ['·', 'Home', true, false]);
check('and only a type that differs from the rest', array_keys($s['types']), ['posts', 'projects']);
$before = ['share_image' => '/uploads/a.jpg', 'robots_disallow' => ['/x']];
$s = S::apply($before, 'search', ['separator' => '|'], $types);
check('a tab leaves what the others keep', [$s['share_image'], $s['robots_disallow']], ['/uploads/a.jpg', ['/x']]);
$s = S::apply([], 'social', ['share_image' => '/uploads/media/share.jpg', 'twitter_card' => 'summary', 'twitter_site' => '@Faros_cms', 'facebook_app_id' => '99x'], $types);
check('social', [$s['share_image'], $s['twitter_card'], $s['twitter_site'], $s['facebook_app_id']], ['/uploads/media/share.jpg', 'summary', 'Faros_cms', '']);
$s = S::apply([], 'social', ['share_image' => 'javascript:alert(1)', 'twitter_card' => 'huge', 'twitter_site' => 'two words'], $types);
check('an image must be an address, a card one of the cards, an account a name', [$s['share_image'], $s['twitter_card'], $s['twitter_site']], ['', 'auto', '']);
$s = S::apply([], 'social', ['share_image' => 'https://cdn.example.test/a.jpg?x=1'], $types);
check('an image on another site is allowed', $s['share_image'], 'https://cdn.example.test/a.jpg?x=1');
$s = S::apply([], 'crawling', ['discourage' => '1', 'robots_disallow' => "/private/\nnonsense\n/a", 'sitemap_enabled' => '1', 'sitemap_exclude' => "/thank-you\nhttps://x.test"], $types);
check('crawling: the boxes not sent are off, the rules are cleaned', [$s['discourage'], $s['block_ai'], $s['snippets'], $s['robots_disallow'], $s['sitemap']], [true, false, false, ['/private/', '/a'], ['enabled' => true, 'images' => false, 'taxonomies' => false, 'exclude' => ['/thank-you']]]);
check('no rules, no key', array_key_exists('robots_disallow', S::apply(['robots_disallow' => ['/x']], 'crawling', ['robots_disallow' => ''], $types)), false);
$s = S::apply([], 'identity', ['identity_type' => 'LocalBusiness', 'identity_name' => 'Faros SA', 'identity_country' => 'gr', 'identity_street' => '12 Main', 'article_type' => 'NewsArticle', 'breadcrumbs' => '1'], $types);
check('identity', [$s['identity']['type'], $s['identity']['name'], $s['identity']['country'], $s['identity']['street'], $s['schema']], ['LocalBusiness', 'Faros SA', 'GR', '12 Main', ['article_type' => 'NewsArticle', 'breadcrumbs' => true, 'search_box' => false]]);
$s = S::apply([], 'identity', ['identity_type' => 'Spaceship', 'identity_country' => 'Greece', 'article_type' => 'Poem'], $types);
check('a type that does not exist is the default', [$s['identity']['type'], $s['identity']['country'], $s['schema']['article_type']], ['Organization', '', 'BlogPosting']);
$s = S::apply([], 'verification', ['verify_google' => 'abc123XYZ', 'verify_bing' => 'x', 'verify_baidu' => '<meta name="baidu-site-verification" content="codeBAIDU1">'], $types);
check('verification codes', [$s['verification']['google'], $s['verification']['bing'], $s['verification']['baidu']], ['abc123XYZ', '', 'codeBAIDU1']);
check('a tab that does not exist changes nothing', S::apply(['a' => 1], 'nope', ['x' => 1], $types), ['a' => 1]);
$hand = S::from(['seo' => ['types' => ['posts' => 'junk', '../x' => ['noindex' => true]], 'twitter_card' => ['x'], 'sitemap' => 'no', 'identity' => 'no', 'verification' => 'no', 'robots_disallow' => 'no']]);
check('a file edited by hand never gives the pages something they cannot use', [$hand['types'], $hand['twitter_card'], $hand['sitemap']['enabled'], $hand['identity']['type'], $hand['robots_disallow']], [[], 'auto', true, 'Organization', []]);

// ---- the sitemap's exclusions and who is indexable
$ex = S::from(['seo' => ['sitemap' => ['exclude' => ['/thank-you', '/forms/', '/*.pdf$', '/en/private*']]]]);
check('an exclusion matches the start of an address', [S::excluded($ex, '/thank-you'), S::excluded($ex, '/thank-you/now'), S::excluded($ex, '/forms/contact'), S::excluded($ex, '/about')], [true, true, true, false]);
check('with the wildcard and the end of the address', [S::excluded($ex, '/files/a.pdf'), S::excluded($ex, '/files/a.pdf2'), S::excluded($ex, '/en/private-notes')], [true, false, true]);
check('an address with a query or without a slash', [S::excluded($ex, 'thank-you?x=1'), S::excluded($ex, 'https://x.test/forms/a')], [true, true]);
check('indexable: its type, its own flag, the site', [S::indexable($seo, 'posts', []), S::indexable($seo, 'projects', []), S::indexable($none, 'pages', ['seo' => ['noindex' => true]]), S::indexable(S::from(['seo' => ['discourage' => true]]), 'pages', [])], [true, false, false, false]);

// ---- robots.txt
check('robots.txt as it always was', RobotsTxt::render('https://x.test/sitemap.xml', []), "User-agent: *\nAllow: /\nSitemap: https://x.test/sitemap.xml\n");
check('with rules, before the sitemap', RobotsTxt::render('https://x.test/sitemap.xml', ['/a']), "User-agent: *\nAllow: /\nDisallow: /a\nSitemap: https://x.test/sitemap.xml\n");
check('without a sitemap it is not listed', RobotsTxt::render('', []), "User-agent: *\nAllow: /\n");
check('a site that asked to stay out closes everything', RobotsTxt::render('https://x.test/sitemap.xml', ['/a'], true, ['GPTBot']), "User-agent: *\nDisallow: /\n");
$ai = RobotsTxt::render('https://x.test/sitemap.xml', [], false, ['GPTBot', 'bad agent', "x\nUser-agent: evil"]);
check('AI crawlers are closed out one by one, before everyone else', $ai, "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\nSitemap: https://x.test/sitemap.xml\n");
check('every crawler of the list is a name that is safe to write', count(array_filter(S::AI_CRAWLERS, fn($a) => preg_match('/^[A-Za-z0-9._-]{2,40}$/', $a))), count(S::AI_CRAWLERS));

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
