<?php
declare(strict_types=1);

use Batoi\Press\Admin\ThemeTemplateController;
use Batoi\Press\Admin\AdminLayout;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__) . '/helpers/url.php';

$root = dirname(__DIR__, 2);
$config = Config::load($root);
$files = new FileStore();
$controller = new ThemeTemplateController(
    $config,
    $files,
    new Csrf(new Session('batoi_press_theme_syntax_test', $config->paths()->dataPath('sessions'))),
    new AuditLog($config->paths(), $files),
    ['username' => 'admin', 'role' => 'owner']
);

$method = new ReflectionMethod($controller, 'isPhpRuntimeStartupNoise');
$method->setAccessible(true);

assertTrue($method->invoke($controller, [
    '[Wed Jul 01 12:01:01.671078 2026] [mpm_winnt:crit] [pid 23124:tid 388] AH02965: Child: Unable to retrieve my generation from the parent',
]), 'Apache startup noise should not be treated as PHP syntax failure.');

assertTrue(!$method->invoke($controller, [
    'PHP Parse error: syntax error, unexpected token "}" in template-lint.php on line 12',
    'Errors parsing template-lint.php',
]), 'Real PHP parse errors must still fail template syntax checks.');

$editorMethod = new ReflectionMethod($controller, 'codeEditor');
$editorMethod->setAccessible(true);
$editorHtml = $editorMethod->invoke($controller, "<?php echo 'Editable'; ?>", 'php');
assertTrue(str_contains($editorHtml, 'id="theme-template-source"'), 'Template source editor should have a stable field id.');
assertTrue(str_contains($editorHtml, 'aria-readonly="false"'), 'Template source editor should render as editable.');
assertTrue(!str_contains($editorHtml, ' disabled'), 'Template source editor must not render disabled.');
assertTrue(!str_contains($editorHtml, ' readonly'), 'Template source editor must not render readonly.');
$templateIndex = $controller->index('default')->content();
assertTrue(str_contains($templateIndex, 'Theme CSS') && str_contains($templateIndex, 'Theme JavaScript'), 'Theme assets should be directly editable from the template index.');
assertTrue(str_contains($templateIndex, 'Contact Layout'), 'Theme owners should be able to edit contact-page server-side layout code.');
$themesIndex = $controller->themes()->content();
assertTrue(str_contains($themesIndex, 'is-active-theme') && str_contains($themesIndex, 'aria-label="Active theme"'), 'The active theme should have a prominent semantic and visual state.');
assertTrue(str_contains($themesIndex, 'Inspect and Upload') && str_contains($themesIndex, 'Platform conversion'), 'Theme upload should explain inspection and governed conversion before installation.');
$templateEditor = $controller->edit('default/theme-js')->content();
assertTrue(str_contains($templateEditor, 'data-bp-code-submit') && str_contains($templateEditor, 'name="source_encoded"'), 'Theme source should use the WAF-safe encoded submission transport.');

$reportMethod = new ReflectionMethod($controller, 'compatibilityReport');
$reportMethod->setAccessible(true);
$reportHtml = $reportMethod->invoke($controller, [
    'contract' => 'press-theme-1',
    'classification' => 'convertible',
    'source_type' => 'static_html',
    'original_name' => 'sample.zip',
    'archive_sha256' => str_repeat('a', 64),
    'file_count' => 3,
    'extracted_bytes' => 1024,
    'recommendation' => 'Convert with Batoi Platform Build.',
    'checks' => [['code' => 'press.manifest', 'status' => 'fail', 'message' => 'No Press manifest was found.']],
]);
assertTrue(str_contains($reportHtml, 'Theme conversion required') && str_contains($reportHtml, 'Open Batoi Platform Build'), 'Convertible uploads should render a Platform conversion handoff.');
assertTrue(str_contains($reportHtml, str_repeat('a', 64)) && str_contains($reportHtml, 'press-theme-1'), 'The handoff report should bind the exact archive checksum and contract version.');
assertTrue(str_contains($reportHtml, 'class="bp-compatibility-report"') && str_contains($reportHtml, 'readonly aria-readonly="true"'), 'The safe handoff JSON should remain read-only and horizontally contained.');

$themeJs = $config->paths()->themePath('default/assets/js/theme.js');
$themeJsBackup = $themeJs . '.syntax-test-backup';
rename($themeJs, $themeJsBackup);
try {
    $missingAssetIndex = $controller->index('default')->content();
    assertTrue(str_contains($missingAssetIndex, 'Create on save'), 'Missing optional theme assets should offer creation instead of a dead link.');
    $missingAssetEditor = $controller->edit('default/theme-js')->content();
    assertTrue(str_contains($missingAssetEditor, '// Add theme interactions here.'), 'Missing theme JavaScript should open with safe starter source.');
} finally {
    rename($themeJsBackup, $themeJs);
}

$contactLayout = $config->paths()->themePath('default/layouts/contact.php');
$contactLayoutBackup = $contactLayout . '.syntax-test-backup';
rename($contactLayout, $contactLayoutBackup);
try {
    $missingContactIndex = $controller->index('default')->content();
    assertTrue(substr_count($missingContactIndex, 'Create on save') >= 1, 'A missing Contact layout should be creatable from the constrained theme editor.');
    $missingContactEditor = $controller->edit('default/contact')->content();
    assertTrue(str_contains($missingContactEditor, 'bp-contact-page') && str_contains($missingContactEditor, '$page[&#039;body&#039;]'), 'A missing Contact layout should open with a safe PHP starter template.');
} finally {
    rename($contactLayoutBackup, $contactLayout);
}

$binaryNameMethod = new ReflectionMethod($controller, 'isCliCandidateName');
$binaryNameMethod->setAccessible(true);
assertTrue($binaryNameMethod->invoke($controller, 'php'), 'The standard PHP CLI binary name should be accepted.');
assertTrue($binaryNameMethod->invoke($controller, 'php8.5'), 'Versioned PHP CLI binary names should be accepted.');
assertTrue($binaryNameMethod->invoke($controller, 'php.exe'), 'The Windows PHP CLI binary name should be accepted.');
assertTrue(!$binaryNameMethod->invoke($controller, 'php-cgi'), 'PHP CGI must not be executed as a CLI syntax checker.');
assertTrue(!$binaryNameMethod->invoke($controller, 'php8.5.2.fcgi'), 'FastCGI launchers must not be executed as CLI syntax checkers.');

$adminHtml = AdminLayout::render('Theme Template Test', '<main>Body</main>');
assertTrue(str_contains($adminHtml, '/assets/js/app.js'), 'Admin pages should load the app script that resets editable source fields.');
$adminScript = (string)file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/js/app.js');
assertTrue(str_contains($adminScript, 'new TextEncoder()') && str_contains($adminScript, 'window.btoa(binary)'), 'Theme source submission should encode UTF-8 before crossing shared-hosting WAF rules.');

$snapshotDir = $config->paths()->dataPath('versions/theme/default/page');
if (!is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0775, true);
}
$snapshotFixture = $snapshotDir . '/20000101-000000-test.php';
file_put_contents($snapshotFixture, "<?php echo 'Snapshot'; ?>", LOCK_EX);
try {
    $referenceMethod = new ReflectionMethod($controller, 'referencePanel');
    $referenceHtml = $referenceMethod->invoke($controller, 'default', 'page', $config->paths()->themePath('default/layouts/page.php'));
    assertTrue(!str_contains($referenceHtml, '<form'), 'Snapshot controls must not nest a restore form inside the template save form.');
    assertTrue(str_contains($referenceHtml, '/admin/theme-templates/restore'), 'Snapshot controls should submit the existing editor form to the restore endpoint.');
    assertTrue(str_contains($referenceHtml, 'name="snapshot" value="20000101-000000-test.php"'), 'Each restore button should identify its snapshot.');

    $beforeSnapshots = glob($snapshotDir . '/*') ?: [];
    $snapshotMethod = new ReflectionMethod($controller, 'snapshot');
    $snapshotMethod->invoke($controller, 'default', 'page', $config->paths()->themePath('default/layouts/page.php'));
    $snapshotMethod->invoke($controller, 'default', 'page', $config->paths()->themePath('default/layouts/page.php'));
    $newSnapshots = array_values(array_diff(glob($snapshotDir . '/*') ?: [], $beforeSnapshots));
    assertTrue(count($newSnapshots) === 2, 'Rapid consecutive saves should retain distinct snapshots.');
    foreach ($newSnapshots as $newSnapshot) {
        unlink($newSnapshot);
    }
} finally {
    unlink($snapshotFixture);
}

echo "Theme template syntax checks passed\n";

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
