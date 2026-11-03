<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Admin > MCP: the switch, the address to give an AI assistant, the connections (each with the account it acts as, its scopes,
 * when it was last used and when it ends) with Pause, Resume and Remove, and a token made by hand for clients that send a
 * header of their own. A token is shown once and only its hash is kept.
 */
final class McpAdmin
{
    public const META_ENABLED = 'mcp_enabled';
    private const HTACCESS_MARK = '# GnozioCMS MCP discovery';
    private const HTACCESS = self::HTACCESS_MARK . " (docs/mcp.md): written and removed with Admin > MCP.\n<FilesMatch \"^(mcp|oauth-authorization-server)$\">\n    ForceType application/json\n    <IfModule mod_headers.c>\n        Header set Cache-Control \"no-store\"\n    </IfModule>\n</FilesMatch>\n";

    /** @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log as App::logActivity */
    public function __construct(
        private McpStore $store,
        private McpOAuth $oauth,
        private SystemMetaRepository $meta,
        private UserRepository $users,
        private \Closure $log,
        private string $publicDir = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->meta->isAvailable() && $this->meta->get(self::META_ENABLED) === '1';
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed>|null $actor the signed-in person
     * @param string $newToken a token just made (shown once), or ''
     * @return array<string, mixed>
     */
    public function screen(array $get, ?array $actor, string $newToken): array
    {
        $this->syncDiscoveryFiles();
        $accounts = $this->accounts($actor);
        $names = array_column($accounts, 'label', 'id');
        $connections = array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'kind' => (string)$row['kind'],
            'account' => $names[(int)$row['user_id']] ?? ((string)($row['display_name'] ?: $row['username']) ?: 'Unknown account'),
            'automation' => ($row['source'] ?? '') === UserRepository::AUTOMATION,
            'scopes' => preg_split('/\s+/', (string)$row['scope'], -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'created_at' => (string)$row['created_at'],
            'last_used_at' => (string)($row['last_used_at'] ?? ''),
            'ends_at' => (string)($row['ends_at'] ?? ''),
            'ends_soon' => ($row['ends_at'] ?? '') !== '' && (string)$row['ends_at'] < gmdate('Y-m-d H:i:s', time() + 7 * 86400),
            'paused' => $row['paused_at'] !== null,
            'policy' => McpStore::policy($row),
            'published_today' => $this->store->publishedToday((int)$row['id']),
        ], $this->store->isAvailable() ? $this->store->connections() : []);
        return [
            'enabled' => $this->isEnabled(),
            'available' => $this->store->isAvailable(),
            'endpoint' => $this->oauth->resourceUrl(),
            'metadata' => [
                'protected_resource' => $this->oauth->protectedResource()['authorization_servers'][0] . McpOAuth::RESOURCE_METADATA_PATH,
                'authorization_server' => $this->oauth->protectedResource()['authorization_servers'][0] . McpOAuth::SERVER_METADATA_PATH,
            ],
            'connections' => $connections,
            'accounts' => $accounts,
            'scopes' => McpOAuth::scopes(),
            'new_token' => $newToken,
            'done' => (string)($get['done'] ?? ''),
            'message' => trim((string)($get['msg'] ?? '')),
        ];
    }

    /**
     * A form of the screen. `token` is set when a token was made, to be shown once.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $actor
     * @return array{ok: bool, message: string, token: string}
     */
    public function act(array $post, ?array $actor): array
    {
        $done = static fn(bool $ok, string $message, string $token = ''): array => ['ok' => $ok, 'message' => $message, 'token' => $token];
        $action = (string)($post['mcp_action'] ?? '');
        if (!$this->store->isAvailable()) {
            return $done(false, 'The site database is not available.');
        }
        if ($action === 'enable' || $action === 'disable') {
            $this->meta->set(self::META_ENABLED, $action === 'enable' ? '1' : '0');
            $this->syncDiscoveryFiles();
            ($this->log)('mcp.' . $action . 'd', 'info', 'mcp', null, $action === 'enable' ? 'MCP was turned on.' : 'MCP was turned off.', []);
            return $done(true, $action === 'enable' ? 'MCP is on. Give the address below to the AI assistant.' : 'MCP is off. Connections stay, and work again when it is on.');
        }
        if ($action === 'token') {
            $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 80);
            $account = (int)($post['account'] ?? 0);
            $scopes = array_values(array_intersect(array_keys(McpOAuth::scopes()), is_array($post['scopes'] ?? null) ? array_map('strval', $post['scopes']) : []));
            $days = max(1, min(McpStore::MAX_TOKEN_DAYS, (int)($post['days'] ?? 90)));
            if ($name === '' || $scopes === [] || !in_array($account, array_column($this->accounts($actor), 'id'), true)) {
                return $done(false, 'Give the token a name, an account and at least one permission.');
            }
            $id = $this->store->createConnection('token', $name, null, $account, (int)($actor['id'] ?? 0) ?: null, implode(' ', $scopes), $this->oauth->resourceUrl());
            $token = $this->store->issueTokens($id, false, $days * 86400)['access_token'];
            ($this->log)('mcp.token_created', 'info', 'mcp_connection', (string)$id, 'An MCP token was made: ' . $name . '.', ['scope' => implode(' ', $scopes), 'acts_as' => $account, 'days' => $days]);
            return $done(true, 'Token made. Copy it now: it is not shown again.', $token);
        }
        $id = (int)($post['connection'] ?? 0);
        $connection = $id > 0 ? $this->store->connection($id) : null;
        if ($connection === null) {
            return $done(false, 'That connection no longer exists.');
        }
        if ($action === 'policy') {
            $list = static fn(string $key): array => array_values(array_filter(array_map(static fn(mixed $v): string => Slug::plain((string)$v), is_array($post[$key] ?? null) ? $post[$key] : [])));
            $policy = ['types' => $list('types'), 'languages' => $list('languages'), 'daily_limit' => (int)($post['daily_limit'] ?? McpStore::DEFAULT_DAILY_LIMIT), 'byline' => (string)($post['byline'] ?? '')];
            $this->store->setPolicy($id, $policy);
            ($this->log)('mcp.connection_policy', 'info', 'mcp_connection', (string)$id, 'MCP connection rules changed: ' . $connection['name'] . '.', McpStore::policy(['policy' => json_encode($policy)]));
            return $done(true, 'Rules of ' . $connection['name'] . ' saved.');
        }
        $result = match ($action) {
            'pause' => $this->store->setPaused($id, true) ? 'Paused: its calls are refused until it is resumed.' : '',
            'resume' => $this->store->setPaused($id, false) ? 'Resumed.' : '',
            'revoke' => $this->store->revoke($id) ? 'Removed. The AI assistant has to connect again to use the site.' : '',
            default => '',
        };
        if ($result !== '') {
            ($this->log)('mcp.connection_' . $action, $action === 'revoke' ? 'warning' : 'info', 'mcp_connection', (string)$id, 'MCP connection ' . ($action === 'revoke' ? 'removed' : $action . 'd') . ': ' . $connection['name'] . '.', []);
        }
        return $done($result !== '', $result !== '' ? $result : 'Nothing was changed.');
    }

    /**
     * The discovery documents as files in public/.well-known/ while MCP is on, removed when it is off. A web server that answers
     * `/.well-known/` itself (nginx panels keep it for certificates) serves them as they are, without the site; Apache gets their
     * type from the .htaccess beside them. Written again when the site's address changes (each visit of the screen checks).
     */
    public function syncDiscoveryFiles(): void
    {
        if ($this->publicDir === '') {
            return;
        }
        $dir = $this->publicDir . '/.well-known';
        $on = $this->isEnabled();
        $files = [
            substr(McpOAuth::RESOURCE_METADATA_PATH, strlen('/.well-known/')) => fn(): array => $this->oauth->protectedResource(),
            substr(McpOAuth::SERVER_METADATA_PATH, strlen('/.well-known/')) => fn(): array => $this->oauth->authorizationServer(),
            '.htaccess' => null,
        ];
        // 0.1.190 kept the protected resource at the form without the path, a file where its folder now goes.
        if (is_file($dir . '/oauth-protected-resource')) {
            @unlink($dir . '/oauth-protected-resource');
        }
        foreach ($files as $name => $document) {
            $path = $dir . '/' . $name;
            $text = $document === null ? self::HTACCESS : json_encode($document(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
            // A .htaccess the site wrote itself is never touched.
            if ($document === null && is_file($path) && !str_starts_with((string)@file_get_contents($path), self::HTACCESS_MARK)) {
                continue;
            }
            if (!$on) {
                if (is_file($path)) {
                    @unlink($path);
                    if (dirname($path) !== $dir) {
                        @rmdir(dirname($path));
                    }
                }
                continue;
            }
            if (@file_get_contents($path) !== $text && (is_dir(dirname($path)) || @mkdir(dirname($path), 0755, true))) {
                @file_put_contents($path, $text, LOCK_EX);
            }
        }
    }

    /**
     * Whom a connection can act as: the person approving, or an active automation account. A super admin (the only one who has
     * MCP) can choose any automation account.
     *
     * @param array<string, mixed>|null $actor
     * @return array<int, array{id: int, label: string, automation: bool, role: string}>
     */
    public function accounts(?array $actor): array
    {
        $list = [];
        $self = (int)($actor['id'] ?? 0);
        foreach ($this->users->isAvailable() ? $this->users->all() : [] as $user) {
            $id = (int)$user['id'];
            $automation = UserRepository::isAutomation($user);
            if (($user['status'] ?? '') !== 'active' || ($id !== $self && !$automation)) {
                continue;
            }
            $label = (string)(($user['display_name'] ?? '') ?: $user['username']);
            $list[] = ['id' => $id, 'label' => $id === $self ? $label . ' (you)' : $label, 'automation' => $automation, 'role' => (string)$user['role']];
        }
        usort($list, static fn(array $a, array $b): int => [$b['automation'], $a['label']] <=> [$a['automation'], $b['label']]);
        return $list;
    }
}
