# Security

Batoi Press establishes secure defaults and structure:

- Sensitive directories include `.htaccess` deny rules.
- HTML content is sanitized before rendering.
- Configuration is JSON and not executable PHP.
- Installer access is blocked by `radpress/config/installed.lock`.
- Password hashes use `password_hash()`.
- Secure session cookie flags are set where available.
- CSRF tokens protect admin write forms.
- File-backed login rate limiting protects login attempts.
- Admin routes redirect to login when not authenticated.
- Media uploads use allowlisted extensions, generated filenames, size limits, and executable-file denial.
- Admin write actions record audit log entries.
- Admin route access is enforced by role before controllers run.
- Author-role users can manage only posts assigned to their username.
- Blocked route and post ownership attempts are recorded in the audit log.

Run the local security baseline check with:

```text
php radpress/tests/security_baseline.php
```
