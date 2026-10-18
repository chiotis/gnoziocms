<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The site's own stylesheet, `custom/assets/css/custom.css`, which base.twig loads after the theme's. Theme > Custom CSS shows it
 * as a text area and writes it back; the file stays an ordinary file, so it can still be edited by hand or kept in git.
 */
final class CustomCss
{
    /** More than this is not a stylesheet someone types in a text area. */
    public const MAX_BYTES = 512 * 1024;

    public function __construct(private readonly string $customDir)
    {
    }

    public function file(): string
    {
        return $this->customDir . '/assets/css/custom.css';
    }

    /** What the file holds now ('' when the site has none). */
    public function read(): string
    {
        $file = $this->file();
        if (!is_file($file)) {
            return '';
        }
        $css = file_get_contents($file);
        return $css === false ? '' : $css;
    }

    /**
     * Writes the stylesheet. Line endings are made `\n`, the file ends with one, and nothing is created for an empty text when the
     * site has no file yet. A text that has not changed is not written again (so the file's version, and every browser's copy of it,
     * stays).
     *
     * @return array{ok: bool, message?: string}
     */
    public function save(string $css): array
    {
        $css = str_replace(["\r\n", "\r"], "\n", $css);
        $css = trim($css) === '' ? '' : rtrim($css) . "\n";
        if (strlen($css) > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'The custom CSS is larger than ' . (self::MAX_BYTES / 1024) . ' KB, so it was not saved. Put the bulk of it in a file in custom/assets/css/.'];
        }
        $file = $this->file();
        $exists = is_file($file);
        if (!$exists && $css === '') {
            return ['ok' => true];
        }
        if ($exists && $this->read() === $css) {
            return ['ok' => true];
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'The custom CSS could not be saved. Check that the custom/ folder is writable.'];
        }
        // Written beside the file and moved over it, so a visitor never gets half of a stylesheet.
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $css, LOCK_EX) === false) {
            @unlink($temporary);
            return ['ok' => false, 'message' => 'The custom CSS could not be saved. Check that the custom/ folder is writable.'];
        }
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return ['ok' => false, 'message' => 'The custom CSS could not be saved. Check that the custom/ folder is writable.'];
        }
        return ['ok' => true];
    }
}
