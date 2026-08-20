# WP AI Bridge

A guarded WordPress REST bridge for authorized AI-assisted diagnostics and maintenance.

## Current scope

The remote API is intentionally read-only:

- Site / PHP / theme information.
- Plugin inventory and activation state.
- Read selected files under `plugins/`, `themes/`, and `uploads/`.
- Path traversal protection and sensitive-file blocking.
- Basic secret redaction before content leaves WordPress.
- Bounded audit log.
- WordPress administrator authentication or a dedicated Bearer API token.
- Connection URL, token generation/rotation/revocation, and a protected ping endpoint.

Write endpoints are **not implemented** yet. A write capability flag exists for the next milestone, where writes will require explicit opt-in, backups, validation, and audit events.

## Install

1. Download or clone the repository.
2. Put the repository folder at `wp-content/plugins/wp-ai-bridge`.
3. Activate **WP AI Bridge** in WordPress.
4. Open **Settings → WP AI Bridge**.
5. Make sure the site is served over HTTPS.
6. Click **Generate API token** and copy the token immediately. Only a password hash is stored after the one-time display.

## Connection

The settings page shows a base URL similar to:

```text
https://example.com/wp-json/wp-ai-bridge/v1/
```

Use the generated token as a Bearer credential:

```http
Authorization: Bearer wpaib_REDACTED
```

Test the connection:

```bash
curl \
  -H "Authorization: Bearer YOUR_TOKEN" \
  https://example.com/wp-json/wp-ai-bridge/v1/ping
```

A successful response includes `connected: true`, the plugin version, the REST base URL, authentication method, and current read capabilities.

The API token can be rotated or revoked from **Settings → WP AI Bridge**. The original plaintext token cannot be recovered from WordPress.

## REST endpoints

All endpoints require either:

- an authenticated WordPress administrator with `manage_options`, or
- the dedicated WP AI Bridge Bearer token.

```text
GET /wp-json/wp-ai-bridge/v1/ping
GET /wp-json/wp-ai-bridge/v1/site-info
GET /wp-json/wp-ai-bridge/v1/plugins
GET /wp-json/wp-ai-bridge/v1/file?path=plugins/example/example.php
GET /wp-json/wp-ai-bridge/v1/audit
```

### File roots

The `file` endpoint accepts paths beginning with:

```text
plugins/
themes/
uploads/
```

It blocks traversal, symlink escapes, common credential/config files, private-key formats, SQL archives, and backup archives. Files larger than 256 KiB are rejected in the current milestone.

## Security model

- Read-only remote API by default.
- A random 256-bit API secret with a fixed `wpaib_` prefix.
- WordPress password hashing is used for token storage; the plaintext token is shown only once after generation.
- Token generation, rotation, and revocation require a logged-in administrator plus a WordPress nonce.
- HTTPS is expected for remote access.
- No shell execution.
- No arbitrary database queries.
- No WordPress core file reads through the file endpoint.
- No `wp-config.php`, `.env`, key files, SQL dumps, or backup archives.
- Audit context redacts fields whose keys resemble passwords, secrets, tokens, credentials, or keys.

## ChatGPT / MCP next step

This plugin exposes a guarded WordPress REST API. To use it as a ChatGPT custom app, place an MCP gateway in front of these endpoints and configure the gateway with the Connection URL and Bearer token. The gateway should map explicit MCP tools such as `wp_ping`, `wp_site_info`, `wp_list_plugins`, and `wp_read_file` to the REST endpoints rather than exposing arbitrary HTTP access.

## Planned guarded-write milestone

The next milestone can add:

- Create backup before mutation.
- Patch a bounded plugin/theme file.
- Validate PHP syntax before finalizing PHP changes.
- Restore from backup.
- Activate/deactivate plugins with explicit allow/deny controls.
- Clear known caches.
- Approval-sensitive operations separated from read operations.

The project is intended only for WordPress installations you own or are authorized to administer.
