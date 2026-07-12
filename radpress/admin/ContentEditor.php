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
            . '<a class="bp-button bp-button-secondary bp-editor-media-link" data-bp-editor-media href="/admin/media?type=images" target="_blank" rel="noopener">' . AdminLayout::icon('image') . '<span>Open Media</span></a></div>';

        return '<div class="bp-field-wide bp-content-editor" data-bp-content-editor><label for="' . $fieldId . '">Body HTML</label><textarea ' . $attributes . '>' . self::e($value) . '</textarea><span class="bp-field-help">' . self::e($help) . '</span>' . $guide . '</div>';
    }

    public static function storageDescription(): string
    {
        return 'Page and post bodies are stored as sanitized HTML. Choose Rich HTML for visual editing or HTML Source for direct markup. Markdown is not available because existing content, previews, themes, and exports currently use HTML.';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
