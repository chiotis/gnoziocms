<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * OAuth 2.1 for the MCP server (docs/mcp.md), as ChatGPT and Claude connect to it: discovery metadata, dynamic client
 * registration, the authorization code with S256 PKCE (the consent is a screen of the admin, `/admin/mcp-authorize`), and
 * tokens with rotating refresh tokens. Only public clients (no secret). Every answer is returned as
 * `['status' => int, 'json' => array, 'headers' => array]` for the caller to send.
 */
final class McpOAuth
{
    public const SCOPE_READ = 'content:read';
    public const SCOPE_WRITE = 'content:write';
    public const SCOPE_PUBLISH = 'content:publish';
    public const SCOPE_MEDIA = 'media:write';
    public const SCOPE_SHOP_READ = 'shop:read';
    public const SCOPE_SHOP_WRITE = 'shop:write';
    /** Scopes a person ticks on purpose: unticked on the consent screen and in Admin > MCP until then. */
    public const OFF_BY_DEFAULT = [self::SCOPE_SHOP_READ, self::SCOPE_SHOP_WRITE];

    public const AUTHORIZE_PATH = '/admin/mcp-authorize';
    public const TOKEN_PATH = '/_mcp/token';
    public const REGISTER_PATH = '/_mcp/register';
    public const ENDPOINT_PATH = '/mcp';
    /**
     * The discovery documents. Also kept as files under public/ (McpAdmin), for servers that serve `/.well-known/` themselves.
     * The protected resource is at the form without the endpoint's path, the one ChatGPT asks for; the form with it still
     * answers through the site where the web server passes it on.
     */
    public const RESOURCE_METADATA_PATH = '/.well-known/oauth-protected-resource';
    public const SERVER_METADATA_PATH = '/.well-known/oauth-authorization-server';

    /** @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log as App::logActivity */
    public function __construct(private McpStore $store, private string $baseUrl, private \Closure $log)
    {
    }

    /** @return array<string, string> scope => what it allows, in the order the consent screen shows them */
    public static function scopes(): array
    {
        return [
            self::SCOPE_READ => 'Read entries, terms and what the site offers, drafts included',
            self::SCOPE_WRITE => 'Write and edit drafts and scheduled entries',
            self::SCOPE_PUBLISH => 'Publish, schedule and change published entries',
            self::SCOPE_MEDIA => 'Add pictures to the media library',
            self::SCOPE_SHOP_READ => 'Read the shop: products with their prices and stock',
            self::SCOPE_SHOP_WRITE => 'Change prices, sales and stock in the shop',
        ];
    }

    /** @param string[] $required */
    public static function allows(string $scope, array $required): bool
    {
        $granted = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_intersect($required, $granted) !== [];
    }

    public function resourceUrl(): string
    {
        return $this->baseUrl . self::ENDPOINT_PATH;
    }

    /** @return array<string, mixed> RFC 9728 */
    public function protectedResource(): array
    {
        return [
            'resource' => $this->resourceUrl(),
            'authorization_servers' => [$this->baseUrl],
            'scopes_supported' => array_keys(self::scopes()),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'GnozioCMS',
        ];
    }

    /** @return array<string, mixed> RFC 8414 */
    public function authorizationServer(): array
    {
        return [
            'issuer' => $this->baseUrl,
            'authorization_endpoint' => $this->baseUrl . self::AUTHORIZE_PATH,
            'token_endpoint' => $this->baseUrl . self::TOKEN_PATH,
            'registration_endpoint' => $this->baseUrl . self::REGISTER_PATH,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => array_keys(self::scopes()),
        ];
    }

    /** RFC 7591, public clients only. @param mixed $payload the decoded JSON body @return array{status: int, json: array<string, mixed>, headers: array<string, string>} */
    public function register(mixed $payload): array
    {
        if (!is_array($payload)) {
            return $this->error('invalid_client_metadata', 'A JSON object is required.', 400);
        }
        $name = trim((string)($payload['client_name'] ?? 'MCP client'));
        $uris = $payload['redirect_uris'] ?? null;
        $grants = $payload['grant_types'] ?? ['authorization_code', 'refresh_token'];
        $responses = $payload['response_types'] ?? ['code'];
        $problem = match (true) {
            $name === '' || mb_strlen($name) > 120 => 'client_name must have 1 to 120 characters.',
            !is_array($uris) || $uris === [] || count($uris) > 20 => 'redirect_uris must list 1 to 20 addresses.',
            array_filter($uris, static fn(mixed $uri): bool => !is_string($uri) || !self::validRedirectUri(trim($uri))) !== [] => 'Every redirect URI must use HTTPS, or http on localhost.',
            !is_array($grants) || !in_array('authorization_code', $grants, true) || array_diff($grants, ['authorization_code', 'refresh_token']) !== [] => 'Only the authorization_code and refresh_token grants are supported.',
            !is_array($responses) || array_values(array_unique($responses)) !== ['code'] => 'Only the code response type is supported.',
            (string)($payload['token_endpoint_auth_method'] ?? 'none') !== 'none' => 'Only public clients with PKCE are supported (token_endpoint_auth_method: none).',
            default => '',
        };
        if ($problem !== '') {
            ($this->log)('mcp.client_refused', 'warning', 'mcp_client', null, 'An MCP client registration was refused.', ['reason' => $problem]);
            return $this->error('invalid_client_metadata', $problem, 400);
        }
        $client = $this->store->registerClient($name, array_values(array_unique(array_map(static fn(string $uri): string => trim($uri), $uris))));
        ($this->log)('mcp.client_registered', 'info', 'mcp_client', $client['client_id'], 'An MCP client registered: ' . $name . '.', ['client_name' => $name]);
        return ['status' => 201, 'headers' => [], 'json' => [
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'redirect_uris' => $client['redirect_uris'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]];
    }

    /**
     * The checked request of a consent (from the address, or kept in the session while the person signs in), or a problem.
     * A problem with the client or its redirect address is shown on the screen; any other is sent back to the client.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, problem: string, redirect: bool, request: array<string, mixed>}
     */
    public function authorizationRequest(array $input): array
    {
        $fail = static fn(string $problem, bool $redirect, array $request = []): array => ['ok' => false, 'problem' => $problem, 'redirect' => $redirect, 'request' => $request];
        $client = $this->store->client(trim((string)($input['client_id'] ?? '')));
        if ($client === null) {
            return $fail('This connection is not known to the site. Start it again from the AI assistant.', false);
        }
        $redirectUri = trim((string)($input['redirect_uri'] ?? ''));
        if (!in_array($redirectUri, $client['redirect_uris'], true)) {
            return $fail('The connection gave an address the site was not told about. Start it again from the AI assistant.', false);
        }
        $request = [
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'redirect_uri' => $redirectUri,
            'state' => mb_substr(trim((string)($input['state'] ?? '')), 0, 1000),
        ];
        if (trim((string)($input['response_type'] ?? '')) !== 'code') {
            return $fail('unsupported_response_type', true, $request);
        }
        $challenge = trim((string)($input['code_challenge'] ?? ''));
        if (strtoupper(trim((string)($input['code_challenge_method'] ?? ''))) !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
            return $fail('invalid_request', true, $request);
        }
        $resource = trim((string)($input['resource'] ?? $this->resourceUrl()));
        if ($resource !== $this->resourceUrl()) {
            return $fail('invalid_target', true, $request);
        }
        // No scope asked for is every scope; the person can untick any of them on the screen.
        $asked = preg_split('/\s+/', trim((string)($input['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array_keys(self::scopes());
        if (array_diff($asked, array_keys(self::scopes())) !== []) {
            return $fail('invalid_scope', true, $request);
        }
        return ['ok' => true, 'problem' => '', 'redirect' => false, 'request' => $request + [
            'scope' => implode(' ', array_values(array_intersect(array_keys(self::scopes()), $asked))),
            'code_challenge' => $challenge,
            'resource' => $resource,
        ]];
    }

    /**
     * The person's answer on the consent screen: where to send the browser. On approval a code is made for the account the
     * connection acts as and the scopes left ticked (only ones that were asked for).
     *
     * @param array<string, mixed> $request a checked request (authorizationRequest)
     * @param string[] $scopes the scopes left ticked
     */
    public function decide(array $request, bool $approve, int $actsAs, int $approvedBy, array $scopes): string
    {
        if (!$approve) {
            ($this->log)('mcp.consent_denied', 'info', 'mcp_client', (string)$request['client_id'], 'An MCP connection was refused: ' . $request['client_name'] . '.', []);
            return $this->back($request, ['error' => 'access_denied']);
        }
        $asked = preg_split('/\s+/', (string)$request['scope'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scope = implode(' ', array_values(array_intersect($asked, $scopes)));
        if ($scope === '') {
            return $this->back($request, ['error' => 'invalid_scope', 'error_description' => 'No permission was left for the connection.']);
        }
        $code = $this->store->createCode([
            'client_id' => (string)$request['client_id'],
            'user_id' => $actsAs,
            'approved_by' => $approvedBy,
            'redirect_uri' => (string)$request['redirect_uri'],
            'scope' => $scope,
            'code_challenge' => (string)$request['code_challenge'],
            'resource' => (string)$request['resource'],
        ]);
        ($this->log)('mcp.consent_given', 'info', 'mcp_client', (string)$request['client_id'], 'An MCP connection was approved: ' . $request['client_name'] . '.', ['scope' => $scope, 'acts_as' => $actsAs]);
        return $this->back($request, ['code' => $code]);
    }

    /**
     * The token endpoint (form fields). `$accountActive` says whether an account may still be used by a connection.
     *
     * @param array<string, mixed> $post
     * @param \Closure(int): bool $accountActive
     * @return array{status: int, json: array<string, mixed>, headers: array<string, string>}
     */
    public function token(array $post, \Closure $accountActive): array
    {
        $clientId = mb_substr(trim((string)($post['client_id'] ?? '')), 0, 200);
        $client = $clientId !== '' ? $this->store->client($clientId) : null;
        if ($client === null) {
            return $this->error('invalid_client', 'The client is not known.', 401);
        }
        $resource = trim((string)($post['resource'] ?? $this->resourceUrl()));
        $grant = (string)($post['grant_type'] ?? '');
        if ($grant === 'authorization_code') {
            $verifier = trim((string)($post['code_verifier'] ?? ''));
            if (strlen($verifier) < 43 || strlen($verifier) > 128 || preg_match('/^[A-Za-z0-9._~-]+$/', $verifier) !== 1) {
                return $this->refused($clientId, $grant, 'The PKCE code_verifier is missing or malformed.');
            }
            $code = $this->store->consumeCode(trim((string)($post['code'] ?? '')), $clientId);
            if ($code === null || $code['redirect_uri'] !== trim((string)($post['redirect_uri'] ?? '')) || $code['resource'] !== $resource) {
                return $this->refused($clientId, $grant, 'The code is not valid, was used, or has expired.');
            }
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!hash_equals((string)$code['code_challenge'], $challenge)) {
                return $this->refused($clientId, $grant, 'The PKCE check failed.');
            }
            if (!$accountActive((int)$code['user_id'])) {
                return $this->refused($clientId, $grant, 'The account of this connection is not active.');
            }
            $connection = $this->store->createConnection('oauth', $client['client_name'], $clientId, (int)$code['user_id'], (int)$code['approved_by'], (string)$code['scope'], $resource);
            ($this->log)('mcp.connected', 'info', 'mcp_connection', (string)$connection, 'An MCP connection started: ' . $client['client_name'] . '.', ['scope' => $code['scope'], 'acts_as' => (int)$code['user_id']]);
            return $this->tokens($this->store->issueTokens($connection), (string)$code['scope']);
        }
        if ($grant === 'refresh_token') {
            $refreshed = $this->store->refresh(trim((string)($post['refresh_token'] ?? '')), $clientId, $resource);
            if ($refreshed === null || !$accountActive((int)$refreshed['connection']['user_id'])) {
                return $this->refused($clientId, $grant, 'The refresh token is not valid or has expired.');
            }
            return $this->tokens($refreshed['tokens'], (string)$refreshed['connection']['scope']);
        }
        return $this->error('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.', 400);
    }

    /** Where to send the browser back with an OAuth error (RFC 6749 4.1.2.1). @param array<string, mixed> $request */
    public function errorRedirect(array $request, string $error): string
    {
        return $this->back($request, ['error' => $error]);
    }

    public static function validRedirectUri(string $uri): bool
    {
        if ($uri === '' || str_contains($uri, '#') || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string)parse_url($uri, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($uri, PHP_URL_HOST));
        return ($scheme === 'https' && $host !== '') || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /** @return array{status: int, json: array<string, mixed>, headers: array<string, string>} */
    public static function error(string $error, string $description, int $status): array
    {
        return ['status' => $status, 'headers' => [], 'json' => ['error' => $error, 'error_description' => $description]];
    }

    /** @param array<string, mixed> $request @param array<string, string> $params */
    private function back(array $request, array $params): string
    {
        if ((string)($request['state'] ?? '') !== '') {
            $params['state'] = (string)$request['state'];
        }
        $uri = (string)$request['redirect_uri'];
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array{access_token: string, expires_in: int, refresh_token?: string} $tokens @return array{status: int, json: array<string, mixed>, headers: array<string, string>} */
    private function tokens(array $tokens, string $scope): array
    {
        return ['status' => 200, 'headers' => ['Pragma' => 'no-cache'], 'json' => ['token_type' => 'Bearer', 'scope' => $scope] + $tokens];
    }

    /** @return array{status: int, json: array<string, mixed>, headers: array<string, string>} */
    private function refused(string $clientId, string $grant, string $reason): array
    {
        ($this->log)('mcp.token_refused', 'warning', 'mcp_client', $clientId, 'An MCP token request was refused.', ['grant_type' => $grant, 'reason' => $reason]);
        return $this->error('invalid_grant', $reason, 400);
    }
}
