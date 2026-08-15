# Batoi UIF and AIF

## Batoi UIF

Batoi Press bundles Batoi UIF 3.0.0 from the neighboring `batoi-uif`
repository for admin, installer, and default-theme screens.

Runtime files:

```text
public_html/assets/uif/uif.css
public_html/assets/uif/uif.iife.js
public_html/assets/uif/uif.life.js
public_html/assets/uif/uif.esm.js
public_html/assets/uif/uif-core.css
public_html/assets/uif/uif-core.js
public_html/assets/uif/uif.js
radpress/uif/manifest.json
radpress/uif/components/
```

`uif.css`, `uif.iife.js`, and `uif.esm.js` are copied from the upstream UIF
distribution. `uif.life.js`, `uif-core.css`, and `uif-core.js` are compatibility
aliases that serve the same 3.0.0 bundles at legacy Press URLs. `uif.js` is the
small Batoi Press initializer wrapper and is not replaced by the upstream build.

`public_html/assets/css/style.css` imports the UIF stylesheet and carries Batoi Press compatibility classes so existing admin and installer screens can adopt primitives incrementally.

Custom public themes may use UIF, but they are not required to.

Admin controllers render through `Batoi\Press\Admin\AdminLayout`, which loads the bundled UIF stylesheet and script.

The admin console uses UIF-backed layout primitives for:

- grouped sidebar navigation
- topbar actions
- page headers
- stats cards
- structured tables
- editor panels
- status and role badges
- notices, empty states, and danger-zone actions

## Batoi AIF

Batoi AIF is an integrated Press feature and remains opt-in. It owns the
assisted-authoring surface, context allowlist, provider routing, feature flags,
usage boundary, audit metadata, and human-review contract. Inbound MCP clients
operate Press through machine scopes; outbound AIF providers prepare native
editor suggestions. Neither interface inherits authority from the other.

Configuration:

```text
radpress/config/aif.json
```

Default behavior:

* `enabled` is `false`.
* Provider is `disabled`.
* No network calls are made.
* Admin can view status at `/admin/aif`.
* Page and post editors include a Batoi AIF panel that accurately reports the
  disabled state instead of implying assistance is active.
* Guarded content-assist actions exist at `/admin/aif/assist` and return a
  disabled response until configured.
* The admin status screen shows readiness, setup requirements, provider availability, feature flags, feature purposes, workspace requirement, configuration file location, and network-call trust boundaries.
* Disabled or unavailable installations keep assist buttons disabled in the UI to avoid implying that AI assistance is active.
* Assist attempts are audit logged with success, failed, or blocked outcomes.

### Local provider

The bundled `local` provider offers deterministic, offline assistance for
content health, SEO metadata, summaries, tags, and outlines. It is useful on
conventional hosting and makes the integrated workflow testable without sending
content to another service. It is still disabled until an owner deliberately
changes `aif.json`:

```json
{
  "enabled": true,
  "provider": "local",
  "workspace_required": false,
  "features": {
    "draft_content": true,
    "seo_assist": true,
    "summarize": true,
    "tags": true,
    "translate": false,
    "alt_text": false,
    "content_health": true
  }
}
```

The editor sends only allowlisted fields to the AIF controller. Body context is
bounded to 50 KB, executable/embed blocks are removed from prepared text, and
unknown form fields are discarded. Requests require an authenticated editor,
CSRF validation, and a per-user/IP rate limit. Responses disable caching.
Audit events record task, provider, request ID, field names, byte counts, and
whether a network was used; prompts and content values are not logged.

Suggestions render as text, never executable markup. Editors must click an
Apply or Copy control, then save normally. Body drafts/outlines are copy-only;
the AIF endpoint cannot publish and does not bypass revisions, sanitization,
roles, or the separate machine publish scope.

Future provider adapters can implement `Batoi\Press\Aif\AifProvider`.
Future Batoi Platform workspace adapters can implement `Batoi\Press\Aif\BatoiWorkspaceAifProvider`.
