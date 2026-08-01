# Batoi Press 2.0 Threat Model

## Assets and trust boundaries

Protected assets include owner credentials, MFA secrets/recovery hashes,
sessions, machine/OAuth tokens, AIF provider credentials, unpublished content,
audit history, release keys, backups, and executable application/theme files.

The public renderer, Admin session, JSON API, MCP resource server, outbound AIF
provider boundary, update downloader/stager, uploads, and filesystem are distinct
trust boundaries. Inbound content and MCP-returned content are untrusted data.
An AIF suggestion or external-agent instruction never becomes authorization.

## Principal threats and controls

- Credential theft and replay: password hashing, TOTP/recovery step-up, hashed
  machine tokens, expiry/revocation, bounded sessions, session revocation, HTTPS
  requirements, and no query-string credentials.
- Scope escalation/confused deputy: explicit read/write/publish scopes, separate
  publish operations, MCP tool filtering, OAuth issuer/audience/resource checks,
  and no token passthrough.
- Cross-site requests: SameSite/HTTP-only cookies, CSRF on Admin writes, exact
  MCP Origin validation, no default CORS, and browser frame/referrer policy.
- Lost updates/replay: content/menu revisions, `If-Match`, per-type locks,
  idempotency keys, atomic replacements, snapshots, and safe conflicts.
- Stored script/content injection: server-side HTML sanitization, structured
  escaping, safe URL schemes, no arbitrary menu HTML/script fields, CSP rollout,
  and text-only rendering of machine/AIF results.
- Prompt injection/data exfiltration: allowlisted bounded AIF context, disabled
  remote providers by default, explicit field/provider policy, no tools or
  publish authority in AIF, and content treated as data by MCP descriptions.
- Upload/archive execution: extension allowlists, MIME/signature checks,
  generated names, archive entry/budget validation, private runtime directories,
  and no uploaded PHP execution path.
- SSRF/key retrieval: OAuth JWKS HTTPS host allowlisting, issuer pinning,
  private-address denial, bounded redirects/time/size, and bounded stale cache.
- Malicious updates/supply chain: Ed25519-signed public index and internal
  package manifest, pinned public key IDs, ZIP/path checks, per-file hashes,
  runtime-state exclusion, pre-apply backup, health check, and rollback.
- Secret leakage: authenticated encryption under a host key, password/token/
  recovery hashes, one-time secret display, safe audit summaries, excluded
  runtime security storage, and no filesystem paths in machine responses.

## Residual and deployment risks

The host, PHP runtime, web server, selected OAuth authorization server, selected
remote AIF provider, DNS/TLS chain, and administrator device remain trusted.
Compromise of those systems can bypass application controls. CSP remains
report-only until each customized theme is reviewed. TOTP is available in 2.0;
hardware-backed WebAuthn/passkeys are deferred. External Claude/ChatGPT OAuth
interoperability requires the operator to select and register an established
provider; Press deliberately does not implement an authorization server.

Operators should keep `radpress/` outside the web root where possible, enforce
HTTPS, protect/backup keys separately, grant minimum scopes, review audit and
session inventories, keep AIF remote providers disabled until approved, and
install only verified supported releases.
