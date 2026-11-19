<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The round Edit button at the bottom left of a public page, for someone signed in who may edit the entry shown: it opens that
 * entry in the admin's editor. Only pages made for a session get it, and those are never kept by a cache (App::sendPublicCacheHeaders),
 * so visitors never see it. It brings its own styles, so it looks the same in every theme, and sits above a theme's mobile bar.
 */
final class FrontEditButton
{
    /** The editor's address for an entry. */
    public static function url(string $type, string $slug, string $lang): string
    {
        return '/admin/edit?' . http_build_query(['type' => $type, 'slug' => $slug, 'lang' => $lang]);
    }

    public static function html(string $url, string $title): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = 'Edit “' . $title . '”';
        return '<a href="' . $e($url) . '" class="gz-edit-entry" data-gz-edit-entry title="' . $e($label) . '" aria-label="' . $e($label) . '">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>'
            . '<span class="gz-edit-entry__label">Edit</span></a>'
            . '<style>'
            . '.gz-edit-entry{position:fixed;left:16px;bottom:calc(16px + env(safe-area-inset-bottom));z-index:2147483000;display:inline-flex;align-items:center;gap:0;'
            . 'height:44px;min-width:44px;padding:0 12px;border-radius:999px;background:#2563eb;color:#fff;font:600 14px/1 system-ui,-apple-system,sans-serif;'
            . 'text-decoration:none;box-shadow:0 6px 20px rgba(15,23,42,.35),0 0 0 2px rgba(255,255,255,.9);opacity:.9;transition:opacity .15s,transform .15s,gap .15s}'
            . '.gz-edit-entry:hover,.gz-edit-entry:focus-visible{opacity:1;background:#1d4ed8;transform:translateY(-1px);gap:8px;color:#fff}'
            . '.gz-edit-entry:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}'
            . '.gz-edit-entry__label{max-width:0;overflow:hidden;white-space:nowrap;transition:max-width .15s}'
            . '.gz-edit-entry:hover .gz-edit-entry__label,.gz-edit-entry:focus-visible .gz-edit-entry__label{max-width:4em}'
            . '@media (max-width:860px){.has-mobile-bar .gz-edit-entry{bottom:calc(3.75rem + 12px + env(safe-area-inset-bottom))}}'
            . '@media print{.gz-edit-entry{display:none}}'
            . '</style>';
    }

    /** The page with the button put just before its last </body> (or at the end, for a page without one). */
    public static function inject(string $page, string $button): string
    {
        $at = strripos($page, '</body>');
        return $at === false ? $page . $button : substr($page, 0, $at) . $button . substr($page, $at);
    }
}
