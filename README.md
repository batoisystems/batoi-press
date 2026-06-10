# Batoi Press

Batoi Press is a secure flat-file CMS and publishing engine aligned with Batoi RAD. It is designed for standard PHP hosting, FTP deployment, cPanel-style `public_html` web roots, and database-free website publishing.

## Features In This Build

- Configurable `public_html` web root.
- RAD-aligned engine under `radpress/`.
- JSON configuration.
- HTML page and post bodies with adjacent JSON metadata.
- Default theme.
- Public routes for `/`, `/about`, `/blog`, `/blog/first-blog-post`, `/sitemap.xml`, and `/feed.xml`.
- Read-only Phase 1 admin dashboard at `/admin`.
- Update status surface at `/admin/updates`.
- Installer status page at `/install.php`, disabled by `radpress/config/installed.lock`.

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

## Directory Model

- `public_html/` is the browser-facing web root.
- `radpress/` is the private RAD-aligned application root.
- `radpress/core/` contains the reusable Batoi Press engine.
- `radpress/app/` is reserved for site-level modules and hooks.
- `radpress/config/` stores JSON configuration.
- `radpress/content/` stores page/post HTML bodies and JSON metadata.
- `radpress/data/` stores cache, logs, sessions, backups, versions, and exports.
- `radpress/theme/` stores render templates and theme assets.

## Content Format

Each page or post is stored as a directory containing:

```text
meta.json
body.html
```

This keeps metadata structured and content human-readable.

## Updates

The default stable update manifest is:

```text
https://batoi.com/press/latest.json
```

Automated installation is intentionally deferred until backup, staging, package verification, and rollback are implemented.

## License

MIT License.
