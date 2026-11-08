# Content Format

Pages and posts use adjacent JSON metadata and HTML body files.

```text
radpress/content/pages/about/meta.json
radpress/content/pages/about/body.html
```

Metadata stores title, slug, status, template, author, dates, SEO fields, and optional page-presentation settings. The body file stores canonical HTML content.

Pages can enable a **Latest posts section** and choose a limit from 1 to 12. The renderer supplies the newest published posts to the active page layout; the bundled Standard and Landing layouts render them below the page body. Enable this setting on the page selected as the homepage to maintain a dynamic homepage feed without changing the homepage route contract.

## Editor Workflow

Page and post bodies remain sanitized HTML in both editor modes:

- **Batoi UIF Rich HTML** provides visual formatting, preview, a sticky toolbar, and a full-viewport focus mode.
- **HTML Source** provides direct access to the stored markup.
- **Text only** is the safe default for layout-heavy existing pages. It exposes visible copy fields while preserving HTML tags, attributes, styles, links, images, tables, forms, embeds, and code samples.
- **Markdown** is not selectable because content storage, previews, themes, and static exports currently use HTML.

For images, upload or locate the file in **Media**, copy its stable public URL, and use the editor's **Image** command. Alternatively, copy the Media HTML snippet and paste it in Source mode. Add meaningful alt text and preview the content before saving. The editor's **Open Media** actions open the image-filtered Media library in a new tab so unsaved form content remains in place.

## Mathematics

Use the editor's **Math** action for inline LaTeX or the **Display math** helper for a standalone equation. Batoi Press stores the standard MathJax delimiters as text:

```html
<p>Einstein's equation is \( E = mc^2 \).</p>

\[
\int_0^1 x^2\,dx = \frac{1}{3}
\]
```

The public asset loader detects these delimiters and loads MathJax only on pages that contain mathematics. Keep equations outside `pre` and `code` elements.

## Code Blocks

Use the **Code block** action for multi-line samples. It inserts semantic markup that themes can style and syntax highlighters can recognize:

```html
<pre><code class="language-python">print(123)</code></pre>
```

Public pages separate inline-code styling from block-code styling and add a language label plus Copy action to each `pre > code` block.

## Post types and page blocks

Posts use `post_type` as their URL prefix: `blog` (the default for existing records), `news`, `activities`, or another lowercase slug. Categories remain independent labels. Parents and children must share a type. Reserved routes, existing top-level pages, and public files/directories cannot be used as type prefixes. Changing a type changes its URLs; update incoming links deliberately. Parent types cannot be changed while children remain attached.

Post page blocks accept boolean `show_image`, `show_date`, and `show_read_more` options. Missing options default to false to preserve old blocks. Default-theme archives support `posts_per_page` and opt-in `posts_load_more`; Previous/Next links work without JavaScript. Static exports contain complete type archives rather than client-side pagination.

## XML import

The additive native format uses a `<batoi-press>` root with `pages/page`, `posts/post`, and optional `media/file` children. Content fields include `title`, `slug`, `status`, `body` (HTML in CDATA), `parent_slug`, category, tags and SEO fields. Posts also accept `post_type`, `published_at`, `featured_image`, and `featured_image_alt`. Unknown XML formats, including WordPress exports and sitemap XML, require conversion; they are not accepted as backups.

```xml
<batoi-press>
  <pages><page><title>About</title><slug>about</slug><status>draft</status>
    <body><![CDATA[<p>About us</p>]]></body>
  </page></pages>
  <media><file name="guide.txt" source="/old/guide.txt" encoding="base64">R3VpZGU=</file></media>
</batoi-press>
```

Embedded media is validated using normal upload restrictions and stored with safe generated names. Exact quoted `src`, `href`, and `poster` references matching `source` (or `name` when source is omitted) are rewritten, as are featured-image URLs. CSS URLs, srcset, and remote attachments are not imported automatically. No remote media is fetched. Imports are limited to 10 MiB, reject DTD/entities, skip existing content slugs, and resolve parents before children. Imports are not transactional; make a backup and test first.

## Default-theme appearance and widgets

Settings expose opt-in visitor light/dark switching, per-mode validated color tokens, top/bottom footer columns, bottom text, and labelled icon links. Custom themes must opt into these settings. Theme switching stores only a local browser preference. Scroll-to-top appears only when a page is scrollable.

Gallery widgets accept up to 24 lines of `Media URL | alternative text`; configured URLs replace legacy gallery HTML. Activity Calendar shows the current month with published-post links. Subscribe uses a configured HTTPS signup page, not a local subscriber database or campaign sender. Legacy sanitized widget HTML remains supported.

## Optional page media metadata

Pages may store `hero_image` (existing local `/assets/` or `/media/` raster URL),
`hero_preload` (boolean), `hero_priority` (`auto` or `high`), `lazy_images`
(boolean) and `image_sizes` (escaped CSS sizes string, maximum 500 bytes).
Omitted values preserve existing metadata for older callers; defaults do not
preload or lazy-load images. These controls are available through the page admin
form; the machine-editable field allowlist is unchanged. Derivative manifests
use `data/cache/media-variants-*.json`, separate from source content, and reference
original checksums and public derivative files.

## Private editor recovery (5.0 development)

Page and post editors preserve content, structured blocks and metadata in an
encrypted server-side copy after a short pause in typing. The status line
distinguishes pending recovery, a saved private copy and a failed request.
**Private recovery never changes the website.** Saving an already public item
uses the explicit **Save and publish changes** action.

Each editing tab has its own copy. Use **Review recovery copies** to compare
the current source with a copy, then **Open recovery in editor** when the
original revision still matches. Opening fills the form; it does not save it.
If another editor saved meanwhile, both versions remain available for manual
comparison and merging. Source HTML is escaped in comparison views, including
untrusted embeds and scripts. Protected-layout recovery preserves the original
structure and safely reapplies indexed text, including intentionally blank text.

Recovery includes title, proposed slug, body/blocks, taxonomy, image and search
metadata. Publication dates/status, reviewer notes and page CSS/JavaScript still
require the normal save action. A browser leave warning covers unsaved form
changes; normal submission attempts to preserve the latest content first.
Without JavaScript or host encryption, use the normal save action. When the
network or sign-in fails, keep the editor open and copy your text before reloading.

Copies belong to the original account and require its current editing permission.
They remain private across logout and are not placed in browser storage. Storage
under `data/editor-recovery/` is excluded from releases. Limits are 100 copies /
16 MiB per account and 2,000 copies / 128 MiB per installation. Owners should
back up the encryption key with private data and archive old copies when needed;
this version does not silently prune them or provide a discard/retention console.

## Insert images from the editor (5.0 development)

1. Place the cursor in the rich or HTML source editor and choose **Insert from
   Media** (or the toolbar's **Media** action).
2. Search existing raster images or expand **Upload images**. Uploads reuse the
   normal Media permission, signature/type checks and host size limits. Optional
   responsive copies are generated only when the host encoder/memory permits.
3. Choose an image and review its content-specific alt text and caption. Supply
   alt text for meaningful images, or explicitly mark a purely decorative image.
4. Insert. The editor restores the cursor/focus and inserts escaped text,
   dimensions and a local media URL. Public rendering adds available verified
   responsive copies; page media settings govern lazy loading. Save and
   preview the content normally. Library metadata and older embeds are unchanged.

The modal supports keyboard navigation, Escape/cancel and focus return. It uses
paginated, bounded image results and inspects derivative checksums only for the
selected image. SVG and files without valid raster signatures are excluded.
Authors without Media access retain their existing permissions; an editor or
administrator manages images. Without JavaScript, the Media page still provides
URLs and HTML snippets for source editing. Complete screen-reader and browser
acceptance remains part of the 5.0 release gate.
