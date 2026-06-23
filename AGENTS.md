# Batoi Press Codex Instructions

These instructions apply to this repository. Follow them together with the user's personal Codex instructions unless a task gives more specific direction.

## Repository Layout

- Runtime application code lives in `radpress/`.
- Public web assets live in `public_html/`.
- Admin UI shell and shared admin rendering live in `radpress/admin/`.
- Content, config, cache, and session data live under `radpress/content/`, `radpress/config/`, `radpress/cache/`, and `radpress/data/`.
- Release/build helper scripts live in `tools/` and specs live in `specs/`.

## Official Batoi Brand Baseline

Use the official Batoi brand style guideline as the primary source when brand decisions matter:

- `https://www.batoi.com/pub/assets/2023/02/25/batoi-brand-style-guidelines-63f9a84dd771d.pdf`
- Local blue logo asset, when available: `/Users/ashwinirath/Desktop/AssetsPortfolio/batoi/Batoi(Blue).png`
- Batoi Press logo family is bundled at `public_html/assets/img/batoi-press/`; use these files for admin favicon, product marks, touch icons, and Press-specific brand surfaces.

Official brand rules captured from the guideline:

- Mission statement: "Bringing the value of digital technologies within the reach of everybody."
- The logo contains a wordmark and a logomark. Do not redraw, distort, stretch, crop, or casually recompose it.
- Use the full logo where space permits. Use compressed marks only in small or confined spaces where the full logo cannot be used.
- Logo construction proportions should be respected; preserve clear spacing and the relative wordmark/logomark relationship.
- For one-color usage, use a light logo on a dark background or a dark logo on a light background.
- For watermarks, use a monochrome logo at 15-30% transparency, placed near the edge, as small as practical while still noticeable.
- Primary brand colors:
  - Batoi Blue: `#0E68B0`.
  - Batoi Green: `#00B696`.
  - Black: `#000000`.
  - White: `#FFFFFF`.
- Brand typography uses Proxima Nova for both headings and body text. Use `"proxima-nova", sans-serif` when available, with system sans-serif fallback.
- Keep the color scheme basic and simple. Blue and green are accent colors; avoid diluting the brand with broad decorative palettes.

## Batoi Product UI References

Use the neighboring `batoi-www` repository as the closest Batoi brand reference when needed, especially:

- `/Users/ashwinirath/Sites/localhost/gitrepo/batoi-www/public_html/assets/css/workspace.css`
- `/Users/ashwinirath/Sites/localhost/gitrepo/batoi-www/rad/theme/bridge.tpl.php`
- `/Users/ashwinirath/Sites/localhost/gitrepo/batoi-www/rad/theme/staffcentral.tpl.php`

Do not broadly search `batoi-www/rad/vendor/` or runtime config files for brand research.

Product UI cues observed from Batoi workspace/admin surfaces:

- Primary blue: `#0E68B0`.
- Primary hover/darker blue: `#07497c`.
- Alternate bright hover blue: `#0d81db`.
- Active sidebar blue wash: `#e6f4ff`.
- Enterprise neutrals: `#111827`, `#374151`, `#4b5563`, `#6b7280`, `#e5e7eb`, `#f3f4f6`, white surfaces.
- Preferred product font in Batoi workspace: `"proxima-nova", sans-serif`; use system sans-serif fallback if the font is not locally available.
- Compact radii are part of the workspace look: about `.125rem` to `.15rem`, with small practical exceptions for icon buttons and pills.
- Workspace shell widths and density are compact: sidebars around `15rem` to `16rem`, padding around `.65rem` to `.8rem`, and gap around `.65rem`.

## Product Design Standard

- Build admin interfaces to a polished B2B SaaS standard.
- Prefer quiet, dense, operational layouts over marketing-style panels.
- Prioritize hierarchy, scanability, predictable navigation, and repeat workflows.
- Avoid oversized hero sections inside admin dashboards.
- Put useful operational data and primary actions in the first viewport.
- Do not use decorative blobs, gradient orbs, heavy gradients, or consumer-style visual effects.
- Keep Batoi branding visible through shell, typography, blue accents, and disciplined spacing, not through loud decoration.

## Admin UI Rules

- Page headers should include title, concise description, and primary actions on the right.
- Avoid duplicated labels such as repeating the page name in both topbar and content.
- Sidebar navigation should be calm: normal items around weight `500`, active items around `600`, and section labels restrained.
- Active navigation should be clear through color/background/indicator, not excessive boldness.
- Use compact KPI cards for metrics. Keep labels small, values clear, and decorative icons subtle.
- Use cards for real grouped content, not as decorative wrappers around every page section.
- Buttons should be compact and clear. Prefer icon plus text when the icon is already available in the local UI system.
- Lists/tables should use the full practical content width and include search/filter/pagination when appropriate.
- Forms should put the primary task first and move metadata/advanced fields into side panels, tabs, or secondary sections when useful.

## Typography

- Use a restrained scale for admin interfaces.
- Avoid too many competing heavy weights.
- Suggested weights:
  - Body and inactive nav: `400` to `520`.
  - Active nav and labels: `600` to `650`.
  - Section headings: `650` to `720`.
  - Page titles: `700` to `760`.
- Letter spacing should usually be `0`. Use uppercase sparingly for small labels only.

## Implementation Preferences

- Prefer improving existing files over creating duplicate documents or parallel UI systems.
- Keep changes scoped to the requested behavior and nearby ownership boundaries.
- Match existing PHP/CSS patterns before adding abstractions.
- Use structured escaping for output. Do not introduce unsafe HTML output.
- Preserve useful decision history and avoid broad reorganization.
- Ask before deleting content.

## Local Review Workflow

- Primary development checkout: `/Users/ashwinirath/Sites/localhost/gitrepo/batoi-press`.
- GitHub download/test checkout: `/Users/ashwinirath/Sites/localhost/exp/testsite`.
- Browser review URL: `https://localhost.exp/testsite/public_html/admin`.
- When UI files are changed and the user wants browser review, copy the appropriate changed files into the test checkout and verify in Chrome when available.

## Verification

- Run `php -l` on changed PHP files.
- Run `php radpress/tests/smoke.php` for admin/runtime changes when practical.
- For admin UI changes, inspect the local browser rendering when possible, including the first viewport and sidebar behavior.
- Do not leave unrelated refactors or metadata churn in the change.
