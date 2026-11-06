<?php
/*
 * AI publishing, phase 1, without the network (a fake OpenAI behind AiHttp): the settings (defaults, the key never in view(), a
 * blank key kept, references and avoid lines checked), the model lists, the usage counts and the estimated cost (prices missing,
 * cached tokens, pictures), the prompt and alt text, and the picture queue: a request, the job (prompt sent, references through
 * images/edits, variants cut to the ratio, usage recorded), a pick into the media library (tag, alt text, ai_pictures, the same
 * picture once), and failures (no credit pauses everything, a model no longer offered, a key refused, a rate limit retried later,
 * the monthly caps, no key).
 *   php tests/unit/ai-pictures.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';

use GnozioCMS\{AiHttp, AiPictures, AiSettings, AiUsage, JobRunner, MediaLibrary, OpenAiProvider, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n";
}

$dir = sys_get_temp_dir() . '/aipics' . getmypid();
foreach (['storage/db', 'content', 'public/uploads/media'] as $d) { mkdir("$dir/$d", 0775, true); }
register_shutdown_function(static function () use ($dir): void { @exec('rm -rf ' . escapeshellarg($dir)); });
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$settings = new AiSettings($meta);

// ---- settings
$s = $settings->all();
check('defaults', [$s['text_model'], $s['image_model'], $s['aspect'], $s['quality'], $s['format'], $s['variants'], $s['cap_action']], ['gpt-5-mini', 'gpt-image-2', '16:9', 'medium', 'webp', 2, 'pause']);
check('a house style and an avoid list to start from', [$s['house_style'] !== '', in_array('logos and watermarks', $s['picture_avoid'], true)], [true, true]);
check('the default price table knows gpt-5-mini, not the picture model', [$s['prices']['gpt-5-mini']['text_input'], $s['prices']['gpt-image-2']['image_output']], [0.25, null]);
$settings->saveSettings(['openai_key' => ' sk-proj-abcdefghijklmnop1234 ', 'image_model' => 'gpt-image-2', 'text_model' => 'bad model!', 'monthly_cost_cap' => '5,50', 'monthly_picture_cap' => 'x', 'cap_action' => 'pause',
    'prices' => [['model' => 'gpt-image-2', 'text_input' => '5', 'image_input' => '8', 'image_output' => '30', 'text_output' => ''], ['model' => '', 'text_input' => '1']]]);
$view = $settings->view();
check('the view never holds the key', [array_key_exists('openai_key', $view), $view['has_key'], $view['key_hint']], [false, true, '…1234']);
check('the key is kept trimmed', $settings->key(), 'sk-proj-abcdefghijklmnop1234');
check('a bad model name falls back to the default; a cap with a comma is read', [$view['text_model'], $view['monthly_cost_cap'], $view['monthly_picture_cap']], ['gpt-5-mini', 5.5, null]);
check('prices saved per model, rows without a model dropped', [array_keys($view['prices']), $view['prices']['gpt-image-2']['image_output'], $view['prices']['gpt-image-2']['text_output']], [['gpt-image-2'], 30.0, null]);
$settings->saveSettings(['openai_key' => '', 'prices' => [['model' => 'gpt-image-2', 'text_input' => '5', 'image_input' => '8', 'image_output' => '30']]]);
check('a blank key keeps the stored one', $settings->key(), 'sk-proj-abcdefghijklmnop1234');
$settings->savePictureInstructions(['house_style' => " Watercolour, teal and sand. \r\n", 'picture_avoid' => "- people\n\n* cars\npeople", 'references' => "/uploads/media/a.png\n/uploads/../etc/passwd\nhttps://x.example/a.png\n/uploads/media/b.webp,/uploads/media/c.jpg,/uploads/media/d.png,/uploads/media/e.png",
    'aspect' => '4:5', 'quality' => 'ultra', 'format' => 'png', 'variants' => '9']);
$s = $settings->all();
check('house style trimmed', $s['house_style'], 'Watercolour, teal and sand.');
check('avoid lines: bullets dropped, empty and repeated lines left out', $s['picture_avoid'], ['people', 'cars']);
check('references: library pictures only, at most four', $s['references'], ['/uploads/media/a.png', '/uploads/media/b.webp', '/uploads/media/c.jpg', '/uploads/media/d.png']);
check('choices checked', [$s['aspect'], $s['quality'], $s['format'], $s['variants']], ['4:5', 'medium', 'png', 4]);
check('sizes for the ratios', [AiSettings::size('16:9'), AiSettings::size('1:1'), AiSettings::size('4:5')], ['1536x1024', '1024x1024', '1024x1536']);

// ---- prompt and alt
check('the prompt: house style, subject, what not to show (fixed line last, no repeats)', AiPictures::prompt('Flat, teal.', 'A shop sign.', ['people', 'people', ''], false),
    "Flat, teal.\nSubject: A shop sign.\nDo not show: people; text, letters, numbers or logos.");
check('with references, a line about their style comes first', str_starts_with(AiPictures::prompt('Flat.', 'A sign.', [], true), 'Follow the style of the reference pictures'), true);
check('alt text: the first sentence, capital first, a full stop', AiPictures::altFrom('a wooden sign with a clock hanging from it. Morning light behind.'), 'A wooden sign with a clock hanging from it.');
check('alt text of nothing', AiPictures::altFrom('  '), '');

// ---- usage
$picUsage = ['input_tokens' => 69, 'input_tokens_details' => ['image_tokens' => 0, 'text_tokens' => 69], 'output_tokens' => 158, 'output_tokens_details' => ['image_tokens' => 158, 'text_tokens' => 0], 'total_tokens' => 227];
check('picture counts', AiUsage::counts($picUsage, 'picture'), ['text_input' => 69, 'cached_input' => 0, 'cache_write' => 0, 'image_input' => 0, 'text_output' => 0, 'reasoning' => 0, 'image_output' => 158]);
$textUsage = ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 400, 'cache_write_tokens' => 10], 'output_tokens' => 575, 'output_tokens_details' => ['reasoning_tokens' => 256]];
check('writing counts', AiUsage::counts($textUsage, 'writing'), ['text_input' => 1000, 'cached_input' => 400, 'cache_write' => 10, 'image_input' => 0, 'text_output' => 575, 'reasoning' => 256, 'image_output' => 0]);
check('writing cost: cached input at its price, reasoning inside output', AiUsage::cost(AiUsage::counts($textUsage, 'writing'), ['text_input' => 0.25, 'cached_input' => 0.025, 'text_output' => 2.0]), round(600 / 1e6 * 0.25 + 400 / 1e6 * 0.025 + 575 / 1e6 * 2.0, 6));
check('cached tokens without their own price cost what input costs', AiUsage::cost(['text_input' => 1000, 'cached_input' => 1000, 'cache_write' => 0, 'image_input' => 0, 'text_output' => 0, 'reasoning' => 0, 'image_output' => 0], ['text_input' => 1.0]), 0.001);
check('picture cost', AiUsage::cost(AiUsage::counts($picUsage, 'picture'), ['text_input' => 5.0, 'image_output' => 30.0]), round(69 / 1e6 * 5 + 158 / 1e6 * 30, 6));
check('a price the counts need is missing: unknown', AiUsage::cost(AiUsage::counts($picUsage, 'picture'), ['text_input' => 5.0]), null);
check('no row for the model: unknown', AiUsage::cost(AiUsage::counts($picUsage, 'picture'), null), null);
check('web searches need their price', [AiUsage::cost(AiUsage::counts([], 'writing'), ['search_call' => 0.01], 3), AiUsage::cost(AiUsage::counts([], 'writing'), [], 1)], [0.03, null]);

// ---- the provider, behind a fake OpenAI
$png = (static function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 140, 140));
    ob_start(); imagepng($im); return (string)ob_get_clean();
})(300, 200);
$calls = [];
$replies = [];
$transport = function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$replies): array {
    $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
    $reply = array_shift($replies) ?? ['status' => 500, 'body' => '{"error":{"message":"no reply set"}}'];
    return ['status' => $reply['status'], 'headers' => $reply['headers'] ?? [], 'body' => $reply['body'], 'error' => '', 'timed_out' => false];
};
$makeProvider = fn(string $key): OpenAiProvider => new OpenAiProvider($key, new AiHttp([OpenAiProvider::HOST], [$key], $transport));

$replies[] = ['status' => 200, 'body' => json_encode(['data' => array_map(fn($id) => ['id' => $id], ['gpt-image-2', 'gpt-image-2-2026-04-21', 'gpt-5-mini', 'gpt-5.5-pro', 'dall-e-3', 'text-embedding-3-large', 'gpt-4o-realtime-preview', 'o3', 'gpt-image-1.5', 'whisper-1'])])];
$models = $makeProvider('sk-test-1234567890')->models();
check('model lists: pictures and writing, dated copies and other kinds left out', [$models['pictures'], $models['writing']], [['dall-e-3', 'gpt-image-1.5', 'gpt-image-2'], ['gpt-5-mini', 'o3']]);

// ---- the queue
$now = time();
$clock = static function () use (&$now): int { return $now; };
$runner = new JobRunner($db, $clock);
$media = new MediaLibrary("$dir/content", "$dir/public/uploads");
$media->ensureDirectories();
$usage = new AiUsage($db, $clock);
$pictures = new AiPictures($runner, $settings, $usage, $media, $db, "$dir/storage", "$dir/public", $makeProvider, $clock);
$pictures->register();
$soon = static fn(): float => microtime(true) + 10;
$okReply = fn(int $n): array => ['status' => 200, 'body' => json_encode(['data' => array_fill(0, $n, ['b64_json' => base64_encode($png)]), 'usage' => $picUsage, 'size' => '1024x1536', 'quality' => 'medium'])];

check('a subject is needed', $pictures->request('  ', [], 2, 'editor', 'chris')['error'], 'Say what the picture shows.');
// No references on disk yet: generations, not edits.
$settings->update(['references' => []]);
$req = $pictures->request('A wooden shop sign with a clock.', ['cars'], 2, 'editor', 'chris', 'posts/opening-hours/en');
check('a request is queued', [$req['ok'], $pictures->status($req['job'])['status']], [true, JobRunner::QUEUED]);
$replies[] = $okReply(2);
$calls = [];
check('the job runs', $runner->work($soon()), 1);
$sent = json_decode((string)$calls[0]['body'], true);
check('sent to images/generations with the settings', [$calls[0]['url'], $sent['model'], $sent['n'], $sent['size'], $sent['quality'], $sent['output_format']], ['https://api.openai.com/v1/images/generations', 'gpt-image-2', 2, '1024x1536', 'medium', 'png']);
check('the prompt holds the house style, the subject and every avoid line', [str_starts_with($sent['prompt'], 'Watercolour, teal and sand.'), str_contains($sent['prompt'], 'Subject: A wooden shop sign with a clock.'), str_contains($sent['prompt'], 'Do not show: people; cars; text, letters, numbers or logos.')], [true, true, true]);
$status = $pictures->status($req['job']);
check('done with two variants', [$status['status'], $status['variants']], [JobRunner::DONE, 2]);
$variant = $pictures->variantPath($req['job'], 0);
$size = getimagesize((string)$variant);
check('each variant cut to 4:5 around its centre', [$size[0], $size[1]], [160, 200]);
check('a variant that is not there', [$pictures->variantPath($req['job'], 5), $pictures->variantPath($req['job'], '../../db/app.sqlite')], [null, null]);
$month = $usage->month($now);
check('usage recorded: two pictures, cost from the saved prices', [$month['pictures'], $month['cost']], [2, round(69 / 1e6 * 5 + 158 / 1e6 * 30, 6)]);
$row = $usage->totals(0, $now + 1, 'source')[0];
check('the row says where it came from and the entry', [$row['label'], $usage->forEntry('posts/opening-hours/en') > 0], ['editor', true]);

$picked = $pictures->pick($req['job'], 1, 'chris');
check('a picked variant goes into the library', [$picked['ok'], str_starts_with($picked['path'], '/uploads/'), is_file("$dir/public" . $picked['path'])], [true, true, true]);
$item = $media->find($picked['id']);
check('tagged generated, with alt text from the subject', [in_array('generated', (array)$item['tags'], true), $item['alt']], [true, 'A wooden shop sign with a clock.']);
$made = $db->connection()->query('SELECT subject, model, entry, made_by FROM ai_pictures')->fetch();
check('how it was made is kept', $made, ['subject' => 'A wooden shop sign with a clock.', 'model' => 'gpt-image-2', 'entry' => 'posts/opening-hours/en', 'made_by' => 'chris']);
check('picked twice: the same picture', $pictures->pick($req['job'], 1, 'chris')['id'], $picked['id']);
check('a variant of an unknown job', $pictures->pick(9999, 0, 'chris')['ok'], false);

// References on disk: edits, with the files.
copy("$dir/public" . $picked['path'], "$dir/public/uploads/media/a.png");
$settings->update(['references' => ['/uploads/media/a.png', '/uploads/media/missing.png'], 'format' => 'webp', 'aspect' => '16:9']);
$req = $pictures->request('A calm harbour.', [], 1, 'test', 'chris');
$replies[] = $okReply(1);
$calls = [];
$runner->work($soon());
check('with references: images/edits, multipart, only the files that exist', [$calls[0]['url'], substr_count((string)$calls[0]['body'], 'name="image[]"')], ['https://api.openai.com/v1/images/edits', 1]);
check('no input_fidelity for gpt-image-2, which refuses it', str_contains((string)$calls[0]['body'], 'name="input_fidelity"'), false);
check('input_fidelity only for the gpt-image-1 family', array_map(OpenAiProvider::takesInputFidelity(...), ['gpt-image-1', 'gpt-image-1.5', 'gpt-image-1-mini', 'gpt-image-2', 'gpt-image-2.5-flare', 'chatgpt-image-latest']), [true, true, true, false, false, false]);
check('16:9 from a 3:2 picture', getimagesize((string)$pictures->variantPath($req['job'], 0))[1], 169);

// ---- failures
$job = fn(): array => $runner->get($pictures->request('A lighthouse.', [], 1, 'editor', 'chris')['job']);
$replies[] = ['status' => 400, 'body' => '{"error":{"message":"The model \'gpt-image-2\' does not exist.","type":"image_generation_user_error","code":"invalid_value","param":"model"}}'];
$j = $job();
$runner->work($soon());
$j = $runner->get($j['id']);
check('a model no longer offered: failed, says to choose another', [$j['status'], str_contains($j['error'], 'no longer offers the model "gpt-image-2"')], [JobRunner::FAILED, true]);
check('a failed call is still in the usage', $usage->totals(0, $now + 1)[0]['failed'], 1);

$replies[] = ['status' => 429, 'headers' => ['retry-after' => '600'], 'body' => '{"error":{"message":"Rate limit reached","code":"rate_limit_exceeded"}}'];
$j = $job();
$runner->work($soon());
$j = $runner->get($j['id']);
check('a rate limit: back in the queue, after the wait the provider asked', [$j['status'], $j['run_after'] - $now], [JobRunner::QUEUED, 600]);
$runner->cancel($j['id']);

$replies[] = ['status' => 401, 'body' => '{"error":{"message":"Incorrect API key provided.","code":"invalid_api_key"}}'];
$j = $job();
$runner->work($soon());
check('a key refused: failed, not retried', [$runner->get($j['id'])['status'], str_contains($runner->get($j['id'])['error'], 'did not accept the key')], [JobRunner::FAILED, true]);

$replies[] = ['status' => 429, 'body' => '{"error":{"message":"You exceeded your current quota.","code":"insufficient_quota"}}'];
$j = $job();
$runner->work($soon());
$s = $settings->all();
check('no credit: failed, and everything paused with the reason', [$runner->get($j['id'])['status'], $s['paused'], str_contains($s['paused_reason'], 'no credit')], [JobRunner::FAILED, true, true]);
check('while paused nothing new is queued', str_starts_with($pictures->request('A boat.', [], 1, 'editor', 'chris')['error'], 'AI publishing is paused'), true);
$settings->setPaused(false);

$settings->update(['monthly_picture_cap' => 4]);
check('the picture cap counts what this request would make', [$pictures->request('A boat.', [], 1, 'editor', 'chris')['ok'], str_contains($pictures->request('A boat.', [], 2, 'editor', 'chris')['error'], 'picture cap (4)')], [true, true]);
$runner->cancel((int)$runner->list(AiPictures::KIND, [JobRunner::QUEUED])[0]['id']);
$settings->update(['monthly_picture_cap' => null, 'monthly_cost_cap' => 0.000001]);
check('the cost cap', str_contains($pictures->request('A boat.', [], 1, 'editor', 'chris')['error'], 'cost cap'), true);
$settings->update(['cap_action' => 'warn']);
check('with "warn only" the caps do not stop it', $pictures->request('A boat.', [], 1, 'editor', 'chris')['ok'], true);
$runner->cancel((int)$runner->list(AiPictures::KIND, [JobRunner::QUEUED])[0]['id']);

$settings->saveSettings(['clear_key' => '1']);
check('no key: nothing is queued', $pictures->request('A boat.', [], 1, 'editor', 'chris')['error'], 'Add an OpenAI key first, in Manage > AI publishing > Settings.');

// ---- bulk: one picture per entry, made from the entry, set as its main image
$settings->saveSettings(['openai_key' => 'sk-test-bulk-1234567890']);
$settings->update(['monthly_cost_cap' => null, 'monthly_picture_cap' => null, 'cap_action' => 'pause', 'references' => [], 'aspect' => '16:9', 'format' => 'webp']);
$entries = [
    'posts/harbour/el' => ['title' => 'Το λιμάνι;', 'excerpt' => 'Βάρκες το πρωί.', 'body' => '', 'main_image' => ''],
    'posts/lighthouse/el' => ['title' => 'Lighthouse', 'excerpt' => '', 'body' => "## Night\n\nThe **light** turns. ![x](/a.png) [More](https://x.example)", 'main_image' => ''],
    'posts/mill/el' => ['title' => 'Mill', 'excerpt' => 'Wind.', 'body' => '', 'main_image' => '/uploads/media/old.webp'],
];
$setCalls = [];
$bulk = new AiPictures($runner, $settings, $usage, $media, $db, "$dir/storage", "$dir/public", $makeProvider, $clock,
    entries: function (string $type, string $slug, string $lang) use (&$entries): ?array { return $entries["$type/$slug/$lang"] ?? null; },
    setMainImage: function (string $type, string $slug, string $lang, string $path, string $actor, bool $replace) use (&$entries, &$setCalls): string {
        $setCalls[] = [$type, $slug, $lang, $path, $actor, $replace];
        $key = "$type/$slug/$lang";
        if (!isset($entries[$key])) { return 'missing'; }
        if (!$replace && $entries[$key]['main_image'] !== '') { return 'kept'; }
        $entries[$key]['main_image'] = $path;
        return 'set';
    });
$bulk->register();
check('the subject of an entry: title and excerpt, no extra stop after a question mark', AiPictures::subjectFrom('Το λιμάνι;', 'Βάρκες το πρωί.', ''), 'Το λιμάνι; Βάρκες το πρωί.');
check('without an excerpt: the start of the text, without its markup', AiPictures::subjectFrom('Lighthouse', '', "## Night\n\nThe **light** turns. ![x](/a.png) [More](https://x.example)"), 'Lighthouse. Night The light turns. More');
$list = [['type' => 'posts', 'slug' => 'harbour', 'lang' => 'el', 'has_image' => false], ['type' => 'posts', 'slug' => 'lighthouse', 'lang' => 'el', 'has_image' => false], ['type' => 'posts', 'slug' => 'mill', 'lang' => 'el', 'has_image' => true]];
$r = $bulk->requestBulk($list, false, 'bob');
check('entries with a main image are left out', [$r['ok'], $r['queued'], $r['skipped']], [true, 2, 1]);
check('an entry already waiting is not queued twice', $bulk->requestBulk([$list[0]], false, 'bob')['queued'], 1);
check('...it is the same job', count($runner->list(AiPictures::KIND, [JobRunner::QUEUED])), 2);
check('all with images and no replace: nothing to do', str_starts_with($bulk->requestBulk([$list[2]], false, 'bob')['error'], 'Every selected entry has a main image'), true);
$states = $bulk->bulkStates('posts', 'el', ['harbour', 'lighthouse', 'mill']);
check('the list sees each entry waiting', [$states['harbour']['status'] ?? '', $states['lighthouse']['status'] ?? '', isset($states['mill'])], [JobRunner::QUEUED, JobRunner::QUEUED, false]);
check('progress: two waiting', $bulk->bulkProgress('posts', 'el'), ['waiting' => 2, 'working' => 0, 'done' => 0, 'failed' => 0]);
check('another language has none', $bulk->bulkProgress('posts', 'en')['waiting'], 0);

$replies[] = $okReply(1);
$calls = [];
$entries['posts/lighthouse/el']['main_image'] = '/uploads/media/set-meanwhile.webp';
check('both are worked', $runner->work($soon(), [AiPictures::KIND]), 2);
$sent = json_decode((string)$calls[0]['body'], true);
check('the prompt is made from the entry, one picture', [str_contains($sent['prompt'], 'Subject: Το λιμάνι; Βάρκες το πρωί.'), $sent['n']], [true, 1]);
check('the picture became the main image, by the bulk run', [$setCalls[0][1], str_starts_with($setCalls[0][3], '/uploads/'), $setCalls[0][4], $entries['posts/harbour/el']['main_image'] === $setCalls[0][3]], ['harbour', true, 'AI publishing (bob)', true]);
$states = $bulk->bulkStates('posts', 'el', ['harbour', 'lighthouse']);
check('done and set', [$states['harbour']['status'], $states['harbour']['outcome']], [JobRunner::DONE, 'set']);
check('an entry that got a main image meanwhile is not paid for', [$states['lighthouse']['status'], str_contains($states['lighthouse']['error'], 'has a main image by now'), count($calls)], [JobRunner::FAILED, true, 1]);
$pic = $media->find(substr(basename($setCalls[0][3]), 0, 16));
check('the picture is in the library with its alt text', [$pic !== null, $pic['alt'] ?? ''], [true, 'Το λιμάνι; Βάρκες το πρωί.']);
check('usage counts it as bulk, for its entry', $usage->totals(0, $now + 1, 'source')[array_search('bulk', array_column($usage->totals(0, $now + 1, 'source'), 'label'), true)]['pictures'], 1);
check('progress: one done, one failed', $bulk->bulkProgress('posts', 'el'), ['waiting' => 0, 'working' => 0, 'done' => 1, 'failed' => 1]);

$r = $bulk->requestBulk([$list[2]], true, 'bob');
$replies[] = $okReply(1);
$runner->work($soon(), [AiPictures::KIND]);
check('replace: an entry with a main image gets a new one', [$r['queued'], $setCalls[count($setCalls) - 1][1], $setCalls[count($setCalls) - 1][5], $bulk->bulkStates('posts', 'el', ['mill'])['mill']['outcome']], [1, 'mill', true, 'set']);
unset($entries['posts/harbour/el']);
$bulk->requestBulk([$list[0]], true, 'bob');
$runner->work($soon(), [AiPictures::KIND]);
check('an entry that is gone: failed, nothing paid', str_contains($bulk->bulkStates('posts', 'el', ['harbour'])['harbour']['error'], 'no longer there'), true);
$settings->update(['monthly_picture_cap' => 1]);
check('the cap counts every picture a bulk run would make', str_contains($bulk->requestBulk([$list[1], $list[2]], true, 'bob')['error'], 'picture cap'), true);
$settings->update(['monthly_picture_cap' => null]);

// ---- old variants are removed
check('variants of the day are kept', count(glob("$dir/storage/ai/variants/*/*") ?: []) >= 3, true);
$now += 2 * 86400;
$pictures->cleanup();
check('variants older than a day are removed', glob("$dir/storage/ai/variants/*/*") ?: [], []);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
