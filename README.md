# WP AI Bridge

A guarded WordPress REST + MCP bridge for authorized AI-assisted diagnostics and theme/plugin maintenance.

## Current scope

The bridge supports:

- Site / PHP / active-theme information.
- Plugin inventory and activation state.
- Directory browsing and text search inside plugins/themes.
- Read selected files under `plugins/`, `themes/`, and `uploads/`.
- MCP tools for creating/replacing/deleting/restoring files under `plugins/` and `themes/` when Maintenance Mode is enabled.
- Automatic backup before overwrite/delete.
- PHP syntax validation before PHP files are written.
- Plugin activation/deactivation while Maintenance Mode is enabled.
- Path traversal and symlink escape protection.
- Secret redaction and bounded audit logging.
- Dedicated Bearer token authentication.

The bridge intentionally does **not** expose WordPress core writes, `wp-config.php`, arbitrary database queries, or shell commands.

## Install

1. Download or clone the repository into `wp-content/plugins/wp-ai-bridge`.
2. Activate **WP AI Bridge**.
3. Open **Settings → WP AI Bridge**.
4. Generate an API token and copy it immediately; only its password hash is stored.
5. Keep the site on HTTPS.
6. Enable **Maintenance Mode** if you want continuous theme/plugin edits without a WordPress confirmation step for each operation.

## Connection

REST base URL:

```text
https://example.com/wp-json/wp-ai-bridge/v1/
```

MCP URL:

```text
https://example.com/wp-json/wp-ai-bridge/v1/mcp
```

Authentication:

```http
Authorization: Bearer YOUR_TOKEN
```

REST connection test:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://example.com/wp-json/wp-ai-bridge/v1/ping
```

## Maintenance Mode

Maintenance Mode is a one-time WordPress setting. While enabled, an authenticated bridge client can continuously modify code without requesting a separate WordPress confirmation for each operation.

Allowed write scope:

```text
wp-content/plugins/**
wp-content/themes/**
```

Hard-blocked write scope includes WordPress core, `wp-config.php`, `.env`, server configuration, database access, shell commands, archives, and private-key files.

Existing files are backed up before overwrite/delete. A returned `backup_id` can be passed to `wp_restore_backup`.

## MCP tools

Read tools:

```text
wp_ping
wp_site_info
wp_list_plugins
wp_list_directory
wp_search_files
wp_read_file
wp_recent_audit
```

Maintenance tools:

```text
wp_write_file
wp_delete_file
wp_restore_backup
wp_activate_plugin
wp_deactivate_plugin
```

Write tools return an error while Maintenance Mode is disabled.

## Security model

- Random 256-bit API secret with `wpaib_` prefix.
- Only a WordPress password hash of the token is stored.
- HTTPS expected for remote access.
- Reads are bounded and sensitive content is redacted.
- Writes are hard-scoped to themes/plugins.
- PHP writes are parsed with `TOKEN_PARSE` before deployment.
- Overwrites/deletes create rollback backups.
- Internal backup paths are blocked from the bridge read API.
- All bridge actions are audit logged.

The project is intended only for WordPress installations you own or are authorized to administer.
