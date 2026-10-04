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
