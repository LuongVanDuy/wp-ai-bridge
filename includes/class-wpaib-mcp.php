<?php

defined('ABSPATH') || exit;

final class WPAIB_MCP {
    private const NS = 'wp-ai-bridge/v1';
    private const MODERN_PROTOCOL = '2026-07-28';
    private const LEGACY_PROTOCOL = '2025-11-25';
    private const TOOL_CACHE_TTL_MS = 300000;

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function endpoint_url(): string {
        return rest_url(self::NS . '/mcp');
    }

    public static function register_routes(): void {
        register_rest_route(self::NS, '/mcp', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    public static function authorize(WP_REST_Request $request): bool|WP_Error {
        return WPAIB_Auth::authorize_read($request);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return self::error(null, -32700, 'Parse error', 400);
        }

        $id = $payload['id'] ?? null;
        $method = isset($payload['method']) && is_string($payload['method']) ? $payload['method'] : '';
        $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];

        if ('2.0' !== ($payload['jsonrpc'] ?? null) || '' === $method) {
            return self::error($id, -32600, 'Invalid Request', 400);
        }

        $transport_error = self::validate_transport_headers($request, $method, $params);
        if (is_wp_error($transport_error)) {
            return self::error($id, -32020, $transport_error->get_error_message(), 400);
        }

        if ('notifications/initialized' === $method || str_starts_with($method, 'notifications/')) {
            WPAIB_Audit::record('mcp_notification', ['method' => $method]);
            return new WP_REST_Response(null, 204);
        }

        WPAIB_Audit::record('mcp_request', ['method' => $method]);

        return match ($method) {
            'server/discover' => self::success($id, self::discover_result(), true),
            'initialize' => self::success($id, self::initialize_result($params)),
            'ping' => self::success($id, []),
            'tools/list' => self::success($id, self::tools_list_result(), self::is_modern($request, $payload)),
            'tools/call' => self::call_tool($id, $params, self::is_modern($request, $payload)),
            default => self::error($id, -32601, 'Method not found', 404),
        };
    }

    private static function discover_result(): array {
        return [
            'supportedVersions' => [self::MODERN_PROTOCOL, self::LEGACY_PROTOCOL],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'instructions' => 'Read-only WordPress diagnostics. Use wp_read_file only for files needed to answer the user request.',
            'ttlMs' => self::TOOL_CACHE_TTL_MS,
            'cacheScope' => 'private',
        ];
    }

    private static function initialize_result(array $params): array {
        $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
            ? $params['protocolVersion']
            : self::LEGACY_PROTOCOL;

        $supported_legacy = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
        $protocol = in_array($requested, $supported_legacy, true) ? $requested : self::LEGACY_PROTOCOL;

        return [
            'protocolVersion' => $protocol,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => self::server_info(),
            'instructions' => 'Read-only WordPress diagnostics. No write, shell, or arbitrary database tools are exposed.',
        ];
    }

    private static function tools_list_result(): array {
        return [
            'tools' => self::tools(),
            'ttlMs' => self::TOOL_CACHE_TTL_MS,
            'cacheScope' => 'private',
        ];
    }

    private static function tools(): array {
        $read_only = [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];

        return [
            [
                'name' => 'wp_ping',
                'description' => 'Test the WP AI Bridge connection and return bridge capabilities.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
                'annotations' => $read_only,
            ],
            [
                'name' => 'wp_site_info',
                'description' => 'Return WordPress, PHP, multisite, active theme, and bridge capability information.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
                'annotations' => $read_only,
            ],
            [
                'name' => 'wp_list_plugins',
                'description' => 'List installed WordPress plugins with version and activation state.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
                'annotations' => $read_only,
            ],
            [
                'name' => 'wp_read_file',
                'description' => 'Read one bounded, non-sensitive file under plugins/, themes/, or uploads/. Secrets are redacted before returning content.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Relative path beginning with plugins/, themes/, or uploads/.',
                            'minLength' => 1,
                        ],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'annotations' => $read_only,
            ],
            [
                'name' => 'wp_recent_audit',
                'description' => 'Return recent WP AI Bridge audit events.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 100,
                            'default' => 25,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $read_only,
            ],
        ];
    }

    private static function call_tool(mixed $id, array $params, bool $modern): WP_REST_Response {
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

        if ('' === $name) {
            return self::error($id, -32602, 'Tool name is required.', 400);
        }

        $result = match ($name) {
            'wp_ping' => self::tool_ping(),
            'wp_site_info' => self::tool_site_info(),
            'wp_list_plugins' => self::tool_plugins(),
            'wp_read_file' => self::tool_read_file($arguments),
            'wp_recent_audit' => self::tool_audit($arguments),
            default => new WP_Error('wpaib_unknown_tool', 'Unknown tool.', ['status' => 404]),
        };

        WPAIB_Audit::record('mcp_tool_call', ['tool' => $name, 'ok' => !is_wp_error($result)]);

        if (is_wp_error($result)) {
            return self::success($id, self::tool_error($result), $modern);
        }

        return self::success($id, self::tool_content($result), $modern);
    }

    private static function tool_ping(): array {
        return [
            'connected' => true,
            'plugin' => 'WP AI Bridge',
            'version' => WPAIB_VERSION,
            'mcp_endpoint' => self::endpoint_url(),
            'auth_method' => WPAIB_Auth::method(),
            'mode' => 'read-only',
            'tools' => array_column(self::tools(), 'name'),
        ];
    }

    private static function tool_site_info(): array {
        $response = WPAIB_REST::site_info();
        return $response->get_data();
    }

    private static function tool_plugins(): array {
        $response = WPAIB_REST::plugins();
        return $response->get_data();
    }

    private static function tool_read_file(array $arguments): array|WP_Error {
        $path = isset($arguments['path']) && is_string($arguments['path']) ? $arguments['path'] : '';
        if ('' === $path) {
            return new WP_Error('wpaib_missing_path', 'path is required.', ['status' => 400]);
        }
        return WPAIB_Files::read($path);
    }

    private static function tool_audit(array $arguments): array {
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 25;
        return WPAIB_Audit::recent(max(1, min($limit, 100)));
    }

    private static function tool_content(mixed $data): array {
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => $json],
            ],
            'structuredContent' => is_array($data) ? $data : ['value' => $data],
            'isError' => false,
        ];
    }

    private static function tool_error(WP_Error $error): array {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
        $payload = [
            'error' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'status' => $status,
        ];

        return [
            'content' => [
                ['type' => 'text', 'text' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"error":"unknown"}'],
            ],
            'structuredContent' => $payload,
            'isError' => true,
        ];
    }

    private static function validate_transport_headers(WP_REST_Request $request, string $method, array $params): true|WP_Error {
        $protocol = trim((string) $request->get_header('mcp-protocol-version'));
        if ('' !== $protocol && self::MODERN_PROTOCOL !== $protocol) {
            return new WP_Error('wpaib_mcp_protocol', 'Unsupported MCP-Protocol-Version header.');
        }

        if (self::MODERN_PROTOCOL !== $protocol) {
            return true;
        }

        $method_header = trim((string) $request->get_header('mcp-method'));
        if ('' !== $method_header && $method_header !== $method) {
            return new WP_Error('wpaib_mcp_method_header', 'Mcp-Method header does not match the JSON-RPC method.');
        }

        if ('tools/call' === $method) {
            $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
            $name_header = trim((string) $request->get_header('mcp-name'));
            if ('' !== $name_header && $name_header !== $name) {
                return new WP_Error('wpaib_mcp_name_header', 'Mcp-Name header does not match the tool name.');
            }
        }

        return true;
    }

    private static function is_modern(WP_REST_Request $request, array $payload): bool {
        if (self::MODERN_PROTOCOL === trim((string) $request->get_header('mcp-protocol-version'))) {
            return true;
        }

        $meta = $payload['params']['_meta'] ?? null;
        return is_array($meta)
            && self::MODERN_PROTOCOL === ($meta['io.modelcontextprotocol/protocolVersion'] ?? null);
    }

    private static function server_info(): array {
        return [
            'name' => 'wp-ai-bridge',
            'title' => 'WP AI Bridge',
            'version' => WPAIB_VERSION,
            'description' => 'Guarded WordPress diagnostics bridge.',
            'websiteUrl' => home_url('/'),
        ];
    }

    private static function success(mixed $id, array $result, bool $modern = false): WP_REST_Response {
        if ($modern) {
            $result['_meta'] = array_merge(
                isset($result['_meta']) && is_array($result['_meta']) ? $result['_meta'] : [],
                ['io.modelcontextprotocol/serverInfo' => self::server_info()]
            );
        }

        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], 200);
    }

    private static function error(mixed $id, int $code, string $message, int $http_status): WP_REST_Response {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $http_status);
    }
}
