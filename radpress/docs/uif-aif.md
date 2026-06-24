# Batoi UIF and AIF

## Batoi UIF

Batoi Press bundles native UIF primitives for admin and installer screens.

Runtime files:

```text
public_html/assets/uif/uif.css
public_html/assets/uif/uif.iife.js
public_html/assets/uif/uif.life.js
public_html/assets/uif/uif.esm.js
public_html/assets/uif/uif.js
radpress/uif/manifest.json
radpress/uif/components/
```

`uif.css`, `uif.iife.js`, `uif.life.js`, and `uif.esm.js` are the full Batoi UIF distribution files bundled for downloads. `uif.js` is the small Batoi Press initializer wrapper.

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

Batoi AIF is optional and disabled by default.

Configuration:

```text
radpress/config/aif.json
```

Default behavior:

* `enabled` is `false`.
* Provider is `disabled`.
* No network calls are made.
* Admin can view status at `/admin/aif`.
* Guarded content-assist actions exist at `/admin/aif/assist` and return a disabled response until configured.
* The admin status screen shows readiness, setup requirements, provider availability, feature flags, feature purposes, workspace requirement, configuration file location, and network-call trust boundaries.
* Disabled or unavailable installations keep assist buttons disabled in the UI to avoid implying that AI assistance is active.
* Assist attempts are audit logged with success, failed, or blocked outcomes.

Future provider adapters can implement `Batoi\Press\Aif\AifProvider`.
Future Batoi Platform workspace adapters can implement `Batoi\Press\Aif\BatoiWorkspaceAifProvider`.
