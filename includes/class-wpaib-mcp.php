<?php

defined('ABSPATH') || exit;

final class WPAIB_MCP {
    private const NS = 'wp-ai-bridge/v1';
    private const MODERN_PROTOCOL = '2026-07-28';
    private const LEGACY_PROTOCOL = '2025-11-25';

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
        if (!is_array($payload)) return self::rpc_error(null, -32700, 'Parse error', 400);

        $id = $payload['id'] ?? null;
        $method = is_string($payload['method'] ?? null) ? $payload['method'] : '';
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        if ('2.0' !== ($payload['jsonrpc'] ?? null) || '' === $method) return self::rpc_error($id, -32600, 'Invalid Request', 400);

        $headers = self::validate_headers($request, $method, $params);
        if (is_wp_error($headers)) return self::rpc_error($id, -32020, $headers->get_error_message(), 400);

        if ('notifications/initialized' === $method || str_starts_with($method, 'notifications/')) {
            WPAIB_Audit::record('mcp_notification', ['method' => $method]);
            return new WP_REST_Response(null, 204);
        }

        WPAIB_Audit::record('mcp_request', ['method' => $method]);
        $modern = self::is_modern($request, $payload);

        return match ($method) {
            'server/discover' => self::success($id, self::discover(), true),
            'initialize' => self::success($id, self::initialize($params), $modern),
            'ping' => self::success($id, []),
            'tools/list' => self::success($id, ['tools' => self::tools(), 'ttlMs' => 300000, 'cacheScope' => 'private'], $modern),
            'tools/call' => self::call_tool($id, $params, $modern),
            default => self::rpc_error($id, -32601, 'Method not found', 404),
        };
    }

    private static function discover(): array {
        return [
            'supportedVersions' => [self::MODERN_PROTOCOL, self::LEGACY_PROTOCOL],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'instructions' => 'Reads may inspect plugins, themes, and selected uploads. Maintenance Mode allows continuous writes only under plugins/ and themes/. No core, wp-config.php, shell, or arbitrary database tools.',
            'ttlMs' => 300000,
            'cacheScope' => 'private',
        ];
    }

    private static function initialize(array $params): array {
        $requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : self::LEGACY_PROTOCOL;
        $legacy = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
        return [
            'protocolVersion' => in_array($requested, $legacy, true) ? $requested : self::LEGACY_PROTOCOL,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => self::server_info(),
            'instructions' => 'Existing files are backed up before overwrite/delete. PHP writes are syntax-checked. Write scope is plugins/ and themes/ only.',
        ];
    }

    private static function tools(): array {
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $rw = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $del = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];
        $empty = ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false];

        return [
            ['name' => 'wp_ping', 'description' => 'Test connection and current bridge mode.', 'inputSchema' => $empty, 'annotations' => $ro],
            ['name' => 'wp_site_info', 'description' => 'Return WordPress, PHP, theme and bridge capability information.', 'inputSchema' => $empty, 'annotations' => $ro],
            ['name' => 'wp_list_plugins', 'description' => 'List installed plugins, versions and activation state.', 'inputSchema' => $empty, 'annotations' => $ro],
            ['name' => 'wp_list_directory', 'description' => 'List files/directories under plugins/, themes/, or uploads/.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'recursive' => ['type' => 'boolean', 'default' => false], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 200]], 'required' => ['path'], 'additionalProperties' => false], 'annotations' => $ro],
            ['name' => 'wp_search_files', 'description' => 'Search source files inside plugins or themes.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'root' => ['type' => 'string', 'enum' => ['plugins', 'themes'], 'default' => 'plugins'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]], 'required' => ['query'], 'additionalProperties' => false], 'annotations' => $ro],
            ['name' => 'wp_read_file', 'description' => 'Read a bounded non-sensitive file under plugins/, themes/, or uploads/.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'], 'additionalProperties' => false], 'annotations' => $ro],
            ['name' => 'wp_write_file', 'description' => 'Create or replace a file under plugins/ or themes/. Existing files are backed up; PHP is syntax-checked.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['path', 'content'], 'additionalProperties' => false], 'annotations' => $rw],
            ['name' => 'wp_delete_file', 'description' => 'Delete one file under plugins/ or themes/ after making a backup.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'], 'additionalProperties' => false], 'annotations' => $del],
            ['name' => 'wp_restore_backup', 'description' => 'Restore a file backup returned by a previous write/delete operation.', 'inputSchema' => ['type' => 'object', 'properties' => ['backup_id' => ['type' => 'string']], 'required' => ['backup_id'], 'additionalProperties' => false], 'annotations' => $rw],
            ['name' => 'wp_activate_plugin', 'description' => 'Activate an installed plugin.', 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin'], 'additionalProperties' => false], 'annotations' => $rw],
            ['name' => 'wp_deactivate_plugin', 'description' => 'Deactivate an installed plugin.', 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin'], 'additionalProperties' => false], 'annotations' => $rw],
            ['name' => 'wp_recent_audit', 'description' => 'Return recent bridge audit events.', 'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]], 'additionalProperties' => false], 'annotations' => $ro],
        ];
    }

    private static function call_tool(mixed $id, array $params, bool $modern): WP_REST_Response {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if ('' === $name) return self::rpc_error($id, -32602, 'Tool name is required.', 400);

        $write_tools = ['wp_write_file', 'wp_delete_file', 'wp_restore_backup', 'wp_activate_plugin', 'wp_deactivate_plugin'];
        if (in_array($name, $write_tools, true) && !WPAIB_Auth::maintenance_enabled()) {
            $result = new WP_Error('wpaib_maintenance_disabled', 'Maintenance Mode is disabled.', ['status' => 403]);
        } else {
            $result = match ($name) {
                'wp_ping' => self::tool_ping(),
                'wp_site_info' => WPAIB_REST::site_info()->get_data(),
                'wp_list_plugins' => WPAIB_REST::plugins()->get_data(),
                'wp_list_directory' => WPAIB_Files::list_directory((string) ($args['path'] ?? ''), !empty($args['recursive']), (int) ($args['limit'] ?? 200)),
                'wp_search_files' => WPAIB_Files::search((string) ($args['query'] ?? ''), (string) ($args['root'] ?? 'plugins'), (int) ($args['limit'] ?? 50)),
                'wp_read_file' => WPAIB_Files::read((string) ($args['path'] ?? '')),
                'wp_write_file' => WPAIB_Maintenance::write_file((string) ($args['path'] ?? ''), is_string($args['content'] ?? null) ? $args['content'] : ''),
                'wp_delete_file' => WPAIB_Maintenance::delete_file((string) ($args['path'] ?? '')),
                'wp_restore_backup' => WPAIB_Maintenance::restore_backup((string) ($args['backup_id'] ?? '')),
                'wp_activate_plugin' => WPAIB_Maintenance::activate_plugin((string) ($args['plugin'] ?? '')),
                'wp_deactivate_plugin' => WPAIB_Maintenance::deactivate_plugin((string) ($args['plugin'] ?? '')),
                'wp_recent_audit' => WPAIB_Audit::recent(max(1, min((int) ($args['limit'] ?? 25), 100))),
                default => new WP_Error('wpaib_unknown_tool', 'Unknown tool.', ['status' => 404]),
            };
        }

        WPAIB_Audit::record('mcp_tool_call', ['tool' => $name, 'ok' => !is_wp_error($result)]);
        return self::success($id, is_wp_error($result) ? self::tool_error($result) : self::tool_content($result), $modern);
    }

    private static function tool_ping(): array {
        return [
            'connected' => true,
            'plugin' => 'WP AI Bridge',
            'version' => WPAIB_VERSION,
            'mcp_endpoint' => self::endpoint_url(),
            'auth_method' => WPAIB_Auth::method(),
            'mode' => WPAIB_Auth::maintenance_enabled() ? 'maintenance' : 'read-only',
            'write_scope' => ['plugins', 'themes'],
            'tools' => array_column(self::tools(), 'name'),
        ];
    }

    private static function tool_content(mixed $data): array {
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return ['content' => [['type' => 'text', 'text' => is_string($json) ? $json : '{}']], 'structuredContent' => is_array($data) ? $data : ['value' => $data], 'isError' => false];
    }

    private static function tool_error(WP_Error $error): array {
        $data = $error->get_error_data();
        $payload = ['error' => $error->get_error_code(), 'message' => $error->get_error_message(), 'status' => is_array($data) && isset($data['status']) ? (int) $data['status'] : 400];
        return ['content' => [['type' => 'text', 'text' => wp_json_encode($payload) ?: '{"error":"unknown"}']], 'structuredContent' => $payload, 'isError' => true];
    }

    private static function validate_headers(WP_REST_Request $request, string $method, array $params): bool|WP_Error {
        $protocol = trim((string) $request->get_header('mcp-protocol-version'));
        if ('' !== $protocol && self::MODERN_PROTOCOL !== $protocol) return new WP_Error('wpaib_mcp_protocol', 'Unsupported MCP-Protocol-Version header.');
        if (self::MODERN_PROTOCOL !== $protocol) return true;
        $method_header = trim((string) $request->get_header('mcp-method'));
        if ('' !== $method_header && $method_header !== $method) return new WP_Error('wpaib_mcp_method_header', 'Mcp-Method header does not match the JSON-RPC method.');
        if ('tools/call' === $method) {
            $name = is_string($params['name'] ?? null) ? $params['name'] : '';
            $name_header = trim((string) $request->get_header('mcp-name'));
            if ('' !== $name_header && $name_header !== $name) return new WP_Error('wpaib_mcp_name_header', 'Mcp-Name header does not match the tool name.');
        }
        return true;
    }

    private static function is_modern(WP_REST_Request $request, array $payload): bool {
        if (self::MODERN_PROTOCOL === trim((string) $request->get_header('mcp-protocol-version'))) return true;
        $meta = $payload['params']['_meta'] ?? null;
        return is_array($meta) && self::MODERN_PROTOCOL === ($meta['io.modelcontextprotocol/protocolVersion'] ?? null);
    }

    private static function server_info(): array {
        return ['name' => 'wp-ai-bridge', 'title' => 'WP AI Bridge', 'version' => WPAIB_VERSION, 'description' => 'WordPress diagnostics and scoped theme/plugin maintenance bridge.', 'websiteUrl' => home_url('/')];
    }

    private static function success(mixed $id, array $result, bool $modern = false): WP_REST_Response {
        if ($modern) $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => self::server_info()];
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
    }

    private static function rpc_error(mixed $id, int $code, string $message, int $http_status): WP_REST_Response {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $http_status);
    }
}
