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
  "menu_locations": {
    "primary": "Primary navigation",
    "footer": "Footer navigation"
  },
  "assets": {
    "styles": [{"file": "css/theme.css", "media": "all"}],
    "scripts": [{"file": "js/theme.js", "defer": true}]
  }
}
```

`menu_locations` declares the named, independently revisioned menus available
to administrators. Location keys use lowercase letters, numbers, underscores,
and hyphens. Existing themes without the field retain a compatible `primary`
location backed by `content/menus/main.json`. The bundled theme also declares
`footer`, backed by `content/menus/footer.json`, and renders its nested groups
without requiring JavaScript.

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

Page layouts also receive `$latestPosts`. It is an empty array unless the page enables its Latest posts section; otherwise it contains up to the page's configured limit of newest published posts. Custom themes can render this collection directly. The bundled Standard and Landing layouts use `partials/latest-posts.php`.

The Admin Themes preview supports home, standard, landing, ecommerce, post, blog, archive, and 404 layouts without activating the candidate theme. Static export uses the same page-template resolver and copies active-theme assets to matching public paths.

## Integrating the 2.3 Appearance and Widget Features

The bundled default theme (Batoi Versatile 3.2.0) implements these features.
Saving their Settings values does not automatically change a custom theme.
Integrate them into the theme's existing shell and declared assets; do not load
a second copy of the default shell or overwrite a site-owned theme.

| Feature | Custom-theme integration contract |
| --- | --- |
| Palette | Emit `Batoi\Press\Core\Appearance::css($site)` in a style element and consume its validated CSS variables in the theme stylesheet. Tokens are `--bp-body-bg`, `--bp-header-bg`, `--bp-footer-bg`, `--bp-link-hover`, `--bp-header-text`, `--bp-body-text`, `--bp-primary-button`, `--bp-secondary-button`, `--bp-primary-hover`, and `--bp-secondary-hover`. |
| Appearance mode | Validate `appearance_mode` against `light`, `dark`, and `system`, then set `data-bp-color-mode` on the root element. Respect system preference for `system`. |
| Visitor switch | Honor `show_theme_toggle`. Adapt the default script's `data-bp-mode-toggle` handler, pressed state, and optional `bp-color-mode` local-storage preference. Keep the control hidden until JavaScript initializes. |
| Footer | Read `footer_top_columns` and `footer_bottom_columns` as integers clamped to 1–4; retain responsive stacking. Escape `footer_text` and `footer_bottom_text`. Parse `footer_icon_links` with `Appearance::footerLinks()`, escape labels/icons, and localize URLs with `bp_url()`. Icons are text, not trusted HTML. |
| Load More | Honor `posts_load_more`. The default script expects a `data-bp-load-more` container, `.bp-post-grid > .bp-post-card`, and `.bp-pagination a[rel="next"]`. Preserve ordinary pagination without JavaScript and after a failed fetch. Use the supplied `archivePath`, not a hard-coded `/blog` path. |
| Scroll to top | Adapt `data-bp-scroll-top` behavior from the default script: hide on non-scrollable pages, honor reduced motion, and provide keyboard focus handling. |
| Widgets | Use `WidgetRenderer::render($widget, $publishedPosts, $postUrls)` for gallery, current-month calendar, and provider signup. Supply published records only and URLs keyed by slug. Recent-post and tag-count rendering remain separate; see the default post layout and `PageBlockRenderer`. |

Reference implementations: `theme/default/layouts/base.php`,
`theme/default/layouts/blog.php`, `theme/default/layouts/post.php`,
`theme/default/partials/footer.php`, and `theme/default/assets/` under
`radpress/`. Declare adapted CSS/JavaScript in the custom manifest so the
renderer loads each asset once. Render text with `bp_esc()`, attributes with
`bp_attr()`, and local links/assets through the URL helpers. Do not print raw
Settings values as CSS or executable markup.

### Custom-theme acceptance checklist

- Preview all supplied layouts before activation, including custom post-type
  archives and nested details. Do not assume compatibility inspection tests
  feature behavior or visual accessibility.
- Check light, dark, and system modes, saved visitor preference, keyboard
  controls, narrow screens, and contrast with configured palette values.
- Verify footer links/grids, gallery alt text, calendar links, provider signup,
  and Load More completion/error/no-JavaScript behavior.
- Test a subdirectory installation and static export. Confirm links/assets
  retain the correct base path and do not assume the site lives at `/`.
- Round-trip UTF-8 header, CSS, and JavaScript through the encoded admin save
  form; verify redirects, snapshots, and rejection of invalid CSRF/source.
  `tests/theme_page_templates.php` covers these controller-level saves with
  an isolated custom theme. It does not emulate an affected host's WAF.
- Retain host-specific reports until the exact deployment has been retested.
  Never bypass signing, authentication, or hosting protections to pass a test.

## Upload Compatibility And Conversion

`/admin/themes` inspects every uploaded ZIP before installation without
executing supplied PHP, JavaScript, hooks, build scripts, or template engines.
The versioned `press-theme-1` report checks archive paths and limits, links and
encryption, supported file types, credentials, PHP syntax and prohibited
operations, direct request-global output, browser inline code, remote asset
dependencies, manifest validity, required layouts, and declared assets.

An upload is classified as:

- **compatible**: a complete Press theme that may be installed inactive;
- **repairable**: a Press-shaped package requiring contract repair or migration;
- **convertible**: a static, Bootstrap, WordPress-presentation, Twig, Liquid, or
  other presentation source that must be converted by Batoi Platform Build;
- **unsafe**: a package that must be rejected before conversion.

Compatible does not mean approved. Preview every provided layout before an
owner explicitly activates the theme. Press does not retain or install rejected
source archives. A convertible report includes the exact source SHA-256 for a
governed Platform conversion record.

Batoi Platform conversion must treat CMS themes as presentation sources only,
generate a Batoi UIF-based Press theme, run security/accessibility/rendering
quality gates, and return signed output. Press must verify that signed contract
before a future direct handoff can install the result. Until that Platform API
and signing contract exists, upload the original archive and copy the safe
compatibility report into Platform Build manually.

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
- Read navigation through `Batoi\\Press\\Content\\MenuRepository`. Schema 2 menu
  documents use stable `mi_*` item IDs and `parent_id` relationships; themes
  must not infer hierarchy from URLs. Respect `enabled`, `type`, `presentation`,
  `column`, `target`, and `description`, and bound recursive rendering to
  `MenuRepository::MAX_DEPTH`.
- Keep a parent destination and its submenu disclosure as separate controls.
  Dropdown and mega-menu panels must work by click, keyboard, and touch, expose
  `aria-expanded`/`aria-controls`, close with Escape, and remain usable without
  hover. Heading and separator items are non-links; headings that own children
  still require an operable disclosure on narrow screens.
- Use the Contact Layout editor for theme-owned contact form handling. Keep
  validation, CSRF protection, rate limiting, and output escaping in place; do
  not place credentials in theme source. If a custom theme declares a Contact
  page template but has no `layouts/contact.php`, the constrained editor can
  create a safe starter layout on first save.
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
