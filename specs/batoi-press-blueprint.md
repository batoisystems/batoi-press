# Batoi Press Blueprint

## Task Status

Last updated: 2026-06-10

### Completed

* [x] Repository scaffold with `public_html/`, `radpress/`, `app/`, `content/`, `config/`, `storage/`, `themes/`, `docs/`, and `tests/`.
* [x] MIT license retained.
* [x] JSON configuration files added.
* [x] HTML body plus JSON metadata content model added.
* [x] Demo home page, about page, and first blog post added.
* [x] PHP autoloader added.
* [x] Request, response, router, config, path resolver, file store, cache status, and theme renderer added.
* [x] HTML content sanitizer added.
* [x] Default theme added.
* [x] Public rendering for `/`, `/about`, `/blog`, `/blog/first-blog-post`, `/sitemap.xml`, and `/feed.xml`.
* [x] Read-only Phase 1 admin dashboard added at `/admin`.
* [x] Update status surface added at `/admin/updates`.
* [x] Installer status page added at `public_html/install.php` and disabled by `config/installed.lock`.
* [x] `batoi.com/press/latest.json` recorded as the default stable update manifest.
* [x] Sensitive-directory `.htaccess` deny rules added.
* [x] Initial README and docs added.
* [x] Smoke test script added.

### In Progress / Next

* [x] Run syntax and smoke verification.
* [ ] Replace Phase 1 installer status page with full browser-based installer.
* [ ] Add authenticated admin login, sessions, CSRF, and rate limiting.
* [ ] Add page, post, media, menu, settings, and user management.
* [ ] Add version history and write-action audit logging.
* [ ] Add cache write/clear workflow.
* [ ] Add static export ZIP generation.
* [ ] Add real update check against the public manifest.
* [ ] Add backup, staging, package verification, and rollback before automated updates.

## 1. Project Name

**Batoi Press**

Repository name:

```text
batoi-press
```

Tagline:

```text
A secure flat-file CMS and publishing engine aligned with Batoi RAD.
```

License:

```text
MIT License
```

## 2. Project Goal

Build an open-source, database-free CMS that can be uploaded through FTP to standard PHP hosting and used to create and manage full websites, pages, blogs, media, menus, SEO metadata, redirects, and static cache securely.

Batoi Press must not require MySQL, MariaDB, PostgreSQL, SQLite, Node.js, Docker, Git, Composer, or command-line access for normal use.

The system should work after FTP upload and browser-based setup.

## 3. Target Users

* SMEs that need a secure website without WordPress complexity
* Agencies building small and medium websites
* Batoi customers needing lightweight public sites
* Developers who want flat-file publishing
* Business users who need an admin panel without Git

## 4. Core Principles

1. No database dependency.
2. FTP-deployable.
3. Secure by default.
4. Human-readable content files.
5. Simple admin interface.
6. Version history and audit trail.
7. Static cache for speed.
8. Theme-based rendering.
9. Batoi RAD-compatible architecture.
10. Minimal dependencies.
11. Configurable web root for cPanel and non-cPanel hosting.
12. Governed updates with backup and rollback.
13. User-friendly administration for non-technical site owners.

## 5. Technology Stack

Use:

```text
PHP 8.1+
HTML5
CSS3
Vanilla JavaScript
JSON
HTML content files
```

Avoid:

```text
MySQL
SQLite
Node.js runtime requirement
Composer runtime requirement
Laravel
Symfony
WordPress dependency
Large frontend frameworks
```

Composer may be used for development, but the release package must include all required runtime files.

## 6. Repository Structure

Create the repository with this structure:

```text
batoi-press/
├── public_html/
│   ├── index.php
│   ├── admin.php
│   ├── install.php
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   └── img/
│   └── .htaccess
├── radpress/
│   ├── Core/
│   │   ├── App.php
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Config.php
│   │   ├── Paths.php
│   │   ├── View.php
│   │   ├── Theme.php
│   │   ├── HtmlContent.php
│   │   ├── FileStore.php
│   │   ├── Cache.php
│   │   ├── Slug.php
│   │   └── Validator.php
│   ├── Admin/
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── PageController.php
│   │   ├── PostController.php
│   │   ├── MediaController.php
│   │   ├── MenuController.php
│   │   ├── SettingsController.php
│   │   ├── UpdateController.php
│   │   └── UserController.php
│   ├── Security/
│   │   ├── Auth.php
│   │   ├── Session.php
│   │   ├── Csrf.php
│   │   ├── Password.php
│   │   ├── RateLimiter.php
│   │   ├── UploadGuard.php
│   │   └── AccessGuard.php
│   ├── Content/
│   │   ├── PageRepository.php
│   │   ├── PostRepository.php
│   │   ├── MediaRepository.php
│   │   └── VersionRepository.php
│   ├── Update/
│   │   ├── VersionChecker.php
│   │   ├── PackageVerifier.php
│   │   ├── BackupManager.php
│   │   ├── UpdateRunner.php
│   │   └── RollbackManager.php
│   ├── Helpers/
│   │   ├── esc.php
│   │   ├── url.php
│   │   └── date.php
│   └── autoload.php
├── app/
│   ├── modules/
│   ├── hooks/
│   └── bootstrap.php
├── content/
│   ├── pages/
│   ├── posts/
│   ├── media/
│   └── menus/
├── config/
│   ├── site.json
│   ├── users.json
│   ├── routes.json
│   ├── security.json
│   ├── update.json
│   └── paths.json
├── storage/
│   ├── cache/
│   ├── backups/
│   ├── versions/
│   ├── logs/
│   ├── sessions/
│   ├── tmp/
│   └── export/
├── themes/
│   └── default/
│       ├── theme.json
│       ├── layouts/
│       │   ├── base.php
│       │   ├── page.php
│       │   ├── post.php
│       │   ├── blog.php
│       │   └── 404.php
│       └── assets/
├── tests/
├── docs/
│   ├── installation.md
│   ├── security.md
│   ├── theme-development.md
│   ├── content-format.md
│   ├── updates.md
│   ├── update-recovery.md
│   └── roadmap.md
├── LICENSE
├── README.md
└── batoi-press.php
```

`radpress/` contains the reusable Batoi Press engine. `app/` is reserved for site-level modules, hooks, and customizations so core code and local extensions do not become tangled.

`public_html/` is the default web root because many cPanel hosts use that name. The public web root must be configurable through `config/paths.json` for hosts that use `public/`, `htdocs/`, `www/`, or a custom document root.

## 7. Deployment Model

The user should be able to upload the project to hosting through FTP.

Preferred deployment:

```text
/radpress
/app
/config
/content
/storage
/themes
```

should remain outside public web root when possible, while `public_html/` is the only browser-facing directory.

If the hosting allows only one public directory, protect sensitive directories through `.htaccess`.

Create Apache rules that deny direct browser access to:

```text
/radpress
/app
/config
/content
/storage
```

Only `/public_html/index.php`, `/public_html/admin.php`, and the temporary installer entrypoint should execute requests by default.

Do not hardcode `public_html`. Path resolution must go through configuration so non-cPanel deployments can use another web root without changing core code.

## 8. Content Format

Configuration files should use JSON. Page and post bodies should use HTML files, not JSON blobs. Metadata should be stored in adjacent JSON files so content remains readable and metadata remains easy to validate, index, and update.

Recommended page file pair:

```text
content/pages/about/meta.json
content/pages/about/body.html
```

Example page metadata:

```json
{
  "id": "pg_about",
  "type": "page",
  "title": "About Us",
  "slug": "about",
  "status": "published",
  "template": "page",
  "author": "admin",
  "created_at": "2026-06-10T10:00:00+05:30",
  "updated_at": "2026-06-10T10:00:00+05:30",
  "seo_title": "About Us | Batoi Press",
  "seo_description": "Learn about our organization."
}
```

Example page body:

```html
<h1>About Us</h1>

<p>This is the page body.</p>
```

Recommended post file pair:

```text
content/posts/first-blog-post/meta.json
content/posts/first-blog-post/body.html
```

Example post metadata:

```json
{
  "id": "post_first",
  "type": "post",
  "title": "First Blog Post",
  "slug": "first-blog-post",
  "status": "published",
  "template": "post",
  "author": "admin",
  "category": "General",
  "tags": ["announcement", "cms"],
  "created_at": "2026-06-10T10:00:00+05:30",
  "updated_at": "2026-06-10T10:00:00+05:30",
  "published_at": "2026-06-10T10:00:00+05:30"
}
```

Example post body:

```html
<h1>First Blog Post</h1>

<p>This is the blog content.</p>
```

HTML content must be sanitized on save and escaped or filtered on render according to a strict allowlist. Markdown import or editing can be added later, but the canonical stored body format should be HTML.

## 9. MVP Features

Implement these first.

### Public Website

* Home page
* Standard pages
* Blog listing
* Blog detail
* Category archive
* Tag archive
* 404 page
* Sitemap XML
* RSS feed
* SEO metadata
* Canonical URLs
* Theme rendering

### Admin Panel

* Browser-based setup
* Login/logout
* Dashboard
* Create/edit/delete pages
* Create/edit/delete blog posts
* Draft/published status
* Slug editor
* HTML content editor
* Media upload
* Menu manager
* Site settings
* User management
* Password change
* Version check and guided update workflow
* Version history
* Audit log

### Content Storage

* Save page metadata and HTML bodies in `/content/pages`
* Save post metadata and HTML bodies in `/content/posts`
* Save media in `/content/media`
* Save menus in `/content/menus`
* Save update backups in `/storage/backups`
* Save versions in `/storage/versions`
* Save audit logs in `/storage/logs/audit.jsonl`

### Cache

* Rendered page cache in `/storage/cache`
* Clear cache from admin
* Auto-clear affected cache after publish/update/delete

## 10. Security Requirements

Implement security from the beginning.

### Authentication

* Passwords must use `password_hash()`.
* Login must use rate limiting.
* Sessions must regenerate ID after login.
* Sessions must use secure cookie flags where available.
* Provide logout that destroys the session.

### CSRF Protection

* All admin POST, PUT, PATCH, DELETE actions must require CSRF tokens.
* CSRF tokens must never be placed in URLs.
* Use hidden form fields or request headers for tokens.

OWASP recommends CSRF tokens for state-changing requests and warns against leaking CSRF tokens in URLs or logs.

### File Upload Security

Media upload must use:

* Extension allowlist
* MIME validation
* File size limit
* Safe generated filenames
* No direct PHP execution from media directory
* Image validation for image uploads
* Authorization checks before upload
* Optional future malware scanning hook

OWASP warns that uploaded files are a significant application risk and recommends allowlisted extensions, server-side type validation, generated filenames, size limits, and authorization checks.

Allow initially:

```text
jpg
jpeg
png
gif
webp
svg only if sanitized or disabled by default
pdf
txt
md
```

Disable executable uploads:

```text
php
phtml
phar
js
html
htm
exe
sh
bat
cmd
```

### Output Escaping

* Escape HTML output by default.
* Allow stored content HTML only through a controlled sanitizer.
* Strip unsafe HTML from content unless explicitly enabled by a trusted role and site setting.
* Escape attributes separately from HTML body.

### Access Control

Roles:

```text
owner
admin
editor
author
viewer
```

MVP permissions:

```text
owner: all
admin: all except owner deletion
editor: manage pages/posts/media/menus
author: manage own posts
viewer: read admin dashboard only
```

### Audit Log

Write one JSON line for every important action:

```json
{"time":"2026-06-10T10:00:00+05:30","user":"admin","action":"page.updated","target":"about","ip":"127.0.0.1"}
```

Track:

```text
login.success
login.failed
logout
page.created
page.updated
page.deleted
post.created
post.updated
post.deleted
media.uploaded
media.deleted
settings.updated
user.created
user.updated
user.deleted
cache.cleared
update.checked
update.started
update.completed
update.failed
update.rollback
```

## 11. Browser-Based Installer

Provide a browser-based installer script at the public web root, for example:

```text
public_html/install.php
```

On first run, if `config/site.json` or `config/users.json` does not exist, the installer should guide setup.

Installer steps:

1. Check PHP version.
2. Check writable directories.
3. Check `.htaccess` protection.
4. Detect or confirm public web root.
5. Create path config.
6. Create site config.
7. Create first owner user.
8. Generate security key.
9. Lock installer.

Create:

```text
config/installed.lock
```

After installation, setup must not be accessible unless the lock file is removed manually by someone with server access.

The installer must also support one of these hardening actions:

1. Disable itself automatically after successful installation.
2. Rename itself to a non-executable disabled filename.
3. Prompt the admin to remove it and clearly show installation as incomplete until this is done.

The safest default is to disable the installer automatically and require manual server access to re-enable it.

## 12. Routing

Public routes:

```text
/
/{page-slug}
/blog
/blog/{post-slug}
/category/{category-slug}
/tag/{tag-slug}
/sitemap.xml
/feed.xml
```

Admin routes:

```text
/admin
/admin/login
/admin/logout
/admin/pages
/admin/pages/new
/admin/pages/edit/{id}
/admin/posts
/admin/posts/new
/admin/posts/edit/{id}
/admin/media
/admin/menus
/admin/settings
/admin/users
/admin/audit
/admin/cache
/admin/updates
```

Use clean URLs through `.htaccess`.

Fallback must support query-string mode:

```text
index.php?route=/about
```

## 13. Theme System

Each theme has:

```text
themes/theme-name/theme.json
themes/theme-name/layouts/
themes/theme-name/assets/
```

`theme.json` example:

```json
{
  "name": "Default",
  "version": "0.1.0",
  "author": "Batoi",
  "supports": ["pages", "posts", "menus", "seo"]
}
```

Theme variables:

```php
$site
$page
$content
$menu
$meta
```

Theme files:

```text
base.php
page.php
post.php
blog.php
archive.php
404.php
```

## 14. Batoi RAD Alignment

Use Batoi-style modular architecture.

Batoi Press should follow the useful layout boundary seen in RAD-based apps: a small browser-facing web root and a separate application engine outside that root. It should not require the full Batoi RAD platform, a database, or RAD-specific hosting artifacts.

Core modules:

```text
Content
Media
Menu
Theme
Security
Audit
Cache
Settings
Users
```

Each module should be small, testable, and replaceable.

Future RAD connection points:

```text
Batoi RAD export/import
Batoi UIF theme integration
Batoi AIF assisted content writing
Batoi SecureOps checks
Batoi Flow publication hooks
```

Do not implement these integrations in MVP. Keep extension points ready.

## 15. Governance, Security, and Usability

Batoi Press should be governed, secure, and user-friendly by design.

Governed system requirements:

* Clear roles and permissions.
* Audit log for administrative actions.
* Version history for content changes.
* Explicit installer and update lifecycle.
* Documented release and upgrade policy.
* No hidden remote control or automatic code changes without admin approval.

Secure system requirements:

* Secure defaults during installation.
* Sensitive directories outside web root where possible.
* Strict upload validation.
* HTML sanitization for stored content.
* CSRF protection for all state-changing admin actions.
* Rate limiting for login and sensitive operations.
* Update package integrity checks before installation.

User-friendly system requirements:

* Browser-based installation.
* Clear admin dashboard status for setup, health, cache, and updates.
* Human-readable error messages with safe technical details.
* Guided update workflow with preflight checks.
* Simple recovery instructions when an update fails.
* No command-line requirement for normal operation.

## 16. Update and Backup Model

The admin should be able to check whether a newer Batoi Press version is available and apply updates safely.

Use release-based updates, not raw Git commit updates. Many target hosting environments do not have Git or command-line access.

The default public distribution surface should be:

```text
https://batoi.com/press
```

Recommended public endpoints:

```text
https://batoi.com/press
https://batoi.com/press/download
https://batoi.com/press/releases
https://batoi.com/press/latest.json
```

The update checker should query the public `batoi.com/press/latest.json` manifest over HTTPS by default. GitHub releases can remain the canonical source repository release record or fallback download source, but Batoi Press installations should not need GitHub, Git, command-line access, or a Batoi Platform account for basic update checks and downloads.

Optional Batoi Platform workspace services may provide registered-site monitoring, security checks, fleet management, update alerts, and assisted lifecycle services. These should be add-on services, not prerequisites for installing or updating the open source package.

Recommended update manifest fields:

```json
{
  "version": "0.2.0",
  "released_at": "2026-06-10T10:00:00+05:30",
  "download_url": "https://batoi.com/press/releases/batoi-press-0.2.0.zip",
  "checksum_sha256": "example-checksum",
  "minimum_php": "8.1",
  "channel": "stable",
  "github_release_url": "https://github.com/batoi/batoi-press/releases/tag/v0.2.0",
  "notes_url": "https://github.com/batoi/batoi-press/releases/tag/v0.2.0"
}
```

Admin update flow:

1. Admin opens `/admin/updates`.
2. System shows current version.
3. Admin clicks "Check for updates".
4. System fetches the official version manifest or GitHub release metadata.
5. System shows release notes, compatibility warnings, and backup requirements.
6. Admin confirms update.
7. System creates a minimal backup.
8. System verifies package checksum.
9. System stages files in a temporary directory.
10. System switches to maintenance mode.
11. System applies update.
12. System runs post-update checks.
13. System clears cache and exits maintenance mode.

Minimal backup should include:

```text
config/
content/
themes/
app/
current version manifest
files that will be overwritten
```

Backups should be written to:

```text
storage/backups/
```

Rollback requirements:

* If package verification fails, do not install.
* If staging fails, do not modify the live installation.
* If post-update checks fail, restore overwritten files from backup.
* Log update status and rollback details.
* Keep manual recovery instructions in `docs/update-recovery.md`.

Update safety limits:

* Never overwrite `content/`, `config/`, `storage/`, custom `themes/`, or `app/` files unless a migration explicitly requires it and the admin confirms.
* Do not run arbitrary remote PHP code from an update package.
* Require HTTPS for remote update checks and downloads.
* Prefer signed or checksum-verified release packages.
* Provide manual ZIP update instructions for hosts that block outbound HTTP requests.

MVP can include update checking only. Automated update installation may be added after the core install, backup, and file integrity model is stable.

## 17. Static Export

Add a basic static export command in admin:

```text
/admin/export-static
```

Output:

```text
/storage/export/site-static.zip
```

The export should generate:

```text
index.html
about/index.html
blog/index.html
blog/post-slug/index.html
sitemap.xml
feed.xml
assets/
media/
```

This makes Batoi Press useful as CMS and static-site generator.

## 18. Coding Standards

Use:

```text
strict_types=1
PSR-4-like autoloading
Small classes
No global business logic
No direct file writes outside FileStore
No direct path references outside the path configuration service
No direct admin actions without CSRF
No direct output without escaping
No remote update installation without package verification
```

Do not use a heavy framework.

Create a simple autoloader.

## 19. Acceptance Criteria for MVP

The MVP is complete when:

1. User can upload files by FTP and open installer in browser.
2. Installer creates owner account.
3. User can log in securely.
4. User can create a page.
5. Page renders publicly with clean URL.
6. User can create a blog post.
7. Blog listing and blog detail work.
8. User can upload an image securely.
9. User can insert uploaded image into content.
10. User can edit menus.
11. SEO title and description render.
12. Sitemap XML and RSS feed work.
13. Audit log records admin actions.
14. Version history is created on save.
15. Cache can be cleared.
16. Static export generates a ZIP.
17. Installer is disabled or removable after successful installation.
18. Admin can check whether a newer release is available.
19. Update checks and update attempts are recorded in audit logs.
20. No MySQL, SQLite, Node.js, Docker, Git, or CLI is required for runtime.

## 20. README Requirements

Create `README.md` with:

```text
Project description
Features
Installation by FTP
Directory permissions
Security notes
Admin setup
Installer lifecycle
Update and backup model
Content format
Theme development
Roadmap
License
Contributing guide
```

## 21. Documentation Files

Create:

```text
docs/installation.md
docs/security.md
docs/content-format.md
docs/theme-development.md
docs/updates.md
docs/update-recovery.md
docs/roadmap.md
```

## 22. License

Use:

```text
MIT License
```

Batoi Press should be released as open source under the MIT License.

## 23. Development Phases

### Phase 1: Foundation

* Project structure
* Autoloader
* Router
* Config loader
* Path resolver
* FileStore
* HTML content loader
* HTML sanitizer
* Theme renderer
* Public page rendering

### Phase 2: Admin and Security

* Installer
* Installer disablement
* Auth
* Session
* CSRF
* Rate limiter
* Admin layout
* Dashboard

### Phase 3: Content Management

* Pages
* Posts
* Slugs
* Draft/published status
* Version history
* Audit log

### Phase 4: Website Features

* Menus
* Media manager
* SEO
* Sitemap
* RSS
* Cache

### Phase 5: Static Export and Hardening

* Static export ZIP
* Update checker
* Backup and rollback design
* Security test checklist
* Upload hardening
* Documentation
* Release package

### Phase 6: Guided Updates

* GitHub release manifest check
* Package download
* Checksum verification
* Staged update application
* Maintenance mode
* Rollback workflow
* Manual update fallback

## 24. Initial Codex Task

Implement Phase 1 first.

Do not implement everything at once.

Start with:

1. Repository scaffold.
2. PHP autoloader.
3. Router.
4. Config loader.
5. Path resolver.
6. FileStore.
7. HTML content loader.
8. HTML sanitizer.
9. Default theme.
10. Public page rendering from `/content/pages`.

Create a demo home page and about page.

After Phase 1, verify that these URLs work:

```text
/
/about
/sitemap.xml
```

No database should be used anywhere.
