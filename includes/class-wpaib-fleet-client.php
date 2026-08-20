<?php

defined('ABSPATH') || exit;

final class WPAIB_Fleet_Client {
    private const OPT_HUB = 'wpaib_fleet_client_hub';
    private const OPT_SITE_ID = 'wpaib_fleet_client_site_id';
    private const OPT_SITE_SECRET = 'wpaib_fleet_client_site_secret';
    private const OPT_REPO = 'wpaib_fleet_client_repo';
    private const OPT_BRANCH = 'wpaib_fleet_client_branch';
    private const OPT_ACCESS_TOKEN = 'wpaib_fleet_client_access_token';
    private const OPT_ACCESS_EXPIRES = 'wpaib_fleet_client_access_expires';

    public static function connected(): bool {
        return '' !== self::hub()
            && '' !== self::site_id()
            && '' !== WPAIB_Crypto::decrypt((string) get_option(self::OPT_SITE_SECRET, ''))
            && '' !== (string) get_option(self::OPT_REPO, '');
    }

    public static function status(): array {
        return [
            'connected' => self::connected(),
            'hub' => self::hub(),
            'site_id' => self::site_id(),
            'repo' => (string) get_option(self::OPT_REPO, ''),
            'branch' => (string) get_option(self::OPT_BRANCH, 'main'),
            'token_expires' => (int) get_option(self::OPT_ACCESS_EXPIRES, 0),
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

        $secret = (string) ($data['site_secret'] ?? '');
        $repo = (string) ($data['repo'] ?? '');
        $branch = (string) ($data['branch'] ?? 'main');
        if ('' === $secret || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return new WP_Error('wpaib_fleet_enroll_response', 'Fleet Hub returned an invalid enrollment response.');
        }

        $encrypted = WPAIB_Crypto::encrypt($secret);
        if (is_wp_error($encrypted)) return $encrypted;
        update_option(self::OPT_HUB, $hub, false);
        update_option(self::OPT_SITE_ID, $site_id, false);
        update_option(self::OPT_SITE_SECRET, $encrypted, false);
        update_option(self::OPT_REPO, $repo, false);
        update_option(self::OPT_BRANCH, '' !== $branch ? $branch : 'main', false);
        self::clear_access_token();

        $configured = WPAIB_GitHub_Sync::configure_fleet_repository($repo, '' !== $branch ? $branch : 'main');
        if (is_wp_error($configured)) return $configured;

        $token = self::access_token();
        if (is_wp_error($token)) return $token;

        WPAIB_Audit::record('fleet_client_connected', ['hub' => $hub, 'repo' => $repo]);
        return ['repo' => $repo, 'branch' => '' !== $branch ? $branch : 'main'];
    }

    public static function disconnect(): void {
        foreach ([self::OPT_HUB, self::OPT_SITE_SECRET, self::OPT_REPO, self::OPT_BRANCH, self::OPT_ACCESS_TOKEN, self::OPT_ACCESS_EXPIRES] as $option) {
            delete_option($option);
        }
        WPAIB_GitHub_Sync::leave_fleet_mode();
        WPAIB_Audit::record('fleet_client_disconnected');
    }

    public static function access_token(): string|WP_Error {
        if (!self::connected()) return new WP_Error('wpaib_fleet_not_connected', 'This site is not connected to a Fleet Hub.');

        $expires = (int) get_option(self::OPT_ACCESS_EXPIRES, 0);
        $cached = WPAIB_Crypto::decrypt((string) get_option(self::OPT_ACCESS_TOKEN, ''));
        if ('' !== $cached && $expires > time() + 300) return $cached;

        $secret = WPAIB_Crypto::decrypt((string) get_option(self::OPT_SITE_SECRET, ''));
        if ('' === $secret) return new WP_Error('wpaib_fleet_secret', 'Fleet site credential is unavailable.');

        $response = wp_remote_post(self::hub() . 'token', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'WP-AI-Bridge-Fleet/' . WPAIB_VERSION,
            ],
            'body' => wp_json_encode(['site_id' => self::site_id()]),
        ]);
        $data = self::decode_response($response, 'Unable to refresh Fleet GitHub token.');
        if (is_wp_error($data)) return $data;

        $token = (string) ($data['token'] ?? '');
        $repo = (string) ($data['repo'] ?? '');
        $branch = (string) ($data['branch'] ?? 'main');
        if ('' === $token) return new WP_Error('wpaib_fleet_token_empty', 'Fleet Hub did not return a GitHub token.');
        if ($repo !== (string) get_option(self::OPT_REPO, '')) {
            return new WP_Error('wpaib_fleet_repo_changed', 'Fleet Hub repository does not match this site registration. Reconnect the site.');
        }

        $encrypted = WPAIB_Crypto::encrypt($token);
        if (is_wp_error($encrypted)) return $encrypted;
        $expires_at = strtotime((string) ($data['expires_at'] ?? ''));
        if (false === $expires_at || $expires_at <= time()) $expires_at = time() + 3300;
        update_option(self::OPT_ACCESS_TOKEN, $encrypted, false);
        update_option(self::OPT_ACCESS_EXPIRES, $expires_at, false);
        if ('' !== $branch) update_option(self::OPT_BRANCH, $branch, false);
        return $token;
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
        $message = (string) ($data['message'] ?? $data['data']['message'] ?? $fallback);
        return new WP_Error('wpaib_fleet_http_' . $status, $message, ['status' => $status]);
    }

    private static function hub(): string {
        return trailingslashit(trim((string) get_option(self::OPT_HUB, '')));
    }

    private static function site_id(): string {
        return trim((string) get_option(self::OPT_SITE_ID, ''));
    }

    private static function clear_access_token(): void {
        delete_option(self::OPT_ACCESS_TOKEN);
        delete_option(self::OPT_ACCESS_EXPIRES);
    }

    private static function base64url_decode(string $value): string|false {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
