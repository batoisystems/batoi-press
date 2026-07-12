# Installation

Batoi Press is designed for FTP deployment to PHP hosting.

## Preferred Layout

Keep these directories outside the public web root when the host allows it:

```text
radpress/
```

`radpress/` contains private app code, config, authored content, data, docs, tests, and themes.

Use `public_html/` as the web root on cPanel hosts.

## Installer

The installer entrypoint is:

```text
public_html/install.php
```

When `radpress/config/installed.lock` exists, the installer is disabled.

To perform a fresh browser setup, remove the lock file manually on the server and open:

```text
/install.php
```

The installer creates or updates:

```text
radpress/config/site.json
radpress/config/users.json
radpress/config/security.json
radpress/config/installed.lock
```

It also ensures writable runtime directories such as:

```text
radpress/content/media/
radpress/data/backups/
radpress/data/cache/
radpress/data/export/
radpress/data/log/
radpress/data/sessions/
radpress/data/tmp/
radpress/data/versions/
```

## Release Package Notes

A release ZIP should contain the runtime files needed for FTP upload:

```text
public_html/
radpress/
README.md
LICENSE
```

Do not include generated runtime state in release packages:

```text
radpress/config/installed.lock
radpress/data/backups/*.zip
radpress/data/cache/*
radpress/data/export/*.zip
radpress/data/log/*.jsonl
radpress/data/sessions/*
radpress/data/tmp/*
radpress/data/versions/*
```

Keep `.gitkeep` and `.htaccess` files in protected runtime directories so uploads preserve the expected folders and deny direct access on Apache-compatible hosts.

Installers should use the verified ZIP attached to the corresponding GitHub release or published through the stable manifest.

## Static Export Notes

The Static Export admin workflow generates a ZIP containing published pages, published posts, the blog index, uploaded media files under `media/`, `sitemap.xml`, and `feed.xml`.

Each generated package is verified after creation. The verification checks expected static paths, rejects unsafe archive entries, and reports status on the export completion screen.
