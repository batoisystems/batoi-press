# Theme Development

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
landing.php
shop.php
product.php
cart.php
checkout.php
account.php
contact.php
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

Themes may declare additional page templates in the manifest. Existing themes without this field continue to expose the standard page template.

```json
"page_templates": {
  "page": {"label": "Standard Page", "layout": "page"},
  "landing": {"label": "Landing Page", "layout": "landing"},
  "shop": {"label": "Shop / Collection", "layout": "shop"}
}
```

Template keys and layout names use lowercase letters, numbers, underscores, and hyphens. Declared layouts must exist below `layouts/`. Missing or unsupported selections fall back to `layouts/page.php`.

Bundled files resolve from `/theme-assets/{theme}/{path}`. Use `bp_theme_asset('images/example.webp')` inside a template instead of calculating filesystem or public paths. Keep site-owned logos and content images in the typed site asset store, and keep reusable third-party packages in Media Libraries.

## Template Context

`$branding` includes `display`, `site_name`, `logo_url`, `logo_alt`, and `favicon_url`. A theme should support `text`, `logo`, and `logo_with_text` display modes and fall back to the site name when no valid logo is available.

`$theme` includes normalized manifest metadata, declared assets, validation status, and errors. The renderer injects declared asset tags; layouts should not duplicate those entry points.

The Admin Themes preview supports home, standard, landing, ecommerce, post, blog, archive, and 404 layouts without activating the candidate theme. Static export uses the same page-template resolver and copies active-theme assets to matching public paths.

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
- Use `bp_is_current_url($url)` to add an `is-active` class and
  `aria-current="page"` to the current menu link. It also treats a parent route
  as active on nested child routes and accounts for subdirectory installations.
- Use the Contact Layout editor for theme-owned contact form handling. Keep
  validation, CSRF protection, rate limiting, and output escaping in place; do
  not place credentials in theme source.
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
