# WP AI Bridge

WP AI Bridge connects a WordPress site directly to GitHub so ChatGPT web can work with the site's active theme/plugin source through a normal GitHub repository.

## v0.9 workflow

```text
WordPress <-> GitHub repository <-> ChatGPT web
```

Each WordPress site connects to GitHub directly with OAuth Device Flow. There is no Fleet Hub, Fleet Key, GitHub App private key, Installation ID, or per-site PAT setup.

## Setup

1. Install/update WP AI Bridge.
2. Open **Settings -> WP AI Bridge**.
3. Click **Connect GitHub** and approve the GitHub device authorization.
4. Choose an accessible repository from the list, or enter `owner/repository` manually.
5. If a repository name under the connected personal account does not exist, WP AI Bridge creates it as a private repository.
6. Click **Push code** for the initial snapshot.

After the initial push, WP AI Bridge checks GitHub about every five minutes and deploys new commits automatically. **Update website now** is available for an immediate manual pull.

## Synced scope

WP AI Bridge syncs only:

- the active theme;
- the parent theme when a child theme is active;
- active plugins.

It intentionally excludes:

- `wp-content/uploads` / Media Library;
- WordPress core;
- `wp-config.php`;
- WP AI Bridge itself;
- caches, logs and backups;
- `.env`, private keys, database dumps and archives;
- `node_modules`;
- individual files larger than 5 MiB.

A small `.wpaib/site.json` manifest is added to the repository.

## Repository workflow

```text
You ask ChatGPT to change the website
        |
        v
ChatGPT edits the selected GitHub repository
        |
        v
WP AI Bridge detects the new commit
        |
        v
Changed theme/plugin files are deployed to WordPress
```

## Safety

GitHub-to-WordPress writes remain hard-scoped to:

```text
wp-content/themes/**
wp-content/plugins/**
```

The plugin rejects path traversal and symlink escapes, blocks sensitive/server configuration files, backs up existing files before overwrite/delete, parses PHP with `TOKEN_PARSE`, invalidates OPcache for changed PHP files when available, and records actions in the audit log.

No WordPress core writes, arbitrary database queries, or shell commands are exposed.

## GitHub authorization

The GitHub OAuth Client ID is embedded in the plugin and is public by design. OAuth access tokens are encrypted at rest. The current OAuth request uses the `repo` scope so the connected account can read/write private repositories and create project repositories when needed.

## Plugin updates

WP AI Bridge checks `LuongVanDuy/wp-ai-bridge` `main` for newer versions and injects updates into the normal WordPress Plugins screen. The updater preserves the current plugin directory so activation state is retained across GitHub ZIP updates.
