# Updates

Batoi Press uses release-based updates.

## Current release behavior

Official 2.x packages and release indexes use Ed25519 signatures. The default
configuration requires signed packages and trusts the configured release public
keys. PHP Sodium is required for signature verification; it must not be bypassed.
The older optional-trust examples below are historical, not the current policy.

The update result distinguishes a newer stable release, a matching release,
and a stable manifest older than the installed version. An older manifest is
not an update or a reason to downgrade: check whether the distribution endpoint
has been deployed. GitHub publication alone does not update that endpoint.
Recoverable transport exceptions return setup guidance without disclosing raw
exception details. A web-server/worker crash still requires hosting logs; an
application-level error handler cannot recover a terminated PHP process.

## Update workflow and historical implementation notes

Repository releases must increment `radpress/config/update.json` before package build and manifest publication. See `radpress/docs/release-management.md` for the version policy, release checklist, and stable `1.0.0` readiness checklist.

The default stable manifest is:

```text
https://www.batoi.com/pub/press/latest.json
```

Basic update checks and downloads should remain public under `https://www.batoi.com/pub/press/`. The human-facing microsite remains `https://www.batoi.com/press`. Optional Batoi Platform workspace services can add monitoring, fleet status, security checks, and assisted upgrades.

The admin update screen can fetch the configured manifest and compare the latest version with the installed version. It uses PHP cURL as the supported transport for remote manifest checks, so `allow_url_fopen` is not required when cURL is available. If cURL is unavailable and `allow_url_fopen` is enabled, Press may fall back to the PHP HTTPS stream wrapper. TLS verification remains enabled for both transports.

The admin update screen separates routine checks from risky operations. Version status, manifest URL, package staging, staged packages, backup creation, and rollback backups are displayed in separate sections. Rollback restore actions are visually marked as danger-zone operations.

Implemented safety steps:

- Create a ZIP backup of config, content, theme, and app customizations.
- Verify a package checksum when a SHA-256 value is provided.
- Check the uploaded file signature and report HTML release pages, non-ZIP data, incomplete archives, and missing ZipArchive support separately.
- Reject ZIP packages with unsafe entry paths before extraction.
- Accept a release manifest at the archive root or inside one unambiguous top-level desktop ZIP folder.
- Extract a package into a staging directory.
- Require a release manifest inside the staged package.
- Apply only manifest-listed files to allowed runtime paths.
- Enable maintenance mode while applying staged files.
- Clear cache and run post-update health checks after replacement.
- Automatically restore from the pre-update backup when a guarded apply or health check fails.
- Restore files from a selected backup ZIP.
- Verify generated release ZIP and `latest.json` outputs before public publication.
- Carry disabled-by-default package-trust metadata for future signed package verification.

Follow-up hardening should add broader update tests and decide when optional signed package metadata should become an enforced policy.

Release packages must include `release.json`, `batoi-press-release.json`, or `manifest.json` at the package root. The manifest must list installable files:

```json
{
  "version": "0.2.0",
  "files": [
    {
      "path": "radpress/core/App.php",
      "sha256": "..."
    }
  ]
}
```

Use `source` and `target` when the package path differs from the live install path.

Public stable manifests may include optional trust metadata:

```json
{
  "trust": {
    "signature_required": false,
    "signature_algorithm": null,
    "signature_url": null,
    "public_key_url": null
  }
}
```

In v0.5.0 this metadata is informational. SHA-256 checksum verification remains the enforced integrity check for normal update workflows.
