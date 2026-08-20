<?php

defined('ABSPATH') || exit;

final class WPAIB_Settings {
    private const ERROR_PREFIX = 'wpaib_admin_error_';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wpaib_oauth_disconnect', [self::class, 'disconnect_oauth']);
        add_action('admin_post_wpaib_repo_save', [self::class, 'save_repository']);
        add_action('wp_ajax_wpaib_oauth_start', [self::class, 'ajax_oauth_start']);
        add_action('wp_ajax_wpaib_oauth_poll', [self::class, 'ajax_oauth_poll']);
        add_action('wp_ajax_wpaib_pull_now', [self::class, 'ajax_pull_now']);
    }

    public static function menu(): void {
        add_options_page('WP AI Bridge', 'WP AI Bridge', 'manage_options', 'wp-ai-bridge', [self::class, 'render']);
    }

    public static function disconnect_oauth(): void {
        self::require_admin_action('wpaib_oauth_disconnect');
        WPAIB_GitHub_OAuth::disconnect();
        foreach (['wpaib_github_repo', 'wpaib_github_branch', 'wpaib_github_token', 'wpaib_github_last_remote_sha', 'wpaib_github_last_sync_at', 'wpaib_github_last_error'] as $option) delete_option($option);
        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'disconnected']));
        exit;
    }

    public static function save_repository(): void {
        self::require_admin_action('wpaib_repo_save');
        $typed = isset($_POST['repo_input']) ? sanitize_text_field(wp_unslash($_POST['repo_input'])) : '';
        $selected = isset($_POST['repo_select']) ? sanitize_text_field(wp_unslash($_POST['repo_select'])) : '';
        $value = '' !== trim($typed) ? trim($typed) : trim($selected);

        $repo = WPAIB_GitHub_OAuth::ensure_repository($value);
        if (is_wp_error($repo)) self::redirect_error($repo->get_error_message());

        $full_name = trim((string) ($repo['full_name'] ?? ''));
        $branch = trim((string) ($repo['default_branch'] ?? 'main')) ?: 'main';
        $saved = WPAIB_GitHub_Sync::save_connection($full_name, $branch, WPAIB_GitHub_OAuth::sync_token());
        if (is_wp_error($saved)) self::redirect_error($saved->get_error_message());

        wp_safe_redirect(self::settings_url(['wpaib_notice' => 'repo-saved']));
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
        wp_send_json_success($result);
    }

    public static function ajax_pull_now(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.'], 403);
        check_ajax_referer('wpaib_github_sync', 'nonce');
        $before = WPAIB_GitHub_Sync::status();
        WPAIB_GitHub_Sync::poll_remote();
        $after = WPAIB_GitHub_Sync::status();
        if (!empty($after['last_error'])) wp_send_json_error(['message' => (string) $after['last_error']], 400);
        wp_send_json_success(['changed' => !empty($before['ahead']), 'repo' => (string) ($after['repo'] ?? '')]);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        $oauth_connected = WPAIB_GitHub_OAuth::connected();
        $login = WPAIB_GitHub_OAuth::login();
        $sync = WPAIB_GitHub_Sync::status();
        $repos = [];
        $repo_error = '';
        if ($oauth_connected) {
            $repo_result = WPAIB_GitHub_OAuth::repositories();
            if (is_wp_error($repo_result)) $repo_error = $repo_result->get_error_message();
            else $repos = $repo_result;
        }

        $notice = isset($_GET['wpaib_notice']) ? sanitize_key(wp_unslash($_GET['wpaib_notice'])) : '';
        $error = get_transient(self::ERROR_PREFIX . get_current_user_id());
        if (is_string($error) && '' !== $error) delete_transient(self::ERROR_PREFIX . get_current_user_id()); else $error = '';
        $oauth_nonce = wp_create_nonce('wpaib_oauth_ajax');
        $sync_nonce = wp_create_nonce('wpaib_github_sync');
        ?>
        <div class="wrap wpaib-wrap">
            <style>
                .wpaib-wrap{max-width:760px}.wpaib-head{display:flex;align-items:center;justify-content:space-between;margin:18px 0}.wpaib-head h1{margin:0}.wpaib-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;margin:14px 0}.wpaib-card h2{margin:0 0 14px}.wpaib-muted{color:#646970}.wpaib-ok{color:#008a20;font-weight:600}.wpaib-warn{color:#b32d2e;font-weight:600}.wpaib-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.wpaib-connect-box{display:none;margin-top:16px;padding:16px;background:#f6f7f7;border-radius:8px}.wpaib-user-code{font:700 26px/1.2 monospace;letter-spacing:2px;margin:8px 0}.wpaib-repo-row{display:grid;grid-template-columns:1fr;gap:10px}.wpaib-repo-row select,.wpaib-repo-row input{width:100%;max-width:100%}.wpaib-project{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.wpaib-progress{display:none;margin-top:14px}.wpaib-progress progress{width:100%;height:14px}.wpaib-small-form{display:inline-block;margin:0}@media(max-width:782px){.wpaib-head{align-items:flex-start;gap:12px}}
            </style>

            <div class="wpaib-head"><h1>WP AI Bridge</h1><span class="wpaib-muted">v<?php echo esc_html(WPAIB_VERSION); ?></span></div>

            <?php if ('' !== $error) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if ('repo-saved' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Repository selected.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>
            <?php if ('disconnected' === $notice) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('GitHub disconnected.', 'wp-ai-bridge'); ?></p></div><?php endif; ?>

            <?php if (!$oauth_connected) : ?>
                <div class="wpaib-card">
                    <h2>GitHub</h2>
                    <button type="button" class="button button-primary button-hero" id="wpaib-connect-github">Connect GitHub</button>
                    <span class="wpaib-muted" id="wpaib-oauth-status"></span>
                    <div class="wpaib-connect-box" id="wpaib-connect-box">
                        <div class="wpaib-muted">Enter this code on GitHub</div>
                        <div class="wpaib-user-code" id="wpaib-user-code"></div>
                        <a class="button" id="wpaib-verification-link" href="https://github.com/login/device" target="_blank" rel="noopener noreferrer">Open GitHub</a>
                        <p class="wpaib-muted" style="margin-bottom:0">Approve access in GitHub. This page will detect it automatically.</p>
                    </div>
                </div>
            <?php else : ?>
                <div class="wpaib-card">
                    <div class="wpaib-project">
                        <div><h2 style="margin-bottom:5px">GitHub</h2><span class="wpaib-ok">✓ Connected</span> <code>@<?php echo esc_html($login); ?></code></div>
                        <form class="wpaib-small-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Disconnect GitHub?');">
                            <input type="hidden" name="action" value="wpaib_oauth_disconnect"><?php wp_nonce_field('wpaib_oauth_disconnect'); ?>
                            <?php submit_button('Disconnect', 'secondary', 'submit', false); ?>
                        </form>
                    </div>
                </div>

                <div class="wpaib-card">
                    <h2>Repository</h2>
                    <?php if ('' !== $repo_error) : ?><p class="wpaib-warn"><?php echo esc_html($repo_error); ?></p><?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpaib_repo_save"><?php wp_nonce_field('wpaib_repo_save'); ?>
                        <div class="wpaib-repo-row">
                            <select name="repo_select">
                                <option value="">Select a repository…</option>
                                <?php foreach ($repos as $repo) : $full = (string) ($repo['full_name'] ?? ''); ?>
                                    <option value="<?php echo esc_attr($full); ?>" <?php selected($full, (string) $sync['repo']); ?>><?php echo esc_html($full . (!empty($repo['private']) ? ' · private' : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="repo_input" class="regular-text code" placeholder="Or enter owner/repository (created automatically if missing)">
                        </div>
                        <div class="wpaib-actions"><?php submit_button('Use repository', 'primary', 'submit', false); ?></div>
                    </form>
                </div>

                <?php if (!empty($sync['connected'])) : ?>
                    <div class="wpaib-card">
                        <div class="wpaib-project">
                            <div><h2 style="margin-bottom:5px">Project</h2><code><?php echo esc_html((string) $sync['repo']); ?></code></div>
                            <span class="wpaib-ok">Auto update: ON · ~5 min</span>
                        </div>
                        <?php if (!empty($sync['last_error'])) : ?><p class="wpaib-warn"><?php echo esc_html((string) $sync['last_error']); ?></p><?php endif; ?>
                        <div class="wpaib-actions">
                            <button type="button" class="button button-primary" id="wpaib-push-now">Push code</button>
                            <button type="button" class="button" id="wpaib-pull-now">Update website now</button>
                            <span class="wpaib-muted" id="wpaib-sync-text"></span>
                        </div>
                        <div class="wpaib-progress" id="wpaib-progress"><progress id="wpaib-progress-bar" max="100" value="0"></progress></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <script>
        (() => {
            const ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const post = async (data) => {
                const body = new URLSearchParams(data);
                const response = await fetch(ajax, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body});
                const json = await response.json();
                if (!json.success) throw new Error(json?.data?.message || 'Request failed.');
                return json.data;
            };

            const connect = document.getElementById('wpaib-connect-github');
            if (connect) {
                const status = document.getElementById('wpaib-oauth-status');
                const box = document.getElementById('wpaib-connect-box');
                const code = document.getElementById('wpaib-user-code');
                const link = document.getElementById('wpaib-verification-link');
                connect.addEventListener('click', async () => {
                    connect.disabled = true;
                    status.textContent = 'Starting…';
                    try {
                        const start = await post({action:'wpaib_oauth_start', nonce:<?php echo wp_json_encode($oauth_nonce); ?>});
                        code.textContent = start.user_code;
                        link.href = start.verification_uri || 'https://github.com/login/device';
                        box.style.display = 'block';
                        status.textContent = 'Waiting for GitHub…';
                        try { await navigator.clipboard.writeText(start.user_code); } catch (e) {}
                        window.open(link.href, '_blank', 'noopener,noreferrer');
                        let interval = Math.max(5, Number(start.interval || 5));
                        const poll = async () => {
                            try {
                                const result = await post({action:'wpaib_oauth_poll', nonce:<?php echo wp_json_encode($oauth_nonce); ?>});
                                if (result.connected) { status.textContent = 'Connected'; window.location.reload(); return; }
                                interval = Math.max(interval, Number(result.interval || interval));
                                setTimeout(poll, interval * 1000);
                            } catch (error) { status.textContent = error.message; connect.disabled = false; }
                        };
                        setTimeout(poll, interval * 1000);
                    } catch (error) { status.textContent = error.message; connect.disabled = false; }
                });
            }

            const push = document.getElementById('wpaib-push-now');
            const pull = document.getElementById('wpaib-pull-now');
            const text = document.getElementById('wpaib-sync-text');
            const progress = document.getElementById('wpaib-progress');
            const bar = document.getElementById('wpaib-progress-bar');
            const syncNonce = <?php echo wp_json_encode($sync_nonce); ?>;

            if (push) push.addEventListener('click', async () => {
                push.disabled = true; if (pull) pull.disabled = true; progress.style.display = 'block'; bar.value = 0; text.textContent = 'Scanning…';
                try {
                    const start = await post({action:'wpaib_sync_start', nonce:syncNonce});
                    let result = start;
                    while (!result.complete) {
                        result = await post({action:'wpaib_sync_step', nonce:syncNonce, sync_id:start.sync_id});
                        bar.value = Math.round((result.done / Math.max(1, result.total)) * 100);
                        text.textContent = `${result.done}/${result.total}`;
                    }
                    bar.value = 100; text.textContent = 'Pushed to GitHub.';
                    setTimeout(() => window.location.reload(), 700);
                } catch (error) { text.textContent = error.message; text.classList.add('wpaib-warn'); push.disabled = false; if (pull) pull.disabled = false; }
            });

            if (pull) pull.addEventListener('click', async () => {
                pull.disabled = true; if (push) push.disabled = true; text.textContent = 'Checking GitHub…';
                try {
                    const result = await post({action:'wpaib_pull_now', nonce:syncNonce});
                    text.textContent = result.changed ? 'Website updated from GitHub.' : 'Website is already up to date.';
                    setTimeout(() => window.location.reload(), 800);
                } catch (error) { text.textContent = error.message; text.classList.add('wpaib-warn'); pull.disabled = false; if (push) push.disabled = false; }
            });
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
