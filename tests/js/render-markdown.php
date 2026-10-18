<?php
/*
 * Draws Markdown the way the visual editor's endpoint does, for the serializer test (serializer.test.js).
 * Reads a JSON list of Markdown texts on standard input and writes the JSON list of what VisualMarkdown makes of each.
 * The Markdown environment is the application's own (App::markdownEnvironment), so the test cannot drift from the site.
 *   echo '["# Hi"]' | php tests/js/render-markdown.php
 */
// What it prints is JSON read by the test: a notice from a library about a newer PHP must not end up in it.
ini_set('display_errors', '0');
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnozioCMS\App;
use GnozioCMS\VisualMarkdown;

$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
$environment = new ReflectionMethod(App::class, 'markdownEnvironment');
$visual = new VisualMarkdown(fn() => $environment->invoke($app));

$texts = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($texts)) {
    fwrite(STDERR, "expected a JSON list of texts\n");
    exit(2);
}
echo json_encode(array_map(fn($text) => $visual->render((string)$text), $texts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
