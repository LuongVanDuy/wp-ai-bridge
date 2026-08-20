<?php

defined('ABSPATH') || exit;

/**
 * Transparently initializes a GitHub repository that has no commits yet.
 *
 * GitHub's Git refs API returns HTTP 409 "Git Repository is empty." for a
 * brand-new repository. The sync engine expects a branch/commit to exist
 * before it can build its normal Git tree. This filter creates one tiny
 * manifest commit, retries the original ref request, and then lets the normal
 * snapshot/deploy engine continue unchanged.
 */
final class WPAIB_Empty_Repo_Bootstrap {
    private static bool $busy = false;

    public static function boot(): void {
        add_filter('http_response', [self::class, 'maybe_bootstrap'], 10, 3);
    }

    public static function maybe_bootstrap($response, array $args, string $url) {
        if (self::$busy || is_wp_error($response)) return $response;
        if ('GET' !== strtoupper((string) ($args['method'] ?? 'GET'))) return $response;
        if (409 !== (int) wp_remote_retrieve_response_code($response)) return $response;

        if (!preg_match('#^https://api\.github\.com/repos/([^/]+)/([^/]+)/git/ref/heads/.+$#', $url, $matches)) {
            return $response;
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $data = '' !== $raw ? json_decode($raw, true) : [];
        $message = is_array($data) ? strtolower((string) ($data['message'] ?? '')) : '';
        if (!str_contains($message, 'repository is empty')) return $response;

        $authorization = self::authorization_header($args['headers'] ?? []);
        if ('' === $authorization) return $response;

        $owner = rawurldecode((string) $matches[1]);
        $repo = rawurldecode((string) $matches[2]);
        if ('' === $owner || '' === $repo) return $response;

        $manifest = wp_json_encode([
            'site_url' => home_url('/'),
            'generated_at' => gmdate('c'),
            'wp_ai_bridge_version' => defined('WPAIB_VERSION') ? WPAIB_VERSION : '',
            'bootstrap' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($manifest)) return $response;
        $manifest .= "\n";

        $headers = [
            'Authorization' => $authorization,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'WP-AI-Bridge/' . (defined('WPAIB_VERSION') ? WPAIB_VERSION : 'unknown'),
            'Content-Type' => 'application/json',
        ];

        self::$busy = true;
        try {
            $bootstrap_url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/.wpaib/site.json';
            $bootstrap = wp_remote_request($bootstrap_url, [
                'method' => 'PUT',
                'timeout' => 30,
                'headers' => $headers,
                'body' => wp_json_encode([
                    'message' => 'chore: initialize WP AI Bridge repository',
                    'content' => base64_encode($manifest),
                ]),
            ]);

            if (is_wp_error($bootstrap)) return $response;
            $status = (int) wp_remote_retrieve_response_code($bootstrap);
            if ($status < 200 || $status >= 300) return $response;

            if (class_exists('WPAIB_Audit')) {
                WPAIB_Audit::record('github_empty_repo_bootstrapped', ['repo' => $owner . '/' . $repo]);
            }

            $retry = wp_remote_request($url, $args);
            return is_wp_error($retry) ? $response : $retry;
        } finally {
            self::$busy = false;
        }
    }

    private static function authorization_header($headers): string {
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        if (!is_array($headers)) return '';

        foreach ($headers as $name => $value) {
            if ('authorization' !== strtolower((string) $name)) continue;
            if (is_array($value)) $value = reset($value);
            return trim((string) $value);
        }
        return '';
    }
}
