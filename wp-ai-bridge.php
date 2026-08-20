<?php
/**
 * Plugin Name: WP AI Bridge
 * Plugin URI: https://github.com/LuongVanDuy/wp-ai-bridge
 * Description: Connect WordPress directly to GitHub so ChatGPT can read and edit the active theme/plugin source through a project repository.
 * Version: 0.9.3
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: LuongVanDuy
 * License: GPL-2.0-or-later
 * Text Domain: wp-ai-bridge
 */

defined('ABSPATH') || exit;

define('WPAIB_VERSION', '0.9.3');
define('WPAIB_FILE', __FILE__);
define('WPAIB_DIR', plugin_dir_path(__FILE__));
define('WPAIB_GITHUB_OAUTH_CLIENT_ID', 'Iv23linx1uF99L7pkGX9');

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
        'includes/class-wpaib-github-oauth.php',
        'includes/class-wpaib-empty-repo-bootstrap.php',
        'includes/class-wpaib-github-sync.php',
        'includes/class-wpaib-updater.php',
        'includes/class-wpaib-settings.php',
    ];

    foreach ($required as $file) {
        $path = WPAIB_DIR . $file;
        if (is_readable($path)) require_once $path;
    }

    if (class_exists('WPAIB_Audit')) WPAIB_Audit::boot();
    if (class_exists('WPAIB_Empty_Repo_Bootstrap')) WPAIB_Empty_Repo_Bootstrap::boot();
    if (class_exists('WPAIB_GitHub_Sync')) WPAIB_GitHub_Sync::boot();
    if (class_exists('WPAIB_Updater')) WPAIB_Updater::boot();
    if (class_exists('WPAIB_Settings')) WPAIB_Settings::boot();
});
