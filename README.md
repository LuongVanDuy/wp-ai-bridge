# WP AI Bridge

A guarded WordPress REST + MCP bridge for authorized AI-assisted diagnostics and theme/plugin development.

## What it does

- Connects ChatGPT to WordPress through a remote MCP endpoint.
- Built-in OAuth 2.1 Authorization Code + PKCE flow using your WordPress administrator login.
- Dynamic OAuth client registration for ChatGPT.
- Access and refresh tokens so the ChatGPT connection can persist.
- Site / PHP / active-theme information.
- Plugin inventory and activation state.
- Directory browsing and source-code search.
- Read selected files under `plugins/`, `themes/`, and `uploads/`.
- Create, replace, delete, and restore files under `plugins/` and `themes/`.
- Automatic backup before overwrite/delete.
- PHP syntax validation before PHP files are written.
- Plugin activation/deactivation.
- Path traversal and symlink escape protection.
- Secret redaction and bounded audit logging.
- Optional manual Bearer API token for curl/scripts.
- GitHub-backed update checks and WordPress-native updating from repository `main`.

The bridge intentionally does **not** expose WordPress core writes, `wp-config.php`, arbitrary database queries, or shell commands.

## Install

1. Put the repository at `wp-content/plugins/wp-ai-bridge`.
2. Activate **WP AI Bridge**.
3. Open **Settings → WP AI Bridge**.
4. Confirm HTTPS is detected.
5. Copy the MCP URL shown on the settings page.

No Maintenance Mode is required. Authorized MCP clients always receive the hard-scoped theme/plugin capabilities.

## ChatGPT connection

MCP URL:

```text
https://example.com/wp-json/wp-ai-bridge/v1/mcp
```

Use **OAuth** when creating the custom MCP app in ChatGPT. ChatGPT discovers the plugin's OAuth metadata, registers itself as a client, opens WordPress for administrator authorization, exchanges the authorization code with PKCE, and stores access/refresh tokens.

OAuth metadata endpoints:

```text
/.well-known/oauth-protected-resource
/.well-known/oauth-authorization-server
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

## Manual API token

The settings page still lets administrators create a manual `wpaib_...` Bearer token for curl/scripts. This is optional and is not needed for the ChatGPT OAuth connection.

## Updating

The WordPress settings page checks the GitHub `main` branch when loaded. When `main` contains a higher plugin version, **Update now** uses the normal WordPress plugin upgrader to install the GitHub ZIP.

For every new release, increase both the `Version:` header and `WPAIB_VERSION` in `wp-ai-bridge.php`.

## Security model

- OAuth authorization requires a logged-in WordPress administrator with `manage_options`.
- OAuth uses Authorization Code + PKCE S256.
- Access tokens expire after one hour; refresh tokens are rotated and expire after 30 days.
- OAuth clients are dynamically registered and redirect URIs are restricted to HTTPS OpenAI/ChatGPT hosts.
- OAuth tokens are stored only as HMAC hashes in WordPress.
- Manual API tokens are stored only as WordPress password hashes.
- HTTPS is expected for remote access.
- Reads are bounded and sensitive content is redacted.
- Writes are hard-scoped to themes/plugins.
- PHP writes are parsed with `TOKEN_PARSE` before deployment.
- Overwrites/deletes create rollback backups.
- Internal backup paths are blocked from the bridge read API.
- All bridge actions are audit logged.

The project is intended only for WordPress installations you own or are authorized to administer.
