<?php
/*
 * Brings the content of a WordPress site into this one. It reads the site through its public REST API (no password), and
 * by default only says what it would do; add --apply to do it.
 *
 *   php scripts/import-wordpress.php https://example.com                  what would happen
 *   php scripts/import-wordpress.php https://example.com --apply         do it
 *
 * Options:
 *   --lang=en            language of the imported content (default en)
 *   --default-lang=en    this site's default language (default: the same as --lang)
 *   --home=index         file name of this site's home page (default index)
 *   --kinds=pages,posts,types  what to bring (default all three; types are the profile's rest_types)
 *   --only-used-media    leave out library files that no page or post uses
 *   --overwrite          also replace a file of the site that an import did not bring
 *   --profile=FILE       what to read from the site's pages (custom types, slides, menus) and the home page layout; see
 *                        docs/wordpress-profiles/
 *   --menus              also write the menus the profile reads (replaces menus of the same name)
 *   --custom=PATH        the custom folder, for content type definitions (default ./custom)
 *   --content=PATH       the content folder (default ./content)
 *   --uploads=PATH       the uploads folder (default ./public/uploads)
 *   --out=PATH           where the report and the redirects list are written (default ./storage/import)
 *   --redirects=apply    also put the redirects into the site's Redirects (with --apply; the dry run says what it would add)
 *   --storage=PATH       the site's storage folder, where its redirects are kept (default: beside --content)
 */
require __DIR__ . '/../vendor/autoload.php';

use GnozioCMS\{ImportedRedirects, MediaLibrary, Menus, RedirectRepository, SystemDatabase, Taxonomies, WordPressHttp, WordPressImporter, WordPressReader, WordPressRestTypes, WordPressScraper};
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$site = '';
$opt = ['apply' => false, 'lang' => 'en', 'default-lang' => '', 'home' => 'index', 'kinds' => 'pages,posts,types', 'only-used-media' => false, 'overwrite' => false, 'profile' => '', 'menus' => false, 'custom' => $root . '/custom', 'content' => $root . '/content', 'uploads' => $root . '/public/uploads', 'out' => $root . '/storage/import', 'redirects' => '', 'storage' => ''];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (!array_key_exists($key, $opt)) {
            fwrite(STDERR, "Unknown option --$key\n");
            exit(2);
        }
        $opt[$key] = is_bool($opt[$key]) ? true : (string)$value;
    } elseif ($site === '') {
        $site = $arg;
    }
}
if ($site === '') {
    fwrite(STDERR, "Usage: php scripts/import-wordpress.php https://example.com [--apply] [--lang=en] ...\n");
    exit(2);
}
if (!in_array($opt['redirects'], ['', 'apply'], true)) {
    fwrite(STDERR, "--redirects takes one value: apply\n");
    exit(2);
}
$site = preg_match('#^https?://#i', $site) ? $site : 'https://' . $site;
// The site's redirects, opened only when they are to be put there.
$siteRedirects = null;
if ($opt['redirects'] === 'apply') {
    $storage = $opt['storage'] !== '' ? (string)$opt['storage'] : dirname(rtrim((string)$opt['content'], '/')) . '/storage';
    $database = new SystemDatabase($storage);
    if (!is_file($database->path())) {
        fwrite(STDERR, "No site database at {$database->path()}: point --storage at the site's storage folder.\n");
        exit(2);
    }
    $database->initialize();
    if (!$database->isAvailable()) {
        fwrite(STDERR, 'The site database could not be opened: ' . $database->lastError() . "\n");
        exit(1);
    }
    $siteRedirects = new ImportedRedirects(new RedirectRepository($database));
}
$redirectReport = static function (array $report, bool $done): void {
    printf("  redirects %s: %d new, %d pointed at a new address, %d already there", $done ? 'put into the site' : 'the site would get', $report['added'], $report['updated'], $report['kept']);
    echo $report['skipped'] !== [] ? ', ' . count($report['skipped']) . " left out:\n" : "\n";
    foreach (array_slice($report['skipped'], 0, 20) as $line) {
        echo "    - {$line['from']} {$line['to']} ({$line['reason']})\n";
    }
};
$lang = (string)$opt['lang'];
$defaultLang = $opt['default-lang'] !== '' ? (string)$opt['default-lang'] : $lang;

$media = new MediaLibrary($opt['content'], $opt['uploads']);
$profile = [];
if ($opt['profile'] !== '') {
    if (!is_file((string)$opt['profile'])) {
        fwrite(STDERR, "No such profile: {$opt['profile']}\n");
        exit(2);
    }
    $profile = Yaml::parseFile((string)$opt['profile']);
    $profile = is_array($profile) ? $profile : [];
}
$rest = WordPressRestTypes::parse($profile);
if ($rest['errors'] !== []) {
    fwrite(STDERR, "The profile has mistakes:\n  - " . implode("\n  - ", $rest['errors']) . "\n");
    exit(2);
}
// The languages the terms are kept in: the site's, the content's, and every one the profile writes.
$languages = array_values(array_unique(array_merge([$defaultLang], WordPressRestTypes::languages($rest, $lang))));
$reader = new WordPressReader($site, static fn(string $url): ?array => WordPressHttp::get($url));
$menus = new Menus($opt['content'], static fn(): array => ['languages' => ['default' => $lang, 'available' => array_values(array_unique([$defaultLang, $lang]))]], static fn(string $key, ?string $l = null): string => '', static fn(): array => []);
$importer = new WordPressImporter(
    $reader,
    $opt['content'],
    $media,
    new Taxonomies($opt['content'], $languages),
    static fn(string $url, string $to): int => WordPressHttp::download($url, $to),
    ['lang' => $lang, 'default_lang' => $defaultLang, 'home_slug' => $opt['home'], 'overwrite' => $opt['overwrite'], 'only_used_media' => $opt['only-used-media'], 'kinds' => array_values(array_filter(explode(',', (string)$opt['kinds']))), 'custom_dir' => $opt['custom'], 'menus' => $opt['menus']],
    $profile !== [] ? new WordPressScraper($reader, $profile) : null,
    $profile,
    static fn(string $key, array $menu) => $menus->write($key, $menu)
);

try {
    echo "Reading $site ...\n";
    $plan = $importer->plan();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not read the site: ' . $e->getMessage() . "\n");
    exit(1);
}

$c = $plan['counts'];
echo "\n{$plan['site']['name']}\n";
printf("  pages and posts: %d new, %d to update, %d left alone\n", $c['new'], $c['update'], $c['skip']);
foreach ($plan['terms'] as $name => $list) {
    printf("  %s: %d\n", $name, count($list));
}
printf("  files for the media library: %d\n  redirects from old addresses: %d\n", $c['media'], $c['redirects']);
if ($plan['route_files'] > 0) {
    printf("  route files to fetch and check: %d\n", $plan['route_files']);
}
$kinds = [];
foreach ($plan['items'] as $item) {
    $kind = $item['type'] . ($item['lang'] !== $lang ? " ({$item['lang']})" : '');
    $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
}
echo '  by kind: ' . implode(', ', array_map(static fn($k, $n) => "$k $n", array_keys($kinds), $kinds)) . "\n";
if ($plan['slides'] > 0) {
    echo "  home page slides: {$plan['slides']}\n";
}
if ($plan['has_logo']) {
    echo "  logo: found\n";
}
foreach ($plan['menus'] as $key => $items) {
    echo "  menu '$key': " . count($items) . ' items' . ($opt['menus'] ? " (will be written)\n" : " (add --menus to write it)\n");
}
if ($lang !== $defaultLang) {
    echo "\n  Note: the content is in '$lang' but this site's default language is '$defaultLang', so its addresses start with /$lang/.\n";
}
foreach ($plan['items'] as $item) {
    if ($item['state'] === 'skip') {
        echo "  left alone: {$item['type']}/{$item['slug']} ({$item['reason']})\n";
    }
}
if ($plan['notes'] !== []) {
    echo "\nWhat could not be carried over exactly (" . count($plan['notes']) . "):\n";
    foreach (array_slice($plan['notes'], 0, 15) as $note) {
        echo "  - $note\n";
    }
}
if ($plan['external_images'] !== []) {
    arsort($plan['external_images']);
    echo "\nPictures that live on other sites (left where they are; they may already be gone):\n";
    foreach (array_slice($plan['external_images'], 0, 10, true) as $host => $count) {
        echo "  - $host ($count)\n";
    }
}
if ($plan['unresolved'] !== []) {
    arsort($plan['unresolved']);
    echo "\nLinks to addresses that are not imported (kept as they were; the redirects list may cover them) - " . count($plan['unresolved']) . ":\n";
    foreach (array_slice($plan['unresolved'], 0, 15, true) as $path => $count) {
        echo "  - $path ($count)\n";
    }
}

// What the run did to the terms and what the custom types lack: every merge, every term left out, every entry without a
// place or a translation. The screen shows the first lines of each; report.txt (written with --apply) has them all.
$sections = [
    'merged' => 'Terms merged',
    'term_map_unknown' => 'Lines of term_map that do nothing',
    'skipped_terms' => 'Terms left out (no entries, no twin)',
    'no_coordinates' => 'Entries without a place',
    'one_coordinate' => 'Entries with only one of the two coordinates (left without a place)',
    'missing_translation' => 'Entries without a translation',
    'unmapped' => 'ACF fields the profile does not name',
    'field_notes' => 'Values that were left out',
    'notes' => 'Other notes',
];
$reportLines = static function (array $report, int $limit) use ($sections, $opt): array {
    $out = [];
    foreach ($sections as $key => $title) {
        $lines = $report[$key] ?? [];
        if ($lines === []) {
            continue;
        }
        $out[] = '';
        $out[] = "$title (" . count($lines) . '):';
        foreach ($limit > 0 ? array_slice($lines, 0, $limit) : $lines as $line) {
            $out[] = "  - $line";
        }
        if ($limit > 0 && count($lines) > $limit) {
            $out[] = '  ... ' . (count($lines) - $limit) . ' more' . ($opt['apply'] ? '' : ' (all of them in report.txt after --apply)');
        }
    }
    return $out;
};
foreach ($reportLines($plan['report'], 20) as $line) {
    echo $line . "\n";
}

if (!$opt['apply']) {
    if ($siteRedirects !== null) {
        echo "\n";
        $redirectReport($siteRedirects->apply($plan['redirects'], false), false);
    }
    echo "\nNothing was changed. Run again with --apply to import.\n";
    exit(0);
}

echo "\nImporting ...\n";
$media->ensureDirectories();
$result = $importer->apply($plan);
if (!is_dir($opt['out'])) {
    mkdir($opt['out'], 0775, true);
}
$redirectsFile = $opt['out'] . '/redirects.txt';
file_put_contents($redirectsFile, WordPressImporter::redirectList($result['redirects']));
printf("  %d written, %d updated, %d left alone\n  %d terms added, %d given names they lacked\n  %d files brought into the library", $result['written'], $result['updated'], $result['skipped'], $result['terms_added'], $result['terms_updated'], $result['media_new']);
echo count($result['media_failed']) > 0 ? ', ' . count($result['media_failed']) . " could not be fetched\n" : "\n";
foreach (array_slice($result['media_failed'], 0, 20) as $failed) {
    echo "    - $failed\n";
}
if ($result['route_files_failed'] !== []) {
    echo '  route files that could not be read (the entries were imported without one): ' . count($result['route_files_failed']) . "\n";
    foreach (array_slice($result['route_files_failed'], 0, 20) as $failed) {
        echo "    - $failed\n";
    }
}
$reportFile = $opt['out'] . '/report.txt';
$full = $reportLines($plan['report'], 0);
if ($result['route_files_failed'] !== []) {
    $full = array_merge($full, ['', 'Route files that could not be read (' . count($result['route_files_failed']) . '):'], array_map(static fn(string $l): string => "  - $l", $result['route_files_failed']));
}
file_put_contents($reportFile, 'Import of ' . $site . ' on ' . date('Y-m-d H:i') . "\n" . implode("\n", $full) . "\n");
echo "  the whole report: $reportFile\n";
if ($result['logo'] !== '') {
    echo "  logo brought into the library: {$result['logo']}\n    Set it in Admin > Theme settings > Brand > Logo.\n";
}
foreach ($result['menus'] as $key) {
    echo "  menu '$key' written\n";
}
foreach ($result['content_types'] as $name) {
    echo "  content type '$name' defined in {$opt['custom']}/content-types\n";
}
if ($siteRedirects !== null) {
    printf("  redirects: %d, written to %s\n", count($result['redirects']), $redirectsFile);
    $redirectReport($siteRedirects->apply($result['redirects']), true);
} else {
    printf("  redirects: %d, written to %s\n    Paste them in Admin > Redirects > Import on the site that will serve them, or run again with --redirects=apply.\n", count($result['redirects']), $redirectsFile);
}
echo "\nDone. Open the admin to look through what came in; every imported file has 'imported_from' in its front matter, so running this again updates them.\n";
