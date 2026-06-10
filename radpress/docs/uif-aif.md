# Batoi UIF and AIF

## Batoi UIF

Batoi Press bundles native UIF primitives for admin and installer screens.

Runtime files:

```text
public_html/assets/uif/uif.css
public_html/assets/uif/uif.js
radpress/uif/manifest.json
radpress/uif/components/
```

`public_html/assets/css/style.css` imports the UIF stylesheet so existing admin and installer screens can adopt primitives incrementally.

Custom public themes may use UIF, but they are not required to.

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

Future provider adapters can implement `Batoi\Press\Aif\AifProvider`.
