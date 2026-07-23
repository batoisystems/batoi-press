# Content Format

Pages and posts use adjacent JSON metadata and HTML body files.

```text
radpress/content/pages/about/meta.json
radpress/content/pages/about/body.html
```

Metadata stores title, slug, status, template, author, dates, and SEO fields. The body file stores canonical HTML content.

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
