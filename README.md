# WP AI Bridge

WP AI Bridge keeps the code that matters on WordPress sites in GitHub so ChatGPT web can read and edit projects through the normal GitHub connector.

## How it works

```text
WordPress site(s) <-> GitHub project repos <-> ChatGPT web
                         ^
                         |
                    Fleet Hub
```

The plugin pushes the live project source to GitHub. ChatGPT reads and edits that repository. WP AI Bridge then checks the configured GitHub branch and deploys new commits back to WordPress.

Version 0.7 adds **Fleet Mode** for people managing many WordPress sites. One Hub holds the GitHub App credentials; client sites use a short Fleet Key once and never need their own GitHub PAT.

## Synced scope

WP AI Bridge syncs only:

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

A small `.wpaib/site.json` manifest is added to each project repository so an AI client can identify the WordPress site, active theme, active plugins, and sync scope.

## Fleet Mode

### 1. Choose one Hub site

Install WP AI Bridge on one HTTPS WordPress site that will act as the Fleet Hub.

Create a GitHub App and install it on the GitHub account or organization that will own the project repositories.

Recommended GitHub App repository permissions:

- **Contents: Read and write** — required for source sync;
- **Administration: Read and write** — required when an organization installation should create repositories automatically.

For the simplest fleet setup, install the GitHub App for **All repositories**.

On **Settings → WP AI Bridge → Fleet Hub — configure once**, enter:

- GitHub App ID;
- GitHub App Installation ID;
- GitHub App private key.

The private key is encrypted before it is stored in WordPress.

### 2. Repository provisioning

For a GitHub **organization**, the Hub first tries to create repositories using the GitHub App installation itself.

For a GitHub **personal account**, GitHub does not allow an installation access token to create a repository for the authenticated user. In that case, configure one optional **Provisioning token** on the Hub. This token is kept only on the Hub and is never sent to client WordPress sites.

Repositories are private by default and named from the site hostname, for example:

```text
longkhai.com -> wp-longkhai-com
shop.example.com -> wp-shop-example-com
```

### 3. Generate one Fleet Key

On the Hub, click **Generate 24-hour Fleet Key**.

A Fleet Key contains the Hub enrollment URL plus a short-lived enrollment code. The same key can be used to enroll multiple sites while it is valid. It can also be revoked immediately from the Hub.

### 4. Connect client sites

On each normal WordPress site:

1. install/update WP AI Bridge;
2. open **Settings → WP AI Bridge**;
3. paste the Fleet Key;
4. click **Connect & Sync**.

The Hub then:

1. identifies or creates that site's dedicated repository;
2. creates a separate site credential;
3. mints a GitHub App installation token restricted to that repository;
4. returns the short-lived token to the client site.

Each client stores its site credential and GitHub token encrypted. GitHub installation tokens are refreshed automatically before they expire.

No per-site GitHub PAT or manual repository entry is required in Fleet Mode.

## Direct GitHub fallback

For a small number of sites, **Advanced → Direct GitHub connection** still supports the v0.6 flow using:

- `owner/repository`;
- branch;
- a fine-grained GitHub token with **Contents: Read and write**.

Direct mode is kept only as a fallback. Saving a direct connection disconnects Fleet on that site.

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

Once project repositories are visible to the ChatGPT GitHub connector:

```text
You ask ChatGPT to change a site
        ↓
ChatGPT reads/edits that site's GitHub repository
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

## Secret storage

WP AI Bridge encrypts stored GitHub App private keys, Fleet site credentials, provisioning tokens, and short-lived access tokens using Sodium when available, with AES-256-GCM/OpenSSL as a fallback.

The Hub stores client site secrets only as WordPress password hashes.

## Plugin updates

The settings page checks `LuongVanDuy/wp-ai-bridge` `main` for newer WP AI Bridge versions. If a newer version exists, the normal WordPress plugin upgrader can install it directly from GitHub.

The project is intended only for WordPress installations and GitHub repositories you own or are authorized to administer.
