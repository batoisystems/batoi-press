# Machine Interfaces

Batoi Press 2.0 exposes governed read and content-mutation services through a
versioned JSON API and Model Context Protocol (MCP). The Admin editors, API,
and MCP use the same revision and repository boundaries for page and post
writes. Neither machine interface calls an AI provider; outbound
model access belongs to the integrated Batoi AIF feature.

## Current security boundary

- Machine credentials must resolve to a current, enabled local owner, admin,
  or editor. Scopes are intersected with the current role on every request;
  authors/viewers are denied until per-record machine ownership is supported.
  Existing PAT `issued_by` values must match exactly one local username or the
  credential must be reissued. Token possession alone does not grant authority.
- Optional `security.machine_allowed_scopes` further limits every connection;
  an empty array disables machine access. Editors cannot use `site:write` or
  `audit:read`. Current user and policy files are read afresh during authorization.
- Send access tokens only as `Authorization: Bearer …`; query-string tokens are
  not accepted.
- Tokens are hashed at rest, scoped, expiring, revocable, and attributable to
  their issuer. The plaintext value is returned only when a token is issued.
- Use HTTPS for every non-local connection. Do not expose a development server
  by binding it to a public network interface.
- `site:read` permits site and menu reads. `content:read` permits page/post
  lists, detail reads, taxonomy counts, search, and fetch.
- `site:write` together with `site:read` permits navigation, widget and public-settings proposals, not
  unattended public changes. Only current owner/admin principals can use it;
  it does not permit arbitrary configuration or executable theme edits.
- `content:write` permits creating drafts and updating existing drafts with an
  exact expected revision. It cannot publish.
- `content:publish` permits a publication proposal requiring review in Press. It does not imply
  draft-read or draft-write access, so grant the scopes a client actually needs.
- `media:read` permits media reads without access to page/post drafts. Existing
  `content:read` grants retain media-read compatibility. `media:write` plus
  either read scope permits upload/metadata proposals, not immediate publication.
  Choose these explicitly in the Connections custom profile.
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

Connections now offers read-only, draft-editor, publisher, and custom profiles.
An owner can delegate a connection to an active local admin/editor; the recorded
issuer remains the owner while authorization uses the delegated principal.
The screen shows effective scopes and policy-blocked credentials rather than
equating unexpired with authorized. Newly issued credentials record the account
creation timestamp to prevent reassignment to a replacement account with the
same username; legacy tokens cannot predate the current account's creation.

Rotate a token with current-password/MFA reauthentication to replace its secret
atomically. The previous secret immediately stops working; scope, principal,
and expiry do not change. Revoked/expired tokens cannot be rotated back to life.
Last-use metadata is recorded after authorization, at most once per minute per
credential. It is operational evidence, not a complete request log.

The Connections screen generates canonical per-installation Codex TOML and
Claude Code JSON examples containing only an environment-variable reference.
Use a client secret store/environment, never source-controlled plaintext tokens.
Hosted OAuth needs separate provider/client acceptance; generated configuration
is not evidence that a particular client version has passed a live test.

### Existing credential migration and rollback

Connections reports **Legacy issuer mapping** for credentials without an explicit
principal/account-creation binding and counts active credentials needing review.
The report is read-only. An unambiguous issuer may continue to resolve to its
current active account, constrained by current role and site policy; missing,
ambiguous, disabled or replacement accounts fail closed. Legacy status is not
permission to bypass those checks. Rotation changes a secret, not its identity
binding, and therefore does not migrate a legacy credential.

1. Before rollout, retain a private application/config/data backup using the
   existing backup procedure. Never attach credential stores to tickets or commits.
2. In Connections, review each legacy principal and effective scopes. Resolve
   pending proposals under that connection: approve only intended changes or
   reject them. A replacement connection cannot take ownership of old proposals.
3. Issue a separately named, expiring account-bound replacement with no broader
   grants. Update the client's secret store, verify site identity and a harmless
   permitted read, and confirm a forbidden action remains unavailable.
4. Revoke the old connection and verify its old secret is rejected. Retain the
   revoked metadata for audit. Do not delete/recreate accounts to make an old
   issuer match, or hand-edit token records to recover access.

If the client cutover fails, stop its use of the replacement; do not publish
pending changes to test connectivity. Before revocation, the old credential can
only retain the access allowed by current policy. After revocation it must stay
revoked: issue another narrowly scoped replacement instead of restoring an old
token-store backup. An application rollback must preserve current credential
revocations, local OAuth bindings and proposal/recovery data. Do not run an older
release that omits the identity checks while keeping machine endpoints exposed;
disable those endpoints at the web server until a compatible build is available.
Do not restore runtime data wholesale over newer content or pending transactions.

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
    "scopes_supported": ["site:read", "content:read"],
    "bindings": [{
      "issuer": "https://identity.example.com",
      "subject": "provider-stable-subject",
      "client_id": "registered-client-id",
      "username": "existing-local-administrator",
      "enabled": true,
      "scopes": ["site:read", "content:read"]
    }]
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

JWTs require a nonempty subject, a future integer expiry, and a client identity
in `client_id` or `azp` (if both exist, they must agree). Issuer and audience
comparison is exact, including trailing slashes. Tokens larger than 16 KiB are
rejected. Configure a direct, allowed HTTPS JWKS URL: redirects are not followed.

Owners use **Admin → Connections → OAuth account links** to link a provider's
verified subject and client ID to an active local administrator, with a scoped,
expiring grant. Linking/revocation require CSRF and password/MFA step-up. Obtain
identifiers from the trusted provider, not from an unverified token or an email
guess. Linking does not configure the provider or certify client interoperability.

New consent records are stored privately in
`data/integrations/oauth-bindings.json`; provider configuration remains in
`security.json`. Explicit configuration bindings are supported for operators,
but subject-only legacy bindings are denied and displayed as requiring re-linking.
Managed records override matching configuration grants, including after revocation.
Re-linking retains old grant history but uses a new connection ID, so revoked
proposals cannot regain authority through the replacement grant. Principal
creation identity, current role, token scopes, grant scopes/expiry and site policy
are all checked. Revocation is immediate locally and independent per client;
provider consent/token revocation remains a separate provider operation.

## JSON API

The API root is `/api/v2`. Read routes are:

- `/api/v2`
- `/api/v2/site`
- `/api/v2/pages` and `/api/v2/pages/{id-or-slug}`
- `/api/v2/posts` and `/api/v2/posts/{id-or-slug}`
- `/api/v2/taxonomies`
- `/api/v2/media` and `/api/v2/media/{stable-id}`
- `/api/v2/content-health`, optionally filtered with `id`
- `/api/v2/menus` and `/api/v2/menus/{id-or-key}`
- `/api/v2/activity` (requires explicit `audit:read`, owner/admin principals only)

Activity reports are also available through MCP `activity_list`. Both accept
`limit` (1–100, default 25) and optional exact `action`/`outcome` filters.
They inspect at most the last 1 MiB and 2,000 log lines, newest complete entries
first. `window_truncated` means older history was not scanned;
`results_truncated` means additional matching entries exist in that window.
This is not a complete audit export or a paginated historical search. Use the
administrator audit screen for historical investigation. Reports expose event
ID, time, actor, action, outcome, and validated proposal/revision identifiers;
IP addresses, arbitrary targets, query values, and raw details are excluded.
The existing per-connection request limit applies. Audit access is deliberately
not part of the read/editor/publisher defaults; an owner must grant it explicitly.

### Navigation proposals

Read a menu using `GET /api/v2/menus/{key}` or MCP `menu_get`. The result contains
the storage `key` and integer `revision`. To propose changes, send JSON containing
only `name`, `location`, and/or `items` to
`POST /api/v2/menus/{key}/proposals`, with `If-Match: "3"` (the actual integer
revision) and `Idempotency-Key`. MCP exposes the same operation as
`menu_propose_changes`, with `key`, `expected_revision`, `changes`, and
`idempotency_key`. An omitted field is preserved; supplying `items` replaces
the entire ordered menu list. Read the existing list first and preserve entries
the administrator did not ask to change. No menu-delete operation exists.

The operation returns a pending proposal and the existing Press review URL.
Only an owner/admin may approve website changes after password/MFA verification.
The menu remains unchanged until approval. Use
`GET /api/v2/menu-proposals/{proposal_id}` or MCP `menu_proposal_get` to check
the originating connection's result; these need `site:read`, not draft-content
access. Neither transport exposes an approval shortcut.

Validation shares the menu repository's item, cycle and nesting rules. Machine
requests also reject unknown fields, invalid types, credential-bearing URLs and
ambiguous encoded paths. Internal destinations must resolve to currently public
pages/posts or supported post archives; custom application routes are not currently
supported by the machine validator. No external destination is fetched. Approval
rechecks destinations, connection authority and the complete menu revision hash;
concurrent browser changes cause a conflict instead of being overwritten.

Menu application uses a single-document atomic replacement and a private journal
under `data/integrations/menu-transactions/`. Reads and browser saves reconcile
interrupted journal state before proceeding. Independent receipts preserve evidence
of a committed operation even after later edits. Owner/admin **Reconcile receipt**
in Proposed changes records whether the operation committed or was not applied;
it never reapplies the menu. Unexpected external file changes stop recovery for
operator review. Preserve the journal and backups when investigating; this protects
process interruptions, not a guarantee against hardware or filesystem failure.

### Widget proposals

`GET /api/v2/widgets` / MCP `widgets_get` returns the ordered sidebar widget
list and its `sha256:` revision. Prepare a replacement list using
`POST /api/v2/widgets/proposals` with JSON `{"widgets":[...]}`, `If-Match`
and `Idempotency-Key`; or use MCP `widgets_propose_changes` with `widgets`,
`expected_revision` and `idempotency_key`. Both require `site:read` plus
`site:write`. Preserve unrequested widgets when constructing the replacement list.
Use `GET /api/v2/widget-proposals/{proposal_id}` / `widget_proposal_get` for
connection-owned status. Approval uses the same password/MFA-protected Press
review screen and current owner/admin checks as navigation.

Supported widget types/targets match the existing admin editor. The shared
normalizer sanitizes HTML, bounds the list to 50 widgets and galleries to 24
images, rejects unknown fields and unsafe gallery/signup URLs, and retains the
required Recent Posts widget. No remote media is downloaded and no executable
widget source is accepted. Widget rendering still depends on the active theme
using Press sidebar/widget surfaces.

Browser saves now carry an expected revision too: forms opened before an update
must be reloaded. Single-document replacement, snapshots and private receipts
under `data/integrations/website-transactions/` prevent replay and support
interrupted-write reconciliation. Public rendering and static export read through
the same recovery-aware widget repository. Unexpected external edits block
recovery rather than being overwritten. Filesystem errors returned to machine
clients do not include private storage paths or document bytes.

### Public settings and appearance

Read `GET /api/v2/public-settings` / MCP `public_settings_get` for allowlisted
settings, a revision, and active-theme capabilities. Submit only changed fields
to `POST /api/v2/public-settings/proposals` with `If-Match` and
`Idempotency-Key`; MCP `public_settings_propose_changes` takes `changes`,
`expected_revision`, and `idempotency_key`. Both require `site:read` plus
`site:write` and return a pending owner/admin review. Read connection-owned
status through `/api/v2/settings-proposals/{proposal_id}` or
`settings_proposal_get`. Proposals never contain the raw site configuration.

Allowed fields are name, tagline, locale, timezone, posts-per-page, appearance
mode/toggle, load-more preference, light/dark palette tokens, primary/accent
colors, a plain font-family name, footer texts/columns, and validated footer
links. Colors use six-digit hex; booleans and counts are typed and bounded.
Palette updates merge the specified tokens with the current validated palette.
Canonical URL, active theme, logo uploads, remote stylesheets, service keys,
integrations and executable source are deliberately not machine-editable.
Unrelated configuration keys are preserved and never returned to the client.

Bundled-theme appearance surfaces are supported. Custom themes must declare
`appearance_tokens`, `footer_layout`, and/or `widgets` in their manifest's
`supports` list. Capability responses distinguish a declaration from actual
custom-theme certification; they do not promise that arbitrary theme code renders
the options correctly. Unsupported appearance changes are rejected. Core public
identity/localization changes remain available. Approval repeats validation
against the current theme and complete site revision.

Site changes use the same fixed-resource document journal/receipt mechanism as
widgets. Configuration loading reconciles interrupted site writes. Browser
Settings forms also require a revision, while theme activation/homepage updates
use the shared store and preserve unrelated settings. Existing combined browser
Settings saves also write editor/integration files; those are not a multi-file
transaction. A concurrent site conflict or later integration failure is reported
explicitly, and referenced newly uploaded brand assets are retained after a site
commit. Machine public-settings proposals write only the site document and cannot
change editor/integration files.

Page and post lists accept `q`, `status`, `limit` (1–100), and an opaque
`cursor`. Single-resource responses include an `ETag` derived from their
revision. A valid `X-Request-Id` is preserved; otherwise Press creates one.
Errors use a stable `{error: {code, message}, request_id}` envelope.
Supported lifecycle filters are `draft`, `in_review`, `approved`, `scheduled`,
`published`, and `archived`. Content records expose `publish_at`,
`unpublish_at`, reviewer, and current public-visibility state. Taxonomies return
shared category/tag names, stable slugs, total counts, and public counts.
Media responses contain stable IDs, public URLs, type, MIME type, byte size, and
modified time, but never server filesystem paths. Content-health responses are
bounded deterministic checks for missing search descriptions, heading
structure, image alt attributes, and stale content; they do not invoke AIF or
an external model.

### Governed media

- `POST /api/v2/media/uploads` accepts `{name, content_base64, metadata?}` and
  an `Idempotency-Key`. It stages a private file and returns HTTP 202 with a
  review URL. Its future asset URL is not a publication confirmation.
- `POST /api/v2/media/{asset_id}/proposals` accepts a metadata patch (`title`,
  `alt`, `caption`), `Idempotency-Key`, and the exact `If-Match` revision from
  `GET /api/v2/media/{asset_id}`. Revisions cover both file bytes and metadata.
- `GET /api/v2/media-proposals/{proposal_id}` reports status only to the
  originating connection. Check for `applied` before using the new public URL.
- MCP equivalents are `media_propose_upload` (`upload`, `idempotency_key`),
  `media_propose_metadata` (`id`, `changes`, `expected_revision`,
  `idempotency_key`) and `media_proposal_get`. Read through `media_list`,
  `media_get` or the `batoi://media` resources.

Uploads accept canonical base64 JPEG/PNG/GIF/WebP and UTF-8 text/Markdown,
capped at 768 KiB or a smaller installation limit. MIME inspection is required;
images are bounded to 8192 pixels per dimension and 16,777,216 total pixels.
The machine allowlist intersects the installation's configured extensions.
PDF/SVG, scripts/styles, multimedia, remote URL ingestion, replacement and
deletion are not exposed by this initial machine upload operation. Existing
browser uploads retain their separate configured type limits.

Only current owners/admins can approve media changes in Proposed changes after
password/MFA verification. The review binds site, connection, scopes, target,
exact bytes and metadata; it rechecks current permission, expiry and revisions.
The private inspection link serves only the pending bounded file to an active
owner/admin, with no-store, nosniff and sandbox headers. Uploaded content and
metadata remain untrusted data, never authorization or executable instructions.

Library metadata is plain text (title 200, alt 1000, caption 2000 UTF-8 bytes).
The browser Media editor uses the same store and revision checks. Changes do
not rewrite existing page/post embeds: those retain their own contextual alt
text. Use the asset's stable ID and canonical URL when preparing new content.
Metadata mutation of script/style assets and files over 25 MiB is not supported.

Private state lives below `data/integrations/media/`, excluded from public
exports and release payloads. Defaults are 32 MiB of staged base64 across the
site, 8 MiB per connection, 256 MiB of currently present machine-uploaded files,
512 staging-history records and 4096 metadata records. Administrators may lower
these through `security.uploads.machine_limits`: `staged_bytes`,
`connection_bytes`, `managed_bytes`, `staging_records`, `metadata_records`.
Temporary bytes are released after approval/rejection/recovery; staging scans
also release unapproved bytes older than 24 hours. Manifests and receipts are
retained. History-limit errors require operator archiving, not silent deletion.

Publication uses a common media mutation lock, a private journal, independent
receipts, and a same-directory hard link to publish complete bytes without
overwriting a destination. The host must support this filesystem operation;
there is no non-atomic fallback. An interrupted approval reconciles from actual
file/metadata hashes and refuses unexpected external edits. Browser replacement
and deletion share the lock and reconcile pending journals before proceeding.
Public asset lookup and static export exclude symlinks and hidden temporary
files. These checks are not an antivirus or complete file-format certification.

Draft mutation routes are:

- `POST /api/v2/pages` and `POST /api/v2/posts` create drafts and require
  `content:write`, JSON, and an `Idempotency-Key` of 8–128 safe characters.
- `PATCH /api/v2/pages/{id-or-slug}` and the post equivalent update drafts and
  require `content:write` plus the current quoted or unquoted revision in
  `If-Match`.
- `POST /api/v2/pages/{id-or-slug}/publish` and the post equivalent require
  `content:publish`, `If-Match`, and `Idempotency-Key`. They return HTTP 202 and
  a pending proposal, not immediate publication.
- `POST /api/v2/pages/{id-or-slug}/proposals` (and posts) accepts
  `{"changes": {...}, "action": "retain"}` with `If-Match` and `Idempotency-Key`.
  `retain` keeps the current lifecycle state and requires `content:write`.
  `publish`, `schedule`, and `unpublish` additionally require `content:publish`;
  changed fields require `content:write`. Unpublish archives rather than deletes.
- `GET /api/v2/proposals/{proposal-id}` returns the originating connection's
  proposal status under `content:read`, not the private review receipt.
- `POST /api/v2/proposals/{proposal-id}/restore` with `{}`, `If-Match`, and
  `Idempotency-Key` prepares restoration from an applied proposal belonging to
  the connection. Requires `content:read` and `content:write`. It creates a new
  review request, never rewinds audit history. Current visibility, scheduling,
  reviewer and executable page assets are preserved; use a separate lifecycle
  proposal to change publication state.

Creates always remain drafts; unsupported fields (including status and executable
page CSS/JavaScript) are rejected. Direct updates refuse non-drafts. All writes are bounded, sanitized by the normal repository,
serialized by content-type locks, snapshotted, atomically replace individual
files, return revisions and safe changed-field names, and never return or audit
the bearer token. An idempotency key replay returns the original result; using
the same key with different input returns `409 idempotency_conflict`.

### Administrator review of public-impact changes

Proposals keep the current live content untouched. Repository preparation validates
and normalizes the requested values without saving. A private, no-store review
screen at **Admin → Proposed changes** displays escaped before/after values,
site identity, connection, action, and URL consequences. Preview content is not
executed. Proposals expire within 24 hours or at the originating credential's
expiry, whichever is earlier.

Give the returned `review_url` to the administrator. The review POST requires
CSRF and password/MFA step-up. Under the shared content lock it checks the exact
proposal hash, current reviewer permissions, assigned reviewer, originating
connection status/scopes, base revision, and current hierarchy URLs. It then
applies the approved changes. Client-supplied approval flags cannot bypass this
workflow. An already processed proposal cannot be applied again. Clients must
check `proposal_get`/the status route for `state: applied` before reporting success.

Rejection is separate from approval: an authorized local reviewer can close a
pending editorial request after its expiry or connection revocation without
changing content or restoring credential access. Owners/admins can also reject
requests whose target no longer exists. Editors still need the current target
and must satisfy its assigned-reviewer restriction. Exact proposal hash,
current local role, CSRF and password/MFA checks remain required. Expired pending
requests show a reject-only form; processed proposals cannot be rejected again.

This changes the earlier immediate machine-publish contract: consumers must
handle HTTP 202 and pending review. No externally callable direct publish path
remains in the API or MCP. Ordinary authenticated admin editing is unchanged.

Page/post saves now use a recoverable storage transaction covering metadata,
body, directory rename, and affected child-parent references. Repository readers
share its lock, so they do not observe intermediate file combinations. Preparation
is revision-checked again under that storage lock. Compatible private version
snapshots are retained before a write starts.

An interrupted pending journal is rolled back before the next repository read
or write. Recovery preflights every affected file against the expected before or
after bytes. Unexpected external edits or conflicting directories stop recovery
for operator review; they are not overwritten. Only files created by the failed
transaction can be removed during rollback. This covers process interruptions;
it does not replace filesystem backups or guarantee recovery from hardware loss.

An unfinished `applying` approval receipt never permits a blind second application.
An owner/admin can use **Reconcile receipt**, with password/MFA step-up, to resolve
it from the independent storage transaction receipt. A committed write is marked
applied without rewriting content; a rollback is marked failed and needs a new
proposal. An external-edit conflict remains blocked. Editorial restoration is a
separate reviewed operation, not a way to bypass interrupted-write recovery.

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
  `post_get`, `taxonomy_list`, `media_list`, `media_get`,
  `content_health_check`, `menu_list`, `menu_get`, and `proposal_get`.
- With `content:write`: `page_create_draft`, `page_update_draft`,
  `post_create_draft`, `post_update_draft`, `page_propose_changes`, and
  `post_propose_changes`.
- With `content:publish`: `page_publish` and `post_publish` create review requests.
- With both `content:read` and `content:write`: `proposal_restore` prepares a
  reviewed editorial restoration from an applied proposal for this connection.
- With `site:read`: widget/public-settings reads and website proposal status;
  additionally granting `site:write` enables menu, widget and allowlisted
  public-settings proposals. These never bypass administrator approval.
- With `media:read` (or compatible `content:read`): media reads and proposal
  status; additionally granting `media:write` enables bounded private upload
  staging and metadata proposals. Only administrator approval publishes them.
- With `audit:read` and a current owner/admin role: `activity_list` returns a
  bounded, reduced activity projection.
- Resources: `batoi://site`, `batoi://menus`, `batoi://taxonomies`,
  `batoi://media`, and templates for individual pages, posts, media, and menus.
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
publish, and public-impact changes require approval in Press. Bounded uploads
remain private until approval; unrestricted uploads, deletion, user
administration, installation updates, raw filesystem access, arbitrary URL
fetching, and executable-code tools are not exposed.

### Optional HTTP interoperability regression

`radpress/tests/mcp_http.mjs` exercises the actual loopback HTTP transport using
the official `@modelcontextprotocol/client` SDK, independently of PHP controller
unit tests. Install the pinned test dependency outside the checkout (no runtime
Node dependency is added to Press), then supply that prefix:

```sh
press_sdk_dir=$(mktemp -d)
npm install --prefix "$press_sdk_dir" --ignore-scripts --no-audit --no-fund --save-exact @modelcontextprotocol/client@2.0.0
node radpress/tests/mcp_http.mjs "$press_sdk_dir"
```

The runner requires Node with built-in fetch and PHP on PATH (or an explicit
`PRESS_TEST_PHP` executable). It creates and removes only synthetic fixtures,
binds PHP to `127.0.0.1`, keeps generated tokens in memory, and exercises root
and `/testsite/public_html` endpoint paths. No model calls, real-site credentials,
global client configuration, or public deployment are involved. This is protocol
and SDK evidence, **not** certification of Codex, Claude Code, hosted Claude,
OAuth consent, or shared-host HTTPS behavior; record those acceptance results
separately.

Optionally pass an absolute Claude Code executable path as the second runner
argument to test that client's MCP connection health and revoked-token refusal:

```sh
node radpress/tests/mcp_http.mjs "$press_sdk_dir" /absolute/path/to/claude
```

This mode uses a temporary `CLAUDE_CONFIG_DIR`, an environment-backed synthetic
credential and `claude mcp list`, with nonessential client traffic disabled and
the model API URL pointed at the loopback fixture. It never launches a model
session or modifies the user's normal client configuration. A successful
health check is only connection acceptance, not proof of Claude tool execution,
OAuth login or hosted-Claude compatibility. The report records the actual
client version. See the official [MCP status guidance](https://code.claude.com/docs/en/mcp)
and [configuration environment variables](https://code.claude.com/docs/en/env-vars).

## Proposal storage and installation identity

Proposals include the installation's canonical base URL in their integrity
hash. A proposal prepared for another URL cannot be reviewed or applied after
a site move merely because its files and connection records were copied.
Prepare new proposals for the destination installation. The review queue marks
unreadable, altered, or different-installation records as unavailable without
displaying their unverified metadata or offering approval actions. Other valid
proposals remain reviewable. Preserve unavailable records for operator
investigation; do not edit their hashes or site field to force acceptance.

Editorial route-changing proposals also preserve the exact affected descendant
URLs, including draft descendants, in the integrity-protected review record.
The review screen lists old and proposed child URLs; no redirects are created.
Approval rechecks the route list and the hierarchy revision. Storage rechecks
that revision under its write lock, so a child added or moved after review
cannot be silently included in a parent rename. Hierarchy changes elsewhere
in the same page/post collection conservatively require a fresh proposal too.
Older route-changing proposals without this hierarchy proof must be prepared
again; ordinary proposals that do not change URLs remain compatible.

## Editorial scheduling semantics

The file-backed runtime does not require cron for correctness. A `scheduled`
record is public only when its valid publish time is due. A `published` record
may also carry a publish time. Either state becomes private at its optional
unpublish time. Draft, in-review, approved, and archived records are never
public. Public routes, feeds, sitemaps, dynamic lists, and static exports use
the same visibility check. Existing draft/published records without the new
fields remain compatible.

Machine scheduling and unpublishing are proposals: preparation does not change
the stored record or its current visibility. Scheduling requires a valid
`publish_at`; after approval, visibility starts at that instant and ends at
`unpublish_at`, if supplied. Use explicit timezone offsets in date values.
Unpublishing archives the record without deleting its body or changing its ID.
To reverse archival, prepare and approve a new `publish` proposal against the
current revision. Publication preserves existing dates: to publish immediately
outside an old schedule, include an appropriate `publish_at` and clear an
obsolete `unpublish_at` with an empty string in the reviewed changes. These
field edits require `content:write` in addition to `content:publish`.

Admin saves may assign a reviewer and append a bounded workflow note. Press
records the actor, timestamp, previous and resulting states, reviewer, and note
in bounded content metadata. Workflow notes are not rendered on the public
site or copied into machine audit logs.

A machine proposal's `workflow_note` is trimmed, shown in its review diff and
included in its integrity hash. It appends to workflow history only when that
exact proposal is approved, attributed to the approving administrator. Preparing
or rejecting a proposal does not append history; replay cannot duplicate it.
Restoration copies editorial values, not historical notes or actors.
