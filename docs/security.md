# Security

Phase 1 establishes secure defaults and structure:

- Sensitive directories include `.htaccess` deny rules.
- HTML content is sanitized before rendering.
- Configuration is JSON and not executable PHP.
- Installer access is blocked by `config/installed.lock`.

Future phases add authenticated admin sessions, CSRF protection, rate limiting, media upload validation, and audit-backed write actions.

