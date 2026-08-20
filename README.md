# WP AI Bridge

WP AI Bridge keeps the code that matters on a WordPress site in a GitHub repository so ChatGPT web can work on the project through the normal GitHub connector.

## How it works

```text
WordPress  <->  GitHub project repo  <->  ChatGPT web
```

The plugin pushes the live project source to GitHub. ChatGPT reads and edits that repository. WP AI Bridge then checks the configured GitHub branch and deploys new commits back to WordPress.

## Synced scope

By default WP AI Bridge syncs only:

- the active theme;
- the parent theme when a child theme is active;
- active plugins.

It intentionally does **not** push:

- Media Library / `wp-content/uploads`;
- WordPress core;
- `wp-config.php`;
- WP AI Bridge itself;
- cache directories;
- logs and backups;
- `.env`, private keys, database dumps, archives;
- `node_modules`;
- individual files larger than 5 MiB.

A small `.wpaib/site.json` manifest is added to the project repository so an AI client can identify the WordPress site, active theme, active plugins, and sync scope.

## Setup

1. Create a dedicated GitHub repository for the website. Initialize its `main` branch (for example by creating the repository with a README).
2. Create a fine-grained GitHub personal access token limited to that repository with **Contents: Read and write**.
3. Install and activate WP AI Bridge.
4. Open **Settings → WP AI Bridge**.
5. Enter `owner/repository`, branch, and the GitHub token.
6. Click **Connect GitHub**.
7. WP AI Bridge automatically starts the first project snapshot. You can also use **Push project now** at any time.

The GitHub token is encrypted at rest using Sodium when available, with AES-256-GCM/OpenSSL as the fallback.

## Repository layout

A synced site repository looks like:

```text
.wpaib/
  site.json
themes/
  active-theme/
  parent-theme/
plugins/
  active-plugin-a/
  active-plugin-b/
```

## ChatGPT workflow

Once the site project repository is visible to the GitHub connector, a ChatGPT web conversation can read and modify the repository directly.

Typical workflow:

```text
You ask ChatGPT to change the site
        ↓
ChatGPT reads/edits the GitHub project
        ↓
GitHub receives a new commit
        ↓
WP AI Bridge notices the branch changed
        ↓
Changed theme/plugin files are deployed
```

WP AI Bridge polls the configured branch about every five minutes through WP-Cron. Actual timing depends on WordPress traffic because WP-Cron is request-driven.

## Deploy safety

GitHub-to-WordPress deploys are hard-scoped to:

```text
wp-content/themes/**
wp-content/plugins/**
```

For changed files, WP AI Bridge:

- rejects path traversal and symlink escapes;
- blocks sensitive/server configuration file types;
- creates a backup before overwrite/delete;
- parses PHP with `TOKEN_PARSE` before deployment;
- invalidates OPcache for changed PHP files when available;
- records actions in the audit log.

No arbitrary database queries, WordPress core writes, or shell commands are exposed.

## Plugin updates

The settings page also checks `LuongVanDuy/wp-ai-bridge` `main` for newer WP AI Bridge versions. If a newer version exists, the normal WordPress plugin upgrader can install it directly from GitHub.

The project is intended only for WordPress installations and GitHub repositories you own or are authorized to administer.
