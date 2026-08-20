<?php

defined('ABSPATH') || exit;

final class WPAIB_Fleet_Hub {
    private const OPT_ENROLL_HASH = 'wpaib_fleet_enroll_hash';
    private const OPT_ENROLL_EXPIRES = 'wpaib_fleet_enroll_expires';
    private const OPT_SITES = 'wpaib_fleet_sites';
    private const KEY_TTL = 604800; // 7 days.

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('wp-ai-bridge/v1', '/fleet/enroll', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_enroll'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wp-ai-bridge/v1', '/fleet/github', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_github'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('wp-ai-bridge/v1', '/fleet/token', [
            'methods' => 'POST',
            'callback' => static function (): WP_Error {
                return new WP_Error('wpaib_fleet_upgrade_required', 'Fleet token mode was removed in WP AI Bridge 0.8. Update the client site.', ['status' => 410]);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    public static function enabled(): bool {
        return class_exists('WPAIB_GitHub_OAuth') && WPAIB_GitHub_OAuth::connected();
    }

    public static function configured(): bool {
        return self::enabled();
    }

    public static function status(): array {
        return [
            'enabled' => self::enabled(),
            'configured' => self::configured(),
            'account' => class_exists('WPAIB_GitHub_OAuth') ? WPAIB_GitHub_OAuth::login() : '',
            'site_count' => count(self::sites()),
            'enroll_expires' => (int) get_option(self::OPT_ENROLL_EXPIRES, 0),
        ];
    }

    public static function generate_fleet_key(): string|WP_Error {
        if (!self::enabled()) return new WP_Error('wpaib_fleet_hub_not_ready', 'Connect GitHub on the Hub first.');

        try {
            $code = 'wpaib_enroll_' . bin2hex(random_bytes(24));
        } catch (Throwable $error) {
            return new WP_Error('wpaib_fleet_random', 'Unable to generate Fleet Key.');
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
        if (!self::enabled()) return new WP_Error('wpaib_fleet_disabled', 'Fleet Hub is not connected to GitHub.', ['status' => 503]);

        $params = $request->get_json_params();
        if (!is_array($params)) $params = [];
        $code = trim((string) ($params['code'] ?? $request->get_param('code')));
        $site_id = sanitize_text_field((string) ($params['site_id'] ?? $request->get_param('site_id')));
        $site_url = esc_url_raw((string) ($params['site_url'] ?? $request->get_param('site_url')));

        if (!self::valid_enrollment_code($code)) {
            return new WP_Error('wpaib_fleet_enroll_denied', 'Fleet Key is invalid or expired.', ['status' => 401]);
        }
        if (!preg_match('/^wpaib_site_[a-f0-9]{32}$/', $site_id)) {
            return new WP_Error('wpaib_fleet_site_id', 'Invalid site id.', ['status' => 400]);
        }
        if (!self::valid_site_url($site_url)) {
            return new WP_Error('wpaib_fleet_site_url', 'A valid HTTPS site URL is required.', ['status' => 400]);
        }

        $owner = WPAIB_GitHub_OAuth::login();
        if ('' === $owner) return new WP_Error('wpaib_fleet_owner', 'Unable to identify the connected GitHub account.', ['status' => 500]);

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

        $repo = self::ensure_repository($owner, $repo_name);
        if (is_wp_error($repo)) return $repo;
        $full_name = (string) ($repo['full_name'] ?? ($owner . '/' . $repo_name));

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
            'created_at' => is_array($existing) ? (string) ($existing['created_at'] ?? gmdate('c')) : gmdate('c'),
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
        ], 201);
    }

    public static function rest_github(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (!self::enabled()) return new WP_Error('wpaib_fleet_disabled', 'Fleet Hub is not connected to GitHub.', ['status' => 503]);

        $params = $request->get_json_params();
        if (!is_array($params)) $params = [];
        $site_id = sanitize_text_field((string) ($params['site_id'] ?? ''));
        $secret = self::bearer_token($request);
        $site = self::authorize_site($site_id, $secret);
        if (is_wp_error($site)) return $site;

        $method = strtoupper((string) ($params['method'] ?? 'GET'));
        $endpoint = (string) ($params['endpoint'] ?? '');
        $body = array_key_exists('body', $params) && is_string($params['body']) ? $params['body'] : null;
        if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
            return new WP_Error('wpaib_fleet_method', 'GitHub method is not allowed.', ['status' => 405]);
        }
        if (!self::endpoint_belongs_to_repo($endpoint, (string) ($site['repo'] ?? ''))) {
            return new WP_Error('wpaib_fleet_endpoint', 'GitHub request is outside this site repository.', ['status' => 403]);
        }

        $result = WPAIB_GitHub_OAuth::raw_api($method, $endpoint, $body);
        if (is_wp_error($result)) return $result;

        $sites = self::sites();
        if (isset($sites[$site_id]) && is_array($sites[$site_id])) {
            $sites[$site_id]['last_seen'] = gmdate('c');
            self::save_sites($sites);
        }

        return new WP_REST_Response([
            'status' => (int) ($result['status'] ?? 500),
            'body' => (string) ($result['body'] ?? ''),
        ], 200);
    }

    private static function ensure_repository(string $owner, string $repo_name): array|WP_Error {
        $endpoint = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo_name);
        $found = WPAIB_GitHub_OAuth::api('GET', $endpoint, null, [404]);
        if (is_wp_error($found)) return $found;
        if ((int) ($found['_http_status'] ?? 200) !== 404) return $found;

        return WPAIB_GitHub_OAuth::api('POST', '/user/repos', [
            'name' => $repo_name,
            'description' => 'WordPress source managed by WP AI Bridge',
            'private' => true,
            'auto_init' => true,
        ]);
    }

    private static function authorize_site(string $site_id, string $secret): array|WP_Error {
        $sites = self::sites();
        if ('' === $site_id || !isset($sites[$site_id]) || !is_array($sites[$site_id])) {
            return new WP_Error('wpaib_fleet_site_unknown', 'Unknown Fleet site.', ['status' => 401]);
        }
        $site = $sites[$site_id];
        $hash = (string) ($site['secret_hash'] ?? '');
        if ('' === $secret || '' === $hash || !wp_check_password($secret, $hash)) {
            return new WP_Error('wpaib_fleet_site_denied', 'Invalid Fleet site credential.', ['status' => 401]);
        }
        return $site;
    }

    private static function endpoint_belongs_to_repo(string $endpoint, string $repo): bool {
        if (!preg_match('#^[/?A-Za-z0-9._%=&+\-]+$#', $endpoint)) return false;
        $path = explode('?', $endpoint, 2)[0];
        $path = rawurldecode($path);
        $prefix = '/repos/' . $repo;
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    private static function valid_enrollment_code(string $code): bool {
        $hash = (string) get_option(self::OPT_ENROLL_HASH, '');
        $expires = (int) get_option(self::OPT_ENROLL_EXPIRES, 0);
        return '' !== $code && '' !== $hash && $expires > time() && wp_check_password($code, $hash);
    }

    private static function valid_site_url(string $url): bool {
        if (false === wp_http_validate_url($url)) return false;
        $parts = wp_parse_url($url);
        return is_array($parts) && 'https' === strtolower((string) ($parts['scheme'] ?? '')) && '' !== (string) ($parts['host'] ?? '');
    }

    private static function repo_slug(string $site_url): string {
        $host = strtolower((string) wp_parse_url($site_url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $host);
        $slug = trim((string) $slug, '-');
        return '' === $slug ? '' : substr('wp-' . $slug, 0, 90);
    }

    private static function repo_name_from_full(string $repo): string {
        $parts = explode('/', $repo, 2);
        return 2 === count($parts) ? $parts[1] : '';
    }

    private static function sites(): array {
        $sites = get_option(self::OPT_SITES, []);
        return is_array($sites) ? $sites : [];
    }

    private static function save_sites(array $sites): void {
        if (count($sites) > 500) $sites = array_slice($sites, -500, null, true);
        update_option(self::OPT_SITES, $sites, false);
    }

    private static function bearer_token(WP_REST_Request $request): string {
        $header = trim((string) $request->get_header('authorization'));
        if ('' === $header && isset($_SERVER['HTTP_AUTHORIZATION'])) $header = trim((string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) return '';
        $token = trim((string) $matches[1]);
        return strlen($token) <= 256 ? $token : '';
    }

    private static function base64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
