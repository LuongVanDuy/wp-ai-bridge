<?php

defined('ABSPATH') || exit;

final class WPAIB_Settings {
    private const ERROR_PREFIX = 'wpaib_admin_error_';
    private const KEY_PREFIX = 'wpaib_admin_fleet_key_';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wpaib_oauth_client_save', [self::class, 'save_oauth_client']);
        add_action('admin_post_wpaib_oauth_disconnect', [self::class, 'disconnect_oauth']);
        add_action('admin_post_wpaib_fleet_connect', [self::class, 'connect_fleet']);
        add_action('admin_post_wpaib_fleet_disconnect', [self::class, 'disconnect_fleet']);
        add_action('admin_post_wpaib_fleet_key_generate', [self::class, 'generate_fleet_key']);
        add_action('admin_post_wpaib_fleet_key_revoke', [self::class, 'revoke_fleet_key']);
        add_action('wp_ajax_wpaib_oauth_start', [self::class, 'ajax_oauth_start']);
        add_action('wp_ajax_wpaib_oauth_poll', [self::class, 'ajax_oauth_poll']);
    }

    public static function menu(): void {
        add_options_page('WP AI Bridge', 'WP AI Bridge', 'manage_options', 'wp-ai-bridge', [self::class, 'render']);
    }

    public static function save_oauth_client(): void {
        self::require_admin_action('wpaib_oauth_client_save');
        $client_id = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
        $result = WPAIB_GitHub_OAuth::save_client_id($client_id);
        if (is_wp_error($result)) self::redirect_error($result->get_error_message());
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'client-saved']));
        exit;
    }

    public static function disconnect_oauth(): void {
        self::require_admin_action('wpaib_oauth_disconnect');
        WPAIB_GitHub_OAuth::disconnect();
        WPAIB_Fleet_Hub::revoke_fleet_key();
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'github-disconnected']));
        exit;
    }

    public static function connect_fleet(): void {
        self::require_admin_action('wpaib_fleet_connect');
        $key = isset($_POST['fleet_key']) ? trim((string) wp_unslash($_POST['fleet_key'])) : '';
        $result = WPAIB_Fleet_Client::connect_with_key($key);
        if (is_wp_error($result)) self::redirect_error($result->get_error_message());
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'fleet-connected', 'wpaib_sync' => '1']));
        exit;
    }

    public static function disconnect_fleet(): void {
        self::require_admin_action('wpaib_fleet_disconnect');
        WPAIB_Fleet_Client::disconnect();
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'fleet-disconnected']));
        exit;
    }

    public static function generate_fleet_key(): void {
        self::require_admin_action('wpaib_fleet_key_generate');
        $key = WPAIB_Fleet_Hub::generate_fleet_key();
        if (is_wp_error($key)) self::redirect_error($key->get_error_message());
        set_transient(self::KEY_PREFIX . get_current_user_id(), $key, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'fleet-key-created']));
        exit;
    }

    public static function revoke_fleet_key(): void {
        self::require_admin_action('wpaib_fleet_key_revoke');
        WPAIB_Fleet_Hub::revoke_fleet_key();
        delete_transient(self::KEY_PREFIX . get_current_user_id());
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'fleet-key-revoked']));
        exit;
    }

    public static function ajax_oauth_start(): void {
        self::require_ajax_admin();
        $result = WPAIB_GitHub_OAuth::start_device_flow(get_current_user_id());
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
        wp_send_json_success($result);
    }

    public static function ajax_oauth_poll(): void {
        self::require_ajax_admin();
        $result = WPAIB_GitHub_OAuth::poll_device_flow(get_current_user_id());
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
        if (empty($result['pending']) && !empty($result['connected'])) {
            $key = WPAIB_Fleet_Hub::generate_fleet_key();
            if (is_string($key) && '' !== $key) set_transient(self::KEY_PREFIX . get_current_user_id(), $key, 10 * MINUTE_IN_SECONDS);
        }
        wp_send_json_success($result);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        $oauth = [
            'configured' => WPAIB_GitHub_OAuth::configured(),
            'connected' => WPAIB_GitHub_OAuth::connected(),
            'client_id' => WPAIB_GitHub_OAuth::client_id(),
            'login' => WPAIB_GitHub_OAuth::login(),
        ];
        $fleet = WPAIB_Fleet_Client::status();
        $hub = WPAIB_Fleet_Hub::status();
        $sync = WPAIB_GitHub_Sync::status();
        $update = WPAIB_Updater::status(true);
        $notice = isset($_GET['wpaib_notice']) ? sanitize_key(wp_unslash($_GET['wpaib_notice'])) : '';
        $auto_sync = isset($_GET['wpaib_sync']) && '1' === (string) $_GET['wpaib_sync'];
        $error = get_transient(self::ERROR_PREFIX . get_current_user_id());
        if (is_string($error) && '' !== $error) delete_transient(self::ERROR_PREFIX . get_current_user_id()); else $error = '';
        $fleet_key = get_transient(self::KEY_PREFIX . get_current_user_id());
        if (is_string($fleet_key) && '' !== $fleet_key) delete_transient(self::KEY_PREFIX . get_current_user_id()); else $fleet_key = '';
        $oauth_nonce = wp_create_nonce('wpaib_oauth_ajax');
        $sync_nonce = wp_create_nonce('wpaib_github_sync');
        ?>
        <div class="wrap wpaib-wrap">
            <style>
                .wpaib-wrap{max-width:820px}.wpaib-head{display:flex;align-items:center;justify-content:space-between;margin:18px 0}.wpaib-head h1{margin:0}.wpaib-muted{color:#646970}.wpaib-ok{color:#008a20;font-weight:600}.wpaib-warn{color:#b32d2e;font-weight:600}.wpaib-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;margin:14px 0}.wpaib-card h2{margin:0 0 12px}.wpaib-grid{display:grid;grid-template-columns:145px 1fr;gap:10px 18px;align-items:center}.wpaib-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.wpaib-secret{font-family:monospace;width:100%;min-height:72px}.wpaib-code{font-family:monospace;width:100%}.wpaib-progress{display:none;margin-top:14px}.wpaib-progress progress{width:100%;height:14px}.wpaib-connect-box{display:none;margin-top:16px;padding:16px;background:#f6f7f7;border-radius:8px}.wpaib-user-code{font:700 26px/1.2 monospace;letter-spacing:2px;margin:8px 0}.wpaib-scope code{display:inline-block;margin:3px 5px 3px 0}@media(max-width:782px){.wpaib-grid{grid-template-columns:1fr}.wpaib-head{gap:12px;align-items:flex-start}}
            </style>

            <div class="wpaib-head"><h1>WP AI Bridge</h1><span class="wpaib-muted">v<?php echo esc_html(WPAIB_VERSION); ?></span></div>

            <?php if ('' !== $error) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if ('client-saved' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('GitHub connection is ready. Click Connect GitHub.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('github-disconnected' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('GitHub disconnected.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('fleet-connected' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Site connected. Preparing the first project snapshot…', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('fleet-disconnected' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fleet connection removed from this site.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>

            <div class="wpaib-card">
                <h2><?php esc_html_e('GitHub', 'wp-ai-bridge'); ?></h2>
                <?php if (!empty($oauth['connected'])) : ?>
                    <div class="wpaib-grid">
                        <strong><?php esc_html_e('Status', 'wp-ai-bridge'); ?></strong><span class="wpaib-ok">✓ <?php esc_html_e('Connected', 'wp-ai-bridge'); ?></span>
                        <strong><?php esc_html_e('Account', 'wp-ai-bridge'); ?></strong><code>@<?php echo esc_html((string) $oauth['login']); ?></code>
                        <strong><?php esc_html_e('Fleet Hub', 'wp-ai-bridge'); ?></strong><span class="wpaib-ok">✓ <?php esc_html_e('Active automatically', 'wp-ai-bridge'); ?></span>
                        <strong><?php esc_html_e('Sites', 'wp-ai-bridge'); ?></strong><span><?php echo esc_html((string) $hub['site_count']); ?></span>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect GitHub from this Hub?', 'wp-ai-bridge')); ?>');">
                        <input type="hidden" name="action" value="wpaib_oauth_disconnect"><?php wp_nonce_field('wpaib_oauth_disconnect'); ?>
                        <div class="wpaib-actions"><?php submit_button(__('Disconnect GitHub', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></div>
                    </form>
                <?php elseif (empty($oauth['configured'])) : ?>
                    <p><strong><?php esc_html_e('One-time setup', 'wp-ai-bridge'); ?></strong></p>
                    <p class="wpaib-muted"><?php esc_html_e('Create one GitHub OAuth App, enable Device Flow, then paste its Client ID here. This is done only on the Hub; client sites never need it.', 'wp-ai-bridge'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpaib_oauth_client_save"><?php wp_nonce_field('wpaib_oauth_client_save'); ?>
                        <input class="regular-text code" name="client_id" placeholder="GitHub Client ID" required>
                        <div class="wpaib-actions"><?php submit_button(__('Save Client ID', 'wp-ai-bridge'), 'primary', 'submit', false); ?></div>
                    </form>
                <?php else : ?>
                    <p><?php esc_html_e('Authorize this Hub once. No App ID, Installation ID, private key, or GitHub PAT is required.', 'wp-ai-bridge'); ?></p>
                    <div class="wpaib-actions"><button type="button" class="button button-primary button-hero" id="wpaib-connect-github"><?php esc_html_e('Connect GitHub', 'wp-ai-bridge'); ?></button><span class="wpaib-muted" id="wpaib-oauth-status"></span></div>
                    <div class="wpaib-connect-box" id="wpaib-connect-box">
                        <div class="wpaib-muted"><?php esc_html_e('GitHub verification code', 'wp-ai-bridge'); ?></div>
                        <div class="wpaib-user-code" id="wpaib-user-code"></div>
                        <a class="button" id="wpaib-verification-link" href="https://github.com/login/device" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open GitHub authorization', 'wp-ai-bridge'); ?></a>
                        <p class="wpaib-muted" style="margin-bottom:0"><?php esc_html_e('Approve in GitHub; this page will detect it automatically.', 'wp-ai-bridge'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($oauth['connected'])) : ?>
            <div class="wpaib-card">
                <h2><?php esc_html_e('Add websites', 'wp-ai-bridge'); ?></h2>
                <p><?php esc_html_e('Use one Fleet Key on any WordPress site you want to add. The same key can enroll multiple sites until it expires.', 'wp-ai-bridge'); ?></p>
                <?php if ('' !== $fleet_key) : ?>
                    <textarea class="wpaib-secret" readonly onclick="this.select();"><?php echo esc_textarea($fleet_key); ?></textarea>
                    <p><strong><?php esc_html_e('Copy this Fleet Key now.', 'wp-ai-bridge'); ?></strong> <span class="wpaib-muted"><?php esc_html_e('Valid for 7 days or until you rotate it.', 'wp-ai-bridge'); ?></span></p>
                <?php endif; ?>
                <div class="wpaib-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wpaib_fleet_key_generate"><?php wp_nonce_field('wpaib_fleet_key_generate'); ?><?php submit_button(__('Generate / Rotate Fleet Key', 'wp-ai-bridge'), 'primary', 'submit', false); ?></form>
                    <?php if (!empty($hub['enroll_expires']) && (int) $hub['enroll_expires'] > time()) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wpaib_fleet_key_revoke"><?php wp_nonce_field('wpaib_fleet_key_revoke'); ?><?php submit_button(__('Revoke current key', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></form><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($fleet['connected'])) : ?>
            <div class="wpaib-card">
                <h2><?php esc_html_e('Connect this website', 'wp-ai-bridge'); ?></h2>
                <p class="wpaib-muted"><?php esc_html_e('On a normal site, this is the only setup required: paste a Fleet Key from your Hub.', 'wp-ai-bridge'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wpaib_fleet_connect"><?php wp_nonce_field('wpaib_fleet_connect'); ?>
                    <textarea class="wpaib-secret" name="fleet_key" placeholder="wpaib_fleet_…" required></textarea>
                    <div class="wpaib-actions"><?php submit_button(__('Connect & Sync', 'wp-ai-bridge'), 'primary', 'submit', false); ?></div>
                </form>
            </div>
            <?php else : ?>
            <div class="wpaib-card">
                <h2><?php esc_html_e('This website', 'wp-ai-bridge'); ?></h2>
                <div class="wpaib-grid">
                    <strong><?php esc_html_e('Status', 'wp-ai-bridge'); ?></strong><span class="wpaib-ok">✓ <?php esc_html_e('Connected', 'wp-ai-bridge'); ?></span>
                    <strong><?php esc_html_e('Repository', 'wp-ai-bridge'); ?></strong><code><?php echo esc_html((string) $fleet['repo']); ?></code>
                    <strong><?php esc_html_e('Hub', 'wp-ai-bridge'); ?></strong><code><?php echo esc_html((string) $fleet['hub']); ?></code>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this site?', 'wp-ai-bridge')); ?>');"><input type="hidden" name="action" value="wpaib_fleet_disconnect"><?php wp_nonce_field('wpaib_fleet_disconnect'); ?><div class="wpaib-actions"><?php submit_button(__('Disconnect site', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></div></form>
            </div>
            <?php endif; ?>

            <?php if (!empty($sync['connected'])) : ?>
            <div class="wpaib-card">
                <h2><?php esc_html_e('Project sync', 'wp-ai-bridge'); ?></h2>
                <p class="wpaib-scope"><code>active theme</code><code>parent theme</code><code>active plugins</code></p>
                <p class="wpaib-muted"><?php esc_html_e('Excluded: uploads/media, WordPress core, wp-config.php, cache, backups, logs, secrets, node_modules and WP AI Bridge itself.', 'wp-ai-bridge'); ?></p>
                <div class="wpaib-grid">
                    <strong><?php esc_html_e('Repository', 'wp-ai-bridge'); ?></strong><code><?php echo esc_html((string) $sync['repo']); ?></code>
                    <strong><?php esc_html_e('Last sync', 'wp-ai-bridge'); ?></strong><span><?php echo !empty($sync['last_sync_at']) ? esc_html((string) $sync['last_sync_at']) : esc_html__('Never', 'wp-ai-bridge'); ?></span>
                    <strong><?php esc_html_e('Auto deploy', 'wp-ai-bridge'); ?></strong><span class="wpaib-ok"><?php esc_html_e('Checks GitHub about every 5 minutes', 'wp-ai-bridge'); ?></span>
                </div>
                <?php if (!empty($sync['last_error'])) : ?><p class="wpaib-warn"><?php echo esc_html((string) $sync['last_error']); ?></p><?php endif; ?>
                <div class="wpaib-actions"><button type="button" class="button button-primary" id="wpaib-sync-now"><?php esc_html_e('Push project now', 'wp-ai-bridge'); ?></button><span id="wpaib-sync-text" class="wpaib-muted"></span></div>
                <div class="wpaib-progress" id="wpaib-progress"><progress id="wpaib-progress-bar" max="100" value="0"></progress></div>
            </div>
            <?php endif; ?>

            <div class="wpaib-card">
                <h2><?php esc_html_e('Plugin update', 'wp-ai-bridge'); ?></h2>
                <?php if (!empty($update['update_available'])) : ?><p><strong>v<?php echo esc_html((string) $update['remote_version']); ?></strong> <span class="wpaib-muted">· installed v<?php echo esc_html(WPAIB_VERSION); ?></span></p><a class="button" href="<?php echo esc_url(WPAIB_Updater::update_url()); ?>"><?php esc_html_e('Update WP AI Bridge', 'wp-ai-bridge'); ?></a><?php elseif (!empty($update['error'])) : ?><p class="wpaib-warn"><?php echo esc_html((string) $update['error']); ?></p><?php else : ?><p><span class="wpaib-ok">✓ <?php esc_html_e('Up to date', 'wp-ai-bridge'); ?></span></p><?php endif; ?>
            </div>
        </div>

        <script>
        (() => {
            const ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const oauthNonce = <?php echo wp_json_encode($oauth_nonce); ?>;
            const syncNonce = <?php echo wp_json_encode($sync_nonce); ?>;
            const post = async (data) => {
                const body = new URLSearchParams(data);
                const response = await fetch(ajax, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
                const json = await response.json();
                if (!json.success) throw new Error(json?.data?.message || 'Request failed.');
                return json.data;
            };

            const connect = document.getElementById('wpaib-connect-github');
            if (connect) {
                const box = document.getElementById('wpaib-connect-box');
                const code = document.getElementById('wpaib-user-code');
                const link = document.getElementById('wpaib-verification-link');
                const status = document.getElementById('wpaib-oauth-status');
                connect.addEventListener('click', async () => {
                    connect.disabled = true;
                    status.textContent = <?php echo wp_json_encode(__('Starting GitHub…', 'wp-ai-bridge')); ?>;
                    try {
                        const start = await post({action:'wpaib_oauth_start',nonce:oauthNonce});
                        code.textContent = start.user_code;
                        link.href = start.verification_uri;
                        box.style.display = 'block';
                        status.textContent = <?php echo wp_json_encode(__('Waiting for authorization…', 'wp-ai-bridge')); ?>;
                        window.open(start.verification_uri, '_blank', 'noopener,noreferrer');
                        let interval = Math.max(5, Number(start.interval || 5));
                        const poll = async () => {
                            try {
                                const result = await post({action:'wpaib_oauth_poll',nonce:oauthNonce});
                                if (result.connected) {
                                    status.textContent = <?php echo wp_json_encode(__('Connected. Reloading…', 'wp-ai-bridge')); ?>;
                                    window.location.reload();
                                    return;
                                }
                                interval = Math.max(interval, Number(result.interval || interval));
                                setTimeout(poll, interval * 1000);
                            } catch (error) {
                                status.textContent = error.message;
                                connect.disabled = false;
                            }
                        };
                        setTimeout(poll, interval * 1000);
                    } catch (error) {
                        status.textContent = error.message;
                        connect.disabled = false;
                    }
                });
            }

            const syncButton = document.getElementById('wpaib-sync-now');
            if (syncButton) {
                const text = document.getElementById('wpaib-sync-text');
                const box = document.getElementById('wpaib-progress');
                const bar = document.getElementById('wpaib-progress-bar');
                const runSync = async () => {
                    syncButton.disabled = true; box.style.display = 'block'; bar.value = 0;
                    text.textContent = <?php echo wp_json_encode(__('Scanning project…', 'wp-ai-bridge')); ?>;
                    try {
                        const start = await post({action:'wpaib_sync_start',nonce:syncNonce});
                        let result = start;
                        while (!result.complete) {
                            result = await post({action:'wpaib_sync_step',nonce:syncNonce,sync_id:start.sync_id});
                            bar.value = Math.round((result.done / Math.max(1,result.total)) * 100);
                            text.textContent = `${result.done}/${result.total}`;
                        }
                        bar.value = 100; text.textContent = <?php echo wp_json_encode(__('Project pushed to GitHub.', 'wp-ai-bridge')); ?>;
                        setTimeout(() => window.location.reload(), 700);
                    } catch (error) {
                        text.textContent = error.message; text.classList.add('wpaib-warn'); syncButton.disabled = false;
                    }
                };
                syncButton.addEventListener('click', runSync);
                <?php if ($auto_sync) : ?>setTimeout(runSync, 250);<?php endif; ?>
            }
        })();
        </script>
        <?php
    }

    private static function require_admin_action(string $nonce_action): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to manage WP AI Bridge.', 'wp-ai-bridge'));
        check_admin_referer($nonce_action);
    }

    private static function require_ajax_admin(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.'], 403);
        check_ajax_referer('wpaib_oauth_ajax', 'nonce');
    }

    private static function redirect_error(string $message): void {
        set_transient(self::ERROR_PREFIX . get_current_user_id(), sanitize_text_field($message), MINUTE_IN_SECONDS);
        wp_safe_redirect(self::settings_url());
        exit;
    }

    private static function settings_url(array $args = []): string {
        return add_query_arg(array_merge(['page' => 'wp-ai-bridge'], $args), admin_url('options-general.php'));
    }
}
