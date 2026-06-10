# Updates

Batoi Press uses release-based updates.

The default stable manifest is:

```text
https://batoi.com/pub/press/latest.json
```

Basic update checks and downloads should remain public under `https://batoi.com/pub/press/`. The human-facing microsite remains `https://batoi.com/press`. Optional Batoi Platform workspace services can add monitoring, fleet status, security checks, and assisted upgrades.

The admin update screen can fetch the configured manifest and compare the latest version with the installed version.

Implemented safety steps:

- Create a ZIP backup of config, content, theme, and app customizations.
- Verify a package checksum when a SHA-256 value is provided.
- Reject ZIP packages with unsafe entry paths before extraction.
- Extract a package into a staging directory.
- Require a release manifest inside the staged package.
- Apply only manifest-listed files to allowed runtime paths.
- Enable maintenance mode while applying staged files.
- Clear cache and run post-update health checks after replacement.
- Automatically restore from the pre-update backup when a guarded apply or health check fails.
- Restore files from a selected backup ZIP.

Follow-up hardening should add broader update tests and optional signed package verification.

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
