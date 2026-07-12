# Editor Experience Upgrade Plan

## Tasks

- [x] Add a sticky Batoi UIF editor toolbar that remains available while long content scrolls.
- [x] Add an accessible focus mode for page and post body editing.
- [x] Preserve the underlying textarea, HTML storage contract, sanitization, and save routes.
- [x] Add a direct Media-library action beside the editor tools.
- [x] Add concise, visible image insertion steps for rich and source editing workflows.
- [x] Clarify the Settings editor description and explain why Markdown is not currently selectable.
- [x] Apply the same editor experience to Pages and Posts.
- [x] Add regression coverage for editor enhancement hooks, image guidance, and Settings copy.
- [x] Verify sticky, focus, escape, image-help, desktop, and mobile behavior in the synchronized testsite.
- [ ] Run the complete automated suite, commit, release, and update public Press artifacts.

## Objective

Make long-form editing efficient without replacing Batoi UIF or changing Batoi Press content storage. Formatting tools must remain reachable, focus mode must reduce surrounding distraction, and managed images must have an obvious path from Media to page or post content.

## Current Implementation

- Page and Post body fields use the same `data-uif="editor"` configuration and store sanitized HTML in `body.html`.
- Batoi UIF creates a rich surface, source view, preview, status bar, and toolbar actions including Image.
- The toolbar is inside the editor and scrolls out of view with the Content panel.
- The Image command accepts an image URL, but the editor does not point users to the managed Media screen or explain which value to copy.
- Media lists stable public URLs and HTML snippets, but that workflow is discoverable only after independently navigating to Media.
- Settings offers Rich HTML and HTML Source while displaying an implementation-oriented Markdown migration warning.

## Gaps

1. **Long-document navigation:** formatting requires returning to the top of the Content panel.
2. **No distraction-free state:** the navigation shell, publishing sidebar, and page actions remain visible while composing.
3. **No editor-to-media bridge:** Image and Media are separate workflows with no visible handoff.
4. **Ambiguous image instructions:** users are not told to upload an image, copy its URL or HTML, and then insert it.
5. **Internal Settings language:** the Markdown message describes migration work rather than current behavior and consequences.
6. **No regression contract:** current tests do not assert editor enhancement hooks or guidance.

## Implementation Decisions

### Sticky Toolbar

- Keep the UIF toolbar sticky below the 64-pixel Admin Console top bar.
- Keep toolbar wrapping at narrow widths and preserve every UIF command.
- Raise the toolbar above editor content without covering the active selection.
- Use application CSS overrides; do not fork generated UIF bundles.

### Focus Mode

- Add one `Focus mode` command to each initialized page/post UIF toolbar.
- Present the editor as a fixed, full-viewport operational surface with a restrained backdrop.
- Expand the editable body to the remaining viewport height.
- Change the command to `Exit focus` while active.
- Close with Escape, restore the prior page position and focus, and prevent background scrolling.
- Keep the same editor instance and textarea so unsaved content and form submission remain intact.

### Image Workflow

- Add an `Open Media` action to the enhanced toolbar, opening the image-filtered Media library in a new tab so unsaved editor content is not discarded.
- Display a compact three-step guide below the body editor:
  1. Upload or locate an image in Media.
  2. Copy its public URL for the Image command, or copy its HTML snippet for Source mode.
  3. Add meaningful alt text and preview before saving.
- Explain that the built-in Image command expects the stable public URL shown in Media.
- Do not duplicate upload handling inside page/post forms in this phase; Media remains the governed asset owner and audit boundary.

### Markdown Clarification

Use user-facing copy:

> Page and post bodies are stored as sanitized HTML. Choose Rich HTML for visual editing or HTML Source for direct markup. Markdown is not available because existing content, previews, themes, and exports currently use HTML.

This states the current contract and practical reason without promising or exposing an unspecified migration.

## Accessibility And Safety

- Focus and Media commands use native buttons/links with explicit accessible names and tooltips.
- Escape exits focus mode without changing content.
- Opening Media uses `target="_blank"` and `rel="noopener"` to preserve the editing form.
- The enhancement does not use `innerHTML` for user-provided content.
- Existing server-side sanitization remains authoritative.
- Reduced-motion preferences must not introduce required animation.

## Verification

- Page and Post forms expose the same editor enhancement hook and image guide.
- Toolbar position remains fixed beneath the top bar while the page scrolls through long content.
- Focus mode occupies the viewport, background scrolling is locked, and Escape restores the normal editor.
- Media opens at the image filter without replacing the editor tab.
- UIF Image remains available and its workflow is explained visibly.
- Settings contains the clarified HTML/Markdown storage explanation.
- Desktop and 390-pixel mobile layouts have no incoherent overlap or horizontal overflow.
- Existing create/edit/save, sanitizer, preview, static export, and role-access tests remain green.

## Delivery

- Synchronize changed PHP, CSS, JavaScript, tests, and documentation to the testsite.
- Verify the rendered experience in the browser before release.
- Commit the implementation and release metadata separately.
- Publish the GitHub release ZIP and checksum.
- Update `batoi-www/public_html/pub/press/latest.json`, the matching release ZIP, release catalog, route fallbacks, and Press documentation from an isolated worktree.
