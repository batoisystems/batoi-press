# Theme, Branding, And Theme Asset Implementation Plan

## Tasks

### Completed Baseline

- [x] Add an Admin Console Themes page with installed-theme discovery.
- [x] Allow owner/admin users to upload, preview, activate, edit, snapshot, and restore approved theme files.
- [x] Keep legacy `/admin/theme-templates` URLs working.
- [x] Add basic theme ZIP path, extension, and required-layout validation.
- [x] Add typed site assets and versioned frontend-library management.

### Phase 1: Contracts And Shared Services

- [x] Define and validate a versioned `theme.json` schema for metadata, capabilities, bundled assets, and template requirements.
- [x] Add a `ThemeManager` service that resolves the active theme, validates manifests, resolves safe bundled-asset paths, and exposes normalized theme metadata.
- [x] Add a `BrandAssetManager` service for logo/favicon upload, validation, replacement, removal, URL resolution, and orphan cleanup.
- [x] Add base-path-aware helpers for site assets and theme assets instead of resolving public paths directly inside templates.
- [x] Preserve compatibility with existing themes whose manifests contain only `name`, `version`, `author`, and `supports`.

### Phase 2: Branding Settings

- [x] Extend Admin Settings with public brand-logo upload, preview, replacement, and removal controls.
- [x] Support safe SVG, PNG, WebP, JPG/JPEG, and GIF logo files with verified content, bounded dimensions, and a documented size limit.
- [x] Add display modes for `text`, `logo`, and `logo_with_text`, with accessible logo alt text and site-name fallback.
- [x] Store site-owned branding below `radpress/content/assets/images/site/` and save only normalized relative URLs in `site.json`.
- [x] Remove superseded favicon/logo files only after the new settings and file write complete successfully.
- [x] Audit logo upload, replacement, removal, and branding-setting changes.

### Phase 3: Theme Asset Delivery

- [x] Establish `assets/` inside each theme as the only public theme-bundle asset root.
- [x] Add a traversal-safe route such as `/theme-assets/{theme}/{path}` with MIME, length, cache, and `nosniff` headers.
- [x] Let manifests declare ordered stylesheet and script entry points, including safe `media`, `defer`, `async`, and module options.
- [x] Inject declared active-theme assets through the renderer without hardcoding theme CSS/JS in `layouts/base.php`.
- [x] Keep application-owned Batoi UIF/runtime assets separate from theme-owned presentation assets.
- [x] Show bundled asset counts, entry points, missing files, and validation state on the Themes screen.

### Phase 4: Rendering And Template Context

- [x] Pass a documented normalized `$theme` context to every layout and partial: slug, manifest, capabilities, assets, and asset URL helper.
- [x] Pass a documented normalized `$branding` context: display mode, site name, logo URL, logo alt text, and favicon metadata.
- [x] Update the default public header to render the uploaded logo according to display mode and fall back to the escaped site name.
- [x] Add responsive logo constraints that preserve intrinsic aspect ratio and prevent navigation displacement on mobile and desktop.
- [x] Keep theme templates free to place branding elsewhere while providing one consistent data contract.
- [x] Replace preview banner injection based on an exact body class with renderer-supported preview markup that works across valid themes.
- [x] Add preview targets for home, page, post, blog, archive, and 404 layouts, including missing-content fallback fixtures.

### Phase 5: Theme Package Hardening

- [x] Validate manifest JSON structure, schema version, slug, semantic version, capabilities, asset declarations, and required templates before installation.
- [x] Enforce archive file-count, per-file size, and total-extracted-size limits.
- [x] Reject links, encrypted entries, duplicate normalized paths, control characters, absolute paths, drive-letter paths, traversal, and executable server files.
- [x] Validate every declared asset entry exists below the theme `assets/` root and has an allowed type.
- [x] Extract entry-by-entry into a temporary directory and publish through an atomic rename only after complete validation.
- [x] Add safe upgrade behavior for an existing theme slug with backup and rollback rather than requiring manual replacement.
- [x] Prevent activation when the manifest or required runtime files are invalid.

### Phase 6: Static Export Parity

- [x] Render exported pages, posts, blog, archive, and 404 output through the active `Theme` renderer instead of the current minimal standalone HTML builder.
- [x] Copy active-theme bundled assets to stable exported paths and rewrite/localize theme-asset URLs for subdirectory-safe static hosting.
- [x] Copy configured brand logo and favicon assets and verify every generated branding reference exists in the ZIP.
- [x] Preserve typed site assets, legacy media, and enabled frontend-library dependency trees.
- [x] Verify exported navigation, branding, styles, scripts, favicon, canonical URLs, and nested page/post paths.

### Phase 7: Verification And Delivery

- [x] Add unit tests for manifest normalization, theme-asset resolution, branding validation, SVG safety, and path traversal rejection.
- [ ] Add integration tests for logo upload/replace/remove, theme package install/upgrade/activate, preview targets, and fallback behavior.
- [x] Expand static-export tests to assert rendered theme shell and all referenced asset files.
- [ ] Verify default and uploaded themes at desktop and mobile widths in the synchronized testsite.
- [ ] Verify text-only, logo-only, logo-with-text, missing-logo, malformed-logo, and long-navigation states.
- [x] Run PHP lint, smoke, role access, security baseline, theme syntax, static export, and update tests.
- [x] Update theme-development, admin, release-management, and package-author documentation.
- [x] Commit the implementation, synchronize the testsite, build and verify the next release, and update the neighboring `batoi-www` publication files.

## Objective

Turn themes into a complete presentation contract rather than a collection of editable PHP files. Site identity belongs to site configuration, bundled presentation assets belong to a theme, reusable third-party libraries remain in the library manager, and static output must match dynamic public rendering.

The immediate testing-team requirement is a public brand-logo upload that replaces or accompanies the site-name text in the header. The implementation must not hardwire that logo to one theme or one header layout.

## Current-State Review

### What Works

- `Theme` selects the configured theme and renders page-type layouts through a shared base layout.
- Owners and administrators can upload, preview, activate, edit, snapshot, and restore constrained theme files.
- Theme ZIPs reject basic path traversal and unsupported extensions.
- The default header reads the saved Main Menu.
- The favicon setting supports preview and validated upload.
- Typed site assets and managed frontend libraries have safe public routes and static-export support.

### Gaps And Risks

1. **No site-logo model.** `site.json` has no normalized logo path, display mode, or alt-text contract. The default header can render only the site-name string.
2. **No public theme-asset route.** Theme ZIPs may contain CSS, JavaScript, images, and fonts, but files below `radpress/theme/{slug}` have no supported browser URL.
3. **Hardcoded default assets.** The default `base.php` loads application CSS and JavaScript directly, so an uploaded theme cannot declare its own ordered entry points through its manifest.
4. **Weak manifest contract.** Theme installation checks required filenames but does not validate manifest fields, capabilities, declared assets, schema version, or runtime compatibility.
5. **Incomplete archive controls.** Theme ZIP installation lacks explicit file-count and extracted-size limits, duplicate normalized-path detection, link detection, and atomic publication.
6. **Undocumented template context.** Templates rely on `$site` and incidental extracted variables; there is no normalized branding or theme context.
7. **Brittle preview injection.** Preview mode replaces one exact `<body class="bp-public-body">` string and can silently fail for uploaded themes.
8. **Static export diverges from the site.** `StaticExporter::html()` builds minimal HTML instead of using the active theme, so headers, footers, navigation, favicon, logo, theme CSS/JS, and layout behavior are absent.
9. **Asset ownership is unclear.** Site branding, theme-bundled files, editor-uploaded content assets, application runtime assets, and third-party libraries need explicit separate ownership.
10. **No visual state matrix.** Current tests do not cover logo aspect ratios, missing assets, long site names, long navigation, mobile wrapping, or alternate themes.

## Ownership And Storage Contract

| Asset class | Owner | Storage | Public URL |
| --- | --- | --- | --- |
| Site logo and favicon | Site configuration | `radpress/content/assets/images/site/` | `/assets/images/site/{file}` |
| Editor-uploaded content | Site content | Existing typed asset paths | `/assets/{path}` |
| Theme CSS, JS, images, fonts | Theme package | `radpress/theme/{slug}/assets/` | `/theme-assets/{slug}/{path}` |
| Reusable third-party libraries | Library manager | `radpress/content/assets/libraries/{name}/{version}/` | `/assets/libraries/{name}/{version}/{path}` |
| Batoi Press/UIF runtime files | Application release | `public_html/assets/` | `/assets/{application-path}` |

Theme activation must not copy files into `public_html` or the site-content store. This keeps theme upgrades atomic and avoids stale files from previously active themes.

## Site Branding Contract

The proposed additive `site.json` shape is:

```json
{
  "name": "Example Company",
  "brand_display": "logo",
  "brand_logo": "/assets/images/site/logo-7f21c4.png",
  "brand_logo_alt": "Example Company",
  "favicon": "/assets/images/site/favicon-2c78a1.ico",
  "theme": "default"
}
```

Rules:

- Existing installations default to `brand_display: "text"` when no value is present.
- `brand_logo` must be an application-relative URL resolved through the asset service, never an arbitrary filesystem path or remote URL.
- `brand_logo_alt` defaults to the site name and remains required when a logo is displayed.
- Missing or unreadable logo files fall back to site-name text without emitting a broken image.
- Uploads receive unique immutable filenames. Replacement updates configuration first and removes the previous owned file afterward.
- SVG logos use the existing active-content safeguards, strengthened to reject external references and unsafe XML constructs.

## Theme Manifest Contract

Existing minimal manifests remain valid and normalize to schema version 1 with empty asset lists. A richer manifest may declare:

```json
{
  "schema": 1,
  "slug": "corporate",
  "name": "Corporate",
  "version": "1.2.0",
  "author": "Example Studio",
  "supports": ["pages", "posts", "menus", "seo", "brand_logo"],
  "assets": {
    "styles": [
      {"file": "css/theme.css", "media": "all"}
    ],
    "scripts": [
      {"file": "js/theme.js", "defer": true}
    ]
  }
}
```

All declared files resolve below the package's `assets/` directory. PHP templates cannot calculate filesystem paths for browser assets; they receive a safe theme-asset URL helper.

## Rendering Contract

Every layout receives the current content variables plus:

```php
$branding = [
    'display' => 'logo',
    'site_name' => 'Example Company',
    'logo_url' => '/assets/images/site/logo-7f21c4.png',
    'logo_alt' => 'Example Company',
    'favicon_url' => '/assets/images/site/favicon-2c78a1.ico',
];

$theme = [
    'slug' => 'corporate',
    'manifest' => [...],
    'styles' => [...],
    'scripts' => [...],
];
```

The renderer owns declared asset-tag injection and preview markup. Templates own visual placement and may call a safe helper such as `bp_theme_asset('images/hero.webp')` for additional bundled files.

## Admin Experience

### Settings

- Branding section shows the effective site identity, current logo, current favicon, and fallback state.
- Logo and favicon have separate file controls and separate remove actions.
- A segmented display-mode control chooses Text, Logo, or Logo + text.
- Logo preview uses the same constraints as the default public header.
- Validation errors return to the complete Settings form with the submitted non-file values preserved.

### Themes

- Each theme shows schema/version, capabilities, required-template status, bundled asset count, and validation state.
- Preview allows selecting a route type and uses the candidate theme without mutating `site.json`.
- Invalid themes remain visible for diagnosis but cannot be activated.
- Theme source editing stays constrained; arbitrary theme filesystem browsing is not introduced.

## Security Requirements

- Keep owner/admin authorization and CSRF checks for theme and branding changes.
- Verify extension and actual content for branding images.
- Apply strict SVG sanitization and never serve uploaded SVG with active content.
- Resolve all asset paths through realpath containment checks.
- Return `X-Content-Type-Options: nosniff` and explicit MIME types.
- Do not permit theme packages to contain `.htaccess`, PHP outside approved templates, executable binaries, symlinks, or server configuration.
- Bound ZIP file count, individual size, and total extracted size before publication.
- Never load remote scripts or styles solely because a manifest declares them.
- Escape text and attribute output through the existing helpers.

## Migration And Compatibility

- Missing branding keys preserve current site-name rendering.
- Existing favicon paths under `/assets/site/` continue to resolve; new writes use the typed site-image location.
- Existing themes without schema or assets remain installable after normalization if their required layouts pass validation.
- Existing template variables remain available during the 1.x line.
- The default theme adopts the new context first and remains the compatibility reference.
- No automatic relocation of content assets or third-party libraries is required.

## Acceptance Criteria

- An administrator can upload a valid logo, choose Logo mode, save, and see the logo replace the public header site-name text.
- Text and Logo + text modes render correctly with accessible names.
- A missing or deleted logo falls back to the site name without broken markup.
- An uploaded theme can load its bundled CSS, JavaScript, images, and fonts through documented theme-asset URLs.
- Unsafe theme and branding files are rejected without partial writes.
- Theme preview works regardless of body classes and can preview all supported route types.
- Dynamic and static output use the same theme shell, branding, navigation, styles, scripts, and favicon.
- Static export contains every locally referenced site, theme, library, and legacy media asset.
- Existing minimal themes and existing installations continue to render without configuration migration.

## Deferred

- Remote theme marketplace installation.
- Automatic CDN fetching or npm/Composer dependency resolution.
- Arbitrary end-user theme option schemas and visual page builders.
- Image cropping, responsive rendition generation, and SVG optimization.
- Per-page theme selection.
- Editing arbitrary bundled binary assets in the Admin Console.
