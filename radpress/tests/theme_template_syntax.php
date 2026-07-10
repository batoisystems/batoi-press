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

$adminHtml = AdminLayout::render('Theme Template Test', '<main>Body</main>');
assertTrue(str_contains($adminHtml, '/assets/js/app.js'), 'Admin pages should load the app script that resets editable source fields.');

echo "Theme template syntax checks passed\n";

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
