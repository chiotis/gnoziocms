<?php
/*
 * Forms that show a part only for some answers, forms in steps, the rating field, the form's look, and archiving what
 * people sent: what the builder's fields are stored as, which parts are shown for an answer (and that what is hidden is
 * neither checked, kept nor emailed), a rating checked and written as "4 / 5", and submissions archived, listed apart,
 * restored, exported and deleted from the archive.
 *   php tests/unit/form-logic.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GnozioCMS\{ContentItem, ContentRepository, FormConditions, FormFields, FormProcessor, FormsAdmin, FormSubmissionRepository};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- what the builder sends, as it is stored
check('rating and step are kinds of field, a step only shows something', [in_array('rating', FormFields::types(), true), in_array('step', FormFields::types(), true), FormFields::isDisplay('step'), FormFields::isDisplay('rating')], [true, true, true, false]);
check('a condition is a field, a test and (for is and is not) a value', [
    FormConditions::clean(['field' => 'Topic', 'op' => 'is', 'value' => ' sales ']),
    FormConditions::clean(['field' => 'topic', 'op' => 'filled', 'value' => 'ignored']),
    FormConditions::clean(['field' => 'topic', 'op' => 'is', 'value' => '']),
    FormConditions::clean(['field' => 'topic', 'op' => 'like']),
    FormConditions::clean(['field' => '', 'op' => 'filled']),
    FormConditions::clean('topic'),
], [['field' => 'topic', 'op' => 'is', 'value' => 'sales'], ['field' => 'topic', 'op' => 'filled'], null, null, null, null]);

$stored = FormFields::parseInput([
    ['type' => 'radio', 'name' => 'topic', 'label' => 'Topic', 'options' => [['value' => 'sales', 'label' => 'Sales'], ['value' => 'support', 'label' => 'Support']]],
    ['type' => 'text', 'name' => 'company', 'label' => 'Company', 'width' => 'half', 'condition' => ['field' => 'topic', 'op' => 'is', 'value' => 'sales']],
    ['type' => 'heading', 'label' => 'About the problem', 'condition' => ['field' => 'topic', 'op' => 'is', 'value' => 'support']],
    ['type' => 'step', 'label' => 'Your rating', 'width' => 'half', 'condition' => ['field' => 'topic', 'op' => 'filled']],
    ['type' => 'rating', 'name' => 'stars', 'label' => 'How did we do?', 'max' => '7', 'required' => true, 'placeholder' => 'x', 'options' => "a\nb"],
    ['type' => 'rating', 'name' => 'wild', 'label' => 'Wild', 'max' => '99'],
    ['type' => 'text', 'name' => 'why', 'label' => 'Why', 'condition' => ['field' => 'gone', 'op' => 'filled']],
    ['type' => 'text', 'name' => 'self', 'label' => 'Self', 'condition' => ['field' => 'self', 'op' => 'filled']],
]);
check('a field keeps its condition', $stored[1]['condition'], ['field' => 'topic', 'op' => 'is', 'value' => 'sales']);
check('a heading can have one too', $stored[2]['condition'], ['field' => 'topic', 'op' => 'is', 'value' => 'support']);
check('a step is named after its kind, takes the whole row, and can be skipped for an answer', [$stored[3]['name'], isset($stored[3]['width']), $stored[3]['condition']], ['step-1', false, ['field' => 'topic', 'op' => 'filled']]);
check('a rating keeps how many stars it has, and nothing a rating has no use for', [$stored[4]['max'], $stored[4]['required'], isset($stored[4]['placeholder']), isset($stored[4]['options'])], ['7', true, false, false]);
check('stars are kept between 2 and 10, 5 when nothing is said', [$stored[5]['max'], FormFields::ratingMax(''), FormFields::ratingMax('1'), FormFields::ratingMax(3)], ['10', 5, 2, 3]);
check('a condition on a field the form does not have, or on the field itself, is dropped', [isset($stored[6]['condition']), isset($stored[7]['condition'])], [false, false]);
$read = FormFields::normalize($stored);
check('read back for the site and the builder', [$read[1]['condition'], $read[4]['max'], isset($read[0]['condition']), $read[3]['width']], [['field' => 'topic', 'op' => 'is', 'value' => 'sales'], '7', false, '']);
check('the builder gets them as they are', [FormFields::forBuilder($stored)[1]['condition']['value'], FormFields::forBuilder($stored)[4]['max']], ['sales', '7']);

// ---- which parts are shown
$fields = FormFields::normalize([
    ['type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => "sales|Sales\nsupport|Support\nother|Other"],
    ['type' => 'text', 'name' => 'company', 'label' => 'Company', 'required' => true, 'condition' => ['field' => 'topic', 'op' => 'is', 'value' => 'sales']],
    ['type' => 'checkboxes', 'name' => 'products', 'label' => 'Products', 'options' => "app\nweb", 'condition' => ['field' => 'topic', 'op' => 'not', 'value' => 'other']],
    ['type' => 'text', 'name' => 'app_version', 'label' => 'App version', 'required' => true, 'condition' => ['field' => 'products', 'op' => 'is', 'value' => 'app']],
    ['type' => 'checkbox', 'name' => 'callback', 'label' => 'Call me back'],
    ['type' => 'tel', 'name' => 'phone', 'label' => 'Phone', 'required' => true, 'condition' => ['field' => 'callback', 'op' => 'filled']],
    ['type' => 'step', 'name' => 'step-1', 'label' => 'Rating', 'condition' => ['field' => 'topic', 'op' => 'filled']],
    ['type' => 'rating', 'name' => 'stars', 'label' => 'Stars', 'required' => true, 'max' => '5'],
    ['type' => 'textarea', 'name' => 'why', 'label' => 'Why so low?', 'required' => true, 'condition' => ['field' => 'stars', 'op' => 'is', 'value' => '1']],
    ['type' => 'email', 'name' => 'email', 'label' => 'Email', 'condition' => ['field' => 'topic', 'op' => 'empty']],
]);
$shown = FormConditions::visible($fields, ['topic' => 'sales', 'products' => ['app'], 'callback' => '', 'stars' => '1']);
check('shown for the answer: is, is not, among ticked, ticked, answered, not answered', [$shown['company'], $shown['products'], $shown['app_version'], $shown['phone'], $shown['step-1'], $shown['stars'], $shown['why'], $shown['email']], [true, true, true, false, true, true, true, false]);
$shown = FormConditions::visible($fields, ['topic' => 'other', 'products' => ['app'], 'callback' => '1', 'stars' => '1']);
check('a field hidden hides what depends on it, whatever was sent for it', [$shown['company'], $shown['products'], $shown['app_version'], $shown['phone']], [false, false, false, true]);
$shown = FormConditions::visible($fields, ['stars' => '1']);
check('a step that is not shown hides everything in it, up to the end of the form or the next step', [$shown['step-1'], $shown['stars'], $shown['why'], $shown['email']], [false, false, false, false]);
check('a form without conditions shows everything', array_values(array_unique(FormConditions::visible(FormFields::normalize([['name' => 'a'], ['name' => 'b', 'type' => 'heading']]), []))), [true]);

// ---- what is hidden is neither checked nor kept
$p = new FormProcessor(fn(string $key, string $fallback): string => $fallback);
$r = $p->collect($fields, ['topic' => 'support', 'company' => 'ACME', 'products' => ['web'], 'app_version' => '', 'callback' => '', 'phone' => '', 'stars' => '4', 'why' => 'left over', 'email' => 'x@y.test']);
check('a required field that is hidden is not asked for', $r['errors'], []);
check('and nothing sent for a hidden field is kept', array_keys($r['values']), ['topic', 'products', 'callback', 'stars']);
$r = $p->collect($fields, ['topic' => 'sales', 'products' => ['app'], 'callback' => 'on', 'stars' => '1']);
check('a required field that is shown is asked for', array_keys($r['errors']), ['company', 'app_version', 'phone', 'why']);
$r = $p->collect($fields, ['topic' => 'support', 'products' => [], 'stars' => '9']);
check('a rating out of its stars is refused', [$r['errors']['stars'] ?? '', $r['values']['stars']], ['Please select a valid option.', '']);
$r = $p->collect($fields, ['topic' => 'support', 'stars' => '2.5']);
check('a rating is a whole number', isset($r['errors']['stars']), true);
$r = $p->collect($fields, ['topic' => 'support', 'stars' => ['3']]);
check('a list sent for a rating is not an answer', isset($r['errors']['stars']), true);
$r = $p->collect($fields, ['topic' => 'support', 'stars' => '']);
check('a required rating left empty is asked for', $r['errors']['stars'] ?? '', 'This field is required.');
check('what was sent, before checking, to decide what is shown', FormProcessor::sent($fields, ['topic' => ' sales ', 'products' => ['app', ['x']], 'callback' => 'on', 'stars' => ['1']]), ['topic' => 'sales', 'company' => '', 'products' => ['app'], 'app_version' => '', 'callback' => '1', 'phone' => '', 'stars' => '', 'why' => '', 'email' => '']);

$form = new ContentItem('forms', 'feedback', 'en', ['title' => 'Feedback'], '', '', '', 0);
$values = $p->collect($fields, ['topic' => 'support', 'products' => ['web'], 'stars' => '4', 'why' => 'left over'])['values'];
$body = $p->body($form, $values, $fields, 'https://s.test/feedback');
check('the email lists only what was shown, a rating as so many of so many', [str_contains($body, 'Stars: 4 / 5'), str_contains($body, 'Company'), str_contains($body, 'Why so low'), str_contains($body, 'Products: web')], [true, false, false, true]);
check('an answer as one line', [FormProcessor::answer(['type' => 'rating', 'max' => '7'], '3'), FormProcessor::answer(['type' => 'rating'], ''), FormProcessor::answer(['type' => 'checkboxes'], ['a', 'b'])], ['3 / 7', '', 'a, b']);

// ---- archiving submissions
$dir = sys_get_temp_dir() . '/form-logic' . getmypid();
mkdir("$dir/content/forms", 0775, true);
file_put_contents("$dir/content/forms/feedback.md", "---\ntitle: Feedback\nfields:\n  - {name: name, label: Name, type: text}\n  - {name: stars, label: Stars, type: rating, max: 5}\n---\n");
$env = new Environment([]);
$env->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($env), ['title' => 'S', 'languages' => ['default' => 'en', 'available' => ['en']]]);
$subs = new FormSubmissionRepository("$dir/content", "$dir/storage/form-uploads");
$ids = [];
foreach (['Ada', 'Bob', 'Cy'] as $n => $name) {
    $when = time() - (3 - $n) * 3600;
    $ids[$name] = $subs->store('feedback', ['form' => 'feedback', 'lang' => 'en', 'submitted_at' => date('c', $when), 'fields' => ['name' => $name, 'stars' => (string)($n + 3)]], date('Ymd-His', $when) . '-' . sprintf('%08x', $n));
}
mkdir("$dir/storage/form-uploads/feedback/{$ids['Ada']}", 0775, true);
file_put_contents("$dir/storage/form-uploads/feedback/{$ids['Ada']}/cv.pdf", '%PDF');
$admin = new FormsAdmin($content, $subs);
$item = $admin->versions('feedback')['en'];

check('a rating is shown in the list as so many of so many', array_column($admin->page($item, [])['rows'][0]['fields'], 'value'), ['Cy', '5 / 5']);
$r = $admin->changeSubmissions('feedback', ['submission_action' => 'archive', 'id' => $ids['Ada']]);
check('one is archived by id', [$r['action'], $r['count'], $subs->archivedCount('feedback'), count($subs->all('feedback'))], ['archive', 1, 1, 2]);
check('it is moved as it is, and its files stay where they were', [is_file("$dir/content/forms-submissions/feedback/archive/{$ids['Ada']}.json"), is_file("$dir/storage/form-uploads/feedback/{$ids['Ada']}/cv.pdf")], [true, true]);
check('it is still found by its id, marked archived', [$subs->find('feedback', $ids['Ada'])['fields']['name'] ?? null, $subs->find('feedback', $ids['Ada'])['archived'] ?? null, $subs->find('feedback', $ids['Bob'])['archived'] ?? null], ['Ada', true, false]);
check('archiving it again does nothing', $admin->changeSubmissions('feedback', ['submission_action' => 'archive', 'id' => $ids['Ada']])['count'], 0);
check('the counts are of the new ones', [$subs->stats('feedback')['total'], $admin->overview([], 'updated', ['en'], 'en', true)['rows'][0]['submissions'], $admin->overview([], 'updated', ['en'], 'en', true)['rows'][0]['archived']], [2, 2, 1]);
$r = $admin->changeSubmissions('feedback', ['submission_action' => 'bulk_archive', 'selected_ids' => [$ids['Bob'], 'nope', '../x']]);
check('the ticked ones are archived, the rest ignored', [$r['count'], $subs->archivedCount('feedback')], [1, 2]);

$inbox = $admin->page($item, []);
$archive = $admin->page($item, ['view' => 'archived']);
check('the inbox lists the new ones, with both counts', [array_column($inbox['rows'], 'title'), $inbox['archived'], $inbox['inbox_total'], $inbox['archived_total']], [['Cy'], false, 1, 2]);
check('the archive lists the archived ones, newest first, with both counts', [array_column($archive['rows'], 'title'), $archive['archived'], $archive['inbox_total'], $archive['archived_total'], $archive['rows'][0]['archived']], [['Bob', 'Ada'], true, 1, 2, true]);
check('the archive can be searched like the inbox', array_column($admin->page($item, ['view' => 'archived', 'q' => 'ada'])['rows'], 'title'), ['Ada']);
check('the editor\'s tab lists only the new ones', array_column($admin->recent('feedback'), 'title'), ['Cy']);
$x = $admin->export($item, 'S', true);
check('the archive is exported on its own', [$x['count'], $x['filename'], $admin->export($item, 'S')['count']], [2, 's-feedback-archived-submissions.csv', 1]);

$r = $admin->changeSubmissions('feedback', ['submission_action' => 'restore', 'id' => $ids['Bob']]);
check('one is restored', [$r['action'], $r['count'], $subs->archivedCount('feedback'), array_column($subs->all('feedback'), 'id')], ['restore', 1, 1, [$ids['Cy'], $ids['Bob']]]);
$r = $admin->changeSubmissions('feedback', ['submission_action' => 'delete', 'id' => $ids['Ada']]);
check('an archived one can be deleted, its files with it', [$r['action'], $r['count'], $subs->archivedCount('feedback'), is_dir("$dir/storage/form-uploads/feedback/{$ids['Ada']}")], ['delete', 1, 0, false]);
check('nothing is done without a known action', $admin->changeSubmissions('feedback', ['submission_action' => 'wipe', 'id' => $ids['Cy']]), ['action' => '', 'count' => 0, 'ids' => [], 'files' => 0]);
check('restoring what is not archived does nothing', $admin->changeSubmissions('feedback', ['submission_action' => 'bulk_restore', 'selected_ids' => [$ids['Cy']]])['count'], 0);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
