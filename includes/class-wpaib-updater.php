<?php

defined('ABSPATH') || exit;

final class WPAIB_Updater {
    private const RAW_PLUGIN_URL = 'https://raw.githubusercontent.com/LuongVanDuy/wp-ai-bridge/main/wp-ai-bridge.php';
    private const PACKAGE_URL = 'https://github.com/LuongVanDuy/wp-ai-bridge/archive/refs/heads/main.zip';
    private const REPO_URL = 'https://github.com/LuongVanDuy/wp-ai-bridge';
    private const CACHE_KEY = 'wpaib_github_update_status';

    public static function boot(): void {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('upgrader_source_selection', [self::class, 'normalize_source'], 10, 4);
    }

    public static function status(bool $force = false): array {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['current_version'])) {
                return $cached;
            }
        }

        $status = [
            'current_version' => WPAIB_VERSION,
            'remote_version' => null,
            'update_available' => false,
            'checked_at' => gmdate('c'),
            'error' => null,
            'repo_url' => self::REPO_URL,
        ];

        $response = wp_remote_get(self::RAW_PLUGIN_URL, [
            'timeout' => 5,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'text/plain',
                'User-Agent' => 'WP-AI-Bridge/' . WPAIB_VERSION . '; ' . home_url('/'),
            ],
        ]);

        if (is_wp_error($response)) {
            $status['error'] = $response->get_error_message();
            set_site_transient(self::CACHE_KEY, $status, MINUTE_IN_SECONDS);
            return $status;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if (200 !== $code || '' === $body) {
            $status['error'] = 'GitHub returned HTTP ' . $code . '.';
            set_site_transient(self::CACHE_KEY, $status, MINUTE_IN_SECONDS);
            return $status;
        }

        if (!preg_match('/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $body, $matches)) {
            $status['error'] = 'Could not read the remote plugin version.';
            set_site_transient(self::CACHE_KEY, $status, MINUTE_IN_SECONDS);
            return $status;
        }

        $remote_version = trim($matches[1]);
        $status['remote_version'] = $remote_version;
        $status['update_available'] = version_compare($remote_version, WPAIB_VERSION, '>');
        set_site_transient(self::CACHE_KEY, $status, 5 * MINUTE_IN_SECONDS);

        return $status;
    }

    public static function update_url(): string {
        $plugin = plugin_basename(WPAIB_FILE);
        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($plugin)),
            'upgrade-plugin_' . $plugin
        );
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $status = self::status(false);
        if (empty($status['update_available']) || empty($status['remote_version'])) {
            return $transient;
        }

        $plugin = plugin_basename(WPAIB_FILE);
        $transient->response[$plugin] = (object) [
            'id' => self::REPO_URL,
            'slug' => 'wp-ai-bridge',
            'plugin' => $plugin,
            'new_version' => $status['remote_version'],
            'url' => self::REPO_URL,
            'package' => self::PACKAGE_URL,
            'tested' => '',
            'requires_php' => '8.0',
            'icons' => [],
            'banners' => [],
        ];

        return $transient;
    }

    public static function normalize_source($source, $remote_source, $upgrader, $hook_extra) {
        $plugin = plugin_basename(WPAIB_FILE);
        if (!is_array($hook_extra) || ($hook_extra['plugin'] ?? '') !== $plugin) {
            return $source;
        }

        global $wp_filesystem;
        if (!is_object($wp_filesystem)) {
            return $source;
        }

        $source = trailingslashit($source);
        $remote_source = trailingslashit($remote_source);
        $expected = $remote_source . 'wp-ai-bridge/';

        if ($source === $expected) {
            return $source;
        }

        if ($wp_filesystem->exists($expected)) {
            $wp_filesystem->delete($expected, true);
        }

        if (!$wp_filesystem->move($source, $expected, true)) {
            return new WP_Error(
                'wpaib_update_source',
                __('WP AI Bridge could not normalize the GitHub update package.', 'wp-ai-bridge')
            );
        }

        return $expected;
    }
}
