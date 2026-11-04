<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The choices of Admin > Analytics, kept under `analytics` in the site settings. The site has no tracking, tracking code of
 * its own (a Google tag, Tag Manager, Plausible, Matomo, a pixel: whatever the owner pastes), the analytics of the platform,
 * which count visits without cookies, or both the platform's analytics and its own code. The class reads the choices (each has a default, each is checked), cleans what a
 * form sent, and builds what goes into the pages for the choice: the code of the owner, or the tag of the platform's script.
 */
final class AnalyticsSettings
{
    public const MODES = ['off' => 'No tracking', 'custom' => 'Your own tracking code', 'platform' => 'GnozioCMS analytics', 'both' => 'GnozioCMS analytics and your own code'];
    public const KEEP = [12 => '1 year', 24 => '2 years', 60 => '5 years', 0 => 'Forever'];
    public const CODE_LIMIT = 20000;
    /** When the Google tag (or Tag Manager) script is fetched: with the page, or once the page has loaded. */
    public const TAG_LOAD = ['now' => 'Right away', 'later' => 'After the page has loaded'];

    /** Whether the platform counts visits under these choices (GnozioCMS analytics alone or with the owner's code). @param array<string, mixed> $a */
    public static function platform(array $a): bool
    {
        return in_array($a['mode'] ?? 'off', ['platform', 'both'], true);
    }

    /** Whether the owner's tracking code goes into the pages under these choices (alone or with the platform's analytics). @param array<string, mixed> $a */
    public static function custom(array $a): bool
    {
        return in_array($a['mode'] ?? 'off', ['custom', 'both'], true);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'mode' => 'off',
            'tag_id' => '',
            'tag_load' => 'later',
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
        // A site that set its tag before this choice existed keeps it as it was (right away); one without a tag starts with later.
        $load = is_scalar($stored['tag_load'] ?? null) ? (string)$stored['tag_load'] : '';
        $out['tag_load'] = array_key_exists($load, self::TAG_LOAD) ? $load : ($out['tag_id'] !== '' ? 'now' : 'later');
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
        $load = is_scalar($post['tag_load'] ?? null) ? (string)$post['tag_load'] : '';
        if (array_key_exists($load, self::TAG_LOAD)) {
            $a['tag_load'] = $load;
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

    /** What goes before `</head>`: the platform's script, the owner's tag and code, or both. Nothing for a person who is signed in when that is chosen. */
    public static function head(array $a, bool $signedIn, string $scriptUrl, string $apiUrl): string
    {
        if ($a['mode'] === 'off' || ($signedIn && $a['skip_signed_in'])) {
            return '';
        }
        $out = '';
        if (self::platform($a)) {
            $out .= '<script defer src="' . htmlspecialchars($scriptUrl, ENT_QUOTES) . '" data-api="' . htmlspecialchars($apiUrl, ENT_QUOTES) . '"'
                . ($a['track_links'] ? '' : ' data-links="0"') . ($a['respect_dnt'] ? '' : ' data-dnt="0"') . "></script>\n";
        }
        if (!self::custom($a)) {
            return rtrim($out);
        }
        $tag = $a['tag_id'];
        if ($tag !== '') {
            $gtm = str_starts_with($tag, 'GTM-');
            $src = $gtm ? 'https://www.googletagmanager.com/gtm.js?id=' . $tag : 'https://www.googletagmanager.com/gtag/js?id=' . $tag;
            // The queue is there from the start, so what the page or other code sends before the script arrives is not lost.
            $queue = $gtm
                ? "window.dataLayer=window.dataLayer||[];dataLayer.push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
                : "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','" . $tag . "');";
            if (($a['tag_load'] ?? 'now') === 'later') {
                $out .= '<script>' . $queue . self::later($src) . "</script>\n";
            } else {
                $out .= '<script async src="' . $src . '"></script>' . "\n" . '<script>' . $queue . "</script>\n";
            }
        }
        return rtrim($out . $a['head_code']);
    }

    /**
     * A script that fetches `$src` once the page has loaded and the browser is idle, or at the visitor's first scroll, tap or key,
     * whichever comes first: the tag's script then takes no part in drawing the page.
     */
    private static function later(string $src): string
    {
        return "(function(w,d){var done=0,go=function(){if(done)return;done=1;var s=d.createElement('script');s.async=true;s.src='" . $src . "';d.head.appendChild(s)};"
            . "['scroll','pointerdown','keydown','touchstart'].forEach(function(e){w.addEventListener(e,go,{once:true,passive:true})});"
            . "var idle=function(){w.requestIdleCallback?w.requestIdleCallback(go,{timeout:3000}):setTimeout(go,1)};"
            . "if(d.readyState==='complete')idle();else w.addEventListener('load',idle,{once:true})})(window,document);";
    }

    /** What goes after `<body>`: the owner's code for the end of the page, and the noscript part of Tag Manager. */
    public static function body(array $a, bool $signedIn): string
    {
        if (!self::custom($a) || ($signedIn && $a['skip_signed_in'])) {
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
