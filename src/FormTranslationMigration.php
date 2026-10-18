<?php

declare(strict_types=1);

namespace GnozioCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Folds the older per-language copies of a form (contact.md and contact.en.md, or a copy under an address of its own that shares
 * the form's translation_id) into the form of the first language, as translations. It can be run again: it only works on copies
 * that are still there, and a copy is put away (in storage/form-translation/copies) only after the form was written.
 *
 *   - a field is matched by its name; a text of a copy whose field has no match is not dropped but kept in the report
 *   - settings that differ between the copy and the form (the address mails go to, the redirect, ...) are reported, not copied
 *   - stored submissions filed under a copy's own address move to the form's, and are marked with the language of the copy
 *   - the address of a copy that had its own stays working: the form keeps it as an alias (`aliases: {contact-en: en}`)
 */
final class FormTranslationMigration
{
    public const MARKER = 'migrated.json';
    public const REPORT = 'report.json';

    public function __construct(private ContentRepository $content, private string $contentDir, private string $dataDir, private array $settings)
    {
    }

    /** Whether the migration has run on this site (it runs once, when the site is first opened after an update). */
    public function done(): bool
    {
        return is_file($this->dataDir . '/' . self::MARKER);
    }

    /** Runs it if it has not run yet. @return array<string, mixed>|null the report, or null when it had run already */
    public function runOnce(): ?array
    {
        if ($this->done()) {
            return null;
        }
        $handle = null;
        if (is_dir($this->dataDir) || @mkdir($this->dataDir, 0775, true) || is_dir($this->dataDir)) {
            $handle = @fopen($this->dataDir . '/.lock', 'c');
        }
        if ($handle !== false && $handle !== null && !flock($handle, LOCK_EX | LOCK_NB)) {
            return null; // another request is doing it
        }
        try {
            if ($this->done()) {
                return null;
            }
            return $this->run();
        } finally {
            if ($handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $raw = @file_get_contents($this->dataDir . '/' . self::REPORT);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    public function dismissReport(): void
    {
        @unlink($this->dataDir . '/' . self::REPORT);
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $this->content->forget();
        $default = (string)($this->settings['languages']['default'] ?? 'en');
        $available = array_map('strval', $this->settings['languages']['available'] ?? [$default]);
        $report = ['ran_at' => gmdate('c'), 'forms' => [], 'skipped' => [], 'unmatched' => 0];

        $items = $this->content->getItems('forms', null, true, false);
        // Copies belong to a form by translation_id; one written before it existed belongs by its address.
        $idBySlug = [];
        foreach ($items as $item) {
            $id = (string)($item->meta['translation_id'] ?? '');
            if ($id !== '' && !isset($idBySlug[$item->slug])) {
                $idBySlug[$item->slug] = $id;
            }
        }
        $groups = [];
        foreach ($items as $item) {
            $id = (string)($item->meta['translation_id'] ?? '') ?: ($idBySlug[$item->slug] ?? '');
            $groups[$id !== '' ? 'id:' . $id : 'slug:' . $item->slug][] = $item;
        }

        foreach ($groups as $members) {
            $primary = null;
            foreach ($members as $member) {
                if ($member->lang === $default) {
                    $primary = $member;
                    break;
                }
            }
            $copies = array_values(array_filter($members, static fn(ContentItem $m): bool => $primary === null || $m !== $primary));
            if ($copies === []) {
                continue;
            }
            if ($primary === null) {
                foreach ($copies as $copy) {
                    $report['skipped'][] = ['form' => $copy->slug, 'lang' => $copy->lang, 'reason' => 'There is no ' . $default . ' form to fold it into, so it stays as it is.'];
                }
                continue;
            }
            $entry = $this->fold($primary, $copies, $available, $default);
            $report['unmatched'] += count($entry['unmatched']);
            $report['forms'][] = $entry;
        }

        $this->content->forget();
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0775, true);
        }
        @file_put_contents($this->dataDir . '/' . self::REPORT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @file_put_contents($this->dataDir . '/' . self::MARKER, json_encode(['at' => gmdate('c')]));
        return $report;
    }

    /**
     * @param ContentItem[] $copies
     * @param string[] $available
     * @return array<string, mixed>
     */
    private function fold(ContentItem $primary, array $copies, array $available, string $default): array
    {
        $raw = (string)file_get_contents($primary->filePath);
        [$yaml, $body] = FrontMatter::split($raw);
        $meta = $yaml !== '' ? (Yaml::parse($yaml) ?: []) : [];
        $meta = is_array($meta) ? $meta : [];
        $fields = is_array($meta['fields'] ?? null) ? array_values($meta['fields']) : [];
        $byName = [];
        foreach ($fields as $i => $field) {
            if (is_array($field)) {
                $name = FormFields::sanitizeName((string)($field['name'] ?? ''));
                if ($name !== '') {
                    $byName[$name] = $i;
                }
            }
        }
        $formTexts = is_array($meta['translations'] ?? null) ? $meta['translations'] : [];
        $entry = ['form' => $primary->slug, 'folded' => [], 'aliases' => [], 'unmatched' => [], 'differing' => [], 'moved_submissions' => 0];
        $folded = [];

        foreach ($copies as $copy) {
            $lang = $copy->lang;
            [$copyYaml, $copyBody] = FrontMatter::split((string)file_get_contents($copy->filePath));
            $copyMeta = $copyYaml !== '' ? (Yaml::parse($copyYaml) ?: []) : [];
            $copyMeta = is_array($copyMeta) ? $copyMeta : [];

            // The fields of the copy, matched by name.
            foreach (FormFields::normalize($copyMeta['fields'] ?? []) as $cf) {
                $i = $byName[$cf['name']] ?? null;
                if ($i === null) {
                    $entry['unmatched'][] = array_filter([
                        'lang' => $lang, 'field' => $cf['name'], 'type' => $cf['type'], 'label' => $cf['label'], 'placeholder' => $cf['placeholder'], 'help' => $cf['help'],
                        'options' => array_map(static fn(array $o): string => $o['value'] . '|' . $o['label'], $cf['options']),
                    ], static fn($v): bool => $v !== '' && $v !== []);
                    continue;
                }
                $base = FormFields::normalize([$fields[$i]])[0] ?? null;
                if ($base === null) {
                    continue;
                }
                $existing = is_array($fields[$i]['translations'][$lang] ?? null) ? $fields[$i]['translations'][$lang] : [];
                $row = $existing;
                foreach (FormTranslations::FIELD_KEYS as $key) {
                    if (trim((string)($row[$key] ?? '')) === '' && trim((string)($cf[$key] ?? '')) !== '') {
                        $row[$key] = trim((string)$cf[$key]);
                    }
                }
                $baseValues = array_column($base['options'], 'value');
                $options = is_array($row['options'] ?? null) ? $row['options'] : [];
                foreach ($cf['options'] as $option) {
                    if (!in_array($option['value'], $baseValues, true)) {
                        $entry['unmatched'][] = ['lang' => $lang, 'field' => $cf['name'], 'option' => $option['value'] . '|' . $option['label']];
                    } elseif (trim((string)($options[$option['value']] ?? '')) === '' && trim((string)$option['label']) !== '') {
                        $options[$option['value']] = trim((string)$option['label']);
                    }
                }
                if ($options !== []) {
                    $row['options'] = $options;
                }
                if ($row !== []) {
                    $fields[$i]['translations'][$lang] = $row;
                }
            }

            // The texts of the form as a whole.
            $texts = is_array($formTexts[$lang] ?? null) ? $formTexts[$lang] : [];
            $pairs = [
                'title' => (string)($copyMeta['title'] ?? ''), 'excerpt' => (string)($copyMeta['excerpt'] ?? ''), 'body' => trim($copyBody),
                'submit_label' => (string)($copyMeta['submit_label'] ?? ''), 'success_message' => (string)($copyMeta['success_message'] ?? ''),
            ];
            foreach ($pairs as $key => $value) {
                if (trim($value) !== '' && trim((string)($texts[$key] ?? '')) === '') {
                    $texts[$key] = trim($value);
                }
            }
            $notify = is_array($copyMeta['notifications'] ?? null) ? $copyMeta['notifications'] : [];
            $textNotify = is_array($texts['notifications'] ?? null) ? $texts['notifications'] : [];
            foreach (FormTranslations::NOTIFY_KEYS as $key) {
                if (trim((string)($notify[$key] ?? '')) !== '' && trim((string)($textNotify[$key] ?? '')) === '') {
                    $textNotify[$key] = trim((string)$notify[$key]);
                }
            }
            if ($textNotify !== []) {
                $texts['notifications'] = $textNotify;
            }
            if ($texts !== []) {
                $formTexts[$lang] = $texts;
            }

            // What the copy set differently and is not a text: reported, because the form has one setting for every language.
            $primaryNotify = is_array($meta['notifications'] ?? null) ? $meta['notifications'] : [];
            foreach (['enabled', 'to', 'cc', 'bcc', 'reply_to_field', 'auto_reply', 'auto_reply_include'] as $key) {
                if (array_key_exists($key, $notify) && !self::same($primaryNotify[$key] ?? null, $notify[$key])) {
                    $entry['differing'][] = ['lang' => $lang, 'setting' => 'notifications.' . $key, 'was' => $primaryNotify[$key] ?? null, 'copy' => $notify[$key]];
                }
            }
            foreach (['redirect_url', 'store_submissions'] as $key) {
                if (array_key_exists($key, $copyMeta) && !self::same($meta[$key] ?? null, $copyMeta[$key])) {
                    $entry['differing'][] = ['lang' => $lang, 'setting' => $key, 'was' => $meta[$key] ?? null, 'copy' => $copyMeta[$key]];
                }
            }

            if ($copy->slug !== $primary->slug) {
                $aliases = is_array($meta['aliases'] ?? null) ? $meta['aliases'] : [];
                $aliases[$copy->slug] = $lang;
                $meta['aliases'] = $aliases;
                $entry['aliases'][] = $copy->slug;
                $entry['moved_submissions'] += $this->moveSubmissions($copy->slug, $primary->slug, $lang);
            }
            $folded[] = $copy;
            $entry['folded'][] = $lang;
        }

        // Only a language the site has is kept as a translation of the form.
        $formTexts = array_filter($formTexts, static fn($lang): bool => in_array((string)$lang, $available, true), ARRAY_FILTER_USE_KEY);
        if ($fields !== []) {
            $meta['fields'] = $fields;
        }
        if ($formTexts !== []) {
            $meta['translations'] = $formTexts;
        }
        if ((string)($meta['translation_id'] ?? '') === '') {
            foreach ($copies as $copy) {
                if ((string)($copy->meta['translation_id'] ?? '') !== '') {
                    $meta['translation_id'] = (string)$copy->meta['translation_id'];
                    break;
                }
            }
        }
        $payload = "---\n" . trim(Yaml::dump($meta, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) . "\n---\n\n" . trim($body) . "\n";
        if (@file_put_contents($primary->filePath, $payload) === false) {
            $entry['folded'] = [];
            $entry['error'] = 'The form could not be written, so its copies were left as they are.';
            return $entry;
        }
        // Only now are the copies put away: kept, not deleted, in case a text needs to be looked up.
        $away = $this->dataDir . '/copies';
        @mkdir($away, 0775, true);
        foreach ($folded as $copy) {
            $target = $away . '/' . basename($copy->filePath);
            if (is_file($target)) {
                $target = $away . '/' . date('Ymd-His') . '-' . basename($copy->filePath);
            }
            if (!@rename($copy->filePath, $target)) {
                @copy($copy->filePath, $target);
                @unlink($copy->filePath);
            }
        }
        return $entry;
    }

    /** Moves the submissions kept under a copy's address to the form's. Returns how many. */
    private function moveSubmissions(string $from, string $to, string $lang): int
    {
        $source = $this->contentDir . '/forms-submissions/' . $from;
        if (!is_dir($source)) {
            return 0;
        }
        $target = $this->contentDir . '/forms-submissions/' . $to;
        @mkdir($target, 0775, true);
        $moved = 0;
        foreach (glob($source . '/*.json') ?: [] as $path) {
            $data = json_decode((string)file_get_contents($path), true);
            if (is_array($data)) {
                $data['form'] = $to;
                if (trim((string)($data['lang'] ?? '')) === '') {
                    $data['lang'] = $lang;
                }
                file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            $name = basename($path);
            if (is_file($target . '/' . $name)) {
                $name = bin2hex(random_bytes(3)) . '-' . $name;
            }
            if (@rename($path, $target . '/' . $name)) {
                $moved++;
            }
        }
        @rmdir($source);
        return $moved;
    }

    /** Whether two settings say the same (a yes written as true, 1 or "yes" is the same yes). */
    private static function same(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b) || in_array($a, ['yes', 'no', 'true', 'false', 'on', 'off'], true) || in_array($b, ['yes', 'no', 'true', 'false', 'on', 'off'], true)) {
            return Format::isTruthy($a) === Format::isTruthy($b);
        }
        return trim((string)$a) === trim((string)$b);
    }
}
