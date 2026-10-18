<?php
/*
 * Folds the per-language copies of a form (contact.md and contact.en.md) into the form of the first language as translations.
 * The site does this by itself the first time it is opened after an update; this runs it again by hand (it only touches copies
 * that are still there) and prints what it did.
 *
 *   php scripts/migrate-form-translations.php --default-lang=el --langs=el,en
 *
 * Options:
 *   --default-lang=el   the site's first language (default en)
 *   --langs=el,en       every language of the site (default: the first language only, which keeps no translation)
 *   --content=PATH      the content folder (default ./content)
 *   --out=PATH          where the report and the put-away copies are kept (default ./storage/form-translation)
 */
require __DIR__ . '/../vendor/autoload.php';

use GnozioCMS\{ContentRepository, FormTranslationMigration};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$root = dirname(__DIR__);
$opt = ['default-lang' => 'en', 'langs' => '', 'content' => $root . '/content', 'out' => $root . '/storage/form-translation'];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (!array_key_exists($key, $opt)) {
            fwrite(STDERR, "Unknown option --$key\n");
            exit(2);
        }
        $opt[$key] = (string)$value;
    }
}
$default = (string)$opt['default-lang'];
$langs = array_values(array_filter(array_map('trim', explode(',', (string)$opt['langs']))));
$settings = ['languages' => ['default' => $default, 'available' => $langs !== [] ? $langs : [$default]]];

$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository((string)$opt['content'], new MarkdownConverter($environment), $settings);
$report = (new FormTranslationMigration($content, (string)$opt['content'], (string)$opt['out'], $settings))->run();

foreach ($report['forms'] as $form) {
    echo $form['form'] . ': folded ' . implode(', ', $form['folded']) . ($form['aliases'] ? ' (old addresses kept: ' . implode(', ', $form['aliases']) . ')' : '') . "\n";
    foreach ($form['unmatched'] as $row) {
        echo '  not matched: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
    foreach ($form['differing'] as $row) {
        echo '  differs: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
foreach ($report['skipped'] as $row) {
    echo 'left as it is: ' . $row['form'] . ' (' . $row['lang'] . '): ' . $row['reason'] . "\n";
}
echo count($report['forms']) . " form(s) folded, {$report['unmatched']} text(s) in the report.\n";
