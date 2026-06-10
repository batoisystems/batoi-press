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
- Installer status page at `/install.php`, disabled by `config/installed.lock`.

## Requirements

- PHP 8.1 or newer.
- Apache with rewrite support for clean URLs, or query-string fallback through `index.php?route=/about`.
- No database, Node.js, Docker, Composer, Git, or CLI access is required for runtime.

## Local Run

```sh
php -S 127.0.0.1:8080 -t public_html
```

Then open:

- `http://127.0.0.1:8080/`
- `http://127.0.0.1:8080/about`
- `http://127.0.0.1:8080/sitemap.xml`

## Directory Model

- `public_html/` is the browser-facing web root.
- `radpress/` contains the reusable Batoi Press engine.
- `app/` is reserved for site-level modules and hooks.
- `config/` stores JSON configuration.
- `content/` stores page/post HTML bodies and JSON metadata.
- `storage/` stores cache, logs, sessions, backups, versions, and exports.
- `themes/` stores render templates and theme assets.

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

