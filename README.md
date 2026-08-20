# WP AI Bridge

WP AI Bridge keeps the WordPress source code that matters in GitHub so ChatGPT web can read and edit each website through the normal GitHub connector.

## v0.8 workflow

```text
WordPress sites -> Fleet Hub -> GitHub repos -> ChatGPT web
```

The Hub connects to GitHub once with OAuth Device Flow. Client sites never receive the Hub's GitHub access token. They authenticate to the Hub with a site-specific Fleet credential, and the Hub proxies GitHub requests only to the repository assigned to that site.

## Simple setup

### Hub

1. Create one GitHub OAuth App and enable **Device Flow**.
2. Paste its public **Client ID** into WP AI Bridge once.
3. Click **Connect GitHub**.
4. GitHub shows a short verification code; approve it at `https://github.com/login/device`.
5. Generate a Fleet Key.

After the Client ID is configured, the Hub UI is just **Connect GitHub** / connection status.

The OAuth App should request the `repo` scope. WP AI Bridge uses that authorization to create private project repositories and read/write their contents.

The Client ID is not a secret. It can also be supplied with the `WPAIB_GITHUB_OAUTH_CLIENT_ID` constant so the Client ID field never appears in the UI.

### Client websites

On every other WordPress site:

1. Install WP AI Bridge.
2. Open **Settings -> WP AI Bridge**.
3. Paste the Fleet Key.
4. Click **Connect & Sync**.

The same Fleet Key can enroll multiple sites for seven days, or until it is rotated/revoked.

Each site receives its own private GitHub repository automatically, normally named from the domain:

```text
example.com -> wp-example-com
shop.example.net -> wp-shop-example-net
```

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
- cache directories;
- logs and backups;
- `.env`, private keys, database dumps and archives;
- `node_modules`;
- individual files larger than 5 MiB.

A small `.wpaib/site.json` manifest is added to each project repository.

## ChatGPT workflow

Once the project repositories are visible to the GitHub connector:

```text
You ask ChatGPT to change a site
        |
        v
ChatGPT reads/edits that site's GitHub repository
        |
        v
WP AI Bridge notices the new commit
        |
        v
Changed theme/plugin files are deployed to WordPress
```

WP AI Bridge checks the configured branch about every five minutes using WP-Cron. Actual timing depends on WordPress traffic.

## Security model

- The GitHub OAuth token is encrypted at rest and remains only on the Hub.
- Client sites get a separate high-entropy Fleet credential, not the GitHub token.
- Hub proxy requests are restricted to the GitHub repository assigned to the authenticated site.
- GitHub methods exposed through the Fleet proxy are limited to the methods required by the sync engine.
- Writes remain hard-scoped to `wp-content/themes/**` and `wp-content/plugins/**`.
- PHP is parsed with `TOKEN_PARSE` before deployment.
- Existing files are backed up before overwrite/delete.
- OPcache is invalidated for changed PHP files when available.
- WordPress core, arbitrary database access and shell commands are not exposed.

## Plugin updates

The plugin checks `LuongVanDuy/wp-ai-bridge` `main` for newer versions and injects updates into the normal WordPress Plugins screen. GitHub ZIP normalization preserves the currently installed plugin directory so update operations retain the plugin activation path.

The project is intended only for WordPress installations and GitHub accounts you own or are authorized to administer.
