<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What the maps work out from the whole of the content, kept so a visit does not work it out again: the data of a map of entries
 * (the markers and lines of a type, a language and who is looking) and the entries near one. It is disposable: a missing, corrupt or
 * unwritable cache only costs the time it saves, and the answer is the one that would have been worked out.
 *
 * An answer is kept under a stamp, a token that changes whenever anything it could depend on changes: the content (the content index
 * says when an entry was saved, deleted, imported or edited by hand), the site's settings, taxonomies and type definitions, and
 * everything the admin changes (a replaced route file in Media included). The files of the routes an answer was made from are also
 * kept with their time and size and compared on every read, so a route file replaced behind the site's back is not served from the
 * old answer. When the stamp changes the answers of the old one are deleted. The stamp is null when nothing may be kept (no index,
 * or the live preview shows an entry that is not saved).
 */
final class GeoCache
{
    private const FORMAT = 1;
    /** Most answers one stamp keeps; more than this (many different filters) and the oldest are dropped by starting again. */
    private const MAX_ANSWERS = 400;
    private const MAX_BYTES = 8 * 1024 * 1024;

    private ?string $stamp = null;
    private bool $stamped = false;
    private ?string $folder = null;

    /**
     * @param \Closure(): ?string $stampOf the token of the content and settings as they are now, or null to keep nothing
     * @param (\Closure(): string)|null $variantOf what else tells answers apart that are both current (who is looking: a visitor and an
     *        editor are each kept their own, so neither takes the other's place)
     */
    public function __construct(private string $dir, private \Closure $stampOf, private GeoLibrary $library, private ?\Closure $variantOf = null)
    {
    }

    /**
     * The answer to a question, from the cache when it holds one made under today's stamp from route files as they still are, else
     * worked out by `$build` and kept. The value must be plain data (arrays, strings, numbers).
     *
     * @param array<mixed> $key everything the answer depends on that the stamp does not cover
     * @param \Closure(): mixed $build
     */
    public function remember(string $kind, array $key, \Closure $build): mixed
    {
        $folder = $this->folder();
        if ($folder === null) {
            return $build();
        }
        $file = $folder . '/' . $kind . '-' . sha1((string)json_encode([$this->variantOf === null ? '' : ($this->variantOf)(), $key])) . '.ser';
        $raw = is_file($file) ? @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1) : false;
        if (is_string($raw) && strlen($raw) <= self::MAX_BYTES) {
            try {
                $data = @unserialize($raw, ['allowed_classes' => false]);
            } catch (\Throwable) {
                $data = null;
            }
            if (is_array($data) && array_key_exists('value', $data) && is_array($data['files'] ?? null) && GeoLibrary::unchanged($data['files'])) {
                $this->library->noteFiles($data['files']);
                return $data['value'];
            }
        }
        $this->library->watch();
        try {
            $value = $build();
        } finally {
            $files = $this->library->unwatch();
        }
        $this->library->noteFiles($files);
        $this->write($folder, $file, serialize(['files' => $files, 'value' => $value]));
        return $value;
    }

    /** The folder of today's stamp, made when it is first needed, or null when nothing may be kept. */
    private function folder(): ?string
    {
        if (!$this->stamped) {
            $this->stamped = true;
            try {
                $this->stamp = ($this->stampOf)();
            } catch (\Throwable) {
                $this->stamp = null;
            }
            if ($this->stamp !== null && $this->stamp !== '') {
                $this->folder = $this->dir . '/' . substr(sha1(self::FORMAT . '|' . $this->stamp), 0, 16);
            }
        }
        return $this->folder;
    }

    private function write(string $folder, string $file, string $data): void
    {
        if (strlen($data) > self::MAX_BYTES) {
            return;
        }
        if (!is_dir($folder)) {
            // A new stamp: what the old ones kept is no longer of any use.
            foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $old) {
                foreach (glob($old . '/*') ?: [] as $stale) {
                    @unlink($stale);
                }
                @rmdir($old);
            }
            if (!@mkdir($folder, 0775, true) && !is_dir($folder)) {
                return;
            }
        } elseif (count(glob($folder . '/*.ser') ?: []) >= self::MAX_ANSWERS) {
            foreach (glob($folder . '/*') ?: [] as $stale) {
                @unlink($stale);
            }
        }
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $data) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
        }
    }
}
