# Theme Development

## Tasks

- [x] Identify the Batoi Press public shell owner.
- [x] Identify the Batoi Press admin shell owner.
- [x] Document where shared header and footer changes belong.
- [x] Document what should remain page-specific.
- [x] Document verification steps for shell changes.
- [x] Link to the canonical `batoi-www` resource documentation.

Themes live under:

```text
radpress/theme/{theme-name}/
```

The default theme uses PHP layouts:

```text
base.php
page.php
post.php
blog.php
archive.php
404.php
```

Theme layouts receive sanitized content and metadata from the engine plus normalized `$theme` and `$branding` contexts.

## Manifest And Assets

`theme.json` uses schema version 1. Existing minimal manifests remain compatible. Themes can declare ordered CSS and JavaScript entry points below their own `assets/` directory:

```json
{
  "schema": 1,
  "slug": "example",
  "name": "Example",
  "version": "1.0.0",
  "author": "Example Studio",
  "supports": ["pages", "posts", "menus", "seo", "brand_logo"],
  "assets": {
    "styles": [{"file": "css/theme.css", "media": "all"}],
    "scripts": [{"file": "js/theme.js", "defer": true}]
  }
}
```

Bundled files resolve from `/theme-assets/{theme}/{path}`. Use `bp_theme_asset('images/example.webp')` inside a template instead of calculating filesystem or public paths. Keep site-owned logos and content images in the typed site asset store, and keep reusable third-party packages in Media Libraries.

## Template Context

`$branding` includes `display`, `site_name`, `logo_url`, `logo_alt`, and `favicon_url`. A theme should support `text`, `logo`, and `logo_with_text` display modes and fall back to the site name when no valid logo is available.

`$theme` includes normalized manifest metadata, declared assets, validation status, and errors. The renderer injects declared asset tags; layouts should not duplicate those entry points.

The Admin Themes preview supports home, page, post, blog, archive, and 404 layouts without activating the candidate theme. Static export uses the same renderer and copies active-theme assets to matching public paths.

## Header and Footer Ownership

Use the canonical Batoi header/footer knowledge base at
`https://www.batoi.com/resources/docs/press/header-footer-standards` for shared
Batoi brand, public navigation, footer, and admin shell standards. Keep this
repository focused on Batoi Press-specific file ownership.

For Batoi Press public pages:

- Edit `radpress/theme/default/layouts/base.php` for the shared public page
  shell, including the document head and UIF asset loading.
- Edit `radpress/theme/default/partials/header.php` for the public header.
- Edit `radpress/theme/default/partials/footer.php` for the public footer.
- Use `/admin/theme-templates` when header/footer changes should be managed
  through the admin console.
- Edit `page.php`, `post.php`, `blog.php`, `archive.php`, or `404.php` only
  for page-type content structure.
- Do not duplicate global header or footer markup inside content files,
  controllers, or individual page/post bodies.

For Batoi Press admin pages:

- Edit `radpress/admin/AdminLayout.php` for the shared admin shell, topbar,
  sidebar navigation, admin header actions, icon rendering, and admin UIF asset
  loading.
- Edit individual admin controllers only for page-specific panels, forms,
  tables, and actions.
- Keep logout, account, update, and view-site actions in the topbar rather than
  in the lower sidebar.

After changing public or admin shell files, run:

```sh
php -l radpress/admin/AdminLayout.php
php radpress/tests/smoke.php
```

Also inspect the admin first viewport and sidebar in a browser when layout or
navigation behavior changes.
