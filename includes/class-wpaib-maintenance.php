<?php

defined('ABSPATH') || exit;

final class WPAIB_Maintenance {
    private const MAX_WRITE_BYTES = 5242880;
    private const BACKUP_DIRNAME = 'wp-ai-bridge-backups';

    public static function write_file(string $relative_path, string $content): array|WP_Error {
        if (strlen($content) > self::MAX_WRITE_BYTES) {
            return new WP_Error('wpaib_write_too_large', 'File content exceeds the 5 MiB write limit.', ['status' => 413]);
        }
        $target = self::resolve_write_path($relative_path, true);
        if (is_wp_error($target)) return $target;
        $validation = self::validate_content($target, $content);
        if (is_wp_error($validation)) return $validation;

        $existed = file_exists($target);
        $backup_id = null;
        if ($existed) {
            if (!is_file($target) || !is_readable($target)) return new WP_Error('wpaib_write_target_invalid', 'Write target is not a readable regular file.', ['status' => 400]);
            $backup_id = self::backup_file($relative_path, $target);
            if (is_wp_error($backup_id)) return $backup_id;
        }

        $parent = dirname($target);
        if (!is_dir($parent) && !wp_mkdir_p($parent)) return new WP_Error('wpaib_parent_create_failed', 'Unable to create the destination directory.', ['status' => 500]);

        $tmp = $target . '.wpaib-' . str_replace('-', '', wp_generate_uuid4()) . '.tmp';
        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if (false === $bytes) return new WP_Error('wpaib_temp_write_failed', 'Unable to write temporary file.', ['status' => 500]);
        @chmod($tmp, 0644);
        $replaced = @rename($tmp, $target);
        if (!$replaced) {
            @unlink($tmp);
            $bytes = @file_put_contents($target, $content, LOCK_EX);
            if (false === $bytes) {
                if (is_string($backup_id)) self::restore_backup($backup_id);
                return new WP_Error('wpaib_write_failed', 'Unable to write file; previous version was preserved when possible.', ['status' => 500]);
            }
        }

        clearstatcache(true, $target);
        self::invalidate_php_cache($target);
        WPAIB_Audit::record('write_file', ['path' => $relative_path, 'bytes' => strlen($content), 'created' => !$existed, 'backup_id' => $backup_id]);
        return ['ok' => true, 'path' => $relative_path, 'bytes' => strlen($content), 'created' => !$existed, 'backup_id' => $backup_id, 'sha256' => hash('sha256', $content)];
    }

    public static function delete_file(string $relative_path): array|WP_Error {
        $target = self::resolve_write_path($relative_path, false);
        if (is_wp_error($target)) return $target;
        if (!is_file($target)) return new WP_Error('wpaib_delete_not_file', 'Only regular files can be deleted.', ['status' => 400]);
        $backup_id = self::backup_file($relative_path, $target);
        if (is_wp_error($backup_id)) return $backup_id;
        if (!@unlink($target)) return new WP_Error('wpaib_delete_failed', 'Unable to delete file.', ['status' => 500]);
        self::invalidate_php_cache($target);
        WPAIB_Audit::record('delete_file', ['path' => $relative_path, 'backup_id' => $backup_id]);
        return ['ok' => true, 'path' => $relative_path, 'backup_id' => $backup_id];
    }

    public static function restore_backup(string $backup_id): array|WP_Error {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $backup_id)) return new WP_Error('wpaib_invalid_backup_id', 'Invalid backup id.', ['status' => 400]);
        $dir = self::backup_root() . '/' . $backup_id;
        $meta_file = $dir . '/meta.json';
        $data_file = $dir . '/file.bak';
        if (!is_readable($meta_file) || !is_readable($data_file)) return new WP_Error('wpaib_backup_missing', 'Backup does not exist.', ['status' => 404]);
        $meta = json_decode((string) file_get_contents($meta_file), true);
        if (!is_array($meta) || empty($meta['path']) || !is_string($meta['path'])) return new WP_Error('wpaib_backup_invalid', 'Backup metadata is invalid.', ['status' => 500]);
        $content = file_get_contents($data_file);
        if (false === $content) return new WP_Error('wpaib_backup_read_failed', 'Unable to read backup.', ['status' => 500]);
        $target = self::resolve_write_path($meta['path'], true);
        if (is_wp_error($target)) return $target;
        $validation = self::validate_content($target, $content);
        if (is_wp_error($validation)) return $validation;
        $parent = dirname($target);
        if (!is_dir($parent) && !wp_mkdir_p($parent)) return new WP_Error('wpaib_restore_parent_failed', 'Unable to create restore directory.', ['status' => 500]);
        if (false === @file_put_contents($target, $content, LOCK_EX)) return new WP_Error('wpaib_restore_failed', 'Unable to restore backup.', ['status' => 500]);
        self::invalidate_php_cache($target);
        WPAIB_Audit::record('restore_backup', ['path' => $meta['path'], 'backup_id' => $backup_id]);
        return ['ok' => true, 'path' => $meta['path'], 'backup_id' => $backup_id, 'sha256' => hash('sha256', $content)];
    }

    private static function backup_file(string $relative_path, string $target): string|WP_Error {
        $root = self::backup_root();
        if (!is_dir($root) && !wp_mkdir_p($root)) return new WP_Error('wpaib_backup_dir_failed', 'Unable to create backup directory.', ['status' => 500]);
        self::protect_backup_root($root);
        $backup_id = gmdate('YmdHis') . '-' . substr(hash('sha256', $relative_path . microtime(true) . wp_rand()), 0, 16);
        $dir = $root . '/' . $backup_id;
        if (!wp_mkdir_p($dir)) return new WP_Error('wpaib_backup_create_failed', 'Unable to create backup.', ['status' => 500]);
        if (!@copy($target, $dir . '/file.bak')) return new WP_Error('wpaib_backup_copy_failed', 'Unable to copy file into backup.', ['status' => 500]);
        $meta = ['path' => $relative_path, 'created_at' => gmdate('c'), 'sha256' => hash_file('sha256', $target)];
        if (false === @file_put_contents($dir . '/meta.json', wp_json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) return new WP_Error('wpaib_backup_meta_failed', 'Unable to write backup metadata.', ['status' => 500]);
        return $backup_id;
    }

    private static function backup_root(): string {
        $uploads = wp_get_upload_dir();
        $base = empty($uploads['error']) && !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
        return untrailingslashit(wp_normalize_path($base)) . '/' . self::BACKUP_DIRNAME;
    }

    private static function protect_backup_root(string $root): void {
        $htaccess = $root . '/.htaccess';
        if (!file_exists($htaccess)) @file_put_contents($htaccess, "Deny from all\n", LOCK_EX);
        $index = $root . '/index.php';
        if (!file_exists($index)) @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX);
    }

    private static function resolve_write_path(string $relative_path, bool $allow_missing): string|WP_Error {
        $relative_path = ltrim(wp_normalize_path($relative_path), '/');
        if ('' === $relative_path || str_contains($relative_path, "\0") || str_contains($relative_path, '..')) return new WP_Error('wpaib_invalid_write_path', 'Invalid write path.', ['status' => 400]);
        $roots = ['plugins' => WP_PLUGIN_DIR, 'themes' => get_theme_root()];
        foreach ($roots as $label => $root) {
            $prefix = $label . '/';
            if (!str_starts_with($relative_path, $prefix)) continue;
            $root_real = realpath($root);
            if (false === $root_real) return new WP_Error('wpaib_write_root_missing', 'Allowed write root does not exist.', ['status' => 500]);
            $root_real = untrailingslashit(wp_normalize_path($root_real));
            $suffix = substr($relative_path, strlen($prefix));
            if ('' === $suffix) return new WP_Error('wpaib_write_root_target', 'A file path inside the allowed root is required.', ['status' => 400]);
            $candidate = wp_normalize_path($root_real . '/' . $suffix);
            if (self::is_blocked_write($candidate)) return new WP_Error('wpaib_write_blocked_file', 'This file type/name is blocked from remote modification.', ['status' => 403]);
            if (file_exists($candidate)) {
                $real = realpath($candidate);
                if (false === $real) return new WP_Error('wpaib_write_resolve_failed', 'Unable to resolve write target.', ['status' => 400]);
                $real = wp_normalize_path($real);
                if (!str_starts_with($real, $root_real . '/')) return new WP_Error('wpaib_write_path_escape', 'Write target escapes the allowed theme/plugin root.', ['status' => 403]);
                return $real;
            }
            if (!$allow_missing) return new WP_Error('wpaib_write_file_missing', 'File does not exist.', ['status' => 404]);
            $ancestor = dirname($candidate);
            while (!file_exists($ancestor) && $ancestor !== dirname($ancestor)) $ancestor = dirname($ancestor);
            $ancestor_real = realpath($ancestor);
            if (false === $ancestor_real || !str_starts_with(wp_normalize_path($ancestor_real) . '/', $root_real . '/')) return new WP_Error('wpaib_write_parent_escape', 'Destination parent escapes the allowed theme/plugin root.', ['status' => 403]);
            return $candidate;
        }
        return new WP_Error('wpaib_write_root_not_allowed', 'Writes are allowed only under plugins/ or themes/.', ['status' => 403]);
    }

    private static function is_blocked_write(string $path): bool {
        $name = strtolower(basename($path));
        if (in_array($name, ['.env', '.htaccess', '.user.ini', 'php.ini'], true)) return true;
        return (bool) preg_match('/\.(pem|key|p12|pfx|sql|zip|tar|gz)$/i', $name);
    }

    private static function validate_content(string $target, string $content): bool|WP_Error {
        if ('php' !== strtolower((string) pathinfo($target, PATHINFO_EXTENSION))) return true;
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (ParseError $error) {
            return new WP_Error('wpaib_php_syntax_error', 'PHP syntax validation failed: ' . $error->getMessage(), ['status' => 422]);
        }
        return true;
    }

    private static function invalidate_php_cache(string $path): void {
        if ('php' === strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) && function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
