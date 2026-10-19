<?php
/*
 * The cache of the maps: what is kept of a route for a list (summary), the answers kept under a stamp (GeoCache), the same answers
 * from the cache and without it, and what makes them be worked out again: a changed stamp, a replaced route file, an entry that is
 * not a saved file, a corrupt or unwritable cache. Also the token of the content index that the stamp is made from.
 *   php tests/unit/geo-cache.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use GnozioCMS\{ContentIndex, ContentItem, ContentRepository, GeoCache, GeoLibrary, GeoMap, GeoView, Images, SystemDatabase, SystemMetaRepository};
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

$work = sys_get_temp_dir() . '/geo-cache-' . getmypid();
mkdir($work . '/public/uploads/media', 0775, true);
mkdir($work . '/cache', 0775, true);
register_shutdown_function(static function () use ($work): void { @exec('rm -rf ' . escapeshellarg($work)); });

$gpx = static function (float $lat, float $lng, int $count = 60, float $climb = 40.0): string {
    $points = '';
    for ($i = 0; $i < $count; $i++) {
        $a = $i * 2 * M_PI / $count;
        $points .= sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>%d</ele></trkpt>', $lat + 0.02 * sin($a), $lng + 0.03 * cos($a), 100 + (int)($climb * sin(2 * $a)) + 60);
    }
    return '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>' . $points . '</trkseg></trk></gpx>';
};
file_put_contents($work . '/public/uploads/media/loop.gpx', $gpx(35.0, 26.0));
$images = new Images($work . '/public', $work . '/public/media');

// ---- what is kept of a route for a list
$library = new GeoLibrary($images, $work . '/cache');
$summary = $library->summary('/uploads/media/loop.gpx');
$track = $library->track('/uploads/media/loop.gpx');
check('a summary holds the facts of the whole recording, an overview line and the short line distances are measured to', [json_encode($summary['stats']) === json_encode($track['stats']), json_encode($summary['overview']) === json_encode(GeoLibrary::overview($track)), count($summary['near']) > 2 && count($summary['near']) <= GeoLibrary::NEAR_POINTS], [true, true, true]);
check('it is kept in a file of its own, next to the copy of the route', count(glob($work . '/cache/geo/*.sum.json') ?: []), 1);
$again = (new GeoLibrary($images, $work . '/cache'))->summary('/uploads/media/loop.gpx');
check('and read from there, the same', $again, $summary);
check('what is not a route file has no summary', [$library->summary(''), $library->summary('/uploads/media/missing.gpx'), $library->summary('https://example.com/x.gpx')], [null, null, null]);

// files used while something is worked out, and whether they are as they were
$library->watch();
$fresh = new GeoLibrary($images, $work . '/cache');
$fresh->watch();
$fresh->summary('/uploads/media/loop.gpx');
$files = $fresh->unwatch();
$library->unwatch();
check('the route files an answer was made from are noted with their time and size', [array_map('basename', array_keys($files)), preg_match('/^\d+\|\d+$/', (string)reset($files))], [['loop.gpx'], 1]);
check('and are found unchanged while they are', GeoLibrary::unchanged($files), true);
file_put_contents($work . '/public/uploads/media/loop.gpx', $gpx(35.0, 26.0, 80, 70.0));
touch($work . '/public/uploads/media/loop.gpx', time() + 30);
clearstatcache();
check('a replaced file is found changed, and a file that is gone too', [GeoLibrary::unchanged($files), GeoLibrary::unchanged(['/nowhere/x.gpx' => '1|1'])], [false, false]);
$replaced = (new GeoLibrary($images, $work . '/cache'))->summary('/uploads/media/loop.gpx');
check('a replaced file is summarized again', $replaced['stats'] !== $summary['stats'] && count(glob($work . '/cache/geo/*.sum.json') ?: []) === 2, true);

// ---- answers kept under a stamp
$stamp = 'one';
$cache = function (?string $dir = null) use (&$stamp, $work, $images): GeoCache {
    return new GeoCache($dir ?? $work . '/answers', function () use (&$stamp): ?string { return $stamp; }, new GeoLibrary($images, $work . '/cache'));
};
$built = 0;
$ask = static function (GeoCache $cache, string $kind = 'k', array $key = [1]) use (&$built): mixed {
    return $cache->remember($kind, $key, function () use (&$built): array { $built++; return ['a' => 1.5, 'b' => [1, 2], 'c' => []]; });
};
$first = $ask($cache());
$second = $ask($cache());
check('an answer is worked out once and then read, exactly as it was', [$built, $second === $first, $second], [1, true, ['a' => 1.5, 'b' => [1, 2], 'c' => []]]);
$ask($cache(), 'k', [2]);
$ask($cache(), 'other', [1]);
check('another question, or another kind, is another answer', $built, 3);
$stamp = 'two';
$ask($cache());
check('a new stamp works the answer out again and deletes what the old one kept', [$built, count(glob($work . '/answers/*', GLOB_ONLYDIR) ?: []), count(glob($work . '/answers/*/*.ser') ?: [])], [4, 1, 1]);
$stamp = null;
$ask($cache());
$ask($cache());
check('with no stamp (no index, or an unsaved preview) nothing is kept: every ask is worked out', $built, 6);
$stamp = 'two';
$ask($cache());
check('and what was kept under the stamp is still there when it returns', $built, 6);
$file = glob($work . '/answers/*/k-*.ser')[0];
file_put_contents($file, 'not serialized');
$ask($cache());
check('a corrupt answer is worked out again and written over', [$built, unserialize((string)file_get_contents($file), ['allowed_classes' => false])['value']['a']], [7, 1.5]);
file_put_contents($work . '/blocker', '');
$ask($cache($work . '/blocker/under'));
$ask($cache($work . '/blocker/under'));
check('a cache that cannot be written only costs the time it saves', $built, 9);
check('no temporary files are left', glob($work . '/answers/*/*.tmp') ?: [], []);

// who is looking: a visitor and an editor are each kept their own, and neither takes the other's place
$stamp = 'audience';
$who = 'visitor';
$audience = function () use (&$stamp, &$who, $work, $images): GeoCache {
    return new GeoCache($work . '/answers', function () use (&$stamp): ?string { return $stamp; }, new GeoLibrary($images, $work . '/cache'), function () use (&$who): string { return $who; });
};
$before = $built;
$ask($audience());
$who = 'editor';
$ask($audience());
$who = 'visitor';
$ask($audience());
$who = 'editor';
$ask($audience());
check('answers for a visitor and for an editor are worked out once each and both stay', [$built - $before, count(glob($work . '/answers/*/k-*.ser') ?: [])], [2, 2]);

// an answer made from a route file is not served after the file is replaced behind the site's back
$stamp = 'three';
$made = 0;
$fromFile = function () use ($images, $work, &$made): array {
    $library = new GeoLibrary($images, $work . '/cache');
    $c = new GeoCache($work . '/answers', fn() => 'three', $library);
    return $c->remember('file', ['x'], function () use ($library, &$made): array { $made++; return ['km' => $library->summary('/uploads/media/loop.gpx')['stats']['distance_km']]; });
};
$a = $fromFile();
$b = $fromFile();
check('an answer made from a route file is kept while the file is as it was', [$made, $a === $b], [1, true]);
file_put_contents($work . '/public/uploads/media/loop.gpx', $gpx(35.0, 26.0, 60, 10.0) . ' ');
touch($work . '/public/uploads/media/loop.gpx', time() + 60);
clearstatcache();
$c = $fromFile();
check('and worked out again when the file is replaced, with no change of the stamp', [$made, $c['km'] === $a['km']], [2, false]);

// ---- the same data with the cache and without it
$publicLoop = fn(string $slug, array $fields, array $meta = [], string $path = '/p', int $mtime = 1700000000): ContentItem => new ContentItem(
    $fields['kind'] ?? 'points', $slug, 'en', ['title' => ucfirst($slug), 'excerpt' => 'About ' . $slug, 'custom_fields' => $fields] + $meta, '', '<p>Text</p>', $path === '' ? '' : $path . '/' . $slug . '.md', $path === '' ? 0 : $mtime
);
file_put_contents($work . '/public/uploads/media/loop.gpx', $gpx(35.0, 26.0));
touch($work . '/public/uploads/media/loop.gpx', time() + 100);
file_put_contents($work . '/public/uploads/media/loop2.gpx', $gpx(35.2, 26.2, 90));
clearstatcache();
$entries = [
    'routes' => [$publicLoop('loop', ['kind' => 'routes', 'track' => '/uploads/media/loop.gpx'], ['categories' => ['hiking']]), $publicLoop('loop-two', ['kind' => 'routes', 'track' => '/uploads/media/loop2.gpx', 'distance_km' => 9.5])],
    'points' => [$publicLoop('near', ['location' => '35.021, 26.001']), $publicLoop('far', ['location' => '36.5, 27.5']), $publicLoop('nowhere', ['area' => 'x'])],
    'businesses' => [$publicLoop('shop', ['kind' => 'businesses', 'location' => '35.041, 26.0'])],
];
$items = function (string $type, string $lang) use (&$entries): array { return $lang === 'en' ? ($entries[$type] ?? []) : []; };
$make = fn(?GeoCache $geoCache): GeoView => new GeoView(
    new GeoMap(new GeoLibrary($images, $work . '/cache'), $images), $items, fn(string $t, string $l): string => ucfirst($t), fn(string $s, string $l): string => ucfirst($s),
    fn(ContentItem $i, string $l): string => '/' . $i->type . '/' . $i->slug, fn(): array => ['points', 'routes', 'businesses'], ['tiles_url' => '', 'attribution' => ''], $geoCache
);
$stamp = 'view';
$plain = $make(null);
$kept = fn(): GeoView => $make($cache($work . '/view'));
$options = ['types' => ['routes', 'points', 'businesses'], 'radius' => 5.0, 'limit' => ['routes' => 3, 'points' => 3]];
$all = array_merge($entries['routes'], $entries['points'], $entries['businesses']);
for ($pass = 1; $pass <= 2; $pass++) {
    $label = $pass === 1 ? ' (worked out)' : ' (read back)';
    check('a map of entries is the same with and without the cache' . $label, $kept()->dataset($all, 'en', ['lines' => true]) === $plain->dataset($all, 'en', ['lines' => true]), true);
    check('the map of a page of one is the same' . $label, $kept()->single($entries['routes'][0], 'en', $options) === $plain->single($entries['routes'][0], 'en', $options), true);
    check('and so are the entries near one, with their distances' . $label, $kept()->nearby($entries['points'][0], ['routes', 'businesses'], 5.0, 5, 'en') == $plain->nearby($entries['points'][0], ['routes', 'businesses'], 5.0, 5, 'en'), true);
    check('and the map of the entries of some types' . $label, $kept()->collection(['points', 'routes'], 'en', 'hiking', ['limit' => 10]) === $plain->collection(['points', 'routes'], 'en', 'hiking', ['limit' => 10]), true);
}
$near = $kept()->nearby($entries['points'][0], ['routes', 'businesses'], 5.0, 5, 'en');
check('what comes back from the cache are the entries themselves, in order of distance', [$near[0]['item'] === $entries['routes'][0] || $near[0]['item'] === $entries['businesses'][0], array_map(fn(array $n): string => $n['item']->slug, $near)], [true, ['loop', 'shop']]);
$entries['businesses'][0] = $publicLoop('shop', ['kind' => 'businesses', 'location' => '35.041, 26.0', 'area' => 'Renamed area']);
$near = $kept()->nearby($entries['points'][0], ['routes', 'businesses'], 5.0, 5, 'en');
check('the titles and the rest are the content\'s today, not those of the day the list was made', $near[1]['item']->meta['custom_fields']['area'], 'Renamed area');

// an entry that is not a saved file (the preview of an unsaved one) is never kept
$unsaved = $publicLoop('draft-pin', ['location' => '35.0, 26.0'], [], '');
$before = count(glob($work . '/view/*/*.ser') ?: []);
$kept()->single($unsaved, 'en', $options);
$kept()->dataset([$unsaved, $entries['points'][0]], 'en');
$kept()->nearby($unsaved, ['points'], 5.0, 5, 'en');
check('an unsaved entry gets nothing written, in a map of one page, of a list or near it', count(glob($work . '/view/*/*.ser') ?: []), $before);
check('and a changed entry (another time) is another question', $kept()->dataset([$publicLoop('near', ['location' => '35.5, 26.5'], [], '/p', 1700000999)], 'en')['items'][0]['lat'], 35.5);

// ---- the token of the content
$database = new SystemDatabase($work . '/storage');
$database->initialize();
$meta = new SystemMetaRepository($database);
mkdir($work . '/content/points', 0775, true);
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$repository = new ContentRepository($work . '/content', new MarkdownConverter($environment), ['languages' => ['default' => 'en']]);
$index = new ContentIndex($database, $work . '/content', $meta);
check('there is no token before the index has said anything', $index->revision(), '');
file_put_contents($work . '/content/points/a.md', "---\ntitle: A\nstatus: published\n---\nx\n");
$index->rebuild($repository, ['points']);
$t1 = $index->revision();
check('a rebuild gives a token', strlen($t1) > 8, true);
$index->upsert($repository->parseFile('points', $work . '/content/points/a.md'));
$t2 = $index->revision();
$index->remove('points', 'a', 'en');
$t3 = $index->revision();
$index->bump();
$t4 = $index->revision();
check('a save, a delete and a bump each change it', count(array_unique([$t1, $t2, $t3, $t4])), 4);
$index->refresh($repository, ['points']);
$t4 = $index->revision();
$index->refresh($repository, ['points']);
check('a refresh that finds nothing new leaves it', $index->revision(), $t4);
file_put_contents($work . '/content/points/b.md', "---\ntitle: B\nstatus: published\n---\nx\n");
$index->refresh($repository, ['points']);
$t5 = $index->revision();
file_put_contents($work . '/content/points/b.md', "---\ntitle: B2\nstatus: published\n---\nx\n");
touch($work . '/content/points/b.md', time() + 50);
$index->refresh($repository, ['points']);
unlink($work . '/content/points/b.md');
$t6 = $index->revision();
$index->refresh($repository, ['points']);
check('a file added, changed or deleted outside the admin changes it when a refresh finds it', [$t5 !== $t4, $t5 !== $t6, $index->revision() !== $t6], [true, true, true]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
