<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;

final class ContentEditor
{
    public static function recoveryAttributes(string $type, string $identifier, bool $recovered = false): string
    {
        $url = function_exists('bp_url') ? \bp_url('/admin/editor-recovery/save') : '/admin/editor-recovery/save';
        return ' data-bp-recovery="' . self::e($type) . '" data-bp-recovery-identifier="' . self::e($identifier) . '" data-bp-recovery-url="' . self::e($url) . '" data-bp-recovered="' . ($recovered ? 'true' : 'false') . '" data-bp-recovery-enabled="' . (\Batoi\Press\Core\RuntimeCapabilities::available('encryption') ? 'true' : 'false') . '" data-bp-recovery-fields="' . self::e(json_encode(\Batoi\Press\Application\EditorRecovery::FIELDS,JSON_THROW_ON_ERROR)) . '"';
    }

    public static function recoveryControls(string $type, string $identifier, bool $recovered = false): string
    {
        $path = '/admin/editor-recovery/list?' . http_build_query(['type'=>$type,'identifier'=>$identifier,'format'=>'html']);
        $url = function_exists('bp_url') ? \bp_url($path) : $path;
        return '<div class="bp-editor-recovery bp-field-wide"><span role="status" aria-live="polite" data-bp-recovery-status>' . ($recovered ? 'Recovery opened. Review the form before saving.' : 'Use the save action to apply your changes.') . '</span><button type="button" class="bp-button bp-button-secondary" data-bp-recovery-retry hidden>Retry recovery</button><a href="' . self::e($url) . '" target="_blank" rel="noopener" data-bp-recovery-review>Review recovery copies</a><span class="bp-field-help">Private recovery covers content, blocks and metadata. Publishing settings and page code require the normal save action.</span></div>';
    }

    public static function render(Config $config, string $value, string $help, string $id, bool $sourceOnly = false, string $name = 'body', bool $visualInitially = false): string
    {
        if (!\Batoi\Press\Core\RuntimeCapabilities::available('dom')) {
            return '<div class="bp-field-wide"><label for="' . self::e($id) . '">Body text</label><textarea id="' . self::e($id) . '" name="' . self::e($name) . '" rows="18">' . self::e(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</textarea><p class="bp-notice">Rich HTML is unavailable on this host. New content is stored as escaped plain text. Enable PHP DOM to edit structured markup.</p></div>';
        }
        $editor = $config->editor();
        $mode = (string)($editor['body_editor'] ?? 'rich_html');
        $toolbar = self::e((string)($editor['html_toolbar'] ?? 'undo redo bold italic underline strike heading quote code ul ol task link image table hr preview source'));
        $height = self::e((string)($editor['html_height'] ?? '24rem'));
        $fieldId = self::e($id);
        $attributes = 'id="' . $fieldId . '" class="bp-editor-textarea" name="' . self::e($name) . '" rows="18"';
        if (!$sourceOnly) {
            $attributes .= ' data-bp-editor-enhance="true"';
        }
        if (!$sourceOnly && $mode === 'rich_html') {
            $attributes .= ' data-uif="editor" data-uif-mode="html" data-uif-preview="manual" data-uif-editor-layout="source" data-uif-editor-height="' . $height . '" data-uif-editor-status="true" data-uif-required="true" data-uif-toolbar="' . $toolbar . '"';
        }

        $label = $sourceOnly ? 'Body HTML source' : 'Body HTML';
        $sourceNotice = $sourceOnly
            ? '<div class="bp-text-only-notice"><strong>HTML source editing</strong><p>Switch views without changing the source. Deliberate visual edits may normalize HTML. Supported component attributes and inline styles are preserved; unsafe scripts, event handlers, and URLs are removed when saving.</p></div>'
            : '';

        $guide = '<div class="bp-editor-assistance" aria-labelledby="' . $fieldId . '-image-help"><div><strong id="' . $fieldId . '-image-help">Insert an image</strong><p>Choose Insert from Media to select or upload an image at the current cursor position. Without JavaScript, Media provides a URL and HTML snippet.</p></div>'
            . '<ol><li>Upload or locate an image in Media.</li><li>Review the caption and mark purely decorative images.</li><li>Add meaningful alt text and preview before saving.</li></ol>'
            . '<a class="bp-button bp-button-secondary bp-editor-media-link" data-bp-editor-media data-bp-picker-url="' . self::e(function_exists('bp_url') ? \bp_url('/admin/media/picker') : '/admin/media/picker') . '" data-bp-upload-url="' . self::e(function_exists('bp_url') ? \bp_url('/admin/media/upload') : '/admin/media/upload') . '" href="/admin/media?type=images" target="_blank" rel="noopener">' . AdminLayout::icon('image') . '<span>Insert from Media</span></a></div>'
            . '<div class="bp-editor-assistance bp-editor-technical-help" aria-labelledby="' . $fieldId . '-technical-help"><div><strong id="' . $fieldId . '-technical-help">Technical content</strong><p>Insert accessible LaTeX delimiters or a semantic code block at the current editor position.</p></div>'
            . '<p>Inline math uses <code>\( … \)</code>; display math uses <code>\[ … \]</code>. Code blocks use <code>&lt;pre&gt;&lt;code&gt;</code> and receive public copy controls automatically.</p>'
            . '<div class="bp-editor-helper-actions"><button type="button" class="bp-button bp-button-secondary" data-bp-editor-insert="inline-math">Inline math</button><button type="button" class="bp-button bp-button-secondary" data-bp-editor-insert="display-math">Display math</button><button type="button" class="bp-button bp-button-secondary" data-bp-editor-insert="code-block">Code block</button></div></div>';

        $views = $sourceOnly ? '<div class="bp-editor-views" role="group" aria-label="Editor view"><button type="button" data-bp-source-view="source" aria-pressed="true">Source</button><button type="button" data-bp-source-view="visual" aria-pressed="false">Visual editor</button><button type="button" data-bp-source-view="preview" aria-pressed="false">Live preview</button></div><div data-bp-visual-toolbar hidden role="group" aria-label="Visual formatting"><button type="button" data-bp-format="bold">Bold</button><button type="button" data-bp-format="italic">Italic</button><button type="button" data-bp-format="formatBlock">Heading</button><button type="button" data-bp-format="insertUnorderedList">List</button><button type="button" data-bp-format="createLink">Link</button></div>' : '';
        $field = '<label for="' . $fieldId . '">' . $label . '</label><textarea ' . $attributes . '>' . self::e($value) . '</textarea>';
        if ($sourceOnly) {
            $manager = new \Batoi\Press\Core\ThemeManager($config->paths());
            $previewHead = '<link rel="stylesheet" href="' . self::e(\bp_url('/assets/uif/uif.css')) . '">' . $manager->tags($manager->activeSlug($config->site()), 'head');
            $field = '<div class="bp-editor-surfaces"><div class="bp-editor-source-pane">' . $field . '</div><iframe title="Content visual editor and preview" sandbox="allow-same-origin" data-bp-source-preview data-bp-preview-head="' . self::e($previewHead) . '" hidden></iframe></div>';
        }
        return '<div class="bp-field-wide bp-content-editor" data-bp-content-editor' . ($sourceOnly ? ' data-bp-source-editor' . ($visualInitially ? ' data-bp-source-initial="visual"' : '') : '') . '>' . $sourceNotice . $views . $field . '<span class="bp-field-help">' . self::e($help) . '</span>' . $guide . '</div>';
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
