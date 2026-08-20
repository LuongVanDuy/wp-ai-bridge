<?php

defined('ABSPATH') || exit;

/**
 * Initializes a completely empty GitHub repository before the normal Git
 * database sync runs.
 *
 * GitHub returns HTTP 409 "Git Repository is empty." for Git ref requests on
 * repositories with no branches. GitHub's documented bootstrap path is the
 * Contents API. After creating the bootstrap commit, this filter returns a
 * synthetic successful ref response containing that commit SHA so the normal
 * sync engine can immediately continue from it without waiting for ref
 * propagation.
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

        if (!preg_match('#^https://api\\.github\\.com/repos/([^/]+)/([^/]+)/git/ref/heads/(.+)$#', $url, $matches)) {
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
        $branch = rawurldecode((string) $matches[3]);
        if ('' === $owner || '' === $repo || '' === $branch) return $response;

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

            if (is_wp_error($bootstrap)) return $bootstrap;

            $status = (int) wp_remote_retrieve_response_code($bootstrap);
            $bootstrap_raw = (string) wp_remote_retrieve_body($bootstrap);
            $bootstrap_data = '' !== $bootstrap_raw ? json_decode($bootstrap_raw, true) : [];
            if (!is_array($bootstrap_data)) $bootstrap_data = [];

            if ($status < 200 || $status >= 300) {
                $github_message = (string) ($bootstrap_data['message'] ?? '');
                if (403 === $status && str_contains(strtolower($github_message), 'resource not accessible by integration')) {
                    $accepted = trim((string) wp_remote_retrieve_header($bootstrap, 'x-accepted-github-permissions'));
                    $hint = 'GitHub App cannot write to this repository. In GitHub App settings set Repository permissions > Contents to Read and write, install/configure the App for this repository (or All repositories), approve the updated permissions, then Disconnect and Connect GitHub again in WP AI Bridge.';
                    if ('' !== $accepted) $hint .= ' GitHub requires: ' . $accepted . '.';
                    $bootstrap['body'] = wp_json_encode(['message' => $hint]);
                }
                return $bootstrap;
            }

            $commit_sha = trim((string) ($bootstrap_data['commit']['sha'] ?? ''));
            if (!preg_match('/^[a-f0-9]{40}$/i', $commit_sha)) {
                return new WP_Error('wpaib_empty_repo_commit', 'GitHub initialized the repository but did not return the bootstrap commit SHA.');
            }

            if (class_exists('WPAIB_Audit')) {
                WPAIB_Audit::record('github_empty_repo_bootstrapped', [
                    'repo' => $owner . '/' . $repo,
                    'branch' => $branch,
                    'sha' => substr($commit_sha, 0, 12),
                ]);
            }

            $synthetic = $response;
            if (!isset($synthetic['response']) || !is_array($synthetic['response'])) {
                $synthetic['response'] = [];
            }
            $synthetic['response']['code'] = 200;
            $synthetic['response']['message'] = 'OK';
            $synthetic['body'] = wp_json_encode([
                'ref' => 'refs/heads/' . $branch,
                'object' => [
                    'type' => 'commit',
                    'sha' => $commit_sha,
                    'url' => 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/git/commits/' . $commit_sha,
                ],
            ]);

            return $synthetic;
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
