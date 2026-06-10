# Batoi Press 0.1.0

Initial public release of Batoi Press, a secure flat-file CMS and publishing engine aligned with Batoi RAD.

## Highlights

- RAD-aligned private `radpress/` application root and public `public_html/` web root.
- Browser installer with owner account creation and installer lock.
- Authenticated admin for pages, posts, media, menus, settings, users, cache, static export, and updates.
- JSON configuration with HTML content bodies and adjacent metadata.
- Secure upload handling with public `/media/{file}` serving and image insertion snippets.
- SEO title and description rendering, sitemap XML, and RSS feed.
- Static export ZIP generation.
- Governed update flow with manifest check, backup, staging, guarded apply, maintenance mode, health checks, cache clear, rollback, and recovery docs.
- Release package builder: `php specs/build-release.php`.

## Release Asset

Attach:

```text
dist/batoi-press-0.1.0.zip
```

## Verification

- PHP syntax check over `public_html`, `radpress`, and `specs`.
- `php radpress/tests/smoke.php`
- `php radpress/tests/update_runner.php`
- Release ZIP excludes generated runtime files.
