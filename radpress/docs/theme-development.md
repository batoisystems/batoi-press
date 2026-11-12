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

## Versioned compatibility contract

`contract` defaults to `1.0.0` for legacy manifests; other contract versions are rejected until supported. Optional `compatibility.php` and `compatibility.press` use three-component `min` and `max_exclusive` bounds. An incompatible theme remains available for inspection but public rendering returns 503 before executing its layouts. Review compatibility in Themes and preview before activation.

Optional `tokens.light` and `tokens.dark` provide palette defaults using the names in `Appearance::LABELS` and six-digit hexadecimal colors only. Site palette settings override theme defaults. Optional `partials` lists required names: `email`, `block-posts`, `block-gallery`, `block-products`, `block-widget`. Declared files must exist; undeclared partials retain bundled fallbacks. Their PHP paths are respectively `partials/email.php` and `partials/blocks/{posts,gallery,products,widget}.php`.

For an existing custom theme, first retain its layouts/assets and omit new optional declarations. Add bounds after testing supported PHP/Press versions; add palette defaults and required partials incrementally. Theme upgrades do not rewrite site content, custom templates or palette settings. Native theme PHP is trusted executable code.

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

### Convert an Exchange template

1. Keep the original ZIP. In Press, copy **Compatibility report JSON** from the
   inspection screen and save it as `compatibility-report.json`.
2. In Batoi Build, select a **Batoi Press App** and open **Press Theme Converter**.
   Under **Start Conversion**, supply the exact original ZIP and the saved JSON.
3. Run inspection and conversion. Review the generated layouts, accessibility
   and rendering checks, and any discarded behavior. An authorized reviewer
   approves the exact result and signs it through the App’s configured signer.
4. Download the ZIP and detached signature envelope. Verify the envelope and ZIP
   digest against the trusted Build signing key separately. **Inspect and Upload**
   in Press checks native theme structure and does not verify that envelope.
5. Upload the verified native ZIP in **Admin → Themes → Inspect and Upload**.
   Preview the inactive theme before activating it.

If the App lacks conversion or signing access, port the template locally on a
test installation. Duplicate the bundled default theme, retain its `theme.json`
and required layout files, and adapt its markup and local assets. Reuse Press’s
layout data and asset helpers. Review any template scripts before including them.
Package the resulting theme and use the same inactive upload and preview flow.

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

## Template save recovery and diagnostics

The editor submits to the existing `admin.php` entry point with the template
save route, using the installation’s path prefix. This avoids the rewrite that
serves pretty URLs. Existing save and editor POST routes remain available.
JavaScript sends UTF-8/base64 source and asks for a JSON acknowledgement. A
native form POST preserves source whitespace and redirects to the editor with
HTTP 303 after a successful save. Both paths retain owner/admin access,
CSRF validation, source validation, the 2 MiB limit, and pre-save snapshots.
Failed validation returns the submitted source escaped in an editable form;
JavaScript transport failures leave the original editor open. **Download source**
creates a local text file only on explicit request. Without JavaScript, copy the
source from the recovery textarea. Source is not saved in browser storage.
Edits made while an earlier save is pending remain in the editor.

A server-generated `X-Request-ID` connects the authenticated request audit record
(method and route) with `theme.template.save_result` (method, status, reference).
Application errors show the reference. Neither record includes template bodies,
cookies, credentials, or CSRF tokens. Requests blocked before PHP cannot receive
an application reference. Download the source before refreshing or signing in
again after session expiry.

If the direct POST still fails on a host, collect the exact timestamp, status,
sanitized redirect chain, and request reference (if present), then correlate
web server, proxy, and hosting-filter logs. A GET never writes a template.

## Page media performance

Page editors can select an existing local asset URL as the hero and separately
choose preload and high fetch priority. Insert the image in the page body;
selection does not insert or move it. Press injects an escaped image preload in
the head of that page only and makes matching body images eager. Theme rendering
adds intrinsic dimensions to local raster images when neither dimension was
specified. Review custom theme CSS after adopting this behavior.

Optional lazy loading applies to later local body images, excluding the first
image and every matching hero. Enable it only after reviewing above-the-fold
placement. Existing explicit loading and responsive-image attributes are retained
except that a selected hero is always eager. The `sizes` field describes the
actual CSS layout (default `100vw`); review it for narrower image columns.

Media upload can create bounded 480/960/1600px WebP and AVIF copies using available
GD encoders. Originals remain unchanged; no upscaling occurs. Unsupported
encoders, unsupported/animated images, inputs over 12 million pixels/16 MiB or insufficient
PHP memory skip optimization. Derived files appear in Media. WebP copies supply
responsive `srcset`; AVIF copies are available for explicit selection. Replacing
an original invalidates its derivative manifest by checksum, and old derivatives
are not automatically deleted. Clearing runtime cache discards derivative manifests; Press can still discover
existing matching copies by their original-content fingerprint. Regenerate by opting in on a new upload. Budget
storage for these optional copies. PHP without DOM leaves image markup unchanged.

Video/audio embeds retain controls, safe URLs, caption tracks and fallback text;
unspecified preload defaults to `none`. Autoplay is not enabled. Page saves report
removed unsupported elements and unsafe attributes without displaying removed
values. Arbitrary `<link>`, script or head markup is still not allowed in bodies.

## Email and page-block presentation

Theme Templates includes **Email Template**, **Blog Posts Block**, **Image Gallery
Block**, **Products Block**, and **Widgets Block** cards. These optional PHP files
can be created from bundled starter templates in existing custom themes. Source
editing retains the normal owner/admin permission, CSRF validation, encoded
transport, PHP syntax checking and prior-file snapshots. Duplicate the default
theme before long-lived customization so a release does not replace its files.

| Template | Relative theme file | Presentation context |
| --- | --- | --- |
| Email Template | `partials/email.php` | `$site`, `$name`, `$email`, `$subject`, `$message`, `$escape` |
| Blog Posts Block | `partials/blocks/posts.php` | `$site`, `$title`, `$block`, `$items`, `$escape` |
| Image Gallery Block | `partials/blocks/gallery.php` | `$site`, `$title`, `$block`, sanitized `$body`, `$escape` |
| Products Block | `partials/blocks/products.php` | `$site`, `$title`, `$block`, `$items`, `$escape` |
| Widgets Block | `partials/blocks/widget.php` | `$site`, `$title`, `$block`, `$widget`, rendered `$body`, `$escape` |

`$escape($text)` escapes text and quoted HTML attributes. Email contact values are
visitor text: escape them and use `nl2br($escape($message), false)` for line breaks.
Use inline styles, presentation tables and absolute HTTPS URLs for email. The
bundled email uses the site name in a Batoi-styled panel and identifies Batoi Press
in the footer. Delivery provider, recipients, CAPTCHA and rate limiting stay in
the application. Settings → Mail and integrations → **Copy to Sender** adds the
validated submitter as Cc to the same message for either supported provider; it is
off by default. The recipients can see each other's address. A sender already
matching Default To is not added again. Mailgun retains its plain-text alternative.

Post and product `$items` are already restricted to published records, the block's
category and its limit. Each includes a backend-generated `url`; post items also
include a validated `image_url`. Preserve escaping and the `show_image`,
`show_date`, and `show_read_more` flags when changing markup. Gallery and widget
bodies are prepared by the existing sanitization/rendering pipeline. Template
changes apply to every matching block in that theme. Use page CSS for a
page-specific variation. Public rendering, theme preview and static export use
the same partials. A theme missing an optional file uses the bundled default;
existing themes do not need a migration or new manifest capability.

These remain trusted PHP source files with the same privileges as existing theme
layouts. They separate presentation from repository logic; they are not a PHP
sandbox. Do not insert credentials, delivery calls or content mutations into them.

## Portable UIF theme generation

The development **Press Theme Kit for Batoi UIF 1.0.0** provides a file workflow
for Claude, Gemini, ChatGPT or any tool that can return JSON. It does not require
a model account inside Press, a particular connector or a Batoi cloud account.

1. As the owner, open **Themes → Create UIF theme**. Download the starter and
   schema, also available in [examples](examples/uif-theme-starter.json) and
   [schemas](schemas/uif-theme.schema.json).
2. Give them to your assistant with this request:

   > Return only valid Press UIF Theme Kit 1.0.0 JSON for my site. Choose a new
   > slug, name, author and version. Use the corporate, editorial or shop preset,
   > documented six-digit color tokens and a 60–90rem width. Introduction sections
   > support heading, text and local-link buttons. Preserve the schema. Include no
   > credentials, PHP, JavaScript, arbitrary CSS, remote assets or extra fields.

3. Paste the result into the owner form. Press validates bounded data, generates
   templates/assets from its bundled implementation and runs the ordinary ZIP
   compatibility inspector before download.
4. Upload the ZIP through the existing Themes workflow. It installs inactive.
   Preview every layout, phone/tablet/desktop widths, long titles, empty states,
   navigation, contrast and keyboard behavior before activating it.

The compiler accepts approved data only; it does not execute assistant-supplied
source. All executable templates and UIF scripts come from bundled Press code.
Introduction sections appear after a Landing page's normal content and retain
its main heading. Contact pages use regular content and governed Forms blocks or
links rather than embedding remote CAPTCHA code. Existing page/post/product
content, menus, branding, responsive layouts and theme asset handling remain
owned by their normal Press services.

Generated ZIPs carry deterministic file ordering/timestamps and provenance hashes.
Presets and token validation do not establish WCAG conformance. Native generated
themes still go through the existing trusted-theme review; inspection is not a
malware certification. Stage files remain private under `data/tmp/theme-kit/` and
are excluded from releases.

`php radpress/tests/uif_theme_kit.php` checks all three presets, reproducible
packages, ordinary compatibility inspection, PHP syntax and hostile/unsupported
inputs. It does not prove actual model generation in a named client.

### Connected theme drafts (5.0 development)

An owner can explicitly grant **theme:read** and **theme:write** in a Connections
custom profile. Existing content/site scopes grant no theme authority. Theme
scopes require a current owner principal and do not permit executable source
uploads, installation or activation.

Compatible clients use `theme_kit_get`, `theme_validate`, `theme_draft_create`
and `theme_draft_get`. Creation requires a unique idempotency key. A retry with
the same key and definition returns the same draft; changes require a new key.
Return the resulting `review_url` to the owner. Never ask the assistant to obtain
the owner's password or approve on the owner's behalf.

The owner reviews an immutable JSON definition and downloads its validated ZIP.
Downloads bind to the reviewed definition and generated-file revision; a source
change requires a fresh draft. The ordinary inactive upload, layout/device
preview and activation workflow follows. Drafts are encrypted under private
`data/integrations/theme-drafts/`, bound to the installation and connection, and
expire within 24 hours or at the connection's expiry. Revocation blocks download.
Draft history is bounded at 1,000 records / approximately 64 MiB and is retained
for operator archival. Draft status never means installed or active; manual
installation/activation is not reported back as an applied machine proposal.

See the [machine interface contract](machine-interfaces.md#connected-uif-theme-drafts-50-development)
for JSON endpoints. `php radpress/tests/theme_drafts.php` exercises both transports,
scope/connection isolation, retries, owner review, revocation and source conflicts.
Visual diffs/restoration, a single reviewed activation proposal, and actual
Claude/Gemini/ChatGPT acceptance remain planned. Responsive browser layouts are
supported; this kit does not build native mobile/desktop application binaries.
