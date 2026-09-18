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
