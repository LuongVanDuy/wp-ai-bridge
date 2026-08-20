<?php

defined('ABSPATH') || exit;

final class WPAIB_Fleet_Hub {
    private const OPT_ENABLED = 'wpaib_fleet_hub_enabled';
    private const OPT_APP_ID = 'wpaib_fleet_app_id';
    private const OPT_INSTALLATION_ID = 'wpaib_fleet_installation_id';
    private const OPT_PRIVATE_KEY = 'wpaib_fleet_private_key';
    private const OPT_PROVISION_TOKEN = 'wpaib_fleet_provision_token';
    private const OPT_ENROLL_HASH = 'wpaib_fleet_enroll_hash';
    private const OPT_ENROLL_EXPIRES = 'wpaib_fleet_enroll_expires';
    private const OPT_SITES = 'wpaib_fleet_sites';
    private const KEY_TTL = DAY_IN_SECONDS;

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('wp-ai-bridge/v1', '/fleet/enroll', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_enroll'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wp-ai-bridge/v1', '/fleet/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_token'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function enabled(): bool {
        return '1' === (string) get_option(self::OPT_ENABLED, '0');
    }

    public static function configured(): bool {
        return (int) get_option(self::OPT_APP_ID, 0) > 0
            && (int) get_option(self::OPT_INSTALLATION_ID, 0) > 0
            && '' !== WPAIB_Crypto::decrypt((string) get_option(self::OPT_PRIVATE_KEY, ''));
    }

    public static function status(): array {
        $sites = self::sites();
        $status = [
            'enabled' => self::enabled(),
            'configured' => self::configured(),
            'app_id' => (int) get_option(self::OPT_APP_ID, 0),
            'installation_id' => (int) get_option(self::OPT_INSTALLATION_ID, 0),
            'has_private_key' => '' !== (string) get_option(self::OPT_PRIVATE_KEY, ''),
            'has_provision_token' => '' !== (string) get_option(self::OPT_PROVISION_TOKEN, ''),
            'enroll_expires' => (int) get_option(self::OPT_ENROLL_EXPIRES, 0),
            'site_count' => count($sites),
            'account' => '',
            'account_type' => '',
            'error' => '',
        ];

        if ($status['configured']) {
            $installation = self::installation_info();
            if (is_wp_error($installation)) {
                $status['error'] = $installation->get_error_message();
            } else {
                $status['account'] = (string) ($installation['account']['login'] ?? '');
                $status['account_type'] = (string) ($installation['account']['type'] ?? '');
            }
        }
        return $status;
    }

    public static function save_config(bool $enabled, int $app_id, int $installation_id, string $private_key = '', string $provision_token = ''): bool|WP_Error {
        if ($app_id < 1 || $installation_id < 1) {
            return new WP_Error('wpaib_fleet_ids', 'GitHub App ID and Installation ID are required.');
        }

        if ('' !== trim($private_key)) {
            $private_key = str_replace(["\r\n", "\r"], "\n", trim($private_key)) . "\n";
            if (!function_exists('openssl_pkey_get_private') || false === openssl_pkey_get_private($private_key)) {
                return new WP_Error('wpaib_fleet_private_key', 'GitHub App private key is not a valid RSA private key.');
            }
            $encrypted = WPAIB_Crypto::encrypt($private_key);
            if (is_wp_error($encrypted)) return $encrypted;
            update_option(self::OPT_PRIVATE_KEY, $encrypted, false);
        } elseif ('' === (string) get_option(self::OPT_PRIVATE_KEY, '')) {
            return new WP_Error('wpaib_fleet_private_key_missing', 'GitHub App private key is required for first-time Hub setup.');
        }

        if ('' !== trim($provision_token)) {
            $encrypted = WPAIB_Crypto::encrypt(trim($provision_token));
            if (is_wp_error($encrypted)) return $encrypted;
            update_option(self::OPT_PROVISION_TOKEN, $encrypted, false);
        }

        update_option(self::OPT_APP_ID, $app_id, false);
        update_option(self::OPT_INSTALLATION_ID, $installation_id, false);
        update_option(self::OPT_ENABLED, $enabled ? '1' : '0', false);

        $test = self::installation_info();
        if (is_wp_error($test)) return $test;

        WPAIB_Audit::record('fleet_hub_configured', [
            'installation_id' => $installation_id,
            'account' => (string) ($test['account']['login'] ?? ''),
            'enabled' => $enabled,
        ]);
        return true;
    }

    public static function generate_fleet_key(): string|WP_Error {
        if (!self::enabled() || !self::configured()) {
            return new WP_Error('wpaib_fleet_hub_not_ready', 'Enable and configure Fleet Hub first.');
        }

        try {
            $code = 'wpaib_enroll_' . bin2hex(random_bytes(24));
        } catch (Throwable $error) {
            return new WP_Error('wpaib_fleet_random', 'Unable to generate enrollment key.');
        }
        $expires = time() + self::KEY_TTL;
        update_option(self::OPT_ENROLL_HASH, wp_hash_password($code), false);
        update_option(self::OPT_ENROLL_EXPIRES, $expires, false);

        $payload = wp_json_encode([
            'hub' => trailingslashit(rest_url('wp-ai-bridge/v1/fleet')),
            'code' => $code,
            'exp' => $expires,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) return new WP_Error('wpaib_fleet_key_encode', 'Unable to encode Fleet Key.');

        WPAIB_Audit::record('fleet_key_generated', ['expires' => gmdate('c', $expires)]);
        return 'wpaib_fleet_' . self::base64url_encode($payload);
    }

    public static function revoke_fleet_key(): void {
        delete_option(self::OPT_ENROLL_HASH);
        delete_option(self::OPT_ENROLL_EXPIRES);
        WPAIB_Audit::record('fleet_key_revoked');
    }

    public static function rest_enroll(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (!self::enabled() || !self::configured()) {
            return new WP_Error('wpaib_fleet_disabled', 'Fleet Hub is not available.', ['status' => 503]);
        }

        $code = trim((string) $request->get_param('code'));
        $site_id = sanitize_text_field((string) $request->get_param('site_id'));
        $site_url = esc_url_raw((string) $request->get_param('site_url'));
        if (!self::valid_enrollment_code($code)) {
            return new WP_Error('wpaib_fleet_enroll_denied', 'Fleet enrollment key is invalid or expired.', ['status' => 401]);
        }
        if (!preg_match('/^wpaib_site_[a-f0-9]{32}$/', $site_id)) {
            return new WP_Error('wpaib_fleet_site_id', 'Invalid site id.', ['status' => 400]);
        }
        if (!self::valid_site_url($site_url)) {
            return new WP_Error('wpaib_fleet_site_url', 'A valid HTTPS site URL is required.', ['status' => 400]);
        }

        $installation = self::installation_info();
        if (is_wp_error($installation)) return $installation;
        $owner = (string) ($installation['account']['login'] ?? '');
        $owner_type = (string) ($installation['account']['type'] ?? '');
        if ('' === $owner) return new WP_Error('wpaib_fleet_owner', 'Unable to determine GitHub App installation owner.', ['status' => 500]);

        $sites = self::sites();
        $existing = isset($sites[$site_id]) && is_array($sites[$site_id]) ? $sites[$site_id] : null;
        $repo_name = $existing ? self::repo_name_from_full((string) ($existing['repo'] ?? '')) : self::repo_slug($site_url);
        if ('' === $repo_name) return new WP_Error('wpaib_fleet_repo_name', 'Unable to derive repository name.', ['status' => 400]);

        if (!$existing) {
            foreach ($sites as $registered_id => $registered) {
                if (!is_array($registered) || $registered_id === $site_id) continue;
                if (($registered['repo'] ?? '') === $owner . '/' . $repo_name && ($registered['site_url'] ?? '') !== $site_url) {
                    $repo_name .= '-' . substr(hash('sha256', $site_url), 0, 8);
                    break;
                }
            }
        }

        $repo = self::ensure_repository($owner, $owner_type, $repo_name);
        if (is_wp_error($repo)) return $repo;
        $full_name = (string) ($repo['full_name'] ?? ($owner . '/' . $repo_name));

        $probe = self::installation_token($repo_name, ['contents' => 'write']);
        if (is_wp_error($probe)) {
            return new WP_Error(
                'wpaib_fleet_repo_access',
                'Repository was found/created but the GitHub App cannot access it. Install the App for all repositories or add this repository to the installation. ' . $probe->get_error_message(),
                ['status' => 403]
            );
        }

        try {
            $site_secret = 'wpaib_site_' . bin2hex(random_bytes(32));
        } catch (Throwable $error) {
            return new WP_Error('wpaib_fleet_random', 'Unable to generate site credential.', ['status' => 500]);
        }

        $sites[$site_id] = [
            'site_url' => $site_url,
            'repo' => $full_name,
            'branch' => 'main',
            'secret_hash' => wp_hash_password($site_secret),
            'created_at' => $existing['created_at'] ?? gmdate('c'),
            'last_seen' => gmdate('c'),
        ];
        self::save_sites($sites);

        WPAIB_Audit::record('fleet_site_enrolled', ['site_url' => $site_url, 'repo' => $full_name]);
        return new WP_REST_Response([
            'ok' => true,
            'site_id' => $site_id,
            'site_secret' => $site_secret,
            'repo' => $full_name,
            'branch' => 'main',
            'hub' => home_url('/'),
        ], 201);
    }

    public static function rest_token(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (!self::enabled() || !self::configured()) {
            return new WP_Error('wpaib_fleet_disabled', 'Fleet Hub is not available.', ['status' => 503]);
        }

        $site_id = sanitize_text_field((string) $request->get_param('site_id'));
        $secret = self::bearer_token($request);
        $sites = self::sites();
        if ('' === $site_id || !isset($sites[$site_id]) || !is_array($sites[$site_id])) {
            return new WP_Error('wpaib_fleet_site_unknown', 'Unknown Fleet site.', ['status' => 401]);
        }
        $site = $sites[$site_id];
        $hash = (string) ($site['secret_hash'] ?? '');
        if ('' === $secret || '' === $hash || !wp_check_password($secret, $hash)) {
            return new WP_Error('wpaib_fleet_site_denied', 'Invalid Fleet site credential.', ['status' => 401]);
        }

        $repo_name = self::repo_name_from_full((string) ($site['repo'] ?? ''));
        if ('' === $repo_name) return new WP_Error('wpaib_fleet_repo', 'Fleet repository is invalid.', ['status' => 500]);
        $token = self::installation_token($repo_name, ['contents' => 'write']);
        if (is_wp_error($token)) return $token;

        $sites[$site_id]['last_seen'] = gmdate('c');
        self::save_sites($sites);
        return new WP_REST_Response([
            'token' => (string) ($token['token'] ?? ''),
            'expires_at' => (string) ($token['expires_at'] ?? ''),
            'repo' => (string) ($site['repo'] ?? ''),
            'branch' => (string) ($site['branch'] ?? 'main'),
        ], 200);
    }

    private static function ensure_repository(string $owner, string $owner_type, string $repo_name): array|WP_Error {
        $read_token = self::installation_token(null, ['contents' => 'read']);
        if (!is_wp_error($read_token)) {
            $found = self::github_api('GET', '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo_name), (string) ($read_token['token'] ?? ''), null, [404]);
            if (!is_wp_error($found) && (int) ($found['_http_status'] ?? 200) !== 404) return $found;
        }

        $provision = self::provision_token();
        if ('' !== $provision) {
            $found = self::github_api('GET', '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo_name), $provision, null, [404]);
            if (!is_wp_error($found) && (int) ($found['_http_status'] ?? 200) !== 404) return $found;
        }

        $body = [
            'name' => $repo_name,
            'description' => 'WordPress source managed by WP AI Bridge Fleet',
            'private' => true,
            'auto_init' => true,
        ];

        if ('Organization' === $owner_type) {
            $admin_token = self::installation_token(null, ['administration' => 'write', 'contents' => 'write']);
            if (!is_wp_error($admin_token)) {
                $created = self::github_api('POST', '/orgs/' . rawurlencode($owner) . '/repos', (string) ($admin_token['token'] ?? ''), $body);
                if (!is_wp_error($created)) return $created;
            }
            if ('' !== $provision) {
                return self::github_api('POST', '/orgs/' . rawurlencode($owner) . '/repos', $provision, $body);
            }
            return new WP_Error('wpaib_fleet_repo_create', 'GitHub App could not create the organization repository. Grant Repository administration: write, or configure the optional Hub provisioning token.', ['status' => 403]);
        }

        if ('' === $provision) {
            return new WP_Error('wpaib_fleet_personal_provision', 'Personal GitHub installations need one provisioning token on the Hub to create repositories automatically. This token is stored only on the Hub; client sites never receive it.', ['status' => 400]);
        }
        $created = self::github_api('POST', '/user/repos', $provision, $body);
        if (is_wp_error($created)) return $created;
        if (($created['owner']['login'] ?? '') !== $owner) {
            return new WP_Error('wpaib_fleet_repo_owner', 'Provisioning token created the repository under a different GitHub account.', ['status' => 409]);
        }
        return $created;
    }

    private static function installation_info(): array|WP_Error {
        $jwt = self::app_jwt();
        if (is_wp_error($jwt)) return $jwt;
        $installation_id = (int) get_option(self::OPT_INSTALLATION_ID, 0);
        return self::github_api('GET', '/app/installations/' . $installation_id, $jwt);
    }

    private static function installation_token(?string $repo_name, array $permissions): array|WP_Error {
        $jwt = self::app_jwt();
        if (is_wp_error($jwt)) return $jwt;
        $installation_id = (int) get_option(self::OPT_INSTALLATION_ID, 0);
        $body = ['permissions' => $permissions];
        if (null !== $repo_name && '' !== $repo_name) $body['repositories'] = [$repo_name];
        return self::github_api('POST', '/app/installations/' . $installation_id . '/access_tokens', $jwt, $body);
    }

    private static function app_jwt(): string|WP_Error {
        if (!function_exists('openssl_sign')) return new WP_Error('wpaib_fleet_openssl', 'OpenSSL is required for GitHub App authentication.');
        $app_id = (int) get_option(self::OPT_APP_ID, 0);
        $private_key = WPAIB_Crypto::decrypt((string) get_option(self::OPT_PRIVATE_KEY, ''));
        if ($app_id < 1 || '' === $private_key) return new WP_Error('wpaib_fleet_app_config', 'GitHub App credentials are incomplete.');

        $now = time();
        $header = self::base64url_encode(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::base64url_encode(wp_json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) $app_id]));
        $unsigned = $header . '.' . $payload;
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $private_key, OPENSSL_ALGO_SHA256);
        if (!$ok) return new WP_Error('wpaib_fleet_jwt', 'Unable to sign GitHub App JWT.');
        return $unsigned . '.' . self::base64url_encode($signature);
    }

    private static function github_api(string $method, string $endpoint, string $token, ?array $body = null, array $allow_status = []): array|WP_Error {
        if ('' === $token) return new WP_Error('wpaib_fleet_github_token', 'GitHub credential is unavailable.');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'WP-AI-Bridge-Fleet/' . WPAIB_VERSION,
            ],
        ];
        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request('https://api.github.com' . $endpoint, $args);
        if (is_wp_error($response)) return $response;
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = '' !== $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        if ($status >= 200 && $status < 300) return $data;
        if (in_array($status, $allow_status, true)) {
            $data['_http_status'] = $status;
            return $data;
        }
        $message = isset($data['message']) ? (string) $data['message'] : 'GitHub API request failed.';
        return new WP_Error('wpaib_fleet_github_' . $status, $message, ['status' => $status]);
    }

    private static function valid_enrollment_code(string $code): bool {
        $hash = (string) get_option(self::OPT_ENROLL_HASH, '');
        $expires = (int) get_option(self::OPT_ENROLL_EXPIRES, 0);
        return '' !== $code && '' !== $hash && $expires >= time() && wp_check_password($code, $hash);
    }

    private static function valid_site_url(string $url): bool {
        if (false === wp_http_validate_url($url)) return false;
        $parts = wp_parse_url($url);
        return is_array($parts) && 'https' === strtolower((string) ($parts['scheme'] ?? '')) && '' !== (string) ($parts['host'] ?? '');
    }

    private static function repo_slug(string $site_url): string {
        $host = strtolower((string) wp_parse_url($site_url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', str_replace('.', '-', $host));
        $slug = trim((string) $slug, '-_.');
        if ('' === $slug) return '';
        return substr('wp-' . $slug, 0, 90);
    }

    private static function repo_name_from_full(string $full): string {
        $parts = explode('/', $full, 2);
        return 2 === count($parts) ? $parts[1] : '';
    }

    private static function provision_token(): string {
        return WPAIB_Crypto::decrypt((string) get_option(self::OPT_PROVISION_TOKEN, ''));
    }

    private static function sites(): array {
        $sites = get_option(self::OPT_SITES, []);
        return is_array($sites) ? $sites : [];
    }

    private static function save_sites(array $sites): void {
        if (count($sites) > 1000) $sites = array_slice($sites, -1000, null, true);
        update_option(self::OPT_SITES, $sites, false);
    }

    private static function bearer_token(WP_REST_Request $request): string {
        $header = trim((string) $request->get_header('authorization'));
        if ('' === $header && isset($_SERVER['HTTP_AUTHORIZATION'])) $header = trim((string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) return '';
        return trim((string) $matches[1]);
    }

    private static function base64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
