# Installation

Batoi Press is designed for FTP deployment to PHP hosting.

## Preferred Layout

Keep these directories outside the public web root when the host allows it:

```text
radpress/
app/
config/
content/
storage/
themes/
```

Use `public_html/` as the web root on cPanel hosts.

## Installer

The installer entrypoint is:

```text
public_html/install.php
```

When `config/installed.lock` exists, the installer is disabled.

