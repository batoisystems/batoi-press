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
