<?php

defined('ABSPATH') || exit;

final class WPAIB_Settings {
    private const TOKEN_TRANSIENT_PREFIX = 'wpaib_new_token_';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_action('admin_post_wpaib_generate_token', [self::class, 'generate_token']);
        add_action('admin_post_wpaib_revoke_token', [self::class, 'revoke_token']);
    }

    public static function menu(): void {
        add_options_page(
            'WP AI Bridge',
            'WP AI Bridge',
            'manage_options',
            'wp-ai-bridge',
            [self::class, 'render']
        );
    }

    public static function register(): void {
        register_setting('wpaib', WPAIB_OPTION_WRITE_ENABLED, [
            'type' => 'string',
            'sanitize_callback' => static fn($value): string => '1' === (string) $value ? '1' : '0',
            'default' => '0',
        ]);
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

        $write_enabled = '1' === get_option(WPAIB_OPTION_WRITE_ENABLED, '0');
        $token = get_transient(self::token_transient_key());
        if (is_string($token) && '' !== $token) {
            delete_transient(self::token_transient_key());
        } else {
            $token = '';
        }

        $connection_url = WPAIB_Auth::connection_url();
        $token_exists = WPAIB_Auth::has_token();
        $created_at = WPAIB_Auth::token_created_at();
        $notice = isset($_GET['wpaib_notice']) ? sanitize_key(wp_unslash($_GET['wpaib_notice'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP AI Bridge', 'wp-ai-bridge'); ?></h1>
            <p><?php esc_html_e('A guarded bridge for AI-assisted WordPress diagnostics. Remote API access is read-only in this milestone.', 'wp-ai-bridge'); ?></p>

            <?php if ('token-created' === $notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('A new API token was created. Copy it now; it will not be shown again.', 'wp-ai-bridge'); ?></p></div>
            <?php elseif ('token-revoked' === $notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('The API token was revoked.', 'wp-ai-bridge'); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Connection', 'wp-ai-bridge'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Connection URL', 'wp-ai-bridge'); ?></th>
                    <td>
                        <input type="text" class="regular-text code" readonly value="<?php echo esc_attr($connection_url); ?>" onclick="this.select();" />
                        <p class="description"><?php esc_html_e('Use this as the REST base URL for an MCP gateway or another authorized client.', 'wp-ai-bridge'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('API token', 'wp-ai-bridge'); ?></th>
                    <td>
                        <?php if ('' !== $token) : ?>
                            <input type="text" class="large-text code" readonly value="<?php echo esc_attr($token); ?>" onclick="this.select();" autocomplete="off" />
                            <p><strong><?php esc_html_e('Copy this token now. Only its password hash is stored after this page view.', 'wp-ai-bridge'); ?></strong></p>
                        <?php elseif ($token_exists) : ?>
                            <p><strong><?php esc_html_e('Configured', 'wp-ai-bridge'); ?></strong><?php echo $created_at ? ' — ' . esc_html($created_at) : ''; ?></p>
                            <p class="description"><?php esc_html_e('The original token cannot be recovered. Generate a new one to rotate credentials.', 'wp-ai-bridge'); ?></p>
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
                    <th scope="row"><?php esc_html_e('Transport security', 'wp-ai-bridge'); ?></th>
                    <td>
                        <?php if (is_ssl()) : ?>
                            <strong><?php esc_html_e('HTTPS detected', 'wp-ai-bridge'); ?></strong>
                        <?php else : ?>
                            <strong style="color:#b32d2e;"><?php esc_html_e('HTTPS not detected. Do not send the API token over plain HTTP.', 'wp-ai-bridge'); ?></strong>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Test request', 'wp-ai-bridge'); ?></h2>
            <p><?php esc_html_e('Send a GET request to the ping endpoint with an Authorization header:', 'wp-ai-bridge'); ?></p>
            <pre><code>Authorization: Bearer YOUR_TOKEN
GET <?php echo esc_html($connection_url . 'ping'); ?></code></pre>

            <form method="post" action="options.php">
                <?php settings_fields('wpaib'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Safe write mode', 'wp-ai-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(WPAIB_OPTION_WRITE_ENABLED); ?>" value="1" <?php checked($write_enabled); ?> />
                                <?php esc_html_e('Enable write capability flag', 'wp-ai-bridge'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('No remote write endpoints are implemented yet. This flag remains reserved for the guarded-write milestone.', 'wp-ai-bridge'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Available REST endpoints', 'wp-ai-bridge'); ?></h2>
            <code>/wp-json/wp-ai-bridge/v1/ping</code><br>
            <code>/wp-json/wp-ai-bridge/v1/site-info</code><br>
            <code>/wp-json/wp-ai-bridge/v1/plugins</code><br>
            <code>/wp-json/wp-ai-bridge/v1/file?path=plugins/example/example.php</code><br>
            <code>/wp-json/wp-ai-bridge/v1/audit</code>
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
        return add_query_arg(
            array_merge(['page' => 'wp-ai-bridge'], $args),
            admin_url('options-general.php')
        );
    }
}
