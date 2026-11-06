<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Posts written on a timetable (docs/ai-publishing-plan.md, section 6). tick() finds the schedules' slots that are due, takes each
 * once (AiSchedules::claim) and queues an `ai_post` job. The job asks OpenAI for the post as JSON bound to a schema, built from the
 * general writing instructions, the facts about the site, what never to do, the schedule's own lines, the terms it may choose and
 * the titles it wrote before; checks the answer (once more with the problems if it falls short); saves it as a draft through the
 * editor's own save; queues its picture; and gives it the schedule's status: published at the slot's time (scheduled), published
 * at once, or left as a draft. With "publish only with a picture" the picture job gives the status when the picture is set.
 */
final class AiWriter
{
    public const KIND = 'ai_post';
    public const PRIORITY = 50;
    private const MIN_BODY = 300;

    /**
     * @param \Closure(string): OpenAiProvider $provider
     * @param \Closure(string, array<string, mixed>, string): array<string, mixed> $saveEntry saves a new entry the way the editor does
     *   (type, the editor's form fields, actor) and returns ContentEditor::save()'s answer
     * @param \Closure(string, string): array<string, array<int, array{id: string, label: string}>> $termsFor the taxonomies of a type
     *   with their terms, labelled in a language
     * @param \Closure(string, string, string, string, ?string, string): string $setStatus (type, slug, lang, status, publish_at, actor)
     * @param \Closure(string): string $languageName
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private JobRunner $runner,
        private AiSettings $settings,
        private AiUsage $usage,
        private AiSchedules $schedules,
        private AiPictures $pictures,
        private \Closure $provider,
        private \Closure $saveEntry,
        private \Closure $termsFor,
        private \Closure $setStatus,
        private \Closure $languageName,
        private \DateTimeZone $zone,
        private ?\Closure $clock = null,
    ) {
    }

    public function register(): void
    {
        $this->runner->register(self::KIND, fn(array $payload, array $job): array => $this->make($payload, $job), 420);
    }

    /** Queues the slots that are due. Returns how many. */
    public function tick(): int
    {
        if (!$this->settings->hasKey() || $this->settings->all()['paused']) {
            return 0;
        }
        $queued = 0;
        foreach ($this->schedules->due($this->zone, (int)$this->settings->all()['missed_window']) as $due) {
            $queued += $this->queue($due['schedule'], $due['slot'], $due['at'], false, '') ? 1 : 0;
        }
        return $queued;
    }

    /**
     * Writes a schedule's post now, outside its timetable, with its usual status (at its time means now).
     * @return array{ok: bool, job: int, error: string}
     */
    public function runNow(int $scheduleId, string $by): array
    {
        $schedule = $this->schedules->get($scheduleId);
        if ($schedule === null) {
            return ['ok' => false, 'job' => 0, 'error' => 'There is no such schedule.'];
        }
        if (!$this->settings->hasKey()) {
            return ['ok' => false, 'job' => 0, 'error' => 'Add an OpenAI key first, in Manage > AI publishing > Settings.'];
        }
        $blocked = $this->pictures->blocked(0);
        if ($blocked !== '') {
            return ['ok' => false, 'job' => 0, 'error' => $blocked];
        }
        $now = $this->now();
        // Every Run now is a run of its own, however quickly they follow.
        $job = $this->queue($schedule, 'now ' . date('Y-m-d H:i:s', $now) . ' ' . bin2hex(random_bytes(3)), $now, true, $by);
        return $job ? ['ok' => true, 'job' => $job, 'error' => ''] : ['ok' => false, 'job' => 0, 'error' => 'It is being written already.'];
    }

    /**
     * The status a post ends with: at its time is "scheduled" for the slot (published at once when the slot has come), when ready is
     * "published", draft is "draft".
     * @return array{status: string, publish_at: ?string}
     */
    public static function finalStatus(string $mode, int $slotAt, int $now, \DateTimeZone $zone): array
    {
        return match ($mode) {
            'draft' => ['status' => 'draft', 'publish_at' => null],
            'when_ready' => ['status' => 'published', 'publish_at' => null],
            default => $slotAt > $now + 60
                ? ['status' => ScheduledPublishing::STATUS, 'publish_at' => (new \DateTimeImmutable('@' . $slotAt))->setTimezone($zone)->format(ScheduledPublishing::FORMAT)]
                : ['status' => 'published', 'publish_at' => null],
        };
    }

    /**
     * The JSON schema the answer is bound to. Terms appear only for the taxonomies the schedule lets the model choose from, each an
     * array of the existing term ids.
     * @param array<string, string[]> $choose taxonomy => term ids
     * @return array<string, mixed>
     */
    public static function schema(array $choose): array
    {
        $string = static fn(string $description): array => ['type' => 'string', 'description' => $description];
        $properties = [
            'title' => $string('The title of the post.'),
            'excerpt' => $string('One or two sentences that say what the reader gets.'),
            'body' => $string('The post in Markdown, without the title at the top and without HTML.'),
            'seo_title' => $string('A title for search results, up to 60 characters.'),
            'seo_description' => $string('A description for search results, about 150 characters.'),
            'image_brief' => $string('What the main picture shows, in one or two sentences, following the picture guide. No style words and no text in the picture.'),
        ];
        if ($choose !== []) {
            $terms = [];
            foreach ($choose as $taxonomy => $ids) {
                $terms[$taxonomy] = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_values($ids)], 'description' => 'One to three ' . $taxonomy . ' from the list.'];
            }
            $properties['terms'] = ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($terms), 'properties' => $terms];
        }
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    /**
     * The problems that keep an answer from becoming a post ('' when there are none).
     * @param array<string, mixed> $answer @param array<string, string[]> $choose
     * @return string[]
     */
    public static function problems(array $answer, array $choose): array
    {
        $problems = [];
        $title = trim((string)($answer['title'] ?? ''));
        $body = trim((string)($answer['body'] ?? ''));
        if ($title === '') {
            $problems[] = 'the title is empty';
        } elseif (mb_strlen($title) > 200) {
            $problems[] = 'the title is longer than 200 characters';
        }
        if (mb_strlen($body) < self::MIN_BODY) {
            $problems[] = 'the body is too short (at least ' . self::MIN_BODY . ' characters)';
        }
        if (preg_match('/<(script|iframe|style|object|embed)\b/i', $body) === 1) {
            $problems[] = 'the body has HTML; write Markdown only';
        }
        foreach ($choose as $taxonomy => $ids) {
            $chosen = (array)($answer['terms'][$taxonomy] ?? []);
            if (array_diff(array_map('strval', $chosen), $ids) !== []) {
                $problems[] = $taxonomy . ' must come from the list';
            }
        }
        return $problems;
    }

    /** @param array<string, mixed> $schedule */
    private function queue(array $schedule, string $slot, int $at, bool $manual, string $by): int
    {
        if (!$this->schedules->claim($schedule['id'], $slot)) {
            return 0;
        }
        $job = $this->runner->enqueue(self::KIND, ['schedule' => $schedule['id'], 'name' => $schedule['name'], 'slot' => $slot, 'at' => $at, 'manual' => $manual, 'by' => mb_substr($by, 0, 120)],
            self::PRIORITY, 'post:' . $schedule['id'] . ':' . $slot, 2);
        $this->schedules->attachJob($schedule['id'], $slot, $job);
        return $job;
    }

    /**
     * The job's handler.
     * @param array<string, mixed> $payload @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function make(array $payload, array $job): array
    {
        $schedule = $this->schedules->get((int)($payload['schedule'] ?? 0));
        if ($schedule === null) {
            throw JobFailure::final('The schedule was deleted.');
        }
        if (!$schedule['enabled'] && !($payload['manual'] ?? false)) {
            throw JobFailure::final('The schedule was switched off before it ran.');
        }
        $s = $this->settings->all();
        if ($s['openai_key'] === '') {
            throw JobFailure::final('There is no OpenAI key. Add one in Manage > AI publishing > Settings.');
        }
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            throw JobFailure::final('This server cannot call OpenAI: PHP\'s curl extension is missing or switched off. Ask the host to turn it on.');
        }
        $blocked = $this->pictures->blocked(0);
        if ($blocked !== '') {
            throw JobFailure::final($blocked);
        }
        $c = $schedule['settings'];
        $type = $c['type'];
        $lang = $c['lang'];
        $taxonomies = ($this->termsFor)($type, $lang);
        $choose = [];
        foreach ($c['choose'] as $taxonomy) {
            if (($taxonomies[$taxonomy] ?? []) !== []) {
                $choose[$taxonomy] = array_column($taxonomies[$taxonomy], 'id');
            }
        }
        [$instructions, $input] = $this->request($schedule, $s, $taxonomies, $choose);
        $model = $c['model'] !== '' ? $c['model'] : $s['text_model'];
        $schema = self::schema($choose);
        $ref = 'job:' . $job['id'];
        $provider = ($this->provider)($s['openai_key']);

        $answer = null;
        $problems = [];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $prompt = $attempt === 1 ? $input : $input . "\n\nYour previous answer could not be used: " . implode('; ', $problems) . '. Write the post again, keeping to the rules.';
            $r = $provider->write($model, $instructions, $prompt, 'post', $schema);
            $this->usage->record('schedule', 'openai', $model, 'writing', $r['usage'], $s['prices'], $r['ok'], $ref);
            if (!$r['ok']) {
                $this->fail($r, $model);
            }
            $problems = self::problems($r['data'], $choose);
            if ($problems === []) {
                $answer = $r['data'];
                break;
            }
        }
        if ($answer === null) {
            throw JobFailure::final('The answer could not be used twice: ' . implode('; ', $problems) . '.');
        }

        // Saved as a draft first; the status comes once the picture is there (or at once without one).
        $terms = $c['terms'];
        foreach ($choose as $taxonomy => $ids) {
            $terms[$taxonomy] = array_values(array_unique(array_merge($terms[$taxonomy] ?? [], array_map('strval', (array)($answer['terms'][$taxonomy] ?? [])))));
        }
        $terms = array_intersect_key($terms, $taxonomies);
        $byline = $c['byline'] !== '' ? $c['byline'] : $s['byline'];
        $actor = 'AI publishing: ' . $schedule['name'];
        $front = ['generated_by' => $actor, 'ai_schedule' => $schedule['id']];
        $saved = ($this->saveEntry)($type, [
            'title' => trim((string)$answer['title']),
            'slug' => '',
            'lang' => $lang,
            'status' => 'draft',
            'publish_at' => '',
            'visible' => '1',
            'date' => '',
            'author' => $byline,
            'excerpt' => trim((string)$answer['excerpt']),
            'body' => trim((string)$answer['body']),
            'frontmatter' => \Symfony\Component\Yaml\Yaml::dump($front, 4, 2),
            'taxonomy_terms' => $terms,
            'translation_id' => '',
            'seo_title' => mb_substr(trim((string)$answer['seo_title']), 0, 70),
            'seo_description' => mb_substr(trim((string)$answer['seo_description']), 0, 180),
        ], $actor);
        $entry = $type . '/' . $saved['slug'] . '/' . $saved['lang'];
        $this->usage->attachEntry($ref, $entry);
        $this->schedules->recordPost($schedule['id'], (string)$payload['slot'], $entry, (string)$answer['title']);

        $final = self::finalStatus($c['status'], (int)($payload['at'] ?? $this->now()), $this->now(), $this->zone);
        $pictureJob = 0;
        $waiting = false;
        if (!$c['no_picture']) {
            $brief = trim((string)$answer['image_brief']) ?: AiPictures::subjectFrom((string)$answer['title'], (string)$answer['excerpt'], '');
            $picture = $this->pictures->requestForEntry($entry, $brief, $c['picture_avoid'], (string)($payload['by'] ?: $schedule['name']), $c['picture_required'] ? $final + ['actor' => $actor] : null);
            $pictureJob = $picture['job'];
            $waiting = $c['picture_required'] && $picture['ok'];
            if ($c['picture_required'] && !$picture['ok']) {
                throw JobFailure::final('The post was saved as a draft (' . $entry . '), but its picture could not be queued: ' . $picture['error']);
            }
        }
        if (!$waiting && $final['status'] !== 'draft') {
            ($this->setStatus)($type, $saved['slug'], $saved['lang'], $final['status'], $final['publish_at'], $actor);
        }
        return [
            'entry' => $entry, 'title' => (string)$answer['title'], 'status' => $waiting ? 'draft until the picture is set' : $final['status'],
            'publish_at' => $final['publish_at'], 'picture_job' => $pictureJob, 'words' => str_word_count(strip_tags((string)$answer['body'])),
            'model' => $model, 'subject' => (string)$answer['title'],
        ];
    }

    /**
     * The two messages: the developer's (how every post is written) and the user's (this schedule's post).
     * @param array<string, mixed> $schedule @param array<string, mixed> $s
     * @param array<string, array<int, array{id: string, label: string}>> $taxonomies @param array<string, string[]> $choose
     * @return array{0: string, 1: string}
     */
    private function request(array $schedule, array $s, array $taxonomies, array $choose): array
    {
        $c = $schedule['settings'];
        $avoid = array_values(array_unique(array_merge($s['text_avoid'], $c['text_avoid'])));
        $language = ($this->languageName)($c['lang']);
        $developer = [trim($s['writing_instructions'])];
        if (trim($s['site_facts']) !== '') {
            $developer[] = "About the site (use only where it fits):\n" . trim($s['site_facts']);
        }
        if ($avoid !== []) {
            $developer[] = "Never do any of these:\n- " . implode("\n- ", $avoid);
        }
        $developer[] = 'Write in ' . $language . ' (' . $c['lang'] . '). Answer only with the JSON asked for. The body is Markdown, without the title at its top and without HTML. '
            . 'image_brief says what the main picture shows, following the picture guide, in one or two sentences; no style words and no text in the picture.';

        $user = ['Write one new post for "' . $schedule['name'] . '".', "What it is about:\n" . trim($c['content'])];
        $user[] = 'Picture guide: ' . (trim($c['image']) !== '' ? trim($c['image']) : 'a picture that fits the post.');
        foreach ($choose as $taxonomy => $ids) {
            $labels = array_map(static fn(array $t): string => $t['id'] . ' (' . $t['label'] . ')', $taxonomies[$taxonomy]);
            $user[] = 'Choose one to three ' . $taxonomy . ' that fit, from: ' . implode(', ', $labels) . '.';
        }
        $titles = $this->schedules->recentTitles($schedule['id'], 30);
        if ($titles !== []) {
            $user[] = "Do not repeat a topic already written by this schedule:\n- " . implode("\n- ", $titles);
        }
        $user[] = 'Today is ' . (new \DateTimeImmutable('@' . $this->now()))->setTimezone($this->zone)->format('l j F Y') . '.';
        return [implode("\n\n", array_filter($developer)), implode("\n\n", $user)];
    }

    /** @param array<string, mixed> $r a failed OpenAiProvider::write() answer */
    private function fail(array $r, string $model): never
    {
        if ($r['kind'] === AiHttp::NO_CREDIT) {
            $this->settings->setPaused(true, 'OpenAI has no credit left. Top up at platform.openai.com > Settings > Billing, then resume');
            throw JobFailure::final('OpenAI has no credit left. AI publishing is paused until you top up and press Resume.');
        }
        if ($r['model_gone']) {
            throw JobFailure::final('OpenAI no longer offers the model "' . $model . '". Choose another writing model in Manage > AI publishing > Settings, or in the schedule.');
        }
        if ($r['kind'] === AiHttp::AUTH) {
            throw JobFailure::final('OpenAI did not accept the key for this model.');
        }
        if (AiHttp::isRetryable($r['kind'])) {
            throw JobFailure::later($r['error'], (int)($r['retry_after'] ?? 0));
        }
        throw JobFailure::final($r['error']);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
