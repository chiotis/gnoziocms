<?php
/*
 * Forms: what happens to a submission (checking it, the record kept, the emails it causes) and what the admin shows
 * of forms and what people sent (the list, one form's submissions, deleting, the export).
 *   php tests/unit/forms.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, FormFields, FormProcessor, FormsAdmin, FormSubmissionRepository};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$translate = fn(string $key, string $fallback): string => $key === 'form.error.email' ? 'Bad email!' : $fallback;
$p = new FormProcessor($translate);
$fields = FormFields::normalize([
    ['name' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true],
    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
    ['name' => 'site', 'label' => 'Website', 'type' => 'url'],
    ['name' => 'qty', 'label' => 'How many', 'type' => 'number'],
    ['name' => 'plan', 'label' => 'Plan', 'type' => 'select', 'options' => "basic\npro"],
    ['name' => 'color', 'label' => 'Colour', 'type' => 'radio', 'options' => "red\nblue"],
    ['name' => 'topics', 'label' => 'Topics', 'type' => 'checkboxes', 'options' => "a\nb\nc", 'default' => "a\nb"],
    ['name' => 'agree', 'label' => 'Agree', 'type' => 'checkbox', 'required' => true, 'default' => '1'],
    ['name' => 'note', 'label' => 'Note', 'type' => 'textarea', 'default' => 'hello'],
]);

// ---- what a form starts with
check('defaults: text as written, checkbox as 1 or empty, choices as a list', $p->defaults($fields), ['name' => '', 'email' => '', 'site' => '', 'qty' => '', 'plan' => '', 'color' => '', 'topics' => ['a', 'b'], 'agree' => '1', 'note' => 'hello']);
check('a field without a name is skipped', $p->defaults([['type' => 'text'], ['name' => 'x', 'type' => 'text', 'default' => 'y']]), ['x' => 'y']);

// ---- checking what was sent
$good = ['name' => ' Ada ', 'email' => 'ada@x.test', 'site' => 'https://x.test', 'qty' => '3', 'plan' => 'pro', 'color' => 'red', 'topics' => ['a', 'zzz', ' c ', ''], 'agree' => 'on', 'note' => "  hi \n"];
$r = $p->collect($fields, $good);
check('a good submission has no errors', $r['errors'], []);
check('values are trimmed, unknown choices dropped, a ticked box is 1', [$r['values']['name'], $r['values']['topics'], $r['values']['agree'], $r['values']['note']], ['Ada', ['a', 'c'], '1', 'hi']);
$r = $p->collect($fields, []);
check('required fields are reported with the theme wording', [$r['errors']['name'], $r['errors']['email'], $r['errors']['agree'], isset($r['errors']['note'])], ['This field is required.', 'This field is required.', 'This field is required.', false]);
$r = $p->collect($fields, ['name' => 'A', 'email' => 'not-an-email', 'agree' => '1', 'site' => 'nope', 'qty' => 'many', 'plan' => 'gold', 'color' => 'green']);
check('each kind of mistake has its own message, from the theme when it has one', [$r['errors']['email'], $r['errors']['site'], $r['errors']['qty'], $r['errors']['plan'], $r['errors']['color']], ['Bad email!', 'Please enter a valid URL.', 'Please enter a numeric value.', 'Please select a valid option.', 'Please select a valid option.']);
check('a wrong value is still kept so the form can show it again', $r['values']['plan'], 'gold');
$r = $p->collect($fields, ['name' => 'A', 'email' => 'a@x.test', 'agree' => '1', 'topics' => 'not-a-list']);
check('optional fields may be empty, a list sent as text is nothing', [$r['errors'], $r['values']['topics'], $r['values']['site']], [[], [], '']);
$required = FormFields::normalize([['name' => 'pick', 'label' => 'Pick', 'type' => 'checkboxes', 'required' => true, 'options' => "a\nb"]]);
check('a required choice list needs at least one valid choice', [array_keys($p->collect($required, ['pick' => ['x']])['errors']), $p->collect($required, ['pick' => ['a']])['errors']], [['pick'], []]);
check('a field with no options list accepts any choice', $p->collect(FormFields::normalize([['name' => 'any', 'label' => 'Any', 'type' => 'select']]), ['any' => 'whatever'])['errors'], []);

// ---- parts of a form that only show something
$withParts = FormFields::normalize([
    ['type' => 'heading', 'label' => 'About you'],
    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
    ['type' => 'paragraph', 'label' => 'Explained'],
    ['name' => 'note', 'label' => 'Note', 'type' => 'text', 'default' => 'x'],
]);
check('a heading or a paragraph has no value, is not checked and is not in the answers', [array_keys($p->defaults($withParts)), array_keys($p->collect($withParts, ['name' => 'A'])['values']), $p->collect($withParts, ['name' => 'A', 'heading-1' => 'x'])['errors']], [['name', 'note'], ['name', 'note'], []]);
$partsForm = new ContentItem('forms', 'contact', 'el', ['title' => 'Contact'], '', '', '', 0);
check('nor in the text of the email', $p->body($partsForm, ['name' => 'Ada', 'note' => ''], $withParts, 'https://s.test/c'), "Form: Contact\nURL: https://s.test/c\n\nName: Ada\nNote: ");

// ---- an answer put in a text
$mf = FormFields::normalize([['name' => 'full-name', 'label' => 'Name', 'type' => 'text'], ['name' => 'email', 'label' => 'Email', 'type' => 'email'], ['name' => 'topics', 'label' => 'Topics', 'type' => 'checkboxes', 'options' => 'a,b'], ['type' => 'heading', 'label' => 'H']]);
$vals = ['full-name' => "Ada\r\nLovelace", 'email' => 'ada@x.test', 'topics' => ['a', 'b']];
check('{name} becomes the answer of that field, a list is joined, a line break is a space, and a word that is not a field is left', FormProcessor::fill('Hi {full-name} ({topics}) {nope} {heading-1} {}', $vals, $mf), 'Hi Ada  Lovelace (a, b) {nope} {heading-1} {}');
check('a text with no braces is untouched', FormProcessor::fill('Plain', $vals, $mf), 'Plain');
$autoForm = new ContentItem('forms', 'contact', 'el', ['title' => 'Contact', 'notifications' => ['enabled' => true, 'to' => 'me@x.test', 'subject' => 'From {full-name}', 'auto_reply' => true, 'auto_reply_subject' => 'Thanks {full-name}', 'auto_reply_message' => 'Hello {full-name}, we have {email}.']], '', '', '', 0);
$mails = $p->emails($autoForm, $vals, $mf, [], 'https://s.test/c');
check('the subject and the automatic reply have the answers in them (on one line in a subject)', [$mails[0]['subject'], $mails[1]['subject'], $mails[1]['body']], ['From Ada  Lovelace', 'Thanks Ada  Lovelace', 'Hello Ada  Lovelace, we have ada@x.test.']);

// ---- the record
$form = new ContentItem('forms', 'contact', 'el', ['title' => 'Contact', 'translation_id' => 'tid1'], '', '', '', 0);
$rec = $p->record($form, ['name' => 'Ada'], '10.0.0.1', 'Agent/1.0');
check('the record names the form, its language and group, and the visitor', [$rec['form'], $rec['lang'], $rec['translation_id'], $rec['ip'], $rec['user_agent'], $rec['fields']], ['contact', 'el', 'tid1', '10.0.0.1', 'Agent/1.0', ['name' => 'Ada']]);
check('with the time it was sent', strtotime($rec['submitted_at']) >= time() - 5, true);

// ---- the emails
$values = ['name' => 'Ada', 'email' => 'ada@x.test', 'topics' => ['a', 'b'], 'agree' => '1'];
$mailFields = FormFields::normalize([['name' => 'name', 'label' => 'Your name', 'type' => 'text'], ['name' => 'email', 'label' => 'Email', 'type' => 'email'], ['name' => 'topics', 'label' => 'Topics', 'type' => 'checkboxes', 'options' => "a\nb"]]);
$withMail = fn(array $n, array $meta = []) => new ContentItem('forms', 'contact', 'el', ['title' => 'Contact', 'notifications' => $n] + $meta, '', '', '', 0);
check('nothing is sent with notifications off and no auto reply', $p->emails($withMail([]), $values, $mailFields, [], 'https://s.test/contact'), []);
check('on, but nowhere to send, is nothing', $p->emails($withMail(['enabled' => true]), $values, $mailFields, [], 'https://s.test/contact'), []);
$m = $p->emails($withMail(['enabled' => true, 'to' => 'owner@x.test', 'cc' => 'c@x.test', 'bcc' => ' b@x.test ']), $values, $mailFields, ['from' => 'hello@s.test', 'from_name' => 'The Site'], 'https://s.test/contact');
check('the site gets one mail', count($m), 1);
check('with a default subject, the sender, the visitor as reply-to, and the copies', [$m[0]['to'], $m[0]['subject'], $m[0]['headers']], ['owner@x.test', 'New submission: Contact', ['From' => 'The Site <hello@s.test>', 'Reply-To' => 'ada@x.test', 'Cc' => 'c@x.test', 'Bcc' => 'b@x.test']]);
check('the body lists the form, its address, and each field', $m[0]['body'], "Form: Contact\nURL: https://s.test/contact\n\nYour name: Ada\nEmail: ada@x.test\nTopics: a, b");
$m = $p->emails($withMail(['enabled' => true, 'to' => 'o@x.test', 'subject' => ' Hello ']), $values, $mailFields, [], 'u');
check('a written subject is used, a missing sender is noreply@localhost', [$m[0]['subject'], $m[0]['headers']['From']], ['Hello', 'noreply@localhost']);
$m = $p->emails($withMail(['enabled' => true, 'to' => 'o@x.test', 'reply_to_field' => 'email']), ['name' => 'X', 'email' => 'bad'], $mailFields, [], 'u');
check('no valid visitor address means no reply-to', isset($m[0]['headers']['Reply-To']), false);
$m = $p->emails($withMail(['auto_reply' => true]), $values, $mailFields, ['from' => 'h@s.test'], 'u');
check('an automatic reply goes to the visitor alone, with stock words', [count($m), $m[0]['to'], $m[0]['subject'], str_starts_with($m[0]['body'], 'Thanks for contacting us.'), $m[0]['headers']], [1, 'ada@x.test', 'Thanks for your message', true, ['From' => 'h@s.test']]);
$m = $p->emails($withMail(['auto_reply' => true, 'auto_reply_subject' => 'Got it', 'auto_reply_message' => 'We will write.', 'auto_reply_include' => true]), $values, $mailFields, [], 'https://s.test/c');
check('its words can be written, and the submission attached', [$m[0]['subject'], str_starts_with($m[0]['body'], "We will write.\n\n---\n\nForm: Contact"), str_contains($m[0]['body'], 'Your name: Ada')], ['Got it', true, true]);
check('no email from the visitor, no auto reply', $p->emails($withMail(['auto_reply' => true]), ['name' => 'X'], $mailFields, [], 'u'), []);
check('both at once: the site first, the visitor second', array_column($p->emails($withMail(['enabled' => true, 'to' => 'o@x.test', 'auto_reply' => true]), $values, $mailFields, [], 'u'), 'to'), ['o@x.test', 'ada@x.test']);

// ---- reply-to and text
check('the named field wins', FormProcessor::replyTo(['a' => 'x@x.test', 'email' => 'e@x.test'], [], 'a'), 'x@x.test');
check('then the first email field with a valid address', FormProcessor::replyTo(['m1' => 'bad', 'm2' => 'ok@x.test'], [['name' => 'm1', 'type' => 'email'], ['name' => 'm2', 'type' => 'email']], ''), 'ok@x.test');
check('then a field called email', FormProcessor::replyTo(['email' => 'e@x.test'], [], ''), 'e@x.test');
check('otherwise nothing', FormProcessor::replyTo(['email' => 'bad'], [], 'email'), '');
check('text joins a list', [FormProcessor::text(['a', 'b']), FormProcessor::text('  x '), FormProcessor::text(5)], ['a, b', 'x', '5']);
check('a submission is called by name, full name, or email', [FormProcessor::title(['name' => ' Ada ']), FormProcessor::title(['full_name' => 'Ada L']), FormProcessor::title(['email' => 'a@x.test', 'x' => 1]), FormProcessor::title(['name' => ' ']), FormProcessor::title('junk')], ['Ada', 'Ada L', 'a@x.test', 'Submission', 'Submission']);
check('dates are shown as year-month-day and time, odd text as it is, nothing as nothing', [FormProcessor::date('2026-09-30T10:05:59+00:00') === date('Y-m-d H:i', strtotime('2026-09-30T10:05:59+00:00')), FormProcessor::date('not a date'), FormProcessor::date('')], [true, 'not a date', '']);

// ---- the admin
$dir = sys_get_temp_dir() . '/forms' . getmypid();
foreach (['forms', 'pages'] as $d) { mkdir("$dir/content/$d", 0775, true); }
$settings = ['title' => 'My Site', 'languages' => ['default' => 'el', 'available' => ['el', 'en']]];
$env = new Environment([]);
$env->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($env), $settings);
$write = fn(string $file, string $yaml) => file_put_contents("$dir/content/forms/$file", "---\n$yaml---\nBody\n");
$write('contact.md', "title: Contact\nstatus: published\ntranslation_id: t1\nfields:\n  - {name: name, label: Your name, type: text}\n  - {name: email, label: Email, type: email}\n  - {name: msg, label: Message, type: textarea}\nnotifications: {enabled: true, to: a@x.test}\n");
$write('contact.en.md', "title: Contact EN\nstatus: published\ntranslation_id: t1\n");
$write('survey.md', "title: Annual survey\nstatus: draft\nstore_submissions: false\nfields:\n  - {name: q1, label: Question 1, type: text}\n");
$write('apply.md', "title: Apply\nstatus: published\n");
touch("$dir/content/forms/survey.md", time() - 5000);
touch("$dir/content/forms/apply.md", time() - 9000);
$subs = new FormSubmissionRepository("$dir/content");
$store = function (string $slug, array $fields, string $lang, string $when) use ($subs) { $id = $subs->store($slug, ['form' => $slug, 'lang' => $lang, 'submitted_at' => $when, 'ip' => '1.2.3.4', 'user_agent' => 'UA', 'fields' => $fields]); return $id; };
$now = date('c');
$old = date('c', time() - 30 * 86400);
$a = $store('contact', ['name' => 'Ada', 'email' => 'ada@x.test', 'msg' => 'Hi there'], 'el', $now);
usleep(1100000);
$b = $store('contact', ['name' => 'Bob', 'email' => 'bob@x.test', 'msg' => 'Hello, "world"', 'extra' => ['x', 'y']], 'en', date('c', time() - 86400));
$c = $store('contact', ['email' => 'only@x.test', 'msg' => 'Old one'], 'el', $old);
$admin = new FormsAdmin($content, $subs);

$o = $admin->overview([], 'updated', ['el', 'en'], 'el', true);
check('one row per form, newest change first', array_column($o['rows'], 'slug'), ['contact', 'survey', 'apply']);
check('the totals', $o['totals'], ['forms' => 3, 'published' => 2, 'submissions' => 3, 'recent' => 2]);
$contact = $o['rows'][0];
check('a row shows fields, the other languages and older copies, submissions, and the shortcode', [$contact['title'], $contact['field_count'], array_keys($contact['translations']), $contact['copies'], $contact['submissions'], $contact['recent'], $contact['notifications'], $contact['stores'], $contact['shortcode']], ['Contact', 3, ['en'], ['en'], 3, 2, true, true, '[form slug="contact"]']);
check('the latest submission is shown as a date', $contact['latest_submission'], date('Y-m-d H:i', strtotime($now)));
$survey = $o['rows'][1];
check('a form can keep nothing, and lacks its texts in the other language', [$survey['stores'], $survey['translations']['en'], $survey['copies'], $survey['status'], $survey['submissions']], [false, ['total' => 3, 'missing' => 3], [], 'draft', 0]);
check('with no setting, forms keep submissions by default, or not', [$admin->overview([], 'updated', ['el'], 'el', true)['rows'][2]['stores'], $admin->overview([], 'updated', ['el'], 'el', false)['rows'][2]['stores']], [true, false]);
check('sorted by submissions', array_column($admin->overview([], 'submissions', ['el'], 'el', true)['rows'], 'slug')[0], 'contact');
check('sorted by name', array_column($admin->overview([], 'name', ['el'], 'el', true)['rows'], 'title'), ['Annual survey', 'Apply', 'Contact']);
check('an unknown sort is by date', $admin->overview([], 'whatever', ['el'], 'el', true)['sort'], 'updated');
check('searched by title or address', array_column($admin->overview(['q' => 'SURV'], 'updated', ['el'], 'el', true)['rows'], 'slug'), ['survey']);
check('filtered by status, the totals still count all', [array_column($admin->overview(['status' => 'draft'], 'updated', ['el'], 'el', true)['rows'], 'slug'), $admin->overview(['status' => 'draft'], 'updated', ['el'], 'el', true)['totals']['forms']], [['survey'], 3]);

check('the versions of a form', array_keys($admin->versions('contact')), ['en', 'el']);
check('no such form has none', [$admin->versions('nope'), $admin->versions('')], [[], []]);

$form = $admin->versions('contact')['el'];
$pg = $admin->page($form, []);
check('the submissions come newest first with their counts', [array_column($pg['rows'], 'title'), $pg['total'], $pg['total_all'], $pg['recent'], $pg['page'], $pg['total_pages'], $pg['per_page']], [['Ada', 'Bob', 'only@x.test'], 3, 3, 2, 1, 1, 25]);
$row = $pg['rows'][1];
check('a row has labelled fields, a summary without name and email, and the visitor', [array_column($row['fields'], 'label'), $row['summary'], $row['email'], $row['lang'], $row['ip']], [['Your name', 'Email', 'Message', 'Extra'], 'Hello, "world" · x, y', 'bob@x.test', 'en', '1.2.3.4']);
check('a field the form does not know is labelled from its name', array_column($row['fields'], 'value'), ['Bob', 'bob@x.test', 'Hello, "world"', 'x, y']);
check('filtered by language', array_column($admin->page($form, ['lang' => 'en'])['rows'], 'title'), ['Bob']);
check('by words in any field', array_column($admin->page($form, ['q' => 'hello'])['rows'], 'title'), ['Bob']);
check('by date, and a wrong date format is ignored', [array_column($admin->page($form, ['date_from' => date('Y-m-d', time() - 3 * 86400)])['rows'], 'title'), $admin->page($form, ['date_from' => '30/09/2026'])['total']], [['Ada', 'Bob'], 3]);
check('the filters are returned cleaned', $admin->page($form, ['lang' => 'EN', 'q' => ' x ', 'date_to' => 'bad'])['filters'], ['lang' => 'en', 'q' => 'x', 'date_from' => '', 'date_to' => '', 'has_files' => '']);
check('a page size is one of the options', [$admin->page($form, ['per_page' => '50'])['per_page'], $admin->page($form, ['per_page' => '7'])['per_page']], [50, 25]);
$paged = $admin->page($form, ['per_page' => '25', 'page' => '9']);
check('a page past the end is the last', [$paged['page'], $paged['total_pages']], [1, 1]);
check('the tab in the editor lists them the same way, newest first', array_column($admin->recent('contact'), 'title'), ['Ada', 'Bob', 'only@x.test']);
check('with labels made from the names', array_column($admin->recent('contact')[0]['fields'], 'label'), ['Name', 'Email', 'Msg']);
check('a form nobody wrote to has none', $admin->recent('apply'), []);

// ---- the export
$x = $admin->export($form, 'My Site');
check('the file is named after the site and the form', $x['filename'], 'my-site-contact-submissions.csv');
check('the fixed columns come first, then each field any submission has, in order', $x['headers'], ['id', 'submitted_at', 'site_title', 'form_title', 'form', 'lang', 'translation_id', 'ip', 'user_agent', 'email', 'extra', 'msg', 'name']);
check('one row per submission, newest first, with what the form and the site are called', [$x['count'], $x['rows'][0][2], $x['rows'][0][3], $x['rows'][0][4], $x['rows'][0][9], $x['rows'][0][12]], [3, 'My Site', 'Contact', 'contact', 'ada@x.test', 'Ada']);
check('a list of choices is one cell, a missing field an empty one', [$x['rows'][1][10], $x['rows'][0][10]], ['x, y', '']);
check('a site with no title still names the file', $admin->export($form, '')['filename'], 'site-contact-submissions.csv');
check('a title that has no usable letters falls back to the form address', $admin->export(new ContentItem('forms', 'contact', 'el', ['title' => '日本'], '', '', '', 0), '!!!')['filename'], 'site-contact-submissions.csv');

check('a cell that would run as a formula in a spreadsheet gets a quote in front of it', array_map([FormsAdmin::class, 'spreadsheetSafe'], ['=HYPERLINK("http://e.test","x")', '+1', '-2+3', '@SUM(A1)', " =1+1", "\t=1", 'plain', 'a=b', '', '3.5']), ["'=HYPERLINK(\"http://e.test\",\"x\")", "'+1", "'-2+3", "'@SUM(A1)", "' =1+1", "'\t=1", 'plain', 'a=b', '', '3.5']);
$hostile = new ContentItem('forms', 'hostile', 'el', ['title' => 'Hostile'], '', '', '', 0);
$subs->store('hostile', ['form' => 'hostile', 'lang' => 'el', 'submitted_at' => date('c'), 'ip' => '1.1.1.1', 'user_agent' => '=cmd|calc', 'fields' => ['name' => '=HYPERLINK("http://e.test","Open")']]);
$h = $admin->export($hostile, 'S');
check('in the export neither a field nor the browser\'s name can start a formula', [$h['rows'][0][array_search('user_agent', $h['headers'], true)], $h['rows'][0][array_search('name', $h['headers'], true)]], ["'=cmd|calc", "'=HYPERLINK(\"http://e.test\",\"Open\")"]);

// ---- deleting
check('nothing is deleted without a known action', $admin->deleteSubmissions('contact', ['submission_action' => 'wipe', 'id' => $a]), ['deleted' => 0, 'ids' => [], 'files' => 0]);
check('one is deleted by id', [$admin->deleteSubmissions('contact', ['submission_action' => 'delete', 'id' => $a])['deleted'], $subs->find('contact', $a)], [1, null]);
check('deleting what is gone deletes nothing', $admin->deleteSubmissions('contact', ['submission_action' => 'delete', 'id' => $a])['deleted'], 0);
check('an id that is not an id is refused', $admin->deleteSubmissions('contact', ['submission_action' => 'delete', 'id' => '../../x'])['deleted'], 0);
$r = $admin->deleteSubmissions('contact', ['submission_action' => 'bulk_delete', 'selected_ids' => [$b, $c, 'nope']]);
check('the ticked ones are deleted, the rest ignored', [$r['deleted'], $r['ids'], $subs->all('contact')], [2, [$b, $c, 'nope'], []]);
check('bulk with nothing ticked deletes nothing', $admin->deleteSubmissions('contact', ['submission_action' => 'bulk_delete'])['deleted'], 0);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
