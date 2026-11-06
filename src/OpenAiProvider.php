<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * OpenAI for AI publishing (docs/ai-publishing-plan.md): the models a key can use, and pictures (images/generations, or images/edits
 * when reference pictures go with the prompt). Writing comes in phase 3. Every call goes through AiHttp, so failures arrive sorted
 * by kind and the key never appears in a message.
 */
final class OpenAiProvider
{
    public const HOST = 'api.openai.com';
    private const BASE = 'https://api.openai.com/v1';

    private AiHttp $http;

    public function __construct(private string $key, ?AiHttp $http = null)
    {
        $this->http = $http ?? new AiHttp([self::HOST], [$key]);
    }

    /**
     * The models this key can use, sorted, split into picture and writing models, dated copies (name-2026-04-21) left out.
     * @return array{ok: bool, pictures: string[], writing: string[], error: string, kind: string}
     */
    public function models(): array
    {
        $r = $this->http->get(self::BASE . '/models', $this->auth());
        if (!$r['ok']) {
            return ['ok' => false, 'pictures' => [], 'writing' => [], 'error' => $r['error'], 'kind' => $r['kind']];
        }
        $ids = array_values(array_unique(array_filter(array_map(static fn($m): string => is_array($m) ? (string)($m['id'] ?? '') : '', (array)($r['data']['data'] ?? [])))));
        $ids = array_values(array_filter($ids, static fn(string $id): bool => preg_match('/-\d{4}-\d{2}-\d{2}$/', $id) !== 1));
        sort($ids, SORT_NATURAL);
        return [
            'ok' => true,
            'pictures' => array_values(array_filter($ids, static fn(string $id): bool => preg_match('/(^gpt-image|^chatgpt-image|^dall-e)/i', $id) === 1)),
            'writing' => array_values(array_filter($ids, static fn(string $id): bool => preg_match('/^(gpt-\d|o\d)/i', $id) === 1
                && preg_match('/image|audio|realtime|transcribe|tts|search|embedding|codex|live|pro\b|-pro$/i', $id) !== 1)),
            'error' => '',
            'kind' => '',
        ];
    }

    /**
     * Makes pictures. With reference paths the request goes to images/edits, which follows their style.
     * @param string[] $references local files
     * @return array{ok: bool, images: string[], usage: array<string, mixed>, kind: string, error: string, retry_after: int|null, ms: int, model_gone: bool}
     *   images are the decoded bytes; model_gone says the provider no longer offers the model by that name
     */
    public function picture(string $model, string $prompt, array $references, string $size, string $quality, string $format, int $n): array
    {
        $n = max(1, min(4, $n));
        if ($references === []) {
            $r = $this->http->postJson(self::BASE . '/images/generations', [
                'model' => $model, 'prompt' => $prompt, 'n' => $n, 'size' => $size, 'quality' => $quality,
                'output_format' => $format, 'background' => 'opaque', 'moderation' => 'auto',
            ], $this->auth(), 300);
        } else {
            $fields = ['model' => $model, 'prompt' => $prompt, 'n' => (string)$n, 'size' => $size, 'quality' => $quality, 'output_format' => $format, 'background' => 'opaque'];
            if (self::takesInputFidelity($model)) {
                $fields['input_fidelity'] = 'high';
            }
            $r = $this->http->postMultipart(self::BASE . '/images/edits', $fields, ['image[]' => $references], $this->auth(), 300);
        }
        $result = ['ok' => $r['ok'], 'images' => [], 'usage' => is_array($r['data']['usage'] ?? null) ? $r['data']['usage'] : [], 'kind' => $r['kind'],
            'error' => $r['error'], 'retry_after' => $r['retry_after'], 'ms' => $r['ms'], 'model_gone' => self::modelGone($r)];
        if (!$r['ok']) {
            return $result;
        }
        foreach ((array)($r['data']['data'] ?? []) as $item) {
            $bytes = is_array($item) && is_string($item['b64_json'] ?? null) ? base64_decode($item['b64_json'], true) : false;
            if (is_string($bytes) && $bytes !== '') {
                $result['images'][] = $bytes;
            }
        }
        if ($result['images'] === []) {
            return ['ok' => false, 'kind' => AiHttp::SERVER, 'error' => 'The provider answered without a picture.'] + $result;
        }
        return $result;
    }

    /**
     * Writes with the Responses API, the answer bound to a JSON schema (strict). `$instructions` go as the developer message, `$input`
     * as the user's. Reasoning models get a low effort; others none.
     * @param array<string, mixed> $schema
     * @return array{ok: bool, data: array<string, mixed>|null, usage: array<string, mixed>, kind: string, error: string, retry_after: int|null,
     *   ms: int, model_gone: bool, refusal: string, incomplete: string}
     */
    public function write(string $model, string $instructions, string $input, string $schemaName, array $schema, int $maxTokens = 8000): array
    {
        $payload = [
            'model' => $model,
            'input' => [['role' => 'developer', 'content' => $instructions], ['role' => 'user', 'content' => $input]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => $schemaName, 'strict' => true, 'schema' => $schema]],
            'max_output_tokens' => $maxTokens,
        ];
        if (self::reasons($model)) {
            $payload['reasoning'] = ['effort' => 'low'];
        }
        $r = $this->http->postJson(self::BASE . '/responses', $payload, $this->auth(), 240);
        $result = ['ok' => false, 'data' => null, 'usage' => is_array($r['data']['usage'] ?? null) ? $r['data']['usage'] : [], 'kind' => $r['kind'], 'error' => $r['error'],
            'retry_after' => $r['retry_after'], 'ms' => $r['ms'], 'model_gone' => self::modelGone($r), 'refusal' => '', 'incomplete' => ''];
        if (!$r['ok']) {
            return $result;
        }
        $text = '';
        foreach ((array)($r['data']['output'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ((array)($item['content'] ?? []) as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    $text .= (string)($part['text'] ?? '');
                } elseif (($part['type'] ?? '') === 'refusal') {
                    $result['refusal'] = trim((string)($part['refusal'] ?? ''));
                }
            }
        }
        $result['incomplete'] = (string)($r['data']['incomplete_details']['reason'] ?? '');
        if ($result['refusal'] !== '') {
            return ['kind' => AiHttp::REFUSED, 'error' => 'The model declined to write it: ' . mb_substr($result['refusal'], 0, 300)] + $result;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return ['kind' => AiHttp::SERVER, 'error' => $result['incomplete'] !== '' ? 'The answer was cut short (' . $result['incomplete'] . ').' : 'The answer was not the JSON asked for.'] + $result;
        }
        return ['ok' => true, 'data' => $data, 'kind' => '', 'error' => ''] + $result;
    }

    /** Models that take `reasoning.effort` (the gpt-5 family and o-series). */
    public static function reasons(string $model): bool
    {
        return preg_match('/^(gpt-[5-9]|o\d)/', $model) === 1;
    }

    /**
     * Whether a picture model accepts `input_fidelity` (follow the reference pictures closely). Only the gpt-image-1 family does;
     * gpt-image-2 refuses the request when it is sent (invalid_input_fidelity_model, checked with a real key on 2026-11-06).
     */
    public static function takesInputFidelity(string $model): bool
    {
        return preg_match('/^gpt-image-1(\.|-|$)/', $model) === 1;
    }

    /** @param array<string, mixed> $r an AiHttp result */
    private static function modelGone(array $r): bool
    {
        $error = is_array($r['data']['error'] ?? null) ? $r['data']['error'] : [];
        return !$r['ok'] && (($error['param'] ?? '') === 'model' || ($error['code'] ?? '') === 'model_not_found'
            || str_contains(strtolower((string)($error['message'] ?? '')), 'does not exist'));
    }

    /** @return string[] */
    private function auth(): array
    {
        return ['Authorization: Bearer ' . $this->key];
    }
}
