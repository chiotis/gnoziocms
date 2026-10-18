<?php
/*
 * Form translations: a form is built once in the first language, and every other language is a set of translations stored
 * with it (per field: label, placeholder, help, choice labels; per form: title, button, messages, emails). Storage and
 * cleaning, the fallback to the first language and what is missing, the form shown and sent in a language (the record and
 * the emails), and the one-time migration of the older per-language copies.
 *   php tests/unit/form-translations.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, FormFields, FormProcessor, FormsAdmin, FormSubmissionRepository, FormTranslationMigration, FormTranslations, PublicForms};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- storage: what the builder sends is cleaned
$sent = [
    ['type' => 'text', 'name' => 'name', 'label' => 'Όνομα', 'placeholder' => 'Γράψε', 'help' => 'Βοήθεια', 'translations' => [
        'en' => ['label' => ' Name ', 'placeholder' => 'Type', 'help' => '', 'junk' => 'x'],
        'fr' => ['label' => 'Nom'],
        'EN2' => ['label' => 'no'],
    ]],
    ['type' => 'select', 'name' => 'topic', 'label' => 'Θέμα', 'options' => [['value' => 'a', 'label' => 'Α'], ['value' => 'b', 'label' => 'Β']], 'translations' => [
        'en' => ['label' => 'Topic', 'options' => ['a' => 'A', 'b' => '', 'gone' => 'Gone']],
    ]],
    ['type' => 'heading', 'name' => 'h', 'label' => 'Τίτλος', 'translations' => ['en' => ['label' => 'Title', 'options' => ['x' => 'y']]]],
];
$stored = FormFields::parseInput($sent, ['en']);
check('a field keeps the translations of the site\'s other languages, trimmed, without what is empty or unknown', $stored[0]['translations'], ['en' => ['label' => 'Name', 'placeholder' => 'Type']]);
check('a choice that is gone, or has no text, has no translation; the values stay as they are', [$stored[1]['options'], $stored[1]['translations']], [['a|Α', 'b|Β'], ['en' => ['label' => 'Topic', 'options' => ['a' => 'A']]]]);
check('a heading is translated by its text alone', $stored[2]['translations'], ['en' => ['label' => 'Title']]);
check('with no list of languages any language code is kept (the migration and the importer)', array_keys(FormFields::parseInput($sent)[0]['translations']), ['en', 'fr']);
check('a field with none has none stored', isset(FormFields::parseInput([['type' => 'text', 'name' => 'x', 'label' => 'X']], ['en'])[0]['translations']), false);
$read = FormFields::normalize($stored);
check('read back, the translations are there and the option values are unchanged', [$read[0]['translations']['en']['label'], array_column($read[1]['options'], 'value'), $read[1]['translations']['en']['options']], ['Name', ['a', 'b'], ['a' => 'A']]);
check('the form\'s own texts are cleaned the same way', FormTranslations::cleanForm(['en' => ['title' => ' Contact ', 'submit_label' => 'Send', 'success_message' => '', 'body' => 'Text', 'notifications' => ['auto_reply_subject' => 'Thanks', 'to' => 'x@x.test']], 'de' => ['title' => 'Kontakt'], 'xx1' => ['title' => 'no']], ['en']), ['en' => ['title' => 'Contact', 'body' => 'Text', 'submit_label' => 'Send', 'notifications' => ['auto_reply_subject' => 'Thanks']]]);
check('and nothing for a language the site does not have', FormTranslations::cleanForm(['fr' => ['title' => 'x']], ['en']), []);

// ---- fallback and what is missing
$meta = [
    'title' => 'Επικοινωνία', 'submit_label' => 'Αποστολή', 'success_message' => 'Ευχαριστούμε', 'excerpt' => '',
    'notifications' => ['enabled' => true, 'to' => 'o@x.test', 'subject' => 'Νέο', 'auto_reply' => true, 'auto_reply_subject' => 'Λάβαμε', 'auto_reply_message' => 'Ευχαριστούμε πολύ'],
    'fields' => $stored,
    'translations' => ['en' => ['title' => 'Contact', 'submit_label' => 'Send', 'notifications' => ['auto_reply_subject' => 'We got it']]],
];
$fields = FormFields::normalize($meta['fields']);
$en = FormTranslations::localizeFields($fields, 'en', 'el');
check('a translated text is shown, option labels too; the values stay', [$en[0]['label'], $en[0]['placeholder'], $en[0]['help'], $en[1]['label'], $en[1]['options']], ['Name', 'Type', 'Βοήθεια', 'Topic', [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'Β']]]);
check('a text with no translation is the first language\'s', [$en[0]['help'], $en[1]['options'][1]['label'], $en[2]['label']], ['Βοήθεια', 'Β', 'Title']);
check('the first language, and a language with nothing, show the form as it is', [FormTranslations::localizeFields($fields, 'el', 'el') === $fields, FormTranslations::localizeFields($fields, 'de', 'el') === $fields], [true, true]);
$lm = FormTranslations::localizeMeta($meta, 'en', 'el');
check('the form\'s own texts: translated ones win, the rest stay', [$lm['title'], $lm['submit_label'], $lm['success_message'], $lm['notifications']['auto_reply_subject'], $lm['notifications']['auto_reply_message'], $lm['notifications']['to']], ['Contact', 'Send', 'Ευχαριστούμε', 'We got it', 'Ευχαριστούμε πολύ', 'o@x.test']);
$item = new ContentItem('forms', 'contact', 'el', $meta + ['translation_id' => 't1'], "Κείμενο\n", '<p>Κείμενο</p>', '/x/contact.md', 5);
$local = FormTranslations::localizeItem($item, 'en', 'el');
check('a form as shown in a language has that language, and keeps its address', [$local->lang, $local->slug, $local->meta['title'], $local->meta['lang']], ['en', 'contact', 'Contact', 'en']);
check('its text above the form is translated when there is a translation (converted by the caller), else the first language\'s', [FormTranslations::localizeItem($item, 'en', 'el')->html, FormTranslations::localizeItem($item, 'fr', 'el')->html], ['<p>Κείμενο</p>', '<p>Κείμενο</p>']);
$withBody = new ContentItem('forms', 'contact', 'el', ['translations' => ['en' => ['body' => 'Hello']]], 'Γεια', '<p>Γεια</p>', '/x', 1);
check('translated body', FormTranslations::localizeItem($withBody, 'en', 'el', fn(string $md): string => '<p>' . $md . '</p>')->html, '<p>Hello</p>');
$meta['body'] = '';
$count = FormTranslations::missing($meta, 'en');
// texts of the first language: 3 field labels... counted below
check('what English still lacks: the help of Name, choice B, the thank-you message, the subject and the reply\'s text', [$count['total'], $count['missing'], $count['fields']], [13, 5, ['name' => 1, 'topic' => 1]]);
check('a language with no translation lacks everything', FormTranslations::missing($meta, 'fr')['missing'], 13);
check('the overview counts each other language, not the first', FormTranslations::overview($meta, ['el', 'en', 'fr'], 'el'), ['en' => ['total' => 13, 'missing' => 5], 'fr' => ['total' => 13, 'missing' => 13]]);

// ---- the form shown and sent in a language
$dir = sys_get_temp_dir() . '/formtr' . getmypid();
foreach (['forms', 'pages'] as $d) { mkdir("$dir/content/$d", 0775, true); }
$settings = ['base_url' => 'https://s.test', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'forms' => ['store_submissions' => true]];
$env = new Environment([]);
$env->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($env), $settings);
$put = function (string $file, array $meta, string $body = "Body\n") use ($dir) { file_put_contents("$dir/content/forms/$file", "---\n" . Yaml::dump($meta, 10, 2) . "---\n$body"); };
$put('contact.md', ['title' => 'Επικοινωνία', 'status' => 'published', 'translation_id' => 't1', 'submit_label' => 'Αποστολή', 'success_message' => 'Ευχαριστούμε',
    'notifications' => ['enabled' => true, 'to' => 'owner@x.test', 'subject' => 'Νέο μήνυμα', 'auto_reply' => true, 'auto_reply_subject' => 'Λάβαμε το μήνυμά σας', 'auto_reply_message' => 'Θα απαντήσουμε σύντομα.'],
    'fields' => $stored, 'translations' => $meta['translations']]);
$subs = new FormSubmissionRepository("$dir/content");
$mail = [];
$services = new PublicForms(
    new FormProcessor(fn(string $k, string $f): string => $f), $subs, fn(): array => $settings, fn(string $k, ?string $f): string => (string)$f,
    fn(string $path): string => 'https://s.test/' . ltrim($path, '/'), function (string $to, string $subject, string $body, array $headers) use (&$mail): void { $mail[] = compact('to', 'subject', 'body'); },
    fn(string $slug, int $seconds): bool => false, fn(string $slug) => null
);
$form = $content->find('forms', 'contact', 'en', true, true);
check('the English page asks for the form and gets the first-language one, built once', [$form->slug, $form->lang], ['contact', 'el']);
$e = $services->embed($form, 'en/contact', null, [], 'en');
check('rendered in English: labels, placeholder, choices, the button', [$e['form_fields'][0]['label'], $e['form_fields'][0]['placeholder'], $e['form_fields'][1]['options'][0]['label'], $e['form']->meta['submit_label'], $e['form']->lang], ['Name', 'Type', 'A', 'Send', 'en']);
$g = $services->embed($form, 'contact', null, [], 'el');
check('rendered in Greek: the first language, untouched', [$g['form_fields'][0]['label'], $g['form_fields'][1]['options'][0]['label'], $g['form']->meta['submit_label'], $g['form_message']], ['Όνομα', 'Α', 'Αποστολή', 'Ευχαριστούμε']);
$f = $services->embed($form, 'fr/contact', null, [], 'fr');
check('in a language with no translation it falls back to the first language', [$f['form_fields'][0]['label'], $f['form']->meta['submit_label']], ['Όνομα', 'Αποστολή']);
check('without a language the form is as stored', $services->embed($form, 'contact', null, [])['form_fields'][0]['label'], 'Όνομα');

$post = ['form_slug' => 'contact', 'name' => 'Ada'];
$request = ['method' => 'POST', 'get' => [], 'post' => $post, 'ip' => '1.1.1.1', 'agent' => 'UA'];
$result = $services->handle($form, 'en', 'en/contact', $request);
$sent = $subs->all('contact');
check('a submission sent from the English page is recorded as English, under the one form', [count($sent), $sent[0]['lang'], $sent[0]['form'], $sent[0]['translation_id']], [1, 'en', 'contact', 't1']);
$thanks = $result['state']['message'];
check('and the visitor is thanked in English where there is a text, else in the first language', $thanks, 'Ευχαριστούμε');
check('the notification and the automatic reply use the language: the reply is in English where translated', [$mail[0]['to'], $mail[0]['subject']], ['owner@x.test', 'Νέο μήνυμα']);
$replyForm = new ContentItem('forms', 'contact', 'el', $form->meta, '', '', '', 0);
$mail = [];
$fieldsWithEmail = $form->meta['fields'];
$fieldsWithEmail[] = ['type' => 'email', 'name' => 'email', 'label' => 'Email'];
$replyForm->meta['fields'] = $fieldsWithEmail;
$services->handle($replyForm, 'en', 'en/contact', ['post' => ['form_slug' => 'contact', 'name' => 'Ada', 'email' => 'ada@x.test']] + $request);
$subjects = array_column($mail, 'subject', 'to');
check('the automatic reply goes out in the language the form was shown in', $subjects['ada@x.test'] ?? null, 'We got it');
check('and the notification body names the fields as the visitor saw them', str_contains($mail[0]['body'], 'Form: Contact') && str_contains($mail[0]['body'], 'Name: Ada'), true);
$mail = [];
$services->handle($replyForm, 'el', 'contact', ['post' => ['form_slug' => 'contact', 'name' => 'Nikos', 'email' => 'n@x.test']] + $request);
check('in Greek the reply is the Greek one', array_column($mail, 'subject', 'to')['n@x.test'] ?? null, 'Λάβαμε το μήνυμά σας');
check('the submissions list can be filtered by language', [count($subs->filter($subs->all('contact'), ['lang' => 'en'])), count($subs->filter($subs->all('contact'), ['lang' => 'el'])), count($subs->filter($subs->all('contact'), []))], [2, 1, 3]);

// ---- the migration of the older per-language copies
$mdir = sys_get_temp_dir() . '/formmig' . getmypid();
mkdir("$mdir/content/forms", 0775, true);
mkdir("$mdir/content/forms-submissions/contact-en", 0775, true);
mkdir("$mdir/content/forms-submissions/contact", 0775, true);
file_put_contents("$mdir/content/forms-submissions/contact-en/old1.json", json_encode(['id' => 'old1', 'form' => 'contact-en', 'submitted_at' => '2026-01-01T10:00:00+00:00', 'fields' => ['name' => 'Old']]));
file_put_contents("$mdir/content/forms-submissions/contact/old2.json", json_encode(['id' => 'old2', 'form' => 'contact', 'lang' => 'el', 'submitted_at' => '2026-01-02T10:00:00+00:00', 'fields' => ['name' => 'Palio']]));
$mput = fn(string $file, array $m, string $body = "\n") => file_put_contents("$mdir/content/forms/$file", "---\n" . Yaml::dump($m, 10, 2) . "---\n" . $body);
$mput('contact.md', ['title' => 'Επικοινωνία', 'translation_id' => 'tt', 'submit_label' => 'Αποστολή', 'notifications' => ['enabled' => true, 'to' => 'a@x.test', 'auto_reply' => true, 'auto_reply_subject' => 'Λάβαμε'],
    'fields' => [['type' => 'text', 'name' => 'name', 'label' => 'Όνομα', 'placeholder' => 'Γράψε'], ['type' => 'select', 'name' => 'topic', 'label' => 'Θέμα', 'options' => ['a|Α', 'b|Β']]]], "Κείμενο\n");
// The English copy has its own address, a field the form does not have, a choice it does not have, and another address for the mails.
$mput('contact-en.en.md', ['title' => 'Contact', 'translation_id' => 'tt', 'submit_label' => 'Send', 'success_message' => 'Thanks', 'notifications' => ['enabled' => true, 'to' => 'b@x.test', 'auto_reply' => true, 'auto_reply_subject' => 'We got it'],
    'fields' => [['type' => 'text', 'name' => 'name', 'label' => 'Name', 'placeholder' => 'Type'], ['type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => ['a|A', 'c|C']], ['type' => 'text', 'name' => 'fax', 'label' => 'Fax', 'help' => 'Old machine']]], "Some text\n");
// A second form whose copy shares the address (the usual contact.md / contact.en.md).
$mput('survey.md', ['title' => 'Έρευνα', 'translation_id' => 'ss', 'fields' => [['type' => 'text', 'name' => 'q1', 'label' => 'Ερώτηση']]]);
$mput('survey.en.md', ['title' => 'Survey', 'translation_id' => 'ss', 'fields' => [['type' => 'text', 'name' => 'q1', 'label' => 'Question']]]);
// A form that only exists in English cannot be folded into anything.
$mput('only.en.md', ['title' => 'Only English', 'translation_id' => 'oo']);
$msettings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$mcontent = new ContentRepository("$mdir/content", new MarkdownConverter($env), $msettings);
$migration = new FormTranslationMigration($mcontent, "$mdir/content", "$mdir/storage/form-translation", $msettings);
check('it has not run', $migration->done(), false);
$report = $migration->runOnce();
check('it runs once, folds two forms and leaves the one it cannot', [$migration->done(), count($report['forms']), array_column($report['skipped'], 'form')], [true, 2, ['only']]);
$files = array_map('basename', glob("$mdir/content/forms/*.md"));
sort($files);
check('the copies are gone from the forms folder (kept aside), the form of the first language and the lone English one stay', $files, ['contact.md', 'only.en.md', 'survey.md']);
check('and the copies are kept aside, not deleted', array_map('basename', glob("$mdir/storage/form-translation/copies/*.md")) !== [], true);
$mcontent->forget();
$contact = $mcontent->find('forms', 'contact', 'el', true, false);
$cf = FormFields::normalize($contact->meta['fields']);
check('fields are matched by name: the English texts are translations of the fields', [$cf[0]['translations']['en'], $cf[1]['translations']['en']['label'], $cf[1]['translations']['en']['options']], [['label' => 'Name', 'placeholder' => 'Type'], 'Topic', ['a' => 'A']]);
check('the first-language texts are untouched', [$cf[0]['label'], $cf[0]['placeholder'], array_column($cf[1]['options'], 'label')], ['Όνομα', 'Γράψε', ['Α', 'Β']]);
check('the form\'s own texts are translations too', [$contact->meta['translations']['en']['title'], $contact->meta['translations']['en']['submit_label'], $contact->meta['translations']['en']['success_message'], $contact->meta['translations']['en']['body'], $contact->meta['translations']['en']['notifications']['auto_reply_subject']], ['Contact', 'Send', 'Thanks', 'Some text', 'We got it']);
$entry = $report['forms'][0]['form'] === 'contact' ? $report['forms'][0] : $report['forms'][1];
$un = $entry['unmatched'];
check('a field with no match, and a choice with no match, are kept in the report, not dropped', count($un), 2);
$fields = array_column($un, 'field');
sort($fields);
check('they are the fax field and the choice c of topic', $fields, ['fax', 'topic']);
check('the fax field\'s text is in the report', array_values(array_filter($un, fn($r) => $r['field'] === 'fax'))[0]['label'] . '/' . array_values(array_filter($un, fn($r) => $r['field'] === 'fax'))[0]['help'], 'Fax/Old machine');
check('a setting that was different in the copy is reported, not copied', [array_column($entry['differing'], 'setting'), $contact->meta['notifications']['to']], [['notifications.to'], 'a@x.test']);
check('the copy\'s own address stays working as an alias of the form', [$contact->meta['aliases'], $entry['aliases']], [['contact-en' => 'en'], ['contact-en']]);
$found = FormTranslations::find($mcontent, 'contact-en', 'en', true);
check('the old English form id still finds the form, and says it was an old address in English', [$found['form']?->slug, $found['alias_lang']], ['contact', 'en']);
check('the new address finds it in English too, with the first-language form', [FormTranslations::find($mcontent, 'contact', 'en', true)['form']?->lang, FormTranslations::find($mcontent, 'contact', 'en', true)['alias_lang']], ['el', '']);
check('an address nobody had finds nothing', FormTranslations::find($mcontent, 'nothing', 'en', true)['form'], null);
check('submissions filed under the copy\'s address are now the form\'s, marked English; the others are as they were', [
    (new FormSubmissionRepository("$mdir/content"))->find('contact', 'old1')['lang'] ?? null,
    (new FormSubmissionRepository("$mdir/content"))->find('contact', 'old1')['form'] ?? null,
    (new FormSubmissionRepository("$mdir/content"))->find('contact', 'old2')['name'] ?? (new FormSubmissionRepository("$mdir/content"))->find('contact', 'old2')['fields']['name'],
    is_dir("$mdir/content/forms-submissions/contact-en"),
], ['en', 'contact', 'Palio', false]);
check('moved submissions are counted', $entry['moved_submissions'], 1);
$survey = $mcontent->find('forms', 'survey', 'el', true, false);
check('a copy at the same address becomes translations with no alias', [FormFields::normalize($survey->meta['fields'])[0]['translations']['en']['label'], $survey->meta['translations']['en']['title'], isset($survey->meta['aliases'])], ['Question', 'Survey', false]);
check('and the English page of the form still renders the form (the first-language one with its translations)', FormTranslations::localizeItem(FormTranslations::find($mcontent, 'survey', 'en', true)['form'], 'en', 'el')->meta['title'], 'Survey');

// Running it again changes nothing.
$before = file_get_contents("$mdir/content/forms/contact.md");
$again = $migration->run();
check('run again, it finds nothing to fold, and the form is the same file', [count($again['forms']), file_get_contents("$mdir/content/forms/contact.md") === $before], [0, true]);
check('and the automatic run does not start again', $migration->runOnce(), null);
// A copy that comes back (a restored backup) is folded again without losing what the form has.
$mput('survey.en.md', ['title' => 'Other title', 'translation_id' => 'ss', 'fields' => [['type' => 'text', 'name' => 'q1', 'label' => 'Other question']]]);
$mcontent->forget();
$migration->run();
$mcontent->forget();
$survey = $mcontent->find('forms', 'survey', 'el', true, false);
check('a copy that comes back never overwrites a translation the form already has', [FormFields::normalize($survey->meta['fields'])[0]['translations']['en']['label'], $survey->meta['translations']['en']['title']], ['Question', 'Survey']);

// ---- the list of forms
$admin = new FormsAdmin($mcontent, new FormSubmissionRepository("$mdir/content"));
$rows = $admin->overview([], 'name', ['el', 'en'], 'el', true)['rows'];
$byslug = array_column($rows, null, 'slug');
ksort($byslug);
check('each form is listed once, with what English lacks', [array_keys($byslug), $byslug['contact']['translations']['en']['missing'] > 0, $byslug['contact']['copies']], [['contact', 'only', 'survey'], true, []]);
check('the report that is left for a person is listed', array_keys($admin->migrationNote($migration->report()) ?? []), ['unmatched', 'differing', 'skipped', 'ran_at']);

function rrm(string $d): void { foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $p) { if (in_array(basename($p), ['.', '..'], true)) continue; is_dir($p) ? rrm($p) : unlink($p); } @rmdir($d); }
rrm($dir); rrm($mdir);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
