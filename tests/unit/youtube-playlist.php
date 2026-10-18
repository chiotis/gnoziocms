<?php
/*
 * The videos of a YouTube playlist for the Playlist block: reading an address, the public feed, the Data API, falling back to the
 * feed when the key does not work, keeping what was fetched (and showing it when YouTube cannot be asked), testing a key, the
 * pictures kept on this site, and how the key is kept in the settings.
 *   php tests/unit/youtube-playlist.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{SiteSettings, SystemDatabase, SystemMetaRepository, YouTubePlaylist, YouTubeThumbs};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/yt' . getmypid();
mkdir("$dir/storage/db", 0775, true);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);

// ---- reading an address
$pl = 'PLBCF2DAC6FFB574DE';
check('the id itself', YouTubePlaylist::parseId($pl), $pl);
check('an address of a playlist', YouTubePlaylist::parseId("https://www.youtube.com/playlist?list=$pl"), $pl);
check('a video address that belongs to a playlist', YouTubePlaylist::parseId("https://www.youtube.com/watch?v=GvgqDSnpRQM&list=$pl&index=2"), $pl);
check('music and short addresses', [YouTubePlaylist::parseId("https://music.youtube.com/playlist?list=$pl"), YouTubePlaylist::parseId("https://youtu.be/GvgqDSnpRQM?list=$pl")], [$pl, $pl]);
check('spaces around are fine', YouTubePlaylist::parseId("  $pl \n"), $pl);
check('a video id is not a playlist', YouTubePlaylist::parseId('GvgqDSnpRQM'), '');
check('another site, no list, or nonsense is nothing', [YouTubePlaylist::parseId("https://evil.test/playlist?list=$pl"), YouTubePlaylist::parseId('https://www.youtube.com/watch?v=GvgqDSnpRQM'), YouTubePlaylist::parseId('<script>'), YouTubePlaylist::parseId('')], ['', '', '', '']);

// ---- lengths
check('a length is written as minutes and seconds, or with hours', [YouTubePlaylist::duration(65), YouTubePlaylist::duration(3725), YouTubePlaylist::duration(9), YouTubePlaylist::duration(600)], ['1:05', '1:02:05', '0:09', '10:00']);
check('an ISO length is read', [YouTubePlaylist::isoSeconds('PT1H2M3S'), YouTubePlaylist::isoSeconds('PT45S'), YouTubePlaylist::isoSeconds('PT10M'), YouTubePlaylist::isoSeconds('P1DT1H')], [3723, 45, 600, 90000]);
check('a live stream has no length', [YouTubePlaylist::isoSeconds('P0D'), YouTubePlaylist::isoSeconds('nonsense')], [null, null]);

// ---- the public feed
$feed = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
 <yt:playlistId>$pl</yt:playlistId>
 <title>Our films</title>
 <author><name>The Studio</name></author>
 <entry>
  <yt:videoId>AAAAAAAAAAA</yt:videoId>
  <title>First &lt;b&gt;film&lt;/b&gt;</title>
  <published>2024-03-01T10:00:00+00:00</published>
  <media:group><media:title>First</media:title><media:description>About the first.</media:description><media:community><media:statistics views="1200"/></media:community></media:group>
 </entry>
 <entry>
  <yt:videoId>BBBBBBBBBBB</yt:videoId>
  <title>Second</title>
  <published>2025-06-01T10:00:00+00:00</published>
  <media:group><media:description>About the second.</media:description><media:community><media:statistics views="50"/></media:community></media:group>
 </entry>
 <entry><yt:videoId>not-an-id</yt:videoId><title>Broken</title></entry>
</feed>
XML;
$calls = [];
$now = 1_800_000_000;
$clock = function () use (&$now) { return $now; };
$answer = ['status' => 200, 'body' => $feed];
$http = function (string $url) use (&$calls, &$answer): array { $calls[] = $url; return [$answer['status'], $answer['body']]; };
$yt = new YouTubePlaylist($meta, '', $http, 6, 'https://yt.test/feed', 'https://api.test/v3', $clock);
$r = $yt->fetch($pl);
check('a playlist from the feed: where it came from, its title and who made it', [$r['ok'], $r['source'], $r['title'], $r['channel']], [true, 'feed', 'Our films', 'The Studio']);
check('its videos, with a broken one left out', array_column($r['items'], 'id'), ['AAAAAAAAAAA', 'BBBBBBBBBBB']);
check('each with text kept as text, the date and the views', [$r['items'][0]['title'], $r['items'][0]['description'], $r['items'][0]['published'], $r['items'][0]['views'], $r['items'][0]['watch_url']], ['First <b>film</b>', 'About the first.', strtotime('2024-03-01T10:00:00+00:00'), 1200, 'https://www.youtube.com/watch?v=AAAAAAAAAAA']);
check('the feed does not say how long a video is', [$r['items'][0]['seconds'], $r['items'][0]['duration']], [null, '']);
check('the feed was asked by playlist', $calls, ["https://yt.test/feed?playlist_id=$pl"]);

// ---- what was fetched is kept
$yt->fetch($pl);
check('a playlist that was fetched is not fetched again for a while', count($calls), 1);
$now += 5 * 3600;
$yt->fetch($pl);
check('not even after five hours when it is kept for six', count($calls), 1);
$now += 2 * 3600;
$answer = ['status' => 200, 'body' => str_replace('Second', 'Second, changed', $feed)];
$r = $yt->fetch($pl);
check('after that it is fetched again', [count($calls), $r['items'][1]['title'], $r['stale']], [2, 'Second, changed', false]);

// ---- YouTube cannot be asked: what was kept is shown, and nobody waits again soon
$now += 7 * 3600;
$answer = ['status' => 0, 'body' => ''];
$r = $yt->fetch($pl);
check('the copy that was kept is shown, and said to be old', [$r['ok'], $r['stale'], count($r['items']), $r['error']], [true, true, 2, 'YouTube did not answer. Check that this server can reach the internet.']);
$before = count($calls);
$yt->fetch($pl);
$yt->fetch($pl);
check('YouTube is not asked again for five minutes', count($calls) - $before, 0);
$now += 301;
$answer = ['status' => 200, 'body' => $feed];
$r = $yt->fetch($pl);
check('and then it is, and the playlist is fresh again', [count($calls) - $before, $r['stale']], [1, false]);

$other = 'PLzzzzzzzzzzzzzzzz';
$answer = ['status' => 404, 'body' => ''];
$r = $yt->fetch($other);
check('a playlist that does not exist, with nothing kept, says so', [$r['ok'], $r['items'], str_contains($r['error'], 'not found')], [false, [], true]);
$calls = [];
$yt->fetch($other);
check('and is not asked again at once', $calls, []);
$answer = ['status' => 200, 'body' => '<html>not a feed</html>'];
check('something that is not a feed is an error', $yt->fetch('PLyyyyyyyyyyyyyyyy')['ok'], false);
$answer = ['status' => 200, 'body' => '<?xml version="1.0"?><!DOCTYPE feed [<!ENTITY x SYSTEM "file:///etc/hostname">]><feed xmlns="http://www.w3.org/2005/Atom"><title>&x;</title></feed>'];
$r = $yt->fetch('PLxxxxxxxxxxxxxxxx');
check('a feed that points at a file is not followed', [$r['ok'], $r['title']], [false, '']);

// ---- the Data API
$apiCalls = [];
$api = function (string $url) use (&$apiCalls, &$feed): array {
    $apiCalls[] = $url;
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    if (str_contains($url, '/playlists?')) {
        return [200, json_encode(['items' => [['snippet' => ['title' => 'API title', 'channelTitle' => 'API channel']]]])];
    }
    if (str_contains($url, '/playlistItems?')) {
        return [200, json_encode(['items' => [
            ['contentDetails' => ['videoId' => 'AAAAAAAAAAA', 'videoPublishedAt' => '2024-03-01T10:00:00Z'], 'status' => ['privacyStatus' => 'public'], 'snippet' => ['title' => 'One', 'description' => 'First video']],
            ['contentDetails' => ['videoId' => 'BBBBBBBBBBB'], 'status' => ['privacyStatus' => 'private'], 'snippet' => ['title' => 'Private video', 'description' => '']],
            ['contentDetails' => ['videoId' => 'CCCCCCCCCCC', 'videoPublishedAt' => '2025-01-01T10:00:00Z'], 'status' => ['privacyStatus' => 'unlisted'], 'snippet' => ['title' => 'Three', 'description' => '']],
            ['contentDetails' => ['videoId' => 'DDDDDDDDDDD'], 'status' => ['privacyStatus' => 'public'], 'snippet' => ['title' => 'Gone', 'description' => '']],
        ]])];
    }
    if (str_contains($url, '/videos?')) {
        return [200, json_encode(['items' => [
            ['id' => 'AAAAAAAAAAA', 'contentDetails' => ['duration' => 'PT3M5S'], 'statistics' => ['viewCount' => '4200']],
            ['id' => 'CCCCCCCCCCC', 'contentDetails' => ['duration' => 'PT1H1M1S'], 'statistics' => []],
        ]])];
    }
    return [404, ''];
};
$withKey = new YouTubePlaylist($meta, 'KEY123', $api, 6, 'https://yt.test/feed', 'https://api.test/v3', $clock);
$r = $withKey->fetch($pl);
check('with a key the Data API is used', [$r['ok'], $r['source'], $r['title'], $r['channel']], [true, 'api', 'API title', 'API channel']);
check('a private video and one YouTube no longer lists are left out', array_column($r['items'], 'id'), ['AAAAAAAAAAA', 'CCCCCCCCCCC']);
check('lengths and views come with it', [$r['items'][0]['duration'], $r['items'][0]['views'], $r['items'][1]['duration'], $r['items'][1]['views']], ['3:05', 4200, '1:01:01', null]);
check('it takes three questions, each with the key', [count($apiCalls), count(array_filter($apiCalls, fn($u) => str_contains($u, 'key=KEY123')))], [3, 3]);
check('the copy of the feed is not used for the key', $meta->getJson('youtube.' . substr(sha1($pl . '|api'), 0, 24)) !== null && $meta->getJson('youtube.' . substr(sha1($pl . '|api'), 0, 24)) !== $meta->getJson('youtube.' . substr(sha1($pl . '|feed'), 0, 24)), true);

// ---- the key does not work: the feed is the way out
$badKey = function (string $url) use ($feed): array {
    if (str_starts_with($url, 'https://api.test')) {
        return [403, json_encode(['error' => ['errors' => [['reason' => 'quotaExceeded']], 'message' => 'quota']])];
    }
    return [200, $feed];
};
$r = (new YouTubePlaylist($meta, 'USEDUP', $badKey, 6, 'https://yt.test/feed', 'https://api.test/v3', $clock))->fetch('PLwwwwwwwwwwwwwwww');
check('a key over its quota falls back to the feed, and says why', [$r['ok'], $r['source'], str_contains($r['note'], 'daily quota')], [true, 'feed', true]);
$notFound = function (string $url): array { return str_contains($url, '/playlists?') ? [200, '{"items":[]}'] : [200, '{}']; };
$r = (new YouTubePlaylist($meta, 'KEY', $notFound, 6, 'https://yt.test/feed', 'https://api.test/v3', $clock))->fetch('PLvvvvvvvvvvvvvvvv');
check('a playlist the API does not know is not looked for in the feed', [$r['ok'], str_contains($r['error'], 'not found')], [false, true]);

// ---- testing a key
$tester = fn(int $status, string $body) => new YouTubePlaylist($meta, 'K', fn() => [$status, $body], 6);
check('a key that works', $tester(200, '{"items":[]}')->test(), ['ok' => true, 'message' => 'The key works.']);
check('a key YouTube does not accept', $tester(400, '{"error":{"errors":[{"reason":"badRequest"}]}}')->test()['message'], 'YouTube does not accept the key.');
check('a key for which the API is not switched on', str_contains($tester(403, '{"error":{"errors":[{"reason":"accessNotConfigured"}],"message":"x"}}')->test()['message'], 'not switched on'), true);
check('no answer', str_contains($tester(0, '')->test()['message'], 'did not answer'), true);
check('no key, nothing to test', (new YouTubePlaylist($meta, '', fn() => [200, ''], 6))->test()['ok'], false);

// ---- the pictures kept on this site
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 800);
$asked = [];
$thumbs = new YouTubeThumbs("$dir/cache", $meta, function (string $url) use (&$asked, $jpeg): array { $asked[] = $url; return str_contains($url, 'hqdefault') ? [200, $jpeg] : [404, '']; });
$path = $thumbs->path('AAAAAAAAAAA');
check('the address of a picture carries a signature', preg_match('#^_yt/AAAAAAAAAAA\.jpg\?s=[0-9a-f]{16}$#', $path) === 1, true);
parse_str((string)parse_url($path, PHP_URL_QUERY), $q);
check('only that signature is good, for that video', [$thumbs->isValid('AAAAAAAAAAA', $q['s']), $thumbs->isValid('BBBBBBBBBBB', $q['s']), $thumbs->isValid('AAAAAAAAAAA', 'x'), $thumbs->isValid('short', $q['s'])], [true, false, false, false]);
check('the signature does not change from one request to the next', $thumbs->path('AAAAAAAAAAA'), $path);
$file = $thumbs->file('AAAAAAAAAAA');
check('a picture is fetched the first time it is asked for, and kept', [$file === "$dir/cache/AAAAAAAAAAA.jpg", is_file($file), count($asked)], [true, true, 1]);
$thumbs->file('AAAAAAAAAAA');
check('and not fetched again', count($asked), 1);
touch($file, time() - 31 * 86400);
clearstatcache(); // touch() does not clear PHP's file status cache on every version, and the file's age is what is being tested
$thumbs->file('AAAAAAAAAAA');
check('after 30 days it is', count($asked), 2);
$none = new YouTubeThumbs("$dir/cache", $meta, fn() => [404, '']);
check('a video with no picture has none', $none->file('ZZZZZZZZZZZ'), null);
$notImage = new YouTubeThumbs("$dir/cache", $meta, fn() => [200, '<html>' . str_repeat('x', 900)]);
check('something that is not a picture is not kept', [$notImage->file('YYYYYYYYYYY'), is_file("$dir/cache/YYYYYYYYYYY.jpg")], [null, false]);
check('only the form of a video id is a file name', $thumbs->file('../../etc/passwd'), null);
touch($file, time() - 40 * 86400);
check('an old copy is kept when YouTube cannot be asked', $none->file('AAAAAAAAAAA'), $file);

// ---- how the key is kept in the settings
$store = new SiteSettings($meta, "$dir/content");
$raw = $store->raw('site_settings', $store->defaults());
$form = fn(array $over) => $over + SiteSettings::formFromPost(['title' => 'Site']);
$store->save($raw, $form(['youtube_key' => 'AIzaKEY', 'youtube_cache_hours' => '24']));
$loaded = $store->load();
check('a key and how long to keep a playlist are saved', [$loaded['apis']['youtube']['key'], $loaded['apis']['youtube']['cache_hours']], ['AIzaKEY', 24]);
$formValues = $store->formValues($store->parse($store->raw('site_settings', $store->defaults())));
check('the key is not given back to the form, only that there is one', [$formValues['youtube_key'], $formValues['youtube_key_set'], $formValues['youtube_cache_hours']], ['', true, '24']);
$store->save($store->raw('site_settings', $store->defaults()), $form(['youtube_key' => '']));
check('a blank key keeps the one saved, and a form without the choice keeps it', [$store->load()['apis']['youtube']['key'], $store->load()['apis']['youtube']['cache_hours']], ['AIzaKEY', 24]);
$store->save($store->raw('site_settings', $store->defaults()), $form(['youtube_cache_hours' => '7']));
check('a number of hours that is not one of the choices is six', $store->load()['apis']['youtube']['cache_hours'], 6);
$store->save($store->raw('site_settings', $store->defaults()), $form(['clear_secrets' => ['youtube_key']]));
check('a key can be removed', $store->load()['apis']['youtube']['key'], '');

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
