<?php

defined('ABSPATH') || exit;

final class WPAIB_Audit {
    private const LOG_OPTION = 'wpaib_audit_log';
    private const MAX_ENTRIES = 200;

    public static function boot(): void {}

    public static function record(string $action, array $context = []): void {
        $entries = get_option(self::LOG_OPTION, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        if (class_exists('WPAIB_Auth') && !isset($context['auth_method'])) {
            $context['auth_method'] = WPAIB_Auth::method();
        }

        $entries[] = [
            'time' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'context' => self::sanitize_context($context),
        ];

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        update_option(self::LOG_OPTION, $entries, false);
    }

    public static function recent(int $limit = 50): array {
        $entries = get_option(self::LOG_OPTION, []);
        if (!is_array($entries)) {
            return [];
        }
        return array_reverse(array_slice($entries, -max(1, min($limit, 100))));
    }

    private static function sanitize_context(array $context): array {
        $redacted = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);
            if (preg_match('/pass|secret|token|key|credential/i', $key)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || null === $value) {
                $redacted[$key] = is_string($value) ? sanitize_text_field($value) : $value;
            }
        }
        return $redacted;
    }
}
