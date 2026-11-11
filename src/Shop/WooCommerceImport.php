<?php

declare(strict_types=1);

namespace GnozioCMS\Shop;

use GnozioCMS\JobRunner;
use GnozioCMS\WooCommerceImporter;

/**
 * Shop settings > Import & export: the WooCommerce import (WooCommerceImporter) as three steps on a screen instead of a command. Connect (the shop's
 * address, its read keys, the customers' CSV), review what the dry run found, import. Reading the shop and fetching its pictures
 * take minutes, so both are background jobs that the screen works while it is open (and cron when it is not): the pictures go a
 * few at a time, each job queuing the next, so no request runs into a time limit.
 *
 * What one import knows lives in storage/import/woocommerce: state.json (the step, the choices, the progress, the outcome),
 * plan.json (the dry run, with the products as they will be written) and customers.csv. The last two hold the customers' password
 * hashes, so they are removed when the import is done or started over. The keys are kept with the shop's other secrets
 * (ShopSecrets), never shown again, and forgotten when the import is done.
 */
final class WooCommerceImport
{
    public const PLAN = 'woocommerce.plan';
    public const APPLY = 'woocommerce.apply';
    public const SECRETS = 'woocommerce';
    /** Seconds of pictures one job fetches before it hands over to the next. */
    private const SLICE = 20;
    private const MAX_CSV_BYTES = 20 * 1024 * 1024;

    /**
     * @param \Closure(string, string, string, array<string, mixed>): WooCommerceImporter $makeImporter the shop's address, the
     *   keys and the options (lang, overwrite)
     * @param \Closure(list<array{0: string, 1: string}>, bool): array<string, int> $finish after the import: puts the redirects
     *   into the site when asked, refreshes the content index and empties the caches; says how the redirects went
     * @param (\Closure(string, string, string): void)|null $log action, level, message
     */
    public function __construct(
        private string $dir,
        private JobRunner $jobs,
        private ShopSecrets $secrets,
        private \Closure $makeImporter,
        private \Closure $finish,
        private ?\Closure $log = null
    ) {
    }

    public function register(): void
    {
        $this->jobs->register(self::PLAN, fn(array $payload, array $job): array => $this->read(), 900);
        $this->jobs->register(self::APPLY, fn(array $payload, array $job): array => $this->import((float)($job['deadline'] ?? 0)), 300);
    }

    /** @return list<string> the kinds the screen works while it waits */
    public static function kinds(): array
    {
        return [self::PLAN, self::APPLY];
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $state = json_decode((string)@file_get_contents($this->dir . '/state.json'), true);
        return (is_array($state) ? $state : []) + ['status' => 'idle', 'site' => '', 'lang' => '', 'options' => [], 'summary' => [], 'progress' => [], 'result' => [], 'error' => '', 'at' => 0];
    }

    /**
     * Step 1: the shop to read. Keeps the keys (a blank one keeps what is stored) and the customers' CSV, then queues the dry run.
     *
     * @param array<string, mixed> $post site, consumer_key, consumer_secret, lang, customers_clear
     * @param array<string, mixed>|null $upload the customers' CSV from $_FILES
     * @param list<string> $languages the site's languages; the first is its default
     * @return array{ok: bool, message: string}
     */
    public function start(array $post, ?array $upload, array $languages): array
    {
        if (in_array($this->state()['status'], ['reading', 'importing'], true)) {
            return ['ok' => false, 'message' => 'An import is already running.'];
        }
        $site = trim((string)($post['site'] ?? ''));
        if ($site !== '' && !preg_match('#^https?://#i', $site)) {
            $site = 'https://' . $site;
        }
        $host = (string)parse_url($site, PHP_URL_HOST);
        if ($site === '' || $host === '' || filter_var($site, FILTER_VALIDATE_URL) === false) {
            return ['ok' => false, 'message' => 'Enter the shop\'s address, like https://shop.example.'];
        }
        $site = rtrim((string)preg_replace('#^(https?://[^/?\#]+(?:/[^?\#]*)?).*$#i', '$1', $site), '/');
        $this->secrets->save(self::SECRETS, ['consumer_key' => $post['consumer_key'] ?? '', 'consumer_secret' => $post['consumer_secret'] ?? '']);
        $keys = $this->secrets->of(self::SECRETS);
        $withKeys = ($keys['consumer_key'] ?? '') !== '' && ($keys['consumer_secret'] ?? '') !== '';
        if ($withKeys && !str_starts_with(strtolower($site), 'https://')) {
            return ['ok' => false, 'message' => 'The keys are only sent over https: the shop\'s address must start with https://.'];
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true)) {
            return ['ok' => false, 'message' => 'The folder for the import could not be made (storage/import/woocommerce).'];
        }
        $csv = $this->dir . '/customers.csv';
        if (!empty($post['customers_clear'])) {
            @unlink($csv);
        }
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK || (int)($upload['size'] ?? 0) > self::MAX_CSV_BYTES || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
                return ['ok' => false, 'message' => 'The customers\' CSV could not be uploaded (at most 20 MB).'];
            }
            if (!@move_uploaded_file((string)$upload['tmp_name'], $csv)) {
                return ['ok' => false, 'message' => 'The customers\' CSV could not be kept.'];
            }
            @chmod($csv, 0600);
            try {
                if (WooCommerceImporter::customersCsv($csv) === []) {
                    @unlink($csv);
                    return ['ok' => false, 'message' => 'The customers\' CSV has no rows with an email address.'];
                }
            } catch (\Throwable $e) {
                @unlink($csv);
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        }
        $lang = (string)($post['lang'] ?? '');
        $state = [
            'status' => 'reading',
            'site' => $site,
            'lang' => in_array($lang, $languages, true) ? $lang : ($languages[0] ?? 'el'),
            'with_keys' => $withKeys,
            'customers' => is_file($csv),
            'options' => [],
            'summary' => [],
            'progress' => [],
            'result' => [],
            'error' => '',
            'at' => time(),
        ];
        $this->save($state);
        @unlink($this->dir . '/plan.json');
        if ($this->jobs->enqueue(self::PLAN, [], 5, 'woocommerce-import', 1) === 0) {
            $this->save(['status' => 'failed', 'error' => 'The site database is not available, so the import cannot run.'] + $state);
            return ['ok' => false, 'message' => 'The site database is not available.'];
        }
        return ['ok' => true, 'message' => 'Reading the shop…'];
    }

    /**
     * Step 3: import what the dry run found.
     *
     * @param array<string, mixed> $post redirects, overwrite
     * @return array{ok: bool, message: string}
     */
    public function confirm(array $post): array
    {
        $state = $this->state();
        if ($state['status'] !== 'ready' || !is_file($this->dir . '/plan.json')) {
            return ['ok' => false, 'message' => 'Check the shop first.'];
        }
        $state['status'] = 'importing';
        $state['options'] = ['redirects' => !empty($post['redirects']), 'overwrite' => !empty($post['overwrite'])];
        $state['progress'] = ['media_next' => 0, 'media_total' => (int)($state['summary']['media'] ?? 0), 'media_done' => 0, 'media_failed' => [], 'step' => 'pictures'];
        $state['at'] = time();
        $this->save($state);
        if ($this->jobs->enqueue(self::APPLY, [], 5, null, 2) === 0) {
            $this->save(['status' => 'ready'] + $state);
            return ['ok' => false, 'message' => 'The site database is not available.'];
        }
        $this->note('shop.import_started', 'info', 'WooCommerce import started from ' . $state['site'] . '.');
        return ['ok' => true, 'message' => 'Importing…'];
    }

    /** Back to step 1: the dry run, the customers' CSV and the stored keys are forgotten; a running import is left to finish. */
    public function reset(): array
    {
        if (in_array($this->state()['status'], ['reading', 'importing'], true)) {
            return ['ok' => false, 'message' => 'Wait for the import to finish.'];
        }
        $site = $this->state()['site'];
        @unlink($this->dir . '/plan.json');
        @unlink($this->dir . '/customers.csv');
        $this->secrets->save(self::SECRETS, [], true);
        $this->save(['status' => 'idle', 'site' => $site, 'at' => time()]);
        return ['ok' => true, 'message' => 'Started over.'];
    }

    /** @return array<string, mixed> what the screen shows */
    public function view(): array
    {
        $state = $this->state();
        $plan = in_array($state['status'], ['ready'], true) ? $this->plan() : null;
        $products = [];
        foreach (array_slice(is_array($plan['products'] ?? null) ? $plan['products'] : [], 0, 500) as $p) {
            $products[] = ['name' => $p['name'], 'slug' => $p['slug'], 'state' => $p['state'], 'kind' => $p['kind'], 'variants' => $p['variants'], 'pictures' => $p['pictures'], 'stock' => is_array($p['stock']) ? array_sum($p['stock']) : null];
        }
        return [
            'state' => $state,
            'products' => $products,
            'problems' => is_array($plan['problems'] ?? null) ? $plan['problems'] : [],
            'keys' => $this->secrets->view(self::SECRETS),
            'has_csv' => is_file($this->dir . '/customers.csv'),
            'reports' => ['report' => is_file($this->dir . '/woocommerce-report.json'), 'redirects' => is_file($this->dir . '/woocommerce-redirects.txt')],
        ];
    }

    /** The status line the screen polls. @return array<string, mixed> */
    public function status(): array
    {
        $state = $this->state();
        return ['status' => $state['status'], 'progress' => $state['progress'], 'error' => $state['error']];
    }

    /** A report of the last dry run to download: 'report' (JSON) or 'redirects' (old new, one a line). */
    public function reportFile(string $which): ?string
    {
        $file = $this->dir . '/' . ($which === 'redirects' ? 'woocommerce-redirects.txt' : 'woocommerce-report.json');
        return in_array($which, ['report', 'redirects'], true) && is_file($file) ? $file : null;
    }

    /** The dry run, as a job. @return array<string, mixed> */
    private function read(): array
    {
        $state = $this->state();
        if ($state['status'] !== 'reading') {
            return ['skipped' => true];
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }
        try {
            $customers = $state['customers'] && is_file($this->dir . '/customers.csv') ? WooCommerceImporter::customersCsv($this->dir . '/customers.csv') : [];
            $plan = $this->importer($state)->plan($customers);
        } catch (\Throwable $e) {
            $message = $e instanceof \RuntimeException ? $e->getMessage() : 'The shop could not be read.';
            $this->save(['status' => 'failed', 'error' => $message] + $state);
            $this->note('shop.import_failed', 'warning', 'WooCommerce import: ' . $message);
            return ['ok' => false];
        }
        if ($plan['products'] === [] && $plan['categories'] === []) {
            $this->save(['status' => 'failed', 'error' => 'No products were found at ' . $state['site'] . '. Is it a WooCommerce shop, and is its address right?'] + $state);
            return ['ok' => false];
        }
        $this->writeJson('plan.json', $plan);
        @chmod($this->dir . '/plan.json', 0600);
        $this->writeJson('woocommerce-report.json', ['site' => $plan['site'], 'at' => date('c'), 'products' => array_map(static fn(array $p): array => array_diff_key($p, ['markdown' => true, 'file' => true]), $plan['products']), 'problems' => $plan['problems'], 'redirects' => $plan['redirects']]);
        @file_put_contents($this->dir . '/woocommerce-redirects.txt', implode("\n", array_map(static fn(array $r): string => $r[0] . ' ' . $r[1], $plan['redirects'])) . "\n");
        $states = array_count_values(array_column($plan['products'], 'state'));
        $kinds = array_count_values(array_column($plan['products'], 'kind'));
        $state['status'] = 'ready';
        $state['summary'] = [
            'full' => $plan['full'],
            'categories' => count($plan['categories']),
            'products' => count($plan['products']),
            'new' => $states['new'] ?? 0,
            'update' => $states['update'] ?? 0,
            'skip' => $states['skip'] ?? 0,
            'physical' => $kinds['physical'] ?? 0,
            'digital' => $kinds['digital'] ?? 0,
            'with_variants' => count(array_filter($plan['products'], static fn(array $p): bool => $p['variants'] > 0)),
            'variants' => array_sum(array_column($plan['products'], 'variants')),
            'stocked' => count(array_filter($plan['products'], static fn(array $p): bool => $p['stock'] !== null)),
            'without_pictures' => count(array_filter($plan['products'], static fn(array $p): bool => $p['pictures'] === 0)),
            'media' => count($plan['media']),
            'redirects' => count($plan['redirects']),
            'problems' => count($plan['problems']),
            'customers_new' => count($plan['customers']['new']),
            'customers_exist' => count($plan['customers']['exists']),
            'customers_invalid' => count($plan['customers']['invalid']),
            'customers_no_password' => $plan['customers']['no_password'],
        ];
        $state['at'] = time();
        $this->save($state);
        return ['ok' => true, 'products' => count($plan['products'])];
    }

    /** One slice of the import, as a job: pictures until the deadline, and when they are all in, the rest. @return array<string, mixed> */
    private function import(float $deadline): array
    {
        $state = $this->state();
        $plan = $this->plan();
        if ($state['status'] !== 'importing' || $plan === null) {
            return ['skipped' => true];
        }
        if (!empty($state['options']['overwrite'])) {
            foreach ($plan['products'] as $i => $p) {
                $plan['products'][$i]['state'] = $p['state'] === 'skip' ? 'update' : $p['state'];
            }
        }
        $importer = $this->importer($state);
        $progress = $state['progress'];
        $next = (int)($progress['media_next'] ?? 0);
        if ($next < count($plan['media'])) {
            // The screen's progress bar moves with each picture, not only when a slice ends.
            $saved = microtime(true);
            $before = (int)($progress['media_done'] ?? 0);
            $onEach = function (int $at, int $fetched) use (&$state, &$saved, $before): void {
                if (microtime(true) - $saved >= 0.5) {
                    $state['progress']['media_next'] = $at;
                    $state['progress']['media_done'] = $before + $fetched;
                    $this->save($state);
                    $saved = microtime(true);
                }
            };
            $slice = $importer->applyMedia($plan, min($deadline > 0 ? $deadline - 2 : INF, microtime(true) + self::SLICE), $next, $onEach);
            $progress['media_next'] = $slice['next'];
            $progress['media_done'] = (int)($progress['media_done'] ?? 0) + $slice['done'];
            $progress['media_failed'] = array_slice(array_merge((array)($progress['media_failed'] ?? []), $slice['failed']), 0, 200);
            $state['progress'] = $progress;
            $this->save($state);
            if ($slice['next'] < $slice['total']) {
                $this->jobs->enqueue(self::APPLY, [], 5, null, 2);
                return ['pictures' => $slice['next'] . '/' . $slice['total']];
            }
        }
        $progress['step'] = 'products';
        $state['progress'] = $progress;
        $this->save($state);
        try {
            $done = $importer->applyContent($plan);
            $redirects = ($this->finish)($plan['redirects'], !empty($state['options']['redirects']));
        } catch (\Throwable $e) {
            $this->save(['status' => 'failed', 'error' => 'The import stopped while writing the products: ' . $e->getMessage()] + $state);
            $this->note('shop.import_failed', 'error', 'WooCommerce import stopped: ' . $e->getMessage());
            return ['ok' => false];
        }
        $state['status'] = 'done';
        $state['result'] = $done + ['media' => (int)$progress['media_done'], 'media_failed' => $progress['media_failed'], 'redirects' => $redirects];
        $state['progress']['step'] = 'done';
        $state['at'] = time();
        $this->save($state);
        // What only this import needed: the customers' password hashes and the keys.
        @unlink($this->dir . '/plan.json');
        @unlink($this->dir . '/customers.csv');
        $this->secrets->save(self::SECRETS, [], true);
        $this->note('shop.import_done', 'info', sprintf('WooCommerce import from %s: %d products, %d categories, %d pictures, %d customers.', $state['site'], $done['written'], $done['categories'], $progress['media_done'], $done['customers']));
        return ['ok' => true];
    }

    /** @param array<string, mixed> $state */
    private function importer(array $state): WooCommerceImporter
    {
        $keys = $this->secrets->of(self::SECRETS);
        return ($this->makeImporter)((string)$state['site'], (string)($keys['consumer_key'] ?? ''), (string)($keys['consumer_secret'] ?? ''), ['lang' => (string)$state['lang'], 'overwrite' => !empty($state['options']['overwrite'])]);
    }

    /** @return array<string, mixed>|null */
    private function plan(): ?array
    {
        $plan = json_decode((string)@file_get_contents($this->dir . '/plan.json'), true);
        return is_array($plan) ? $plan : null;
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        $this->writeJson('state.json', $state);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $name, array $data): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $tmp = $this->dir . '/.' . $name . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $this->dir . '/' . $name);
        }
    }

    private function note(string $action, string $level, string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($action, $level, $message);
        }
    }
}
