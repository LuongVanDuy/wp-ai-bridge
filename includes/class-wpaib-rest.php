<?php

defined('ABSPATH') || exit;

final class WPAIB_REST {
    private const NS = 'wp-ai-bridge/v1';

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(self::NS, '/site-info', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'site_info'],
            'permission_callback' => [self::class, 'can_read'],
        ]);

        register_rest_route(self::NS, '/plugins', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'plugins'],
            'permission_callback' => [self::class, 'can_read'],
        ]);

        register_rest_route(self::NS, '/file', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'read_file'],
            'permission_callback' => [self::class, 'can_read'],
            'args' => [
                'path' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NS, '/audit', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'audit'],
            'permission_callback' => [self::class, 'can_read'],
        ]);
    }

    public static function can_read(): bool {
        return current_user_can('manage_options');
    }

    public static function site_info(): WP_REST_Response {
        global $wp_version;

        WPAIB_Audit::record('site_info');

        return new WP_REST_Response([
            'wordpress' => $wp_version,
            'php' => PHP_VERSION,
            'multisite' => is_multisite(),
            'theme' => [
                'stylesheet' => get_stylesheet(),
                'template' => get_template(),
                'version' => wp_get_theme()->get('Version'),
            ],
            'write_enabled' => '1' === get_option(WPAIB_OPTION_WRITE_ENABLED, '0'),
            'allowed_roots' => array_keys(WPAIB_Files::allowed_roots()),
        ]);
    }

    public static function plugins(): WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        $active = get_option('active_plugins', []);
        $rows = [];

        foreach ($plugins as $file => $data) {
            $rows[] = [
                'file' => $file,
                'name' => $data['Name'] ?? $file,
                'version' => $data['Version'] ?? '',
                'active' => in_array($file, $active, true),
            ];
        }

        WPAIB_Audit::record('list_plugins', ['count' => count($rows)]);
        return new WP_REST_Response($rows);
    }

    public static function read_file(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = WPAIB_Files::read((string) $request->get_param('path'));
        return is_wp_error($result) ? $result : new WP_REST_Response($result);
    }

    public static function audit(): WP_REST_Response {
        return new WP_REST_Response(WPAIB_Audit::recent(50));
    }
}
