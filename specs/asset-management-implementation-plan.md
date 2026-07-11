# Asset And Library Management Implementation Plan

## Tasks

- [x] Document the storage contract, compatibility rules, security boundaries, and acceptance checks.
- [x] Add typed physical storage for images, documents, multimedia, custom styles, and custom scripts.
- [x] Preserve discovery and serving of existing flat `radpress/content/media/*` assets.
- [x] Add safe recursive `/assets/{path}` delivery with correct MIME and cache headers.
- [x] Update Admin Media to list, filter, copy, view, and delete typed assets.
- [x] Add validated, versioned library ZIP installation with a required `library.json` manifest.
- [x] Add library enable/disable and removal controls restricted to owner/admin roles.
- [x] Automatically load enabled global library CSS and JavaScript entry points in public theme output.
- [x] Include typed assets, complete library dependency trees, and legacy media in static export packages.
- [x] Add focused asset/library, security, role-access, static-export, and smoke regression coverage.
- [ ] Verify the updated Media workflow in the testsite browser.
- [x] Commit the implementation and synchronize the local testsite checkout.

## Goal

Replace the flat media-only upload model with an integrated asset system that can safely organize ordinary files and preserve complete third-party library packages without breaking existing installations.

## Storage Contract

New uploads are classified by extension and stored under:

```text
radpress/content/assets/
├── images/YYYY/MM/
├── documents/YYYY/MM/
├── multimedia/audio/YYYY/MM/
├── multimedia/video/YYYY/MM/
├── styles/custom/
├── scripts/custom/
└── libraries/{library}/{version}/
```

Existing files under `radpress/content/media/` remain available from `/media/{file}` and remain visible in Admin. No automatic migration or URL rewrite is performed because existing page and template references must stay valid.

New files are served from `/assets/{relative-path}`. Generated filenames remain unique and immutable so long-lived public caching is safe.

## Library Package Contract

A library is uploaded as a ZIP containing `library.json` at its root. Package paths are preserved so CSS `url(...)`, fonts, images, maps, and companion scripts continue to resolve.

Example manifest:

```json
{
  "name": "swiper",
  "version": "12.0.2",
  "enabled": true,
  "scope": "global",
  "styles": ["swiper-bundle.min.css"],
  "scripts": [
    {
      "file": "swiper-bundle.min.js",
      "defer": true
    }
  ]
}
```

Rules:

- `name` is a lowercase slug and `version` is a path-safe version identifier.
- A library version cannot silently overwrite an installed version.
- Enabling a library version disables other installed versions of the same library name while retaining them for rollback.
- Entry paths must be relative, traversal-free, and present in the ZIP.
- Styles must reference `.css`; scripts must reference `.js` or `.mjs`.
- PHP, HTML, shell files, executables, absolute paths, traversal paths, links, and unsupported package contents are rejected.
- Extracted package size and file count are bounded.
- Enabled global styles load in `<head>` and scripts load before `</body>`.
- `defer`, `async`, `type="module"`, `integrity`, and `crossorigin` are supported when declared safely.
- Library changes are audited and owner/admin-only.

## Admin Experience

The Media screen provides two distinct workflows:

1. Upload asset: ordinary images, documents, audio, video, CSS, and JavaScript are classified automatically.
2. Install library: a versioned ZIP with a manifest is validated and installed as one dependency-preserving unit.

The asset list shows the relative storage path, type, size, modification time, public URL, embed snippet, and actions. The library list shows name, version, status, scope, declared entry points, and governance actions.

## Static Export

Static export recursively copies:

- `radpress/content/assets/**` to `assets/**`, excluding private catalog state if introduced later.
- `radpress/content/media/*` to `media/*` for compatibility.

Enabled global library tags are included in generated HTML. Package verification expects every exported asset file at its preserved relative path.

## Security Boundaries

- Ordinary uploads continue through the extension and size allowlist.
- Library ZIP inspection occurs before installation and extraction is performed entry-by-entry.
- Public asset resolution rejects empty paths, absolute paths, null bytes, dot segments, traversal, directories, and paths outside the asset root.
- Executable server files are never accepted into asset or library storage.
- Media and asset responses include `X-Content-Type-Options: nosniff`.
- Editors can manage ordinary assets; only owners/admins can install, enable, disable, or remove executable library packages.

## Acceptance

- New image, document, audio, video, CSS, JS, and MJS uploads resolve to their documented directories.
- Existing `/media/...` URLs continue working unchanged.
- A valid library ZIP installs with its complete directory tree and renders declared global tags.
- Unsafe, malformed, duplicate, or incomplete libraries are rejected without partial installation.
- Static export preserves both typed paths and library-relative dependencies.
- Admin actions remain base-path compatible in the testsite installation.

## Deferred

- Fetching packages from CDNs or package registries.
- Automatic downloading of remote dependencies.
- Per-page library activation and dependency graph resolution.
- Asset reference indexing across arbitrary custom PHP templates.
- Image transformation, transcoding, and video streaming optimization.

## Verification Notes

- Repository and synchronized testsite regression suites pass for asset libraries, media UI rendering, security, roles, ownership, static export, smoke routes, theme syntax, and updates.
- Browser verification remains open because browser control was stopped by the browser security policy before the synchronized Media page could be reloaded. No alternate browser-control path was used to bypass that restriction.
