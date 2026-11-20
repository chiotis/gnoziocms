<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The flag that closes the public site while an update replaces the code. Visitors get a short "back in a moment"
 * page (503, with Retry-After); the admin stays open, and a request that carries the flag's own token (the check the
 * installer makes of the new version) is let through. A flag that is older than a quarter of an hour is ignored, so a
 * failed update can never leave the site closed.
 */
final class MaintenanceMode
{
    public const MAX_AGE = 900;
    public const HEADER = 'X-Gnozio-Update';
    public const CHECK_PARAMETER = '_gnozio_check';
    public const CHECK_HEADER = 'X-Gnozio-Update-Check';
    /**
     * The names before the rename (0.1.316 and older). An update is checked by the code of the version it starts from, so a
     * site updating from one of those asks with these and reads its proof under the old header. They can go once no site
     * is left on 0.1.316 or older (Omni shows the versions).
     */
    private const OLD = ['header' => 'HTTP_X_FAROS_UPDATE', 'parameter' => '_faros_check', 'proof' => 'X-Faros-Update-Check'];

    public function __construct(private string $basePath)
    {
    }

    public function path(): string
    {
        return $this->basePath . '/storage/maintenance.flag';
    }

    /** Closes the public site; the token that still gets in is returned. */
    public function enable(string $version): string
    {
        $token = bin2hex(random_bytes(16));
        if (!is_dir(dirname($this->path()))) {
            @mkdir(dirname($this->path()), 0775, true);
        }
        file_put_contents($this->path(), json_encode(['since' => time(), 'version' => $version, 'token' => $token]));
        return $token;
    }

    public function disable(): void
    {
        @unlink($this->path());
    }

    /** @return array{since: int, version: string, token: string}|null the flag, when it is there and not too old */
    public function state(): ?array
    {
        $raw = @file_get_contents($this->path());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['since'], $data['token'])) {
            return null;
        }
        if (time() - (int)$data['since'] > self::MAX_AGE) {
            return null;
        }
        return ['since' => (int)$data['since'], 'version' => (string)($data['version'] ?? ''), 'token' => (string)$data['token']];
    }

    /** Whether a public request is to be answered with the maintenance page: the site is closed and the request holds no token. */
    public function blocks(string $presentedToken): bool
    {
        $state = $this->state();
        return $state !== null && !($presentedToken !== '' && hash_equals($state['token'], $presentedToken));
    }

    /**
     * What a request holds for the check of an update: the token, the challenge and the header the proof goes back in, under
     * the names it was asked with.
     *
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $query $_GET
     * @return array{token: string, nonce: string, proof: string}
     */
    public static function presented(array $server, array $query): array
    {
        $new = (string)($server['HTTP_X_GNOZIO_UPDATE'] ?? '');
        $old = $new === '' ? (string)($server[self::OLD['header']] ?? '') : '';
        $parameter = $old !== '' ? self::OLD['parameter'] : self::CHECK_PARAMETER;
        return ['token' => $new !== '' ? $new : $old, 'nonce' => is_string($query[$parameter] ?? null) ? $query[$parameter] : '',
            'proof' => $old !== '' ? self::OLD['proof'] : self::CHECK_HEADER];
    }

    public static function page(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Back in a moment</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a}'
            . 'main{max-width:28rem;padding:2rem;text-align:center}h1{font-size:1.5rem;margin:0 0 .5rem}p{margin:0;color:#475569}</style></head>'
            . '<body><main><h1>Back in a moment</h1><p>This site is being updated. Please try again in a minute.</p></main></body></html>';
    }
}
