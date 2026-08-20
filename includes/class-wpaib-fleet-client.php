<?php

defined('ABSPATH') || exit;

final class WPAIB_Fleet_Client {
    private const OPT_HUB = 'wpaib_fleet_client_hub';
    private const OPT_SITE_ID = 'wpaib_fleet_client_site_id';
    private const OPT_SITE_SECRET = 'wpaib_fleet_client_site_secret';
    private const OPT_REPO = 'wpaib_fleet_client_repo';
    private const OPT_BRANCH = 'wpaib_fleet_client_branch';
    private const PROXY_TOKEN = 'wpaib_fleet_proxy';

    public static function boot(): void {
        add_filter('pre_http_request', [self::class, 'proxy_github_request'], 10, 3);
    }

    public static function connected(): bool {
        return '' !== self::hub()
            && '' !== self::site_id()
            && '' !== self::site_secret()
            && '' !== (string) get_option(self::OPT_REPO, '');
    }

    public static function status(): array {
        return [
            'connected' => self::connected(),
            'hub' => self::hub(),
            'site_id' => self::site_id(),
            'repo' => (string) get_option(self::OPT_REPO, ''),
            'branch' => (string) get_option(self::OPT_BRANCH, 'main'),
        ];
    }

    public static function connect_with_key(string $fleet_key): array|WP_Error {
        $payload = self::parse_fleet_key($fleet_key);
        if (is_wp_error($payload)) return $payload;

        $hub = trailingslashit((string) $payload['hub']);
        $site_id = self::site_id();
        if ('' === $site_id) {
            try {
                $site_id = 'wpaib_site_' . bin2hex(random_bytes(16));
            } catch (Throwable $error) {
                return new WP_Error('wpaib_fleet_random', 'Unable to generate site id.');
            }
        }

        $response = wp_remote_post($hub . 'enroll', [
            'timeout' => 45,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'WP-AI-Bridge-Fleet/' . WPAIB_VERSION,
            ],
            'body' => wp_json_encode([
                'code' => (string) $payload['code'],
                'site_id' => $site_id,
                'site_url' => home_url('/'),
                'site_name' => get_bloginfo('name'),
                'wp_version' => get_bloginfo('version'),
                'bridge_version' => WPAIB_VERSION,
            ]),
        ]);
        $data = self::decode_response($response, 'Fleet enrollment failed.');
        if (is_wp_error($data)) return $data;

        $secret = trim((string) ($data['site_secret'] ?? ''));
        $repo = trim((string) ($data['repo'] ?? ''));
        $branch = trim((string) ($data['branch'] ?? 'main'));
        if ('' === $secret || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return new WP_Error('wpaib_fleet_enroll_response', 'Fleet Hub returned an invalid enrollment response.');
        }

        $encrypted = WPAIB_Crypto::encrypt($secret);
        if (is_wp_error($encrypted)) return $encrypted;
        $sync_token = self::encrypt_sync_token(self::PROXY_TOKEN);
        if (is_wp_error($sync_token)) return $sync_token;

        update_option(self::OPT_HUB, $hub, false);
        update_option(self::OPT_SITE_ID, $site_id, false);
        update_option(self::OPT_SITE_SECRET, $encrypted, false);
        update_option(self::OPT_REPO, $repo, false);
        update_option(self::OPT_BRANCH, '' !== $branch ? $branch : 'main', false);

        update_option('wpaib_github_repo', $repo, false);
        update_option('wpaib_github_branch', '' !== $branch ? $branch : 'main', false);
        update_option('wpaib_github_token', $sync_token, false);
        delete_option('wpaib_github_last_remote_sha');
        delete_option('wpaib_github_last_sync_at');
        delete_option('wpaib_github_last_error');

        WPAIB_Audit::record('fleet_client_connected', ['hub' => $hub, 'repo' => $repo]);
        return ['repo' => $repo, 'branch' => '' !== $branch ? $branch : 'main'];
    }

    public static function disconnect(): void {
        foreach ([self::OPT_HUB, self::OPT_SITE_SECRET, self::OPT_REPO, self::OPT_BRANCH] as $option) delete_option($option);
        foreach (['wpaib_github_repo', 'wpaib_github_branch', 'wpaib_github_token', 'wpaib_github_last_remote_sha', 'wpaib_github_last_sync_at', 'wpaib_github_last_error'] as $option) delete_option($option);
        WPAIB_Audit::record('fleet_client_disconnected');
    }

    public static function proxy_github_request($preempt, array $args, string $url) {
        if (!self::connected() || !str_starts_with($url, 'https://api.github.com/')) return $preempt;

        $authorization = '';
        if (isset($args['headers']) && is_array($args['headers'])) {
            foreach ($args['headers'] as $name => $value) {
                if ('authorization' === strtolower((string) $name)) {
                    $authorization = trim((string) $value);
                    break;
                }
            }
        }
        if ('Bearer ' . self::PROXY_TOKEN !== $authorization) return $preempt;

        $secret = self::site_secret();
        if ('' === $secret) return new WP_Error('wpaib_fleet_secret', 'Fleet site credential is unavailable.');

        $endpoint = substr($url, strlen('https://api.github.com'));
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $body = isset($args['body']) && is_string($args['body']) ? $args['body'] : null;

        $response = wp_remote_post(self::hub() . 'github', [
            'timeout' => max(30, (int) ($args['timeout'] ?? 30)),
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'WP-AI-Bridge-Fleet/' . WPAIB_VERSION,
            ],
            'body' => wp_json_encode([
                'site_id' => self::site_id(),
                'method' => $method,
                'endpoint' => $endpoint,
                'body' => $body,
            ]),
        ]);
        if (is_wp_error($response)) return $response;

        $hub_status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = '' !== $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        if ($hub_status < 200 || $hub_status >= 300) {
            return new WP_Error('wpaib_fleet_proxy_http_' . $hub_status, (string) ($data['message'] ?? $data['data']['message'] ?? 'Fleet Hub GitHub proxy failed.'));
        }

        $status = (int) ($data['status'] ?? 500);
        $body_out = (string) ($data['body'] ?? '');
        return [
            'headers' => [],
            'body' => $body_out,
            'response' => ['code' => $status, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    }

    private static function parse_fleet_key(string $fleet_key): array|WP_Error {
        $fleet_key = trim($fleet_key);
        if (!str_starts_with($fleet_key, 'wpaib_fleet_')) return new WP_Error('wpaib_fleet_key_format', 'Invalid Fleet Key.');
        $encoded = substr($fleet_key, strlen('wpaib_fleet_'));
        $decoded = self::base64url_decode($encoded);
        if (false === $decoded) return new WP_Error('wpaib_fleet_key_decode', 'Invalid Fleet Key encoding.');
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) return new WP_Error('wpaib_fleet_key_payload', 'Invalid Fleet Key payload.');

        $hub = trailingslashit(esc_url_raw((string) ($payload['hub'] ?? '')));
        $code = (string) ($payload['code'] ?? '');
        $expires = (int) ($payload['exp'] ?? 0);
        if ($expires < time()) return new WP_Error('wpaib_fleet_key_expired', 'Fleet Key has expired. Generate a new key on the Hub.');
        if ('' === $code || false === wp_http_validate_url($hub)) return new WP_Error('wpaib_fleet_key_invalid', 'Fleet Key contains an invalid Hub URL.');
        $parts = wp_parse_url($hub);
        if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))) {
            return new WP_Error('wpaib_fleet_https', 'Fleet Hub must use HTTPS.');
        }
        return ['hub' => $hub, 'code' => $code, 'exp' => $expires];
    }

    private static function decode_response($response, string $fallback): array|WP_Error {
        if (is_wp_error($response)) return $response;
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = '' !== $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        if ($status >= 200 && $status < 300) return $data;
        return new WP_Error('wpaib_fleet_http_' . $status, (string) ($data['message'] ?? $data['data']['message'] ?? $fallback), ['status' => $status]);
    }

    private static function hub(): string {
        return trailingslashit(trim((string) get_option(self::OPT_HUB, '')));
    }

    private static function site_id(): string {
        return trim((string) get_option(self::OPT_SITE_ID, ''));
    }

    private static function site_secret(): string {
        return WPAIB_Crypto::decrypt((string) get_option(self::OPT_SITE_SECRET, ''));
    }

    private static function encrypt_sync_token(string $token): string|WP_Error {
        $key = hash('sha256', wp_salt('auth'), true);
        if (function_exists('sodium_crypto_secretbox')) {
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                return 'sodium:' . base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, $key));
            } catch (Throwable $error) {
                return new WP_Error('wpaib_fleet_encrypt', 'Unable to prepare Fleet proxy token.');
            }
        }
        if (function_exists('openssl_encrypt')) {
            try {
                $iv = random_bytes(12);
            } catch (Throwable $error) {
                return new WP_Error('wpaib_fleet_encrypt', 'Unable to prepare Fleet proxy token.');
            }
            $tag = '';
            $cipher = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (false === $cipher) return new WP_Error('wpaib_fleet_encrypt', 'Unable to prepare Fleet proxy token.');
            return 'openssl:' . base64_encode($iv . $tag . $cipher);
        }
        return new WP_Error('wpaib_fleet_encrypt_unavailable', 'Server requires Sodium or OpenSSL.');
    }

    private static function base64url_decode(string $value): string|false {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
