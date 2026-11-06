<?php
/*
 * Posts written on a schedule, without the network (a fake OpenAI) and with fake entries: schedules (saved, checked, duplicated,
 * switched off, deleted), which slots are due (days, time in the site's zone, prepare ahead, the missed window, dates, midnight) and
 * that a slot runs once, the final status for each choice, the answer's schema and its checks, and a run: the request (instructions,
 * facts, what to avoid, the schedule's lines, the terms to choose, the titles not to repeat), one more try when an answer falls
 * short, the post saved as a draft with its byline and terms, its picture queued with the writer's brief, and its status given at
 * once (no picture, or a picture not required) or when the picture is set; failures (no credit, a model no longer offered, two
 * unusable answers, a deleted schedule) and Run now.
 *   php tests/unit/ai-writer.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{AiHttp, AiPictures, AiSchedules, AiSettings, AiUsage, AiWriter, JobRunner, MediaLibrary, OpenAiProvider, ScheduledPublishing, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$dir = sys_get_temp_dir() . '/aiwriter' . getmypid();
foreach (['storage/db', 'content', 'public/uploads/media'] as $d) { mkdir("$dir/$d", 0775, true); }
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$zone = new DateTimeZone('Europe/Athens');
// Wednesday 2026-11-04, 07:40 in Athens.
$now = (new DateTimeImmutable('2026-11-04 07:40', $zone))->getTimestamp();
$clock = static function () use (&$now): int { return $now; };
$schedules = new AiSchedules($db, $clock);
$types = ['posts', 'pages'];
$langs = ['el', 'en'];

// ---- schedules
$bad = $schedules->save(0, ['name' => ' ', 'days' => [], 'time' => '25:00', 'type' => 'forms', 'lang' => 'de', 'content' => ''], $types, $langs);
check('a schedule needs a name, days, a time, a type, a language and content', [$bad['ok'], count($bad['errors'])], [false, 6]);
$form = ['name' => 'Tip of the day', 'days' => ['1', '2', '3', '4', '5', '9'], 'time' => '8:00', 'type' => 'posts', 'lang' => 'en', 'content' => 'One practical tip about opening hours.',
    'text_avoid' => "- prices\n", 'image' => 'An everyday object.', 'picture_avoid' => 'people', 'choose' => ['categories'], 'terms' => ['tags' => ['tips', '']], 'status' => 'at_time', 'picture_required' => '1', 'prepare' => '30'];
$saved = $schedules->save(0, $form, $types, $langs);
check('a good schedule is saved', [$saved['ok'], $saved['id'] > 0], [true, true]);
$tip = $schedules->get($saved['id']);
check('its settings are checked', [$tip['settings']['days'], $tip['settings']['time'], $tip['settings']['text_avoid'], $tip['settings']['terms'], $tip['settings']['picture_required'], $tip['settings']['no_picture']], [[1, 2, 3, 4, 5], '08:00', ['prices'], ['tags' => ['tips']], true, false]);
check('a box left unticked (sent as 0) is off', AiSchedules::normalize(['picture_required' => '0'])['picture_required'], false);
check('an end before the start is refused', $schedules->save(0, $form + ['start_date' => '2026-12-01', 'end_date' => '2026-11-01'], $types, $langs)['errors'], ['The end date comes before the start date.']);
$copy = $schedules->duplicate($tip['id']);
check('a copy is switched off and named so', [$schedules->get($copy)['enabled'], $schedules->get($copy)['name']], [false, 'Tip of the day (copy)']);
check('a schedule can be deleted', [$schedules->delete($copy), $schedules->get($copy)], [true, null]);

// ---- due slots
check('Wednesday 07:40, prepare 30 min before 08:00: due', array_column($schedules->due($zone, 60), 'slot'), ['2026-11-04 08:00']);
$now -= 15 * 60;
check('07:25: not yet', $schedules->due($zone, 60), []);
$now += 15 * 60;
check('a slot is taken once', [$schedules->claim($tip['id'], '2026-11-04 08:00'), $schedules->claim($tip['id'], '2026-11-04 08:00')], [true, false]);
check('a slot that ran is no longer due', $schedules->due($zone, 60), []);
check('the next run is tomorrow at 08:00', $schedules->next($tip, $zone)?->format('Y-m-d H:i'), '2026-11-05 08:00');
$now = (new DateTimeImmutable('2026-11-05 09:30', $zone))->getTimestamp();
check('missed by 90 minutes with a window of 60: skipped', $schedules->due($zone, 60), []);
check('with a window of 120: still runs', array_column($schedules->due($zone, 120), 'slot'), ['2026-11-05 08:00']);
$now = (new DateTimeImmutable('2026-11-07 08:00', $zone))->getTimestamp();
check('Saturday is not one of its days', $schedules->due($zone, 60), []);
$late = $schedules->save(0, ['name' => 'Late', 'days' => ['7', '1'], 'time' => '00:10', 'type' => 'posts', 'lang' => 'en', 'content' => 'x', 'prepare' => '30', 'start_date' => '2026-11-08'], $types, $langs)['id'];
$now = (new DateTimeImmutable('2026-11-07 23:50', $zone))->getTimestamp();
check('a time just after midnight is prepared the evening before', array_column(array_filter($schedules->due($zone, 60), fn($d) => $d['schedule']['id'] === $late), 'slot'), ['2026-11-08 00:10']);
$schedules->save($late, ['name' => 'Late', 'days' => ['7'], 'time' => '00:10', 'type' => 'posts', 'lang' => 'en', 'content' => 'x', 'start_date' => '2026-11-09'], $types, $langs);
check('not before its start date', array_filter($schedules->due($zone, 60), fn($d) => $d['schedule']['id'] === $late), []);
$schedules->setEnabled($late, false);
check('a schedule switched off has no next run', $schedules->next($schedules->get($late), $zone), null);

// ---- status, schema, checks
$slot = (new DateTimeImmutable('2026-11-04 08:00', $zone))->getTimestamp();
check('at its time, before the slot: scheduled for it', AiWriter::finalStatus('at_time', $slot, $slot - 1800, $zone), ['status' => ScheduledPublishing::STATUS, 'publish_at' => '2026-11-04 08:00']);
check('at its time, the slot has come: published', AiWriter::finalStatus('at_time', $slot, $slot + 5, $zone)['status'], 'published');
check('when ready, and draft', [AiWriter::finalStatus('when_ready', $slot, 0, $zone)['status'], AiWriter::finalStatus('draft', $slot, 0, $zone)['status']], ['published', 'draft']);
$schema = AiWriter::schema(['categories' => ['tips', 'news']]);
check('the schema: every field required, nothing else allowed, terms from the list', [$schema['additionalProperties'], in_array('image_brief', $schema['required'], true), $schema['properties']['terms']['properties']['categories']['items']['enum']], [false, true, ['tips', 'news']]);
check('no terms field when there is nothing to choose', isset(AiWriter::schema([])['properties']['terms']), false);
$good = ['title' => 'Clear hours', 'body' => str_repeat('Clear hours help. ', 30), 'terms' => ['categories' => ['tips']]];
check('a good answer has no problems', AiWriter::problems($good, ['categories' => ['tips', 'news']]), []);
check('problems: empty title, short body, HTML, a term not on the list', AiWriter::problems(['title' => '', 'body' => '<script>x</script>', 'terms' => ['categories' => ['made-up']]], ['categories' => ['tips']]),
    ['the title is empty', 'the body is too short (at least 300 characters)', 'the body has HTML; write Markdown only', 'categories must come from the list']);

// ---- a run, with a fake OpenAI and fake entries
$meta = new SystemMetaRepository($db);
$settings = new AiSettings($meta);
$settings->saveSettings(['openai_key' => 'sk-test-writer-1234567890']);
$settings->saveWritingInstructions(['writing_instructions' => 'Friendly, short paragraphs.', 'text_avoid' => "invented prices\nemojis", 'site_facts' => 'A bakery in Volos.', 'byline' => 'The Bakery']);
$settings->savePictureInstructions(['house_style' => 'Flat, warm.', 'picture_avoid' => 'text', 'aspect' => '16:9', 'quality' => 'low', 'format' => 'png', 'variants' => '1']);
$settings->saveSettings(['prices' => [['model' => 'gpt-5-mini', 'text_input' => '0.25', 'cached_input' => '0.025', 'text_output' => '2'], ['model' => 'gpt-image-2', 'text_input' => '5', 'image_output' => '30']]]);
$runner = new JobRunner($db, $clock);
$usage = new AiUsage($db, $clock);
$media = new MediaLibrary("$dir/content", "$dir/public/uploads");
$media->ensureDirectories();
$calls = [];
$replies = [];
$transport = function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$replies): array {
    $calls[] = ['url' => $url, 'body' => $body];
    $reply = array_shift($replies) ?? ['status' => 500, 'body' => '{"error":{"message":"no reply set"}}'];
    return ['status' => $reply['status'], 'headers' => [], 'body' => $reply['body'], 'error' => '', 'timed_out' => false];
};
$provider = fn(string $key): OpenAiProvider => new OpenAiProvider($key, new AiHttp([OpenAiProvider::HOST], [$key], $transport));
$writeReply = fn(array $answer): array => ['status' => 200, 'body' => json_encode(['status' => 'completed', 'output' => [['type' => 'reasoning'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer, JSON_UNESCAPED_UNICODE)]]]],
    'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 600, 'output_tokens_details' => ['reasoning_tokens' => 200]]])];
$png = (static function (): string { $im = imagecreatetruecolor(300, 200); ob_start(); imagepng($im); return (string)ob_get_clean(); })();
$pictureReply = ['status' => 200, 'body' => json_encode(['data' => [['b64_json' => base64_encode($png)]], 'usage' => ['input_tokens' => 60, 'input_tokens_details' => ['text_tokens' => 60, 'image_tokens' => 0], 'output_tokens' => 158, 'output_tokens_details' => ['image_tokens' => 158, 'text_tokens' => 0]]])];

$entries = [];
$statusCalls = [];
$saveCalls = [];
$saveEntry = function (string $type, array $post, string $actor) use (&$entries, &$saveCalls): array {
    $saveCalls[] = ['type' => $type, 'post' => $post, 'actor' => $actor];
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($post['title'])), '-');
    $entries["$type/$slug/{$post['lang']}"] = ['title' => $post['title'], 'excerpt' => $post['excerpt'], 'body' => $post['body'], 'main_image' => '', 'status' => $post['status']];
    return ['type' => $type, 'slug' => $slug, 'lang' => $post['lang'], 'status' => $post['status']];
};
$setStatus = function (string $type, string $slug, string $lang, string $status, ?string $publishAt, string $actor) use (&$entries, &$statusCalls): string {
    $statusCalls[] = [$type, $slug, $lang, $status, $publishAt, $actor];
    $entries["$type/$slug/$lang"]['status'] = $status;
    return 'set';
};
$pictures = new AiPictures($runner, $settings, $usage, $media, $db, "$dir/storage", "$dir/public", $provider, $clock,
    entries: function (string $type, string $slug, string $lang) use (&$entries): ?array { return $entries["$type/$slug/$lang"] ?? null; },
    setMainImage: function (string $type, string $slug, string $lang, string $path) use (&$entries): string { $entries["$type/$slug/$lang"]['main_image'] = $path; return 'set'; },
    finalize: $setStatus);
$pictures->register();
$writer = new AiWriter($runner, $settings, $usage, $schedules, $pictures, $provider, $saveEntry,
    fn(string $type, string $lang): array => $type === 'posts' ? ['categories' => [['id' => 'tips', 'label' => 'Tips'], ['id' => 'news', 'label' => 'News']], 'tags' => [['id' => 'tips', 'label' => 'tips']]] : [],
    $setStatus, fn(string $code): string => ['en' => 'English', 'el' => 'Greek'][$code] ?? $code, $zone, $clock);
$writer->register();
$soon = static fn(): float => microtime(true) + 10;

$now = (new DateTimeImmutable('2026-11-06 07:35', $zone))->getTimestamp();
check('tick queues the slot due (Friday 08:00)', $writer->tick(), 1);
check('and not twice', $writer->tick(), 0);
$schedules->recordPost($tip['id'], '2026-11-04 08:00', 'posts/old/en', 'Write your hours in one place');
$answer = ['title' => 'Show holiday hours early', 'excerpt' => 'Tell people before they come.', 'body' => str_repeat('Post your holiday hours a week before. ', 12), 'seo_title' => 'Holiday hours', 'seo_description' => 'How to show holiday hours.',
    'image_brief' => 'A paper calendar with one day circled, on a counter.', 'terms' => ['categories' => ['tips']]];
$replies = [$writeReply(['title' => 'Too short', 'body' => 'Short.', 'excerpt' => '', 'seo_title' => '', 'seo_description' => '', 'image_brief' => '', 'terms' => ['categories' => []]]), $writeReply($answer)];
$calls = [];
check('the post job runs (the picture waits its turn)', $runner->work($soon(), [AiWriter::KIND]), 1);
$first = json_decode((string)$calls[0]['body'], true);
$dev = $first['input'][0]['content'];
$user = $first['input'][1]['content'];
check('sent to the Responses API with a strict schema and low effort', [$calls[0]['url'], $first['model'], $first['text']['format']['strict'], $first['reasoning']['effort']], ['https://api.openai.com/v1/responses', 'gpt-5-mini', true, 'low']);
check('the developer message: instructions, facts, what never to do (site and schedule), the language', [str_starts_with($dev, 'Friendly, short paragraphs.'), str_contains($dev, 'A bakery in Volos.'), str_contains($dev, "- invented prices\n- emojis\n- prices"), str_contains($dev, 'Write in English (en)')], [true, true, true, true]);
check('the user message: the schedule, the picture guide, terms to choose, titles not to repeat, the date', [str_contains($user, 'One practical tip about opening hours.'), str_contains($user, 'Picture guide: An everyday object.'), str_contains($user, 'tips (Tips), news (News)'), str_contains($user, '- Write your hours in one place'), str_contains($user, 'Friday 6 November 2026')], [true, true, true, true, true]);
$second = json_decode((string)$calls[1]['body'], true);
check('a short answer is sent back once with its problems', str_contains($second['input'][1]['content'], 'Your previous answer could not be used: the body is too short'), true);
$post = $saveCalls[0]['post'];
check('saved as a draft with byline, excerpt, SEO texts', [$post['status'], $post['author'], $post['excerpt'], $post['seo_title'], $post['visible'], $saveCalls[0]['actor']], ['draft', 'The Bakery', 'Tell people before they come.', 'Holiday hours', '1', 'AI publishing: Tip of the day']);
check('terms: the schedule\'s fixed ones and the chosen ones', $post['taxonomy_terms'], ['tags' => ['tips'], 'categories' => ['tips']]);
check('marked as written by the schedule', str_contains($post['frontmatter'], 'generated_by: \'AI publishing: Tip of the day\'') && str_contains($post['frontmatter'], 'ai_schedule: ' . $tip['id']), true);
check('no status yet: it waits for its picture', $statusCalls, []);
$postJob = $runner->list(AiWriter::KIND)[0];
check('the job says so', [$postJob['status'], $postJob['result']['status'], $postJob['result']['entry']], [JobRunner::DONE, 'draft until the picture is set', 'posts/show-holiday-hours-early/en']);
check('both writing calls are counted for the entry', [count($usage->rows(0, $now + 1)), $usage->forEntry('posts/show-holiday-hours-early/en') > 0], [2, true]);
check('the title is remembered for the next runs', $schedules->recentTitles($tip['id'])[0], 'Show holiday hours early');

$replies = [$pictureReply];
$calls = [];
$runner->work($soon(), [AiPictures::KIND]);
$pic = json_decode((string)$calls[0]['body'], true);
check('the picture uses the writer\'s brief and the schedule\'s avoid lines', [str_contains($pic['prompt'], 'Subject: A paper calendar with one day circled'), str_contains($pic['prompt'], 'Do not show: text; people;')], [true, true]);
check('once the picture is set, the post is scheduled for its slot', [$entries['posts/show-holiday-hours-early/en']['main_image'] !== '', $statusCalls[0][3], $statusCalls[0][4]], [true, ScheduledPublishing::STATUS, '2026-11-06 08:00']);

// No picture: the status at once.
$noPic = $schedules->save(0, ['name' => 'Quick note', 'days' => ['5'], 'time' => '07:00', 'type' => 'posts', 'lang' => 'el', 'content' => 'A short note.', 'no_picture' => '1', 'status' => 'when_ready'], $types, $langs)['id'];
$statusCalls = [];
$r = $writer->runNow($noPic, 'chris');
check('Run now queues a post outside the timetable', [$r['ok'], $r['job'] > 0], [true, true]);
$replies = [$writeReply(['title' => 'Νέο ωράριο', 'excerpt' => 'x', 'body' => str_repeat('Κείμενο. ', 50), 'seo_title' => '', 'seo_description' => '', 'image_brief' => ''])];
$calls = [];
$runner->work($soon(), [AiWriter::KIND]);
check('without terms to choose, no terms in the schema', isset(json_decode((string)$calls[0]['body'], true)['text']['format']['schema']['properties']['terms']), false);
check('no picture: published as soon as it is written', [count($statusCalls), $statusCalls[0][3] ?? ''], [1, 'published']);
check('no picture job for it', count($runner->list(AiPictures::KIND, [JobRunner::QUEUED])), 0);

// Picture not required: the status at once, the picture later.
$schedules->save($noPic, ['name' => 'Quick note', 'days' => ['5'], 'time' => '07:00', 'type' => 'posts', 'lang' => 'el', 'content' => 'A short note.', 'picture_required' => '0', 'status' => 'draft'], $types, $langs);
$statusCalls = [];
$writer->runNow($noPic, 'chris');
$replies = [$writeReply(['title' => 'Another note', 'excerpt' => 'x', 'body' => str_repeat('Words. ', 60), 'seo_title' => '', 'seo_description' => '', 'image_brief' => 'A cup.'])];
$runner->work($soon(), [AiWriter::KIND]);
check('draft mode: no status change, and the picture queued anyway', [$statusCalls, count($runner->list(AiPictures::KIND, [JobRunner::QUEUED]))], [[], 1]);
$runner->cancel((int)$runner->list(AiPictures::KIND, [JobRunner::QUEUED])[0]['id']);

// ---- failures
$writer->runNow($noPic, 'chris');
$replies = [$writeReply(['title' => '', 'body' => 'x', 'excerpt' => '', 'seo_title' => '', 'seo_description' => '', 'image_brief' => '']), $writeReply(['title' => '', 'body' => 'x', 'excerpt' => '', 'seo_title' => '', 'seo_description' => '', 'image_brief' => ''])];
$saveCalls = [];
$runner->work($soon(), [AiWriter::KIND]);
$job = $runner->list(AiWriter::KIND)[0];
check('two unusable answers: failed, nothing saved', [$job['status'], str_starts_with($job['error'], 'The answer could not be used twice'), $saveCalls], [JobRunner::FAILED, true, []]);

$writer->runNow($noPic, 'chris');
$replies = [['status' => 400, 'body' => '{"error":{"message":"The model `gpt-5-mini` does not exist","code":"model_not_found","param":"model"}}']];
$runner->work($soon(), [AiWriter::KIND]);
check('a model no longer offered', str_contains($runner->list(AiWriter::KIND)[0]['error'], 'no longer offers the model "gpt-5-mini"'), true);

$writer->runNow($noPic, 'chris');
$replies = [['status' => 429, 'body' => '{"error":{"message":"quota","code":"insufficient_quota"}}']];
$runner->work($soon(), [AiWriter::KIND]);
check('no credit: failed and everything paused', [$runner->list(AiWriter::KIND)[0]['status'], $settings->all()['paused']], [JobRunner::FAILED, true]);
check('while paused, tick queues nothing and Run now says why', [$writer->tick(), str_starts_with($writer->runNow($noPic, 'chris')['error'], 'AI publishing is paused')], [0, true]);
$settings->setPaused(false);

$writer->runNow($noPic, 'chris');
$schedules->delete($noPic);
$runner->work($soon(), [AiWriter::KIND]);
check('a schedule deleted before its run', $runner->list(AiWriter::KIND)[0]['error'], 'The schedule was deleted.');
check('Run now for a schedule that is not there', $writer->runNow(99999, 'chris')['error'], 'There is no such schedule.');

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
