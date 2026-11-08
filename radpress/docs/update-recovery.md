# Update Recovery

Guided update safety supports:

- Minimal backup of config, content, app customizations, themes, and overwritten files under `radpress/`.
- Package checksum verification.
- Staging before live file replacement.
- Remote manifest checks through PHP cURL even when `allow_url_fopen` is disabled.
- Manifest-driven live file replacement to allowed runtime paths.
- Maintenance mode while staged files are applied.
- Cache clearing and post-update health checks.
- Automatic rollback when guarded apply or health checks fail.
- Manual rollback from a selected backup ZIP.

Still required before fully automated recovery:

- Focused automated tests for health-check failure and rollback behavior.
- Optional signed package verification.

## Legacy 1.8.0 unsafe-path recovery

The 1.8.0 updater does not allow the newer `radpress/application/`,
`radpress/api/`, `radpress/mcp/`, `radpress/vendor/` or Composer metadata paths.
Applying a newer package can therefore report **Release manifest contains an
unsafe file path. Automatic rollback completed.** Re-uploading the same package
cannot change the updater already loaded by PHP. Current versions support those
runtime paths and report the specific invalid path during staging.

For an affected installation, a hosting administrator must bootstrap the updater
before attempting the full upgrade:

1. Preserve a complete site backup and confirm rollback left the existing site
   usable. Retain the failed package, its SHA-256 and the hosting logs; other
   invalid paths can produce the same message.
2. Obtain the official signed release ZIP. On a trusted current Press checkout,
   verify its release signature, manifest and file checksums before extracting
   recovery files. Do not use an arbitrary ZIP or disable signature/path checks.
3. With the site offline for maintenance, back up `radpress/updates/` and
   `radpress/config/update.json`. Copy the **complete `radpress/updates/`
   subsystem from that same verified release**, including `ReleaseSignature.php`.
   Do not copy only `UpdateRunner.php`: its dependencies changed too.
4. Merge the official release's `release_public_keys` into the existing update
   configuration, preserve other settings and the actual installed version, and
   set `require_signed_packages` to `true`. Obtain the trusted keys from the
   verified distribution, never from the failed package alone. Enable PHP Sodium
   and ZipArchive if necessary. Do not replace site/content/security configuration.
5. Restart the PHP worker or clear OPcache so the next request loads the new
   updater. Stage the verified full package again and apply it through Updates.
   Confirm backup creation, health checks, installed version, existing content,
   custom theme and hosting rules before taking the site out of maintenance.

A local regression using the exact tagged 1.8.0 source reproduced the reported
error. Bootstrapping the updater subsystem and trusted test key then installed a
synthetically signed package containing all six newer paths, preserved sampled
site/security/path/content/hosting files, and rejected tampered package content.
This validates the recovery mechanism; it is not acceptance of the team's
particular ZIP or live hosting environment.

## Legacy gallery compatibility

The shared HTML sanitizer rebuilds the native UIF lightbox contract for gallery
markup, including linked items, the initially hidden dialog, viewer image, and
Previous/Close/Next controls. It also recognizes the legacy `uif-lightbox`,
`uif-masonry-item`, `uif-lightbox-dialog`, and `uif-lightbox-image` classes when a
previous editor save removed their data attributes. Custom themes can render the
sanitized page body directly; no theme-specific workaround is needed. Unrelated
UIF request, routing, and action attributes remain filtered.

Very old versions such as 1.6.1 served draft pages publicly. Current releases
enforce publication status. Before upgrading, compare publicly linked galleries
with their stored statuses. If a gallery was intentionally public but marked
draft, review and publish that page to retain its availability. Do not publish
all drafts automatically or disable publication checks. Preserve a backup and
verify gallery URLs, image loading, viewer navigation, and closing after upgrade.

## Legacy custom-theme presentation

The shared sanitizer also preserves native UIF carousels, FAQ accordions, and
icon placeholders in page bodies rendered by any custom theme. Carousel slides,
indicators, Previous/Next controls, bounded item/step settings, and accordion
triggers, panels, and initial expanded state retain their presentation contract.
Legacy `uif-carousel`, `uif-carousel-slide`, `uif-carousel-indicators`,
`uif-accordion`, and `component-accordion` classes can restore structural markers
removed by an earlier save. Icon names, titles, classes, sizes, and accessibility
settings are validated separately; client-generated mounted flags are discarded.
Themes still need to load the bundled UIF assets.

Inline backgrounds retain validated URLs, size, position, repeat, and related
background properties. Relative/static positioning and bounded z-index values
are supported for background overlays. An unsupported CSS declaration no longer
removes safe neighboring declarations. Unsafe URLs, scripts, event handlers,
arbitrary UIF configuration, request/routing attributes, and unrelated actions
remain filtered.

These rules apply during reads and editor saves without rewriting stored files
on read. They do not infer missing icon names, background URLs, or custom layout
values that a previous save already deleted; restore those values from a backup.
After upgrading, verify carousel navigation, FAQ expansion/collapse, icons,
backgrounds, and a protected-layout text save on a staging copy before removing
any existing theme-specific compatibility adapter.

## Automatic theme preservation and base URL repair

Before replacing bundled theme files, the updater compares installed files with
the signed manifest of the currently installed release. It uses the private
`radpress/data/updates/installed-release.json` record from a successful upgrade,
or the original signed root `release.json` on a fresh installation. The version
and signature must match the installed configuration. Unchanged stock themes
receive normal updates. Modified, added, or deleted theme files are treated as
customizations. Without verified history, a differing existing theme is preserved
conservatively rather than assumed to be stock.

The complete customized theme is copied to an unused `<slug>-preserved` name
before replacement; only its name and slug metadata change. If it was active,
the site switches to that preserved copy. Existing and incoming theme names are
never overwritten by the copy. Inactive customizations are also retained without
changing the active theme. A theme containing hard-coded references to its old
theme paths stays intact in its existing directory instead of being renamed or
mixed with new release files. Linked theme entries stop the upgrade for review.
Separately configured theme stores remain untouched; unused bundled-theme
replacements are skipped. Existing settings-transaction recovery and revision
checks are honored before changing the site document.

The same transaction corrects an extra `/public_html` suffix in `base_url` only
when the configured public directory, web document root, and admin request path
confirm the public mount. It preserves the configured scheme, host, port, and
legitimate subdirectory prefix. Request headers never supply a replacement
origin. Valid subdirectory installations are unchanged. Unknown CLI context,
reverse-proxy prefixes, or a different request host leave the URL untouched and
produce a Settings review notice. A hosting CLI that knows the deployment can
pass trusted server context as the optional second argument to `UpdateRunner::apply`.

Theme activation, URL changes, preserved copies, and the installed-release record
are covered by the pre-upgrade backup and automatic rollback. Failed upgrades
remove only their newly created files and empty directories. Backups use unique
names, including for multiple upgrades in the same second. Successful browser
updates display and audit the preservation and URL repair results.

These safeguards must be present in the updater **executing** the upgrade. An
older updater cannot gain them merely by installing a ZIP that contains them.
For the first protected upgrade of such an installation, bootstrap the complete
verified `radpress/updates/` subsystem (including `UpgradeCompatibility.php`) as
described above. Subsequent upgrades use the shared safeguards automatically;
no site-specific theme migration script or domain rule is required.
