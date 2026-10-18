<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * A form is built once, in the site's first language; every other language is a set of translations kept in the same file.
 * A field carries its own (`translations: {en: {label, placeholder, help, options: {value: label}}}`, the option values
 * stay the same); the form carries the texts of the form as a whole (`translations: {en: {title, excerpt, body,
 * submit_label, success_message, notifications: {subject, auto_reply_subject, auto_reply_message}}}`). A text with no
 * translation is shown in the first language, and counted as missing.
 */
final class FormTranslations
{
    public const FIELD_KEYS = ['label', 'placeholder', 'help', 'button', 'error_size', 'error_type', 'error_max', 'error_min'];
    public const FORM_KEYS = ['title', 'excerpt', 'body', 'submit_label', 'success_message'];
    public const NOTIFY_KEYS = ['subject', 'auto_reply_subject', 'auto_reply_message'];

    /** A language code as the site writes it. */
    public static function isLang(string $code): bool
    {
        return (bool)preg_match('/^[a-z]{2}$/', $code);
    }

    /**
     * The translations of one field as they are stored: only known texts, only for languages that are listed (any when the
     * list is empty), only for options the field has when the options are given, and nothing that is empty.
     *
     * @param string[]|null $optionValues the values of the field's options, or null to keep every option
     * @param string[] $languages
     * @return array<string, array<string, mixed>>
     */
    public static function cleanField(mixed $raw, ?array $optionValues = null, array $languages = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $lang => $texts) {
            $lang = (string)$lang;
            if (!self::isLang($lang) || !is_array($texts) || ($languages !== [] && !in_array($lang, $languages, true))) {
                continue;
            }
            $row = [];
            foreach (self::FIELD_KEYS as $key) {
                $value = trim((string)($texts[$key] ?? ''));
                if ($value !== '') {
                    $row[$key] = $value;
                }
            }
            if (is_array($texts['options'] ?? null)) {
                $options = [];
                foreach ($texts['options'] as $value => $label) {
                    $value = (string)$value;
                    $label = is_scalar($label) ? trim(str_replace(["\r", "\n"], ' ', (string)$label)) : '';
                    if ($label !== '' && ($optionValues === null || in_array($value, $optionValues, true))) {
                        $options[$value] = $label;
                    }
                }
                if ($options !== []) {
                    $row['options'] = $options;
                }
            }
            if ($row !== []) {
                $clean[$lang] = $row;
            }
        }
        return $clean;
    }

    /**
     * The texts of the form as a whole, cleaned for storing.
     *
     * @param string[] $languages
     * @return array<string, array<string, mixed>>
     */
    public static function cleanForm(mixed $raw, array $languages = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $lang => $texts) {
            $lang = (string)$lang;
            if (!self::isLang($lang) || !is_array($texts) || ($languages !== [] && !in_array($lang, $languages, true))) {
                continue;
            }
            $row = [];
            foreach (self::FORM_KEYS as $key) {
                $value = trim((string)($texts[$key] ?? ''));
                if ($value !== '') {
                    $row[$key] = $value;
                }
            }
            $notifications = [];
            foreach (self::NOTIFY_KEYS as $key) {
                $value = trim((string)(is_array($texts['notifications'] ?? null) ? ($texts['notifications'][$key] ?? '') : ''));
                if ($value !== '') {
                    $notifications[$key] = $value;
                }
            }
            if ($notifications !== []) {
                $row['notifications'] = $notifications;
            }
            if ($row !== []) {
                $clean[$lang] = $row;
            }
        }
        return $clean;
    }

    /**
     * The fields (as FormFields::normalize gives them) with their texts in a language; whatever has no translation stays as it is.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    public static function localizeFields(array $fields, string $lang, string $default): array
    {
        if ($lang === '' || $lang === $default) {
            return $fields;
        }
        foreach ($fields as $i => $field) {
            $texts = $field['translations'][$lang] ?? null;
            if (!is_array($texts)) {
                continue;
            }
            foreach (self::FIELD_KEYS as $key) {
                if (($texts[$key] ?? '') !== '') {
                    $fields[$i][$key] = (string)$texts[$key];
                }
            }
            if (is_array($texts['options'] ?? null) && is_array($field['options'] ?? null)) {
                foreach ($field['options'] as $n => $option) {
                    $label = (string)($texts['options'][$option['value']] ?? '');
                    if ($label !== '') {
                        $fields[$i]['options'][$n]['label'] = $label;
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * The form's own texts in a language, laid over its meta: title, button, messages, and what the emails say.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function localizeMeta(array $meta, string $lang, string $default): array
    {
        if ($lang === '' || $lang === $default) {
            return $meta;
        }
        $texts = $meta['translations'][$lang] ?? null;
        if (!is_array($texts)) {
            return $meta;
        }
        foreach (['title', 'excerpt', 'submit_label', 'success_message'] as $key) {
            if (trim((string)($texts[$key] ?? '')) !== '') {
                $meta[$key] = (string)$texts[$key];
            }
        }
        if (is_array($texts['notifications'] ?? null)) {
            $notifications = is_array($meta['notifications'] ?? null) ? $meta['notifications'] : [];
            foreach (self::NOTIFY_KEYS as $key) {
                if (trim((string)($texts['notifications'][$key] ?? '')) !== '') {
                    $notifications[$key] = (string)$texts['notifications'][$key];
                }
            }
            $meta['notifications'] = $notifications;
        }
        return $meta;
    }

    /**
     * A copy of a form as it is shown in a language: its texts translated, and that language as its language (so a
     * submission records the language it was sent from). The text above the form is converted with `$markdown`.
     *
     * @param (\Closure(string): string)|null $markdown
     */
    public static function localizeItem(ContentItem $form, string $lang, string $default, ?\Closure $markdown = null): ContentItem
    {
        $meta = self::localizeMeta($form->meta, $lang, $default);
        $body = $form->markdown;
        $html = $form->html;
        $translatedBody = $lang !== $default ? trim((string)($form->meta['translations'][$lang]['body'] ?? '')) : '';
        if ($translatedBody !== '') {
            $body = $translatedBody;
            $html = $markdown !== null ? $markdown($translatedBody) : $form->html;
        }
        $meta['lang'] = $lang !== '' ? $lang : $form->lang;
        return new ContentItem($form->type, $form->slug, $lang !== '' ? $lang : $form->lang, $meta, $body, $html, $form->filePath, $form->mtime);
    }

    /**
     * What a language still lacks: how many texts a form has to translate, how many are missing, and how many each field misses.
     * Counted are the texts the first language has filled in.
     *
     * @param array<string, mixed> $meta
     * @return array{total: int, missing: int, fields: array<string, int>}
     */
    public static function missing(array $meta, string $lang): array
    {
        $total = 0;
        $missing = 0;
        $perField = [];
        foreach (FormFields::normalize($meta['fields'] ?? []) as $field) {
            $texts = $field['translations'][$lang] ?? [];
            $lacking = 0;
            foreach (self::FIELD_KEYS as $key) {
                if (trim((string)($field[$key] ?? '')) === '') {
                    continue;
                }
                $total++;
                if (trim((string)($texts[$key] ?? '')) === '') {
                    $lacking++;
                }
            }
            foreach ($field['options'] as $option) {
                if (trim((string)$option['label']) === '') {
                    continue;
                }
                $total++;
                if (trim((string)($texts['options'][$option['value']] ?? '')) === '') {
                    $lacking++;
                }
            }
            $missing += $lacking;
            if ($lacking > 0) {
                $perField[$field['name']] = $lacking;
            }
        }
        $texts = is_array($meta['translations'][$lang] ?? null) ? $meta['translations'][$lang] : [];
        $notifications = is_array($meta['notifications'] ?? null) ? $meta['notifications'] : [];
        $formTexts = [
            'title' => [(string)($meta['title'] ?? ''), (string)($texts['title'] ?? '')],
            'excerpt' => [(string)($meta['excerpt'] ?? ''), (string)($texts['excerpt'] ?? '')],
            'submit_label' => [(string)($meta['submit_label'] ?? ''), (string)($texts['submit_label'] ?? '')],
            'success_message' => [(string)($meta['success_message'] ?? ''), (string)($texts['success_message'] ?? '')],
            'body' => [(string)($meta['body'] ?? ''), (string)($texts['body'] ?? '')],
        ];
        foreach (self::NOTIFY_KEYS as $key) {
            $formTexts['notifications.' . $key] = [(string)($notifications[$key] ?? ''), (string)($texts['notifications'][$key] ?? '')];
        }
        foreach ($formTexts as [$original, $translated]) {
            if (trim($original) === '') {
                continue;
            }
            $total++;
            if (trim($translated) === '') {
                $missing++;
            }
        }
        return ['total' => $total, 'missing' => $missing, 'fields' => $perField];
    }

    /**
     * How many texts each other language still lacks.
     *
     * @param array<string, mixed> $meta the form's meta, with its text above the form as `body`
     * @param string[] $languages every language of the site
     * @return array<string, array{total: int, missing: int}>
     */
    public static function overview(array $meta, array $languages, string $default): array
    {
        $rows = [];
        foreach ($languages as $lang) {
            if ($lang === $default) {
                continue;
            }
            $count = self::missing($meta, $lang);
            $rows[$lang] = ['total' => $count['total'], 'missing' => $count['missing']];
        }
        return $rows;
    }

    /**
     * The form behind an address: the form of that name in the language, else (a form is one thing in every language) the
     * first-language form; else the form an older address of it pointed at.
     *
     * @return array{form: ?ContentItem, alias_lang: string} alias_lang is the language the old address stood for, when it was an old address
     */
    public static function find(ContentRepository $content, string $slug, string $lang, bool $includeHidden): array
    {
        $form = $content->find('forms', $slug, $lang, $includeHidden, true);
        if ($form !== null) {
            return ['form' => $form, 'alias_lang' => ''];
        }
        foreach ($content->getItems('forms', null, $includeHidden, false) as $candidate) {
            $aliases = is_array($candidate->meta['aliases'] ?? null) ? $candidate->meta['aliases'] : [];
            foreach ($aliases as $alias => $aliasLang) {
                if ((string)$alias === $slug) {
                    return ['form' => $candidate, 'alias_lang' => is_string($aliasLang) ? $aliasLang : ''];
                }
            }
        }
        return ['form' => null, 'alias_lang' => ''];
    }
}
