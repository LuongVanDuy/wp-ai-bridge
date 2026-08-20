<?php
/**
 * Plugin Name: WP AI Bridge
 * Plugin URI: https://github.com/LuongVanDuy/wp-ai-bridge
 * Description: Guarded REST and MCP bridge for authorized AI-assisted WordPress diagnostics and maintenance.
 * Version: 0.3.0-dev
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: LuongVanDuy
 * License: GPL-2.0-or-later
 * Text Domain: wp-ai-bridge
 */

defined('ABSPATH') || exit;

define('WPAIB_VERSION', '0.3.0-dev');
define('WPAIB_FILE', __FILE__);
define('WPAIB_DIR', plugin_dir_path(__FILE__));

define('WPAIB_OPTION_WRITE_ENABLED', 'wpaib_write_enabled');
define('WPAIB_OPTION_TOKEN_HASH', 'wpaib_api_token_hash');
define('WPAIB_OPTION_TOKEN_CREATED_AT', 'wpaib_api_token_created_at');

register_activation_hook(__FILE__, static function (): void {
    if (false === get_option(WPAIB_OPTION_WRITE_ENABLED, false)) {
        add_option(WPAIB_OPTION_WRITE_ENABLED, '0', '', false);
    }
});

add_action('plugins_loaded', static function (): void {
    $required = [
        'includes/class-wpaib-audit.php',
        'includes/class-wpaib-auth.php',
        'includes/class-wpaib-files.php',
        'includes/class-wpaib-rest.php',
        'includes/class-wpaib-mcp.php',
        'includes/class-wpaib-settings.php',
    ];

    foreach ($required as $file) {
        $path = WPAIB_DIR . $file;
        if (is_readable($path)) {
            require_once $path;
        }
    }

    if (class_exists('WPAIB_Audit')) {
        WPAIB_Audit::boot();
    }
    if (class_exists('WPAIB_Settings')) {
        WPAIB_Settings::boot();
    }
    if (class_exists('WPAIB_REST')) {
        WPAIB_REST::boot();
    }
    if (class_exists('WPAIB_MCP')) {
        WPAIB_MCP::boot();
    }
});
