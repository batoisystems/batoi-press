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
- **Markdown** is not selectable because content storage, previews, themes, and static exports currently use HTML.

For images, upload or locate the file in **Media**, copy its stable public URL, and use the editor's **Image** command. Alternatively, copy the Media HTML snippet and paste it in Source mode. Add meaningful alt text and preview the content before saving. The editor's **Open Media** actions open the image-filtered Media library in a new tab so unsaved form content remains in place.
