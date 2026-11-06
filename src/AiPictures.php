<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Pictures made by AI publishing (docs/ai-publishing-plan.md, sections 5 and 7). A request is a job on the JobRunner: its handler
 * builds the prompt (the house style word for word, the subject, then what not to show), sends it with the reference pictures,
 * records the usage, cuts each picture to the chosen ratio and keeps it as a variant in storage. A variant becomes a picture of the
 * media library only when someone picks it (pick()), tagged `generated`, with alt text from its subject and a row in `ai_pictures`
 * saying how it was made. Variants not picked are removed after a day.
 */
final class AiPictures
{
    public const KIND = 'ai_picture';
    public const PRIORITY_EDITOR = 100;
    private const VARIANTS_KEPT = 86400;
    private const FIXED_AVOID = 'text, letters, numbers or logos';

    /**
     * @param \Closure(string): OpenAiProvider $provider makes the provider for a key (tests give a fake transport)
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private JobRunner $runner,
        private AiSettings $settings,
        private AiUsage $usage,
        private MediaLibrary $media,
        private SystemDatabase $db,
        private string $storageDir,
        private string $publicDir,
        private \Closure $provider,
        private ?\Closure $clock = null,
    ) {
    }

    public function register(): void
    {
        $this->runner->register(self::KIND, fn(array $payload, array $job): array => $this->make($payload, $job), 420);
    }

    /**
     * The prompt sent to the provider, always in this order: the house style as written, the subject, then what not to show (the
     * site's list, the request's own lines, and the fixed line about text). With reference pictures, a line saying to follow their
     * style and not copy them comes first.
     * @param string[] $avoid
     */
    public static function prompt(string $houseStyle, string $subject, array $avoid, bool $withReferences): string
    {
        $avoid = array_values(array_unique(array_filter(array_map('trim', array_merge($avoid, [self::FIXED_AVOID])))));
        $parts = [];
        if ($withReferences) {
            $parts[] = 'Follow the style of the reference pictures (palette, medium, light, composition). Do not copy their subjects.';
        }
        if (trim($houseStyle) !== '') {
            $parts[] = trim($houseStyle);
        }
        $parts[] = 'Subject: ' . trim($subject);
        $parts[] = 'Do not show: ' . implode('; ', $avoid) . '.';
        return implode("\n", $parts);
    }

    /** One sentence for alt text, from the subject. */
    public static function altFrom(string $subject): string
    {
        $subject = trim((string)preg_replace('/\s+/', ' ', $subject));
        if (preg_match('/^(.{20,}?[.!?])(\s|$)/u', $subject, $m) === 1) {
            $subject = $m[1];
        }
        $subject = rtrim(mb_substr($subject, 0, 200), ' .') ;
        return $subject === '' ? '' : mb_strtoupper(mb_substr($subject, 0, 1)) . mb_substr($subject, 1) . '.';
    }

    /**
     * Queues pictures for a subject. Returns the job's id, or a reason it cannot be queued.
     * @param string[] $avoid lines added to the site's list for this request only
     * @return array{ok: bool, job: int, error: string}
     */
    public function request(string $subject, array $avoid, int $variants, string $source, string $by, string $entry = ''): array
    {
        $subject = trim(mb_substr((string)preg_replace('/\s+/', ' ', $subject), 0, 1000));
        if ($subject === '') {
            return ['ok' => false, 'job' => 0, 'error' => 'Say what the picture shows.'];
        }
        if (!$this->settings->hasKey()) {
            return ['ok' => false, 'job' => 0, 'error' => 'Add an OpenAI key first, in Manage > AI publishing > Settings.'];
        }
        $blocked = $this->blocked(max(1, $variants));
        if ($blocked !== '') {
            return ['ok' => false, 'job' => 0, 'error' => $blocked];
        }
        $job = $this->runner->enqueue(self::KIND, [
            'subject' => $subject,
            'avoid' => array_slice(AiSettings::lines(implode("\n", $avoid), 20), 0, 20),
            'variants' => max(1, min(4, $variants)),
            'source' => in_array($source, AiUsage::SOURCES, true) ? $source : 'editor',
            'by' => mb_substr($by, 0, 120),
            'entry' => mb_substr($entry, 0, 300),
        ], self::PRIORITY_EDITOR, null, 2);
        return $job > 0 ? ['ok' => true, 'job' => $job, 'error' => ''] : ['ok' => false, 'job' => 0, 'error' => 'The site database is not available.'];
    }

    /**
     * Where a job stands, for the screen that waits on it.
     * @return array{status: string, error: string, variants: int, wait: int}|null
     */
    public function status(int $jobId): ?array
    {
        $job = $this->runner->get($jobId);
        if ($job === null || $job['kind'] !== self::KIND) {
            return null;
        }
        $files = $job['status'] === JobRunner::DONE ? array_filter((array)($job['result']['files'] ?? []), fn(string $f): bool => $this->variantPath($jobId, $f) !== null) : [];
        return [
            'status' => (string)$job['status'],
            'error' => (string)$job['error'],
            'variants' => count($files),
            'wait' => $job['status'] === JobRunner::QUEUED ? max(0, $job['run_after'] - $this->now()) : 0,
        ];
    }

    /** The file of a variant, or null when it is not there (any more). */
    public function variantPath(int $jobId, string|int $file): ?string
    {
        $job = $this->runner->get($jobId);
        if ($job === null || $job['kind'] !== self::KIND || $job['status'] !== JobRunner::DONE) {
            return null;
        }
        $files = array_values((array)($job['result']['files'] ?? []));
        $name = is_int($file) ? ($files[$file] ?? '') : (in_array($file, $files, true) ? $file : '');
        if ($name === '' || preg_match('/^\d\.(webp|jpg|png)$/', $name) !== 1) {
            return null;
        }
        $path = $this->variantDir($jobId) . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /**
     * Keeps one variant in the media library and returns its address. The same variant picked twice gives the same picture.
     * @return array{ok: bool, path: string, id: string, alt: string, error: string}
     */
    public function pick(int $jobId, int $index, string $by): array
    {
        $file = $this->variantPath($jobId, $index);
        $job = $this->runner->get($jobId);
        if ($file === null || $job === null) {
            return ['ok' => false, 'path' => '', 'id' => '', 'alt' => '', 'error' => 'This picture is no longer kept; make it again.'];
        }
        $id = substr((string)hash_file('sha256', $file), 0, 16);
        $alt = self::altFrom((string)$job['payload']['subject']);
        $this->media->ensureDirectories();
        $existing = $this->media->find($id);
        if ($existing === null) {
            $copy = $this->storageDir . '/ai/pick-' . bin2hex(random_bytes(6)) . '.' . pathinfo($file, PATHINFO_EXTENSION);
            if (!@copy($file, $copy)) {
                return ['ok' => false, 'path' => '', 'id' => '', 'alt' => '', 'error' => 'The picture could not be copied.'];
            }
            $name = mb_substr(Slug::fromText((string)$job['payload']['subject']) ?: 'ai-picture', 0, 60) . '.' . pathinfo($file, PATHINFO_EXTENSION);
            try {
                $item = $this->media->import($copy, $name, $id, $alt, $by . ' (AI)');
            } catch (\Throwable $e) {
                @unlink($copy);
                return ['ok' => false, 'path' => '', 'id' => '', 'alt' => '', 'error' => $e->getMessage()];
            }
            @unlink($copy);
            if ((string)($item['direct_url'] ?? '') === '' || $this->media->find($id) === null) {
                return ['ok' => false, 'path' => '', 'id' => '', 'alt' => '', 'error' => 'The picture could not be kept in the media library.'];
            }
            $this->media->updateTags($id, 'generated', $alt);
            $this->remember($id, $job);
        } else {
            $item = $existing;
        }
        return ['ok' => true, 'path' => (string)$item['direct_url'], 'id' => (string)$item['id'], 'alt' => (string)($item['alt'] ?? $alt), 'error' => ''];
    }

    /** Why no picture can be made now ('' when one can): paused, or a monthly cap reached with "pause". */
    public function blocked(int $pictures = 1): string
    {
        $s = $this->settings->all();
        if ($s['paused']) {
            return 'AI publishing is paused' . ($s['paused_reason'] !== '' ? ': ' . $s['paused_reason'] : '.') . ' Resume it in Manage > AI publishing.';
        }
        if ($s['cap_action'] !== 'pause') {
            return '';
        }
        $month = $this->usage->month($this->now());
        if ($s['monthly_picture_cap'] !== null && $month['pictures'] + $pictures > $s['monthly_picture_cap']) {
            return 'This month\'s picture cap (' . $s['monthly_picture_cap'] . ') is reached. Raise it in Manage > AI publishing > Settings.';
        }
        if ($s['monthly_cost_cap'] !== null && $month['cost'] >= $s['monthly_cost_cap']) {
            return 'This month\'s cost cap ($' . number_format($s['monthly_cost_cap'], 2) . ') is reached. Raise it in Manage > AI publishing > Settings.';
        }
        return '';
    }

    /** Removes variants older than a day. */
    public function cleanup(): void
    {
        $base = $this->storageDir . '/ai/variants';
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ((int)@filemtime($dir) < $this->now() - self::VARIANTS_KEPT) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        }
    }

    /**
     * The job's handler.
     * @param array<string, mixed> $payload @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function make(array $payload, array $job): array
    {
        $this->cleanup();
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            throw JobFailure::final('This server cannot call OpenAI: PHP\'s curl extension is missing or switched off. Ask the host to turn it on.');
        }
        $s = $this->settings->all();
        if ($s['openai_key'] === '') {
            throw JobFailure::final('There is no OpenAI key. Add one in Manage > AI publishing > Settings.');
        }
        $variants = max(1, min(4, (int)($payload['variants'] ?? 1)));
        $blocked = $this->blocked($variants);
        if ($blocked !== '') {
            throw JobFailure::final($blocked);
        }
        $references = [];
        foreach ($s['references'] as $path) {
            $file = $this->publicDir . $path;
            if (is_file($file) && SafeHttp::imageKind($file) !== '') {
                $references[] = $file;
            }
        }
        $prompt = self::prompt($s['house_style'], (string)($payload['subject'] ?? ''), array_merge($s['picture_avoid'], (array)($payload['avoid'] ?? [])), $references !== []);
        $size = AiSettings::size($s['aspect']);
        $model = $s['image_model'];
        $answer = ($this->provider)($s['openai_key'])->picture($model, $prompt, $references, $size, $s['quality'], $s['format'], $variants);
        $source = (string)($payload['source'] ?? 'editor');
        $cost = $this->usage->record($source, 'openai', $model, 'picture', $answer['usage'], $s['prices'], $answer['ok'], 'job:' . $job['id'], (string)($payload['entry'] ?? ''), count($answer['images']));

        if (!$answer['ok']) {
            if ($answer['kind'] === AiHttp::NO_CREDIT) {
                $this->settings->setPaused(true, 'OpenAI has no credit left. Top up at platform.openai.com > Settings > Billing, then resume');
                throw JobFailure::final('OpenAI has no credit left. AI publishing is paused until you top up and press Resume.');
            }
            if ($answer['model_gone']) {
                throw JobFailure::final('OpenAI no longer offers the model "' . $model . '". Choose another picture model in Manage > AI publishing > Settings.');
            }
            if ($answer['kind'] === AiHttp::AUTH) {
                throw JobFailure::final('OpenAI did not accept the key for this model. Check the key, and that the organization is verified for picture models.');
            }
            if (AiHttp::isRetryable($answer['kind'])) {
                throw JobFailure::later($answer['error'], (int)($answer['retry_after'] ?? 0));
            }
            throw JobFailure::final($answer['error']);
        }

        $dir = $this->variantDir((int)$job['id']);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw JobFailure::final('The folder for the pictures could not be made (storage/ai).');
        }
        $extension = $s['format'] === 'jpeg' ? 'jpg' : $s['format'];
        $files = [];
        foreach (array_values($answer['images']) as $i => $bytes) {
            $name = $i . '.' . $extension;
            file_put_contents($dir . '/' . $name, self::cut($bytes, $s['aspect'], $s['format']));
            $files[] = $name;
        }
        return ['files' => $files, 'prompt' => $prompt, 'model' => $model, 'size' => $size, 'quality' => $s['quality'], 'aspect' => $s['aspect'],
            'references' => $s['references'], 'cost' => $cost];
    }

    /**
     * The picture cut to the exact ratio around its centre and written again in its format, when GD can read it; otherwise as it came.
     */
    public static function cut(string $bytes, string $aspect, string $format): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return $bytes;
        }
        [$rw, $rh] = AiSettings::ratio($aspect);
        $w = imagesx($image);
        $h = imagesy($image);
        $targetW = $w;
        $targetH = (int)round($w * $rh / $rw);
        if ($targetH > $h) {
            $targetH = $h;
            $targetW = (int)round($h * $rw / $rh);
        }
        if (abs($targetW - $w) <= 2 && abs($targetH - $h) <= 2) {
            return $bytes;
        }
        $cut = imagecrop($image, ['x' => intdiv($w - $targetW, 2), 'y' => intdiv($h - $targetH, 2), 'width' => $targetW, 'height' => $targetH]);
        if ($cut === false) {
            return $bytes;
        }
        ob_start();
        $written = match ($format) {
            'jpeg' => imagejpeg($cut, null, 90),
            'png' => imagepng($cut, null, 6),
            default => function_exists('imagewebp') ? imagewebp($cut, null, 88) : false,
        };
        $out = (string)ob_get_clean();
        return $written && $out !== '' ? $out : $bytes;
    }

    /** @param array<string, mixed> $job */
    private function remember(string $mediaId, array $job): void
    {
        if (!$this->db->isAvailable()) {
            return;
        }
        $result = (array)($job['result'] ?? []);
        $this->db->connection()->prepare('INSERT OR REPLACE INTO ai_pictures (media_id, job_id, entry, subject, prompt, provider, model, size, quality, references_used, made_by, made_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$mediaId, (int)$job['id'], (string)($job['payload']['entry'] ?? ''), (string)($job['payload']['subject'] ?? ''), (string)($result['prompt'] ?? ''),
                'openai', (string)($result['model'] ?? ''), (string)($result['size'] ?? ''), (string)($result['quality'] ?? ''),
                json_encode($result['references'] ?? []), (string)($job['payload']['by'] ?? ''), $this->now()]);
    }

    private function variantDir(int $jobId): string
    {
        return $this->storageDir . '/ai/variants/' . $jobId;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
