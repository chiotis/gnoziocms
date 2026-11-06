<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Manage > AI publishing (docs/ai-publishing-plan.md, section 1): the Instructions, Settings and Usage tabs of phase 1. Schedules
 * and Runs come with phase 3. The key is never sent to the browser: the screen gets AiSettings::view().
 */
final class AiPublishingAdmin
{
    public const TABS = ['instructions' => 'Instructions', 'settings' => 'Settings', 'usage' => 'Usage'];

    /**
     * @param \Closure(string): OpenAiProvider $provider
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log as App::logActivity
     */
    public function __construct(
        private AiSettings $settings,
        private AiUsage $usage,
        private AiPictures $pictures,
        private JobRunner $runner,
        private \Closure $provider,
        private \Closure $log,
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
            $tab = $view['has_key'] ? 'instructions' : ($canSettings ? 'settings' : 'instructions');
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
