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
        if (!current_user_can('manage_options')) return;

        $token = get_transient(self::token_transient_key());
        if (is_string($token) && '' !== $token) delete_transient(self::token_transient_key()); else $token = '';

        $mcp_url = class_exists('WPAIB_MCP') ? WPAIB_MCP::endpoint_url() : rest_url('wp-ai-bridge/v1/mcp');
        $token_exists = WPAIB_Auth::has_token();
        $created_at = WPAIB_Auth::token_created_at();
        $notice = isset($_GET['wpaib_notice']) ? sanitize_key(wp_unslash($_GET['wpaib_notice'])) : '';
        $update = class_exists('WPAIB_Updater') ? WPAIB_Updater::status(true) : null;
        ?>
        <div class="wrap wpaib-wrap">
            <style>
                .wpaib-wrap{max-width:880px}.wpaib-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:18px 0}.wpaib-head h1{margin:0}.wpaib-version,.wpaib-muted{color:#646970}.wpaib-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:14px 0}.wpaib-card h2{margin:0 0 14px}.wpaib-row{display:grid;grid-template-columns:155px 1fr;gap:16px;padding:10px 0;border-top:1px solid #f0f0f1}.wpaib-row:first-of-type{border-top:0}.wpaib-label{font-weight:600}.wpaib-code{width:100%;font-family:monospace}.wpaib-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wpaib-ok{color:#008a20;font-weight:600}.wpaib-warn{color:#b32d2e;font-weight:600}.wpaib-wrap details{margin-top:14px}.wpaib-wrap summary{cursor:pointer;font-weight:600}@media(max-width:782px){.wpaib-row{grid-template-columns:1fr;gap:6px}}
            </style>

            <div class="wpaib-head"><h1>WP AI Bridge</h1><span class="wpaib-version">v<?php echo esc_html(WPAIB_VERSION); ?></span></div>

            <?php if ('token-created' === $notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Manual API token created. Copy it now; it will not be shown again.', 'wp-ai-bridge'); ?></p></div>
            <?php elseif ('token-revoked' === $notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Manual API token revoked.', 'wp-ai-bridge'); ?></p></div>
            <?php endif; ?>

            <div class="wpaib-card">
                <h2><?php esc_html_e('ChatGPT connection', 'wp-ai-bridge'); ?></h2>
                <div class="wpaib-row"><div class="wpaib-label">MCP URL</div><div><input class="wpaib-code" type="text" readonly value="<?php echo esc_attr($mcp_url); ?>" onclick="this.select();"></div></div>
                <div class="wpaib-row"><div class="wpaib-label">OAuth 2.1</div><div><span class="wpaib-ok"><?php esc_html_e('Ready', 'wp-ai-bridge'); ?></span><br><span class="wpaib-muted"><?php esc_html_e('ChatGPT will open WordPress authorization once when you connect the app.', 'wp-ai-bridge'); ?></span></div></div>
                <div class="wpaib-row"><div class="wpaib-label">HTTPS</div><div><?php if (is_ssl()) : ?><span class="wpaib-ok"><?php esc_html_e('Secure', 'wp-ai-bridge'); ?></span><?php else : ?><span class="wpaib-warn"><?php esc_html_e('HTTPS not detected — OAuth should not be used until HTTPS is fixed.', 'wp-ai-bridge'); ?></span><?php endif; ?></div></div>
            </div>

            <div class="wpaib-card">
                <h2><?php esc_html_e('Access', 'wp-ai-bridge'); ?></h2>
                <div class="wpaib-row"><div class="wpaib-label"><?php esc_html_e('Writable', 'wp-ai-bridge'); ?></div><div><code>wp-content/plugins/**</code><br><code>wp-content/themes/**</code></div></div>
                <div class="wpaib-row"><div class="wpaib-label"><?php esc_html_e('Protected', 'wp-ai-bridge'); ?></div><div class="wpaib-muted"><?php esc_html_e('Automatic backup before overwrite/delete; PHP syntax validation; no core, wp-config.php, shell, or arbitrary database access.', 'wp-ai-bridge'); ?></div></div>
            </div>

            <div class="wpaib-card">
                <h2><?php esc_html_e('Updates', 'wp-ai-bridge'); ?></h2>
                <?php if (!is_array($update)) : ?>
                    <p class="wpaib-warn"><?php esc_html_e('Update checker unavailable.', 'wp-ai-bridge'); ?></p>
                <?php elseif (!empty($update['error'])) : ?>
                    <p class="wpaib-warn"><?php esc_html_e('Could not check GitHub:', 'wp-ai-bridge'); ?> <?php echo esc_html((string) $update['error']); ?></p>
                <?php elseif (!empty($update['update_available'])) : ?>
                    <p><strong>v<?php echo esc_html((string) $update['remote_version']); ?></strong> <span class="wpaib-muted">· installed v<?php echo esc_html(WPAIB_VERSION); ?></span></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(WPAIB_Updater::update_url()); ?>"><?php esc_html_e('Update now', 'wp-ai-bridge'); ?></a></p>
                <?php else : ?>
                    <p><span class="wpaib-ok"><?php esc_html_e('Up to date', 'wp-ai-bridge'); ?></span> <span class="wpaib-muted">· GitHub main v<?php echo esc_html((string) ($update['remote_version'] ?? WPAIB_VERSION)); ?></span></p>
                <?php endif; ?>
                <p class="wpaib-muted" style="margin-bottom:0"><?php esc_html_e('GitHub is checked whenever this settings page is loaded.', 'wp-ai-bridge'); ?></p>
            </div>

            <details class="wpaib-card">
                <summary><?php esc_html_e('Manual API token', 'wp-ai-bridge'); ?></summary>
                <p class="wpaib-muted"><?php esc_html_e('Optional. Use this only for curl, scripts, or other API clients. ChatGPT uses OAuth instead.', 'wp-ai-bridge'); ?></p>
                <?php if ('' !== $token) : ?>
                    <input class="wpaib-code" type="text" readonly value="<?php echo esc_attr($token); ?>" onclick="this.select();" autocomplete="off"><p><strong><?php esc_html_e('Copy this token now.', 'wp-ai-bridge'); ?></strong></p>
                <?php elseif ($token_exists) : ?>
                    <p><span class="wpaib-ok"><?php esc_html_e('Configured', 'wp-ai-bridge'); ?></span><?php echo $created_at ? ' · ' . esc_html($created_at) : ''; ?></p>
                <?php else : ?>
                    <p class="wpaib-muted"><?php esc_html_e('Not configured.', 'wp-ai-bridge'); ?></p>
                <?php endif; ?>
                <div class="wpaib-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wpaib_generate_token"><?php wp_nonce_field('wpaib_generate_token'); ?><?php submit_button($token_exists ? __('Rotate token', 'wp-ai-bridge') : __('Generate token', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></form>
                    <?php if ($token_exists) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Revoke the current API token?', 'wp-ai-bridge')); ?>');"><input type="hidden" name="action" value="wpaib_revoke_token"><?php wp_nonce_field('wpaib_revoke_token'); ?><?php submit_button(__('Revoke', 'wp-ai-bridge'), 'link-delete', 'submit', false); ?></form><?php endif; ?>
                </div>
            </details>
        </div>
        <?php
    }

    private static function require_admin_action(string $nonce_action): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to manage WP AI Bridge.', 'wp-ai-bridge'));
        check_admin_referer($nonce_action);
    }

    private static function token_transient_key(): string {
        return self::TOKEN_TRANSIENT_PREFIX . get_current_user_id();
    }

    private static function settings_url(array $args = []): string {
        return add_query_arg(array_merge(['page' => 'wp-ai-bridge'], $args), admin_url('options-general.php'));
    }
}
