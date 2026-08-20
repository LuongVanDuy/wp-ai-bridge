<?php

defined('ABSPATH') || exit;

final class WPAIB_Settings {
    private const ERROR_PREFIX = 'wpaib_admin_error_';
    private const KEY_PREFIX = 'wpaib_admin_fleet_key_';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wpaib_fleet_connect', [self::class, 'connect_fleet']);
        add_action('admin_post_wpaib_fleet_disconnect', [self::class, 'disconnect_fleet']);
        add_action('admin_post_wpaib_fleet_hub_save', [self::class, 'save_hub']);
        add_action('admin_post_wpaib_fleet_key_generate', [self::class, 'generate_fleet_key']);
        add_action('admin_post_wpaib_fleet_key_revoke', [self::class, 'revoke_fleet_key']);
        add_action('admin_post_wpaib_github_connect', [self::class, 'connect_github']);
    }

    public static function menu(): void {
        add_options_page('WP AI Bridge', 'WP AI Bridge', 'manage_options', 'wp-ai-bridge', [self::class, 'render']);
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

    public static function save_hub(): void {
        self::require_admin_action('wpaib_fleet_hub_save');
        $enabled = isset($_POST['enabled']) && '1' === (string) $_POST['enabled'];
        $app_id = isset($_POST['app_id']) ? absint($_POST['app_id']) : 0;
        $installation_id = isset($_POST['installation_id']) ? absint($_POST['installation_id']) : 0;
        $private_key = isset($_POST['private_key']) ? (string) wp_unslash($_POST['private_key']) : '';
        $provision_token = isset($_POST['provision_token']) ? trim((string) wp_unslash($_POST['provision_token'])) : '';
        $result = WPAIB_Fleet_Hub::save_config($enabled, $app_id, $installation_id, $private_key, $provision_token);
        if (is_wp_error($result)) self::redirect_error($result->get_error_message());
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'hub-saved']));
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

    public static function connect_github(): void {
        self::require_admin_action('wpaib_github_connect');
        if (WPAIB_Fleet_Client::connected()) WPAIB_Fleet_Client::disconnect();
        $repo = isset($_POST['repo']) ? sanitize_text_field(wp_unslash($_POST['repo'])) : '';
        $branch = isset($_POST['branch']) ? sanitize_text_field(wp_unslash($_POST['branch'])) : 'main';
        $token = isset($_POST['token']) ? trim((string) wp_unslash($_POST['token'])) : '';
        $result = WPAIB_GitHub_Sync::save_connection($repo, $branch, $token);
        if (is_wp_error($result)) self::redirect_error($result->get_error_message());
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'direct-connected', 'wpaib_sync' => '1']));
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        $sync = WPAIB_GitHub_Sync::status();
        $fleet = WPAIB_Fleet_Client::status();
        $hub = WPAIB_Fleet_Hub::status();
        $update = WPAIB_Updater::status(true);
        $notice = isset($_GET['wpaib_notice']) ? sanitize_key(wp_unslash($_GET['wpaib_notice'])) : '';
        $auto_sync = isset($_GET['wpaib_sync']) && '1' === (string) $_GET['wpaib_sync'];
        $error = get_transient(self::ERROR_PREFIX . get_current_user_id());
        if (is_string($error) && '' !== $error) delete_transient(self::ERROR_PREFIX . get_current_user_id()); else $error = '';
        $fleet_key = get_transient(self::KEY_PREFIX . get_current_user_id());
        if (is_string($fleet_key) && '' !== $fleet_key) delete_transient(self::KEY_PREFIX . get_current_user_id()); else $fleet_key = '';
        $nonce = wp_create_nonce('wpaib_github_sync');
        ?>
        <div class="wrap wpaib-wrap">
            <style>
                .wpaib-wrap{max-width:860px}.wpaib-head{display:flex;align-items:center;justify-content:space-between;margin:18px 0}.wpaib-head h1{margin:0}.wpaib-muted{color:#646970}.wpaib-ok{color:#008a20;font-weight:600}.wpaib-warn{color:#b32d2e;font-weight:600}.wpaib-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:14px 0}.wpaib-card h2{margin:0 0 14px}.wpaib-card summary{cursor:pointer;font-weight:600;font-size:14px}.wpaib-grid{display:grid;grid-template-columns:160px 1fr;gap:12px 18px;align-items:center}.wpaib-code{width:100%;font-family:monospace}.wpaib-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.wpaib-progress{display:none;margin-top:14px}.wpaib-progress progress{width:100%;height:14px}.wpaib-scope code{display:inline-block;margin:3px 5px 3px 0}.wpaib-status{padding-top:4px}.wpaib-secret{font-family:monospace;width:100%;min-height:72px}.wpaib-inline-form{display:inline-block;margin:0}.wpaib-section{margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f1}@media(max-width:782px){.wpaib-grid{grid-template-columns:1fr}.wpaib-head{align-items:flex-start;gap:12px}}
            </style>

            <div class="wpaib-head"><h1>WP AI Bridge</h1><span class="wpaib-muted">v<?php echo esc_html(WPAIB_VERSION); ?></span></div>

            <?php if ('' !== $error) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if ('fleet-connected' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fleet connected. Preparing the first project snapshot…', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('fleet-disconnected' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fleet connection removed from this site.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('hub-saved' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fleet Hub settings saved.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('fleet-key-created' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fleet Key created. Copy it now.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('fleet-key-revoked' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Fleet Key revoked.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>

            <div class="wpaib-card">
                <h2><?php esc_html_e('Fleet connection', 'wp-ai-bridge'); ?></h2>
                <?php if (!empty($fleet['connected'])) : ?>
                    <div class="wpaib-grid wpaib-status">
                        <strong><?php esc_html_e('Status', 'wp-ai-bridge'); ?></strong><span class="wpaib-ok"><?php esc_html_e('Connected', 'wp-ai-bridge'); ?></span>
                        <strong><?php esc_html_e('Hub', 'wp-ai-bridge'); ?></strong><code><?php echo esc_html((string) $fleet['hub']); ?></code>
                        <strong><?php esc_html_e('Repository', 'wp-ai-bridge'); ?></strong><code><?php echo esc_html((string) $fleet['repo']); ?></code>
                        <strong><?php esc_html_e('GitHub token', 'wp-ai-bridge'); ?></strong><span class="wpaib-muted"><?php esc_html_e('Short-lived and refreshed automatically by Fleet Hub', 'wp-ai-bridge'); ?></span>
                    </div>
                    <form class="wpaib-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this site from Fleet?', 'wp-ai-bridge')); ?>');">
                        <input type="hidden" name="action" value="wpaib_fleet_disconnect"><?php wp_nonce_field('wpaib_fleet_disconnect'); ?>
                        <div class="wpaib-actions"><?php submit_button(__('Disconnect Fleet', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></div>
                    </form>
                <?php else : ?>
                    <p><?php esc_html_e('For normal sites, paste one Fleet Key generated by your Hub. No repository name or GitHub PAT is needed on this site.', 'wp-ai-bridge'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpaib_fleet_connect"><?php wp_nonce_field('wpaib_fleet_connect'); ?>
                        <textarea class="wpaib-secret" name="fleet_key" placeholder="wpaib_fleet_…" required></textarea>
                        <div class="wpaib-actions"><?php submit_button(__('Connect & Sync', 'wp-ai-bridge'), 'primary', 'submit', false); ?></div>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!empty($sync['connected'])) : ?>
            <div class="wpaib-card">
                <h2><?php esc_html_e('Project sync', 'wp-ai-bridge'); ?></h2>
                <p class="wpaib-scope"><code>active theme</code><code>parent theme</code><code>active plugins</code></p>
                <p class="wpaib-muted"><?php esc_html_e('Not uploaded: Media Library/uploads, WordPress core, wp-config.php, cache, backups, logs, secrets, node_modules, or WP AI Bridge itself.', 'wp-ai-bridge'); ?></p>
                <div class="wpaib-grid wpaib-status">
                    <strong><?php esc_html_e('Repository', 'wp-ai-bridge'); ?></strong><span><code><?php echo esc_html((string) $sync['repo']); ?></code> · <code><?php echo esc_html((string) $sync['branch']); ?></code></span>
                    <strong><?php esc_html_e('Last sync', 'wp-ai-bridge'); ?></strong><span><?php echo !empty($sync['last_sync_at']) ? esc_html((string) $sync['last_sync_at']) : '<span class="wpaib-muted">Never</span>'; ?></span>
                    <strong><?php esc_html_e('Site commit', 'wp-ai-bridge'); ?></strong><span><?php echo !empty($sync['last_remote_sha']) ? '<code>' . esc_html(substr((string) $sync['last_remote_sha'], 0, 12)) . '</code>' : '<span class="wpaib-muted">Not synced yet</span>'; ?></span>
                    <strong><?php esc_html_e('Auto deploy', 'wp-ai-bridge'); ?></strong><span class="wpaib-ok"><?php esc_html_e('Checks GitHub about every 5 minutes', 'wp-ai-bridge'); ?></span>
                </div>
                <?php if (!empty($sync['last_error'])) : ?><p class="wpaib-warn"><?php echo esc_html((string) $sync['last_error']); ?></p><?php endif; ?>
                <div class="wpaib-actions"><button type="button" class="button button-primary" id="wpaib-sync-now"><?php esc_html_e('Push project now', 'wp-ai-bridge'); ?></button><span id="wpaib-sync-text" class="wpaib-muted"></span></div>
                <div class="wpaib-progress" id="wpaib-progress"><progress id="wpaib-progress-bar" max="100" value="0"></progress></div>
            </div>
            <?php endif; ?>

            <details class="wpaib-card" <?php echo !empty($hub['enabled']) ? 'open' : ''; ?>>
                <summary><?php esc_html_e('Fleet Hub — configure once', 'wp-ai-bridge'); ?></summary>
                <div class="wpaib-section">
                    <p><?php esc_html_e('Use one WordPress site as the Hub for all your sites. The Hub stores the GitHub App private key; client sites receive only repo-scoped, short-lived installation tokens.', 'wp-ai-bridge'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpaib_fleet_hub_save"><?php wp_nonce_field('wpaib_fleet_hub_save'); ?>
                        <div class="wpaib-grid">
                            <strong><?php esc_html_e('Enable Hub', 'wp-ai-bridge'); ?></strong><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($hub['enabled'])); ?>> <?php esc_html_e('This site is the Fleet Hub', 'wp-ai-bridge'); ?></label>
                            <label for="wpaib-app-id"><strong><?php esc_html_e('GitHub App ID', 'wp-ai-bridge'); ?></strong></label><input id="wpaib-app-id" name="app_id" type="number" min="1" value="<?php echo esc_attr((string) $hub['app_id']); ?>" required>
                            <label for="wpaib-install-id"><strong><?php esc_html_e('Installation ID', 'wp-ai-bridge'); ?></strong></label><input id="wpaib-install-id" name="installation_id" type="number" min="1" value="<?php echo esc_attr((string) $hub['installation_id']); ?>" required>
                            <label for="wpaib-private-key"><strong><?php esc_html_e('Private key', 'wp-ai-bridge'); ?></strong></label><div><textarea id="wpaib-private-key" class="wpaib-secret" name="private_key" placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;…"></textarea><p class="description"><?php echo !empty($hub['has_private_key']) ? esc_html__('Configured — leave blank to keep it.', 'wp-ai-bridge') : esc_html__('Paste the GitHub App private key once. It is encrypted before storage.', 'wp-ai-bridge'); ?></p></div>
                            <label for="wpaib-provision"><strong><?php esc_html_e('Provisioning token', 'wp-ai-bridge'); ?></strong></label><div><input id="wpaib-provision" class="regular-text code" type="password" name="provision_token" autocomplete="new-password"><p class="description"><?php esc_html_e('Optional for organizations. Required only when a personal GitHub account should auto-create repositories. Stored only on the Hub.', 'wp-ai-bridge'); ?><?php echo !empty($hub['has_provision_token']) ? ' ' . esc_html__('Already configured; leave blank to keep it.', 'wp-ai-bridge') : ''; ?></p></div>
                        </div>
                        <p class="description"><?php esc_html_e('GitHub App repository permissions: Contents Read & write. For organization auto-provisioning also grant Administration Read & write. Install the App for All repositories for the simplest fleet setup.', 'wp-ai-bridge'); ?></p>
                        <div class="wpaib-actions"><?php submit_button(__('Save Hub', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></div>
                    </form>

                    <?php if (!empty($hub['configured'])) : ?>
                    <div class="wpaib-grid wpaib-section">
                        <strong><?php esc_html_e('GitHub owner', 'wp-ai-bridge'); ?></strong><span><?php echo esc_html((string) $hub['account']); ?><?php echo !empty($hub['account_type']) ? ' · ' . esc_html((string) $hub['account_type']) : ''; ?></span>
                        <strong><?php esc_html_e('Enrolled sites', 'wp-ai-bridge'); ?></strong><span><?php echo esc_html((string) $hub['site_count']); ?></span>
                        <strong><?php esc_html_e('Fleet Key', 'wp-ai-bridge'); ?></strong><span><?php echo !empty($hub['enroll_expires']) && (int) $hub['enroll_expires'] >= time() ? '<span class="wpaib-ok">Active until ' . esc_html(gmdate('Y-m-d H:i', (int) $hub['enroll_expires'])) . ' UTC</span>' : '<span class="wpaib-muted">Not active</span>'; ?></span>
                    </div>
                    <?php if (!empty($hub['error'])) : ?><p class="wpaib-warn"><?php echo esc_html((string) $hub['error']); ?></p><?php endif; ?>
                    <?php if ('' !== $fleet_key) : ?><p><strong><?php esc_html_e('Copy this Fleet Key now:', 'wp-ai-bridge'); ?></strong></p><textarea class="wpaib-secret" readonly onclick="this.select();"><?php echo esc_textarea($fleet_key); ?></textarea><?php endif; ?>
                    <div class="wpaib-actions">
                        <form class="wpaib-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wpaib_fleet_key_generate"><?php wp_nonce_field('wpaib_fleet_key_generate'); ?><?php submit_button(__('Generate 24-hour Fleet Key', 'wp-ai-bridge'), 'primary', 'submit', false); ?></form>
                        <?php if (!empty($hub['enroll_expires']) && (int) $hub['enroll_expires'] >= time()) : ?><form class="wpaib-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wpaib_fleet_key_revoke"><?php wp_nonce_field('wpaib_fleet_key_revoke'); ?><?php submit_button(__('Revoke Fleet Key', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></form><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </details>

            <details class="wpaib-card">
                <summary><?php esc_html_e('Advanced — Direct GitHub connection', 'wp-ai-bridge'); ?></summary>
                <div class="wpaib-section">
                    <p class="wpaib-muted"><?php esc_html_e('Fallback for a small number of sites. This uses a per-site GitHub token. Saving this form disconnects Fleet on this site.', 'wp-ai-bridge'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpaib_github_connect"><?php wp_nonce_field('wpaib_github_connect'); ?>
                        <div class="wpaib-grid">
                            <label for="wpaib-repo"><strong><?php esc_html_e('Repository', 'wp-ai-bridge'); ?></strong></label><input id="wpaib-repo" class="regular-text code" name="repo" placeholder="owner/website-project" value="<?php echo esc_attr(!empty($fleet['connected']) ? '' : (string) $sync['repo']); ?>" required>
                            <label for="wpaib-branch"><strong><?php esc_html_e('Branch', 'wp-ai-bridge'); ?></strong></label><input id="wpaib-branch" class="regular-text code" name="branch" value="<?php echo esc_attr(!empty($fleet['connected']) ? 'main' : (string) $sync['branch']); ?>" required>
                            <label for="wpaib-token"><strong><?php esc_html_e('GitHub token', 'wp-ai-bridge'); ?></strong></label><input id="wpaib-token" class="regular-text code" type="password" name="token" autocomplete="new-password" placeholder="github_pat_…">
                        </div>
                        <div class="wpaib-actions"><?php submit_button(__('Save direct connection', 'wp-ai-bridge'), 'secondary', 'submit', false); ?></div>
                    </form>
                </div>
            </details>

            <div class="wpaib-card">
                <h2><?php esc_html_e('Plugin update', 'wp-ai-bridge'); ?></h2>
                <?php if (!empty($update['update_available'])) : ?>
                    <p><strong>v<?php echo esc_html((string) $update['remote_version']); ?></strong> <span class="wpaib-muted">· installed v<?php echo esc_html(WPAIB_VERSION); ?></span></p>
                    <a class="button" href="<?php echo esc_url(WPAIB_Updater::update_url()); ?>"><?php esc_html_e('Update WP AI Bridge', 'wp-ai-bridge'); ?></a>
                <?php elseif (!empty($update['error'])) : ?>
                    <p class="wpaib-warn"><?php echo esc_html((string) $update['error']); ?></p>
                <?php else : ?><p><span class="wpaib-ok"><?php esc_html_e('Up to date', 'wp-ai-bridge'); ?></span></p><?php endif; ?>
            </div>
        </div>

        <?php if (!empty($sync['connected'])) : ?>
        <script>
        (() => {
            const button = document.getElementById('wpaib-sync-now');
            const text = document.getElementById('wpaib-sync-text');
            const box = document.getElementById('wpaib-progress');
            const bar = document.getElementById('wpaib-progress-bar');
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            if (!button) return;
            const post = async (data) => {
                const body = new URLSearchParams(data);
                const response = await fetch(ajaxurl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
                const json = await response.json();
                if (!json.success) throw new Error(json?.data?.message || 'Sync failed.');
                return json.data;
            };
            const run = async () => {
                button.disabled = true; box.style.display = 'block'; bar.value = 0; text.textContent = <?php echo wp_json_encode(__('Scanning project…', 'wp-ai-bridge')); ?>;
                try {
                    const start = await post({action:'wpaib_sync_start',nonce});
                    let result = start;
                    while (!result.complete) {
                        result = await post({action:'wpaib_sync_step',nonce,sync_id:start.sync_id});
                        bar.value = Math.round((result.done / Math.max(1,result.total)) * 100);
                        text.textContent = `${result.done}/${result.total}`;
                    }
                    bar.value = 100; text.textContent = <?php echo wp_json_encode(__('Project pushed to GitHub.', 'wp-ai-bridge')); ?>;
                    setTimeout(() => window.location.reload(), 700);
                } catch (error) { text.textContent = error.message; text.classList.add('wpaib-warn'); button.disabled = false; }
            };
            button.addEventListener('click', run);
            <?php if ($auto_sync) : ?>setTimeout(run, 250);<?php endif; ?>
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    private static function redirect_error(string $message): void {
        set_transient(self::ERROR_PREFIX . get_current_user_id(), sanitize_text_field($message), MINUTE_IN_SECONDS);
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'error']));
        exit;
    }

    private static function require_admin_action(string $nonce_action): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to manage WP AI Bridge.', 'wp-ai-bridge'));
        check_admin_referer($nonce_action);
    }

    private static function settings_url(array $args = []): string {
        return add_query_arg(array_merge(['page' => 'wp-ai-bridge'], $args), admin_url('options-general.php'));
    }
}
