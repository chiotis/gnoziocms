<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Small display formatters shared by admin services. */
final class Format
{
    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }
        return number_format($value, 1) . ' ' . $units[$unitIndex];
    }

    /**
     * A line of plain text with links written as [label](address), as safe HTML: everything is escaped, and only web, mail and
     * site-relative addresses become links (a link out opens in a new tab). For short lines such as the credits in a footer.
     */
    public static function inlineLinks(string $text): string
    {
        return self::linkify(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * A line of Markdown as safe HTML, for what a release's notes say: `code`, **bold**, *italic* and [links](address); everything
     * else is text, escaped (no HTML gets through).
     */
    public static function inlineMarkdown(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $codes = [];
        // Code first, so what is inside it is left alone, then put back at the end.
        $html = (string)preg_replace_callback('/`([^`\n]+)`/u', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $m[1] . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $html);
        $html = self::linkify($html);
        $html = (string)preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html);
        $html = (string)preg_replace('/(?<![\w*])\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*(?![\w*])/u', '<em>$1</em>', $html);
        return (string)preg_replace_callback('/\x00(\d+)\x00/', static fn(array $m): string => $codes[(int)$m[1]] ?? '', $html);
    }

    /** Turns [label](address) in text that is already escaped into links (see inlineLinks). */
    private static function linkify(string $html): string
    {
        return (string)preg_replace_callback('/\[([^\]\[]+)\]\(([^()\s]+)\)/u', static function (array $m): string {
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $external = (bool)preg_match('#^https?://#i', $href);
            if (!$external && !preg_match('#^(mailto:[^\s]+|/(?!/)[^\s]*)$#i', $href)) {
                return $m[0];
            }
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
        }, $html);
    }

    /**
     * A text that may have a version for each language (a plain string, or a map of `default` and language codes) as the text for
     * one language: its own version, else the default one, else the first there is.
     */
    public static function localized(mixed $value, string $lang): string
    {
        if (!is_array($value)) {
            return is_scalar($value) ? (string)$value : '';
        }
        foreach ([$lang, 'default'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') {
                return (string)$value[$key];
            }
        }
        foreach ($value as $text) {
            if (is_scalar($text) && trim((string)$text) !== '') {
                return (string)$text;
            }
        }
        return '';
    }

    /** A list from front matter: an array, a comma separated text, or one value; empty entries are dropped. @return array<int, string> */
    public static function list(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        $value = (string)$value;
        if (str_contains($value, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }
        return [$value];
    }

    /** "a, b,,c" as ['a', 'b', 'c']. @return array<int, string> */
    public static function commaList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }

    /** Checkbox and query values: true, 1, "1", "yes", "on", "true". */
    public static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value > 0;
        }
        if (is_numeric($value)) {
            return (int)$value > 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /** A date, timestamp or date text in the site's date format; text that is not a date is returned as it is. */
    public static function dateValue(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value) || is_numeric($value)) {
            return date($format, (int)$value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }
        $timestamp = strtotime((string)$value);
        return $timestamp === false ? (string)$value : date($format, $timestamp);
    }
}
