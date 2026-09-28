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

## Asset delivery and hosting performance

PHP public asset routes stream in 64 KiB chunks, with HEAD, ETag/Last-Modified,
conditional GET, and single byte ranges. Valid ranges return 206; unsatisfiable
ranges return 416 with the total size; matching validators return 304. Malformed
or multiple ranges are ignored with a full 200 response. If-Range mismatch also
returns 200. HEAD ignores Range and emits the full representation headers only.
File responses have an empty `Response::content()`; callers should use `send()`.
Existing path restrictions, MIME handling and private preview controls remain.

An optional integer `asset_cache_max_age` in the existing `site.json` config sets
mutable public asset freshness in seconds (0 by default, capped at 86400), always
with revalidation. No configuration file is replaced to introduce this setting.
Only theme URLs whose `h` parameter matches their current SHA-256 fingerprint
receive one-year immutable caching. Filename suffixes and library versions are
not assumed to identify content. Cookie/Authorization requests use
`private, no-store`; asset responses vary on those headers. Admin responses are
private and non-stored. Direct files served by Apache/CDN use hosting policies,
not these PHP policies.

The strong ETag hashes the opened representation in bounded memory, using xxh128
when available for ordinary assets and SHA-256 for theme fingerprints/fallback.
These are response validators, not signature/trust decisions. Even HEAD/304/range
requests currently read the file for validation; benchmark large-video workloads
before rollout. Concurrent in-place modifications by external FTP software are
outside the atomic application replacement contract.

Admin → Hosting Health reuses updater runtime checks and reports PHP extensions,
Sodium, ZIP, OPcache, writable directories and application cache policies. It is
restricted to owners/admins and makes no outbound diagnostic requests. Compression
and actual proxy/static-file headers require browser inspection; the view reports
“not verified” rather than guessing. Optional encoders and OPcache are separated
from blocking runtime or operation prerequisites.

### Compression configuration

Use the host's supported Apache/cPanel or reverse-proxy settings. Have the host
merge changes into existing rules; do not replace `.htaccess`. For Apache with
`mod_deflate` and permitted overrides, this optional example targets text assets:

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/css text/javascript application/javascript text/plain application/xml
</IfModule>
```

Choose one compression layer. Do not enable PHP zlib/`ob_gzhandler` and hosting
compression together. PHP file streaming disables zlib for its identity-byte
response; avoid output handlers that buffer entire file responses. Exclude media
range responses and already-compressed images/video/ZIPs from compression. Keep
`Vary: Accept-Encoding` for negotiated text responses and verify actual headers,
lengths, conditional responses and seeking in the browser after host changes.
See [Apache mod_deflate](https://httpd.apache.org/docs/2.4/mod/mod_deflate.html)
and [PHP zlib configuration](https://www.php.net/manual/en/zlib.configuration.php).
No hosting configuration is automatically changed by this implementation.
