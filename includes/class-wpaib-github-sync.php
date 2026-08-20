<?php

defined('ABSPATH') || exit;

final class WPAIB_GitHub_Sync {
    private const OPT_REPO = 'wpaib_github_repo';
    private const OPT_BRANCH = 'wpaib_github_branch';
    private const OPT_TOKEN = 'wpaib_github_token';
    private const OPT_LAST_REMOTE = 'wpaib_github_last_remote_sha';
    private const OPT_LAST_SYNC = 'wpaib_github_last_sync_at';
    private const OPT_LAST_ERROR = 'wpaib_github_last_error';
    private const QUEUE_PREFIX = 'wpaib_sync_queue_';
    private const MAX_FILE_BYTES = 5242880;
    private const BATCH_SIZE = 8;

    public static function boot(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action('wpaib_github_poll', [self::class, 'poll_remote']);
        add_action('wp_ajax_wpaib_sync_start', [self::class, 'ajax_sync_start']);
        add_action('wp_ajax_wpaib_sync_step', [self::class, 'ajax_sync_step']);

        if (!wp_next_scheduled('wpaib_github_poll')) {
            wp_schedule_event(time() + 120, 'wpaib_five_minutes', 'wpaib_github_poll');
        }
    }

    public static function cron_schedules(array $schedules): array {
        $schedules['wpaib_five_minutes'] = [
            'interval' => 300,
            'display' => 'Every 5 minutes (WP AI Bridge)',
        ];
        return $schedules;
    }

    public static function repo(): string {
        return trim((string) get_option(self::OPT_REPO, ''));
    }

    public static function branch(): string {
        $branch = trim((string) get_option(self::OPT_BRANCH, 'main'));
        return '' !== $branch ? $branch : 'main';
    }

    public static function connected(): bool {
        return '' !== self::repo() && '' !== self::token();
    }

    public static function last_remote_sha(): string {
        return trim((string) get_option(self::OPT_LAST_REMOTE, ''));
    }

    public static function last_sync_at(): string {
        return trim((string) get_option(self::OPT_LAST_SYNC, ''));
    }

    public static function last_error(): string {
        return trim((string) get_option(self::OPT_LAST_ERROR, ''));
    }

    public static function save_connection(string $repo, string $branch, string $token = ''): bool|WP_Error {
        $repo = trim($repo);
        $branch = trim($branch);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return new WP_Error('wpaib_repo_invalid', 'Repository must use owner/repository format.');
        }
        if ('' === $branch || !preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
            return new WP_Error('wpaib_branch_invalid', 'Invalid branch name.');
        }
        if ('' !== $token) {
            $encrypted = self::encrypt_token($token);
            if (is_wp_error($encrypted)) return $encrypted;
            update_option(self::OPT_TOKEN, $encrypted, false);
        } elseif ('' === self::token()) {
            return new WP_Error('wpaib_token_missing', 'A GitHub token is required for the first connection.');
        }

        update_option(self::OPT_REPO, $repo, false);
        update_option(self::OPT_BRANCH, $branch, false);

        $check = self::api('GET', '/repos/' . $repo);
        if (is_wp_error($check)) return $check;

        update_option(self::OPT_LAST_ERROR, '', false);
        WPAIB_Audit::record('github_connected', ['repo' => $repo, 'branch' => $branch]);
        return true;
    }

    public static function status(): array {
        $status = [
            'connected' => self::connected(),
            'repo' => self::repo(),
            'branch' => self::branch(),
            'last_remote_sha' => self::last_remote_sha(),
            'last_sync_at' => self::last_sync_at(),
            'last_error' => self::last_error(),
        ];
        if (!$status['connected']) return $status;

        $ref = self::remote_ref();
        if (!is_wp_error($ref)) {
            $status['remote_sha'] = $ref['sha'];
            $status['ahead'] = $ref['sha'] !== $status['last_remote_sha'];
        }
        return $status;
    }

    public static function ajax_sync_start(): void {
        self::require_ajax_admin();
        $prepared = self::prepare_snapshot();
        if (is_wp_error($prepared)) {
            wp_send_json_error(['message' => $prepared->get_error_message()], 400);
        }
        wp_send_json_success($prepared);
    }

    public static function ajax_sync_step(): void {
        self::require_ajax_admin();
        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field(wp_unslash($_POST['sync_id'])) : '';
        if ('' === $sync_id) wp_send_json_error(['message' => 'Missing sync id.'], 400);
        $result = self::process_snapshot_batch($sync_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success($result);
    }

    public static function poll_remote(): void {
        if (!self::connected()) return;
        $ref = self::remote_ref();
        if (is_wp_error($ref)) {
            self::remember_error($ref->get_error_message());
            return;
        }

        $remote = $ref['sha'];
        $last = self::last_remote_sha();
        if ('' === $last) {
            update_option(self::OPT_LAST_REMOTE, $remote, false);
            return;
        }
        if (hash_equals($last, $remote)) return;

        $deployed = self::deploy_range($last, $remote);
        if (is_wp_error($deployed)) {
            self::remember_error($deployed->get_error_message());
            return;
        }

        update_option(self::OPT_LAST_REMOTE, $remote, false);
        update_option(self::OPT_LAST_SYNC, gmdate('c'), false);
        update_option(self::OPT_LAST_ERROR, '', false);
        WPAIB_Audit::record('github_deployed', ['repo' => self::repo(), 'sha' => substr($remote, 0, 12), 'files' => $deployed]);
    }

    private static function prepare_snapshot(): array|WP_Error {
        if (!self::connected()) return new WP_Error('wpaib_not_connected', 'Connect GitHub first.');

        $local = self::project_files();
        if (is_wp_error($local)) return $local;

        $ref = self::remote_ref();
        $base_commit = null;
        $base_tree = null;
        $remote_map = [];

        if (!is_wp_error($ref)) {
            $base_commit = $ref['sha'];
            $commit = self::api('GET', '/repos/' . self::repo() . '/git/commits/' . rawurlencode($base_commit));
            if (is_wp_error($commit)) return $commit;
            $base_tree = isset($commit['tree']['sha']) ? (string) $commit['tree']['sha'] : null;
            if ($base_tree) {
                $tree = self::api('GET', '/repos/' . self::repo() . '/git/trees/' . rawurlencode($base_tree) . '?recursive=1');
                if (is_wp_error($tree)) return $tree;
                foreach ((array) ($tree['tree'] ?? []) as $entry) {
                    if (($entry['type'] ?? '') !== 'blob') continue;
                    $path = (string) ($entry['path'] ?? '');
                    if (self::managed_remote_path($path)) $remote_map[$path] = (string) ($entry['sha'] ?? '');
                }
            }
        } elseif ('wpaib_github_404' !== $ref->get_error_code()) {
            return $ref;
        }

        $ops = [];
        foreach ($local as $path => $meta) {
            if (!isset($remote_map[$path]) || !hash_equals($remote_map[$path], $meta['git_sha'])) {
                $ops[] = ['op' => 'blob', 'path' => $path, 'local' => $meta['local']];
            }
        }
        foreach ($remote_map as $path => $sha) {
            if (!isset($local[$path])) $ops[] = ['op' => 'delete', 'path' => $path];
        }

        $manifest = self::manifest();
        $ops[] = ['op' => 'manifest', 'path' => '.wpaib/site.json', 'content' => wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"];

        $sync_id = wp_generate_uuid4();
        $state = [
            'id' => $sync_id,
            'base_commit' => $base_commit,
            'base_tree' => $base_tree,
            'ops' => $ops,
            'entries' => [],
            'cursor' => 0,
            'total' => count($ops),
        ];
        set_transient(self::QUEUE_PREFIX . $sync_id, $state, HOUR_IN_SECONDS);

        return ['sync_id' => $sync_id, 'total' => count($ops), 'done' => 0];
    }

    private static function process_snapshot_batch(string $sync_id): array|WP_Error {
        $key = self::QUEUE_PREFIX . $sync_id;
        $state = get_transient($key);
        if (!is_array($state)) return new WP_Error('wpaib_sync_expired', 'Sync session expired. Start again.');

        $processed = 0;
        while ($state['cursor'] < $state['total'] && $processed < self::BATCH_SIZE) {
            $op = $state['ops'][$state['cursor']];
            if ('delete' === $op['op']) {
                $state['entries'][] = ['path' => $op['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => null];
            } else {
                if ('manifest' === $op['op']) {
                    $content = (string) $op['content'];
                } else {
                    $content = file_get_contents($op['local']);
                    if (false === $content) return new WP_Error('wpaib_local_read', 'Unable to read ' . $op['path']);
                }
                $blob = self::api('POST', '/repos/' . self::repo() . '/git/blobs', [
                    'content' => base64_encode($content),
                    'encoding' => 'base64',
                ]);
                if (is_wp_error($blob)) return $blob;
                $state['entries'][] = ['path' => $op['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => (string) ($blob['sha'] ?? '')];
            }
            $state['cursor']++;
            $processed++;
        }

        if ($state['cursor'] < $state['total']) {
            set_transient($key, $state, HOUR_IN_SECONDS);
            return ['sync_id' => $sync_id, 'total' => $state['total'], 'done' => $state['cursor'], 'complete' => false];
        }

        $tree_body = ['tree' => $state['entries']];
        if (!empty($state['base_tree'])) $tree_body['base_tree'] = $state['base_tree'];
        $tree = self::api('POST', '/repos/' . self::repo() . '/git/trees', $tree_body);
        if (is_wp_error($tree)) return $tree;

        $commit_body = [
            'message' => 'sync: ' . home_url('/') . ' theme/plugin snapshot',
            'tree' => (string) ($tree['sha'] ?? ''),
        ];
        if (!empty($state['base_commit'])) $commit_body['parents'] = [$state['base_commit']];
        $commit = self::api('POST', '/repos/' . self::repo() . '/git/commits', $commit_body);
        if (is_wp_error($commit)) return $commit;
        $commit_sha = (string) ($commit['sha'] ?? '');

        if (!empty($state['base_commit'])) {
            $updated = self::api('PATCH', '/repos/' . self::repo() . '/git/refs/heads/' . self::encode_ref(self::branch()), ['sha' => $commit_sha, 'force' => false]);
        } else {
            $updated = self::api('POST', '/repos/' . self::repo() . '/git/refs', ['ref' => 'refs/heads/' . self::branch(), 'sha' => $commit_sha]);
        }
        if (is_wp_error($updated)) return $updated;

        delete_transient($key);
        update_option(self::OPT_LAST_REMOTE, $commit_sha, false);
        update_option(self::OPT_LAST_SYNC, gmdate('c'), false);
        update_option(self::OPT_LAST_ERROR, '', false);
        WPAIB_Audit::record('github_snapshot_pushed', ['repo' => self::repo(), 'files' => $state['total'], 'sha' => substr($commit_sha, 0, 12)]);

        return ['sync_id' => $sync_id, 'total' => $state['total'], 'done' => $state['total'], 'complete' => true, 'sha' => $commit_sha];
    }

    private static function deploy_range(string $from, string $to): int|WP_Error {
        $compare = self::api('GET', '/repos/' . self::repo() . '/compare/' . rawurlencode($from) . '...' . rawurlencode($to));
        if (is_wp_error($compare)) return $compare;
        $files = (array) ($compare['files'] ?? []);
        $count = 0;

        foreach ($files as $file) {
            $path = (string) ($file['filename'] ?? '');
            if (!self::managed_remote_path($path)) continue;
            $status = (string) ($file['status'] ?? 'modified');

            if ('renamed' === $status && !empty($file['previous_filename']) && self::managed_remote_path((string) $file['previous_filename'])) {
                $old = self::remote_to_local_relative((string) $file['previous_filename']);
                $deleted = WPAIB_Maintenance::delete_file($old);
                if (is_wp_error($deleted) && 'wpaib_write_file_missing' !== $deleted->get_error_code()) return $deleted;
            }

            if ('removed' === $status) {
                $deleted = WPAIB_Maintenance::delete_file(self::remote_to_local_relative($path));
                if (is_wp_error($deleted) && 'wpaib_write_file_missing' !== $deleted->get_error_code()) return $deleted;
                $count++;
                continue;
            }

            $contents = self::api('GET', '/repos/' . self::repo() . '/contents/' . self::encode_path($path) . '?ref=' . rawurlencode($to));
            if (is_wp_error($contents)) return $contents;
            if ('base64' !== ($contents['encoding'] ?? '') || empty($contents['content'])) {
                return new WP_Error('wpaib_github_content', 'GitHub did not return file content for ' . $path);
            }
            $content = base64_decode(str_replace(["\r", "\n"], '', (string) $contents['content']), true);
            if (false === $content) return new WP_Error('wpaib_github_decode', 'Unable to decode ' . $path);

            $written = WPAIB_Maintenance::write_file(self::remote_to_local_relative($path), $content);
            if (is_wp_error($written)) return $written;
            $count++;
        }

        return $count;
    }

    private static function project_files(): array|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $targets = [];
        $stylesheet = get_stylesheet();
        $targets[] = ['remote' => 'themes/' . $stylesheet, 'local' => get_stylesheet_directory()];
        $template = get_template();
        if ($template !== $stylesheet) $targets[] = ['remote' => 'themes/' . $template, 'local' => get_template_directory()];

        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) $active = array_unique(array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', []))));
        $self = plugin_basename(WPAIB_FILE);
        foreach ($active as $plugin_file) {
            if ($plugin_file === $self || str_starts_with($plugin_file, dirname($self) . '/')) continue;
            $parts = explode('/', $plugin_file, 2);
            if (2 === count($parts)) {
                $targets[] = ['remote' => 'plugins/' . $parts[0], 'local' => WP_PLUGIN_DIR . '/' . $parts[0]];
            } else {
                $targets[] = ['remote' => 'plugins/' . $plugin_file, 'local' => WP_PLUGIN_DIR . '/' . $plugin_file];
            }
        }

        $files = [];
        foreach ($targets as $target) {
            if (is_file($target['local'])) {
                $added = self::add_local_file($files, $target['remote'], $target['local']);
                if (is_wp_error($added)) return $added;
                continue;
            }
            $root = realpath($target['local']);
            if (false === $root) continue;
            $root = wp_normalize_path($root);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) continue;
                $path = wp_normalize_path($entry->getPathname());
                if (self::skip_local_path($path)) continue;
                $relative = ltrim(substr($path, strlen($root)), '/');
                $remote = trailingslashit($target['remote']) . $relative;
                $added = self::add_local_file($files, $remote, $path);
                if (is_wp_error($added)) return $added;
            }
        }
        return $files;
    }

    private static function add_local_file(array &$files, string $remote, string $local): bool|WP_Error {
        $size = filesize($local);
        if (false === $size || $size > self::MAX_FILE_BYTES) return true;
        $content = file_get_contents($local);
        if (false === $content) return new WP_Error('wpaib_read_failed', 'Unable to read ' . $local);
        $files[$remote] = [
            'local' => $local,
            'git_sha' => sha1('blob ' . strlen($content) . "\0" . $content),
        ];
        return true;
    }

    private static function manifest(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $active = (array) get_option('active_plugins', []);
        return [
            'site_url' => home_url('/'),
            'generated_at' => gmdate('c'),
            'wp_ai_bridge_version' => WPAIB_VERSION,
            'sync_scope' => ['active_theme', 'parent_theme_if_any', 'active_plugins'],
            'excluded' => ['uploads', 'wordpress-core', 'wp-config.php', 'wp-ai-bridge'],
            'theme' => ['stylesheet' => get_stylesheet(), 'template' => get_template(), 'version' => wp_get_theme()->get('Version')],
            'active_plugins' => array_values($active),
        ];
    }

    private static function managed_remote_path(string $path): bool {
        if (!str_starts_with($path, 'themes/') && !str_starts_with($path, 'plugins/')) return false;
        return !str_starts_with($path, 'plugins/wp-ai-bridge/');
    }

    private static function remote_to_local_relative(string $path): string {
        return $path;
    }

    private static function skip_local_path(string $path): bool {
        $normalized = '/' . trim(wp_normalize_path($path), '/') . '/';
        foreach (['/.git/', '/node_modules/', '/wp-ai-bridge-backups/', '/cache/', '/caches/'] as $segment) {
            if (str_contains($normalized, $segment)) return true;
        }
        $name = strtolower(basename($path));
        if (in_array($name, ['.env', '.ds_store', '.htaccess', '.user.ini', 'php.ini', 'debug.log'], true)) return true;
        return (bool) preg_match('/\.(log|sql|zip|tar|gz|7z|pem|key|p12|pfx)$/i', $name);
    }

    private static function remote_ref(): array|WP_Error {
        $result = self::api('GET', '/repos/' . self::repo() . '/git/ref/heads/' . self::encode_ref(self::branch()), null, [404]);
        if (is_wp_error($result)) return $result;
        if (isset($result['_http_status']) && 404 === $result['_http_status']) return new WP_Error('wpaib_github_404', 'Branch does not exist yet.');
        $sha = (string) ($result['object']['sha'] ?? '');
        if ('' === $sha) return new WP_Error('wpaib_github_ref', 'Unable to resolve GitHub branch.');
        return ['sha' => $sha];
    }

    private static function api(string $method, string $endpoint, ?array $body = null, array $allow_status = []): array|WP_Error {
        $token = self::token();
        if ('' === $token) return new WP_Error('wpaib_github_token', 'GitHub token is not configured.');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'WP-AI-Bridge/' . WPAIB_VERSION,
            ],
        ];
        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request('https://api.github.com' . $endpoint, $args);
        if (is_wp_error($response)) return $response;
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = '' !== $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        if ($status >= 200 && $status < 300) return $data;
        if (in_array($status, $allow_status, true)) {
            $data['_http_status'] = $status;
            return $data;
        }
        $message = isset($data['message']) ? (string) $data['message'] : 'GitHub API request failed.';
        return new WP_Error('wpaib_github_http_' . $status, $message, ['status' => $status]);
    }

    private static function encrypt_token(string $token): string|WP_Error {
        $key = hash('sha256', wp_salt('auth'), true);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'sodium:' . base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, $key));
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (false === $cipher) return new WP_Error('wpaib_encrypt', 'Unable to encrypt GitHub token.');
            return 'openssl:' . base64_encode($iv . $tag . $cipher);
        }
        return new WP_Error('wpaib_encrypt_unavailable', 'Server requires sodium or OpenSSL to store the GitHub token safely.');
    }

    private static function token(): string {
        $stored = (string) get_option(self::OPT_TOKEN, '');
        if ('' === $stored) return '';
        $key = hash('sha256', wp_salt('auth'), true);
        if (str_starts_with($stored, 'sodium:') && function_exists('sodium_crypto_secretbox_open')) {
            $decoded = base64_decode(substr($stored, 7), true);
            if (false === $decoded || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return false === $plain ? '' : $plain;
        }
        if (str_starts_with($stored, 'openssl:') && function_exists('openssl_decrypt')) {
            $decoded = base64_decode(substr($stored, 8), true);
            if (false === $decoded || strlen($decoded) <= 28) return '';
            $iv = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $cipher = substr($decoded, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return false === $plain ? '' : $plain;
        }
        return '';
    }

    private static function encode_path(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function encode_ref(string $ref): string {
        return implode('/', array_map('rawurlencode', explode('/', $ref)));
    }

    private static function remember_error(string $message): void {
        update_option(self::OPT_LAST_ERROR, sanitize_text_field($message), false);
        WPAIB_Audit::record('github_sync_error', ['message' => $message]);
    }

    private static function require_ajax_admin(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.'], 403);
        check_ajax_referer('wpaib_github_sync', 'nonce');
    }
}
