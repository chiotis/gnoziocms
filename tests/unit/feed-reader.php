<?php
/*
 * Feeds and pages for AI publishing, without the network: RSS 2.0, Atom and RSS 1.0 items (title, link, a plain summary, date,
 * a stable key), newest first, relative links made absolute, a feed with its own entities refused, something that is not a feed,
 * the readable text of a page (article first, no scripts, menus or forms), Greek in another encoding, and fetching through SafeHttp
 * (the guards for addresses, the Accept header for text).
 *   php tests/unit/feed-reader.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{FeedReader, SafeHttp};

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Υπουργείο</title>
<item><title>Παλαιό νέο</title><link>https://gov.example/old</link><pubDate>Mon, 26 Oct 2026 09:00:00 +0200</pubDate><guid>old-1</guid><description>Παλιό.</description></item>
<item><title>Νέα πρόσκληση &amp; χρηματοδότηση</title><link>/news/123</link><pubDate>Fri, 30 Oct 2026 10:00:00 +0200</pubDate><guid>n-123</guid>
<description><![CDATA[<p>Η <b>πρόσκληση</b> ανοίγει.</p><script>alert(1)</script>]]></description></item>
</channel></rss>';
$items = FeedReader::parse($rss, 'https://gov.example/feed');
check('RSS: two items, newest first', array_column($items, 'title'), ['Νέα πρόσκληση & χρηματοδότηση', 'Παλαιό νέο']);
check('a relative link made absolute', $items[0]['link'], 'https://gov.example/news/123');
check('the summary is plain text, no tags or script content kept as markup', [str_contains($items[0]['summary'], '<'), str_starts_with($items[0]['summary'], 'Η πρόσκληση ανοίγει.')], [false, true]);
check('the date is read', date('Y-m-d', $items[0]['date']), '2026-10-30');
check('a stable key from the guid', [strlen($items[0]['key']), FeedReader::parse($rss, 'https://gov.example/feed')[0]['key'] === $items[0]['key']], [32, true]);

$atom = '<?xml version="1.0" encoding="utf-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Blog</title>
<entry><title>Atom entry</title><link rel="alternate" href="https://blog.example/a"/><link rel="self" href="https://blog.example/a.xml"/><id>tag:blog,1</id><updated>2026-11-02T08:00:00Z</updated><summary>Short.</summary></entry></feed>';
$a = FeedReader::parse($atom);
check('Atom: the alternate link, summary and date', [$a[0]['link'], $a[0]['summary'], date('Y-m-d', $a[0]['date'])], ['https://blog.example/a', 'Short.', '2026-11-02']);
$rdf = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>x</title></channel>
<item><title>RDF item</title><link>https://r.example/1</link><dc:date>2026-11-01T10:00:00Z</dc:date></item></rdf:RDF>';
check('RSS 1.0 (RDF) with a dc:date', [FeedReader::parse($rdf)[0]['title'], date('Y-m-d', FeedReader::parse($rdf)[0]['date'])], ['RDF item', '2026-11-01']);
check('an empty feed has no items', FeedReader::parse('<rss version="2.0"><channel><title>x</title></channel></rss>'), []);
$refused = static function (string $xml): string { try { FeedReader::parse($xml); return 'parsed'; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
check('entities of its own are refused', $refused('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><rss><channel><item><title>&a;</title></item></channel></rss>'), 'The feed declares entities of its own, which are not read.');
check('a web page is not a feed', $refused('<html><body>hi</body></html>'), 'The address does not answer with a feed (RSS or Atom).');
check('broken XML is not a feed', $refused('<rss><channel><item>'), 'The address does not answer with a feed (RSS or Atom).');

[$title, $text] = FeedReader::pageText('<html><head><title>Ανακοινώσεις</title><script>var x=1;</script></head><body><nav>Menu Home</nav><header>Logo</header>
<main><article><h1>Πρόσκληση</h1><p>Πρώτη παράγραφος.</p><p>Δεύτερη&nbsp;παράγραφος.</p><form><input name="q"></form><style>.a{}</style></article></main><footer>©</footer></body></html>');
check('a page: its title', $title, 'Ανακοινώσεις');
check('its article text, paragraphs apart, without menu, form, style or script', [str_contains($text, 'Πρώτη παράγραφος.'), str_contains($text, "\n"), str_contains($text, 'Menu'), str_contains($text, 'var x'), str_contains($text, '.a{}')], [true, true, false, false, false]);
check('plain text is cut to its length', mb_strlen(FeedReader::plain(str_repeat('λέξη ', 300), 50)), 50);

// ---- through SafeHttp
$dir = sys_get_temp_dir() . '/feeds' . getmypid();
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$accepts = [];
$pages = [
    'https://gov.example/feed' => $rss,
    'https://gr.example/feed' => mb_convert_encoding('<?xml version="1.0" encoding="ISO-8859-7"?><rss version="2.0"><channel><item><title>Ελληνικά</title><link>https://gr.example/1</link></item></channel></rss>', 'ISO-8859-7', 'UTF-8'),
    'https://gov.example/announcements' => '<html><head><title>Ann</title></head><body><main><p>Text of the page.</p></main></body></html>',
];
$http = new SafeHttp(
    fn(string $host): array => ['gov.example' => ['93.184.216.34'], 'gr.example' => ['93.184.216.35'], 'inside.example' => ['10.0.0.1']][$host] ?? [],
    function (string $url, string $ip, string $to, int $max, int $timeout, string $accept = '') use (&$pages, &$accepts): array {
        $accepts[] = $accept;
        if (!isset($pages[$url])) { return ['status' => 404, 'location' => '', 'error' => '']; }
        file_put_contents($to, $pages[$url]);
        return ['status' => 200, 'location' => '', 'error' => ''];
    }
);
$reader = new FeedReader($http, $dir);
$got = $reader->items('https://gov.example/feed');
check('a feed fetched and read', [$got['ok'], count($got['items'])], [true, 2]);
check('asked for as a feed, not a picture', str_contains($accepts[0], 'application/rss+xml'), true);
check('Greek in ISO-8859-7 is read', $reader->items('https://gr.example/feed')['items'][0]['title'] ?? '', 'Ελληνικά');
check('a private address is refused', str_contains($reader->items('https://inside.example/feed')['error'], 'private or reserved'), true);
check('a missing feed says so', $reader->items('https://gov.example/missing')['error'], 'The address answered 404.');
$pages['https://gr.example/page'] = mb_convert_encoding('<html><head><meta charset="iso-8859-7"><title>Σελίδα</title></head><body><p>Ελληνικό κείμενο.</p></body></html>', 'ISO-8859-7', 'UTF-8');
check('a Greek page in ISO-8859-7 is read', [$reader->page('https://gr.example/page')['title'], $reader->page('https://gr.example/page')['text']], ['Σελίδα', 'Ελληνικό κείμενο.']);
$page = $reader->page('https://gov.example/announcements');
check('a page fetched and read', [$page['ok'], $page['title'], $page['text']], [true, 'Ann', 'Text of the page.']);
check('no temporary files left', glob("$dir/fetch-*") ?: [], []);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
