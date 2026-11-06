<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The settings of Manage > AI publishing (docs/ai-publishing-plan.md): the provider key, the models, the instructions for pictures,
 * the limits and the price table. Kept in `system_meta` under its own name, apart from the site settings, so no settings form or
 * export ever carries the key; the screens get view(), which never holds it.
 */
final class AiSettings
{
    public const META = 'ai_settings';
    public const ASPECTS = ['16:9', '3:2', '4:3', '1:1', '4:5'];
    public const QUALITIES = ['low', 'medium', 'high'];
    public const FORMATS = ['webp', 'jpeg', 'png'];
    public const MAX_REFERENCES = 4;
    public const DEFAULT_TEXT_MODEL = 'gpt-5-mini';
    public const DEFAULT_IMAGE_MODEL = 'gpt-image-2';
    /** Price fields, in US dollars: per million tokens, and per call for a web search. */
    public const PRICE_FIELDS = ['text_input', 'cached_input', 'text_output', 'image_input', 'image_output', 'search_call'];

    public const HOUSE_STYLE_EXAMPLE = 'Flat editorial illustration, soft muted palette, generous empty space, one clear subject in the centre, gentle natural light, calm mood.';
    public const WRITING_EXAMPLE = "Write for the readers of this site in clear, friendly language, in the language of the post. Short paragraphs, a few ## headings, lists where they help. 400 to 700 words unless the schedule says otherwise. A clear title that says what the reader gets; an excerpt of one or two sentences; an SEO description of about 150 characters.";
    public const TEXT_AVOID_EXAMPLE = "invented facts, quotes, prices, dates or statistics\nmedical, legal or financial advice\nnames of competitors\nphrases such as \"in today's fast-paced world\" or \"in conclusion\"\nemojis";
    public const AVOID_EXAMPLE = "text, letters or numbers\nlogos and watermarks\nrecognisable faces\nclichés such as handshakes and light bulbs";

    public function __construct(private SystemMetaRepository $meta)
    {
    }

    /** @return array<string, mixed> everything, the key included: for the server only */
    public function all(): array
    {
        $stored = $this->meta->isAvailable() ? ($this->meta->getJson(self::META) ?? []) : [];
        return self::normalize($stored);
    }

    public function key(): string
    {
        return (string)$this->all()['openai_key'];
    }

    public function hasKey(): bool
    {
        return $this->key() !== '';
    }

    /** @return array<string, mixed> for screens: no key, only whether there is one and its last four characters */
    public function view(): array
    {
        $all = $this->all();
        $key = (string)$all['openai_key'];
        unset($all['openai_key']);
        return $all + ['has_key' => $key !== '', 'key_hint' => $key !== '' ? '…' . substr($key, -4) : ''];
    }

    /** @param array<string, mixed> $values merged over what is stored, then checked */
    public function update(array $values): void
    {
        if (!$this->meta->isAvailable()) {
            return;
        }
        $this->meta->setJson(self::META, self::normalize(array_replace($this->meta->getJson(self::META) ?? [], $values)));
    }

    /**
     * The Settings tab's form: key (blank keeps it, clear_key removes it), models, limits, prices.
     * @param array<string, mixed> $post
     */
    public function saveSettings(array $post): void
    {
        $values = [
            'text_model' => self::modelName($post['text_model'] ?? ''),
            'image_model' => self::modelName($post['image_model'] ?? ''),
            'monthly_cost_cap' => self::money($post['monthly_cost_cap'] ?? ''),
            'monthly_picture_cap' => self::positiveInt($post['monthly_picture_cap'] ?? ''),
            'cap_action' => ($post['cap_action'] ?? '') === 'warn' ? 'warn' : 'pause',
            'keep_logs_days' => max(1, min(365, (int)($post['keep_logs_days'] ?? 30) ?: 30)),
            'missed_window' => max(0, min(1440, (int)($post['missed_window'] ?? 60))),
        ];
        $key = trim((string)($post['openai_key'] ?? ''));
        if (($post['clear_key'] ?? '') === '1') {
            $values['openai_key'] = '';
            $values['models'] = ['pictures' => [], 'writing' => [], 'checked_at' => 0];
        } elseif ($key !== '') {
            $values['openai_key'] = $key;
        }
        if (is_array($post['prices'] ?? null)) {
            $prices = [];
            foreach ($post['prices'] as $row) {
                $model = self::modelName(is_array($row) ? ($row['model'] ?? '') : '');
                if ($model === '') {
                    continue;
                }
                foreach (self::PRICE_FIELDS as $field) {
                    $prices[$model][$field] = self::money($row[$field] ?? '');
                }
            }
            $values['prices'] = $prices;
            if (($post['prices_checked'] ?? '') === '1') {
                $values['prices_checked'] = gmdate('Y-m-d');
            }
        }
        $this->update($values);
    }

    /**
     * The picture half of the Instructions tab.
     * @param array<string, mixed> $post
     */
    public function savePictureInstructions(array $post): void
    {
        $references = preg_split('/[\r\n,]+/', (string)($post['references'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->update([
            'house_style' => self::text($post['house_style'] ?? '', 4000),
            'picture_avoid' => self::lines($post['picture_avoid'] ?? '', 40),
            'references' => array_slice(array_values(array_unique(array_filter(array_map(self::libraryPath(...), $references)))), 0, self::MAX_REFERENCES),
            'aspect' => (string)($post['aspect'] ?? ''),
            'quality' => (string)($post['quality'] ?? ''),
            'format' => (string)($post['format'] ?? ''),
            'variants' => (int)($post['variants'] ?? 0),
        ]);
    }

    /**
     * The writing half of the Instructions tab.
     * @param array<string, mixed> $post
     */
    public function saveWritingInstructions(array $post): void
    {
        $this->update([
            'writing_instructions' => self::text($post['writing_instructions'] ?? '', 6000),
            'text_avoid' => self::lines($post['text_avoid'] ?? '', 40),
            'site_facts' => self::text($post['site_facts'] ?? '', 4000),
            'byline' => self::text($post['byline'] ?? '', 80),
        ]);
    }

    /** @param string[] $pictures @param string[] $writing */
    public function setModels(array $pictures, array $writing, int $checkedAt): void
    {
        $this->update(['models' => ['pictures' => array_values($pictures), 'writing' => array_values($writing), 'checked_at' => $checkedAt]]);
    }

    public function setPaused(bool $paused, string $reason = ''): void
    {
        $this->update(['paused' => $paused, 'paused_reason' => $paused ? self::text($reason, 300) : '']);
    }

    /** The provider's size for an aspect ratio: the nearest it makes; the picture is cut to the exact ratio afterwards. */
    public static function size(string $aspect): string
    {
        return match ($aspect) {
            '1:1' => '1024x1024',
            '4:5' => '1024x1536',
            default => '1536x1024',
        };
    }

    /** @return array{0: int, 1: int} width and height of the ratio */
    public static function ratio(string $aspect): array
    {
        [$w, $h] = array_map('intval', explode(':', in_array($aspect, self::ASPECTS, true) ? $aspect : '16:9'));
        return [$w, $h];
    }

    /** @param array<string, mixed> $stored @return array<string, mixed> */
    public static function normalize(array $stored): array
    {
        $models = is_array($stored['models'] ?? null) ? $stored['models'] : [];
        $prices = [];
        foreach (is_array($stored['prices'] ?? null) ? $stored['prices'] : self::defaultPrices() as $model => $row) {
            $model = self::modelName($model);
            if ($model === '' || !is_array($row)) {
                continue;
            }
            foreach (self::PRICE_FIELDS as $field) {
                $prices[$model][$field] = isset($row[$field]) && is_numeric($row[$field]) ? (float)$row[$field] : null;
            }
        }
        ksort($prices);
        return [
            'openai_key' => is_string($stored['openai_key'] ?? null) ? trim($stored['openai_key']) : '',
            'text_model' => self::modelName($stored['text_model'] ?? '') ?: self::DEFAULT_TEXT_MODEL,
            'image_model' => self::modelName($stored['image_model'] ?? '') ?: self::DEFAULT_IMAGE_MODEL,
            'models' => [
                'pictures' => array_values(array_filter(array_map(self::modelName(...), (array)($models['pictures'] ?? [])))),
                'writing' => array_values(array_filter(array_map(self::modelName(...), (array)($models['writing'] ?? [])))),
                'checked_at' => (int)($models['checked_at'] ?? 0),
            ],
            'house_style' => is_string($stored['house_style'] ?? null) ? $stored['house_style'] : self::HOUSE_STYLE_EXAMPLE,
            'picture_avoid' => is_array($stored['picture_avoid'] ?? null) ? array_values(array_map('strval', $stored['picture_avoid'])) : self::lines(self::AVOID_EXAMPLE, 40),
            'references' => array_slice(array_values(array_filter(array_map(self::libraryPath(...), (array)($stored['references'] ?? [])))), 0, self::MAX_REFERENCES),
            'aspect' => in_array($stored['aspect'] ?? null, self::ASPECTS, true) ? $stored['aspect'] : '16:9',
            'quality' => in_array($stored['quality'] ?? null, self::QUALITIES, true) ? $stored['quality'] : 'medium',
            'format' => in_array($stored['format'] ?? null, self::FORMATS, true) ? $stored['format'] : 'webp',
            'variants' => max(1, min(4, (int)($stored['variants'] ?? 2) ?: 2)),
            'monthly_cost_cap' => isset($stored['monthly_cost_cap']) && is_numeric($stored['monthly_cost_cap']) && $stored['monthly_cost_cap'] > 0 ? (float)$stored['monthly_cost_cap'] : null,
            'monthly_picture_cap' => isset($stored['monthly_picture_cap']) && is_numeric($stored['monthly_picture_cap']) && $stored['monthly_picture_cap'] > 0 ? (int)$stored['monthly_picture_cap'] : null,
            'cap_action' => ($stored['cap_action'] ?? '') === 'warn' ? 'warn' : 'pause',
            'keep_logs_days' => max(1, min(365, (int)($stored['keep_logs_days'] ?? 30) ?: 30)),
            'missed_window' => isset($stored['missed_window']) && is_numeric($stored['missed_window']) ? max(0, min(1440, (int)$stored['missed_window'])) : 60,
            'writing_instructions' => is_string($stored['writing_instructions'] ?? null) ? $stored['writing_instructions'] : self::WRITING_EXAMPLE,
            'text_avoid' => is_array($stored['text_avoid'] ?? null) ? array_values(array_map('strval', $stored['text_avoid'])) : self::lines(self::TEXT_AVOID_EXAMPLE, 40),
            'site_facts' => is_string($stored['site_facts'] ?? null) ? $stored['site_facts'] : '',
            'byline' => is_string($stored['byline'] ?? null) ? $stored['byline'] : '',
            'paused' => (bool)($stored['paused'] ?? false),
            'paused_reason' => is_string($stored['paused_reason'] ?? null) ? $stored['paused_reason'] : '',
            'prices' => $prices,
            'prices_checked' => is_string($stored['prices_checked'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $stored['prices_checked']) === 1 ? $stored['prices_checked'] : '',
        ];
    }

    /**
     * The prices this version starts with, per million tokens in US dollars, for the models whose prices were published when it was
     * written. Picture models are left empty to be filled from OpenAI's pricing page: their prices were not known when this was made.
     * @return array<string, array<string, float|null>>
     */
    public static function defaultPrices(): array
    {
        $row = static fn(?float $in, ?float $cached, ?float $out, ?float $imageIn = null, ?float $imageOut = null): array
            => ['text_input' => $in, 'cached_input' => $cached, 'text_output' => $out, 'image_input' => $imageIn, 'image_output' => $imageOut, 'search_call' => null];
        return [
            'gpt-5' => $row(1.25, 0.125, 10.0),
            'gpt-5-mini' => $row(0.25, 0.025, 2.0),
            'gpt-5-nano' => $row(0.05, 0.005, 0.4),
            'gpt-image-2' => $row(null, null, null),
        ];
    }

    /** A model name as providers write them, or '' for anything else. */
    public static function modelName(mixed $value): string
    {
        $value = trim(is_string($value) ? $value : '');
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,99}$/', $value) === 1 ? $value : '';
    }

    /** A library address (/uploads/...) of a picture, or ''. */
    public static function libraryPath(mixed $value): string
    {
        $value = trim(is_string($value) ? $value : '');
        return preg_match('#^/uploads/[A-Za-z0-9_./\-]+\.(jpe?g|png|webp|gif|avif)$#i', $value) === 1 && !str_contains($value, '..') ? $value : '';
    }

    private static function money(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string)$value));
        return is_numeric($value) && (float)$value >= 0 ? round((float)$value, 6) : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $value = trim((string)$value);
        return ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    private static function text(mixed $value, int $max): string
    {
        $value = trim(str_replace("\r\n", "\n", (string)$value));
        return mb_substr($value, 0, $max);
    }

    /** @return string[] one item per line, empty lines and leading "- " dropped */
    public static function lines(mixed $value, int $max): array
    {
        $lines = [];
        // Stored lists come back as lists; forms send text.
        $value = is_array($value) ? implode("\n", array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', $value)) : (string)$value;
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim(preg_replace('/^[-*•]\s*/u', '', trim($line)) ?? '');
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 200);
            }
        }
        return array_slice(array_values(array_unique($lines)), 0, $max);
    }
}
