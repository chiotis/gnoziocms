<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Manage > AI publishing (docs/ai-publishing-plan.md, section 1): the Instructions, Settings and Usage tabs of phase 1. Schedules
 * and Runs come with phase 3. The key is never sent to the browser: the screen gets AiSettings::view().
 */
final class AiPublishingAdmin
{
    public const TABS = ['schedules' => 'Schedules', 'instructions' => 'Instructions', 'runs' => 'Runs', 'settings' => 'Settings', 'usage' => 'Usage'];

    /**
     * @param \Closure(string): OpenAiProvider $provider
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log as App::logActivity
     * @param (\Closure(): array{types: string[], languages: array<int, array{code: string, name: string}>, terms: \Closure(string, string): array<string, array<int, array{id: string, label: string}>>})|null $site
     *   what a schedule may write: content types, languages, and the terms of a type
     */
    public function __construct(
        private AiSettings $settings,
        private AiUsage $usage,
        private AiPictures $pictures,
        private JobRunner $runner,
        private \Closure $provider,
        private \Closure $log,
        private ?AiSchedules $schedules = null,
        private ?AiWriter $writer = null,
        private ?\DateTimeZone $zone = null,
        private ?\Closure $site = null,
    ) {
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function screen(array $get, bool $canSettings): array
    {
        $view = $this->settings->view();
        $tab = (string)($get['tab'] ?? '');
        if (!isset(self::TABS[$tab])) {
            $tab = $view['has_key'] ? 'schedules' : ($canSettings ? 'settings' : 'instructions');
        }
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($get['month'] ?? '')) === 1 ? (string)$get['month'] : date('Y-m');
        $tests = array_map(fn(array $job): array => [
            'id' => (int)$job['id'],
            'subject' => (string)($job['payload']['subject'] ?? ''),
            'status' => (string)$job['status'],
            'error' => (string)$job['error'],
            'variants' => count((array)($job['result']['files'] ?? [])),
            'cost' => $job['result']['cost'] ?? null,
            'when' => (int)$job['created_at'],
        ], array_values(array_filter($this->runner->list(AiPictures::KIND, [], 20), static fn(array $job): bool => ($job['payload']['source'] ?? '') === 'test')));
        return [
            'tab' => $tab,
            'tabs' => self::TABS,
            'ai' => $view,
            'can_settings' => $canSettings,
            'blocked' => $this->pictures->blocked(),
            'aspects' => AiSettings::ASPECTS,
            'qualities' => AiSettings::QUALITIES,
            'formats' => AiSettings::FORMATS,
            'price_fields' => AiSettings::PRICE_FIELDS,
            'max_references' => AiSettings::MAX_REFERENCES,
            'tests' => array_slice($tests, 0, 5),
            'watch_job' => (int)($get['job'] ?? 0),
            'usage' => $tab === 'usage' ? $this->usageScreen($month) : null,
            'runs' => $tab === 'runs' ? $this->runs((string)($get['show'] ?? '')) : null,
            'schedules' => $tab === 'schedules' ? $this->schedulesScreen($get) : null,
            'status_modes' => ['at_time' => 'Published at its time', 'when_ready' => 'Published as soon as it is ready', 'draft' => 'Kept as a draft for review'],
            'day_names' => AiSchedules::DAY_NAMES,
            'show' => in_array($get['show'] ?? '', ['failed', 'live'], true) ? (string)$get['show'] : '',
            'this_month' => $this->usage->month(),
            'done' => (string)($get['done'] ?? ''),
            'message' => trim((string)($get['msg'] ?? '')),
        ];
    }

    /**
     * A form of the module. Returns where to go next and what to say.
     * @param array<string, mixed> $post @param array<string, mixed>|null $user
     * @return array{ok: bool, message: string, tab: string, job: int}
     */
    public function act(array $post, ?array $user, bool $canSettings): array
    {
        $action = (string)($post['ai_action'] ?? '');
        $who = (string)($user['username'] ?? '');
        $result = static fn(bool $ok, string $message, string $tab, int $job = 0): array => ['ok' => $ok, 'message' => $message, 'tab' => $tab, 'job' => $job];
        if (in_array($action, ['save_settings', 'check_key'], true) && !$canSettings) {
            return $result(false, 'Only people who may change the site settings can change the key, models, limits and prices.', 'settings');
        }
        switch ($action) {
            case 'save_settings':
                $hadKey = $this->settings->hasKey();
                $this->settings->saveSettings($post);
                ($this->log)('ai.settings', 'info', 'ai', null, 'AI publishing settings saved.', ['key_changed' => trim((string)($post['openai_key'] ?? '')) !== '' || ($post['clear_key'] ?? '') === '1']);
                return $result(true, !$hadKey && $this->settings->hasKey() ? 'Settings saved. Press Check key to see the models this key can use.' : 'Settings saved.', 'settings');
            case 'check_key':
                return $this->checkKey($result);
            case 'save_writing':
                $this->settings->saveWritingInstructions($post);
                ($this->log)('ai.instructions', 'info', 'ai', null, 'AI publishing writing instructions saved.', []);
                return $result(true, 'Writing instructions saved.', 'instructions');
            case 'save_schedule':
            case 'schedule_toggle':
            case 'schedule_run':
            case 'schedule_duplicate':
            case 'schedule_delete':
                return $this->scheduleAction($action, $post, $who, $result);
            case 'save_instructions':
                $this->settings->savePictureInstructions($post);
                ($this->log)('ai.instructions', 'info', 'ai', null, 'AI publishing picture instructions saved.', []);
                return $result(true, 'Instructions saved.', 'instructions');
            case 'pause':
                $this->settings->setPaused(true, 'Paused by ' . ($who !== '' ? $who : 'an administrator'));
                ($this->log)('ai.pause', 'warning', 'ai', null, 'AI publishing paused.', []);
                return $result(true, 'Paused. No picture is made until you resume.', (string)($post['tab'] ?? 'instructions'));
            case 'resume':
                $this->settings->setPaused(false);
                ($this->log)('ai.resume', 'info', 'ai', null, 'AI publishing resumed.', []);
                return $result(true, 'Resumed.', (string)($post['tab'] ?? 'instructions'));
            case 'retry_job':
                $ok = $this->runner->retry((int)($post['job'] ?? 0));
                return $result($ok, $ok ? 'Back in the queue.' : 'This one cannot be tried again (it is waiting already, or another for the same entry is).', 'runs');
            case 'cancel_job':
                $ok = $this->runner->cancel((int)($post['job'] ?? 0));
                return $result($ok, $ok ? 'Cancelled.' : 'It has finished already.', 'runs');
            case 'cancel_waiting':
                $n = 0;
                foreach (array_merge($this->runner->list(AiPictures::KIND, [JobRunner::QUEUED], 500), $this->runner->list(AiWriter::KIND, [JobRunner::QUEUED], 500)) as $job) {
                    $n += $this->runner->cancel((int)$job['id']) ? 1 : 0;
                }
                ($this->log)('ai.cancel_waiting', 'warning', 'ai', null, 'Waiting AI pictures cancelled.', ['count' => $n]);
                return $result(true, $n . ' waiting picture' . ($n === 1 ? '' : 's') . ' cancelled.', 'runs');
            case 'test_picture':
                $subject = trim((string)($post['subject'] ?? '')) ?: 'A cup of coffee on a wooden table by a window.';
                $request = $this->pictures->request($subject, [], 1, 'test', $who);
                return $result($request['ok'], $request['ok'] ? 'Making the test picture…' : $request['error'], 'instructions', $request['job']);
        }
        return $result(false, 'Unknown action.', 'instructions');
    }

    /** The usage of a month as CSV, one call per row. */
    public function usageCsv(string $month): string
    {
        [$from, $to] = AiUsage::monthRange($month);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['time', 'source', 'ref', 'entry', 'provider', 'model', 'kind', 'ok', 'text_input', 'cached_input', 'cache_write', 'image_input', 'text_output', 'reasoning', 'image_output', 'searches', 'pictures', 'estimated_cost_usd'], ',', '"', '');
        foreach ($this->usage->rows($from, $to) as $row) {
            fputcsv($out, [date('Y-m-d H:i:s', (int)$row['at']), $row['source'], $row['ref'], $row['entry'], $row['provider'], $row['model'], $row['kind'], $row['ok'],
                $row['text_input'], $row['cached_input'], $row['cache_write'], $row['image_input'], $row['text_output'], $row['reasoning'], $row['image_output'],
                $row['searches'], $row['pictures'], $row['cost'] === null ? '' : number_format((float)$row['cost'], 6, '.', '')], ',', '"', '');
        }
        rewind($out);
        return (string)stream_get_contents($out);
    }

    /**
     * @param \Closure(bool, string, string, int=): array{ok: bool, message: string, tab: string, job: int} $result
     * @return array{ok: bool, message: string, tab: string, job: int}
     */
    private function checkKey(\Closure $result): array
    {
        if (!$this->settings->hasKey()) {
            return $result(false, 'Add a key first.', 'settings');
        }
        $models = ($this->provider)($this->settings->key())->models();
        if (!$models['ok']) {
            return $result(false, $models['error'], 'settings');
        }
        $this->settings->setModels($models['pictures'], $models['writing'], time());
        $s = $this->settings->all();
        $notes = [];
        if (!in_array($s['image_model'], $models['pictures'], true)) {
            $notes[] = 'the picture model "' . $s['image_model'] . '" is not offered to this key; choose one of the list';
        }
        if (!in_array($s['text_model'], $models['writing'], true)) {
            $notes[] = 'the writing model "' . $s['text_model'] . '" is not offered to this key; choose one of the list';
        }
        return $result($notes === [], 'The key works: ' . count($models['pictures']) . ' picture models and ' . count($models['writing']) . ' writing models.'
            . ($notes === [] ? '' : ' But ' . implode(', and ', $notes) . '.'), 'settings');
    }

    /**
     * The picture jobs, newest first: what made each (editor, test, bulk), its entry and subject, how it went and what it cost.
     * @return array<string, mixed>
     */
    private function runs(string $show): array
    {
        $statuses = match ($show) {
            'failed' => [JobRunner::FAILED],
            'live' => [JobRunner::QUEUED, JobRunner::RUNNING],
            default => [],
        };
        $rows = array_map(static function (array $job): array {
            $post = $job['kind'] === AiWriter::KIND;
            [$type, $slug, $lang] = array_pad(explode('/', (string)($post ? ($job['result']['entry'] ?? '') : ($job['payload']['entry'] ?? '')), 3), 3, '');
            return [
                'id' => (int)$job['id'],
                'kind' => $post ? 'post' : 'picture',
                'source' => $post ? 'schedule' : (string)($job['payload']['source'] ?? 'editor'),
                'by' => (string)($job['payload']['by'] ?? ''),
                'type' => $type, 'slug' => $slug, 'lang' => $lang,
                'subject' => $post ? (string)($job['result']['title'] ?? ('Schedule: ' . ($job['payload']['name'] ?? ''))) : (string)($job['result']['subject'] ?? ($job['payload']['subject'] ?? '')),
                'status' => (string)$job['status'],
                'error' => (string)$job['error'],
                'attempts' => (int)$job['attempts'],
                'pictures' => count((array)($job['result']['files'] ?? [])),
                'outcome' => (string)($job['result']['outcome'] ?? ''),
                'post_status' => (string)($job['result']['status'] ?? ''),
                'searches' => (int)($job['result']['searches'] ?? 0),
                'sources' => (int)($job['result']['sources'] ?? 0),
                'publish_at' => (string)($job['result']['publish_at'] ?? ''),
                'path' => (string)($job['result']['path'] ?? ''),
                'cost' => $job['result']['cost'] ?? null,
                'created' => (int)$job['created_at'],
                'finished' => (int)$job['finished_at'],
                'wait' => $job['status'] === JobRunner::QUEUED ? max(0, (int)$job['run_after'] - time()) : 0,
            ];
        }, $this->mergedJobs($statuses));
        return [
            'rows' => $rows,
            'waiting' => $this->runner->pending(AiPictures::KIND) + $this->runner->pending(AiWriter::KIND),
        ];
    }

    /**
     * Picture and post jobs together, newest first.
     * @param string[] $statuses
     * @return array<int, array<string, mixed>>
     */
    private function mergedJobs(array $statuses): array
    {
        $jobs = array_merge($this->runner->list(AiPictures::KIND, $statuses, 100), $this->runner->list(AiWriter::KIND, $statuses, 100));
        usort($jobs, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
        return array_slice($jobs, 0, 100);
    }

    /**
     * The Schedules tab: the list (each with its next run, its last run and its posts this month), and the form of one.
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    private function schedulesScreen(array $get): array
    {
        $site = $this->site !== null ? ($this->site)() : ['types' => [], 'languages' => [], 'terms' => static fn(): array => []];
        $zone = $this->zone ?? new \DateTimeZone(date_default_timezone_get());
        $monthStart = (int)strtotime(date('Y-m-01 00:00:00'));
        $list = [];
        foreach ($this->schedules?->all() ?? [] as $schedule) {
            $last = $this->schedules->lastRun($schedule['id']);
            $lastJob = $last !== null && $last['job_id'] > 0 ? $this->runner->get($last['job_id']) : null;
            $next = $this->schedules->next($schedule, $zone);
            $list[] = $schedule + [
                'next' => $next?->format('D j M, H:i'),
                'last' => $last,
                'last_status' => (string)($lastJob['status'] ?? ''),
                'last_error' => (string)($lastJob['error'] ?? ''),
                'month_posts' => $this->schedules->postsSince($schedule['id'], $monthStart),
            ];
        }
        $editId = (string)($get['edit'] ?? '');
        $editing = null;
        if ($editId === 'new') {
            $editing = ['id' => 0, 'name' => '', 'enabled' => true, 'settings' => AiSchedules::normalize([
                'days' => [1, 2, 3, 4, 5], 'time' => '08:00', 'type' => in_array('posts', $site['types'], true) ? 'posts' : ($site['types'][0] ?? ''),
                'lang' => $site['languages'][0]['code'] ?? '', 'prepare' => 30, 'status' => 'at_time',
            ])];
        } elseif (ctype_digit($editId)) {
            $editing = $this->schedules?->get((int)$editId);
        }
        $form = is_array($_SESSION['ai_schedule_form'] ?? null) ? $_SESSION['ai_schedule_form'] : null;
        unset($_SESSION['ai_schedule_form']);
        if ($editing !== null && $form !== null && (int)($form['id'] ?? -1) === (int)$editing['id']) {
            // The form as it was sent, after a save that was refused.
            $editing['name'] = (string)($form['name'] ?? '');
            $editing['settings'] = AiSchedules::normalize($form);
        }
        return [
            'list' => $list,
            'editing' => $editing,
            'types' => $site['types'],
            'languages' => $site['languages'],
            'taxonomies' => $editing !== null ? ($site['terms'])($editing['settings']['type'], $editing['settings']['lang']) : [],
            'zone' => $zone->getName(),
            'missed_window' => (int)$this->settings->all()['missed_window'],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param \Closure(bool, string, string, int=): array{ok: bool, message: string, tab: string, job: int} $result
     * @return array{ok: bool, message: string, tab: string, job: int, edit?: string}
     */
    private function scheduleAction(string $action, array $post, string $who, \Closure $result): array
    {
        if ($this->schedules === null || $this->writer === null) {
            return $result(false, 'Schedules are not available.', 'schedules');
        }
        $id = (int)($post['schedule'] ?? 0);
        switch ($action) {
            case 'save_schedule':
                $site = $this->site !== null ? ($this->site)() : ['types' => [], 'languages' => []];
                $saved = $this->schedules->save($id, $post, $site['types'], array_column($site['languages'], 'code'));
                if (!$saved['ok']) {
                    $_SESSION['ai_schedule_form'] = $post + ['id' => $id];
                    return ['ok' => false, 'message' => implode(' ', $saved['errors']), 'tab' => 'schedules', 'job' => 0, 'edit' => $id > 0 ? (string)$id : 'new'];
                }
                ($this->log)('ai.schedule_saved', 'info', 'ai_schedule', (string)$saved['id'], 'AI publishing schedule saved.', ['name' => (string)($post['name'] ?? '')]);
                return $result(true, 'Schedule saved.', 'schedules');
            case 'schedule_toggle':
                $schedule = $this->schedules->get($id);
                if ($schedule === null) {
                    return $result(false, 'There is no such schedule.', 'schedules');
                }
                $this->schedules->setEnabled($id, !$schedule['enabled']);
                return $result(true, $schedule['enabled'] ? 'Switched off: it does not run until you switch it on.' : 'Switched on.', 'schedules');
            case 'schedule_run':
                $run = $this->writer->runNow($id, $who);
                if ($run['ok']) {
                    ($this->log)('ai.schedule_run', 'info', 'ai_schedule', (string)$id, 'AI publishing schedule run by hand.', []);
                }
                return $result($run['ok'], $run['ok'] ? 'Writing the post now; it shows below. Keep this page open, or the cron job finishes it.' : $run['error'], $run['ok'] ? 'runs' : 'schedules', $run['job']);
            case 'schedule_duplicate':
                $copy = $this->schedules->duplicate($id);
                return $copy > 0 ? ['ok' => true, 'message' => 'Copied, switched off. Change what you need and switch it on.', 'tab' => 'schedules', 'job' => 0, 'edit' => (string)$copy]
                    : $result(false, 'There is no such schedule.', 'schedules');
            case 'schedule_delete':
                $ok = $this->schedules->delete($id);
                if ($ok) {
                    ($this->log)('ai.schedule_deleted', 'warning', 'ai_schedule', (string)$id, 'AI publishing schedule deleted.', []);
                }
                return $result($ok, $ok ? 'Deleted. The posts it wrote stay.' : 'There is no such schedule.', 'schedules');
        }
        return $result(false, 'Unknown action.', 'schedules');
    }

    /** @return array<string, mixed> */
    private function usageScreen(string $month): array
    {
        [$from, $to] = AiUsage::monthRange($month);
        [$prevFrom, $prevTo] = AiUsage::monthRange(date('Y-m', $from - 86400));
        $total = $this->usage->totals($from, $to)[0] ?? null;
        $previous = $this->usage->totals($prevFrom, $prevTo)[0] ?? null;
        $isCurrent = $month === date('Y-m');
        $forecast = null;
        if ($isCurrent && $total !== null) {
            $elapsed = max(1, (time() - $from) / 86400);
            $forecast = round($total['cost'] / $elapsed * (($to - $from) / 86400), 4);
        }
        $days = $this->usage->totals($from, $to, 'day');
        $max = max(0.000001, ...array_map(static fn(array $d): float => (float)$d['cost'], $days ?: [['cost' => 0]]));
        return [
            'month' => $month,
            'month_label' => date('F Y', $from),
            'previous_month' => date('Y-m', $prevFrom),
            'next_month' => $isCurrent ? '' : date('Y-m', $to),
            'is_current' => $isCurrent,
            'total' => $total,
            'previous' => $previous,
            'forecast' => $forecast,
            'by_kind' => $this->usage->totals($from, $to, 'kind'),
            'by_source' => $this->usage->totals($from, $to, 'source'),
            'by_model' => $this->usage->totals($from, $to, 'model'),
            'by_day' => array_map(static fn(array $d): array => $d + ['share' => (int)round((float)$d['cost'] / $max * 100)], $days),
        ];
    }
}
