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

Theme layouts receive sanitized content and metadata from the engine.

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
