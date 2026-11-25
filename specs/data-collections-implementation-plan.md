# Data Collections implementation plan

## Tasks

- [x] Add versioned JSON storage, typed fields, stable record IDs, and conflict-safe draft and publication operations.
- [x] Add the Collections admin area with field management, single-record editing, bulk entry, search, filtering, and archive controls.
- [x] Add CSV and XLSX import with column mapping, worksheet selection, validation, revision-checked preview, and commit.
- [x] Add authorized CSV and XLSX exports, with spreadsheet values written safely.
- [x] Add a Collection page block, bundled layouts, theme overrides, and shared live and static rendering.
- [x] Integrate permissions, previews, audit events, backup, deployment, and hosting capability checks.
- [x] Verify the Utkal Chamber workflow with representative fixtures and review the admin UI.
- [x] Compare pending public values and publish selected records or pending changes from the latest import.

## Scope

Collections are a first-party Press feature for directories, schedules, resources, and other structured publishing. Administrators manage schemas and publication. Editors can manage records in collections explicitly enabled for them. Drafts and fields remain private until an administrator approves the public columns and publishes. Archive records to remove them from a future publication.

## Storage and delivery

Use one bounded, revisioned JSON document per collection. Use a stable lock to check revision and atomically save changes. Store the working data and published projection together to avoid a partially committed publish. Keep bounded publication history for restore, and apply current field visibility when restoring older publications. Protect site-owned collection content during application releases and cover it in private backups.

Retain Record IDs alongside published rows so selected changes can be applied without replacing unrelated published values. Match older snapshots to working records only when their public values identify them unambiguously. Keep collection-wide name and public field changes in the entire-draft workflow. Record the eligible results of the latest import and include only records without other pending public edits or later changes when publishing that batch.

## Import and export

Parse CSV and XLSX through format-specific readers and a shared typed row validator. Preview column mapping and row results before committing a batch. Add records by default. Updates match a configured unique field and preserve existing values for unmapped columns. Report duplicate or invalid rows before any write. Enforce file, workbook, row, and document budgets. CSV remains available on the minimal hosting profile. Surface XLSX availability through Hosting Health.

## Public presentation

Pages store a collection ID and a declarative view with a filter, ordering, field selection, and layout. A shared renderer reads approved data for both the live site and static export. Use escaped cards, list, and table presets and allow themes to declare custom collection layouts. Keep private field values out of all public template contexts. Preview drafts inside authenticated Admin.

## Acceptance

Use representative Members data with an L1 row missing a membership number, a professional record missing an organisation, and a category exceeding 24 records. Demonstrate adding and editing records, column-mapped CSV and XLSX import, reimport without duplicates, safe exports, publication, restoration, and rendering the same collection in more than one layout. Exercise invalid input, concurrent edits, permissions, private fields, backup, and static export. Live directory migration requires the Chamber's authoritative workbook and a field review.

## Verification

- `php radpress/tests/data_collections.php` exercises the representative workflow, stale writes, repeat submission, export safety, Theme Templates, backup, and static delivery.
- A local Chrome review covered the Admin sidebar and records grid, bulk paste, CSV upload, XLSX worksheet selection, and publication into a filtered public directory.
- A synthetic collection with 2,000 rows and eight fields completed CSV import, publication, and table rendering with a 64 MiB PHP memory limit. Peak allocated memory was 16 MiB in that run.
- An isolated package build included the Collection controller, spreadsheet library, and stylesheet. Site-owned collection JSON was excluded.
