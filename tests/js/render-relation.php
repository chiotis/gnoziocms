<?php
/*
 * Draws the admin form of relation fields (admin/templates/partials/fields.twig, the real macros), for relations.test.js.
 * Reads {"definitions": {...}, "name": "...", "values": {...}, "labels": {"<id>": {title, type, status, hidden, edit_url}}} on standard input.
 *   echo '{"definitions": {...}, "name": "details", "values": {}, "labels": {}}' | php tests/js/render-relation.php
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
$twig->addGlobal('admin_languages', ['el']);
$twig->addFilter(new Twig\TwigFilter('localized', fn(mixed $value): string => is_array($value) ? (string)($value['default'] ?? reset($value)) : (string)$value));
$labels = $input['labels'] ?? [];
$attach = function (array $fields) use (&$attach, $labels): array {
    foreach ($fields as $key => $field) {
        if ($field['type'] === 'repeater') {
            $fields[$key]['fields'] = $attach($field['fields']);
        } elseif ($field['type'] === 'relation') {
            $fields[$key]['labels'] = $labels;
        }
    }
    return $fields;
};
$fields = $attach(FieldSchema::normalize($input['definitions'] ?? []));
$template = $twig->createTemplate("{% import '@admin/partials/fields.twig' as f %}{% for key, field in fields %}{{ f.field(field, name ~ '[' ~ key ~ ']', values[key]|default(field.multiple|default(false) ? [] : '')) }}{% endfor %}");
echo $template->render(['fields' => $fields, 'name' => (string)($input['name'] ?? 'details'), 'values' => $input['values'] ?? []]);
