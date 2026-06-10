# Security

Phase 1 establishes secure defaults and structure:

- Sensitive directories include `.htaccess` deny rules.
- HTML content is sanitized before rendering.
- Configuration is JSON and not executable PHP.
- Installer access is blocked by `radpress/config/installed.lock`.

Future phases add authenticated admin sessions, CSRF protection, rate limiting, media upload validation, and audit-backed write actions.
Current admin security includes:

- Password hashes with `password_hash()`.
- Secure session cookie flags where available.
- CSRF tokens for login/logout forms.
- File-backed login rate limiting.
- Admin routes redirect to login when not authenticated.

Future phases add media upload validation, role enforcement per action, and audit-backed write actions.
