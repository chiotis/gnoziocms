<?php
/*
 * Places and routes: positions, distances, line simplification, reading GPX, KML and GeoJSON, the facts of a route, what is
 * kept of a route file, what is near what, and the tiles a map draws.
 *   php tests/unit/geo.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use GnozioCMS\{Geo, GeoTrack, GeoLibrary, GeoMap, GeoView, Images, ContentItem, FieldSchema};
$fail = 0;
function check(string $label, $actual, $expected) { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . var_export($actual, true) . ' expected ' . var_export($expected, true)) . "\n"; }

// ---- a position
check('a position typed with a comma', Geo::parse('35.2012, 26.2744'), ['lat' => 35.2012, 'lng' => 26.2744]);
check('with a space, a semicolon, or as a geo: address', [Geo::parse('35.2 26.3'), Geo::parse('35.2;26.3'), Geo::parse('geo:35.2,26.3')], [['lat' => 35.2, 'lng' => 26.3], ['lat' => 35.2, 'lng' => 26.3], ['lat' => 35.2, 'lng' => 26.3]]);
check('and a negative one', Geo::parse('-33.9, 151.2'), ['lat' => -33.9, 'lng' => 151.2]);
check('what is not a position is not one', [Geo::parse(''), Geo::parse('abc'), Geo::parse('91, 10'), Geo::parse('10, 181'), Geo::parse('0, 0'), Geo::parse(12), Geo::parse(null)], [null, null, null, null, null, null, null]);
check('a position is written one way', [Geo::format(35.20120000, 26.2744), Geo::format(35.0, 26.5), Geo::format(-33.123456789, 151.2)], ['35.2012, 26.2744', '35, 26.5', '-33.123457, 151.2']);
$location = ['type' => 'location', 'default' => '', 'label' => 'x', 'key' => 'x'];
check('a location field keeps a position in the one way, and nothing else', [FieldSchema::clean($location, ' 35.20120 ,26.2744 '), FieldSchema::clean($location, 'nowhere'), FieldSchema::clean($location, '')], ['35.2012, 26.2744', '', '']);
check('a file field can ask for route files only', [FieldSchema::normalize(['f' => ['type' => 'file', 'kind' => 'track']])['f']['kind'], FieldSchema::normalize(['f' => ['type' => 'file', 'kind' => 'weird']])['f']['kind'], FieldSchema::normalize(['f' => ['type' => 'file']])['f']['kind']], ['track', 'all', 'all']);

// ---- distances
check('a degree of latitude is about 111 km', round(Geo::distanceKm(35, 26, 36, 26), 1), 111.2);
check('the same place is no distance', Geo::distanceKm(35.2, 26.2, 35.2, 26.2), 0.0);
$line = [[35.0, 26.0], [35.0, 26.1], [35.1, 26.1]];
check('how far from a line: near the middle of a segment', round(Geo::distanceToLineKm(35.01, 26.05, $line), 2), 1.11);
check('beyond its end, the end is the nearest', round(Geo::distanceToLineKm(34.9, 26.0, $line), 1), 11.1);
check('on the line is no distance', round(Geo::distanceToLineKm(35.0, 26.05, $line), 3), 0.0);
check('no line is infinitely far', Geo::distanceToLineKm(35, 26, []), INF);
check('the box around some points', Geo::bbox([[35.0, 26.0], [35.5, 25.5], [34.8, 26.4]]), [34.8, 25.5, 35.5, 26.4]);
check('no points, no box', Geo::bbox([]), null);

// ---- fewer points on a line
$wiggle = [];
for ($i = 0; $i <= 1000; $i++) { $wiggle[] = [35 + $i * 0.0001, 26 + 0.00002 * sin($i / 3), (float)$i]; }
$few = Geo::simplify($wiggle, 40);
check('a line is simplified to at most the number asked', count($few) <= 40 && count($few) >= 2, true);
check('the first and the last point stay, with their height', [$few[0], $few[count($few) - 1]], [$wiggle[0], $wiggle[1000]]);
check('a short line is left as it is', Geo::simplify([[1.0, 1.0], [2.0, 2.0]], 10), [[1.0, 1.0], [2.0, 2.0]]);
check('a spike that matters is kept when little else is', Geo::simplify([[0.0, 0.0], [0.0, 1.0], [0.0, 2.0], [0.0, 3.0], [1.0, 4.0], [0.0, 5.0], [0.0, 6.0]], 3), [[0.0, 0.0], [1.0, 4.0], [0.0, 6.0]]);

// ---- reading a route file
$gpx = '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="35.2" lon="26.2"><name>Spring</name><desc>Cold</desc></wpt>'
    . '<trk><name>x</name><trkseg><trkpt lat="35.0" lon="26.0"><ele>100</ele></trkpt><trkpt lat="35.01" lon="26.0"><ele>150</ele></trkpt><trkpt lat="35.02" lon="26.0"><ele>120</ele></trkpt></trkseg>'
    . '<trkseg><trkpt lat="35.03" lon="26.0"><ele>130</ele></trkpt><trkpt lat="35.04" lon="26.0"><ele>140</ele></trkpt></trkseg></trk></gpx>';
$parsed = GeoTrack::parse($gpx, 'gpx');
check('GPX: each segment is a line, with its heights', [count($parsed['lines']), $parsed['lines'][0][1]], [2, [35.01, 26.0, 150.0]]);
check('GPX: the places it marks', $parsed['waypoints'], [['lat' => 35.2, 'lng' => 26.2, 'name' => 'Spring', 'text' => 'Cold']]);
$routeOnly = GeoTrack::parse('<gpx xmlns="http://www.topografix.com/GPX/1/1"><rte><rtept lat="1" lon="2"/><rtept lat="1.5" lon="2.5"/></rte></gpx>', 'gpx');
check('GPX: a planned route is used when there is no track', $routeOnly['lines'][0], [[1.0, 2.0, null], [1.5, 2.5, null]]);
$kml = '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark><name>Line</name><LineString><coordinates>26.0,35.0,10 26.1,35.1,20</coordinates></LineString></Placemark><Placemark><name>Pin</name><description>&lt;b&gt;Nice&lt;/b&gt; view</description><Point><coordinates>26.5,35.5</coordinates></Point></Placemark></Document></kml>';
$parsed = GeoTrack::parse($kml, 'kml');
check('KML: a line (longitude first in the file), and a place with its text without markup', [$parsed['lines'][0], $parsed['waypoints']], [[[35.0, 26.0, 10.0], [35.1, 26.1, 20.0]], [['lat' => 35.5, 'lng' => 26.5, 'name' => 'Pin', 'text' => 'Nice view']]]);
$geojson = '{"type":"FeatureCollection","features":[{"type":"Feature","properties":{"name":"A"},"geometry":{"type":"LineString","coordinates":[[26,35,5],[26.1,35.1,9]]}},{"type":"Feature","properties":{"name":"P"},"geometry":{"type":"Point","coordinates":[26.3,35.3]}},{"type":"Feature","properties":{},"geometry":{"type":"MultiLineString","coordinates":[[[27,36],[27.1,36.1]],[[28,37],[28.1,37.1]]]}}]}';
$parsed = GeoTrack::parse($geojson, 'geojson');
check('GeoJSON: lines, multi lines and places', [count($parsed['lines']), $parsed['lines'][0][0], count($parsed['waypoints']), $parsed['waypoints'][0]['name']], [3, [35.0, 26.0, 5.0], 1, 'P']);
check('a file with a DOCTYPE is refused (no outside entities)', GeoTrack::parse('<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/hostname">]><gpx><trk><trkseg><trkpt lat="1" lon="1"/><trkpt lat="2" lon="2"/></trkseg></trk></gpx>', 'gpx'), null);
check('a file with markup a browser could run is refused: a page, a drawing, a script, a style sheet', array_map(static fn(string $extra): ?array => GeoTrack::parse('<gpx><trk><trkseg><trkpt lat="1" lon="1"/><trkpt lat="2" lon="2"/></trkseg></trk>' . $extra . '</gpx>', 'gpx'), ['<html xmlns="http://www.w3.org/1999/xhtml"/>', '<svg xmlns="http://www.w3.org/2000/svg"/>', '<script>alert(1)</script>', '<?xml-stylesheet href="x.xsl"?>']), [null, null, null, null]);
check('not XML, not JSON, no line or place, an unknown kind, too large: nothing', [GeoTrack::parse('hello', 'gpx'), GeoTrack::parse('{"a":1}', 'geojson'), GeoTrack::parse('<gpx/>', 'gpx'), GeoTrack::parse($gpx, 'txt'), GeoTrack::parse(str_repeat(' ', GeoTrack::MAX_BYTES + 1), 'gpx'), GeoTrack::parse('', 'gpx')], [null, null, null, null, null, null]);
check('a point outside the earth is skipped', GeoTrack::parse('<gpx><trk><trkseg><trkpt lat="95" lon="1"/><trkpt lat="1" lon="2"/><trkpt lat="1.1" lon="2.1"/></trkseg></trk></gpx>', 'gpx')['lines'][0], [[1.0, 2.0, null], [1.1, 2.1, null]]);

// ---- the facts of a route
$stats = GeoTrack::stats(GeoTrack::parse($gpx, 'gpx')['lines']);
check('length, climb and descent (small changes are noise)', [$stats['distance_km'], $stats['ascent_m'], $stats['descent_m'], $stats['min_m'], $stats['max_m'], $stats['has_height']], [3.34, 70, 30, 100, 150, true]);
check('start and end', [$stats['start'], $stats['end']], [[35.0, 26.0], [35.04, 26.0]]);
check('the profile runs from 0 to the length', [$stats['profile'][0][0], $stats['profile'][count($stats['profile']) - 1][0]], [0.0, 3.336]);
$noise = [[]];
for ($i = 0; $i < 50; $i++) { $noise[0][] = [35 + $i * 0.0001, 26.0, 100 + ($i % 2) * 2.0]; }
check('a receiver that wobbles by 2 metres climbs nothing', GeoTrack::stats($noise)['ascent_m'], 0);
$loop = [[[35.0, 26.0, null], [35.1, 26.0, null], [35.1, 26.1, null], [35.0, 26.1, null], [35.0, 26.0005, null]]];
check('a line that ends where it began is a loop, a line without heights has none', [GeoTrack::stats($loop)['loop'], GeoTrack::stats($loop)['has_height'], GeoTrack::stats($loop)['profile']], [true, false, []]);
check('a straight line is not a loop', GeoTrack::stats([[[35.0, 26.0, null], [35.2, 26.0, null]]])['loop'], false);

// ---- what is kept of a file
$public = sys_get_temp_dir() . '/faros-geo-' . bin2hex(random_bytes(4));
mkdir($public . '/uploads/media', 0775, true);
$cache = $public . '/cache';
file_put_contents($public . '/uploads/media/loop.gpx', $gpx);
file_put_contents($public . '/uploads/media/notes.txt', 'x');
$images = new Images($public, $public . '/media');
$library = new GeoLibrary($images, $cache);
$track = $library->track('/uploads/media/loop.gpx');
check('a route file is read, with its facts and its box', [$track['stats']['distance_km'], count($track['lines']), $track['bbox'], $track['ext']], [3.34, 2, [35.0, 26.0, 35.2, 26.2], 'gpx']);
check('and kept, so the second reading does not read the file', count(glob($cache . '/geo/*.json')), 1);
check('a copy is used while the file is as it was', [(new GeoLibrary($images, $cache))->track('/uploads/media/loop.gpx')['stats']['distance_km'], count(glob($cache . '/geo/*.json'))], [3.34, 1]);
file_put_contents($public . '/uploads/media/loop.gpx', '<gpx>broken');
touch($public . '/uploads/media/loop.gpx', time() + 5);
clearstatcache();
check('a changed file is read again (and one that cannot be read is nothing)', (new GeoLibrary($images, $cache))->track('/uploads/media/loop.gpx'), null);
check('only the site\'s own route files are read', [$library->track('/uploads/media/notes.txt'), $library->track('https://example.com/a.gpx'), $library->track('/uploads/../x.gpx'), $library->track('/uploads/media/missing.gpx'), $library->track('')], [null, null, null, null, null]);
$big = [];
for ($i = 0; $i < 6000; $i++) { $big[] = sprintf('<trkpt lat="%.6f" lon="%.6f"><ele>%d</ele></trkpt>', 35 + $i * 0.00005, 26 + 0.001 * sin($i / 40), 100 + $i % 90); }
file_put_contents($public . '/uploads/media/big.gpx', '<gpx><trk><trkseg>' . implode('', $big) . '</trkseg></trk></gpx>');
$bigTrack = $library->track('/uploads/media/big.gpx');
check('a long recording keeps few points for the page, and the length of the whole', [count($bigTrack['lines'][0]) <= GeoLibrary::PAGE_POINTS, $bigTrack['stats']['distance_km'] > 30], [true, true]);
check('and fewer for an overview', count(GeoLibrary::overview($bigTrack)[0]) <= GeoLibrary::OVERVIEW_POINTS, true);

// ---- entries on a map
$item = static fn(string $type, string $slug, array $fields, array $meta = []) => new ContentItem($type, $slug, 'en', ['title' => ucfirst($slug), 'custom_fields' => $fields] + $meta, '', '<p>Text</p>', '', 0);
file_put_contents($public . '/uploads/media/loop.gpx', $gpx);
touch($public . '/uploads/media/loop.gpx', time() + 20);
clearstatcache();
$geo = new GeoMap(new GeoLibrary($images, $cache . '2'), $images);
$route = $item('routes', 'loop', ['track' => '/uploads/media/loop.gpx', 'distance_km' => 12.5]);
$pointNear = $item('points', 'near', ['location' => '35.021, 26.001']);
$pointFar = $item('points', 'far', ['location' => '36.5, 27.5']);
$shop = $item('businesses', 'shop', ['location' => '35.041, 26.0']);
$nowhere = $item('points', 'nowhere', ['area' => 'Somewhere']);
check('an entry is where its location says', $geo->position($pointNear), ['lat' => 35.021, 'lng' => 26.001]);
check('a route without a location is at the start of its line', $geo->position($route), ['lat' => 35.0, 'lng' => 26.0]);
check('an entry with neither has no place', $geo->position($nowhere), null);
$facts = $geo->routeFacts($route);
check('what the editor typed beats the file, the rest comes from the file', [$facts['distance_km'], $facts['ascent_m'], $facts['max_m'], $facts['has_file']], [12.5, 70, 150, true]);
check('a point is not a route', $geo->routeFacts($pointNear), null);
$near = $geo->nearby($route, [$pointNear, $pointFar, $shop, $nowhere, $route], 3.0, 10);
check('near a route: what is close to its line, the nearest first, not itself, not what is far or has no place', array_map(static fn(array $n): string => $n['item']->slug, $near), ['near', 'shop']);
check('with the distance in kilometres', array_map(static fn(array $n): float => $n['km'], $near), [0.09, 0.11]);
$back = $geo->nearby($pointNear, [$route, $pointFar, $shop], 3.0, 10);
check('near a point: the route whose line passes by, and the shop', array_map(static fn(array $n): string => $n['item']->slug, $back), ['loop', 'shop']);
check('the number can be limited, and a place with no position has nothing near', [count($geo->nearby($route, [$pointNear, $shop], 3.0, 1)), $geo->nearby($nowhere, [$pointNear], 3.0, 5)], [1, []]);
// a limit for each kind: a crowded kind cannot push the other out
$crowd = [$item('businesses', 's1', ['location' => '35.001, 26.0']), $item('businesses', 's2', ['location' => '35.002, 26.0']), $item('businesses', 's3', ['location' => '35.003, 26.0']), $pointNear];
$slugs = static fn(array $n): array => array_map(static fn(array $e): string => $e['item']->slug, $n);
check('one limit for all lets the nearest kind take every place', $slugs($geo->nearby($route, $crowd, 3.0, 2)), ['s1', 's2']);
check('a limit for each kind keeps both kinds', $slugs($geo->nearby($route, $crowd, 3.0, ['businesses' => 2, 'points' => 5])), ['s1', 's2', 'near']);
check('a kind with no limit is not cut, and 0 leaves a kind out', [count($geo->nearby($route, $crowd, 3.0, ['points' => 1])), $slugs($geo->nearby($route, $crowd, 3.0, ['businesses' => 0]))], [4, ['near']]);
$feature = $geo->feature($route, ['type_label' => 'Routes', 'url' => '/routes/loop', 'terms' => [['slug' => 'hiking', 'label' => 'Hiking']], 'lines' => true]);
check('an entry as a map draws it: kind, place, facts, categories, and the line of a route', [$feature['id'], $feature['kind'], $feature['facts'][0], $feature['cats'], count($feature['line']) > 0, $feature['lat']], ['routes/loop', 'Routes', '12.5 km', ['hiking'], true, 35.0]);
check('a line is left out when many are drawn without lines', $geo->feature($route, ['type_label' => 'R', 'url' => '/r', 'terms' => [], 'lines' => false])['line'], []);

// ---- the text of a popup starts at the first paragraph
$withHeads = new ContentItem('points', 'heads', 'en', ['title' => 'Heads', 'custom_fields' => ['location' => '35.1, 26.1']], '', "<h2>History</h2>\n<pre><code>block: code</code></pre>\n<p>The old &amp; quiet harbour.</p>\n<h3>More</h3><p>Second.</p>", '', 0);
check('the popup text skips the headings and the code, and starts at the first paragraph', $geo->feature($withHeads, ['type_label' => 'P', 'url' => '/p', 'terms' => [], 'lines' => false])['text'], 'The old & quiet harbour. Second.');

// ---- a set of entries, and the tiles
$view = new GeoView($geo, fn(string $type, string $lang): array => $type === 'points' ? [$pointNear, $pointFar, $nowhere] : [], fn(string $t, string $l): string => ucfirst($t), fn(string $s, string $l): string => ucfirst($s), fn(ContentItem $i, string $l): string => '/' . $i->type . '/' . $i->slug, fn(): array => ['points'], ['tiles_url' => '', 'attribution' => '']);
$data = $view->dataset([$pointNear, $pointFar, $nowhere, $route], 'en');
check('a map of entries leaves out those with no place, and counts the kinds', [count($data['items']), $data['types']], [3, [['id' => 'points', 'label' => 'Points', 'count' => 2], ['id' => 'routes', 'label' => 'Routes', 'count' => 1]]]);
check('and keeps the asked categories', array_column($view->dataset([$item('points', 'a', ['location' => '35,26'], ['categories' => ['sights', 'springs']]), $item('points', 'b', ['location' => '35,26'], ['categories' => ['sights']])], 'en')['cats'], 'count', 'slug'), ['sights' => 2, 'springs' => 1]);
$single = $view->single($route, 'en', ['types' => ['points'], 'radius' => 3.0]);
check('the map of one route: its line whole, its places, and the entries near it', [count($single['route']['lines']), count($single['route']['waypoints']), array_column($single['items'], 'id'), $single['current']], [2, 1, ['routes/loop', 'points/near'], 'routes/loop']);
check('what to draw near is chosen by type, and a bad type name is ignored', $view->nearby($route, ['../etc'], 3.0, 5, 'en'), []);
check('the map draws OpenStreetMap unless the site chose tiles', [$view->tiles()['url'], str_contains($view->tiles()['attribution'], 'OpenStreetMap')], ['https://tile.openstreetmap.org/{z}/{x}/{y}.png', true]);
$own = new GeoView($geo, fn() => [], fn() => '', fn() => '', fn() => '', fn() => [], ['tiles_url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key=1', 'attribution' => 'Map © <a href="https://example.com">Example</a> <script>x</script>']);
check('the site\'s own tiles and credit, the credit with links and text only', [$own->tiles()['url'], $own->tiles()['attribution']], ['https://tiles.example.com/{z}/{x}/{y}.png?key=1', 'Map © <a href="https://example.com" rel="noopener">Example</a> x']);
foreach (['http://tiles.example.com/{z}/{x}/{y}.png', 'https://tiles.example.com/map.png', 'javascript:alert(1)', 'https://t.example.com/{z}/{x}/{y}.png"onload="x'] as $bad) {
    check('tiles that are not https with {z} {x} {y} are not used: ' . $bad, (new GeoView($geo, fn() => [], fn() => '', fn() => '', fn() => '', fn() => [], ['tiles_url' => $bad, 'attribution' => 'x']))->tiles()['url'], GeoView::DEFAULT_TILES);
}
check('a link in the credit that is not http(s) is dropped, an open link is closed', GeoView::cleanAttribution('<a href="javascript:x">a</a> <a href="https://e.com">b'), 'a <a href="https://e.com" rel="noopener">b</a>');
$svg = GeoView::profileSvg([[0.0, 100], [1.0, 150], [2.0, 120]], 'Height: 100–150 m');
check('the profile of the height is a drawing that says what it shows', [str_starts_with($svg, '<svg'), str_contains($svg, 'role="img"'), str_contains($svg, 'aria-label="Height: 100–150 m"'), str_contains($svg, '<script')], [true, true, true, false]);
check('no profile for a line with one point', GeoView::profileSvg([[0.0, 100]], 'x'), '');
check('the label of the drawing is text', str_contains(GeoView::profileSvg([[0.0, 1], [1.0, 2]], '"><script>'), '<script>'), false);

array_map('unlink', array_merge(glob($public . '/uploads/media/*') ?: [], glob($cache . '/geo/*') ?: [], glob($cache . '2/geo/*') ?: []));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
