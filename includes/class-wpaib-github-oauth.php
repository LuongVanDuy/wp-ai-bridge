<?php

defined('ABSPATH') || exit;

final class WPAIB_GitHub_OAuth {
    private const OPT_ACCESS_TOKEN = 'wpaib_github_oauth_access_token';
    private const OPT_LOGIN = 'wpaib_github_oauth_login';
    private const DEVICE_PREFIX = 'wpaib_github_device_';

    public static function configured(): bool {
        return '' !== self::client_id();
    }

    public static function connected(): bool {
        return '' !== self::access_token() && '' !== self::login();
    }

    public static function client_id(): string {
        return defined('WPAIB_GITHUB_OAUTH_CLIENT_ID') ? trim((string) WPAIB_GITHUB_OAUTH_CLIENT_ID) : '';
    }

    public static function login(): string {
        return trim((string) get_option(self::OPT_LOGIN, ''));
    }

    public static function start_device_flow(int $user_id): array|WP_Error {
        $client_id = self::client_id();
        if ('' === $client_id) {
            return new WP_Error('wpaib_oauth_not_configured', 'GitHub OAuth Client ID is not configured.');
        }

        $response = wp_remote_post('https://github.com/login/device/code', [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WP-AI-Bridge/' . WPAIB_VERSION,
            ],
            'body' => [
                'client_id' => $client_id,
                'scope' => 'repo',
            ],
        ]);
        $data = self::decode_oauth_response($response, 'GitHub did not start the authorization flow.');
        if (is_wp_error($data)) return $data;

        $device_code = trim((string) ($data['device_code'] ?? ''));
        $user_code = trim((string) ($data['user_code'] ?? ''));
        $verification_uri = esc_url_raw((string) ($data['verification_uri'] ?? 'https://github.com/login/device'));
        $expires_in = max(60, (int) ($data['expires_in'] ?? 900));
        $interval = max(5, (int) ($data['interval'] ?? 5));
        if ('' === $device_code || '' === $user_code) {
            return new WP_Error('wpaib_oauth_device_response', 'GitHub returned an invalid device authorization response.');
        }

        set_transient(self::DEVICE_PREFIX . $user_id, [
            'device_code' => $device_code,
            'expires_at' => time() + $expires_in,
            'interval' => $interval,
        ], $expires_in);

        return [
            'user_code' => $user_code,
            'verification_uri' => $verification_uri,
            'expires_in' => $expires_in,
            'interval' => $interval,
        ];
    }

    public static function poll_device_flow(int $user_id): array|WP_Error {
        $state = get_transient(self::DEVICE_PREFIX . $user_id);
        if (!is_array($state) || empty($state['device_code'])) {
            return new WP_Error('wpaib_oauth_device_expired', 'GitHub connection session expired. Start again.');
        }
        if ((int) ($state['expires_at'] ?? 0) <= time()) {
            delete_transient(self::DEVICE_PREFIX . $user_id);
            return new WP_Error('wpaib_oauth_device_expired', 'GitHub connection session expired. Start again.');
        }

        $response = wp_remote_post('https://github.com/login/oauth/access_token', [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WP-AI-Bridge/' . WPAIB_VERSION,
            ],
            'body' => [
                'client_id' => self::client_id(),
                'device_code' => (string) $state['device_code'],
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ],
        ]);
        $data = self::decode_oauth_response($response, 'GitHub authorization failed.');
        if (is_wp_error($data)) return $data;

        $error = (string) ($data['error'] ?? '');
        if ('authorization_pending' === $error) {
            return ['pending' => true, 'interval' => (int) ($state['interval'] ?? 5)];
        }
        if ('slow_down' === $error) {
            $interval = max((int) ($state['interval'] ?? 5) + 5, (int) ($data['interval'] ?? 0));
            $state['interval'] = $interval;
            set_transient(self::DEVICE_PREFIX . $user_id, $state, max(60, (int) $state['expires_at'] - time()));
            return ['pending' => true, 'interval' => $interval];
        }
        if ('' !== $error) {
            delete_transient(self::DEVICE_PREFIX . $user_id);
            return new WP_Error('wpaib_oauth_' . sanitize_key($error), (string) ($data['error_description'] ?? $error));
        }

        $token = trim((string) ($data['access_token'] ?? ''));
        if ('' === $token) return new WP_Error('wpaib_oauth_token_missing', 'GitHub did not return an access token.');

        $encrypted = WPAIB_Crypto::encrypt($token);
        if (is_wp_error($encrypted)) return $encrypted;
        update_option(self::OPT_ACCESS_TOKEN, $encrypted, false);

        $user = self::api('GET', '/user');
        if (is_wp_error($user)) {
            delete_option(self::OPT_ACCESS_TOKEN);
            return $user;
        }
        $login = trim((string) ($user['login'] ?? ''));
        if ('' === $login) {
            delete_option(self::OPT_ACCESS_TOKEN);
            return new WP_Error('wpaib_oauth_user', 'Unable to identify the connected GitHub account.');
        }

        update_option(self::OPT_LOGIN, $login, false);
        delete_transient(self::DEVICE_PREFIX . $user_id);
        WPAIB_Audit::record('github_oauth_connected', ['login' => $login]);
        return ['pending' => false, 'connected' => true, 'login' => $login];
    }

    public static function sync_token(): string {
        return self::access_token();
    }

    public static function disconnect(): void {
        delete_option(self::OPT_ACCESS_TOKEN);
        delete_option(self::OPT_LOGIN);
        WPAIB_Audit::record('github_oauth_disconnected');
    }

    public static function repositories(): array|WP_Error {
        $data = self::api('GET', '/user/repos?per_page=100&sort=updated&direction=desc&affiliation=owner%2Ccollaborator%2Corganization_member');
        if (is_wp_error($data)) return $data;

        $repos = [];
        foreach ($data as $repo) {
            if (!is_array($repo)) continue;
            $full_name = trim((string) ($repo['full_name'] ?? ''));
            if ('' === $full_name) continue;
            $repos[] = [
                'full_name' => $full_name,
                'private' => !empty($repo['private']),
                'default_branch' => trim((string) ($repo['default_branch'] ?? 'main')) ?: 'main',
            ];
        }
        return $repos;
    }

    public static function ensure_repository(string $value): array|WP_Error {
        if (!self::connected()) return new WP_Error('wpaib_oauth_not_connected', 'Connect GitHub first.');

        $value = trim($value);
        if ('' === $value) return new WP_Error('wpaib_repo_missing', 'Choose or enter a repository.');
        if (!str_contains($value, '/')) $value = self::login() . '/' . $value;
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value)) {
            return new WP_Error('wpaib_repo_invalid', 'Repository must use owner/repository format.');
        }

        [$owner, $name] = explode('/', $value, 2);
        $found = self::api('GET', '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name), null, [404]);
        if (is_wp_error($found)) return $found;
        if ((int) ($found['_http_status'] ?? 200) !== 404) return $found;

        if (0 !== strcasecmp($owner, self::login())) {
            return new WP_Error('wpaib_repo_not_found', 'Repository does not exist or the connected GitHub account cannot access it.');
        }

        $created = self::api('POST', '/user/repos', [
            'name' => $name,
            'private' => true,
            'auto_init' => true,
            'description' => 'WordPress source managed by WP AI Bridge',
        ]);
        if (is_wp_error($created)) return $created;
        return $created;
    }

    public static function api(string $method, string $endpoint, ?array $body = null, array $allow_status = []): array|WP_Error {
        $token = self::access_token();
        if ('' === $token) return new WP_Error('wpaib_oauth_not_connected', 'Connect GitHub first.');
        if (!str_starts_with($endpoint, '/')) return new WP_Error('wpaib_oauth_endpoint', 'Invalid GitHub API endpoint.');

        $args = [
            'method' => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'WP-AI-Bridge/' . WPAIB_VERSION,
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

        $message = (string) ($data['message'] ?? 'GitHub API request failed.');
        if (403 === $status && str_contains(strtolower($message), 'resource not accessible by integration')) {
            $accepted = trim((string) wp_remote_retrieve_header($response, 'x-accepted-github-permissions'));
            if (str_contains($accepted, 'administration=write')) {
                $message = 'GitHub App cannot create this repository. Set Repository permissions > Administration to Read and write, install/configure the App for your GitHub account, approve updated permissions, then Disconnect and Connect GitHub again.';
            } elseif (str_contains($accepted, 'contents=write')) {
                $message = 'GitHub App cannot write repository contents. Set Repository permissions > Contents to Read and write, install/configure the App for this repository (or All repositories), approve updated permissions, then Disconnect and Connect GitHub again.';
            } else {
                $message = 'GitHub App does not have enough permission for this action. Grant the requested repository permission, ensure the App is installed for this repository/account, approve updated permissions, then Disconnect and Connect GitHub again.';
                if ('' !== $accepted) $message .= ' GitHub requires: ' . $accepted . '.';
            }
        }

        return new WP_Error('wpaib_github_http_' . $status, $message, ['status' => $status]);
    }

    private static function access_token(): string {
        return WPAIB_Crypto::decrypt((string) get_option(self::OPT_ACCESS_TOKEN, ''));
    }

    private static function decode_oauth_response($response, string $fallback): array|WP_Error {
        if (is_wp_error($response)) return $response;
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = '' !== $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        if ($status >= 200 && $status < 300) return $data;
        return new WP_Error('wpaib_oauth_http_' . $status, (string) ($data['error_description'] ?? $data['error'] ?? $fallback), ['status' => $status]);
    }
}
