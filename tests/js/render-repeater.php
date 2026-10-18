<?php
/*
 * Draws the admin form of a list of rows with a list in each row (admin/templates/partials/fields.twig, the real macros), for
 * repeaters.test.js. Reads {"definitions": {...}, "name": "...", "values": {...}} on standard input; the definitions go through
 * FieldSchema like any content type's, and the HTML of each field is written out.
 *   echo '{"definitions": {...}, "name": "details", "values": {}}' | php tests/js/render-repeater.php
 */
ini_set('display_errors', '0');
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\FieldSchema;

$input = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($input)) {
    fwrite(STDERR, "expected a JSON object\n");
    exit(2);
}
$loader = new Twig\Loader\FilesystemLoader();
$loader->addPath($repo . '/admin/templates', 'admin');
$twig = new Twig\Environment($loader, ['autoescape' => 'html', 'strict_variables' => false]);
$twig->addGlobal('admin_languages', $input['languages'] ?? ['el']);
$twig->addFilter(new Twig\TwigFilter('localized', fn(mixed $value): string => is_array($value) ? (string)($value['default'] ?? reset($value)) : (string)$value));
$fields = FieldSchema::normalize($input['definitions'] ?? []);
$template = $twig->createTemplate("{% import '@admin/partials/fields.twig' as f %}{% for key, field in fields %}{{ f.field(field, name ~ '[' ~ key ~ ']', values[key]|default([])) }}{% endfor %}");
echo $template->render(['fields' => $fields, 'name' => (string)($input['name'] ?? 'details'), 'values' => $input['values'] ?? []]);
