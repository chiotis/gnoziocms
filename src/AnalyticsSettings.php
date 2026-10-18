<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The choices of Admin > Analytics, kept under `analytics` in the site settings. The site either has no tracking, tracking code
 * of its own (a Google tag, Tag Manager, Plausible, Matomo, a pixel: whatever the owner pastes) or the analytics of the
 * platform, which count visits without cookies. The class reads the choices (each has a default, each is checked), cleans what a
 * form sent, and builds what goes into the pages for the choice: the code of the owner, or the tag of the platform's script.
 */
final class AnalyticsSettings
{
    public const MODES = ['off' => 'No tracking', 'custom' => 'Your own tracking code', 'platform' => 'GnozioCMS analytics'];
    public const KEEP = [12 => '1 year', 24 => '2 years', 60 => '5 years', 0 => 'Forever'];
    public const CODE_LIMIT = 20000;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'mode' => 'off',
            'tag_id' => '',
            'head_code' => '',
            'body_code' => '',
            'skip_signed_in' => true,
            'respect_dnt' => true,
            'track_links' => true,
            'ignore_paths' => [],
            'keep_months' => 24,
        ];
    }

    /**
     * @param array<string, mixed> $site the site settings
     * @return array<string, mixed>
     */
    public static function from(array $site): array
    {
        $stored = is_array($site['analytics'] ?? null) ? $site['analytics'] : [];
        $out = self::defaults();
        $mode = is_scalar($stored['mode'] ?? null) ? (string)$stored['mode'] : '';
        $out['mode'] = array_key_exists($mode, self::MODES) ? $mode : 'off';
        $out['tag_id'] = self::tag($stored['tag_id'] ?? '');
        $out['head_code'] = self::code($stored['head_code'] ?? '');
        $out['body_code'] = self::code($stored['body_code'] ?? '');
        foreach (['skip_signed_in', 'respect_dnt', 'track_links'] as $key) {
            $out[$key] = array_key_exists($key, $stored) ? Format::isTruthy($stored[$key]) : $out[$key];
        }
        $out['ignore_paths'] = RobotsTxt::rules(is_array($stored['ignore_paths'] ?? null) ? $stored['ignore_paths'] : []);
        $keep = is_numeric($stored['keep_months'] ?? null) ? (int)$stored['keep_months'] : 24;
        $out['keep_months'] = array_key_exists($keep, self::KEEP) ? $keep : 24;
        return $out;
    }

    /**
     * The stored `analytics` after the settings form was saved. Code is executable, so it is only taken from a person who may
     * write raw HTML: for anyone else the code that is stored stays as it is (they can still switch between the choices).
     *
     * @param array<string, mixed> $stored what is stored now
     * @param array<string, mixed> $post what the form sent
     * @return array<string, mixed>
     */
    public static function apply(array $stored, array $post, bool $mayWriteCode): array
    {
        $on = static fn(mixed $value): bool => $value !== null && (string)$value !== '' && (string)$value !== '0';
        $mode = is_scalar($post['mode'] ?? null) ? (string)$post['mode'] : '';
        $a = $stored;
        $a['mode'] = array_key_exists($mode, self::MODES) ? $mode : 'off';
        if ($mayWriteCode) {
            $a['tag_id'] = self::tag($post['tag_id'] ?? '');
            $a['head_code'] = self::code($post['head_code'] ?? '');
            $a['body_code'] = self::code($post['body_code'] ?? '');
        }
        $a['skip_signed_in'] = $on($post['skip_signed_in'] ?? null);
        $a['respect_dnt'] = $on($post['respect_dnt'] ?? null);
        $a['track_links'] = $on($post['track_links'] ?? null);
        $a['ignore_paths'] = RobotsTxt::rules((string)($post['ignore_paths'] ?? ''));
        $keep = is_numeric($post['keep_months'] ?? null) ? (int)$post['keep_months'] : 24;
        $a['keep_months'] = array_key_exists($keep, self::KEEP) ? $keep : 24;
        if ($a['ignore_paths'] === []) {
            unset($a['ignore_paths']);
        }
        foreach (['tag_id', 'head_code', 'body_code'] as $key) {
            if (($a[$key] ?? '') === '') {
                unset($a[$key]);
            }
        }
        return $a;
    }

    /** What goes before `</head>`: the owner's tag and code, or the platform's script. Nothing for a person who is signed in when that is chosen. */
    public static function head(array $a, bool $signedIn, string $scriptUrl, string $apiUrl): string
    {
        if ($a['mode'] === 'off' || ($signedIn && $a['skip_signed_in'])) {
            return '';
        }
        if ($a['mode'] === 'platform') {
            return '<script defer src="' . htmlspecialchars($scriptUrl, ENT_QUOTES) . '" data-api="' . htmlspecialchars($apiUrl, ENT_QUOTES) . '"'
                . ($a['track_links'] ? '' : ' data-links="0"') . ($a['respect_dnt'] ? '' : ' data-dnt="0"') . '></script>';
        }
        $out = '';
        $tag = $a['tag_id'];
        if (str_starts_with($tag, 'GTM-')) {
            $out .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . $tag . "');</script>\n";
        } elseif ($tag !== '') {
            $out .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $tag . '"></script>' . "\n"
                . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','" . $tag . "');</script>\n";
        }
        return rtrim($out . $a['head_code']);
    }

    /** What goes after `<body>`: the owner's code for the end of the page, and the noscript part of Tag Manager. */
    public static function body(array $a, bool $signedIn): string
    {
        if ($a['mode'] !== 'custom' || ($signedIn && $a['skip_signed_in'])) {
            return '';
        }
        $out = '';
        if (str_starts_with($a['tag_id'], 'GTM-')) {
            $out .= '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $a['tag_id'] . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
        }
        return rtrim($out . $a['body_code']);
    }

    /** A Google tag (G-…, GT-…, AW-…) or Tag Manager container (GTM-…) ID, or nothing. */
    public static function tag(mixed $value): string
    {
        $value = strtoupper(trim(is_scalar($value) ? (string)$value : ''));
        return preg_match('/^(?:G|GT|AW|GTM)-[A-Z0-9]{4,20}$/', $value) === 1 ? $value : '';
    }

    /** Code as pasted: kept as it is, only cut to its length and cleaned of characters that are not text. */
    private static function code(mixed $value): string
    {
        $value = is_scalar($value) ? str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], (string)$value) : '';
        return trim(mb_substr($value, 0, self::CODE_LIMIT));
    }
}
