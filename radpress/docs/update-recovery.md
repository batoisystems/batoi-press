# Update Recovery

Guided update safety supports:

- Minimal backup of config, content, app customizations, themes, and overwritten files under `radpress/`.
- Package checksum verification.
- Staging before live file replacement.
- Manifest-driven live file replacement to allowed runtime paths.
- Manual rollback from a selected backup ZIP.

Still required before fully automated recovery:

- Maintenance mode.
- Post-update health checks.
- Automatic rollback trigger after failed health checks.
