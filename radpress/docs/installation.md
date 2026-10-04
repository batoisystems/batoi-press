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

When `radpress/config/installed.lock` or an owner account exists, the installer is disabled. Removing the lock alone does not permit replacing an owner. Corrupt account storage requires server recovery.

For a genuinely fresh deployment with no owner and no installation lock, open:

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

## Major-upgrade hosting contract

This development line requires supported PHP 8.3+ with native JSON, sessions, hashing, filters, password hashing and secure random support. Reliable local locking and rename are required. No database, Composer command, Node, shell, cron, external identity provider or network access is required for local publishing. Release dependencies/assets arrive prebuilt; Firebase JWT loads only for optional OAuth.

Installer and Hosting Health use the same capability registry. DOM enables rich HTML editing; without it new content is stored as escaped plain text with an editor notice. Fileinfo is required for new uploads; GD is optional for derivatives. ZIP enables package/archive features. Sodium is required for signed automatic updates; use verified manual deployment when unavailable, never unsigned update fallback. Encrypted-secret features require Sodium or OpenSSL AES-256-GCM. Existing Sodium v1 secrets still need Sodium. cURL enables remote adapters; mbstring improves multilingual case folding; neither is a core install requirement.

Forms and Webhook delivery are optional, default-off modules under Admin → Plugins. Queued form email and webhook jobs require encrypted storage. Ordinary contact forms remain on their existing delivery path. An optional cron/CLI runner can process one queued job per invocation; without it use Forms → Process next delivery. Public requests never drain the queue.

Private storage must remain outside the document root or be denied by verified server rules. On Nginx, set the root to public_html; Apache .htaccess protections are not Nginx protections. Do not assume a health screen proves HTTP access is denied. Test direct requests to configuration, sessions, logs and backups on the actual host.

Plugin and form package contracts, recovery and delivery operation are documented in [Plugin SDK and custom forms](plugins.md).

### Restricted-function verification profile

`php radpress/tests/minimal_runtime.php` verifies local login, publishing, rendering and store-only forms using private synthetic data, and checks that optional Composer dependencies stay unloaded. A stricter local profile can run:

```sh
php -n -d pcre.jit=0 -d disable_functions=curl_init,sodium_crypto_sign_verify_detached,sodium_crypto_aead_xchacha20poly1305_ietf_encrypt,sodium_crypto_aead_xchacha20poly1305_ietf_decrypt,openssl_get_cipher_methods,openssl_encrypt,openssl_decrypt,imagecreatetruecolor,mb_strtolower,mb_stripos radpress/tests/minimal_runtime.php
```

This tests unavailable functions and fail-closed integration activation. It does not remove compiled-in DOM/Fileinfo/ZIP classes, replace shared-host filesystem tests, or establish measured coverage of the hosting market. Validate those environments separately before release.

### Server configuration examples and migration

The official [PHP support schedule](https://www.php.net/supported-versions.php), checked on 2026-10-04, lists PHP 8.3 security support through 2027-12-31, 8.4 through 2028-12-31 and 8.5 through 2029-12-31. Local focused fixtures pass on 8.3.30 and 8.5.2; the complete local suite runs on 8.4.17. This is not measured shared-host market coverage. Older sites should back up private content/configuration and custom code, switch both web and CLI PHP to a supported version, then follow the verified upgrade/recovery procedure. Keep the prior runtime and backup available for rollback.

Apache should use the release's `public_html` as its document root, with its shipped rewrite rules enabled:

```apache
DocumentRoot /srv/press/public_html
<Directory /srv/press/public_html>
    Require all granted
    AllowOverride All
</Directory>
```

A corresponding Nginx server excerpt is below. Replace the root and PHP-FPM socket with host-provided values. Keep private `radpress` outside this root and do not create aliases into it.

```nginx
root /srv/press/public_html;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ ^/(index|admin|install)\.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
location ~ \.php$ { return 404; }
location ~ /\. { deny all; }
```

These are configuration examples, not executed Apache/Nginx acceptance. On the actual installation verify public pages, authenticated administration and the installer lock, then confirm direct requests for configuration, session, submission, queue, log, backup and staged files return 403/404 with no private body. Test root/subdirectory routing and HTTPS cookie behavior after any proxy configuration change. Never expose private folders to make asset URLs work.
