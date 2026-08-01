# Machine Interfaces

Batoi Press 2.0 exposes one governed read model through a versioned JSON API
and Model Context Protocol (MCP). Both interfaces use the same page, post,
menu, and site read service. Neither interface calls an AI provider; outbound
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
- Requests are rate-limited by source address during failed authentication and
  by token after authentication.
- MCP validates any supplied `Origin` against the site's configured origin and
  `security.machine_allowed_origins`.
- Machine reads are audit-attributed to the token ID. Credentials, full request
  bodies, and content bodies are not copied into audit details.
- CORS is not enabled. Add exact trusted origins only when a real browser-based
  client requires them.

Personal access tokens are the initial operator/development credential. Remote
end-user connections from ChatGPT or Claude require the OAuth authorization
layer described in the 2.0 implementation plan; do not weaken authentication
or put a token in a public connector URL while that layer is being deployed.

## JSON API

The API root is `/api/v2`. All routes currently support `GET` only:

- `/api/v2`
- `/api/v2/site`
- `/api/v2/pages` and `/api/v2/pages/{id-or-slug}`
- `/api/v2/posts` and `/api/v2/posts/{id-or-slug}`
- `/api/v2/menus` and `/api/v2/menus/{id-or-key}`

Page and post lists accept `q`, `status`, `limit` (1–100), and an opaque
`cursor`. Single-resource responses include an `ETag` derived from their
revision. A valid `X-Request-Id` is preserved; otherwise Press creates one.
Errors use a stable `{error: {code, message}, request_id}` envelope.

Example:

```sh
curl https://press.example.com/api/v2/pages?status=draft \
  -H "Authorization: Bearer YOUR_ONE_TIME_TOKEN" \
  -H "X-Request-Id: editorial-review-001"
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
- Resources: `batoi://site`, `batoi://menus`, and templates for individual
  pages, posts, and menus.
- All tools are read-only and return structured content. `search` and `fetch`
  also mirror their structured value as JSON text for client compatibility.
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
Clients must treat it as data, not as instructions. No draft mutation,
publishing, upload, deletion, user administration, update installation, raw
filesystem access, arbitrary URL fetch, or arbitrary code tool is exposed by
the read-only endpoint.
