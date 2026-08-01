# Security

Batoi Press establishes secure defaults and structure:

- Sensitive directories include `.htaccess` deny rules.
- HTML content is sanitized before rendering.
- Configuration is JSON and not executable PHP.
- Installer access is blocked by `radpress/config/installed.lock`.
- Password hashes use `password_hash()`.
- Secure, HTTP-only, SameSite session cookies are paired with configurable idle
  and absolute authenticated-session lifetimes.
- Authenticated sessions are inventoried by hashed identifier with bounded
  source metadata. Users can revoke other sessions after password and, when
  enabled, MFA verification; revocation takes effect on the next request.
- Standards-based TOTP two-factor authentication is available to every account.
  Secrets use authenticated encryption under `BATOI_PRESS_SECRET_KEY` or a
  generated `0600` host key in `radpress/data/security/master.key`.
- Recovery codes are displayed once, stored only as password hashes, consumed
  on use, and required alongside the password when MFA is used to change
  machine credentials.
- CSRF tokens protect admin write forms.
- File-backed login rate limiting protects login attempts.
- Admin routes redirect to login when not authenticated.
- Media uploads use allowlisted extensions, generated filenames, size limits,
  executable-content denial, and MIME/signature-to-extension validation.
- Admin write actions record audit log entries.
- Admin route access is enforced by role before controllers run.
- Author-role users can manage only posts assigned to their username.
- Blocked route and post ownership attempts are recorded in the audit log.
- A versioned machine-access token repository foundation stores only password hashes, restricts tokens to explicit scopes, supports expiry and revocation, and keeps token metadata out of public routes.
- Every routed response receives MIME-sniffing, frame, referrer, and permissions
  safeguards. HTTPS responses receive HSTS. CSP is emitted in report-only mode
  by default so custom themes can be audited before switching to `enforce`.
- Stable updates require Ed25519 signatures over canonical release metadata and
  every installable file checksum. Public verifier keys ship by key ID;
  private release keys remain offline in excluded runtime security storage.

Run the local security baseline check with:

```text
php radpress/tests/security_baseline.php
php radpress/tests/machine_access.php
php radpress/tests/mfa_security.php
php radpress/tests/release_signature.php
```

## Browser header configuration

Configure `security.headers.csp_mode` as `report-only`, `enforce`, or `off`.
Keep report-only during a theme compatibility review, then use `enforce` after
required image, frame, script, and style sources are represented by a narrow
policy. Batoi Press never adds arbitrary request values to the policy.

The generated local encryption key and all encrypted runtime secrets are
excluded from release packages. Back it up through an operator-controlled
secret process; losing the key requires MFA recovery/reset rather than exposing
the protected value.

Admin → Security includes read-only diagnostics for Sodium, signed-update
policy, private runtime storage, ZIP support, and HTTPS. A Review result is an
operator prompt, not an automatic configuration change.

## Machine Access

Version 2.0 exposes its governed API and MCP routes only after authentication,
rate limits, audit attribution, atomic file replacement, revision preconditions,
idempotency, and protocol tests. OAuth access for end-user Claude and ChatGPT
connections uses an established external OAuth 2.1/PKCE authorization server;
an Admin Console session or password is never an API credential.
