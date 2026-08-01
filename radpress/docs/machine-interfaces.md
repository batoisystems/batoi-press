# Machine Interfaces

Batoi Press 2.0 exposes governed read and content-mutation services through a
versioned JSON API and Model Context Protocol (MCP). The Admin editors, API,
and MCP use the same revision and repository boundaries for page and post
writes. Neither machine interface calls an AI provider; outbound
model access belongs to the integrated Batoi AIF feature.

## Current security boundary

- Send access tokens only as `Authorization: Bearer …`; query-string tokens are
  not accepted.
- Tokens are hashed at rest, scoped, expiring, revocable, and attributable to
  their issuer. The plaintext value is returned only when a token is issued.
- Use HTTPS for every non-local connection. Do not expose a development server
  by binding it to a public network interface.
- `site:read` permits site and menu reads. `content:read` permits page/post
  lists, detail reads, search, and fetch.
- `content:write` permits creating drafts and updating existing drafts with an
  exact expected revision. It cannot publish.
- `content:publish` permits the distinct publish operation. It does not imply
  draft-read or draft-write access, so grant the scopes a client actually needs.
- Requests are rate-limited by source address during failed authentication and
  by token after authentication.
- MCP validates any supplied `Origin` against the site's configured origin and
  `security.machine_allowed_origins`.
- Machine reads and writes are audit-attributed to the token ID and request
  correlation ID. Credentials, full request
  bodies, and content bodies are not copied into audit details.
- CORS is not enabled. Add exact trusted origins only when a real browser-based
  client requires them.

Personal access tokens are the initial operator/development credential. Remote
end-user connections from ChatGPT or Claude use an established external OAuth
2.1 authorization server; do not weaken authentication or put a token in a
public connector URL while that provider is being configured.
Owners issue and revoke these credentials under **Admin → Connections**. The
console requires current-password reauthentication, enforces an expiry, shows
the secret once, disables response caching, and retains only safe token metadata
afterward. Draft-write and publish scopes are separate choices.

## OAuth resource-server configuration

Press deliberately does not implement an authorization server from scratch.
Configure an established provider under `security.oauth`; that provider owns
login, consent, Authorization Code + PKCE, client identification/registration,
refresh tokens, and revocation. Press remains the resource server and performs
full access-token validation on every request.

```json
{
  "oauth": {
    "enabled": true,
    "issuer": "https://identity.example.com",
    "resource": "https://press.example.com/mcp",
    "authorization_servers": ["https://identity.example.com"],
    "jwks_uri": "https://identity.example.com/.well-known/jwks.json",
    "allowed_jwks_hosts": ["identity.example.com"],
    "scopes_supported": ["site:read", "content:read"]
  }
}
```

When enabled, Press publishes
`/.well-known/oauth-protected-resource` (and the `/mcp` path variant), adds its
URL to bearer challenges, and declares per-tool OAuth schemes. JWT verification
checks the signing key, issuer, exact MCP audience/resource, validity window,
and allowlisted scopes. JWKS downloads require HTTPS, are size/time bounded,
are host-pinned to the issuer or an explicit allowlist, and use a short cache
with a bounded stale fallback. The bundled verifier is locked in
`radpress/composer.lock`; run `composer audit --working-dir=radpress --no-dev`
as part of security review.

## JSON API

The API root is `/api/v2`. Read routes are:

- `/api/v2`
- `/api/v2/site`
- `/api/v2/pages` and `/api/v2/pages/{id-or-slug}`
- `/api/v2/posts` and `/api/v2/posts/{id-or-slug}`
- `/api/v2/menus` and `/api/v2/menus/{id-or-key}`

Page and post lists accept `q`, `status`, `limit` (1–100), and an opaque
`cursor`. Single-resource responses include an `ETag` derived from their
revision. A valid `X-Request-Id` is preserved; otherwise Press creates one.
Errors use a stable `{error: {code, message}, request_id}` envelope.

Draft mutation routes are:

- `POST /api/v2/pages` and `POST /api/v2/posts` create drafts and require
  `content:write`, JSON, and an `Idempotency-Key` of 8–128 safe characters.
- `PATCH /api/v2/pages/{id-or-slug}` and the post equivalent update drafts and
  require `content:write` plus the current quoted or unquoted revision in
  `If-Match`.
- `POST /api/v2/pages/{id-or-slug}/publish` and the post equivalent require
  `content:publish`, `If-Match`, and `Idempotency-Key`.

Creates always remain drafts even if a payload contains a status field. Updates
refuse non-drafts. All writes are bounded, sanitized by the normal repository,
serialized by content-type locks, snapshotted, atomically replace individual
files, return revisions and safe changed-field names, and never return or audit
the bearer token. An idempotency key replay returns the original result; using
the same key with different input returns `409 idempotency_conflict`.

Example:

```sh
curl https://press.example.com/api/v2/pages?status=draft \
  -H "Authorization: Bearer YOUR_ONE_TIME_TOKEN" \
  -H "X-Request-Id: editorial-review-001"
```

Create a draft:

```sh
curl https://press.example.com/api/v2/pages \
  -X POST \
  -H "Authorization: Bearer YOUR_ONE_TIME_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: editorial-page-0001" \
  --data '{"title":"New page","slug":"new-page","body":"<p>Review before publishing.</p>"}'
```

## Streamable HTTP MCP

The MCP endpoint is `/mcp`. It implements JSON-RPC over Streamable HTTP with
protocol negotiation for `2025-11-25` and the supported compatibility
versions. POST responses use `application/json`. The current stateless server
does not initiate server-to-client streams, so GET returns `405 Method Not
Allowed` as permitted by the transport specification.

Capabilities:

- Tools: `search`, `fetch`, `site_get`, `page_list`, `page_get`, `post_list`,
  `post_get`, `menu_list`, and `menu_get`.
- With `content:write`: `page_create_draft`, `page_update_draft`,
  `post_create_draft`, and `post_update_draft`.
- With `content:publish`: `page_publish` and `post_publish`.
- Resources: `batoi://site`, `batoi://menus`, and templates for individual
  pages, posts, and menus.
- Tools return structured content mirrored as JSON text for client
  compatibility. Mutation tools carry accurate read-only/idempotent annotations
  and are omitted entirely when the connection lacks their scope.
- Search/fetch results include canonical URLs so compatible clients can cite
  the source content.

Clients should send both accepted response types and the negotiated protocol
version after initialization:

```text
Accept: application/json, text/event-stream
Content-Type: application/json
MCP-Protocol-Version: 2025-11-25
Authorization: Bearer …
```

Content returned by pages and posts can contain untrusted third-party text.
Clients must treat it as data, not as instructions. Draft operations cannot
publish, and publishing is explicit. No upload, deletion, user administration,
update installation, raw filesystem access, arbitrary URL fetch, or arbitrary
code tool is exposed.
