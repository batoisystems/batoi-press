# Stable Readiness

Batoi Press `1.0.0` is stable for the documented flat-file CMS scope.

## Stable Product Surface

- Public rendering for pages, blog, posts, media, sitemap, and RSS feed.
- Admin workflows for pages, posts, media, menus, settings, themes, users, audit log, cache, static export, updates, and Batoi AIF status.
- Browser installer with owner-account creation and `radpress/config/installed.lock`.
- Release ZIP and public `latest.json` manifest workflow.
- PHP-only runtime suitable for standard hosting and FTP deployment.

## Stable Contracts

- Content format: each page or post uses `meta.json` and `body.html`.
- Public routes: page slugs, `/blog`, `/blog/{slug}`, `/media/{file}`, `/sitemap.xml`, and `/feed.xml`.
- Theme ownership: templates and theme assets live under `radpress/theme/`.
- Update model: packages are staged, manifest-listed files are applied, backups are created, maintenance mode is used, cache is cleared, and rollback is available.
- Release artifacts: `dist/batoi-press-{version}.zip` and `dist/latest.json`.

## Verification Evidence

Use these commands before publishing a stable release:

```text
php radpress/tests/smoke.php
php radpress/tests/update_runner.php
php radpress/tests/static_export.php
php radpress/tests/role_access.php
php radpress/tests/post_ownership.php
php radpress/tests/security_baseline.php
php tools/build-release.php
php tools/generate-release-manifest.php
php tools/verify-release-artifacts.php
```

## Deferred Items

The following items are candidates for `1.x` releases:

- Mandatory signed package enforcement.
- Optional media copying in static export packages.
- Per-page ownership rules.
- Multi-stage editorial approvals.
- Formal browser screenshot matrix for every role and viewport.

These are not required for the `1.0.0` stable declaration because the current release scope, compatibility commitments, and local verification commands are documented.
