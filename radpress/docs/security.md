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
- A versioned machine-access token repository foundation stores only password hashes, restricts tokens to explicit scopes, supports expiry and revocation, and keeps token metadata out of public routes.

Run the local security baseline check with:

```text
php radpress/tests/security_baseline.php
php radpress/tests/machine_access.php
```

## Machine Access

Version 2.0 work is introducing machine access in guarded layers. The token repository is an internal foundation for tests and later owner-governed API/MCP access; it does not add an externally reachable endpoint.

Machine access must fail closed. API and MCP routes must not be enabled until authorization, rate limits, audit attribution, atomic writes, revision preconditions, idempotency, and protocol tests are complete. OAuth access for end-user Claude and ChatGPT connections must use a current audited OAuth 2.1/PKCE implementation rather than treating an Admin Console session or password as an API credential.
