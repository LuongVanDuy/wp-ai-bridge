<?php
/**
 * Plugin Name: WP AI Bridge
 * Plugin URI: https://github.com/LuongVanDuy/wp-ai-bridge
 * Description: Sync active WordPress theme/plugin source with GitHub so ChatGPT can read and edit the live project through the GitHub connector.
 * Version: 0.7.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: LuongVanDuy
 * License: GPL-2.0-or-later
 * Text Domain: wp-ai-bridge
 */

defined('ABSPATH') || exit;

define('WPAIB_VERSION', '0.7.0');
define('WPAIB_FILE', __FILE__);
define('WPAIB_DIR', plugin_dir_path(__FILE__));

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('wpaib_github_poll');
});

add_action('plugins_loaded', static function (): void {
    if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = (string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    $required = [
        'includes/class-wpaib-audit.php',
        'includes/class-wpaib-crypto.php',
        'includes/class-wpaib-maintenance.php',
        'includes/class-wpaib-fleet-hub.php',
        'includes/class-wpaib-fleet-client.php',
        'includes/class-wpaib-github-sync.php',
        'includes/class-wpaib-updater.php',
        'includes/class-wpaib-settings.php',
    ];

    foreach ($required as $file) {
        $path = WPAIB_DIR . $file;
        if (is_readable($path)) require_once $path;
    }

    if (class_exists('WPAIB_Audit')) WPAIB_Audit::boot();
    if (class_exists('WPAIB_Fleet_Hub')) WPAIB_Fleet_Hub::boot();
    if (class_exists('WPAIB_Fleet_Client')) WPAIB_Fleet_Client::boot();
    if (class_exists('WPAIB_GitHub_Sync')) WPAIB_GitHub_Sync::boot();
    if (class_exists('WPAIB_Updater')) WPAIB_Updater::boot();
    if (class_exists('WPAIB_Settings')) WPAIB_Settings::boot();
});
