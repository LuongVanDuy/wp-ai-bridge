<?php

defined('ABSPATH') || exit;

final class WPAIB_OAuth {
    private const CLIENTS_OPTION = 'wpaib_oauth_clients';
    private const TOKENS_OPTION = 'wpaib_oauth_tokens';
    private const CODE_PREFIX = 'wpaib_oauth_code_';
    private const ACCESS_TTL = 3600;
    private const REFRESH_TTL = 2592000;
    private const SCOPE = 'wpaib';

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('parse_request', [self::class, 'maybe_serve_well_known'], 0);
        add_action('admin_post_wpaib_oauth_authorize', [self::class, 'authorize']);
        add_action('admin_post_nopriv_wpaib_oauth_authorize', [self::class, 'authorize']);
        add_filter('rest_post_dispatch', [self::class, 'add_auth_challenge'], 10, 3);
    }

    public static function issuer(): string {
        return trailingslashit(home_url('/'));
    }

    public static function authorization_url(): string {
        return admin_url('admin-post.php?action=wpaib_oauth_authorize');
    }

    public static function token_url(): string {
        return rest_url('wp-ai-bridge/v1/oauth/token');
    }

    public static function registration_url(): string {
        return rest_url('wp-ai-bridge/v1/oauth/register');
    }

    public static function revocation_url(): string {
        return rest_url('wp-ai-bridge/v1/oauth/revoke');
    }

    public static function protected_resource_url(): string {
        return home_url('/.well-known/oauth-protected-resource');
    }

    public static function authorization_metadata_url(): string {
        return home_url('/.well-known/oauth-authorization-server');
    }

    public static function protected_resource_metadata(): array {
        return [
            'resource' => class_exists('WPAIB_MCP') ? WPAIB_MCP::endpoint_url() : rest_url('wp-ai-bridge/v1/mcp'),
            'authorization_servers' => [self::issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE, 'offline_access'],
            'resource_name' => 'WP AI Bridge',
        ];
    }

    public static function authorization_server_metadata(): array {
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => self::authorization_url(),
            'token_endpoint' => self::token_url(),
            'registration_endpoint' => self::registration_url(),
            'revocation_endpoint' => self::revocation_url(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE, 'offline_access'],
        ];
    }

    public static function register_routes(): void {
        register_rest_route('wp-ai-bridge/v1', '/oauth/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'register_client'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wp-ai-bridge/v1', '/oauth/token', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'token'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wp-ai-bridge/v1', '/oauth/revoke', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'revoke'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wp-ai-bridge/v1', '/oauth/protected-resource', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => static fn(): WP_REST_Response => new WP_REST_Response(self::protected_resource_metadata()),
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wp-ai-bridge/v1', '/oauth/authorization-server', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => static fn(): WP_REST_Response => new WP_REST_Response(self::authorization_server_metadata()),
            'permission_callback' => '__return_true',
        ]);
    }

    public static function maybe_serve_well_known($wp): void {
        if (!is_object($wp) || !isset($wp->request)) return;
        $request = trim((string) $wp->request, '/');
        if ('.well-known/oauth-protected-resource' === $request) {
            self::send_json(self::protected_resource_metadata());
        }
        if ('.well-known/oauth-authorization-server' === $request) {
            self::send_json(self::authorization_server_metadata());
        }
    }

    public static function add_auth_challenge($response, $server, $request) {
        if (!$response instanceof WP_REST_Response || !$request instanceof WP_REST_Request) return $response;
        $route = $request->get_route();
        if (401 === $response->get_status() && str_starts_with($route, '/wp-ai-bridge/v1/')) {
            $response->header('WWW-Authenticate', self::challenge_header());
        }
        return $response;
    }

    public static function challenge_header(): string {
        return 'Bearer resource_metadata="' . esc_url_raw(self::protected_resource_url()) . '", scope="' . self::SCOPE . '"';
    }

    public static function register_client(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = $request->get_params();

        $redirect_uris = isset($body['redirect_uris']) && is_array($body['redirect_uris']) ? array_values($body['redirect_uris']) : [];
        if (!$redirect_uris || count($redirect_uris) > 10) return self::oauth_error('invalid_client_metadata', 'One or more redirect_uris are required.', 400);

        $valid_redirects = [];
        foreach ($redirect_uris as $uri) {
            if (!is_string($uri) || !self::allowed_redirect_uri($uri)) {
                return self::oauth_error('invalid_redirect_uri', 'Only HTTPS OpenAI/ChatGPT redirect URIs are allowed.', 400);
            }
            $valid_redirects[] = esc_url_raw($uri);
        }

        $auth_method = isset($body['token_endpoint_auth_method']) ? (string) $body['token_endpoint_auth_method'] : 'none';
        if ('none' !== $auth_method) return self::oauth_error('invalid_client_metadata', 'token_endpoint_auth_method must be none.', 400);

        $client_id = 'wpaib_client_' . bin2hex(random_bytes(18));
        $clients = self::clients();
        $clients[$client_id] = [
            'redirect_uris' => $valid_redirects,
            'client_name' => sanitize_text_field((string) ($body['client_name'] ?? 'ChatGPT')),
            'created_at' => time(),
        ];
        self::save_clients($clients);

        WPAIB_Audit::record('oauth_client_registered', ['client_id' => $client_id]);
        return new WP_REST_Response([
            'client_id' => $client_id,
            'client_id_issued_at' => time(),
            'redirect_uris' => $valid_redirects,
            'client_name' => $clients[$client_id]['client_name'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], 201);
    }

    public static function authorize(): void {
        $request_url = self::current_authorize_url();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($request_url));
            exit;
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Administrator permission is required to authorize WP AI Bridge.', 'wp-ai-bridge'), 403);
        }

        $params = self::authorization_params();
        if (is_wp_error($params)) {
            wp_die(esc_html($params->get_error_message()), 400);
        }

        if ('POST' === strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            check_admin_referer('wpaib_oauth_authorize_' . $params['client_id']);
            $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : 'deny';
            if ('approve' !== $decision) {
                self::redirect_oauth($params['redirect_uri'], ['error' => 'access_denied', 'state' => $params['state']]);
            }

            $code = 'wpaib_code_' . bin2hex(random_bytes(32));
            set_transient(self::CODE_PREFIX . self::token_hash($code), [
                'client_id' => $params['client_id'],
                'redirect_uri' => $params['redirect_uri'],
                'code_challenge' => $params['code_challenge'],
                'scope' => $params['scope'],
                'resource' => $params['resource'],
                'user_id' => get_current_user_id(),
            ], 5 * MINUTE_IN_SECONDS);

            WPAIB_Audit::record('oauth_authorized', ['client_id' => $params['client_id']]);
            self::redirect_oauth($params['redirect_uri'], ['code' => $code, 'state' => $params['state']]);
        }

        self::render_consent($params);
    }

    public static function token(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $grant_type = (string) $request->get_param('grant_type');
        if ('authorization_code' === $grant_type) return self::exchange_code($request);
        if ('refresh_token' === $grant_type) return self::exchange_refresh($request);
        return self::oauth_error('unsupported_grant_type', 'Supported grants are authorization_code and refresh_token.', 400);
    }

    public static function revoke(WP_REST_Request $request): WP_REST_Response {
        $token = trim((string) $request->get_param('token'));
        if ('' !== $token) {
            $tokens = self::tokens();
            unset($tokens[self::token_hash($token)]);
            self::save_tokens($tokens);
        }
        return new WP_REST_Response(null, 200);
    }

    public static function validate_access_token(string $token): array|false {
        if (!str_starts_with($token, 'wpaib_at_')) return false;
        $tokens = self::tokens();
        $record = $tokens[self::token_hash($token)] ?? null;
        if (!is_array($record) || 'access' !== ($record['type'] ?? '') || (int) ($record['expires_at'] ?? 0) <= time()) return false;
        $user_id = (int) ($record['user_id'] ?? 0);
        if ($user_id < 1 || !user_can($user_id, 'manage_options')) return false;
        return $record;
    }

    private static function exchange_code(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $code = trim((string) $request->get_param('code'));
        $client_id = trim((string) $request->get_param('client_id'));
        $redirect_uri = trim((string) $request->get_param('redirect_uri'));
        $verifier = trim((string) $request->get_param('code_verifier'));
        if ('' === $code || '' === $client_id || '' === $redirect_uri || '' === $verifier) return self::oauth_error('invalid_request', 'code, client_id, redirect_uri and code_verifier are required.', 400);

        $key = self::CODE_PREFIX . self::token_hash($code);
        $record = get_transient($key);
        if (!is_array($record)) return self::oauth_error('invalid_grant', 'Authorization code is invalid or expired.', 400);
        if (!hash_equals((string) $record['client_id'], $client_id) || !hash_equals((string) $record['redirect_uri'], $redirect_uri)) return self::oauth_error('invalid_grant', 'Client or redirect URI mismatch.', 400);

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $record['code_challenge'], $computed)) return self::oauth_error('invalid_grant', 'PKCE verification failed.', 400);

        delete_transient($key);
        $payload = self::issue_tokens($client_id, (int) $record['user_id'], (string) $record['scope']);
        WPAIB_Audit::record('oauth_token_issued', ['client_id' => $client_id]);
        return new WP_REST_Response($payload, 200);
    }

    private static function exchange_refresh(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $refresh = trim((string) $request->get_param('refresh_token'));
        $client_id = trim((string) $request->get_param('client_id'));
        if ('' === $refresh || '' === $client_id) return self::oauth_error('invalid_request', 'refresh_token and client_id are required.', 400);

        $tokens = self::tokens();
        $hash = self::token_hash($refresh);
        $record = $tokens[$hash] ?? null;
        if (!is_array($record) || 'refresh' !== ($record['type'] ?? '') || (int) ($record['expires_at'] ?? 0) <= time()) return self::oauth_error('invalid_grant', 'Refresh token is invalid or expired.', 400);
        if (!hash_equals((string) $record['client_id'], $client_id)) return self::oauth_error('invalid_grant', 'Client mismatch.', 400);
        $user_id = (int) ($record['user_id'] ?? 0);
        if ($user_id < 1 || !user_can($user_id, 'manage_options')) return self::oauth_error('invalid_grant', 'The authorizing WordPress administrator no longer has access.', 400);

        unset($tokens[$hash]);
        self::save_tokens($tokens);
        $payload = self::issue_tokens($client_id, $user_id, (string) ($record['scope'] ?? self::SCOPE));
        WPAIB_Audit::record('oauth_token_refreshed', ['client_id' => $client_id]);
        return new WP_REST_Response($payload, 200);
    }

    private static function issue_tokens(string $client_id, int $user_id, string $scope): array {
        $scope = self::normalize_scope($scope);
        $access = 'wpaib_at_' . bin2hex(random_bytes(32));
        $refresh = 'wpaib_rt_' . bin2hex(random_bytes(32));
        $tokens = self::tokens();
        $tokens[self::token_hash($access)] = [
            'type' => 'access', 'client_id' => $client_id, 'user_id' => $user_id,
            'scope' => $scope, 'expires_at' => time() + self::ACCESS_TTL,
        ];
        $tokens[self::token_hash($refresh)] = [
            'type' => 'refresh', 'client_id' => $client_id, 'user_id' => $user_id,
            'scope' => $scope, 'expires_at' => time() + self::REFRESH_TTL,
        ];
        self::save_tokens($tokens);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    private static function authorization_params(): array|WP_Error {
        $client_id = isset($_REQUEST['client_id']) ? sanitize_text_field(wp_unslash($_REQUEST['client_id'])) : '';
        $redirect_uri = isset($_REQUEST['redirect_uri']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_uri'])) : '';
        $response_type = isset($_REQUEST['response_type']) ? sanitize_key(wp_unslash($_REQUEST['response_type'])) : '';
        $code_challenge = isset($_REQUEST['code_challenge']) ? sanitize_text_field(wp_unslash($_REQUEST['code_challenge'])) : '';
        $method = isset($_REQUEST['code_challenge_method']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['code_challenge_method']))) : '';
        $state = isset($_REQUEST['state']) ? sanitize_text_field(wp_unslash($_REQUEST['state'])) : '';
        $scope = isset($_REQUEST['scope']) ? self::normalize_scope((string) wp_unslash($_REQUEST['scope'])) : self::SCOPE . ' offline_access';
        $resource = isset($_REQUEST['resource']) ? esc_url_raw(wp_unslash($_REQUEST['resource'])) : '';

        $clients = self::clients();
        $client = $clients[$client_id] ?? null;
        if (!is_array($client)) return new WP_Error('wpaib_oauth_client', 'Unknown OAuth client.');
        if ('code' !== $response_type) return new WP_Error('wpaib_oauth_response', 'response_type must be code.');
        if (!in_array($redirect_uri, $client['redirect_uris'] ?? [], true)) return new WP_Error('wpaib_oauth_redirect', 'redirect_uri is not registered.');
        if ('S256' !== $method || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $code_challenge)) return new WP_Error('wpaib_oauth_pkce', 'PKCE S256 is required.');
        if (!str_contains(' ' . $scope . ' ', ' ' . self::SCOPE . ' ')) return new WP_Error('wpaib_oauth_scope', 'The wpaib scope is required.');
        if ('' !== $resource && class_exists('WPAIB_MCP') && !hash_equals(WPAIB_MCP::endpoint_url(), $resource)) return new WP_Error('wpaib_oauth_resource', 'Unknown OAuth resource.');

        return [
            'client_id' => $client_id,
            'client_name' => (string) ($client['client_name'] ?? 'ChatGPT'),
            'redirect_uri' => $redirect_uri,
            'response_type' => $response_type,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'scope' => $scope,
            'resource' => $resource,
        ];
    }

    private static function render_consent(array $params): void {
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        $site = get_bloginfo('name');
        $action = admin_url('admin-post.php');
        echo '<!doctype html><html><head><meta charset="' . esc_attr(get_option('blog_charset')) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Authorize WP AI Bridge</title>';
        echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f7f7;margin:0;padding:40px 16px;color:#1d2327}.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:28px}.muted{color:#646970}.scope{background:#f6f7f7;border-radius:6px;padding:12px 14px;margin:18px 0}.actions{display:flex;gap:10px}.button{display:inline-block;border:1px solid #2271b1;border-radius:4px;padding:8px 14px;font-size:14px;cursor:pointer}.primary{background:#2271b1;color:#fff}.secondary{background:#fff;color:#2c3338;border-color:#8c8f94}</style></head><body><div class="box">';
        echo '<h1 style="margin-top:0">Authorize WP AI Bridge</h1>';
        echo '<p><strong>' . esc_html($params['client_name']) . '</strong> wants to connect to <strong>' . esc_html($site) . '</strong>.</p>';
        echo '<div class="scope"><strong>Allowed</strong><br>Read, create, edit, delete and restore files inside <code>wp-content/plugins/</code> and <code>wp-content/themes/</code>; activate/deactivate plugins.<br><br><strong>Blocked</strong><br>WordPress core, <code>wp-config.php</code>, shell commands and arbitrary database access.</div>';
        echo '<p class="muted">Existing files are backed up before overwrite/delete, and PHP writes are syntax-checked.</p>';
        echo '<form method="post" action="' . esc_url($action) . '"><input type="hidden" name="action" value="wpaib_oauth_authorize">';
        foreach (['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','state','scope','resource'] as $key) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $params[$key]) . '">';
        }
        wp_nonce_field('wpaib_oauth_authorize_' . $params['client_id']);
        echo '<div class="actions"><button class="button primary" type="submit" name="decision" value="approve">Authorize</button><button class="button secondary" type="submit" name="decision" value="deny">Cancel</button></div></form>';
        echo '</div></body></html>';
        exit;
    }

    private static function current_authorize_url(): string {
        $args = [];
        foreach ($_GET as $key => $value) {
            if (is_scalar($value)) $args[sanitize_key((string) $key)] = wp_unslash((string) $value);
        }
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    private static function redirect_oauth(string $redirect_uri, array $args): void {
        if (!self::allowed_redirect_uri($redirect_uri)) wp_die('Invalid OAuth redirect URI.', 400);
        $args = array_filter($args, static fn($value): bool => null !== $value && '' !== $value);
        wp_redirect(add_query_arg($args, $redirect_uri), 302, 'WP AI Bridge');
        exit;
    }

    private static function allowed_redirect_uri(string $uri): bool {
        $parts = wp_parse_url($uri);
        if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))) return false;
        $host = strtolower((string) ($parts['host'] ?? ''));
        return 'chatgpt.com' === $host || str_ends_with($host, '.chatgpt.com') || 'openai.com' === $host || str_ends_with($host, '.openai.com');
    }

    private static function normalize_scope(string $scope): string {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        $parts = array_values(array_unique(array_filter(array_map('sanitize_key', $parts))));
        if (!in_array(self::SCOPE, $parts, true)) array_unshift($parts, self::SCOPE);
        if (!in_array('offline_access', $parts, true)) $parts[] = 'offline_access';
        return implode(' ', $parts);
    }

    private static function clients(): array {
        $clients = get_option(self::CLIENTS_OPTION, []);
        if (!is_array($clients)) return [];
        $cutoff = time() - 90 * DAY_IN_SECONDS;
        foreach ($clients as $id => $client) {
            if (!is_array($client) || (int) ($client['created_at'] ?? 0) < $cutoff) unset($clients[$id]);
        }
        return $clients;
    }

    private static function save_clients(array $clients): void {
        if (count($clients) > 30) {
            uasort($clients, static fn($a, $b): int => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
            $clients = array_slice($clients, 0, 30, true);
        }
        update_option(self::CLIENTS_OPTION, $clients, false);
    }

    private static function tokens(): array {
        $tokens = get_option(self::TOKENS_OPTION, []);
        if (!is_array($tokens)) return [];
        $now = time();
        $dirty = false;
        foreach ($tokens as $hash => $record) {
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) <= $now) {
                unset($tokens[$hash]);
                $dirty = true;
            }
        }
        if ($dirty) self::save_tokens($tokens);
        return $tokens;
    }

    private static function save_tokens(array $tokens): void {
        if (count($tokens) > 100) {
            uasort($tokens, static fn($a, $b): int => (int) ($b['expires_at'] ?? 0) <=> (int) ($a['expires_at'] ?? 0));
            $tokens = array_slice($tokens, 0, 100, true);
        }
        update_option(self::TOKENS_OPTION, $tokens, false);
    }

    private static function token_hash(string $token): string {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private static function oauth_error(string $error, string $description, int $status): WP_Error {
        return new WP_Error($error, $description, ['status' => $status, 'oauth_error' => $error]);
    }

    private static function send_json(array $data): void {
        status_header(200);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
