<?php
declare(strict_types=1);

use Batoi\Press\Admin\ContentEditor;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\HtmlContent;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';

$root = sys_get_temp_dir() . '/batoi-press-editor-experience-' . bin2hex(random_bytes(4));

try {
    $semanticHtml = '<main><header><h1>Title</h1></header><section class="bp-hero"><article><p>Body</p></article></section><aside>Related</aside><footer>Footer</footer></main>';
    $sanitizedSemanticHtml = (new HtmlContent())->sanitize($semanticHtml);
    assertEditor(str_contains($sanitizedSemanticHtml, '<section class="bp-hero">'), 'semantic section wrappers and classes should survive sanitization');
    assertEditor(str_contains($sanitizedSemanticHtml, '<article>'), 'semantic article wrappers should survive sanitization');
    assertEditor(str_contains($sanitizedSemanticHtml, '<header>') && str_contains($sanitizedSemanticHtml, '<footer>'), 'semantic header and footer wrappers should survive sanitization');
    $embeddedHtml = (new HtmlContent())->sanitize('<div style="background:url(/hero.jpg);color:#fff"><iframe src="https://www.youtube.com/embed/demo" allowfullscreen></iframe></div>');
    assertEditor(str_contains($embeddedHtml, 'style="background:url(/hero.jpg);color:#fff"'), 'inline presentation styles should survive sanitization');
    assertEditor(str_contains($embeddedHtml, '<iframe src="https://www.youtube.com/embed/demo"'), 'HTTPS iframe embeds should survive sanitization');
    $unsafeHtml = (new HtmlContent())->sanitize('<iframe src="javascript:alert(1)"></iframe><div onclick="alert(1)">Safe</div>');
    assertEditor(!str_contains($unsafeHtml, 'javascript:') && !str_contains($unsafeHtml, 'onclick='), 'unsafe iframe URLs and event handlers should be removed');
    $protectedHtml = '<section class="about-layout"><div><h2>About us</h2><p>Mission <strong>first</strong></p><table><tr><td>Protected table</td></tr></table><pre><code class="language-python">print(123)</code></pre></div></section>';
    $htmlContent = new HtmlContent();
    assertEditor($htmlContent->hasComplexStructure($protectedHtml), 'layout-heavy page bodies should be detected for protected editing');
    $segments = $htmlContent->editableTextSegments($protectedHtml);
    $segmentValues = array_column($segments, 'value');
    assertEditor(in_array('About us', $segmentValues, true) && in_array('Mission', $segmentValues, true) && in_array('first', $segmentValues, true), 'simple visible text should be available to the protected editor');
    assertEditor(!in_array('Protected table', $segmentValues, true) && !in_array('print(123)', $segmentValues, true), 'tables and code samples should be excluded from protected text editing');
    $replacements = [];
    foreach ($segments as $segment) {
        if ($segment['value'] === 'About us') {
            $replacements[$segment['id']] = 'About <Batoi>';
        }
    }
    $updatedProtectedHtml = $htmlContent->replaceEditableText($protectedHtml, $replacements);
    assertEditor(str_contains($updatedProtectedHtml, '<h2>About &lt;Batoi&gt;</h2>'), 'protected text replacements should be safely encoded');
    assertEditor(str_contains($updatedProtectedHtml, '<table><tr><td>Protected table</td></tr></table>'), 'protected editing should preserve table markup byte-for-byte');
    assertEditor(str_contains($updatedProtectedHtml, '<pre><code class="language-python">print(123)</code></pre>'), 'protected editing should preserve code blocks byte-for-byte');

    mkdir($root . '/radpress/config', 0775, true);
    mkdir($root . '/public_html', 0775, true);
    file_put_contents($root . '/radpress/config/paths.json', json_encode([
        'public_root' => 'public_html',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/config/editor.json', json_encode([
        'body_editor' => 'rich_html',
        'html_height' => '32rem',
        'html_toolbar' => 'bold image preview source',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

    $html = ContentEditor::render(Config::load($root), '<p>Long article</p>', 'Sanitized before saving.', 'bp-test-body');
    assertEditor(str_contains($html, 'data-bp-content-editor'), 'content editor should expose the enhancement host');
    assertEditor(str_contains($html, 'data-bp-editor-enhance="true"'), 'body field should expose the editor enhancement hook');
    assertEditor(str_contains($html, 'id="bp-test-body"'), 'body field should preserve an explicit accessible id');
    assertEditor(str_contains($html, 'data-uif="editor"'), 'rich HTML mode should initialize Batoi UIF');
    assertEditor(str_contains($html, 'data-uif-editor-height="32rem"'), 'configured editor height should be preserved');
    assertEditor(str_contains($html, '/admin/media?type=images'), 'editor assistance should link directly to image media');
    assertEditor(str_contains($html, 'Upload or locate an image in Media.'), 'editor assistance should explain image discovery');
    assertEditor(str_contains($html, 'Add meaningful alt text'), 'editor assistance should require accessible image text');
    assertEditor(str_contains($html, 'data-bp-editor-insert="inline-math"'), 'editor assistance should provide an inline LaTeX action');
    assertEditor(str_contains($html, 'data-bp-editor-insert="display-math"'), 'editor assistance should provide a display LaTeX action');
    assertEditor(str_contains($html, 'data-bp-editor-insert="code-block"'), 'editor assistance should provide a semantic code-block action');

    $textOnlyHtml = ContentEditor::renderTextOnly($protectedHtml, $segments, 'bp-protected-body');
    assertEditor(str_contains($textOnlyHtml, 'name="body_edit_mode" value="text_only"'), 'protected editor should identify its save mode');
    assertEditor(str_contains($textOnlyHtml, 'name="body_source_hash"'), 'protected editor should carry a source revision hash');
    assertEditor(str_contains($textOnlyHtml, 'name="body_text[0]"'), 'protected editor should render indexed text fields');
    assertEditor(!str_contains($textOnlyHtml, 'data-uif="editor"'), 'protected editor should not round-trip layout markup through the rich editor');

    $description = ContentEditor::storageDescription();
    assertEditor(str_contains($description, 'stored as sanitized HTML'), 'Settings should explain the stored body format');
    assertEditor(str_contains($description, 'Markdown is not available'), 'Settings should clearly state Markdown availability');
    assertEditor(!str_contains($description, 'migration'), 'Settings should avoid internal migration language');

    $app = (string)file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/js/app.js');
    $css = (string)file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/css/style.css');
    assertEditor(str_contains($app, 'bp-editor-focus-mode'), 'admin JavaScript should implement focus mode');
    assertEditor(str_contains($app, 'Open image media library'), 'admin JavaScript should add a Media toolbar action');
    assertEditor(str_contains($app, 'Insert inline LaTeX') && str_contains($app, 'Insert code block'), 'admin JavaScript should add technical-content toolbar actions');
    assertEditor(str_contains($app, 'mathjax@4.0.0/tex-mml-chtml.js'), 'public JavaScript should conditionally load a pinned MathJax 4 release');
    assertEditor(str_contains($app, 'enhancePublicCodeBlocks'), 'public JavaScript should enhance semantic code blocks');
    assertEditor(str_contains($app, "event.key !== 'Escape'"), 'Escape should close focus mode');
    assertEditor(str_contains($app, '[data-bp-reorder-list]') && str_contains($app, '[data-bp-move]'), 'admin JavaScript should support saved menu and widget ordering');
    assertEditor(str_contains($css, 'position: sticky'), 'admin styles should support a sticky editor toolbar');
    assertEditor(str_contains($css, '.bp-editor-focus-open'), 'admin styles should lock background scrolling in focus mode');
    assertEditor(str_contains($css, '.bp-editor-assistance'), 'admin styles should present image guidance consistently');
    assertEditor(str_contains($css, '.bp-text-only-editor'), 'admin styles should support protected-layout text editing');
    assertEditor(str_contains($css, '.bp-code-block-header'), 'public styles should present code-block controls');
    assertEditor(str_contains($css, 'pre > code'), 'block code should override the inline code treatment');
    assertEditor(str_contains($css, '.bp-admin-action-card button span'), 'theme card button text should retain readable button contrast');

    echo "Editor experience checks passed\n";
} finally {
    removeEditorFixture($root);
}

function assertEditor(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeEditorFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
