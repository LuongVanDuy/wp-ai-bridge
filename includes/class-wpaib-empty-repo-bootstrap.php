<?php

defined('ABSPATH') || exit;

/**
 * Normalizes GitHub's empty-repository response so the existing sync engine
 * can create the first Git tree, commit and branch itself.
 *
 * GitHub returns HTTP 409 "Git Repository is empty." when reading a branch
 * from a repository with no commits. The sync engine already knows how to
 * create an initial commit when the branch is missing, so this filter maps
 * only that exact 409 response to the same 404/missing-branch condition.
 */
final class WPAIB_Empty_Repo_Bootstrap {
    public static function boot(): void {
        add_filter('http_response', [self::class, 'normalize_empty_repo'], 10, 3);
    }

    public static function normalize_empty_repo($response, array $args, string $url) {
        if (is_wp_error($response)) return $response;
        if ('GET' !== strtoupper((string) ($args['method'] ?? 'GET'))) return $response;
        if (409 !== (int) wp_remote_retrieve_response_code($response)) return $response;

        if (!preg_match('#^https://api\\.github\\.com/repos/[^/]+/[^/]+/git/ref/heads/.+$#', $url)) {
            return $response;
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $data = '' !== $raw ? json_decode($raw, true) : [];
        $message = is_array($data) ? strtolower((string) ($data['message'] ?? '')) : '';
        if (!str_contains($message, 'repository is empty')) return $response;

        if (!isset($response['response']) || !is_array($response['response'])) {
            $response['response'] = [];
        }
        $response['response']['code'] = 404;
        $response['response']['message'] = 'Not Found';
        $response['body'] = wp_json_encode([
            'message' => 'Branch does not exist yet.',
            'wpaib_empty_repository' => true,
        ]);

        return $response;
    }
}
