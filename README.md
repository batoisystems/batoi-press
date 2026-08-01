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
- Governed editorial workflows for pages, posts, media, professional menus, settings, users, machine connections, security, cache, static export, updates, audit log, and integrated Batoi AIF.
- Searchable admin lists for pages, posts, media, users, and audit review.
- Browser installer at `/install.php`; after installation it creates `radpress/config/installed.lock`.
- Cache clear, static export, and update-check admin surfaces.
- Bundled Batoi UIF primitives for admin and installer UI.
- Integrated, opt-in Batoi AIF authoring assistance with a private offline provider, bounded context, explicit human review, and no publish authority.
- Scoped JSON API at `/api/v2` with safe reads, revisioned/idempotent page/post draft writes, explicit publishing, filtering, cursor pagination, stable errors, ETags, and request correlation.
- Streamable HTTP MCP endpoint at `/mcp` with governed reads/writes, citation-ready search/fetch results, personal tokens, and an external-OAuth resource-server boundary.
- TOTP MFA and recovery, encrypted secrets, session inventory/revocation, security headers, upload signature validation, and signed releases.

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
- `radpress/aif/` contains the integrated AIF service, guarded provider adapters, and assisted-authoring actions.
- `radpress/theme/` stores render templates and theme assets.

Empty `bin/`, `ms/`, and `vendor/` directories are intentionally omitted from MVP. Add them only when a concrete CLI, RAD module-service, or bundled dependency requirement exists.

## Content Format

Each page or post is stored as a directory containing:

```text
meta.json
body.html
```

This keeps metadata structured and content human-readable.

## Stable Contract

Batoi Press 2.0 preserves the stable content, route, theme, installer-lock, and deployment contracts established in 1.0 while adding compatible workflow metadata and signed update trust.

## Security Notes

Batoi Press uses password hashing, TOTP MFA, encrypted secrets, bounded file-backed sessions with inventory/revocation, CSRF tokens, rate limiting, MIME/signature upload validation, browser security headers, audit logs, Ed25519-signed updates, and installer locking through `radpress/config/installed.lock`.

## Admin Setup

The installer creates the first owner user in `radpress/config/users.json`. After installation, use `/admin/login` to manage pages, posts, media, menus, settings, users, cache, static export, and updates.

## Admin Console

The admin console uses bundled Batoi UIF assets and a persistent business console layout with grouped navigation:

- Overview: dashboard.
- Publish: pages, posts, media, menus.
- Site: settings, static export, cache.
- Governance: users, updates, audit log.
- Intelligence: Batoi AIF.

Pages and posts use structured list screens, publication badges, preview links, and editor panels for content, publishing, and SEO. Pages can select manifest-declared layouts, with bundled templates for standard content, landing pages, contact pages, and presentation-ready shop, product, cart, checkout, and customer-account journeys. Media organizes images, documents, multimedia, custom styles, and scripts into typed paths while preserving existing `/media/` URLs. Owners and admins can install versioned frontend library ZIPs with dependency-preserving manifests, activation controls, and automatic public CSS/JS loading. Menus use stable item IDs, typed links, validated hierarchy, revision checks, drag/drop and button reordering, structural preview, theme-declared primary/footer locations, dropdowns, nested child menus, and responsive mega menus; the legacy `Label|/url` import remains available. Settings are grouped by identity, branding, URL, localization, editor configuration, and theme; branding supports text, logo, and logo-plus-text public header modes. Theme management supports validated manifests, bundled assets, activation, multi-layout preview, upload/upgrade, and constrained template editing. Users show roles, creation dates, account status, filtering, edit flows, password reset, and disable/reactivate controls with owner safeguards.

Admin routes are role-aware. Owners and admins have full access. Editors can manage pages, posts, media, menus, and Batoi AIF assist. Authors can use post workflow routes for posts assigned to their username. Viewers can access only the dashboard. Blocked route and post ownership attempts return a 403 page and are written to the audit log.

Operations are separated from publishing work. Static Export renders through the active theme and creates verified downloadable ZIP packages containing the public shell, branding, application assets, theme assets, typed assets, complete library dependency trees, and legacy media at their stable public paths. Cache explains safe maintenance actions and runtime directory status. Updates expose version status, stable manifest, package staging, backup creation, staged packages, and rollback backups. Audit Log provides paginated search, filters, CSV/JSONL export, and retention cleanup with a 90-day minimum while recording authenticated admin views, actions, downloads, semantic changes, outcomes, and safe request details.

## Installer Lifecycle

After a successful install, `radpress/config/installed.lock` disables the installer. Remove that lock manually only when intentionally running setup again on a controlled installation.

## Updates

The default stable update manifest is:

```text
https://www.batoi.com/pub/press/latest.json
```

Batoi Press can authenticate the Ed25519-signed release index and internal package manifest, verify the ZIP and every installable checksum, stage a package, create a backup, apply manifest-listed files in maintenance mode, clear cache, run health checks, roll back automatically after failed checks, and restore manually from a selected backup ZIP.

## Release Packages

Release ZIPs should include `public_html/`, `radpress/`, `README.md`, and `LICENSE`, while excluding generated runtime files such as sessions, cache, backups, exports, logs, and `radpress/config/installed.lock`. See `radpress/docs/installation.md` for package notes.

Every repository release increments `radpress/config/update.json`. The resulting verified ZIP is attached to the matching GitHub release and published through the stable update manifest.

The generated files are:

```text
dist/batoi-press-{version}.zip
dist/latest.json
dist/latest.json.sig
dist/release-public-keys.json
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

Official packages are attached to versioned GitHub releases and mirrored through the stable update manifest. Check the published SHA-256 value before installing a package.

## Theme Development

Themes live under `radpress/theme/{theme-name}/` with PHP layouts and optional bundled files below `assets/`. The active theme is configured in `radpress/config/site.json`; declared theme files are served from `/theme-assets/{theme}/{path}`. See `radpress/docs/theme-development.md` for the manifest and template-context contracts.

The bundled Batoi Versatile theme supports corporate, service, editorial, campaign, and ecommerce presentation pages. Ecommerce templates provide the public experience layer; inventory, payments, tax, shipping, customer authentication, and order processing require an external integration or a future commerce module.

## Batoi UIF and AIF

Batoi UIF primitives are bundled locally under `public_html/assets/uif/` and documented in `radpress/docs/uif-aif.md`.

Batoi AIF is the integrated, opt-in outbound intelligence layer. It is disabled by default through `radpress/config/aif.json`; an owner can enable the bundled offline local provider for content health, SEO, summary, tag, and outline assistance without sending content over the network. External providers remain disabled until explicitly configured and approved. Page and post editors require human review before applying suggestions, and AIF has no publication authority. The inbound JSON API and MCP interface remain provider-neutral and do not call an AI model. See `radpress/docs/uif-aif.md` and `radpress/docs/machine-interfaces.md` for configuration, security, and protocol contracts.

## Roadmap

See `radpress/docs/roadmap.md`.

## License

MIT License.

## Contributing

Keep runtime requirements minimal, avoid database dependencies, preserve the flat-file content model, and update tracked documentation when implementation status changes.
