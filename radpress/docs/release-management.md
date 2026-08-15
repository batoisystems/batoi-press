# Release Management

Batoi Press uses explicit release versions. Every repository release must increment the installed version in `radpress/config/update.json` before building the release package or publishing the stable manifest.

## Version Policy

Use semantic versioning until `1.0.0`:

- Patch release: bug fixes, documentation corrections, small visual corrections, and low-risk compatibility fixes.
- Minor release: new admin capabilities, new workflows, theme changes, update-system improvements, bundled asset changes, or user-visible behavior changes that remain backward compatible.
- Major release: breaking changes to content format, theme contracts, public routes, update package format, installation layout, or minimum runtime requirements.

Pre-`1.0.0` releases may still change internal APIs, but public content files, theme templates, installer behavior, and update packages should remain backward compatible unless the release notes call out a migration.

From `1.0.0` onward, public content files, theme templates, installer behavior, public routes, and update package contracts are stable unless release notes document a migration.

## Release Checklist

- [ ] Decide the next version number from the version policy.
- [ ] Update `radpress/config/update.json`.
- [ ] Update user-facing documentation when behavior changes.
- [ ] Add release notes for the version.
- [ ] Run PHP syntax checks for changed PHP files.
- [ ] Run `php radpress/tests/smoke.php`.
- [ ] Run `php radpress/tests/update_runner.php` when update or release packaging changed.
- [ ] Build the package with the maintainer's local release tooling.
- [ ] Confirm the offline Ed25519 signing key is available only in private release storage.
- [ ] Generate the public manifest from the verified package.
- [ ] Verify package structure, version, checksum, and excluded runtime state.
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
- `0.9.0`: Repeatable security baseline checks for installer lock, deny rules, upload allowlists, CSRF, rate limiting, roles, and author ownership. See `radpress/docs/releases/v0.9.0.md`.
- `1.0.0`: Stable flat-file CMS baseline with documented compatibility commitments and verification evidence. See `radpress/docs/releases/v1.0.0.md` and `radpress/docs/stable-readiness.md`.
- `1.0.1`: Testing-team fixes for admin filters, Body HTML form controls, theme template saves, static export feedback, public menu rendering, and favicon display. See `radpress/docs/releases/v1.0.1.md`.
- `1.1.0`: Typed asset storage, legacy media compatibility, multimedia uploads, versioned frontend library packages, governed activation, public tag injection, and recursive static export. See `radpress/docs/releases/v1.1.0.md`.
- `1.2.0`: Site branding controls, versioned theme manifests, bundled theme assets, hardened theme packages, multi-layout preview, and active-theme static export parity. See `radpress/docs/releases/v1.2.0.md`.
- `1.2.1`: Complete theme integration coverage and correct empty-site page/post preview fixtures. See `radpress/docs/releases/v1.2.1.md`.
- `1.2.2`: Align Users filters with the shared admin filter grid and normalize filter control heights. See `radpress/docs/releases/v1.2.2.md`.
- `1.2.3`: Keep the Static Export action clickable while reporting server capability and permission failures after submission. See `radpress/docs/releases/v1.2.3.md`.
- `1.3.0`: Long-form editor focus mode, sticky tools, integrated Media guidance, and clarified HTML/Markdown behavior. See `radpress/docs/releases/v1.3.0.md`.
- `1.4.0`–`1.4.6`: Expanded Batoi Versatile layouts plus focused theme, branding, media, and template-editor reliability fixes. See the corresponding files under `radpress/docs/releases/`.
- `1.5.0`: Testing-team workflow fixes for safe embedded HTML, theme duplication, password recovery, update staging, scheduled publication dates, and homepage selection. See `radpress/docs/releases/v1.5.0.md`.
- `1.6.0`–`1.6.1`: Theme integration, static-export, theme-editor, and featured-image accessibility improvements. See the corresponding files under `radpress/docs/releases/`.
- `1.7.0`: Hierarchical content, improved authoring for LaTeX and code, protected special-page copy editing, responsive nested navigation, and desktop update ZIP support. See `radpress/docs/releases/v1.7.0.md`.
- `1.8.0`: Testing-team follow-up for resilient update checks, actionable ZIP diagnostics, configurable latest homepage posts, creatable Contact layouts, and corrected full-width mobile navigation. See `radpress/docs/releases/v1.8.0.md`.
- `2.0.0`: Governed JSON API/MCP automation, integrated Batoi AIF, professional multi-location navigation, editorial scheduling, MFA/session hardening, OAuth resource verification, and signed releases. See `radpress/docs/releases/v2.0.0.md`.
- `2.1.0`: Batoi UIF 3.0.0, governed theme compatibility inspection, and validated timezone selection. See `radpress/docs/releases/v2.1.0.md`.

## Package Trust Metadata

Version 2.0 stable packages require Ed25519 trust metadata:

```json
{
  "trust": {
    "signature_required": true,
    "signature_algorithm": "Ed25519",
    "key_id": "batoi-press-release-2026-01",
    "signed_payload": "release-index",
    "signature": "base64-signature",
    "signature_url": "https://www.batoi.com/pub/press/latest.json.sig",
    "public_key_url": "https://www.batoi.com/pub/press/release-public-keys.json"
  }
}
```

The ZIP contains its own signed `release.json`, covering the version and ordered
installable-file checksums. `latest.json` is independently signed and retains
the ZIP SHA-256 checksum. Generate the offline key once with
`php tools/generate-release-signing-key.php`; it refuses to replace an existing
key and stores it below excluded `radpress/data/security/`. Build, manifest,
detached signature, public-key document, and artifact verification all fail
closed if the expected key or signature is unavailable. Rotate keys by adding
a new key ID while retaining old public keys for supported releases.

## Stable 1.0 Checklist

The `1.0.0` stable decision is recorded in `radpress/docs/stable-readiness.md`. Remaining unchecked items below should be treated as ongoing `1.x` quality tracking unless a future release explicitly reclassifies them as blockers.

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
