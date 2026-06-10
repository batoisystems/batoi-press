# Update Recovery

Guided update safety supports:

- Minimal backup of config, content, app customizations, themes, and overwritten files under `radpress/`.
- Package checksum verification.
- Staging before live file replacement.
- Manifest-driven live file replacement to allowed runtime paths.
- Maintenance mode while staged files are applied.
- Cache clearing and post-update health checks.
- Automatic rollback when guarded apply or health checks fail.
- Manual rollback from a selected backup ZIP.

Still required before fully automated recovery:

- Focused automated tests for health-check failure and rollback behavior.
- Optional signed package verification.
