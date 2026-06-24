# Admin Console Page Standardization Review

## Objective

Review every Admin Console page reachable from the left sidebar and its associated pages. Each page should be professional, standardized, and provide adequate user guidance for safe operation.

## Review Standard

- Clear page title and purpose.
- Primary actions are visible and use icons.
- Supporting sections explain impact, safety, and next steps.
- Empty states are useful.
- Forms include field-level help and validation expectations.
- Operational pages explain what will happen before users run actions.
- Governance and security-sensitive pages expose audit, retention, and recovery expectations.

## Task List

### Overview

- [x] Dashboard (`/admin`)

### Publish

- [x] Pages list (`/admin/pages`)
- [x] Page editor (`/admin/pages/new`, `/admin/pages/edit/{slug}`)
- [x] Posts list (`/admin/posts`)
- [x] Post editor (`/admin/posts/new`, `/admin/posts/edit/{slug}`)
- [x] Media library (`/admin/media`)
- [x] Media upload action (`/admin/media/upload`)
- [x] Menus editor (`/admin/menus`)

### Site

- [x] Settings (`/admin/settings`)
- [x] Themes (`/admin/themes`)
- [x] Theme templates (`/admin/theme-templates`, `/admin/theme-templates/edit/{theme}/{file}`)
- [x] Static Export (`/admin/export-static`)
- [x] Cache (`/admin/cache`)

### Governance

- [x] Users list (`/admin/users`)
- [x] Create user (`/admin/users/new`)
- [x] Updates (`/admin/updates`)
- [x] Audit Log (`/admin/audit`)

### Intelligence

- [x] Batoi AIF (`/admin/aif`)

## Associated Action Routes

- `/admin/pages/save`
- `/admin/posts/save`
- `/admin/media/upload`
- `/admin/menus/save`
- `/admin/settings/save`
- `/admin/themes/activate`
- `/admin/themes/upload`
- `/admin/theme-templates/save`
- `/admin/theme-templates/restore`
- `/admin/users/save`
- `/admin/updates/check`
- `/admin/updates/backup`
- `/admin/updates/stage`
- `/admin/cache/clear`
- `/admin/export-static/run`
- `/admin/audit/export`
- `/admin/audit/cleanup`
- `/admin/aif/assist`
