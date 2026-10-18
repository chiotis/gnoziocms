<?php
/*
 * The ready-made forms the New form screen offers: that each one is whole (its fields survive being stored, its
 * emails refer to fields it has) and comes in English and Greek.
 *   php tests/unit/form-templates.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{FormFields, FormTemplates};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

check('eight templates, the blank one first', FormTemplates::ids(), ['blank', 'contact', 'newsletter', 'quote', 'booking', 'event', 'support', 'feedback']);
check('all of them are offered, in that order', array_keys(FormTemplates::all('en')), FormTemplates::ids());
check('an id that is not one gives nothing', [FormTemplates::get('nope', 'en'), FormTemplates::get('', 'en')], [null, null]);

foreach (['en', 'el', 'de'] as $lang) {
    foreach (FormTemplates::all($lang) as $id => $t) {
        $stored = FormFields::parseInput($t['fields']);
        $names = array_column($stored, 'name');
        $problems = [];
        if (count($stored) !== count($t['fields'])) $problems[] = 'a field was lost on the way into storage';
        if ($names !== array_values(array_unique($names))) $problems[] = 'two fields share a name';
        foreach ($t['fields'] as $i => $f) {
            if (($f['name'] ?? '') !== $stored[$i]['name']) $problems[] = 'a name changed: ' . $f['name'];
            if (isset($f['width']) && ($stored[$i]['width'] ?? '') !== $f['width']) $problems[] = 'a width was lost';
        }
        $emails = array_column(array_filter($stored, fn($f) => $f['type'] === 'email'), 'name');
        $reply = (string)($t['notifications']['reply_to_field'] ?? '');
        if ($reply !== '' && !in_array($reply, $emails, true)) $problems[] = 'the reply goes to a field the form does not have';
        $text = ($t['notifications']['auto_reply_subject'] ?? '') . ' ' . ($t['notifications']['auto_reply_message'] ?? '');
        if (preg_match_all('/\{([a-z0-9-]+)\}/', $text, $m)) {
            foreach ($m[1] as $tag) if (!in_array($tag, $names, true)) $problems[] = "the reply uses {{$tag}}, which is not a field";
        }
        if (!empty($t['notifications']['auto_reply']) && $emails === []) $problems[] = 'an automatic reply and no email field';
        if ($t['name'] === '' || $t['description'] === '' || $t['title'] === '' || $t['submit_label'] === '' || $t['success_message'] === '') $problems[] = 'a text is missing';
        check("$lang / $id is whole", $problems, []);
    }
}
check('Greek has its own words, and a language with none gets English', [FormTemplates::get('contact', 'el')['name'], FormTemplates::get('contact', 'en')['name'], FormTemplates::get('contact', 'de')['name']], ['Επικοινωνία', 'Contact', 'Contact']);
check('a template can be made of fields of many kinds, with display parts and widths', array_values(array_unique(array_column(FormTemplates::get('quote', 'en')['fields'], 'type'))), ['heading', 'text', 'email', 'tel', 'select', 'date', 'textarea', 'checkbox']);
check('the blank one has no fields', FormTemplates::get('blank', 'en')['fields'], []);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
