# WP AI Bridge

A guarded WordPress REST + MCP bridge for authorized AI-assisted diagnostics and theme/plugin development.

## What it does

- Site / PHP / active-theme information.
- Plugin inventory and activation state.
- Directory browsing and source-code search.
- Read selected files under `plugins/`, `themes/`, and `uploads/`.
- Create, replace, delete, and restore files under `plugins/` and `themes/` with a valid API token.
- Automatic backup before overwrite/delete.
- PHP syntax validation before PHP files are written.
- Plugin activation/deactivation.
- Path traversal and symlink escape protection.
- Secret redaction and bounded audit logging.
- Dedicated Bearer token authentication.
- GitHub-backed update checks and WordPress-native updating from the repository `main` branch.

The bridge intentionally does **not** expose WordPress core writes, `wp-config.php`, arbitrary database queries, or shell commands.

## Install

1. Put the repository at `wp-content/plugins/wp-ai-bridge`.
2. Activate **WP AI Bridge**.
3. Open **Settings → WP AI Bridge**.
4. Generate an API token and copy it immediately; only its password hash is stored.
5. Keep the site on HTTPS.

There is no Maintenance Mode. A valid bridge token always grants the hard-scoped theme/plugin capabilities below.

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

Allowed write scope:

```text
wp-content/plugins/**
wp-content/themes/**
```

Hard-blocked scope includes WordPress core, `wp-config.php`, `.env`, server configuration, arbitrary database access, shell commands, archives, and private-key files.

## MCP tools

Read/discovery tools:

```text
wp_ping
wp_site_info
wp_list_plugins
wp_list_directory
wp_search_files
wp_read_file
wp_recent_audit
```

Theme/plugin development tools:

```text
wp_write_file
wp_delete_file
wp_restore_backup
wp_activate_plugin
wp_deactivate_plugin
```

## Updating

The WordPress settings page checks the GitHub `main` branch when it is loaded. It compares the installed `WPAIB_VERSION` / plugin `Version` header with the version in `main`.

When a newer version exists, **Update now** uses the normal WordPress plugin upgrader and downloads the public GitHub `main` ZIP.

For every new plugin release, increase the `Version:` header and `WPAIB_VERSION` in `wp-ai-bridge.php`.

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
