<?php
/*
 * What the WordPress importer does with pictures and videos in the text: a picture with no alternative text is described by its title
 * or its caption (never by a file name or a camera's name), a picture that floated to a side is written with the attribute the editor
 * writes for it, and a link to a YouTube video that stands alone in a paragraph becomes a Video block between Text blocks.
 *   php tests/unit/importer-media.php
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnozioCMS\{FrontMatter, HtmlToMarkdown, MediaLibrary, Taxonomies, WordPressImporter, WordPressReader};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$md = static fn(string $html): string => (new HtmlToMarkdown())->convert($html);

// ---- a description for the picture
check('the alternative text is what it is', $md('<p><img src="/a.jpg" alt="A cat" title="Tom"></p>'), "![A cat](/a.jpg)\n");
check('without one, the title', $md('<p><img src="/a.jpg" title="Tom on the wall"></p>'), "![Tom on the wall](/a.jpg)\n");
check('a file name as the alternative text gives way to the title', $md('<p><img src="/a.jpg" alt="hoos_l.gif" title="The hoopoe"></p>'), "![The hoopoe](/a.jpg)\n");
check('without a title, the caption of the figure', $md('<figure><img src="/a.jpg"><figcaption>The port at dusk</figcaption></figure>'), "![The port at dusk](/a.jpg)\n\nThe port at dusk\n");
check('and the caption of WordPress, also when the picture is a link', $md('<div class="wp-caption"><a href="/big.jpg"><img src="/a.jpg" alt=""></a><p class="wp-caption-text">By the sea</p></div>'), "[![By the sea](/a.jpg)](/big.jpg)\n\nBy the sea\n");
check('the title comes before the caption', $md('<figure><img src="/a.jpg" title="Own title"><figcaption>Caption</figcaption></figure>'), "![Own title](/a.jpg)\n\nCaption\n");
check('a camera\'s name says nothing, so nothing is used', [$md('<p><img src="/a.jpg" title="IMG_0042"></p>'), $md('<p><img src="/a.jpg" title="DSC00123.JPG"></p>'), $md('<p><img src="/a.jpg" title="2013-04-05 12-01"></p>')], ["![](/a.jpg)\n", "![](/a.jpg)\n", "![](/a.jpg)\n"]);
check('a caption that is a paragraph is not a description', $md('<figure><img src="/a.jpg"><figcaption>' . str_repeat('word ', 50) . '</figcaption></figure>') === "![](/a.jpg)\n\n" . trim(str_repeat('word ', 50)) . "\n", true);
check('a picture not in a figure takes no caption from a neighbour', $md('<p><img src="/a.jpg"></p><figure><img src="/b.jpg"><figcaption>Only b</figcaption></figure>'), "![](/a.jpg)\n\n![Only b](/b.jpg)\n\nOnly b\n");
check('what says something is told apart from what does not', array_map([HtmlToMarkdown::class, 'describes'], ['Spring meadow', 'paper', 'hoos_l.gif', 'IMG_1234', 'DSC00123', 'dcim 12', '123', '', 'Covid-19 ward']), [true, true, false, false, false, false, false, false, true]);

// ---- pictures that floated
check('a class of WordPress', $md('<p><img class="alignleft size-medium" src="/a.jpg" alt="A"> The text goes around it.</p>'), "![A](/a.jpg){align=left} The text goes around it.\n");
check('the other side', $md('<p><img class="wp-image-3 alignright" src="/a.jpg" alt="A"> Text.</p>'), "![A](/a.jpg){align=right} Text.\n");
check('the align attribute of old HTML', $md('<p><img align="right" src="/a.jpg" alt="A"> Text.</p>'), "![A](/a.jpg){align=right} Text.\n");
check('a float in the style', $md('<p><img style="border:0; float: left; margin:4px" src="/a.jpg" alt="A"> Text.</p>'), "![A](/a.jpg){align=left} Text.\n");
check('the class on the link around the picture', $md('<p><a class="alignright" href="/big.jpg"><img src="/a.jpg" alt="A"></a> Text.</p>'), "[![A](/a.jpg){align=right}](/big.jpg) Text.\n");
check('the class on the box of the caption', $md('<div class="wp-caption alignleft"><img src="/a.jpg" alt="A"><p class="wp-caption-text">Cap</p></div><p>Text.</p>'), "![A](/a.jpg){align=left}\n\nCap\n\nText.\n");
check('the class on the figure of the block editor', $md('<figure class="wp-block-image alignright"><img src="/a.jpg" alt="A"></figure><p>Text.</p>'), "![A](/a.jpg){align=right}\n\nText.\n");
check('a picture in the line of text, or centred, has no side', [$md('<p><img class="aligncenter" src="/a.jpg" alt="A"></p>'), $md('<p>Text <img class="alignnone" src="/a.jpg" alt="A"> more</p>')], ["![A](/a.jpg)\n", "Text ![A](/a.jpg) more\n"]);
check('a picture on its own line without a side is a paragraph of its own, as before', $md('<p><img src="/a.jpg" alt="A"> Then text.</p>'), "![A](/a.jpg)\n\nThen text.\n");
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$environment->addExtension(new AttributesExtension());
$html = (string)(new MarkdownConverter($environment))->convert("![A](/a.jpg){align=left} The text goes around it.\n");
check('the site draws that as a picture with the align attribute, in the paragraph of the text', $html, "<p><img align=\"left\" src=\"/a.jpg\" alt=\"A\" /> The text goes around it.</p>\n");

// ---- a link to a video that stands alone
$video = 'https://www.youtube.com/watch?v=abc_DEF-123';
check('a link on a line of its own is a video between two texts', HtmlToMarkdown::splitVideos("Before.\n\n$video\n\nAfter.\n"), [['type' => 'text', 'value' => "Before.\n"], ['type' => 'video', 'value' => $video], ['type' => 'text', 'value' => "After.\n"]]);
check('the short address and the embed address are the same video', array_column(HtmlToMarkdown::splitVideos("https://youtu.be/abc_DEF-123\n\nhttps://www.youtube-nocookie.com/embed/abc_DEF-123\n\nhttp://m.youtube.com/watch?v=abc_DEF-123&t=30s"), 'value'), [$video, $video, $video]);
check('a link whose text is its address, or in angle brackets, too', array_column(HtmlToMarkdown::splitVideos("[https://youtu.be/abc_DEF-123](https://youtu.be/abc_DEF-123)\n\n<https://youtu.be/abc_DEF-123>"), 'type'), ['video', 'video']);
check('a link with words is a link, and stays', HtmlToMarkdown::splitVideos("[Watch it](https://youtu.be/abc_DEF-123)\n"), [['type' => 'text', 'value' => "[Watch it](https://youtu.be/abc_DEF-123)\n"]]);
check('in a sentence, a list, a quote or with text on the next line it stays', array_column(HtmlToMarkdown::splitVideos("See https://youtu.be/abc_DEF-123 now\n\n- https://youtu.be/abc_DEF-123\n\n> https://youtu.be/abc_DEF-123\n\nhttps://youtu.be/abc_DEF-123\nand more\n"), 'type'), ['text']);
check('inside a block of code it stays', array_column(HtmlToMarkdown::splitVideos("```\n\nhttps://youtu.be/abc_DEF-123\n\n```\n\nEnd\n"), 'type'), ['text']);
check('a page of a video alone', HtmlToMarkdown::splitVideos("$video\n"), [['type' => 'video', 'value' => $video]]);
check('another site\'s video, or a page that is not one, is not a Video block here', array_column(HtmlToMarkdown::splitVideos("https://vimeo.com/123456789\n\nhttps://www.youtube.com/user/someone\n\nhttps://example.com/watch?v=abc_DEF-123\n"), 'type'), ['text']);
check('a page with no video is one text', HtmlToMarkdown::splitVideos("Just words.\n\nMore.\n"), [['type' => 'text', 'value' => "Just words.\n\nMore.\n"]]);
check('and an empty page nothing', HtmlToMarkdown::splitVideos(''), []);
check('the converter gives such a paragraph from WordPress\'s own markup', array_column(HtmlToMarkdown::splitVideos($md('<p>Before</p><p>' . $video . '</p><p>After</p>')), 'type'), ['text', 'video', 'text']);

// ---- through the importer
$SITE = 'https://old.example';
$web = [
    '/wp-json/' => ['name' => 'Old', 'description' => '', 'home' => $SITE, 'namespaces' => ['wp/v2'], 'show_on_front' => 'posts', 'page_on_front' => 0],
    '/wp-json/wp/v2/types' => ['post' => ['rest_base' => 'posts'], 'page' => ['rest_base' => 'pages']],
    '/wp-json/wp/v2/taxonomies' => [],
    '/wp-json/wp/v2/categories' => [],
    '/wp-json/wp/v2/tags' => [],
    '/wp-json/wp/v2/media' => [
        ['id' => 60, 'source_url' => "$SITE/wp-content/uploads/a.png", 'alt_text' => '', 'title' => ['rendered' => 'Spring meadow'], 'mime_type' => 'image/png', 'post' => 0],
        ['id' => 61, 'source_url' => "$SITE/wp-content/uploads/b.png", 'alt_text' => '', 'title' => ['rendered' => 'IMG_0042'], 'mime_type' => 'image/png', 'post' => 0],
        ['id' => 62, 'source_url' => "$SITE/wp-content/uploads/c.png", 'alt_text' => 'Own text', 'title' => ['rendered' => 'Other title'], 'mime_type' => 'image/png', 'post' => 0],
    ],
    '/wp-json/wp/v2/pages' => [],
    '/wp-json/wp/v2/posts' => [
        ['id' => 20, 'slug' => 'trip', 'link' => "$SITE/trip/", 'title' => ['rendered' => 'Trip'], 'content' => ['rendered' =>
            '<p><img class="alignleft" title="On the hill" src="' . $SITE . '/wp-content/uploads/a.png"> We went out.</p><p>https://youtu.be/abc_DEF-123</p><p>Then we came back.</p>'],
            'excerpt' => ['rendered' => ''], 'date' => '2011-05-01T10:00:00', 'modified' => '', 'status' => 'publish', 'categories' => [], 'tags' => [], 'featured_media' => 0, 'parent' => 0, 'template' => ''],
        ['id' => 21, 'slug' => 'plain', 'link' => "$SITE/plain/", 'title' => ['rendered' => 'Plain'], 'content' => ['rendered' => '<p>No video here.</p>'],
            'excerpt' => ['rendered' => ''], 'date' => '2011-05-02T10:00:00', 'modified' => '', 'status' => 'publish', 'categories' => [], 'tags' => [], 'featured_media' => 0, 'parent' => 0, 'template' => ''],
    ],
];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$get = static function (string $url) use ($web, $SITE): ?array {
    $path = parse_url($url, PHP_URL_PATH);
    if (!str_starts_with($url, $SITE) || !isset($web[$path])) { return ['status' => 404, 'body' => '{}']; }
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    return ($query['page'] ?? '1') !== '1' ? ['status' => 400, 'body' => '{"code":"rest_post_invalid_page_number"}'] : ['status' => 200, 'body' => json_encode($web[$path])];
};
$download = static function (string $url, string $to) use ($png): int { file_put_contents($to, $png); return 200; };

$root = sys_get_temp_dir() . '/importermedia' . getmypid();
register_shutdown_function(static function () use ($root): void { @exec('rm -rf ' . escapeshellarg($root)); });
mkdir("$root/content", 0775, true);
mkdir("$root/uploads", 0775, true);
$media = new MediaLibrary("$root/content", "$root/uploads");
$media->ensureDirectories();
$importer = new WordPressImporter(new WordPressReader($SITE, $get), "$root/content", $media, new Taxonomies("$root/content", ['en']), $download, ['lang' => 'en', 'default_lang' => 'en', 'custom_dir' => "$root/custom"], null, [], static function (): void {});
$importer->apply($importer->plan());

$front = static function (string $path): array { [$yaml] = FrontMatter::split((string)file_get_contents($path)); return Yaml::parse($yaml); };
$bodyOf = static function (string $path): string { [, $body] = FrontMatter::split((string)file_get_contents($path)); return $body; };
$trip = $front("$root/content/posts/trip.md");
$idA = substr(sha1("$SITE/wp-content/uploads/a.png"), 0, 16);
check('a post with a video link becomes Text, Video, Text blocks and no body', [array_column($trip['blocks'], 'type'), trim($bodyOf("$root/content/posts/trip.md"))], [['text', 'video', 'text'], '']);
check('the video is the one linked, the texts are what was around it', [$trip['blocks'][1]['videos'], $trip['blocks'][2]['body']], [[['url' => 'https://www.youtube.com/watch?v=abc_DEF-123']], "Then we came back.\n"]);
check('the picture that floated is written with its side, in the text that goes around it', str_contains($trip['blocks'][0]['body'] . '', "![On the hill](/uploads/media/$idA.png){align=left} We went out."), true);
check('a post with no video is written as before', [array_key_exists('blocks', $front("$root/content/posts/plain.md")), trim($bodyOf("$root/content/posts/plain.md"))], [false, 'No video here.']);
$library = new MediaLibrary("$root/content", "$root/uploads");
$alt = static fn(string $file): string => (string)$library->find(substr(sha1("$SITE/wp-content/uploads/$file"), 0, 16))['alt'];
check('a file of the library with no alternative text takes its title, unless the title is a camera\'s name; one that has text keeps it', [$alt('a.png'), $alt('b.png'), $alt('c.png')], ['Spring meadow', '', 'Own text']);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
