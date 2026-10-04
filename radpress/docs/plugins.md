# Plugin SDK and custom forms

## Runtime contract

Plugin API `1.0.0` provides optional modules without adding requirements to ordinary page publishing. Custom forms and webhook delivery start disabled. The installation owner enables them through **Admin → Plugins**. Administrators manage form definitions and private submissions through **Admin → Forms**; editors cannot export submissions or manage packages.

Native PHP plugins are trusted executable code with application privileges. Capability declarations are review metadata, not a sandbox. Installation verifies package authenticity and file integrity, not whether its author wrote safe code. Review a package before activation.

## Signed package format

A ZIP contains `plugin.json` at its root and exactly the files in its `files` map. Do not include directory entries. Paths must be relative; symlinks, traversal, duplicate/unlisted files and oversized archives are rejected. Limits are 200 payload files, 5 MiB per file and 50 MiB total/archive. Manifest size is limited to 64 KiB.

```json
{
  "schema": 1,
  "id": "delivery-observer",
  "name": "Delivery observer",
  "version": "1.0.0",
  "api": "1.0.0",
  "kind": "native",
  "entrypoint": "entry.php",
  "compatibility": {
    "php": {"min": "8.3.0", "max_exclusive": "9.0.0"},
    "press": {"min": "3.2.4", "max_exclusive": "5.0.0"}
  },
  "requirements": [],
  "capabilities": ["events.listen"],
  "files": {"entry.php": "REPLACE_WITH_SHA256"},
  "trust": {"key_id": "publisher-key", "signature": "REPLACE_WITH_BASE64_SIGNATURE"}
}
```

Versions and bounds use three numeric components. Minimums are inclusive; upper bounds are exclusive. Incompatible packages may be inspected/installed while disabled; activation and loading require compatible PHP/Press versions and available capabilities. The plugin API version must match exactly. Press reads its recorded version from `config/update.json`; do not change it to bypass compatibility checks.

Requirements use IDs from `RuntimeCapabilities::definitions()`. Accepted capability names are `content.read`, `content.propose`, `media.read`, `audit.write`, `blocks.render`, `admin.routes`, `forms.email`, `forms.webhook`, `events.listen` and `state.write`. Native packages receive a scoped context with the services below. Form services submit through existing configured actions; arbitrary native form-action registration is unavailable. External declarative packages currently carry verified metadata only; author runnable forms in the built-in Forms module.

Calculate each file's SHA-256 before signing. Sign the UTF-8 JSON encoding of the manifest **without** `trust`, using PHP `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and preserving object order. Use Ed25519 detached signatures, encoded in base64. Keep signing private keys outside the website. An operator installs only the publisher's base64 public key in private `radpress/config/plugin-keys.json`:

```json
{"keys":{"publisher-key":"REPLACE_WITH_BASE64_ED25519_PUBLIC_KEY"}}
```

ZIP and Sodium are needed for package installation/signature verification, not core website installation. Signed native packages are reverified before loading. Invalid installed packages remain visible as needing attention and can be disabled, restored from a verified backup, or archived without executing their code. Package install and upgrade leave code disabled for owner review. Upgrades require a higher version; rollback restores the verified prior package and leaves it disabled. Disabling retains state; uninstall archives the package and retains data.

## Event entrypoint

`entry.php` returns a callback accepting `PluginContext`:

```php
<?php
declare(strict_types=1);

use Batoi\Press\Core\PluginContext;

return static function (PluginContext $context): void {
    $context->on('delivery.completed', static function (array $event): void {
        // id: stable delivery ID; success: whether this attempt succeeded.
        // Keep processing bounded and never assume exactly-once notification.
    });
};
```

| Event | Payload | Semantics |
| --- | --- | --- |
| `form.accepted` | `id` string, `form_id` string | Emitted after a successful public submission; excludes visitor values. |
| `delivery.completed` | `id` string, `success` boolean | Emitted after the attempt's queue status is committed; failed attempts can retry. |

Listeners run in registration order. Exceptions are logged without visitor data and do not cancel accepted work. Notifications are best effort: a process crash between commit and notification can lose an event. Plugins must not interpret an event as exactly-once delivery or write directly into governed Admin/API/MCP services. `content.changed` supplies only `id`, `type` and `action` after a governed page/post mutation while plugins are loaded. Core admin requests deliberately skip native loading, so their mutations do not notify native listeners. Use the governed audit feed for durable observation. Plugin callbacks cannot emit core events through their scoped context.

## Owner settings and private state

Declare optional `settings_schema` in the signed manifest. Up to 20 named fields use `string`, `integer`, `boolean` or `enum`, with a label, optional default and required flag. Integer fields can declare `min`/`max`; enum fields declare 1–30 choices. Keys use lowercase letters/digits/underscores (40 characters maximum). Strings are valid UTF-8 and at most 2,000 bytes. These are **non-secret** settings; use private encrypted integration connections for credentials.

```json
{
  "settings_schema": {
    "label": {"label":"Label","type":"string","required":true,"default":"Website"},
    "limit": {"label":"Limit","type":"integer","min":1,"max":10,"default":3},
    "active": {"label":"Active","type":"boolean","default":false}
  },
  "data_schema": 2,
  "migrations": [{"from":1,"to":2,"renames":{"old_name":"name"},"defaults":{"active":false}}]
}
```

Owners use **Configure** in Plugins. Settings saves require CSRF and the displayed revision; stale writes return a conflict. Required/invalid settings block activation. Existing settings incompatible with a new definition can be replaced through Configure. Settings changes take effect on subsequent requests.

`$context->settings()` returns validated settings/defaults. `$context->data()` returns `values` and `revision` for this plugin's private state. Packages declaring `state.write` can call `$context->replaceData($values, $revision)`; stale revisions are rejected under the stable storage lock. Data is a flat object with at most 40 keys, scalar values, 4 KiB per text value and 64 KiB total. This state is private and separate from governed website content. Native PHP is still trusted; these API restrictions do not prevent direct PHP filesystem access.

`data_schema` defaults to 1 and supports integers through 100. Declarative migrations advance one schema at a time using only key renames and missing-key defaults. They run on owner activation, before package code executes. Renames cannot overwrite existing keys. Missing/conflicting steps preserve the current state. A successful multi-step migration writes one prior-state snapshot under private `data/plugins/PLUGIN-ID/history/` before the atomic state change. Repeated activation does not reapply completed steps. Arbitrary PHP migration expressions and destructive operations are unsupported.

Package rollback is refused if its data schema is older than the stored schema. The package remains disabled and the data/snapshots are retained. Operators must review and restore the appropriate snapshot offline before activating an older data schema; automatic restoration could lose data written since migration. Package rollback is therefore intentionally narrower than full site/data rollback.

Packages declaring `content.read` can call `$context->publishedPages()` for up to 200 published page metadata records (`slug`, `title`, `seo_description`, `status` when present). Drafts, raw bodies and private configuration are excluded. The API provides no bypass of Admin/API/MCP write approvals.

## Governed services and rendering

`proposeContent($request, $type, $identifier, $changes, $revision, $key, $action)` requires `content.propose` and a POST bearer request. The existing machine policy checks current principal, scopes, origin and quota. Revision/idempotency checks and human approval remain mandatory; the SDK cannot approve its own proposal. `media($request, $filters)` requires `media.read` and a bearer credential with `media:read`. `audit($request, $action, $target)` requires `audit.write`, POST and a bearer credential with `audit:read`; it records bounded namespaced metadata without credentials or visitor values.

`submitForm($id, $request)` requires the matching `forms.email` or `forms.webhook` capability and submits a configured form through its ordinary POST, CSRF, nonce, validation, CAPTCHA and quota controls. It does not provide a direct mail bypass.

`registerBlock($name, $renderer)` requires `blocks.render`. A Plugin page block selects `PLUGIN-ID:NAME`. Renderers receive block settings, return at most 64 KiB of HTML, and pass through the shared sanitizer. Failures produce empty output. Static export explicitly loads verified active plugins and restores its previous rendering context afterward.

`registerAdminPage('index', 'Extension', $handler)` requires `admin.routes`. The handler receives `Request` and `Csrf` and returns at most 64 KiB of body HTML within the shared admin shell. The route is `/admin/extensions/PLUGIN-ID/index`; it requires owner/admin authentication, GET or POST, POST CSRF and an account quota. Escape all dynamic output. Native handler HTML remains trusted PHP output. Normal core admin pages continue to boot without native plugins for recovery.

## Recovery and deployment

Create private `radpress/data/plugins/disabled.lock` through server access to disable all optional modules before public loading. Core admin routes skip native plugin loading so the owner can recover. Exceptions disable a failing plugin, but PHP exits, fatal errors and memory exhaustion cannot be reliably contained; use the recovery marker before troubleshooting them. Remove the marker only after fixing or disabling the package.

Installed site plugins, plugin state, connections, submissions and queue secrets are excluded from release builds. Back them up independently with appropriate private permissions. Update deployment preserves existing `radpress/app/` customization. Package rollback does not roll back arbitrary filesystem/network changes made by trusted PHP.

## Forms, delivery and privacy

Forms support 1–20 fields: text, email, textarea, select, checkbox and consent. Field IDs are stable submission keys. Drafts can be saved while optional delivery requirements are missing. Configured reCAPTCHA verification applies to custom forms as well as the legacy contact form; incomplete settings fail closed. Publishing email/webhook forms checks their local delivery configuration; submission checks again if settings change. Email requires a required email field and configured site mail. Webhooks require the enabled module, enabled owner connection and decryptable signing secret. Network availability is checked at delivery time.

Owner/admin users can preview drafts through the Forms screen using the same renderer as the public endpoint. Preview responses are private and no-store; fields and submission are disabled, and preview sends no visitor data or CAPTCHA request. Public draft URLs remain unavailable.

Forms can be linked at `/forms/FORM-ID` or placed using a Form page block. Blocks link to a dynamic form rather than embedding session tokens in cached/static pages. Exported static pages therefore need a reachable PHP form endpoint. Use **Create contact draft** to copy the existing name/email/subject/message fields into a new disabled form. It reuses current mail and reCAPTCHA settings, retains no submissions by default, and cannot overwrite an existing form ID. Publish and place it explicitly after review. Existing `/contact` forms and settings remain available; conversion does not replace their routes or templates.

Storage is optional for delivery actions and mandatory for store-only forms. Marked sensitive values are redacted from submission storage and CSV exports. Delivery payloads still contain those values encrypted because the receiving service needs them. Store-only submission storage is limited to 1,000 records per form. Retained queued submissions share a global 1,000-record bound and commit atomically with their delivery job. Both use 1–365 day retention. Retrying the same delivery ID with identical input is idempotent; changed input is rejected. Expired records are pruned on access. Export requires owner/admin access, uses private no-store responses and protects spreadsheet formula cells. There is no destructive deletion control; retention and operator-controlled backups govern removal.

The private queue holds up to 1,000 encrypted jobs, expires jobs after seven days and attempts one eligible job per processing invocation. There are up to five attempts with bounded backoff. Use **Process next delivery** or optional `php tools/process-form-deliveries.php`; cron is optional. Worker leases permit recovery after a crash. Delivery is at least once: a worker may crash after the receiver accepts a request but before queue acknowledgement. Receivers must deduplicate the stable ID.

## HTTPS webhook adapter contract

Owner connections accept public HTTPS DNS destinations on port 443. Credentials/fragments, nonpublic DNS answers, redirects and proxy routing are rejected. The transport pins the approved address, verifies TLS, limits payload/response bodies to 64 KiB, connection time to two seconds and total time to five seconds. Destination resolution is rechecked on each attempt. Private-network integrations are unsupported.

Payload:

```json
{"id":"STABLE_DELIVERY_ID","event":"form.submitted","data":{"form_id":"enquiry","fields":{"email":"person@example.com"}}}
```

Headers include `Idempotency-Key`, `X-Press-Timestamp` (Unix seconds) and `X-Press-Signature` (`sha256=` followed by lowercase HMAC hex). Compute HMAC-SHA256 over the exact timestamp string, a dot, and the raw request body using the shared connection secret (at least 32 bytes). A receiver should verify with a constant-time comparison, reject timestamps outside its agreed tolerance (for example five minutes), and atomically deduplicate `id` before applying changes. Each retry has a fresh timestamp/signature and the same delivery ID. Return a 2xx response only after accepting the event; other responses trigger bounded retries. Never log secrets or unrestricted visitor payloads.

A runnable receiver example is `radpress/tests/fixtures/webhook-receiver.php`. It verifies the exact signed bytes, timestamp tolerance and matching idempotency key, then atomically stores a receipt and a synthetic mapped operation. `major_upgrade.php` verifies valid delivery, duplicate suppression, tampering and expiry. Its bounded flat-file store is illustrative: production adapters must retain deduplication receipts for at least the sender’s seven-day retry horizon and use a transaction with their real business operation. No receiver endpoint is installed in Press.

This is a generic adapter contract, not a verified Batoi Flow API. Connect another service only after agreeing on field mapping, authentication, retention and deduplication. No inbound webhook endpoint is exposed by Press in this version.

## Conformance fixtures

Run `php radpress/tests/plugin_packages.php`, `php radpress/tests/major_upgrade.php`, `php radpress/tests/forms_admin.php` and `php radpress/tests/plugin_state.php`. They use synthetic signing keys, private temporary stores and injected transports. They do not send real mail or webhooks. The package fixture covers incompatible activation, signature/inventory rejection, disabled upgrades, verified rollback and recovery. Form fixtures cover CSRF/nonces, validation, quota concurrency, redaction, encrypted queue idempotency, publication preflight, private filtering/pagination and CSV export authorization.
