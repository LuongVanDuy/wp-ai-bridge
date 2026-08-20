<?php

defined('ABSPATH') || exit;

final class WPAIB_Files {
    private const MAX_READ_BYTES = 1048576;
    private const MAX_SEARCH_FILE_BYTES = 524288;
    private const MAX_DIRECTORY_RESULTS = 500;

    public static function read(string $relative_path): array|WP_Error {
        $resolved = self::resolve_allowed_path($relative_path, false);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            return new WP_Error('wpaib_file_unreadable', 'File is not readable.', ['status' => 404]);
        }

        $size = filesize($resolved);
        if (false === $size || $size > self::MAX_READ_BYTES) {
            return new WP_Error('wpaib_file_too_large', 'File exceeds the 1 MiB readable size limit.', ['status' => 413]);
        }

        $content = file_get_contents($resolved);
        if (false === $content) {
            return new WP_Error('wpaib_file_read_failed', 'Unable to read file.', ['status' => 500]);
        }

        WPAIB_Audit::record('read_file', ['path' => $relative_path, 'bytes' => strlen($content)]);

        return [
            'path' => $relative_path,
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'content' => self::redact_secrets($content),
        ];
    }

    public static function list_directory(string $relative_path, bool $recursive = false, int $limit = 200): array|WP_Error {
        $resolved = self::resolve_allowed_path($relative_path, true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        if (!is_dir($resolved) || !is_readable($resolved)) {
            return new WP_Error('wpaib_directory_unreadable', 'Directory is not readable.', ['status' => 404]);
        }

        $limit = max(1, min($limit, self::MAX_DIRECTORY_RESULTS));
        $items = [];
        $root_prefix = self::relative_root_prefix($relative_path);

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new IteratorIterator(new DirectoryIterator($resolved));
        }

        foreach ($iterator as $entry) {
            if ($entry instanceof DirectoryIterator && $entry->isDot()) {
                continue;
            }

            $path = wp_normalize_path($entry->getPathname());
            if (self::is_backup_path($path) || self::is_blocked($path)) {
                continue;
            }

            $relative = ltrim(substr($path, strlen(untrailingslashit(wp_normalize_path($resolved)))), '/');
            $items[] = [
                'path' => trailingslashit($root_prefix) . $relative,
                'type' => $entry->isDir() ? 'directory' : 'file',
                'bytes' => $entry->isFile() ? $entry->getSize() : null,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        WPAIB_Audit::record('list_directory', ['path' => $relative_path, 'count' => count($items), 'recursive' => $recursive]);
        return ['path' => $relative_path, 'items' => $items, 'truncated' => count($items) >= $limit];
    }

    public static function search(string $query, string $root = 'plugins', int $limit = 50): array|WP_Error {
        $query = trim($query);
        if ('' === $query) {
            return new WP_Error('wpaib_search_empty', 'Search query is required.', ['status' => 400]);
        }
        if (!in_array($root, ['plugins', 'themes'], true)) {
            return new WP_Error('wpaib_search_root', 'Search root must be plugins or themes.', ['status' => 400]);
        }

        $resolved = self::resolve_allowed_path($root, true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $limit = max(1, min($limit, 100));
        $matches = [];
        $extensions = ['php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'sass', 'less', 'json', 'html', 'htm', 'txt', 'md', 'xml', 'yml', 'yaml'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile() || !$entry->isReadable()) {
                continue;
            }

            $path = wp_normalize_path($entry->getPathname());
            if (self::is_backup_path($path) || self::is_blocked($path)) {
                continue;
            }
            if ($entry->getSize() > self::MAX_SEARCH_FILE_BYTES) {
                continue;
            }

            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions, true)) {
                continue;
            }

            $content = file_get_contents($path);
            if (false === $content) {
                continue;
            }

            $offset = stripos($content, $query);
            if (false === $offset) {
                continue;
            }

            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $start = max(0, $offset - 160);
            $snippet = substr($content, $start, min(strlen($content) - $start, 420));
            $snippet = preg_replace('/\s+/', ' ', self::redact_secrets($snippet)) ?? '';

            $matches[] = [
                'path' => $root . '/' . ltrim(substr($path, strlen(untrailingslashit(wp_normalize_path($resolved)))), '/'),
                'line' => $line,
                'snippet' => trim($snippet),
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        WPAIB_Audit::record('search_files', ['root' => $root, 'query_length' => strlen($query), 'count' => count($matches)]);
        return ['query' => $query, 'root' => $root, 'matches' => $matches, 'truncated' => count($matches) >= $limit];
    }

    public static function allowed_roots(): array {
        $roots = [
            'plugins' => WP_PLUGIN_DIR,
            'themes' => get_theme_root(),
        ];

        $uploads = wp_get_upload_dir();
        if (empty($uploads['error']) && !empty($uploads['basedir'])) {
            $roots['uploads'] = $uploads['basedir'];
        }

        return $roots;
    }

    private static function resolve_allowed_path(string $relative_path, bool $allow_directory): string|WP_Error {
        $relative_path = trim(ltrim(wp_normalize_path($relative_path), '/'));
        if ('' === $relative_path || str_contains($relative_path, "\0") || str_contains($relative_path, '..')) {
            return new WP_Error('wpaib_invalid_path', 'Invalid path.', ['status' => 400]);
        }

        foreach (self::allowed_roots() as $label => $root) {
            $root_real = realpath($root);
            if (false === $root_real) {
                continue;
            }
            $root_real = untrailingslashit(wp_normalize_path($root_real));

            if ($relative_path === $label && $allow_directory) {
                return $root_real;
            }

            $prefix = $label . '/';
            if (!str_starts_with($relative_path, $prefix)) {
                continue;
            }

            $candidate = $root_real . '/' . substr($relative_path, strlen($prefix));
            $real = realpath($candidate);
            if (false === $real) {
                return new WP_Error('wpaib_file_not_found', 'Path does not exist.', ['status' => 404]);
            }

            $real = wp_normalize_path($real);
            if (!str_starts_with($real . '/', $root_real . '/')) {
                return new WP_Error('wpaib_path_escape', 'Path is outside the allowed root.', ['status' => 403]);
            }

            if (self::is_backup_path($real) || self::is_blocked($real)) {
                return new WP_Error('wpaib_sensitive_file', 'Sensitive or internal backup paths cannot be read through the bridge.', ['status' => 403]);
            }

            return $real;
        }

        return new WP_Error('wpaib_root_not_allowed', 'Path must start with plugins/, themes/, or uploads/.', ['status' => 403]);
    }

    private static function relative_root_prefix(string $relative_path): string {
        return trim($relative_path, '/');
    }

    private static function is_backup_path(string $path): bool {
        return str_contains('/' . trim(wp_normalize_path($path), '/') . '/', '/wp-ai-bridge-backups/');
    }

    private static function is_blocked(string $path): bool {
        $name = strtolower(basename($path));
        $blocked = ['wp-config.php', '.env', '.htaccess', '.user.ini', 'php.ini'];
        if (in_array($name, $blocked, true)) {
            return true;
        }

        return (bool) preg_match('/\.(pem|key|p12|pfx|sql|zip|tar|gz)$/i', $name);
    }

    private static function redact_secrets(string $content): string {
        $patterns = [
            '/(?i)(password|passwd|secret|api[_-]?key|access[_-]?token)(\s*[=:]\s*)["\']?[^"\'\s;]+/' => '$1$2[redacted]',
            '/(?i)Bearer\s+[A-Za-z0-9._\-]+/' => 'Bearer [redacted]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $content) ?? $content;
    }
}
