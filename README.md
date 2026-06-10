# Batoi Press

Batoi Press is a secure flat-file CMS and publishing engine aligned with Batoi RAD. It is designed for standard PHP hosting, FTP deployment, cPanel-style `public_html` web roots, and database-free website publishing.

## Features In This Build

- Configurable `public_html` web root.
- RAD-aligned engine under `radpress/`.
- JSON configuration.
- HTML page and post bodies with adjacent JSON metadata.
- Default theme.
- Public routes for `/`, `/about`, `/blog`, `/blog/first-blog-post`, `/sitemap.xml`, and `/feed.xml`.
- Authenticated admin dashboard at `/admin`.
- Update status surface at `/admin/updates`.
- Browser installer at `/install.php`; after installation it creates `radpress/config/installed.lock`.
- Cache clear, static export, and update-check admin surfaces.

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

## Installer Lifecycle

After a successful install, `radpress/config/installed.lock` disables the installer. Remove that lock manually only when intentionally running setup again on a controlled installation.

## Updates

The default stable update manifest is:

```text
https://batoi.com/pub/press/latest.json
```

Batoi Press can check the manifest, verify and stage a package, create a backup, apply manifest-listed files in maintenance mode, clear cache, run health checks, roll back automatically after failed checks, and restore manually from a selected backup ZIP.

## Release Packages

Release ZIPs should include `public_html/`, `radpress/`, `README.md`, and `LICENSE`, while excluding generated runtime files such as sessions, cache, backups, exports, logs, and `radpress/config/installed.lock`. See `radpress/docs/installation.md` for package notes.

Build a local release ZIP with:

```sh
php specs/build-release.php
```

## Theme Development

Themes live under `radpress/theme/{theme-name}/` with PHP layouts and optional assets. The active theme is configured in `radpress/config/site.json`.

## Roadmap

See `radpress/docs/roadmap.md`.

## License

MIT License.

## Contributing

Keep runtime requirements minimal, avoid database dependencies, preserve the flat-file content model, and update `specs/batoi-press-blueprint.md` when implementation status changes.
