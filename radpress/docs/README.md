# Batoi Press documentation

## Start with your role

| Role | Start here | Continue with |
| --- | --- | --- |
| Site owner | [Installation](installation.md) | [Content format](content-format.md), [themes](theme-development.md), [updates](updates.md), [recovery](update-recovery.md) |
| Editor | [Content format and publishing](content-format.md) | [Social publishing](social-publishing.md), [private recovery](content-format.md#private-editor-recovery-50-development), [image insertion](content-format.md#insert-images-from-the-editor-50-development), blocks and HTML/source/protected-text modes |
| Merchant | [Commerce development guide](commerce.md) | [Product/order schemas](schemas/product.schema.json), Stripe sandbox acceptance and backup requirements |
| Theme creator or AI user | [UIF theme workflow](theme-development.md#portable-uif-theme-generation) | [Starter JSON](examples/uif-theme-starter.json), [schema](schemas/uif-theme.schema.json), Admin → Themes → Create UIF theme |
| Plugin developer | [Plugin SDK](plugins.md#developer-starter-workflow) | [Developer tool](examples/plugin-tool.php), [schema](schemas/plugin.schema.json), package trust and recovery |
| Integrator | [API and MCP](machine-interfaces.md) | [UIF/AIF](uif-aif.md), [security](security.md) |
| Operator | [Installation](installation.md) | [Update recovery](update-recovery.md), [security](security.md), [releases](releases/v4.0.8.md) |

## Versions and evidence

The current release is **4.0.8**. Commerce, editor recovery, the UIF theme kit and
plugin developer tooling are included as previews of the ongoing 5.0 work;
they are not a completed 5.0 release. See [4.0.1 notes](releases/v4.0.1.md). Individual guides identify current
limits and required external acceptance. [Roadmap](roadmap.md) retains product
history; release notes retain the evidence for each published version.

Schemas help authoring. Runtime validators, signatures, permissions, revision
checks and service-specific semantic checks remain authoritative. Examples use
synthetic data and must never contain merchant or model credentials.
