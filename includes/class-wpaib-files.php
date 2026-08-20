<?php

defined('ABSPATH') || exit;

final class WPAIB_Files {
    private const MAX_READ_BYTES = 262144;

    public static function read(string $relative_path): array|WP_Error {
        $resolved = self::resolve_allowed_path($relative_path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            return new WP_Error('wpaib_file_unreadable', 'File is not readable.', ['status' => 404]);
        }

        $size = filesize($resolved);
        if (false === $size || $size > self::MAX_READ_BYTES) {
            return new WP_Error('wpaib_file_too_large', 'File exceeds the maximum readable size.', ['status' => 413]);
        }

        $content = file_get_contents($resolved);
        if (false === $content) {
            return new WP_Error('wpaib_file_read_failed', 'Unable to read file.', ['status' => 500]);
        }

        WPAIB_Audit::record('read_file', ['path' => $relative_path, 'bytes' => strlen($content)]);

        return [
            'path' => $relative_path,
            'bytes' => strlen($content),
            'content' => self::redact_secrets($content),
        ];
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

    private static function resolve_allowed_path(string $relative_path): string|WP_Error {
        $relative_path = ltrim(wp_normalize_path($relative_path), '/');
        if ('' === $relative_path || str_contains($relative_path, "\0") || str_contains($relative_path, '..')) {
            return new WP_Error('wpaib_invalid_path', 'Invalid path.', ['status' => 400]);
        }

        foreach (self::allowed_roots() as $label => $root) {
            $root = untrailingslashit(wp_normalize_path($root));
            $prefix = $label . '/';
            if (!str_starts_with($relative_path, $prefix)) {
                continue;
            }

            $candidate = $root . '/' . substr($relative_path, strlen($prefix));
            $real = realpath($candidate);
            if (false === $real) {
                return new WP_Error('wpaib_file_not_found', 'File does not exist.', ['status' => 404]);
            }

            $real = wp_normalize_path($real);
            if (!str_starts_with($real, $root . '/')) {
                return new WP_Error('wpaib_path_escape', 'Path is outside the allowed root.', ['status' => 403]);
            }

            if (self::is_blocked($real)) {
                return new WP_Error('wpaib_sensitive_file', 'Sensitive files cannot be read through the bridge.', ['status' => 403]);
            }

            return $real;
        }

        return new WP_Error('wpaib_root_not_allowed', 'Path must start with plugins/, themes/, or uploads/.', ['status' => 403]);
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
