<?php

defined('ABSPATH') || exit;

final class WPAIB_Settings {
    private const TOKEN_TRANSIENT_PREFIX = 'wpaib_new_token_';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wpaib_generate_token', [self::class, 'generate_token']);
        add_action('admin_post_wpaib_revoke_token', [self::class, 'revoke_token']);
    }

    public static function menu(): void {
        add_options_page('WP AI Bridge', 'WP AI Bridge', 'manage_options', 'wp-ai-bridge', [self::class, 'render']);
    }

    public static function generate_token(): void {
        self::require_admin_action('wpaib_generate_token');
        $token = WPAIB_Auth::generate_token();
        set_transient(self::token_transient_key(), $token, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'token-created']));
        exit;
    }

    public static function revoke_token(): void {
        self::require_admin_action('wpaib_revoke_token');
        WPAIB_Auth::revoke_token();
        delete_transient(self::token_transient_key());
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'token-revoked']));
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $token = get_transient(self::token_transient_key());
        if (is_string($token) && '' !== $token) {
            delete_transient(self::token_transient_key());
        } else {
            $token = '';
        }

        $connection_url = WPAIB_Auth::connection_url();
        $mcp_url = class_exists('WPAIB_MCP') ? WPAIB_MCP::endpoint_url() : $connection_url . 'mcp';
        $token_exists = WPAIB_Auth::has_token();
        $created_at = WPAIB_Auth::token_created_at();
        $notice = isset($_GET['wpaib_notice']) ? sanitize_key(wp_unslash($_GET['wpaib_notice'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP AI Bridge', 'wp-ai-bridge'); ?></h1>
            <p><?php esc_html_e('Use ChatGPT as the working interface. WordPress only supplies the authenticated tools.', 'wp-ai-bridge'); ?></p>

            <?php if ('token-created' === $notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('A new API token was created. Copy it now; it will not be shown again.', 'wp-ai-bridge'); ?></p></div>
            <?php elseif ('token-revoked' === $notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('The API token was revoked.', 'wp-ai-bridge'); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('ChatGPT connection', 'wp-ai-bridge'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('MCP URL', 'wp-ai-bridge'); ?></th>
                    <td><input type="text" class="large-text code" readonly value="<?php echo esc_attr($mcp_url); ?>" onclick="this.select();" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('REST base URL', 'wp-ai-bridge'); ?></th>
                    <td><input type="text" class="large-text code" readonly value="<?php echo esc_attr($connection_url); ?>" onclick="this.select();" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('API token', 'wp-ai-bridge'); ?></th>
                    <td>
                        <?php if ('' !== $token) : ?>
                            <input type="text" class="large-text code" readonly value="<?php echo esc_attr($token); ?>" onclick="this.select();" autocomplete="off" />
                            <p><strong><?php esc_html_e('Copy this token now. Only its password hash is stored after this page view.', 'wp-ai-bridge'); ?></strong></p>
                        <?php elseif ($token_exists) : ?>
                            <p><strong><?php esc_html_e('Configured', 'wp-ai-bridge'); ?></strong><?php echo $created_at ? ' — ' . esc_html($created_at) : ''; ?></p>
                        <?php else : ?>
                            <p><?php esc_html_e('No API token has been created yet.', 'wp-ai-bridge'); ?></p>
                        <?php endif; ?>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="wpaib_generate_token" />
                                <?php wp_nonce_field('wpaib_generate_token'); ?>
                                <?php submit_button($token_exists ? __('Rotate API token', 'wp-ai-bridge') : __('Generate API token', 'wp-ai-bridge'), 'secondary', 'submit', false); ?>
                            </form>
                            <?php if ($token_exists) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Revoke the current API token?', 'wp-ai-bridge')); ?>');">
                                    <input type="hidden" name="action" value="wpaib_revoke_token" />
                                    <?php wp_nonce_field('wpaib_revoke_token'); ?>
                                    <?php submit_button(__('Revoke token', 'wp-ai-bridge'), 'delete', 'submit', false); ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Write scope', 'wp-ai-bridge'); ?></th>
                    <td>
                        <strong><?php esc_html_e('Always enabled for authenticated tokens', 'wp-ai-bridge'); ?></strong>
                        <p class="description"><code>wp-content/plugins/**</code><br><code>wp-content/themes/**</code></p>
                        <p class="description"><?php esc_html_e('No separate Maintenance Mode is required. Existing files are backed up automatically and PHP writes are syntax-checked.', 'wp-ai-bridge'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Hard limits', 'wp-ai-bridge'); ?></th>
                    <td><?php esc_html_e('No WordPress core writes, no wp-config.php, no arbitrary database access, and no shell commands.', 'wp-ai-bridge'); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Transport security', 'wp-ai-bridge'); ?></th>
                    <td><?php if (is_ssl()) : ?><strong><?php esc_html_e('HTTPS detected', 'wp-ai-bridge'); ?></strong><?php else : ?><strong style="color:#b32d2e;"><?php esc_html_e('HTTPS not detected. Do not send the API token over plain HTTP.', 'wp-ai-bridge'); ?></strong><?php endif; ?></td>
                </tr>
            </table>

            <h2><?php esc_html_e('How to work', 'wp-ai-bridge'); ?></h2>
            <p><?php esc_html_e('After the MCP app is connected to ChatGPT, describe the changes in your normal ChatGPT conversation. There is no separate WordPress task-entry screen.', 'wp-ai-bridge'); ?></p>

            <h2><?php esc_html_e('REST test', 'wp-ai-bridge'); ?></h2>
            <pre><code>Authorization: Bearer YOUR_TOKEN
GET <?php echo esc_html($connection_url . 'ping'); ?></code></pre>
        </div>
        <?php
    }

    private static function require_admin_action(string $nonce_action): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage WP AI Bridge.', 'wp-ai-bridge'));
        }
        check_admin_referer($nonce_action);
    }

    private static function token_transient_key(): string {
        return self::TOKEN_TRANSIENT_PREFIX . get_current_user_id();
    }

    private static function settings_url(array $args = []): string {
        return add_query_arg(array_merge(['page' => 'wp-ai-bridge'], $args), admin_url('options-general.php'));
    }
}
