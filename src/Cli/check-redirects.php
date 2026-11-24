<?php
/*
 * After moving a site: asks the new site for every address the old one had and says which do not lead to a page. Each old
 * address should answer 200 (the same address here) or 301 to a page that answers 200. See docs/wordpress-import.md, "Checking
 * the old addresses".
 *
 *   php src/Cli/check-redirects.php https://new.example --old=https://old.example        the old site's sitemap
 *   php src/Cli/check-redirects.php https://new.example --list=storage/import/redirects.txt   the import's list (old new)
 *
 * Options:
 *   --old=ADDRESS        the old site (its sitemap is read: /wp-sitemap.xml, /sitemap_index.xml or /sitemap.xml) or a sitemap's
 *                        address; while the old site is still up
 *   --sitemap=FILE       a sitemap saved before the move, read from a file
 *   --list=FILE          a list of old addresses, one a line, or "old new" lines (redirects.txt): where each should lead is checked too
 *   --limit=N            check only the first N addresses
 *   --delay=MS           wait this many milliseconds between addresses (default 100), to be gentle with the site
 *   --out=FILE           where the full result is written as CSV (default ./storage/import/redirect-check.csv)
 *   --allow-local        the new site is on this server or its network (a copy being checked before the move)
 *
 * The exit code is 0 when every address leads to a page, 1 when some do not, 2 for a mistake in the options.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../../vendor/autoload.php';

use GnozioCMS\{RedirectCheck, SafeHttp, WordPressHttp};

$root = dirname(__DIR__, 2);
$site = '';
$opt = ['old' => '', 'sitemap' => '', 'list' => '', 'limit' => '0', 'delay' => '100', 'out' => $root . '/storage/import/redirect-check.csv', 'allow-local' => false];
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
if ($site === '' || ($opt['old'] === '' && $opt['sitemap'] === '' && $opt['list'] === '')) {
    fwrite(STDERR, "Usage: php src/Cli/check-redirects.php https://new.example (--old=https://old.example | --sitemap=FILE | --list=FILE)\n");
    exit(2);
}
$site = preg_match('#^https?://#i', $site) ? rtrim($site, '/') : 'https://' . rtrim($site, '/');
$allowLocal = (bool)$opt['allow-local'];
WordPressHttp::$allowLocal = false; // the old site is read as a public one
$http = new SafeHttp();
$checker = new RedirectCheck(
    static function (string $url) use ($opt): ?array {
        if ($opt['sitemap'] !== '' && !preg_match('#^https?://#i', $url)) {
            return is_file($url) ? ['status' => 200, 'body' => (string)file_get_contents($url)] : null;
        }
        return WordPressHttp::get($url);
    },
    static fn(string $url): array => $http->step($url, 20, $allowLocal)
);

$lines = [];
if ($opt['list'] !== '') {
    if (!is_file($opt['list'])) {
        fwrite(STDERR, "No such list: {$opt['list']}\n");
        exit(2);
    }
    $lines = RedirectCheck::listLines((string)file_get_contents($opt['list']));
}
if ($opt['sitemap'] !== '') {
    if (!is_file($opt['sitemap'])) {
        fwrite(STDERR, "No such sitemap: {$opt['sitemap']}\n");
        exit(2);
    }
    foreach ($checker->sitemapAddresses($opt['sitemap']) as $address) {
        $lines[] = [$address, ''];
    }
}
if ($opt['old'] !== '') {
    $old = preg_match('#^https?://#i', $opt['old']) ? $opt['old'] : 'https://' . $opt['old'];
    echo "Reading the sitemap of $old ...\n";
    $found = $checker->sitemapAddresses($old);
    if ($found === []) {
        fwrite(STDERR, "No sitemap with addresses was found at $old (tried /wp-sitemap.xml, /sitemap_index.xml, /sitemap.xml).\n");
        exit(1);
    }
    foreach ($found as $address) {
        $lines[] = [$address, ''];
    }
}
// One check for each old address; a line that says where it leads wins over the sitemap's line for the same address.
$unique = [];
foreach ($lines as [$old, $to]) {
    $key = RedirectCheck::pathAndQuery($old);
    if (!isset($unique[$key]) || ($unique[$key][1] === '' && $to !== '')) {
        $unique[$key] = [$old, $to];
    }
}
$lines = array_values($unique);
if ((int)$opt['limit'] > 0) {
    $lines = array_slice($lines, 0, (int)$opt['limit']);
}
$total = count($lines);
echo "Checking $total old addresses against $site ...\n";
$delay = max(0, (int)$opt['delay']) * 1000;
$result = $checker->run($site, $lines, static function (int $done, array $row) use ($total, $delay): void {
    if (!in_array($row['outcome'], RedirectCheck::GOOD, true)) {
        echo "  " . str_pad($row['outcome'], 12) . ' ' . RedirectCheck::pathAndQuery($row['old']) . '  [' . implode(' ', $row['steps']) . ']' . ($row['note'] !== '' ? '  ' . $row['note'] : '') . "\n";
    }
    if ($done % 50 === 0 || $done === $total) {
        echo "  ... $done of $total\n";
    }
    if ($delay > 0) {
        usleep($delay);
    }
});

echo "\n";
foreach (RedirectCheck::OUTCOMES as $key => $label) {
    if ($result['counts'][$key] > 0) {
        printf("  %5d  %s\n", $result['counts'][$key], $label);
    }
}
$dir = dirname((string)$opt['out']);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
if (@file_put_contents((string)$opt['out'], RedirectCheck::csv($result['rows'])) !== false) {
    echo "\nThe whole result: {$opt['out']}\n";
}
$problems = count($result['problems']);
echo $problems === 0 ? "Every old address leads to a page.\n" : "$problems old addresses need a look (above, and in the CSV).\n";
exit($problems === 0 ? 0 : 1);
