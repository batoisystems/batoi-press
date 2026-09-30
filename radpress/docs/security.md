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

The built-in contact form supports **reCAPTCHA v2 checkbox** keys, configured
as a pair in Settings. A partially configured site key without a secret fails
human verification rather than silently bypassing it. A custom v3 form must
implement its own server-side action/score verification; v3 keys are not a
drop-in replacement for the built-in v2 form.

Generated CSP includes Google's path-scoped reCAPTCHA script/connect sources
and both Google frame endpoints when the integration site key is configured or
**Allow reCAPTCHA used by a custom theme** is enabled in Settings
(`recaptcha_custom_theme: true` in `integrations.json`). The switch declares
browser dependencies only: it does not render a widget, add built-in CAPTCHA
keys, bypass configured verification, or implement custom v3 verification.
See [Google's CSP guidance](https://developers.google.com/recaptcha/docs/faq).
For installations using an explicit custom policy, maintain the complete policy
under `headers.content_security_policy` in `radpress/config/security.json`.
This replaces, rather than extends, the generated policy: retain your existing
directives and explicitly authorize necessary inline code by hash/nonce. Include
`https://www.google.com/recaptcha/` and `https://www.gstatic.com/recaptcha/` in
`script-src`, both `https://www.google.com/recaptcha/` and
`https://recaptcha.google.com/recaptcha/` in `frame-src`, and
`https://www.google.com/recaptcha/` in `connect-src`. Do not add whole-origin
wildcards or disable CSP. Omit `upgrade-insecure-requests` from report-only
policies. Test the actual browser response for hosting-injected policies too.

Google Maps embeds are independent of reCAPTCHA. Enable **Allow Google Maps
embeds** in Settings (`google_maps_embed: true` in `integrations.json`) to add
`https://www.google.com/maps/embed` and `https://www.google.com/maps/embed/`
to generated `frame-src`. These cover the shared-map URL and Embed API paths;
they do not authorize the Maps JavaScript API or arbitrary Google frames. Sites
without either opt-in gain no new Google sources. Explicit custom policies
remain authoritative and need the corresponding frame sources added manually.
An error quoting the Google origin can refer to either product: inspect the
actual iframe URL before changing CAPTCHA settings.

Contact email uses UTF-8 HTML for server mail and Mailgun. Visitor fields are
escaped and message line breaks retained; submitted HTML remains literal text.
Mailgun also receives the original plain-text alternative. Server mail carries
MIME headers and quoted-printable encoding. Reply-To is validated at the mail
service boundary as well as by the public contact controller.

Security diagnostics identify generated versus custom policy without exposing
private keys. Contact delivery failures return a neutral visitor message and
request reference; the private audit records that reference, not enquiry content
or raw provider errors.

Theme ZIP uploads undergo non-executing compatibility inspection before
installation. The inspection rejects unsafe paths, links, encrypted entries,
archive-limit violations, likely secrets, prohibited PHP execution operations,
invalid PHP syntax, and direct output from common request globals. Inline
browser code and remote dependencies prevent direct compatibility and require
repair or governed conversion. Inspection is a pre-install gate, not a malware
scanner or proof that a theme is trustworthy; preview and human approval remain
required, and uploaded source is never executed during inspection.

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
