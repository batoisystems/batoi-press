# Batoi Press

Batoi Press is a secure flat-file CMS and publishing engine aligned with Batoi RAD. It is designed for standard PHP hosting, FTP deployment, cPanel-style `public_html` web roots, and database-free website publishing.

## Features In This Build

- Configurable `public_html` web root.
- RAD-aligned engine under `radpress/`.
- JSON configuration.
- HTML page and post bodies with adjacent JSON metadata.
- Default theme.
- Public routes for `/`, `/about`, `/blog`, `/blog/first-blog-post`, `/sitemap.xml`, and `/feed.xml`.
- Business-ready authenticated admin console at `/admin`.
- Content workflows for pages, posts, media, menus, settings, users, cache, static export, updates, audit log, and Batoi AIF status.
- Browser installer at `/install.php`; after installation it creates `radpress/config/installed.lock`.
- Cache clear, static export, and update-check admin surfaces.
- Bundled Batoi UIF primitives for admin and installer UI.
- Disabled-by-default Batoi AIF scaffolding with admin status at `/admin/aif`.

## Requirements

- PHP 8.1 or newer.
- Apache with rewrite support for clean URLs, or query-string fallback through `index.php?route=/about`.
- No database, Node.js, Docker, Composer, Git, or CLI access is required for runtime.

## Local Run

```sh
php -S 127.0.0.1:8081 -t public_html
```

Then open:

- `http://127.0.0.1:8081/`
- `http://127.0.0.1:8081/about`
- `http://127.0.0.1:8081/sitemap.xml`

For first-run setup, open:

- `http://127.0.0.1:8081/install.php`

## FTP Installation

Upload `public_html/`, `radpress/`, `README.md`, and `LICENSE` to the host. Point the web root to `public_html/` when the host allows it. Keep `radpress/` outside the public web root when possible; otherwise the included `.htaccess` files deny direct access on Apache-compatible hosts.

Open `/install.php` in a browser and create the owner account.

## Directory Permissions

The web server needs write access to:

```text
radpress/config/
radpress/content/
radpress/data/
```

Theme files under `radpress/theme/` must be readable.

## Directory Model

- `public_html/` is the browser-facing web root.
- `radpress/` is the private RAD-aligned application root.
- `radpress/core/` contains the reusable Batoi Press engine.
- `radpress/app/` is reserved for site-level modules and hooks.
- `radpress/config/` stores JSON configuration.
- `radpress/content/` stores page/post HTML bodies and JSON metadata.
- `radpress/data/` stores cache, logs, sessions, backups, versions, and exports.
- `radpress/uif/` stores native UIF metadata and component notes.
- `radpress/aif/` stores optional AIF provider scaffolding.
- `radpress/theme/` stores render templates and theme assets.

Empty `bin/`, `ms/`, and `vendor/` directories are intentionally omitted from MVP. Add them only when a concrete CLI, RAD module-service, or bundled dependency requirement exists.

## Content Format

Each page or post is stored as a directory containing:

```text
meta.json
body.html
```

This keeps metadata structured and content human-readable.

## Security Notes

Batoi Press uses password hashing, file-backed sessions, CSRF tokens for admin writes, login rate limiting, upload allowlists, generated upload filenames, audit logs, and installer locking through `radpress/config/installed.lock`.

## Admin Setup

The installer creates the first owner user in `radpress/config/users.json`. After installation, use `/admin/login` to manage pages, posts, media, menus, settings, users, cache, static export, and updates.

## Admin Console

The admin console uses bundled Batoi UIF assets and a persistent business console layout with grouped navigation:

- Overview: dashboard.
- Publish: pages, posts, media, menus.
- Site: settings, static export, cache.
- Governance: users, updates, audit log.
- Intelligence: Batoi AIF.

Pages and posts use structured list screens, publication badges, preview links, and editor panels for content, publishing, and SEO. Media lists file type, size, modified date, public URL, and image HTML snippets. Menus use label/URL rows with a legacy `Label|/url` fallback. Settings are grouped by identity, branding, URL, localization, editor configuration, and theme. Theme management supports active theme selection, upload, preview, and template editing. Users show roles, creation dates, account status, filtering, edit flows, password reset, and disable/reactivate controls with owner safeguards.

Operations are separated from publishing work. Static Export creates downloadable ZIP packages with readiness status and recent export history. Cache explains safe maintenance actions and runtime directory status. Updates expose version status, stable manifest, package staging, backup creation, staged packages, and rollback backups. Audit Log provides paginated search, filters, CSV/JSONL export, and retention cleanup with a 90-day minimum while recording authenticated admin views, actions, downloads, semantic changes, outcomes, and safe request details.

## Installer Lifecycle

After a successful install, `radpress/config/installed.lock` disables the installer. Remove that lock manually only when intentionally running setup again on a controlled installation.

## Updates

The default stable update manifest is:

```text
https://www.batoi.com/pub/press/latest.json
```

Batoi Press can check the manifest, verify and stage a package, create a backup, apply manifest-listed files in maintenance mode, clear cache, run health checks, roll back automatically after failed checks, and restore manually from a selected backup ZIP.

## Release Packages

Release ZIPs should include `public_html/`, `radpress/`, `README.md`, and `LICENSE`, while excluding generated runtime files such as sessions, cache, backups, exports, logs, and `radpress/config/installed.lock`. See `radpress/docs/installation.md` for package notes.

Every repository release must increment `radpress/config/update.json` before building packages or publishing the stable manifest. See `radpress/docs/release-management.md` for version policy, release checklist, and the stable `1.0.0` readiness checklist.

Build a local release ZIP with:

```sh
php tools/build-release.php
```

Generate the public update manifest with:

```sh
php tools/generate-release-manifest.php
```

The generated files are:

```text
dist/batoi-press-{version}.zip
dist/latest.json
```

`latest.json` is published to:

```text
https://www.batoi.com/pub/press/latest.json
```

Versioned ZIP packages are published to:

```text
https://www.batoi.com/pub/press/releases/batoi-press-{version}.zip
```

## Release Publication

GitHub Actions publishes release files when a GitHub release is published or when the `Publish release` workflow is run manually. The workflow checks PHP syntax, runs smoke and update tests, builds the ZIP, generates `latest.json`, uploads both files as workflow artifacts, and deploys them to the public Batoi website.

Required GitHub Actions secrets:

- `BATOI_WWW_DEPLOY_HOST`
- `BATOI_WWW_DEPLOY_USER`
- `BATOI_WWW_DEPLOY_PORT`
- `BATOI_WWW_DEPLOY_KEY`
- `BATOI_WWW_DEPLOY_PATH`

The deploy target layout is:

```text
{BATOI_WWW_DEPLOY_PATH}/public_html/pub/press/latest.json
{BATOI_WWW_DEPLOY_PATH}/public_html/pub/press/releases/batoi-press-{version}.zip
```

Manual fallback:

```sh
php tools/build-release.php
php tools/generate-release-manifest.php
VERSION=$(php -r '$config = json_decode(file_get_contents("radpress/config/update.json"), true); echo $config["current_version"];')
ssh -p "$BATOI_WWW_DEPLOY_PORT" "$BATOI_WWW_DEPLOY_USER@$BATOI_WWW_DEPLOY_HOST" "mkdir -p '$BATOI_WWW_DEPLOY_PATH/public_html/pub/press/releases'"
scp -P "$BATOI_WWW_DEPLOY_PORT" dist/latest.json "$BATOI_WWW_DEPLOY_USER@$BATOI_WWW_DEPLOY_HOST:$BATOI_WWW_DEPLOY_PATH/public_html/pub/press/latest.json"
scp -P "$BATOI_WWW_DEPLOY_PORT" "dist/batoi-press-$VERSION.zip" "$BATOI_WWW_DEPLOY_USER@$BATOI_WWW_DEPLOY_HOST:$BATOI_WWW_DEPLOY_PATH/public_html/pub/press/releases/batoi-press-$VERSION.zip"
```

## Theme Development

Themes live under `radpress/theme/{theme-name}/` with PHP layouts and optional assets. The active theme is configured in `radpress/config/site.json`.

## Batoi UIF and AIF

Batoi UIF primitives are bundled locally under `public_html/assets/uif/` and documented in `radpress/docs/uif-aif.md`.

Batoi AIF is disabled by default through `radpress/config/aif.json`. It has provider scaffolding for future integrations but makes no AI network calls unless a future provider is explicitly configured. The admin status screen documents provider availability, feature flags, and trust boundaries.

## Roadmap

See `radpress/docs/roadmap.md`.

## License

MIT License.

## Contributing

Keep runtime requirements minimal, avoid database dependencies, preserve the flat-file content model, and update tracked documentation when implementation status changes.
