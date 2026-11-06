<?php
/*
 * One real call of each kind to OpenAI, from the command line, before the AI publishing module is built on them
 * (docs/ai-publishing-plan.md, phase 0): it checks the key and the models, writes one short post as structured JSON
 * (Responses API) and makes one picture (with reference pictures when given), and prints how long each took, how big each answer
 * was, the rate-limit headers and the exact `usage` fields. The key is read from the environment and never printed.
 *
 *   read -s OPENAI_API_KEY && export OPENAI_API_KEY    (paste the key; nothing is shown, so it stays off the screen)
 *   php src/ai-check.php [options]
 *
 *   --text-model=gpt-5-mini     the model that writes
 *   --image-model=gpt-image-2   the model that paints
 *   --effort=low                reasoning effort for reasoning models (minimal, low, medium, high; "none" sends nothing)
 *   --quality=low               picture quality (low, medium, high, auto)
 *   --size=1536x1024            picture size
 *   --format=webp               picture format (webp, jpeg, png)
 *   --ref=a.jpg,b.png           reference pictures (sent to the edits endpoint; input_fidelity high for gpt-image-1 models only)
 *   --subject="..."             what the picture shows (default: a shop sign with a clock)
 *   --out=/path                 where the picture and the raw answers are saved (default: a folder in the system temp)
 *   --search                    let the writing model search the web (the web_search tool) for a short news brief with sources
 *   --skip-text, --skip-image   leave one out
 *
 * Costs a few cents at low quality. Nothing is written to the site.
 * (It lives in src/ so an update keeps it; it answers 404 to the web.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use GnozioCMS\AiHttp;

$options = getopt('', ['text-model:', 'image-model:', 'effort:', 'quality:', 'size:', 'format:', 'ref:', 'subject:', 'out:', 'skip-text', 'skip-image', 'search', 'help']);
if (isset($options['help'])) {
    preg_match('#/\*(.*?)\*/#s', (string)file_get_contents(__FILE__), $m);
    echo trim((string)preg_replace('/^\s*\* ?/m', '', $m[1] ?? '')) . PHP_EOL;
    exit(0);
}
$key = trim((string)getenv('OPENAI_API_KEY'));
if ($key === '') {
    fwrite(STDERR, "Set OPENAI_API_KEY in the environment first, without showing it on screen:\n  read -s OPENAI_API_KEY && export OPENAI_API_KEY\n  (paste the key, press Enter), then: php src/ai-check.php\n");
    exit(2);
}
$textModel = (string)($options['text-model'] ?? 'gpt-5-mini');
$imageModel = (string)($options['image-model'] ?? 'gpt-image-2');
$effort = (string)($options['effort'] ?? 'low');
$quality = (string)($options['quality'] ?? 'low');
$size = (string)($options['size'] ?? '1536x1024');
$format = (string)($options['format'] ?? 'webp');
$refs = array_values(array_filter(array_map('trim', explode(',', (string)($options['ref'] ?? '')))));
$out = rtrim((string)($options['out'] ?? sys_get_temp_dir() . '/gnozio-ai-check-' . date('Ymd-His')), '/');
if (!is_dir($out) && !@mkdir($out, 0775, true)) {
    fwrite(STDERR, "The folder $out could not be made.\n");
    exit(2);
}
foreach ($refs as $ref) {
    if (!is_file($ref)) {
        fwrite(STDERR, "The reference picture $ref is missing.\n");
        exit(2);
    }
}

$http = new AiHttp(['api.openai.com'], [$key]);
$auth = ['Authorization: Bearer ' . $key];
$base = 'https://api.openai.com/v1';
$failed = 0;

$line = static fn(string $text = ''): int => print($text . PHP_EOL);
$json = static fn(mixed $value): string => (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$report = static function (string $title, array $r) use ($line, $json, &$failed): void {
    $line();
    $line('== ' . $title);
    $line('   status:   ' . $r['status'] . ($r['ok'] ? ' ok' : ' FAILED (' . $r['kind'] . ')'));
    $line('   time:     ' . number_format($r['ms'] / 1000, 2) . ' s');
    $line('   answer:   ' . number_format($r['bytes'] / 1024, 1) . ' KB');
    if ($r['rate'] !== []) {
        $line('   limits:   ' . implode(', ', array_map(static fn(string $k, string $v): string => $k . '=' . $v, array_keys($r['rate']), $r['rate'])));
    }
    if (!$r['ok']) {
        $failed++;
        $line('   error:    ' . $r['error']);
        $error = is_array($r['data']['error'] ?? null) ? $r['data']['error'] : [];
        if ($error !== []) {
            $line('   type:     ' . json_encode($error['type'] ?? null) . ', code: ' . json_encode($error['code'] ?? null) . ', param: ' . json_encode($error['param'] ?? null));
        }
        if ($r['retry_after'] !== null) {
            $line('   retry in: ' . $r['retry_after'] . ' s');
        }
        return;
    }
    if (isset($r['data']['usage'])) {
        $line('   usage:');
        $line(preg_replace('/^/m', '     ', $json($r['data']['usage'])));
    }
};
$save = static function (string $name, array $r) use ($out): void {
    $data = $r['data'];
    // Pictures are saved as files; the raw answer keeps a mark where the base64 was, so it stays readable.
    foreach ($data['data'] ?? [] as $i => $item) {
        if (isset($item['b64_json'])) {
            $data['data'][$i]['b64_json'] = '[' . strlen((string)$item['b64_json']) . ' characters of base64, saved as a file]';
        }
    }
    file_put_contents("$out/$name.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};

$line('GnozioCMS AI check against OpenAI, ' . date('Y-m-d H:i:s T'));
$line('Text model ' . $textModel . ($effort !== 'none' ? ' (effort ' . $effort . ')' : '') . ', image model ' . $imageModel . ' (' . $quality . ', ' . $size . ', ' . $format . ', ' . count($refs) . ' reference pictures)');
$line('Answers are saved in ' . $out);

// ---- 1. the key and the models
$r = $http->get($base . '/models', $auth);
$report('Models this key can see', $r);
if ($r['ok']) {
    $ids = array_map(static fn(array $m): string => (string)($m['id'] ?? ''), $r['data']['data'] ?? []);
    sort($ids);
    $pictures = array_values(array_filter($ids, static fn(string $id): bool => preg_match('/image|dall-e/i', $id) === 1));
    $writers = array_values(array_filter($ids, static fn(string $id): bool => preg_match('/^(gpt-|o\d)/i', $id) === 1 && preg_match('/image|audio|realtime|transcribe|tts|search|embedding/i', $id) !== 1));
    $line('   pictures: ' . ($pictures === [] ? '(none)' : implode(', ', $pictures)));
    $line('   writing:  ' . ($writers === [] ? '(none)' : implode(', ', $writers)));
    $line('   in all:   ' . count($ids) . ' models');
}
foreach (array_unique(array_filter([isset($options['skip-text']) ? '' : $textModel, isset($options['skip-image']) ? '' : $imageModel])) as $model) {
    $r = $http->get($base . '/models/' . rawurlencode($model), $auth);
    $report('Key and model ' . $model, $r);
    if ($r['ok']) {
        $line('   owned by: ' . ($r['data']['owned_by'] ?? '?'));
    }
}

// ---- 2. a short post as structured JSON
if (!isset($options['skip-text'])) {
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['title', 'excerpt', 'body', 'terms', 'seo_title', 'seo_description', 'image_brief', 'sources'],
        'properties' => [
            'title' => ['type' => 'string'],
            'excerpt' => ['type' => 'string'],
            'body' => ['type' => 'string', 'description' => 'Markdown'],
            'terms' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['Tips', 'News', 'Guides']]],
            'seo_title' => ['type' => 'string'],
            'seo_description' => ['type' => 'string'],
            'image_brief' => ['type' => 'string', 'description' => 'What the picture shows, one or two sentences, no style words'],
            'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
            'can_write' => ['type' => 'boolean'],
            'cannot_write_reason' => ['type' => 'string'],
        ],
    ];
    $schema['required'][] = 'can_write';
    $schema['required'][] = 'cannot_write_reason';
    $search = isset($options['search']);
    $payload = [
        'model' => $textModel,
        'input' => [
            ['role' => 'developer', 'content' => "You write posts for a small-business website. Plain, friendly English, short paragraphs, one heading level (##).\nNever do any of these:\n- invent facts, quotes, prices or statistics\n- use the phrase \"in today's fast-paced world\""],
            ['role' => 'user', 'content' => $search
                ? 'Today is ' . date('j F Y') . '. Search the web and write a news brief of 150 to 200 words about one notable official announcement in Greece from the past seven days, with its date. Put the addresses you used in sources and end the body with them as Markdown links. Choose terms only from the allowed list (News). If you cannot confirm anything, set can_write to false and say why.'
                : 'Write one practical tip about writing clear opening hours on a website, 150 to 200 words. Choose terms only from the allowed list. image_brief: an everyday object that stands for the tip. sources: an empty list. can_write: true.'],
        ],
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'entry', 'strict' => true, 'schema' => $schema]],
        'max_output_tokens' => 4000,
    ];
    if ($effort !== 'none') {
        $payload['reasoning'] = ['effort' => $effort];
    }
    if ($search) {
        $payload['tools'] = [['type' => 'web_search']];
        $payload['max_output_tokens'] = 16000;
    }
    $r = $http->postJson($base . '/responses', $payload, $auth, 300);
    $report('Writing (Responses API, json_schema' . ($search ? ', web search' : '') . ')', $r);
    if (!$r['ok'] && $r['data'] !== null) {
        $save('text-error', $r);
    }
    if ($r['ok']) {
        $save('text-answer', $r);
        $text = '';
        foreach ($r['data']['output'] ?? [] as $item) {
            foreach (($item['type'] ?? '') === 'message' ? ($item['content'] ?? []) : [] as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    $text .= (string)($part['text'] ?? '');
                } elseif (($part['type'] ?? '') === 'refusal') {
                    $line('   refusal:  ' . ($part['refusal'] ?? ''));
                }
            }
        }
        $entry = json_decode($text, true);
        $line('   status in answer: ' . ($r['data']['status'] ?? '?') . (isset($r['data']['incomplete_details']['reason']) ? ' (' . $r['data']['incomplete_details']['reason'] . ')' : ''));
        $line('   output items:     ' . implode(', ', array_map(static fn(array $i): string => (string)($i['type'] ?? '?'), $r['data']['output'] ?? [])));
        $line('   searches:         ' . count(array_filter($r['data']['output'] ?? [], static fn(array $i): bool => ($i['type'] ?? '') === 'web_search_call')));
        if (is_array($entry)) {
            $missing = array_diff($schema['required'], array_keys($entry));
            $line('   JSON:     parsed' . ($missing === [] ? ', every field present' : ', MISSING ' . implode(', ', $missing)));
            $line('   title:    ' . ($entry['title'] ?? ''));
            $line('   terms:    ' . implode(', ', (array)($entry['terms'] ?? [])));
            $line('   words:    ' . str_word_count(strip_tags((string)($entry['body'] ?? ''))));
            $line('   brief:    ' . ($entry['image_brief'] ?? ''));
            $line('   can write: ' . json_encode($entry['can_write'] ?? null) . (($entry['cannot_write_reason'] ?? '') !== '' ? ' (' . $entry['cannot_write_reason'] . ')' : ''));
            $line('   sources:  ' . (implode(', ', (array)($entry['sources'] ?? [])) ?: '(none)'));
            $failed += $missing === [] ? 0 : 1;
        } else {
            $failed++;
            $line('   JSON:     NOT PARSED; the text was: ' . mb_substr($text, 0, 300));
        }
    }
}

// ---- 3. one picture
if (!isset($options['skip-image'])) {
    $prompt = "Flat editorial illustration, soft muted palette of teal and warm sand, generous empty space, one subject in the centre, gentle morning light.\n"
        . 'Subject: ' . (trim((string)($options['subject'] ?? '')) ?: 'a wooden shop sign with a small clock hanging from it, seen from the front.') . "\n"
        . "Do not show: text, letters or numbers; logos; watermarks; people.";
    if ($refs === []) {
        $r = $http->postJson($base . '/images/generations', [
            'model' => $imageModel, 'prompt' => $prompt, 'n' => 1, 'size' => $size, 'quality' => $quality, 'output_format' => $format, 'background' => 'opaque', 'moderation' => 'auto',
        ], $auth, 300);
        $title = 'Picture (images/generations)';
    } else {
        $r = $http->postMultipart($base . '/images/edits', [
            'model' => $imageModel, 'prompt' => "Match the style of the reference pictures; do not copy their subjects.\n" . $prompt, 'n' => '1', 'size' => $size,
            'quality' => $quality, 'output_format' => $format, 'background' => 'opaque',
        ] + (GnozioCMS\OpenAiProvider::takesInputFidelity($imageModel) ? ['input_fidelity' => 'high'] : []), ['image[]' => $refs], $auth, 300);
        $title = 'Picture (images/edits, ' . count($refs) . ' reference pictures)';
    }
    $report($title, $r);
    if (!$r['ok'] && $r['data'] !== null) {
        $save('image-error', $r);
    }
    if ($r['ok']) {
        $b64 = (string)($r['data']['data'][0]['b64_json'] ?? '');
        if ($b64 !== '') {
            $file = "$out/picture." . ($format === 'jpeg' ? 'jpg' : $format);
            file_put_contents($file, base64_decode($b64));
            $dimensions = @getimagesize($file);
            $line('   picture:  ' . $file . ' (' . number_format(filesize($file) / 1024, 1) . ' KB' . ($dimensions ? ', ' . $dimensions[0] . '×' . $dimensions[1] : '') . ')');
        } else {
            $failed++;
            $line('   picture:  NONE in the answer (fields: ' . implode(', ', array_keys($r['data']['data'][0] ?? [])) . ')');
        }
        foreach (['size', 'quality', 'output_format', 'background'] as $field) {
            if (isset($r['data'][$field])) {
                $line('   ' . str_pad($field . ':', 10) . $r['data'][$field]);
            }
        }
        $save('image-answer', $r);
    }
}

$line();
$line($failed === 0 ? 'All calls worked. Send this output (it holds no key).' : $failed . ' failed. Send this output (it holds no key).');
exit($failed === 0 ? 0 : 1);
