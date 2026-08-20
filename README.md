# WP AI Bridge

A guarded WordPress REST bridge for authorized AI-assisted diagnostics and maintenance.

## v0.1 scope

The first milestone is intentionally read-only:

- Site / PHP / theme information.
- Plugin inventory and activation state.
- Read selected files under `plugins/`, `themes/`, and `uploads/`.
- Path traversal protection and sensitive-file blocking.
- Basic secret redaction before content leaves WordPress.
- Bounded audit log.
- Administrator-only REST permission checks.
- WordPress admin settings page.

Write endpoints are **not implemented** in v0.1. A write capability flag exists for the next milestone, where writes will require explicit opt-in, backups, validation, and audit events.

## Install

1. Download or clone the repository.
2. Put the repository folder at `wp-content/plugins/wp-ai-bridge`.
3. Activate **WP AI Bridge** in WordPress.
4. Open **Settings → WP AI Bridge**.
5. Keep the site on HTTPS.
6. Create a dedicated WordPress **Application Password** for the integration rather than sharing your normal password.

## REST endpoints

All endpoints require an authenticated WordPress user with `manage_options`.

```text
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

It blocks traversal, symlink escapes, common credential/config files, private-key formats, SQL archives, and backup archives. Files larger than 256 KiB are rejected in v0.1.

## Security model

- Read-only by default.
- WordPress capability checks on every route.
- HTTPS expected for remote access.
- Dedicated Application Password recommended.
- No shell execution.
- No arbitrary database queries.
- No WordPress core file reads through the file endpoint.
- No `wp-config.php`, `.env`, key files, SQL dumps, or backup archives.
- Audit context redacts fields whose keys resemble passwords, secrets, tokens, credentials, or keys.

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
