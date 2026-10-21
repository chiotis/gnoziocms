<?php
/*
 * Custom post types of the REST API with their ACF fields: the profile's keys checked, the duplicates of terms merged the
 * same way on every run, a pair of coordinates as a place, the _el/_en fields as two entries linked by translation_id, and a
 * whole run against a site that answers from memory (route files fetched and checked, the gallery, the video, the report).
 *   php tests/unit/wordpress-rest-types.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{FrontMatter, GeoTrack, ImportedRedirects, MediaLibrary, RedirectRepository, SystemDatabase, Taxonomies, WordPressImporter, WordPressReader, WordPressRestTypes, WordPressTermMerge};
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- the profile
$rural = WordPressRestTypes::parse(Yaml::parseFile(dirname(__DIR__, 2) . '/docs/wordpress-profiles/ruralgreece.yaml'));
check('the Rural Greece profile has no mistakes', $rural['errors'], []);
check('its three types go to the three types here', array_map(fn($t) => $t['endpoint'] . '>' . $t['content_type'], array_values($rural['types'])), ['route>routes', 'business>businesses', 'pois>points']);
check('an ACF key keeps its exact spelling, the Greek ν of υπότιτλος_eν too', [$rural['types']['route']['fields']['υπότιτλος_eν'] ?? null, isset($rural['types']['route']['fields']['υπότιτλος_en'])], [['to' => 'subtitle', 'lang' => 'en', 'type' => 'text'], false]);
check('a shared taxonomy is one taxonomy here', [$rural['taxonomies']['location'], $rural['taxonomies']['business-type'], count($rural['taxonomies'])], ['location', 'business-type', 18]);
check('term_map lines are source ids', [$rural['term_map']['activity'][767], $rural['term_map']['categories'][752]], [310, 7]);
check('the languages it writes', WordPressRestTypes::languages($rural, 'el'), ['el', 'en']);
check('the route file names the field its length goes to', $rural['types']['route']['media']['map_kml'], ['to' => 'track', 'length' => 'distance_km']);
check('a field as a map with a type', $rural['types']['route']['fields']['μήκος_μονοπατιού'], ['to' => 'distance_km', 'lang' => '', 'type' => 'number']);
check('no profile is no types and no mistakes', [WordPressRestTypes::parse([])['types'], WordPressRestTypes::parse([])['errors'], WordPressRestTypes::parse([])['skip_empty_terms']], [[], [], false]);
$bad = WordPressRestTypes::parse([
    'rest_types' => [
        'a' => ['fields' => ['x' => 'Not A Field', 'y' => 'body@english', 'z' => ['to' => 'n', 'type' => 'money']], 'media' => ['m' => 'nowhere!'], 'coordinates' => ['lat' => 'la'], 'taxonomies' => ['activity' => 'activity']],
        'b' => ['content_type' => 'points', 'endpoint' => 'b c', 'taxonomies' => ['activity' => 'other']],
        'c' => 'routes',
    ],
    'term_map' => ['activity' => ['767' => 'Bird'], 'x' => 'y'],
    'language_prefixes' => ['english' => '/en'],
]);
check('every mistake of a profile is named', count($bad['errors']), 12);
check('a missing content type is one of them', in_array('rest_types.a.content_type: the content type here is needed (lower case, like routes)', $bad['errors'], true), true);
check('so is a taxonomy sent to two places', (bool)array_filter($bad['errors'], fn($e) => str_contains($e, "another type sends it to 'activity'")), true);
check('and a term_map line that is not two ids', (bool)array_filter($bad['errors'], fn($e) => str_starts_with($e, 'term_map.activity.767:')), true);

// ---- the terms
$t = fn(int $id, string $name, int $count, int $parent = 0, string $slug = '') => ['id' => $id, 'slug' => $slug !== '' ? $slug : 's' . $id, 'name' => $name, 'link' => "https://old.example/activity/s$id/", 'parent' => $parent, 'count' => $count, 'description' => ''];
$activity = [
    $t(767, 'Bird watchin', 27), $t(149, 'Παρατήρηση πουλιών', 23), $t(310, 'Παρατήρηση Πτηνών', 90), $t(145, 'Πεζοπορία', 483),
    $t(150, 'Ορεινή ποδηλασία', 0), $t(311, 'Ποδήλατο Βουνού', 26), $t(500, 'ΠΕΖΟΠΟΡΙΑ', 2), $t(501, 'πεζοπορια', 0),
    $t(600, 'Κολύμβησις', 3), $t(601, 'Κολύμβησισ', 9), $t(700, 'Unused', 0), $t(701, 'Also unused', 0),
];
$merged = WordPressTermMerge::apply($activity, [767 => 310, 149 => 310, 150 => 311, 999 => 310], true);
check('term_map lines merge a term into another', array_map(fn($m) => [$m['from'], $m['into'], $m['why']], array_values(array_filter($merged['merges'], fn($m) => $m['why'] === 'term_map'))), [[149, 310, 'term_map'], [150, 311, 'term_map'], [767, 310, 'term_map']]);
check('names that differ only in case, accent or ς/σ are one, the one with more entries stays', array_map(fn($m) => [$m['from'], $m['into']], array_values(array_filter($merged['merges'], fn($m) => $m['why'] === 'same name'))), [[500, 145], [501, 145], [600, 601]]);
check('the term that stays carries the ids of those merged into it', array_column(array_map(fn($k) => ['id' => $k['id'], 'aliases' => $k['aliases']], $merged['terms']), 'aliases', 'id'), [145 => [500, 501], 310 => [149, 767], 311 => [150], 601 => [600]]);
check('and their old addresses', array_values(array_filter($merged['terms'], fn($k) => $k['id'] === 310))[0]['alias_links'], ['https://old.example/activity/s149/', 'https://old.example/activity/s767/']);
check('a term with no entries and no twin is left out; one with a twin stays', [array_column($merged['skipped'], 'id'), in_array(311, array_column($merged['terms'], 'id'), true)], [[700, 701], true]);
check('a line naming a term the site does not have is reported', $merged['unknown'], [['from' => 999, 'into' => 310]]);
$shuffled = $activity;
shuffle($shuffled);
check('the same terms in another order give the same result', WordPressTermMerge::apply($shuffled, [767 => 310, 149 => 310, 150 => 311, 999 => 310], true), $merged);
check('and so does a second run', WordPressTermMerge::apply($activity, [149 => 310, 150 => 311, 767 => 310, 999 => 310], true), $merged);
check('merge_same_names is off unless the profile asks', [WordPressRestTypes::parse([])['merge_same_names'], WordPressRestTypes::parse(['merge_same_names' => true])['merge_same_names']], [false, true]);
check('without merge_same_names only the term_map lines merge', array_map(fn($m) => $m['why'], WordPressTermMerge::apply($activity, [767 => 310], true, false)['merges']), ['term_map']);
check('without skip_empty_terms every term without a twin stays', in_array(700, array_column(WordPressTermMerge::apply($activity, [], false)['terms'], 'id'), true), true);
$chain = WordPressTermMerge::apply([$t(1, 'A', 1), $t(2, 'B', 1), $t(3, 'C', 1), $t(4, 'D', 1)], [1 => 2, 2 => 3, 4 => 4], false);
check('a chain of lines ends at its last term; a line into itself is nothing', [array_map(fn($m) => [$m['from'], $m['into']], $chain['merges']), array_column($chain['terms'], 'id')], [[[1, 3], [2, 3]], [3, 4]]);
$circle = WordPressTermMerge::apply([$t(1, 'A', 1), $t(2, 'B', 1)], [1 => 2, 2 => 1], false);
check('lines that make a circle are dropped and reported', [$circle['merges'], count($circle['unknown']), array_column($circle['terms'], 'id')], [[], 2, [1, 2]]);
$tree = WordPressTermMerge::apply([$t(4, 'Διαδρομές Κρήτης', 138), $t(7, 'Γεωδιαδρομές', 27, 4), $t(752, 'Γεωδιαδρομές', 0), $t(170, 'Γαστρονομία', 0), $t(176, 'Γκουρμέ', 0, 170), $t(171, 'Εστιατόριο', 4, 170), $t(800, 'Same', 1, 4), $t(801, 'same', 1, 170)], [752 => 7], true);
check('a term under another parent is another term; a line merges it', [array_map(fn($m) => [$m['from'], $m['into']], $tree['merges']), array_column($tree['skipped'], 'id')], [[[752, 7]], [176]]);
check('an empty parent with a used term below it stays, with its tree', array_map(fn($k) => [$k['id'], $k['parent']], $tree['terms']), [[4, 0], [7, 4], [170, 0], [171, 170], [800, 4], [801, 170]]);
check('a child of a merged term moves to the term it went into', array_map(fn($k) => [$k['id'], $k['parent']], WordPressTermMerge::apply([$t(1, 'A', 1), $t(2, 'B', 1), $t(3, 'C', 1, 1)], [1 => 2], false)['terms']), [[2, 0], [3, 2]]);
check('how names are compared', [WordPressTermMerge::normalize('  Παρατήρηση   ΠΤΗΝΏΝ '), WordPressTermMerge::normalize('Κολύμβησις'), WordPressTermMerge::normalize('Café'), WordPressTermMerge::normalize('Μινωική Βίλλα') === WordPressTermMerge::normalize('Μινωική βίλα')], ['παρατηρηση πτηνων', 'κολυμβησισ', 'cafe', false]);

// ---- coordinates
check('two numeric strings are a place', WordPressRestTypes::position('35.2012', '26.27440'), ['35.2012, 26.2744', 'both']);
check('numbers, and a decimal comma', [WordPressRestTypes::position(35.5, 26)[0], WordPressRestTypes::position('35,5', ' 26,1 ')[0]], ['35.5, 26', '35.5, 26.1']);
check('one of the two leaves it empty', [WordPressRestTypes::position('35.2', ''), WordPressRestTypes::position(null, '26.1')], [['', 'one'], ['', 'one']]);
check('none, nonsense, out of range or 0,0 is no place', [WordPressRestTypes::position('', null), WordPressRestTypes::position('north', 'east')[1], WordPressRestTypes::position('95', '26')[0], WordPressRestTypes::position('0', '0')[0]], [['', 'none'], 'none', '', '']);

// ---- one record
$route = $rural['types']['route'];
$record = [
    'id' => 16254, 'slug' => 'podilatikos-gyros-limnis-taka', 'link' => 'https://old.example/route/podilatikos-gyros-limnis-taka/',
    'title' => ['rendered' => 'Ποδηλατικός Γύρος Λίμνης Τάκα'], 'featured_media' => 0, 'activity' => [310, 416], 'location' => [793], 'categories' => [],
    'acf' => [
        'title_en' => 'Cycling around Lake Taka', 'υπότιτλος_el' => 'Γύρω από τη λίμνη', 'υπότιτλος_eν' => 'Around the lake',
        'εισαγωγικό_κείμενο' => 'Μια βόλτα.', 'εισαγωγικό_κείμενο_en' => 'A ride.', 'κεντρικό_κείμενο' => "<p>Κείμενο</p>", 'κεντρικό_κείμενο_en' => "First line\nsecond line\n\nNext paragraph",
        'latitude' => '37.4501', 'longitude' => '22.3712', 'map_kml' => 'https://old.example/wp-content/uploads/taka.kml', 'banner_image' => 50, 'photo_gallery' => [50, 51],
        'youtube_video' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed"></iframe>', 'μήκος_μονοπατιού' => '', 'διάρκεια_el' => '3 ώρες', 'διάρκεια_en' => '',
        'map_img_url' => 'https://old.example/x.png', 'new_field' => 'something',
    ],
];
$m = WordPressRestTypes::map($record, $route, 'el');
check('the Greek entry has the record\'s title and the Greek fields', [$m['texts']['el']['title'], $m['texts']['el']['excerpt'], $m['texts']['el']['fields']], ['Ποδηλατικός Γύρος Λίμνης Τάκα', 'Μια βόλτα.', ['subtitle' => 'Γύρω από τη λίμνη', 'duration' => '3 ώρες']]);
check('the English entry has title_en and the _en and _eν fields; an empty one is no value', [$m['texts']['en']['title'], $m['texts']['en']['excerpt'], $m['texts']['en']['fields']], ['Cycling around Lake Taka', 'A ride.', ['subtitle' => 'Around the lake']]);
check('the place, the terms by taxonomy here, the video as an address', [$m['location'], $m['coordinates'], $m['terms'], $m['video']], ['37.4501, 22.3712', 'both', ['activity' => [310, 416], 'location' => [793]], 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
check('the files: one picture, a gallery that is a list, a route file with the field its length goes to', [$m['media']['banner_image']['refs'], $m['media']['photo_gallery']['refs'], $m['media']['photo_gallery']['list'], $m['media']['map_kml']['refs'], $m['media']['map_kml']['length']], [[50], [50, 51], true, ['https://old.example/wp-content/uploads/taka.kml'], 'distance_km']);
check('a key the profile does not name is reported; one it ignores is not', $m['unmapped'], ['new_field']);
check('a number field takes the number out of a text', WordPressRestTypes::map(['acf' => ['μήκος_μονοπατιού' => '12,5 χλμ.']] + $record, $route, 'el')['fields'], ['distance_km' => 12.5]);
check('and says so when there is none', WordPressRestTypes::map(['acf' => ['μήκος_μονοπατιού' => 'μεγάλο']] + $record, $route, 'el')['notes'], ["μήκος_μονοπατιού: 'μεγάλο' is not a number, left empty"]);
$bare = WordPressRestTypes::map(['id' => 1, 'slug' => 'x', 'link' => '', 'title' => ['rendered' => 'X'], 'acf' => []], $route, 'el');
check('a record with nothing filled in is still an entry, with no place and no English', [$bare['texts']['el']['title'], array_keys($bare['texts']), $bare['coordinates'], $bare['fields'], $bare['media']], ['X', ['el'], 'none', [], []]);
check('video addresses: youtu.be, a Vimeo player, an address that is not a video host', [WordPressRestTypes::videoUrl('https://youtu.be/dQw4w9WgXcQ?t=3'), WordPressRestTypes::videoUrl('<iframe src="https://player.vimeo.com/video/76979871"></iframe>'), WordPressRestTypes::videoUrl('http://example.com/x'), WordPressRestTypes::videoUrl('')], ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://vimeo.com/76979871', '', '']);

// ---- a whole run
$SITE = 'https://old.example';
$kml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark><LineString><coordinates>22.37,37.45,600 22.38,37.45,610 22.39,37.46,640</coordinates></LineString></Placemark></Document></kml>';
$kmLength = GeoTrack::stats(GeoTrack::parse($kml, 'kml')['lines'])['distance_km'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$terms = fn(string $tax, array $rows) => array_map(fn($r) => ['id' => $r[0], 'slug' => $r[1], 'name' => $r[2], 'link' => "$SITE/$tax/{$r[1]}/", 'parent' => $r[4] ?? 0, 'count' => $r[3], 'description' => $r[5] ?? ''], $rows);
$web = [
    '/wp-json/' => ['name' => 'Rural', 'description' => '', 'home' => $SITE, 'namespaces' => ['wp/v2']],
    '/wp-json/wp/v2/types' => ['route' => ['rest_base' => 'route'], 'pois' => ['rest_base' => 'pois']],
    '/wp-json/wp/v2/taxonomies' => ['activity' => ['rest_base' => 'activity', 'name' => 'Δραστηριότητες'], 'location' => ['rest_base' => 'location', 'name' => 'Περιοχές'], 'category' => ['rest_base' => 'categories', 'name' => 'Κατηγορίες'], 'post_tag' => ['rest_base' => 'tags', 'name' => 'Ετικέτες']],
    '/wp-json/wp/v2/activity' => $terms('activity', [[767, 'bird-watchin', 'Bird watchin', 27], [310, 'paratirisi-ptinon', 'Παρατήρηση Πτηνών', 90, 0, 'Πουλιά'], [416, 'podilasia', 'Ποδηλασία', 71], [700, 'unused', 'Αχρησιμοποίητο', 0]]),
    '/en/wp-json/wp/v2/activity' => $terms('activity', [[310, 'paratirisi-ptinon', 'Bird watching', 90, 0, 'Birds'], [416, 'podilasia', 'Ποδηλασία', 71]]),
    '/wp-json/wp/v2/location' => $terms('location', [[793, 'arkadia', 'Αρκαδία', 33], [758, 'p-e-arkadias', 'Π.Ε. Αρκαδίας', 0]]),
    '/wp-json/wp/v2/categories' => $terms('category', [[4, 'crete-routes', 'Διαδρομές Κρήτης', 138], [7, 'georoutes', 'Γεωδιαδρομές', 27, 4], [752, 'geodiadromes', 'Γεωδιαδρομές', 0]]),
    '/wp-json/wp/v2/tags' => [],
    '/wp-json/wp/v2/media' => [
        ['id' => 50, 'source_url' => "$SITE/wp-content/uploads/banner.png", 'alt_text' => 'Η λίμνη', 'title' => ['rendered' => 'banner'], 'mime_type' => 'image/png', 'post' => 0],
    ],
    '/wp-json/wp/v2/media/51' => ['id' => 51, 'source_url' => "$SITE/wp-content/uploads/shore.png", 'alt_text' => '', 'title' => ['rendered' => 'shore'], 'mime_type' => 'image/png', 'post' => 0],
    '/wp-json/wp/v2/route' => [
        $record,
        ['id' => 16251, 'slug' => 'silimna-chrysovitsi', 'link' => "$SITE/route/silimna-chrysovitsi/", 'status' => 'publish', 'title' => ['rendered' => 'Σιλίμνα – Χρυσοβίτσι'], 'featured_media' => 0, 'activity' => [767], 'location' => [758], 'categories' => [752],
            'acf' => ['title_en' => '', 'κεντρικό_κείμενο' => 'Μόνο ελληνικά.', 'latitude' => '37.5', 'longitude' => '', 'map_kml' => "$SITE/wp-content/uploads/broken.kml", 'μήκος_μονοπατιού' => '8']],
    ],
    '/wp-json/wp/v2/pois' => [
        ['id' => 16509, 'slug' => 'chora-samothrakis', 'link' => "$SITE/pois/chora-samothrakis/", 'status' => 'publish', 'title' => ['rendered' => 'Χώρα Σαμοθράκης'], 'featured_media' => 0, 'activity' => [], 'acf' => ['title_en' => 'Chora', 'banner_image' => '', 'latitude' => '', 'longitude' => '', 'ωράριο_λειτουργίας_el' => 'Πάντα<br>ανοιχτό', 'photo_gallery' => [51]]],
    ],
];
$web['/wp-json/wp/v2/route'][0]['status'] = 'publish';
// The old site's English pages have slugs of their own, the merged-away term too; a Greek term's address is percent-encoded.
$web['/en/wp-json/wp/v2/activity'][0]['link'] = "$SITE/en/activity/bird-watching/";
$web['/en/wp-json/wp/v2/activity'][] = ['id' => 767, 'slug' => 'bird-watchin-en', 'name' => 'Bird watchin', 'link' => "$SITE/en/activity/bird-watchin-en/", 'parent' => 0, 'count' => 27, 'description' => ''];
$web['/wp-json/wp/v2/location'][1]['slug'] = rawurlencode('π-ε-αρκαδίας');
$web['/wp-json/wp/v2/location'][1]['link'] = "$SITE/location/" . rawurlencode('π-ε-αρκαδίας') . '/';
$get = function (string $url) use (&$web, $SITE): ?array {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (!str_starts_with($url, $SITE) || !isset($web[$path])) { return ['status' => 404, 'body' => '{}']; }
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    if (($query['page'] ?? '1') !== '1') { return ['status' => 400, 'body' => '{}']; }
    return ['status' => 200, 'body' => json_encode($web[$path])];
};
$downloads = [];
$download = function (string $url, string $to) use (&$downloads, $png, $kml): int {
    $downloads[] = $url;
    if (str_ends_with($url, '.png')) { file_put_contents($to, $png); return 200; }
    if (str_ends_with($url, 'taka.kml')) { file_put_contents($to, $kml); return 200; }
    if (str_ends_with($url, 'broken.kml')) { file_put_contents($to, '<kml><Document></Document></kml>'); return 200; }
    return 404;
};
$profile = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/wordpress-profiles/ruralgreece.yaml');
$root = sys_get_temp_dir() . '/wprest' . getmypid();
$make = function () use ($root, $SITE, $get, $download, $profile): WordPressImporter {
    @mkdir("$root/content", 0775, true);
    @mkdir("$root/uploads", 0775, true);
    $media = new MediaLibrary("$root/content", "$root/uploads");
    $media->ensureDirectories();
    return new WordPressImporter(new WordPressReader($SITE, $get), "$root/content", $media, new Taxonomies("$root/content", ['el', 'en']), $download, ['lang' => 'el', 'default_lang' => 'el', 'kinds' => ['types'], 'custom_dir' => "$root/custom"], null, $profile);
};
$front = static function (string $path): array { [$yaml] = FrontMatter::split((string)file_get_contents($path)); return Yaml::parse($yaml); };
$bodyOf = static function (string $path): string { [, $body] = FrontMatter::split((string)file_get_contents($path)); return trim($body); };

$importer = $make();
$plan = $importer->plan();
check('the dry run writes nothing and fetches no file', [glob("$root/content/routes/*") ?: [], glob("$root/content/taxonomies/*") ?: [], $downloads], [[], [], []]);
check('it lists an entry in each language that has text', array_map(fn($i) => $i['type'] . '/' . $i['slug'] . '.' . $i['lang'], $plan['items']), ['routes/silimna-chrysovitsi.el', 'routes/podilatikos-gyros-limnis-taka.el', 'routes/podilatikos-gyros-limnis-taka.en', 'points/chora-samothrakis.el', 'points/chora-samothrakis.en']);
check('the English file sits beside the Greek one, under /en/', [basename($plan["items"][2]["file"]), $plan["items"][2]["path"]], ['podilatikos-gyros-limnis-taka.en.md', 'en/routes/podilatikos-gyros-limnis-taka']);
check('and the route files to fetch and check are counted', $plan['route_files'], 2);
check('the report says what was merged, by which rule', $plan['report']['merged'], ['categories: Γεωδιαδρομές (752) into Γεωδιαδρομές (7), term_map', 'activity: Bird watchin (767) into Παρατήρηση Πτηνών (310), term_map', 'location: Π.Ε. Αρκαδίας (758) into Αρκαδία (793), term_map']);
check('a type or taxonomy the site\'s API does not list is said so, not fatal; so is a language whose terms cannot be read', [in_array("type 'business': the site's API does not list it, so it was not read", $plan['report']['notes'], true), in_array("taxonomy 'period': the site's API does not list it, so it was not read", $plan['report']['notes'], true), in_array("taxonomy 'location' in 'en': The site answered 404 for /wp-json/wp/v2/location.", $plan['report']['notes'], true)], [true, true, true]);
check('what was left out', $plan['report']['skipped_terms'], ['activity: Αχρησιμοποίητο (700), no entries']);
check('which entries have no place, or only one coordinate', [$plan['report']['no_coordinates'], $plan['report']['one_coordinate']], [['points/chora-samothrakis'], ['routes/silimna-chrysovitsi']]);
check('which have no English', $plan['report']['missing_translation'], ["routes/silimna-chrysovitsi: no text in 'en'"]);
check('and the ACF keys the profile does not name', $plan['report']['unmapped'], ['route: new_field (1 entries have a value; the profile does not say where it goes)']);
$list = array_map(fn($r) => $r[0] . ' ' . $r[1], $plan['redirects']);
check('old addresses lead to the new ones, in both languages', array_values(array_intersect(['/route/podilatikos-gyros-limnis-taka /routes/podilatikos-gyros-limnis-taka', '/en/route/podilatikos-gyros-limnis-taka /en/routes/podilatikos-gyros-limnis-taka', '/route /routes', '/en/route /en/routes'], $list)), ['/route/podilatikos-gyros-limnis-taka /routes/podilatikos-gyros-limnis-taka', '/en/route/podilatikos-gyros-limnis-taka /en/routes/podilatikos-gyros-limnis-taka', '/route /routes', '/en/route /en/routes']);
check('a merged term\'s old address leads to the term it went into', [in_array('/activity/bird-watchin /activity/paratirisi-ptinon', $list, true), in_array('/en/activity/bird-watchin /en/activity/paratirisi-ptinon', $list, true), in_array('/category/geodiadromes /category/crete-routes/georoutes', $list, true)], [true, true, true]);
check('the old English address of a term, with its own slug, and of a term merged into it, lead to the English page', [in_array('/en/activity/bird-watching /en/activity/paratirisi-ptinon', $list, true), in_array('/en/activity/bird-watchin-en /en/activity/paratirisi-ptinon', $list, true)], [true, true]);
check('a merged term\'s Greek address comes decoded', in_array('/location/π-ε-αρκαδίας /location/arkadia', $list, true), true);
check('no old address is a term page of the new site', array_values(array_filter($list, fn($l) => in_array(explode(' ', $l)[0], ['/activity/paratirisi-ptinon', '/en/activity/paratirisi-ptinon', '/location/arkadia'], true))), []);
check('an entry with no English has no English address to redirect', (bool)array_filter($list, fn($l) => str_starts_with($l, '/en/route/silimna')), false);

$result = $importer->apply($plan);
$el = $front("$root/content/routes/podilatikos-gyros-limnis-taka.md");
$en = $front("$root/content/routes/podilatikos-gyros-limnis-taka.en.md");
check('the two entries are one, linked by translation_id', [$el['translation_id'] === $en['translation_id'], strlen($el['translation_id'])], [true, 16]);
check('each has its title and excerpt', [$el['title'], $el['excerpt'], $en['title'], $en['excerpt']], ['Ποδηλατικός Γύρος Λίμνης Τάκα', 'Μια βόλτα.', 'Cycling around Lake Taka', 'A ride.']);
check('the text of each, a plain one as paragraphs', [$bodyOf("$root/content/routes/podilatikos-gyros-limnis-taka.md"), $bodyOf("$root/content/routes/podilatikos-gyros-limnis-taka.en.md")], ['Κείμενο', "First line\nsecond line\n\nNext paragraph"]);
$bannerId = substr(sha1("$SITE/wp-content/uploads/banner.png"), 0, 16);
$shoreId = substr(sha1("$SITE/wp-content/uploads/shore.png"), 0, 16);
$trackId = substr(sha1("$SITE/wp-content/uploads/taka.kml"), 0, 16);
check('the banner is the main image of both', [$el['main_image'], $en['main_image']], ["/uploads/media/$bannerId.png", "/uploads/media/$bannerId.png"]);
check('the fields: the place, the route file in the library, its length, the gallery as a list of library paths', [$el['custom_fields']['location'], $el['custom_fields']['track'], $el['custom_fields']['distance_km'], $el['custom_fields']['gallery']], ['37.4501, 22.3712', "/uploads/media/$trackId.kml", $kmLength, ["/uploads/media/$bannerId.png", "/uploads/media/$shoreId.png"]]);
check('a field of one language is only in its entry', [$el['custom_fields']['subtitle'], $en['custom_fields']['subtitle'], $el['custom_fields']['duration'], isset($en['custom_fields']['duration'])], ['Γύρω από τη λίμνη', 'Around the lake', '3 ώρες', false]);
check('the video is a Video block', $el['blocks'], [['type' => 'video', 'variant' => 'featured', 'videos' => [['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']]]]);
check('the terms are filed under the taxonomies here', [$el['activity'], $el['location'], $en['activity']], [['paratirisi-ptinon', 'podilasia'], ['arkadia'], ['paratirisi-ptinon', 'podilasia']]);
$other = $front("$root/content/routes/silimna-chrysovitsi.md");
check('a term merged away files the entry under the one it went into', [$other['activity'], $other['location'], $other['categories']], [['paratirisi-ptinon'], ['arkadia'], ['georoutes']]);
check('a route file that is not one is left out and reported; the entry comes all the same', [isset($other['custom_fields']['track']), count($result['route_files_failed']), str_contains($result['route_files_failed'][0] ?? '', 'routes/silimna-chrysovitsi')], [false, 1, true]);
check('a length the old site gave is kept; one coordinate is no place', [$other['custom_fields']['distance_km'], isset($other['custom_fields']['location'])], [8, false]);
check('no English, no English file', is_file("$root/content/routes/silimna-chrysovitsi.en.md"), false);
$point = $front("$root/content/points/chora-samothrakis.md");
check('a point with no place, no picture and a line break in its hours', [isset($point['custom_fields']['location']), isset($point['main_image']), $point['custom_fields']['opening_hours'], $point['custom_fields']['gallery']], [false, false, "Πάντα\nανοιχτό", ["/uploads/media/$shoreId.png"]]);
$tax = new Taxonomies("$root/content", ['el', 'en']);
$act = $tax->load('activity');
check('a taxonomy of the old site is one here, with its name and no tree it does not need', [$act['title'], $act['hierarchical'], array_column($act['terms'], 'id')], ['Δραστηριότητες', false, ['paratirisi-ptinon', 'podilasia']]);
check('a term has its source address, both names and both descriptions', [$act['terms'][0]['imported_from'], $act['terms'][0]['labels'], $act['terms'][0]['descriptions']], ["$SITE/wp-json/wp/v2/activity/310", ['el' => 'Παρατήρηση Πτηνών', 'en' => 'Bird watching'], ['el' => 'Πουλιά', 'en' => 'Birds']]);
check('a name the English pages give unchanged is not an English name', $act['terms'][1]['labels'], ['el' => 'Ποδηλασία', 'en' => '']);
check('the categories keep their tree', array_map(fn($x) => [$x['id'], $x['parent']], $tax->load('categories')['terms']), [['crete-routes', ''], ['georoutes', 'crete-routes']]);
check('a new taxonomy lists the types the profile files under it (so a taxonomy of places opens as a map); categories keep theirs', [$act['archive']['types'] ?? null, $tax->load('location')['archive']['types'] ?? null, $tax->load('categories')['archive']], [['routes', 'points'], ['routes', 'businesses', 'points'], []]);
check('and opens as a map on a site whose types have a place', (new Taxonomies("$root/content", ['el', 'en']))->withPlaceTypes(fn() => ['routes', 'businesses', 'points'])->archive('activity', 'el', 'el')['layout'], 'map');

// ---- the redirects put into the site (--redirects=apply), and where they lead
mkdir("$root/storage/db", 0775, true);
$database = new SystemDatabase("$root/storage");
$database->initialize();
$siteRedirects = new RedirectRepository($database);
$applied = (new ImportedRedirects($siteRedirects))->apply(ImportedRedirects::parse(WordPressImporter::redirectList($result['redirects'])));
check('the list the import wrote goes in whole', [$applied['added'], $applied['skipped']], [count($result['redirects']), []]);
$lands = function (string $asked) use ($siteRedirects, $tax): string {
    $hit = $siteRedirects->resolve($asked);
    if ($hit === null) { return 'nothing'; }
    $parts = explode('/', ltrim($hit['target'], '/'));
    if ($parts[0] === 'en') { array_shift($parts); }
    $taxonomy = $tax->fromWord($parts[0]);
    return $hit['target'] . ($taxonomy !== null && $tax->findByPath($taxonomy, implode('/', array_slice($parts, 1))) !== null ? ' (a term page)' : '');
};
check('old term addresses, as a browser asks for them, land on the new term pages, merged ones and English ones too', [
    $lands('/activity/bird-watchin/'), $lands('/en/activity/bird-watching/'), $lands('/en/activity/bird-watchin-en'), $lands('/location/' . rawurlencode('π-ε-αρκαδίας') . '/'), $lands('/category/geodiadromes/'),
], ['/activity/paratirisi-ptinon (a term page)', '/en/activity/paratirisi-ptinon (a term page)', '/en/activity/paratirisi-ptinon (a term page)', '/location/arkadia (a term page)', '/category/crete-routes/georoutes (a term page)']);
$appliedAgain = (new ImportedRedirects($siteRedirects))->apply($result['redirects']);
check('applying them again adds nothing', [$appliedAgain['added'], $appliedAgain['updated'], $appliedAgain['kept']], [0, 0, count($result['redirects'])]);
check('the site\'s own additions to the types are written', [is_file("$root/custom/content-types/routes.yaml"), Yaml::parseFile("$root/custom/content-types/routes.yaml")['fields']['activity'] ?? null], [true, ['hidden' => true]]);

// ---- again
$downloads = [];
$again = $make();
$plan2 = $again->plan();
check('a second run finds every entry it wrote', array_count_values(array_column($plan2['items'], 'state')), ['update' => 5]);
$result2 = $again->apply($plan2);
$el2 = $front("$root/content/routes/podilatikos-gyros-limnis-taka.md");
check('with the same translation_id, the same terms and the length of the route file already in the library', [$el2['translation_id'], $el2['activity'], $el2['custom_fields']['distance_km']], [$el['translation_id'], $el['activity'], $kmLength]);
check('the merges are the same on every run', $plan2['report']['merged'], $plan['report']['merged']);
check('no term is added twice, and only the broken route file is fetched again', [$result2['terms_added'], array_values(array_filter($downloads, fn($u) => !str_ends_with($u, 'broken.kml')))], [0, []]);
$tax->forget();
$edited = $tax->load('activity');
$edited['terms'][1]['labels']['en'] = 'Cycling';
$tax->save('activity', $edited['title'], $edited['terms']);
$web['/en/wp-json/wp/v2/activity'][1]['name'] = 'Bicycle';
$third = $make();
$third->apply($third->plan());
$tax->forget();
check('a name typed here is never replaced; the source address survives a save in the admin', [$tax->load('activity')['terms'][1]['labels']['en'], $tax->load('activity')['terms'][1]['imported_from']], ['Cycling', "$SITE/wp-json/wp/v2/activity/416"]);
$prepared = $tax->prepare('activity', array_map(fn($x) => ['id' => $x['id'], 'slug' => $x['slug'], 'labels' => $x['labels']], $tax->load('activity')['terms']), 'el');
check('and an edit in the Taxonomies screen keeps it too', $prepared['terms'][0]['imported_from'], "$SITE/wp-json/wp/v2/activity/310");

// ---- a profile with mistakes is refused before anything is read
check('the importer refuses a profile with mistakes', (function () use ($SITE, $get, $download, $root) { try { (new WordPressImporter(new WordPressReader($SITE, $get), "$root/content", new MediaLibrary("$root/content", "$root/uploads"), new Taxonomies("$root/content", ['el']), $download, [], null, ['rest_types' => ['x' => []]]))->plan(); } catch (InvalidArgumentException $e) { return 'refused'; } return 'accepted'; })(), 'refused');

exec('rm -rf ' . escapeshellarg($root));
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
