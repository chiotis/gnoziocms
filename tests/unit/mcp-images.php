<?php
/*
 * Pictures from an address (MCP add_image_from_url), without the network: SafeHttp refuses other schemes and ports, a name and
 * password in the address, a host that resolves to a private or reserved address (also one of several, and after a redirect),
 * too many redirects, an answer that is not 200 and a file over the limit; it connects to the address it checked. The tool keeps
 * only real pictures, the same picture once (by its fingerprint), with its alt text, and gives its address for main_image. A file
 * from the chat (ChatGPT's file parameter) is fetched from its download_url with the same checks.
 *   php tests/unit/mcp-images.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{BlockRegistry, ContentEditor, ContentIndex, ContentRepository, ContentTypes, HtmlGuard, McpContent, McpStore, MediaLibrary, RedirectRepository, RevisionRepository, SafeHttp, SystemDatabase, Taxonomies, Theme};
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

$dir = sys_get_temp_dir() . '/mcpimg' . getmypid();
foreach (['content/posts', 'content/taxonomies', 'storage/db', 'public/uploads/media'] as $d) { mkdir("$dir/$d", 0775, true); }
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$dns = ['photos.example' => ['93.184.216.34'], 'inside.example' => ['10.0.0.5'], 'mixed.example' => ['93.184.216.34', '192.168.1.9'], 'hop.example' => ['93.184.216.35'], 'files.chat.example' => ['93.184.216.40'], 'inside.chat.example' => ['10.1.1.1']];
$pages = [];
$connected = [];
$http = new SafeHttp(
    fn(string $host): array => $dns[$host] ?? [],
    function (string $url, string $ip, string $to, int $max, int $timeout) use (&$pages, &$connected): array {
        $connected[] = $ip;
        $page = $pages[$url] ?? ['status' => 404];
        if (isset($page['body'])) {
            if (strlen($page['body']) > $max) {
                return ['status' => 0, 'location' => '', 'error' => 'The file is larger than the limit.'];
            }
            file_put_contents($to, $page['body']);
        }
        return ['status' => $page['status'], 'location' => $page['location'] ?? '', 'error' => ''];
    }
);
$tmp = "$dir/download";
$get = fn(string $url): array => $http->download($url, $tmp, 1000);

// ---- addresses
$pages['https://photos.example/a.png'] = ['status' => 200, 'body' => $png];
check('a public address is fetched', $get('https://photos.example/a.png')['ok'], true);
check('connecting to the address that was checked', end($connected), '93.184.216.34');
foreach ([
    'ftp://photos.example/a.png' => 'Only http and https',
    'file:///etc/passwd' => 'Only http and https',
    'https://photos.example:8443/a.png' => 'usual ports',
    'https://u:p@photos.example/a.png' => 'name and password',
    'http://inside.example/a.png' => 'private or reserved',
    'http://mixed.example/a.png' => 'private or reserved',
    'http://127.0.0.1/a.png' => 'private or reserved',
    'http://[::1]/a.png' => 'private or reserved',
    'http://169.254.169.254/latest/meta-data' => 'private or reserved',
    'http://nowhere.example/a.png' => 'could not be found',
] as $url => $why) {
    $r = $get($url);
    check('refused: ' . $url, [$r['ok'], str_contains($r['error'], $why)], [false, true]);
}
$pages['https://hop.example/to-inside'] = ['status' => 302, 'location' => 'http://inside.example/secret'];
check('a redirect to a private address is refused', str_contains($get('https://hop.example/to-inside')['error'], 'private'), true);
$pages['https://hop.example/rel'] = ['status' => 301, 'location' => '/a.png'];
$pages['https://hop.example/a.png'] = ['status' => 200, 'body' => $png];
check('a relative redirect is followed on the same host', $get('https://hop.example/rel'), ['ok' => true, 'error' => '', 'url' => 'https://hop.example/a.png']);
for ($i = 0; $i < 5; $i++) { $pages["https://hop.example/loop$i"] = ['status' => 302, 'location' => 'https://hop.example/loop' . ($i + 1)]; }
check('more than three redirects are refused', $get('https://hop.example/loop0')['error'], 'Too many redirects.');
check('an answer that is not 200 is refused', $get('https://photos.example/missing.png')['error'], 'The address answered 404.');
$pages['https://photos.example/big.png'] = ['status' => 200, 'body' => str_repeat('x', 2000)];
check('a file over the limit is refused', $get('https://photos.example/big.png')['ok'], false);

// ---- kinds
foreach (['jpg' => "\xFF\xD8\xFF\xE0rest", 'png' => $png, 'gif' => 'GIF89a....', 'webp' => "RIFF\0\0\0\0WEBPVP8 ", 'avif' => "\0\0\0\x1CftypavifX", '' => '<svg onload=alert(1)>'] as $kind => $bytes) {
    file_put_contents($tmp, $bytes);
    check('a ' . ($kind ?: 'non-picture') . ' is told by its bytes', SafeHttp::imageKind($tmp), $kind);
}

// ---- the tool
$settings = ['languages' => ['default' => 'en', 'available' => ['en']], 'content_types' => ['posts']];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$index = new ContentIndex($db, "$dir/content");
$theme = new Theme($repo, 'default');
$registry = new BlockRegistry($theme);
$taxonomies = new Taxonomies("$dir/content", ['en']);
$editor = new ContentEditor("$dir/content", $settings, $content, $index, new ContentTypes($theme), $theme, new RedirectRepository($db), new HtmlGuard(fn() => $environment, fn() => $registry), fn() => $registry, fn(?string $type = null) => $taxonomies->names($type), new RevisionRepository($db));
$media = new MediaLibrary("$dir/content", "$dir/public/uploads");
$grew = 0;
$room = true;
$tools = new McpContent($content, $editor, $index, new ContentTypes($theme), $taxonomies, new McpStore($db), $settings, "$dir/public", fn(string $p): string => 'https://site.test' . $p, function (): void {}, $media, $http, function (int $bytes) use (&$room): bool { return $room; }, function (int $bytes) use (&$grew): void { $grew += $bytes; });
$connection = ['id' => 1, 'name' => 'Job', 'username' => 'newsroom', 'scope' => 'content:read content:write content:publish media:write', 'policy' => '{}'];
$add = static fn(array $args) => $tools->call('add_image_from_url', $args, $connection);

$pages['https://photos.example/storm-over-athens.png'] = ['status' => 200, 'body' => $png];
$first = $add(['url' => 'https://photos.example/storm-over-athens.png', 'alt' => 'Clouds over the Acropolis']);
check('a picture is added with its address', [$first['existing'], str_starts_with($first['path'], '/uploads/media/'), $first['url'] === 'https://site.test' . $first['path']], [false, true, true]);
check('kept as the picture, with its alt text and who added it', [is_file("$dir/public" . $first['path']), $media->find($first['id'])['alt'], $media->find($first['id'])['uploaded_by']], [true, 'Clouds over the Acropolis', 'newsroom (MCP: Job)']);
check('the storage figure follows', $grew, strlen($png));
$pages['https://photos.example/copy.png'] = ['status' => 200, 'body' => $png];
$again = $add(['url' => 'https://photos.example/copy.png', 'alt' => 'Same']);
check('the same picture from another address is the one kept', [$again['existing'], $again['id'], $grew], [true, $first['id'], strlen($png)]);
$pages['https://photos.example/page.png'] = ['status' => 200, 'body' => '<html>not a picture</html>'];
try { $add(['url' => 'https://photos.example/page.png', 'alt' => 'x']); $r = 'saved'; } catch (\InvalidArgumentException $e) { $r = $e->getMessage(); }
check('something that is not a picture is refused', str_contains($r, 'not a JPEG'), true);
try { $add(['url' => 'https://photos.example/storm-over-athens.png']); $r = 'saved'; } catch (\InvalidArgumentException $e) { $r = $e->getMessage(); }
check('alt text is required', str_contains($r, 'alt'), true);
$room = false;
$pages['https://photos.example/new.gif'] = ['status' => 200, 'body' => 'GIF89a' . str_repeat("\0", 20)];
try { $add(['url' => 'https://photos.example/new.gif', 'alt' => 'x']); $r = 'saved'; } catch (\InvalidArgumentException $e) { $r = $e->getMessage(); }
check('a full site takes no new picture', str_contains($r, 'storage is full'), true);
check('no temporary file is left', glob(sys_get_temp_dir() . '/mcpimg*') === array_values(array_filter(glob(sys_get_temp_dir() . '/mcpimg*'), 'is_dir')), true);

// ---- a file from the chat (ChatGPT's file parameter)
$definition = McpContent::definitions()['add_image_from_url'];
check('ChatGPT is told that file takes a file', [$definition['_meta']['openai/fileParams'] ?? null, $definition['inputSchema']['required']], [['file'], ['alt']]);
$room = true;
$pages['https://files.chat.example/f/abc?sig=1'] = ['status' => 200, 'body' => 'GIF89a' . str_repeat("\1", 20)];
$fromChat = $add(['file' => ['download_url' => 'https://files.chat.example/f/abc?sig=1', 'file_id' => 'file_abc', 'mime_type' => 'image/gif', 'file_name' => 'Harbour at dawn.gif'], 'alt' => 'A harbour']);
check('a file from the chat is added, named after its file name', [$fromChat['existing'], $media->find($fromChat['id'])['original_name']], [false, 'harbour-at-dawn.gif']);
foreach ([
    'url and file together' => [['url' => 'https://photos.example/a.png', 'file' => ['download_url' => 'https://files.chat.example/f/abc?sig=1'], 'alt' => 'x'], 'either url or file'],
    'neither url nor file' => [['alt' => 'x'], 'either url or file'],
    'a file id alone' => [['file' => 'file_abc', 'alt' => 'x'], 'without a download_url'],
    'a file with a private address' => [['file' => ['download_url' => 'https://inside.chat.example/f'], 'alt' => 'x'], 'private or reserved'],
] as $label => [$args, $why]) {
    try { $add($args); $r = 'saved'; } catch (\InvalidArgumentException $e) { $r = $e->getMessage(); }
    check('refused: ' . $label, str_contains($r, $why), true);
}

$entry = $tools->call('create_entry', ['type' => 'posts', 'title' => 'Storm', 'body' => 'Rain. ![Clouds](' . $first['path'] . ')', 'status' => 'draft', 'main_image' => $first['path']], $connection);
check('the picture can be the main image', str_contains((string)file_get_contents("$dir/content/posts/storm.md"), 'main_image: ' . $first['path']), true);

// ---- generate_main_image: the site makes the picture in its house style
try { $tools->call('generate_main_image', ['type' => 'posts', 'slug' => 'storm'], $connection); $r = 'queued'; } catch (\InvalidArgumentException $e) { $r = $e->getMessage(); }
check('without AI publishing the tool says what to use instead', str_contains($r, 'add_image_from_url'), true);
$asked = [];
$states = [];
$picturing = new McpContent($content, $editor, $index, new ContentTypes($theme), $taxonomies, new McpStore($db), $settings, "$dir/public", fn(string $p): string => 'https://site.test' . $p, function (): void {}, $media, $http, null, null,
    function (string $entry, string $brief, bool $replace, string $by) use (&$asked): array { $asked[] = [$entry, $brief, $replace, $by]; return ['ok' => true, 'job' => 7, 'error' => '']; },
    function (string $entry) use (&$states): ?array { return $states[$entry] ?? null; });
$created = $picturing->call('create_entry', ['type' => 'posts', 'title' => 'Harbour at dawn', 'body' => 'Boats.', 'status' => 'draft', 'image_brief' => 'Fishing boats at a quay at dawn.'], $connection);
$file = (string)file_get_contents("$dir/content/posts/harbour-at-dawn.md");
check('image_brief is kept with the entry', str_contains($file, 'image_brief: \'Fishing boats at a quay at dawn.\'') || str_contains($file, 'image_brief: Fishing boats at a quay at dawn.'), true);
$r = $picturing->call('generate_main_image', ['type' => 'posts', 'slug' => 'harbour-at-dawn'], $connection);
check('the picture is queued for the entry, by the connection', [$r['queued'], $asked[0][0], $asked[0][1], $asked[0][2], $asked[0][3]], [true, 'posts/harbour-at-dawn/en', '', false, 'newsroom (MCP: Job)']);
check('an entry with a main picture keeps it unless replace', [$picturing->call('generate_main_image', ['type' => 'posts', 'slug' => 'storm'], $connection)['queued'], count($asked)], [false, 1]);
$picturing->call('generate_main_image', ['type' => 'posts', 'slug' => 'storm', 'replace' => true, 'brief' => 'Dark clouds over a city.'], $connection);
check('replace with a brief of its own', [$asked[1][1], $asked[1][2]], ['Dark clouds over a city.', true]);
$states['posts/harbour-at-dawn/en'] = ['status' => 'done', 'error' => '', 'main_image' => '/uploads/media/x.webp'];
$got = $picturing->call('get_entry', ['type' => 'posts', 'slug' => 'harbour-at-dawn'], $connection);
check('get_entry shows image_brief and image_status', [$got['image_brief'], $got['image_status']['status']], ['Fishing boats at a quay at dawn.', 'done']);
check('the tool is listed for the media scope', in_array('media:write', McpContent::definitions()['generate_main_image']['scopes'], true), true);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
