# Release Management

Batoi Press uses explicit release versions. Every repository release must increment the installed version in `radpress/config/update.json` before building the release package or publishing the stable manifest.

## Version Policy

Use semantic versioning until `1.0.0`:

- Patch release: bug fixes, documentation corrections, small visual corrections, and low-risk compatibility fixes.
- Minor release: new admin capabilities, new workflows, theme changes, update-system improvements, bundled asset changes, or user-visible behavior changes that remain backward compatible.
- Major release: breaking changes to content format, theme contracts, public routes, update package format, installation layout, or minimum runtime requirements.

Pre-`1.0.0` releases may still change internal APIs, but public content files, theme templates, installer behavior, and update packages should remain backward compatible unless the release notes call out a migration.

## Release Checklist

- [ ] Decide the next version number from the version policy.
- [ ] Update `radpress/config/update.json`.
- [ ] Update user-facing documentation when behavior changes.
- [ ] Add release notes for the version.
- [ ] Run PHP syntax checks for changed PHP files.
- [ ] Run `php radpress/tests/smoke.php`.
- [ ] Run `php radpress/tests/update_runner.php` when update or release packaging changed.
- [ ] Build the package with `php tools/build-release.php`.
- [ ] Generate the manifest with `php tools/generate-release-manifest.php`.
- [ ] Verify release artifacts with `php tools/verify-release-artifacts.php`.
- [ ] Verify `dist/batoi-press-{version}.zip` excludes generated runtime state.
- [ ] Verify `dist/latest.json` uses the same version and correct SHA-256 checksum.
- [ ] Commit the release changes.
- [ ] Tag the commit as `v{version}`.
- [ ] Publish the GitHub release and public Batoi manifest/package.

## Current Release Track

- `0.1.0`: Initial flat-file CMS, installer, public rendering, and admin foundation.
- `0.2.0`: Bundled Batoi UIF and disabled-by-default Batoi AIF scaffolding.
- `0.3.0`: Business-ready admin console, theme management, favicon handling, audit-log operations, static export/cache/user guidance, and admin standardization. See `radpress/docs/releases/v0.3.0.md`.
- `0.4.0`: Operational hardening with governed user lifecycle controls, owner safeguards, disabled-account authentication blocking, and release verification updates. See `radpress/docs/releases/v0.4.0.md`.
- `0.5.0`: Searchable admin operations, media filtering, release artifact verification, and optional package-trust metadata groundwork. See `radpress/docs/releases/v0.5.0.md`.
- `0.6.0`: Verified static export packages with page, post, blog, sitemap, feed, and media guidance checks. See `radpress/docs/releases/v0.6.0.md`.
- `0.7.0`: Role-aware admin access enforcement, blocked-route audit entries, and permission-filtered navigation. See `radpress/docs/releases/v0.7.0.md`.
- `0.8.0`: Author-owned post governance with post-list filtering, edit/save protection, and blocked ownership audit events. See `radpress/docs/releases/v0.8.0.md`.

## Package Trust Metadata

Stable public manifests may include package-trust fields before signed packages become mandatory:

```json
{
  "trust": {
    "signature_required": false,
    "signature_algorithm": null,
    "signature_url": null,
    "public_key_url": null
  }
}
```

For pre-`1.0.0` releases, `signature_required` stays `false` unless a release explicitly documents a signing rollout. Installations should continue to verify SHA-256 checksums and treat signature URLs as optional metadata until enforcement is enabled.

## Stable 1.0 Checklist

### Product Scope

- [ ] Pages, posts, media, menus, settings, themes, users, updates, audit log, cache, static export, and AIF status have stable admin workflows.
- [ ] Theme management supports activation, preview, upload, constrained template editing, snapshots, restore, and documented header/footer customization.
- [ ] Website favicon upload is stable, and admin favicon remains the Batoi Press icon.
- [ ] Content editor configuration is documented, including Batoi UIF rich HTML and source HTML modes.
- [ ] Public routes, content format, theme contracts, and release package format are documented as stable.

### Security And Governance

- [ ] Installer lock, password hashing, CSRF, sessions, rate limiting, upload allowlists, and Apache deny rules are verified.
- [ ] Audit log covers authenticated admin views, writes, downloads, update actions, exports, cleanup, user creation, media uploads, theme actions, and failed/blocked outcomes.
- [ ] Audit log supports pagination, search, filters, CSV/JSONL export, and cleanup with a minimum 90-day retention window.
- [ ] Secrets, passwords, CSRF tokens, and provider credentials are excluded from audit details and public release artifacts.
- [ ] Role behavior is documented, and user lifecycle gaps are either implemented or explicitly deferred.

### Updates And Releases

- [ ] Release version, Git tag, release ZIP, and public `latest.json` manifest match.
- [ ] Update staging rejects unsafe ZIP paths and unsupported package structure.
- [ ] Update apply uses manifest-listed files, creates backups, enables maintenance mode, clears cache, runs health checks, and rolls back on failure.
- [ ] Release package includes full bundled UIF files and Batoi Press branding assets.
- [ ] Release package excludes sessions, cache, exports, logs, backups, versions, temporary files, and installer lock state.
- [ ] Manual and GitHub Actions release paths are both documented and tested.

### Compatibility

- [ ] PHP 8.1+ compatibility is verified.
- [ ] Apache rewrite and query-string fallback routes are verified.
- [ ] Standard cPanel/FTP deployment is verified with `public_html/` and private `radpress/` layout.
- [ ] Fresh install and update-from-previous-release paths are verified.
- [ ] Static export package output is verified for pages, posts, blog, sitemap, feed, and media guidance.

### Quality Gate

- [ ] All changed PHP files pass `php -l`.
- [ ] Smoke tests pass.
- [ ] Update runner tests pass.
- [ ] Release build and manifest generation pass.
- [ ] Admin console pages have a professional layout, consistent typography, icon-backed buttons, adequate guidance, and selected left-sidebar navigation.
- [ ] Browser review passes for dashboard, publish pages, site pages, governance pages, AIF, editor screens, theme preview, and mobile/responsive states.
- [ ] Documentation links are current and no duplicate/obsolete release instructions remain.

## 1.0 Release Decision

Do not tag `v1.0.0` until the checklist above is complete or consciously converted into documented `1.x` follow-up issues. The `1.0.0` release should mean the content format, theme contract, update package contract, and deployment model are stable for normal production use.
