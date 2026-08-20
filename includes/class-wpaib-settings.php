<?php

defined('ABSPATH') || exit;

final class WPAIB_Settings {
    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wpaib_github_connect', [self::class, 'connect_github']);
    }

    public static function menu(): void {
        add_options_page('WP AI Bridge', 'WP AI Bridge', 'manage_options', 'wp-ai-bridge', [self::class, 'render']);
    }

    public static function connect_github(): void {
        self::require_admin_action('wpaib_github_connect');
        $repo = isset($_POST['repo']) ? sanitize_text_field(wp_unslash($_POST['repo'])) : '';
        $branch = isset($_POST['branch']) ? sanitize_text_field(wp_unslash($_POST['branch'])) : 'main';
        $token = isset($_POST['token']) ? trim((string) wp_unslash($_POST['token'])) : '';

        $result = WPAIB_GitHub_Sync::save_connection($repo, $branch, $token);
        if (is_wp_error($result)) {
            set_transient('wpaib_admin_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS);
            wp_safe_redirect(self::settings_url(['wpaib_notice' => 'connect-error']));
            exit;
        }

        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'connected', 'wpaib_sync' => '1']));
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        $status = WPAIB_GitHub_Sync::status();
        $update = WPAIB_Updater::status(true);
        $notice = isset($_GET['wpaib_notice']) ? sanitize_key(wp_unslash($_GET['wpaib_notice'])) : '';
        $auto_sync = isset($_GET['wpaib_sync']) && '1' === (string) $_GET['wpaib_sync'];
        $error = get_transient('wpaib_admin_error_' . get_current_user_id());
        if (is_string($error) && '' !== $error) delete_transient('wpaib_admin_error_' . get_current_user_id()); else $error = '';
        $nonce = wp_create_nonce('wpaib_github_sync');
        ?>
        <div class="wrap wpaib-wrap">
            <style>
                .wpaib-wrap{max-width:820px}.wpaib-head{display:flex;align-items:center;justify-content:space-between;margin:18px 0}.wpaib-head h1{margin:0}.wpaib-muted{color:#646970}.wpaib-ok{color:#008a20;font-weight:600}.wpaib-warn{color:#b32d2e;font-weight:600}.wpaib-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:14px 0}.wpaib-card h2{margin:0 0 14px}.wpaib-grid{display:grid;grid-template-columns:150px 1fr;gap:12px 18px;align-items:center}.wpaib-code{width:100%;font-family:monospace}.wpaib-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.wpaib-progress{display:none;margin-top:14px}.wpaib-progress progress{width:100%;height:14px}.wpaib-scope code{display:inline-block;margin:3px 5px 3px 0}.wpaib-status{padding-top:4px}@media(max-width:782px){.wpaib-grid{grid-template-columns:1fr}.wpaib-head{align-items:flex-start;gap:12px}}
            </style>

            <div class="wpaib-head"><h1>WP AI Bridge</h1><span class="wpaib-muted">v<?php echo esc_html(WPAIB_VERSION); ?></span></div>

            <?php if ('connected' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('GitHub connected. Preparing the first project snapshot…', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('' !== $error) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

            <div class="wpaib-card">
                <h2><?php esc_html_e('GitHub project', 'wp-ai-bridge'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wpaib_github_connect">
                    <?php wp_nonce_field('wpaib_github_connect'); ?>
                    <div class="wpaib-grid">
                        <label for="wpaib-repo"><strong><?php esc_html_e('Repository', 'wp-ai-bridge'); ?></strong></label>
                        <input id="wpaib-repo" class="regular-text code" name="repo" placeholder="owner/website-project" value="<?php echo esc_attr((string) $status['repo']); ?>" required>

                        <label for="wpaib-branch"><strong><?php esc_html_e('Branch', 'wp-ai-bridge'); ?></strong></label>
                        <input id="wpaib-branch" class="regular-text code" name="branch" value="<?php echo esc_attr((string) $status['branch']); ?>" required>

                        <label for="wpaib-token"><strong><?php esc_html_e('GitHub token', 'wp-ai-bridge'); ?></strong></label>
                        <div><input id="wpaib-token" class="regular-text code" type="password" name="token" autocomplete="new-password" placeholder="<?php echo !empty($status['connected']) ? esc_attr__('Leave blank to keep current token', 'wp-ai-bridge') : 'github_pat_…'; ?>"><p class="description"><?php esc_html_e('Use a fine-grained token limited to this repository with Contents: Read and write. The token is encrypted before storage.', 'wp-ai-bridge'); ?></p></div>
                    </div>
                    <div class="wpaib-actions"><?php submit_button(!empty($status['connected']) ? __('Save connection', 'wp-ai-bridge') : __('Connect GitHub', 'wp-ai-bridge'), 'primary', 'submit', false); ?><?php if (!empty($status['connected'])) : ?><span class="wpaib-ok"><?php esc_html_e('Connected', 'wp-ai-bridge'); ?></span><?php endif; ?></div>
                </form>
            </div>

            <?php if (!empty($status['connected'])) : ?>
            <div class="wpaib-card">
                <h2><?php esc_html_e('Project sync', 'wp-ai-bridge'); ?></h2>
                <p class="wpaib-scope"><code>active theme</code><code>parent theme</code><code>active plugins</code></p>
                <p class="wpaib-muted"><?php esc_html_e('Not uploaded: Media Library/uploads, WordPress core, wp-config.php, cache, backups, logs, secrets, node_modules, or WP AI Bridge itself.', 'wp-ai-bridge'); ?></p>

                <div class="wpaib-grid wpaib-status">
                    <strong><?php esc_html_e('Repository', 'wp-ai-bridge'); ?></strong><span><code><?php echo esc_html((string) $status['repo']); ?></code> · <code><?php echo esc_html((string) $status['branch']); ?></code></span>
                    <strong><?php esc_html_e('Last sync', 'wp-ai-bridge'); ?></strong><span><?php echo !empty($status['last_sync_at']) ? esc_html((string) $status['last_sync_at']) : '<span class="wpaib-muted">Never</span>'; ?></span>
                    <strong><?php esc_html_e('Site commit', 'wp-ai-bridge'); ?></strong><span><?php echo !empty($status['last_remote_sha']) ? '<code>' . esc_html(substr((string) $status['last_remote_sha'], 0, 12)) . '</code>' : '<span class="wpaib-muted">Not synced yet</span>'; ?></span>
                    <strong><?php esc_html_e('Auto deploy', 'wp-ai-bridge'); ?></strong><span class="wpaib-ok"><?php esc_html_e('Checks GitHub about every 5 minutes', 'wp-ai-bridge'); ?></span>
                </div>

                <?php if (!empty($status['last_error'])) : ?><p class="wpaib-warn"><?php echo esc_html((string) $status['last_error']); ?></p><?php endif; ?>

                <div class="wpaib-actions"><button type="button" class="button button-primary" id="wpaib-sync-now"><?php esc_html_e('Push project now', 'wp-ai-bridge'); ?></button><span id="wpaib-sync-text" class="wpaib-muted"></span></div>
                <div class="wpaib-progress" id="wpaib-progress"><progress id="wpaib-progress-bar" max="100" value="0"></progress></div>
            </div>
            <?php endif; ?>

            <div class="wpaib-card">
                <h2><?php esc_html_e('Plugin update', 'wp-ai-bridge'); ?></h2>
                <?php if (!empty($update['update_available'])) : ?>
                    <p><strong>v<?php echo esc_html((string) $update['remote_version']); ?></strong> <span class="wpaib-muted">· installed v<?php echo esc_html(WPAIB_VERSION); ?></span></p>
                    <a class="button" href="<?php echo esc_url(WPAIB_Updater::update_url()); ?>"><?php esc_html_e('Update WP AI Bridge', 'wp-ai-bridge'); ?></a>
                <?php elseif (!empty($update['error'])) : ?>
                    <p class="wpaib-warn"><?php echo esc_html((string) $update['error']); ?></p>
                <?php else : ?>
                    <p><span class="wpaib-ok"><?php esc_html_e('Up to date', 'wp-ai-bridge'); ?></span></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($status['connected'])) : ?>
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
                const response = await fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body});
                const json = await response.json();
                if (!json.success) throw new Error(json?.data?.message || 'Sync failed.');
                return json.data;
            };

            const run = async () => {
                button.disabled = true;
                box.style.display = 'block';
                bar.value = 0;
                text.textContent = <?php echo wp_json_encode(__('Scanning project…', 'wp-ai-bridge')); ?>;
                try {
                    const start = await post({action: 'wpaib_sync_start', nonce});
                    let result = start;
                    while (!result.complete) {
                        result = await post({action: 'wpaib_sync_step', nonce, sync_id: start.sync_id});
                        const pct = Math.round((result.done / Math.max(1, result.total)) * 100);
                        bar.value = pct;
                        text.textContent = `${result.done}/${result.total}`;
                    }
                    bar.value = 100;
                    text.textContent = <?php echo wp_json_encode(__('Project pushed to GitHub.', 'wp-ai-bridge')); ?>;
                    setTimeout(() => window.location.reload(), 700);
                } catch (error) {
                    text.textContent = error.message;
                    text.classList.add('wpaib-warn');
                    button.disabled = false;
                }
            };

            button.addEventListener('click', run);
            <?php if ($auto_sync) : ?>setTimeout(run, 250);<?php endif; ?>
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    private static function require_admin_action(string $nonce_action): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to manage WP AI Bridge.', 'wp-ai-bridge'));
        check_admin_referer($nonce_action);
    }

    private static function settings_url(array $args = []): string {
        return add_query_arg(array_merge(['page' => 'wp-ai-bridge'], $args), admin_url('options-general.php'));
    }
}
