<?php

defined('ABSPATH') || exit;

final class WPAIB_Auth {
    private static string $method = 'none';

    public static function connection_url(): string {
        return trailingslashit(rest_url('wp-ai-bridge/v1'));
    }

    public static function has_token(): bool {
        $hash = get_option(WPAIB_OPTION_TOKEN_HASH, '');
        return is_string($hash) && '' !== $hash;
    }

    public static function token_created_at(): string {
        $value = get_option(WPAIB_OPTION_TOKEN_CREATED_AT, '');
        return is_string($value) ? $value : '';
    }

    public static function generate_token(): string {
        $token = 'wpaib_' . bin2hex(random_bytes(32));
        update_option(WPAIB_OPTION_TOKEN_HASH, wp_hash_password($token), false);
        update_option(WPAIB_OPTION_TOKEN_CREATED_AT, gmdate('c'), false);

        self::$method = 'wordpress_admin';
        if (class_exists('WPAIB_Audit')) WPAIB_Audit::record('api_token_generated');
        return $token;
    }

    public static function revoke_token(): void {
        delete_option(WPAIB_OPTION_TOKEN_HASH);
        delete_option(WPAIB_OPTION_TOKEN_CREATED_AT);
        self::$method = 'wordpress_admin';
        if (class_exists('WPAIB_Audit')) WPAIB_Audit::record('api_token_revoked');
    }

    public static function authorize_read(WP_REST_Request $request): bool|WP_Error {
        if (current_user_can('manage_options')) {
            self::$method = 'wordpress_admin';
            return true;
        }

        $token = self::extract_bearer_token($request);
        if ('' !== $token && class_exists('WPAIB_OAuth')) {
            $oauth = WPAIB_OAuth::validate_access_token($token);
            if (is_array($oauth)) {
                self::$method = 'oauth_bearer';
                return true;
            }
        }

        $hash = get_option(WPAIB_OPTION_TOKEN_HASH, '');
        if ('' !== $token && is_string($hash) && '' !== $hash && wp_check_password($token, $hash)) {
            self::$method = 'api_bearer';
            return true;
        }

        self::$method = 'none';
        return new WP_Error(
            'wpaib_unauthorized',
            __('OAuth authorization, a valid WP AI Bridge API token, or WordPress administrator authentication is required.', 'wp-ai-bridge'),
            ['status' => 401]
        );
    }

    public static function authorize_write(WP_REST_Request $request): bool|WP_Error {
        return self::authorize_read($request);
    }

    public static function method(): string {
        return self::$method;
    }

    private static function extract_bearer_token(WP_REST_Request $request): string {
        $header = trim((string) $request->get_header('authorization'));
        if ('' === $header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = trim((string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        if ('' === $header && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = trim((string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) return '';
        $token = trim($matches[1]);
        return strlen($token) <= 256 ? $token : '';
    }
}
