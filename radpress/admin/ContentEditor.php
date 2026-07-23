<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;

final class ContentEditor
{
    public static function render(Config $config, string $value, string $help, string $id): string
    {
        $editor = $config->editor();
        $mode = (string)($editor['body_editor'] ?? 'rich_html');
        $toolbar = self::e((string)($editor['html_toolbar'] ?? 'undo redo bold italic underline strike heading quote code ul ol task link image table hr preview source'));
        $height = self::e((string)($editor['html_height'] ?? '24rem'));
        $fieldId = self::e($id);
        $attributes = 'id="' . $fieldId . '" class="bp-editor-textarea" name="body" rows="18" data-bp-editor-enhance="true"';
        if ($mode === 'rich_html') {
            $attributes .= ' data-uif="editor" data-uif-mode="html" data-uif-preview="manual" data-uif-editor-layout="source" data-uif-editor-height="' . $height . '" data-uif-editor-status="true" data-uif-required="true" data-uif-toolbar="' . $toolbar . '"';
        }

        $guide = '<div class="bp-editor-assistance" aria-labelledby="' . $fieldId . '-image-help"><div><strong id="' . $fieldId . '-image-help">Insert an image</strong><p>Use the Image tool with the stable public URL shown in Media, or paste the copied HTML snippet in Source mode.</p></div>'
            . '<ol><li>Upload or locate an image in Media.</li><li>Copy its URL or HTML snippet.</li><li>Add meaningful alt text and preview before saving.</li></ol>'
            . '<a class="bp-button bp-button-secondary bp-editor-media-link" data-bp-editor-media href="/admin/media?type=images" target="_blank" rel="noopener">' . AdminLayout::icon('image') . '<span>Open Media</span></a></div>'
            . '<div class="bp-editor-assistance bp-editor-technical-help" aria-labelledby="' . $fieldId . '-technical-help"><div><strong id="' . $fieldId . '-technical-help">Technical content</strong><p>Insert accessible LaTeX delimiters or a semantic code block at the current editor position.</p></div>'
            . '<p>Inline math uses <code>\( … \)</code>; display math uses <code>\[ … \]</code>. Code blocks use <code>&lt;pre&gt;&lt;code&gt;</code> and receive public copy controls automatically.</p>'
            . '<div class="bp-editor-helper-actions"><button type="button" class="bp-button bp-button-secondary" data-bp-editor-insert="inline-math">Inline math</button><button type="button" class="bp-button bp-button-secondary" data-bp-editor-insert="display-math">Display math</button><button type="button" class="bp-button bp-button-secondary" data-bp-editor-insert="code-block">Code block</button></div></div>';

        return '<div class="bp-field-wide bp-content-editor" data-bp-content-editor><label for="' . $fieldId . '">Body HTML</label><textarea ' . $attributes . '>' . self::e($value) . '</textarea><span class="bp-field-help">' . self::e($help) . '</span>' . $guide . '</div>';
    }

    /**
     * @param array<int, array{id: string, tag: string, value: string}> $segments
     */
    public static function renderTextOnly(string $value, array $segments, string $id): string
    {
        $fieldId = self::e($id);
        $fields = '';
        foreach ($segments as $position => $segment) {
            $segmentId = (string)($segment['id'] ?? $position);
            $tag = strtolower((string)($segment['tag'] ?? 'text'));
            $label = match ($tag) {
                'h1' => 'Page heading',
                'h2', 'h3', 'h4', 'h5', 'h6' => 'Section heading',
                'li' => 'List item',
                'a' => 'Link text',
                'blockquote' => 'Quotation',
                'figcaption' => 'Image caption',
                default => 'Body text',
            };
            $text = (string)($segment['value'] ?? '');
            $rows = max(2, min(6, (int)ceil(max(1, strlen($text)) / 90)));
            $controlId = $fieldId . '-text-' . self::e($segmentId);
            $fields .= '<label for="' . $controlId . '"><span>' . self::e($label) . ' <small>&lt;' . self::e($tag) . '&gt;</small></span><textarea id="' . $controlId . '" name="body_text[' . self::e($segmentId) . ']" rows="' . $rows . '">' . self::e($text) . '</textarea></label>';
        }

        return '<div class="bp-field-wide bp-content-editor bp-text-only-editor">'
            . '<input type="hidden" name="body_edit_mode" value="text_only">'
            . '<input type="hidden" name="body_source_hash" value="' . self::e(hash('sha256', $value)) . '">'
            . '<div class="bp-text-only-notice"><strong>Protected-layout editing</strong><p>Only visible text is editable here. HTML tags, inline styles, links, images, tables, forms, embeds, and code samples remain unchanged.</p></div>'
            . '<div class="bp-text-only-fields">' . $fields . '</div></div>';
    }

    public static function storageDescription(): string
    {
        return 'Page and post bodies are stored as sanitized HTML. Choose Rich HTML for visual editing, HTML Source for direct markup, or protected Text-only editing for layout-heavy pages. Markdown is not available because existing content, previews, themes, and exports currently use HTML.';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
