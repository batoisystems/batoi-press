# Batoi Press 2.0 Migration

Version 2.0 is an in-place, backward-compatible update for supported 1.8.x
installations. Create an operator backup before staging the signed package.

## Preserved installation data

The updater does not replace `site.json`, `security.json`, `users.json`, content,
media, session data, access tokens, AIF provider settings, encrypted secrets, or
the installer lock. It updates application/runtime files and the release-owned
`update.json`, `paths.json`, and default AIF baseline only.

Existing page/post directories retain `meta.json` plus `body.html`. Legacy
`draft` and `published` values continue to work; new workflow, reviewer,
scheduling, and unpublish fields appear only after an editor saves them.
`published_at` remains accepted for posts.

Legacy `content/menus/main.json` rows are read through an in-memory schema-v2
migration. Their visible labels, URLs, order, and URL-parent relationships are
preserved. The first successful Admin save assigns stable item IDs and creates
a private pre-change snapshot. `main.json` remains the primary location;
`footer.json` is created only when its new location is saved.

Themes without `menu_locations` continue to receive `primary`. No theme is
required to adopt footer menus, AIF panels, or machine interfaces to remain
renderable.

## Operator sequence

1. Back up the installation and retain the backup outside the web root.
2. Confirm PHP 8.1+, Sodium, ZipArchive, HTTPS, and writable private data paths.
3. Check that `update.json` contains the published Ed25519 verifier key.
4. Stage the official ZIP. Press verifies its signature, manifest, every
   installable checksum, and archive paths before it becomes applyable.
5. Apply; Press enters maintenance mode, creates another backup, runs health
   checks, clears cache, and rolls back automatically on failure.
6. Review Admin → Security, Connections, Menus, Pages, Posts, Batoi AIF, and
   Updates. Keep AIF/provider and external OAuth settings disabled until their
   privacy and identity configuration is approved.
7. Verify the public site, feed, sitemap, nested/mega navigation, footer, and a
   static export.

The migration regression test is `php radpress/tests/v2_migration.php`.
